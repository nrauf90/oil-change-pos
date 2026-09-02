<?php

namespace App\Http\Middleware;

use App\Enums\ShopStatus;
use App\Http\Responses\ShopUnavailableResponse;
use App\Models\Central\Shop;
use App\Support\TenantSessionAuthentication;
use App\Tenancy\CentralTenantResolver;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSessionInvalidator;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\UrlGenerator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class InitializeTenancy
{
    public const SESSION_SHOP_KEY = 'tenant.shop_id';

    private const OPTIONAL = 'optional';

    private const LOCAL_PACKAGE_ROUTES = [
        'livewire.update',
        'livewire.upload-file',
        'livewire.preview-file',
        'filament.exports.download',
        'filament.imports.failed-rows.download',
    ];

    public function __construct(
        private TenantResolver $resolver,
        private CentralTenantResolver $centralResolver,
        private TenantConnectionManager $manager,
        private TenantContext $context,
        private ShopUnavailableResponse $unavailableResponse,
        private AuthManager $auth,
        private CookieJar $cookies,
        private UrlGenerator $url,
        private ConfigRepository $config,
        private TenantSessionInvalidator $sessionInvalidator,
        private TenantSessionAuthentication $sessionAuthentication,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if ($request->route() === null) {
            return $this->handleGlobalRequest($request, $next);
        }

        if ($request !== request()) {
            return $this->handlePersistentRequest($request, $next);
        }

        $this->manager->disconnect();

        try {
            $shop = $request->attributes->get(EnsureShopIsActive::RESOLVED_SHOP_ATTRIBUTE);

            if (! $shop instanceof Shop) {
                $shop = $this->resolver->resolve($request);
            }
        } catch (Throwable) {
            $this->manager->disconnect();
            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->unavailable($request);
        }

        if ($shop === null) {
            if ($mode === self::OPTIONAL && $this->centralResolver->isTrustedCentralRequest($request)) {
                return $next($request);
            }

            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->notFound($request);
        }

        if ($shop->status !== ShopStatus::Active) {
            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->unavailable($request);
        }

        $routeTenant = $request->route('tenant');
        $previousTenantDefault = $this->url->getDefaultParameters()['tenant'] ?? null;
        $previousShopDefault = $this->url->getDefaultParameters()[TenantLivewireUploadUrlGenerator::TENANT_CLAIM] ?? null;
        $previousPathFormatter = $this->url->pathFormatter();
        $this->url->defaults([
            'tenant' => $shop->slug,
            TenantLivewireUploadUrlGenerator::TENANT_CLAIM => (string) $shop->getKey(),
        ]);
        $this->useLocalTenantPackagePaths($shop, $previousPathFormatter);

        if (is_string($routeTenant)) {
            $request->route()?->forgetParameter('tenant');
        }

        try {
            try {
                $this->manager->connect($shop, requireActiveShop: true);

                // The outer global invocation disconnects after the route pipeline
                // unwinds, so StartSession can persist while the tenant guard is valid.
                return $this->runTenantRequest($request, $next);
            } catch (TenantDatabaseAttestationFailed) {
                $this->manager->disconnect();
                $this->sessionInvalidator->invalidate($request);

                return $this->unavailableResponse->unavailable($request);
            }
        } finally {
            $this->url->defaults([
                'tenant' => $previousTenantDefault,
                TenantLivewireUploadUrlGenerator::TENANT_CLAIM => $previousShopDefault,
            ]);
            $this->url->formatPathUsing($previousPathFormatter);
        }
    }

    /** @param Closure(Request): Response $next */
    private function handleGlobalRequest(Request $request, Closure $next): Response
    {
        $this->manager->disconnect();

        try {
            return $next($request);
        } finally {
            $this->manager->disconnect();
        }
    }

    /** @param Closure(Request): Response $next */
    private function handlePersistentRequest(Request $request, Closure $next): Response
    {
        try {
            $shop = $request->attributes->get(EnsureShopIsActive::RESOLVED_SHOP_ATTRIBUTE);

            if (! $shop instanceof Shop) {
                $shop = $this->resolver->resolve($request);
            }
        } catch (Throwable) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $shop instanceof Shop
            || $shop->status !== ShopStatus::Active
            || ! $this->context->initialized()
            || ! hash_equals($this->context->id(), (string) $shop->getKey())) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }

    /** @param Closure(Request): Response $next */
    private function runTenantRequest(Request $request, Closure $next): Response
    {
        $this->bindSessionToActiveShop($request);
        $this->sessionAuthentication->ensureCurrentAuthentication($request);

        $configurationKey = 'livewire.temporary_file_upload.directory';
        $previousDirectory = $this->config->get($configurationKey);
        $baseDirectory = is_string($previousDirectory) && trim($previousDirectory, '/\\') !== ''
            ? trim($previousDirectory, '/\\')
            : 'livewire-tmp';
        $this->config->set(
            $configurationKey,
            'tenants/'.$this->context->id().'/'.$baseDirectory,
        );

        try {
            return $next($request);
        } finally {
            $this->config->set($configurationKey, $previousDirectory);
        }
    }

    private function bindSessionToActiveShop(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $guard = $this->auth->guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new RuntimeException('The tenant web guard must use session authentication.');
        }

        $session = $request->session();
        $sessionShopId = $session->get(self::SESSION_SHOP_KEY);
        $activeShopId = $this->context->id();
        $rememberCookie = $guard->getRecallerName();
        $hasWebIdentity = $session->has($guard->getName()) || $request->cookies->has($rememberCookie);
        $shopMatches = is_string($sessionShopId) && hash_equals($activeShopId, $sessionShopId);

        if ($hasWebIdentity && ! $shopMatches) {
            $session->forget([$guard->getName(), self::SESSION_SHOP_KEY]);
            $request->cookies->remove($rememberCookie);
            $this->cookies->queue($this->cookies->forget($rememberCookie));
            $guard->forgetUser();
            $session->regenerate(true);
        } elseif ($sessionShopId !== null && ! $shopMatches) {
            $session->regenerate(true);
        }

        $session->put(self::SESSION_SHOP_KEY, $activeShopId);
    }

    private function useLocalTenantPackagePaths(Shop $shop, Closure $previousFormatter): void
    {
        if (! in_array((string) $this->config->get('app.env'), ['local', 'testing'], true)) {
            return;
        }

        $this->url->formatPathUsing(static function (
            string $path,
            ?RoutingRoute $route = null,
        ) use ($previousFormatter, $shop): string {
            $path = $previousFormatter($path, $route);

            if (! $route instanceof RoutingRoute
                || ! in_array($route->getName(), self::LOCAL_PACKAGE_ROUTES, true)) {
                return $path;
            }

            return '/__tenants/'.$shop->slug.'/'.ltrim($path, '/');
        });
    }
}
