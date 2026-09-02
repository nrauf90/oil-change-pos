<?php

namespace App\Http\Middleware;

use App\Enums\ShopStatus;
use App\Http\Responses\ShopUnavailableResponse;
use App\Models\Central\Shop;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSessionInvalidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class EnsureShopIsActive
{
    public const RESOLVED_SHOP_ATTRIBUTE = '_resolved_tenant_shop';

    public function __construct(
        private TenantResolver $resolver,
        private TenantConnectionManager $manager,
        private TenantContext $context,
        private ShopUnavailableResponse $unavailableResponse,
        private UrlGenerator $url,
        private TenantSessionInvalidator $sessionInvalidator,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request !== request()) {
            return $this->handlePersistentRequest($request, $next);
        }

        $this->manager->disconnect();

        try {
            $shop = $this->resolver->resolve($request);
        } catch (Throwable) {
            $this->manager->disconnect();
            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->unavailable($request);
        }

        if ($shop === null) {
            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->notFound($request);
        }

        if ($shop->status !== ShopStatus::Active) {
            $this->sessionInvalidator->invalidate($request);

            return $this->unavailableResponse->unavailable($request);
        }

        $request->attributes->set(self::RESOLVED_SHOP_ATTRIBUTE, $shop);
        $routeTenant = $request->route('tenant');
        $previousTenantDefault = $this->url->getDefaultParameters()['tenant'] ?? null;
        $this->url->defaults(['tenant' => $shop->slug]);

        if (is_string($routeTenant)) {
            $request->route()?->forgetParameter('tenant');
        }

        try {
            return $next($request);
        } finally {
            $this->url->defaults(['tenant' => $previousTenantDefault]);
        }
    }

    /** @param Closure(Request): Response $next */
    private function handlePersistentRequest(Request $request, Closure $next): Response
    {
        try {
            $shop = $this->resolver->resolve($request);
        } catch (Throwable) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $shop instanceof Shop
            || $shop->status !== ShopStatus::Active
            || ! $this->context->initialized()
            || ! hash_equals($this->context->id(), (string) $shop->getKey())) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(self::RESOLVED_SHOP_ATTRIBUTE, $shop);
        $request->route()?->forgetParameter('tenant');

        return $next($request);
    }
}
