<?php

namespace App\Actions\Tenancy;

use App\Enums\DashboardPeriod;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\Item;
use App\Models\User;
use App\Support\AdminDashboardMetrics;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use LogicException;

final readonly class CollectTenantStatistics
{
    public function __construct(
        private TenantConnectionManager $connectionManager,
        private TenantContext $tenantContext,
        private AdminDashboardMetrics $dashboardMetrics,
    ) {}

    /**
     * @return array{
     *     sales: string,
     *     expenses: string,
     *     gross_margin: string,
     *     transaction_count: int,
     *     active_users: int,
     *     inventory_count: int,
     *     last_activity_at: ?string
     * }
     */
    public function handle(
        #[\SensitiveParameter]
        Shop $shop,
        DashboardPeriod $period,
    ): array {
        if ($this->tenantContext->initialized()) {
            throw new LogicException('Tenant statistics require a clean tenant context.');
        }

        try {
            $this->connectionManager->connect($shop, requireActiveShop: true);
            $metrics = $this->dashboardMetrics->comparison($period)['current'];
            $lastActivity = ActivityLog::query()->latest('created_at')->first(['created_at']);

            return [
                'sales' => (string) $metrics['sales'],
                'expenses' => (string) $metrics['expenses'],
                'gross_margin' => (string) $metrics['margin'],
                'transaction_count' => (int) $metrics['sale_count'],
                'active_users' => User::query()->active()->count(),
                'inventory_count' => Item::query()->active()->count(),
                'last_activity_at' => $lastActivity?->created_at?->toIso8601String(),
            ];
        } finally {
            $this->connectionManager->disconnect();
        }
    }
}
