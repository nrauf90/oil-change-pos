<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCentralHost
{
    public function __construct(private ConfigRepository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configuredHost = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        if (! is_string($configuredHost) || trim($configuredHost) === '') {
            abort(404);
        }

        $configuredHost = strtolower(rtrim($configuredHost, '.'));
        $requestHost = $this->rawHost($request);

        if ($requestHost === null || ! hash_equals($configuredHost, $requestHost)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * Read the host the way CentralTenantResolver does — from the raw Host
     * header, never Symfony's resolved host.
     *
     * `$request->getHost()` honours `X-Forwarded-Host` whenever a proxy is
     * trusted, and `TrustProxies` self-enables `'*'` on Laravel Cloud. Since
     * host separation is the only separation the platform panel has, reading
     * the resolved host would let a forwarded header serve the control plane
     * on a tenant-owned hostname while the resolver still saw the tenant.
     */
    private function rawHost(Request $request): ?string
    {
        $authority = $request->server->get('HTTP_HOST');

        if (! is_string($authority)
            || preg_match('/\A(?<host>[A-Za-z0-9.-]+)(?::(?<port>[0-9]{1,5}))?\z/D', $authority, $matches) !== 1) {
            return null;
        }

        return strtolower(rtrim($matches['host'], '.'));
    }
}
