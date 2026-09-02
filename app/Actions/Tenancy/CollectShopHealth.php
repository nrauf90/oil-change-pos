<?php

namespace App\Actions\Tenancy;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Item;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantConnectionManager;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CollectShopHealth
{
    public function __construct(
        private TenantConnectionManager $connectionManager,
        private ModuleRegistry $moduleRegistry,
    ) {}

    public function handle(#[\SensitiveParameter] Shop $shop): ShopHealthSnapshot
    {
        $attributes = [
            'migration_status' => 'unavailable',
            'summary' => [
                'connection_status' => 'unavailable',
                'seed_status' => 'unknown',
                'pending_migrations' => null,
            ],
        ];

        try {
            $this->connectionManager->connect($shop);
            [$migrationStatus, $pendingMigrations] = $this->migrationState();

            $attributes = [
                'last_successful_connection_at' => now(),
                'migration_status' => $migrationStatus,
                'last_activity_at' => $this->latestActivityAt(),
                'summary' => [
                    'connection_status' => 'healthy',
                    'seed_status' => $this->seedStatus(),
                    'pending_migrations' => $pendingMigrations,
                ],
            ];
        } catch (Throwable) {
        } finally {
            $this->connectionManager->disconnect();
        }

        return ShopHealthSnapshot::query()->updateOrCreate(
            ['shop_id' => $shop->getKey()],
            $attributes,
        );
    }

    /** @return array{0: 'current'|'pending'|'unavailable', 1: ?int} */
    private function migrationState(): array
    {
        try {
            $migrationFiles = glob(database_path('migrations/tenant').DIRECTORY_SEPARATOR.'*.php');

            if ($migrationFiles === false) {
                return ['unavailable', null];
            }

            $expectedMigrations = array_map(
                static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
                $migrationFiles,
            );
            $ranMigrations = DB::connection('tenant')
                ->table((string) config('database.migrations.table', 'migrations'))
                ->pluck('migration')
                ->map(static fn (mixed $migration): string => (string) $migration)
                ->all();
            $pendingMigrations = count(array_diff($expectedMigrations, $ranMigrations));

            return [$pendingMigrations === 0 ? 'current' : 'pending', $pendingMigrations];
        } catch (Throwable) {
            return ['unavailable', null];
        }
    }

    private function seedStatus(): string
    {
        try {
            $moduleKeys = $this->moduleRegistry->all()->keys()->values()->all();
            $permissionNames = array_map(
                static fn (PermissionEnum $permission): string => $permission->value,
                PermissionEnum::cases(),
            );
            $roleNames = array_map(
                static fn (RoleEnum $role): string => $role->value,
                RoleEnum::cases(),
            );
            $authorizationIsCurrent = ModuleSetting::query()->whereIn('key', $moduleKeys)->count()
                    === count($moduleKeys)
                && Permission::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $permissionNames)
                    ->count() === count($permissionNames)
                && Role::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $roleNames)
                    ->count() === count($roleNames);
            $referenceDataIsPresent = Item::query()->exists()
                && VehicleMake::query()->exists()
                && VehicleModel::query()->exists();

            return $authorizationIsCurrent && $referenceDataIsPresent ? 'current' : 'incomplete';
        } catch (Throwable) {
            return 'incomplete';
        }
    }

    private function latestActivityAt(): mixed
    {
        try {
            return ActivityLog::query()->latest('created_at')->value('created_at');
        } catch (Throwable) {
            return null;
        }
    }
}
