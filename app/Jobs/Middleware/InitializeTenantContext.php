<?php

namespace App\Jobs\Middleware;

use App\Models\Central\Shop;
use App\Tenancy\TenantConnectionManager;
use Closure;

final readonly class InitializeTenantContext
{
    public function __construct(private string $shopId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $shop = Shop::query()->findOrFail($this->shopId);

        return resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): mixed => $next($job),
            requireActiveShop: true,
        );
    }
}
