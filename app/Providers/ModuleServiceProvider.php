<?php

namespace App\Providers;

use App\Modules\Features\AdminModule;
use App\Modules\Features\ExpensesModule;
use App\Modules\Features\InventoryModule;
use App\Modules\Features\ReportsModule;
use App\Modules\Features\SalesModule;
use App\Modules\Features\ScriptsModule;
use App\Modules\Features\WorkshopModule;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantFeatureGate;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Every pluggable feature of the shop system. Adding one is a single line
     * here plus its Module class — no shared nav or permission file to edit.
     */
    private const MODULES = [
        SalesModule::class,
        InventoryModule::class,
        ReportsModule::class,
        ExpensesModule::class,
        ScriptsModule::class,
        WorkshopModule::class,
        AdminModule::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function (): ModuleRegistry {
            return new ModuleRegistry(
                modules: array_map(fn (string $module) => new $module, self::MODULES),
                featureGate: $this->app->make(TenantFeatureGate::class),
            );
        });
    }
}
