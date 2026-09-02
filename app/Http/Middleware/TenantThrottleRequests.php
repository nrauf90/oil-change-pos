<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use RuntimeException;

final class TenantThrottleRequests extends ThrottleRequests
{
    protected function resolveRequestSignature(mixed $request): string
    {
        if (! $request instanceof Request) {
            throw new RuntimeException('Unable to generate the request signature. Request unavailable.');
        }

        $context = resolve(TenantContext::class);

        if (! $context->initialized()) {
            return parent::resolveRequestSignature($request);
        }

        if ($user = $request->user()) {
            return $this->formatTenantIdentifier($context->id().'|'.$user->getAuthIdentifier());
        }

        if ($route = $request->route()) {
            return $this->formatTenantIdentifier($context->id().'|'.$route->getDomain().'|'.$request->ip());
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    private function formatTenantIdentifier(string $value): string
    {
        return self::$shouldHashKeys ? sha1($value) : $value;
    }
}
