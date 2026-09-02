<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class UpdateShopFeatureEntitlements
{
    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private ModuleRegistry $modules,
        private RecordShopLifecycleActivity $recordActivity,
    ) {}

    /** @param list<string> $enabledFeatureKeys */
    public function handle(PlatformUser $actor, Shop $shop, array $enabledFeatureKeys): int
    {
        $modules = $this->modules
            ->all()
            ->reject(static fn (Module $module): bool => $module->isCore());
        $enabledKeys = collect($enabledFeatureKeys);

        return Cache::lock(
            'shop-feature-entitlements:'.$shop->getKey(),
            self::LOCK_SECONDS,
        )->block(
            self::LOCK_WAIT_SECONDS,
            fn (): int => DB::connection('central')->transaction(
                function () use ($actor, $enabledKeys, $modules, $shop): int {
                    $lockedActor = PlatformUser::on('central')
                        ->whereKey($actor->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedActor instanceof PlatformUser
                        || ! $lockedActor->is_active
                        || $lockedActor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
                        throw new AuthorizationException(
                            'Your platform administrator access is no longer active.',
                        );
                    }

                    $lockedShop = Shop::on('central')
                        ->whereKey($shop->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedShop instanceof Shop) {
                        throw new AuthorizationException('The shop is no longer available.');
                    }

                    $storedFeatures = ShopFeature::on('central')
                        ->where('shop_id', $lockedShop->getKey())
                        ->orderBy('module_key')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('module_key');
                    $changes = 0;

                    foreach ($modules as $module) {
                        $storedFeature = $storedFeatures->get($module->key());
                        $currentlyEnabled = $storedFeature instanceof ShopFeature
                            ? $storedFeature->enabled
                            : $module->enabledByDefault();
                        $shouldEnable = $enabledKeys->contains($module->key());

                        if ($currentlyEnabled === $shouldEnable) {
                            continue;
                        }

                        ShopFeature::on('central')->updateOrCreate(
                            [
                                'shop_id' => $lockedShop->getKey(),
                                'module_key' => $module->key(),
                            ],
                            ['enabled' => $shouldEnable],
                        );
                        $this->recordActivity->handle(
                            $lockedShop,
                            $shouldEnable
                                ? ShopLifecycleEvent::FeatureEnabled
                                : ShopLifecycleEvent::FeatureDisabled,
                            $lockedActor,
                            [
                                'module_key' => $module->key(),
                                'reason_code' => 'platform_action',
                            ],
                        );
                        $changes++;
                    }

                    return $changes;
                },
            ),
        );
    }
}
