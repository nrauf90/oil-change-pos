<?php

namespace Tests;

use App\Tenancy\TenantConnectionManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $defaultTestTenantId = null;

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

        $this->defaultTestTenantId = (string) Str::uuid();
        $this->aliasTenantConnectionToDefault();
    }

    private function aliasTenantConnectionToDefault(): void
    {
        $defaultConnection = DB::connection((string) config('database.default'));
        app(TenantConnectionManager::class)->bootstrapForTesting(
            $this->defaultTestTenantId ??= (string) Str::uuid(),
            $defaultConnection,
        );
        $tenantConnection = DB::connection('tenant');

        if ($this->app->bound('db.transactions')) {
            $tenantConnection->setTransactionManager($this->app->make('db.transactions'));
        }

        $synchronizeTransactionLevel = function (int $transactionLevel): void {
            $this->transactions = $transactionLevel;
        };
        $synchronizeTransactionLevel->call($tenantConnection, $defaultConnection->transactionLevel());
    }
}
