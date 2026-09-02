<?php

namespace App\Http\Middleware;

use App\Http\Responses\ShopUnavailableResponse;
use App\Tenancy\SupportAccessContext;
use App\Tenancy\SupportAccessManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class InitializeSupportAccess
{
    public const VALIDATED_ATTRIBUTE = '_validated_support_access';

    public function __construct(
        private SupportAccessManager $manager,
        private SupportAccessContext $context,
        private ShopUnavailableResponse $unavailableResponse,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->manager->resume($request);

        if ($this->context->shopUnavailable()) {
            try {
                return $this->unavailableResponse->unavailable($request);
            } finally {
                $this->context->clear();
            }
        }

        if ($this->context->active()) {
            $request->attributes->set(self::VALIDATED_ATTRIBUTE, true);
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
