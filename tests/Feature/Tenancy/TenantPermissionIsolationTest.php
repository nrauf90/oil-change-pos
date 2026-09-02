<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Permission;
use App\Models\Central\Shop;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantPermissionCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantPermissionIsolationTest extends TestCase
{
    private string $temporaryDirectory;

    private TenantConnectionManager $manager;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenant-permissions-'.Str::uuid();

        if (! File::makeDirectory($this->temporaryDirectory, 0700, true)) {
            throw new RuntimeException('Unable to create the tenant permission test directory.');
        }

        $centralDatabase = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the tenant permission central database.');
        }

        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->temporaryDirectory);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->temporaryDirectory.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createShop('permission-shop-a');
        $this->shopB = $this->createShop('permission-shop-b');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            DB::purge('central');
            File::deleteDirectory($this->temporaryDirectory);
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_permission_cache_key_tracks_activation_and_cleanup(): void
    {
        $cache = app(TenantPermissionCache::class);
        $registrar = app(PermissionRegistrar::class);

        $this->manager->connect($this->shopA);

        $this->assertSame($cache->keyFor($this->shopA), config('permission.cache.key'));
        $this->assertSame($cache->keyFor($this->shopA), $registrar->cacheKey);

        $this->manager->disconnect();

        $this->assertSame($cache->keyWithoutTenant(), config('permission.cache.key'));
        $this->assertSame($cache->keyWithoutTenant(), $registrar->cacheKey);
    }

    public function test_same_role_name_uses_only_current_tenant_permissions_across_switches(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $this->manager->connect($this->shopA);
        $shopAUser = $this->createRoleAndUser(
            username: 'shared-user',
            permission: Permission::ViewMargins,
        );
        $shopAKey = $registrar->cacheKey;

        $this->assertTrue($shopAUser->can(Permission::ViewMargins->value));
        $this->assertFalse($shopAUser->can(Permission::UsePos->value));
        $this->assertTrue($registrar->getCacheRepository()->has($shopAKey));
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $shopBUser = $this->createRoleAndUser(
            username: 'shared-user',
            permission: Permission::UsePos,
        );
        $shopBKey = $registrar->cacheKey;

        $this->assertNotSame($shopAKey, $shopBKey);
        $this->assertTrue($shopBUser->can(Permission::UsePos->value));
        $this->assertFalse($shopBUser->can(Permission::ViewMargins->value));
        $this->assertTrue($registrar->getCacheRepository()->has($shopBKey));
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $shopAUser = User::query()->where('username', 'shared-user')->sole();

        $this->assertSame($shopAKey, $registrar->cacheKey);
        $this->assertTrue($shopAUser->can(Permission::ViewMargins->value));
        $this->assertFalse($shopAUser->can(Permission::UsePos->value));
    }

    private function createShop(string $slug): Shop
    {
        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->temporaryDirectory.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $this->createMigratedTenantDatabase($shop);

        return $shop;
    }

    private function createRoleAndUser(string $username, Permission $permission): User
    {
        $role = Role::query()->create([
            'name' => 'shared role name',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([$permission->value]);
        $user = User::factory()->create(['username' => $username]);
        $user->syncRoles([$role]);

        return $user->fresh();
    }
}
