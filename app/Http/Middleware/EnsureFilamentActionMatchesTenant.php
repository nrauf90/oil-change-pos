<?php

namespace App\Http\Middleware;

use App\Http\Responses\ShopUnavailableResponse;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureFilamentActionMatchesTenant
{
    public function __construct(
        private TenantContext $context,
        private ShopUnavailableResponse $unavailableResponse,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $claimedShopId = $request->route(TenantLivewireUploadUrlGenerator::TENANT_CLAIM);

        if (! $this->context->initialized()) {
            if ($claimedShopId !== null) {
                return $this->unavailableResponse->notFound($request);
            }

            return $next($request);
        }

        if (! is_string($claimedShopId)
            || ! hash_equals($this->context->id(), $claimedShopId)) {
            return $this->unavailableResponse->notFound($request);
        }

        // Filament's action metadata is still central-only. Tenant downloads stay
        // dormant until that metadata has explicit tenant ownership.
        return $this->unavailableResponse->notFound($request);
    }
}
