<?php

namespace App\Tenancy;

use App\Http\Middleware\InitializeSupportAccess;
use App\Models\Central\Shop;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

final readonly class CentralTenantResolver implements TenantResolver
{
    public function __construct(
        private ConfigRepository $config,
        private SupportAccessContext $supportAccess,
    ) {}

    public function resolve(Request $request): ?Shop
    {
        $baseHost = $this->baseHost();
        $requestHost = $this->requestHost($request);

        if ($baseHost === null || $requestHost === null) {
            return null;
        }

        $hostSlug = $this->hostSlug($requestHost, $baseHost);
        $routeSlug = $this->routeSlug($request);

        if ($hostSlug !== null && $routeSlug !== null && ! hash_equals($hostSlug, $routeSlug)) {
            return null;
        }

        $slug = $hostSlug;

        if ($slug === null && $requestHost === $baseHost) {
            $slug = $routeSlug;
        }

        if ($slug === null) {
            return $this->supportFallback($request, $requestHost, $baseHost);
        }

        $shop = Shop::query()->where('slug', $slug)->first();

        if ($this->supportAccess->active()
            && (! $shop instanceof Shop
                || ! hash_equals((string) $this->supportAccess->shop()->getKey(), (string) $shop->getKey()))) {
            return null;
        }

        return $shop;
    }

    public function isTrustedCentralRequest(Request $request): bool
    {
        $baseHost = $this->baseHost();
        $requestHost = $this->requestHost($request);

        return $baseHost !== null
            && $requestHost !== null
            && hash_equals($baseHost, $requestHost)
            && $request->route('tenant') === null;
    }

    private function baseHost(): ?string
    {
        $host = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = strtolower($host);

        return $this->isValidDnsName($host) ? $host : null;
    }

    private function requestHost(Request $request): ?string
    {
        $authority = $request->server->get('HTTP_HOST');

        if (! is_string($authority)
            || preg_match('/\A(?<host>[A-Za-z0-9.-]+)(?::(?<port>[0-9]{1,5}))?\z/D', $authority, $matches) !== 1) {
            return null;
        }

        if (isset($matches['port'])
            && ((int) $matches['port'] < 1 || (int) $matches['port'] > 65535)) {
            return null;
        }

        $host = strtolower($matches['host']);

        return $this->isValidDnsName($host) ? $host : null;
    }

    private function hostSlug(string $requestHost, string $baseHost): ?string
    {
        $suffix = '.'.$baseHost;

        if (! str_ends_with($requestHost, $suffix)) {
            return null;
        }

        $slug = substr($requestHost, 0, -strlen($suffix));

        return $this->isValidSlug($slug) ? $slug : null;
    }

    private function routeSlug(Request $request): ?string
    {
        if (! in_array((string) $this->config->get('app.env'), ['local', 'testing'], true)) {
            return null;
        }

        $slug = $request->route('tenant');

        return is_string($slug) && $this->isValidSlug($slug) ? $slug : null;
    }

    private function supportFallback(Request $request, string $requestHost, string $baseHost): ?Shop
    {
        if (! in_array((string) $this->config->get('app.env'), ['local', 'testing'], true)
            || ! hash_equals($baseHost, $requestHost)
            || $request->route('tenant') !== null
            || $request->attributes->get(InitializeSupportAccess::VALIDATED_ATTRIBUTE) !== true
            || ! $this->supportAccess->active()) {
            return null;
        }

        return $this->supportAccess->shop();
    }

    private function isValidSlug(string $slug): bool
    {
        return TenantSlug::isValid($slug);
    }

    private function isValidDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (! $this->isValidSlug($label)) {
                return false;
            }
        }

        return true;
    }
}
