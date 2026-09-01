<?php

namespace Tests;

use App\Models\Central\Shop;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        if ($this->usesDefaultTenantContext()) {
            $this->initializeDefaultTenantContext();
        }

        $uses = parent::setUpTraits();

        if ($this->usesDefaultTenantContext()) {
            if (isset($uses[LazilyRefreshDatabase::class])) {
                DB::connection((string) config('database.default'))->select('select 1');
            }

            $this->aliasTenantConnectionToDefault();
        }

        return $uses;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert on rendered Blade, not on the asset pipeline.
        $this->withoutVite();
    }

    protected function usesDefaultTenantContext(): bool
    {
        return true;
    }

    private function initializeDefaultTenantContext(): void
    {
        $defaultConnectionName = (string) config('database.default');
        $defaultConfiguration = config('database.connections.'.$defaultConnectionName);

        if (! is_array($defaultConfiguration)) {
            return;
        }

        unset($defaultConfiguration['url']);
        config()->set('database.connections.tenant', $defaultConfiguration);
        DB::purge('tenant');

        $this->aliasTenantConnectionToDefault();

        $shop = new Shop;
        $shop->setRawAttributes([
            'id' => (string) Str::uuid(),
            'name' => 'Test tenant',
            'slug' => 'test-tenant',
        ], true);
        $shop->exists = true;
        app(TenantContext::class)->initialize($shop);
        config()->set('permission.cache.key', 'spatie.permission.cache.tenant.'.$shop->getKey());
        app(PermissionRegistrar::class)->initializeCache();
    }

    private function aliasTenantConnectionToDefault(): void
    {
        $defaultConnection = DB::connection((string) config('database.default'));
        $tenantConnection = DB::connection('tenant');
        $tenantConnection->setPdo($defaultConnection->getPdo());
        $tenantConnection->setReadPdo($defaultConnection->getReadPdo());

        if ($this->app->bound('db.transactions')) {
            $tenantConnection->setTransactionManager($this->app->make('db.transactions'));
        }

        $synchronizeTransactionLevel = function (int $transactionLevel): void {
            $this->transactions = $transactionLevel;
        };
        $synchronizeTransactionLevel->call($tenantConnection, $defaultConnection->transactionLevel());
    }
}
