<?php

namespace App\Filament\Platform\Resources\Shops\Pages;

use App\Actions\Tenancy\CollectShopHealth;
use App\Actions\Tenancy\CollectTenantStatistics;
use App\Enums\DashboardPeriod;
use App\Enums\ShopStatus;
use App\Filament\Platform\Resources\Shops\ShopResource;
use App\Filament\Platform\Resources\Shops\Tables\ShopsTable;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    /** @var array<string, int|string|null> */
    public array $tenantStatistics = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $shop = $this->getRecord();

        if (! $shop instanceof Shop) {
            return;
        }

        $platformUser = Auth::guard('platform')->user();

        try {
            $shop->setRelation(
                'healthSnapshot',
                resolve(CollectShopHealth::class)->handle($shop),
            );

            if ($shop->status === ShopStatus::Active) {
                try {
                    $this->tenantStatistics = resolve(CollectTenantStatistics::class)->handle(
                        $shop,
                        DashboardPeriod::Month,
                    );
                } catch (Throwable) {
                    $this->tenantStatistics = [];
                }
            }
        } finally {
            if ($platformUser instanceof PlatformUser) {
                Auth::guard('platform')->setUser($platformUser);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ShopsTable::suspendAction(),
            ShopsTable::reactivateAction(),
            ShopsTable::retryProvisioningAction(),
        ];
    }
}
