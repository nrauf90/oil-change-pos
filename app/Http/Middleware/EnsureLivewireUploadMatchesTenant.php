<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureLivewireUploadMatchesTenant
{
    public function __construct(private TenantContext $context) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $claim = $request->query(TenantLivewireUploadUrlGenerator::TENANT_CLAIM);
        $expectedClaim = $this->context->initialized()
            ? $this->context->id()
            : TenantLivewireUploadUrlGenerator::CENTRAL_CLAIM;

        abort_unless(is_string($claim) && hash_equals($expectedClaim, $claim), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
