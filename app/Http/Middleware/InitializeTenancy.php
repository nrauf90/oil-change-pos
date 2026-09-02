<?php

namespace App\Http\Middleware;

use App\Enums\ShopStatus;
use App\Http\Responses\ShopUnavailableResponse;
use App\Models\Central\Shop;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class InitializeTenancy
{
    public const SESSION_SHOP_KEY = 'tenant.shop_id';

    private const OPTIONAL = 'optional';

    public function __construct(
        private TenantResolver $resolver,
        private TenantConnectionManager $manager,
        private TenantContext $context,
        private ShopUnavailableResponse $unavailableResponse,
        private AuthManager $auth,
        private CookieJar $cookies,
        private UrlGenerator $url,
        private ConfigRepository $config,
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

            return $this->unavailableResponse->unavailable($request);
        }

        if ($shop === null) {
            return $mode === self::OPTIONAL
                ? $next($request)
                : $this->unavailableResponse->notFound($request);
        }

        if ($shop->status !== ShopStatus::Active) {
            return $this->unavailableResponse->unavailable($request);
        }

        $routeTenant = $request->route('tenant');
        $previousTenantDefault = $this->url->getDefaultParameters()['tenant'] ?? null;
        $this->url->defaults(['tenant' => $shop->slug]);

        if (is_string($routeTenant)) {
            $request->route()?->forgetParameter('tenant');
        }

        try {
            try {
                return $this->manager->within($shop, function () use ($request, $next): Response {
                    return $this->runTenantRequest($request, $next);
                }, requireActiveShop: true);
            } catch (TenantDatabaseAttestationFailed) {
                $this->manager->disconnect();

                return $this->unavailableResponse->unavailable($request);
            }
        } finally {
            $this->url->defaults(['tenant' => $previousTenantDefault]);
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
            $this->auth->forgetGuards();
            $session->regenerate(true);
        } elseif ($sessionShopId !== null && ! $shopMatches) {
            $session->regenerate(true);
        }

        $session->put(self::SESSION_SHOP_KEY, $activeShopId);
    }
}
