<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\Inspection;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Observers\ExpenseObserver;
use App\Observers\InspectionObserver;
use App\Observers\ItemObserver;
use App\Observers\SaleObserver;
use App\Observers\SupplierObserver;
use App\Observers\SupplierPaymentObserver;
use App\Observers\SupplyObserver;
use App\Observers\UserObserver;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\NullTenantConnectionAttestationHook;
use App\Tenancy\SystemDatabaseHostResolver;
use App\Tenancy\TenantConnectionAttestationHook;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseAttestor;
use App\Tenancy\TenantRuntimeState;
use App\Tenancy\TenantSqliteAttestationLock;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $runtimeState = new TenantRuntimeState;

        $this->app->bind(DatabaseHostResolver::class, SystemDatabaseHostResolver::class);
        $this->app->singleton(
            TenantConnectionAttestationHook::class,
            NullTenantConnectionAttestationHook::class,
        );
        $this->app->singleton(
            TenantContext::class,
            static fn (): TenantContext => new TenantContext($runtimeState),
        );
        $this->app->singleton(
            TenantConnectionManager::class,
            static fn (Application $application): TenantConnectionManager => new TenantConnectionManager(
                database: $application->make('db'),
                config: $application->make('config'),
                runtimeState: $runtimeState,
                permissionRegistrar: $application->make(PermissionRegistrar::class),
                auth: $application->make('auth'),
                application: $application,
                attestor: new TenantDatabaseAttestor(
                    $application->make('config'),
                    new TenantSqliteAttestationLock(
                        (string) $application->make('config')->get('database.tenant_attestation_lock_path'),
                    ),
                ),
                moduleRegistry: $application->make(ModuleRegistry::class),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event): void {
                if ($event->connection->getName() === 'tenant') {
                    resolve(TenantConnectionManager::class)
                        ->attestEstablishedConnection($event->connection);
                }
            },
        );

        Auth::provider(
            'active_platform_eloquent',
            static fn (Application $application, array $config): EloquentUserProvider => (new EloquentUserProvider(
                $application->make('hash'),
                $config['model'],
            ))->withQuery(
                static fn (Builder $query): Builder => $query->where('is_active', true),
            ),
        );

        /*
        | Audit trail. Attached to the model events rather than to the actions
        | in the controllers, so a Filament resource, an artisan command or a
        | future second write path all land in the log automatically — there is
        | no code path that saves one of these rows without an entry.
        */
        Sale::observe(SaleObserver::class);
        Item::observe(ItemObserver::class);
        Expense::observe(ExpenseObserver::class);
        Inspection::observe(InspectionObserver::class);
        Supplier::observe(SupplierObserver::class);
        Supply::observe(SupplyObserver::class);
        SupplierPayment::observe(SupplierPaymentObserver::class);
        User::observe(UserObserver::class);
    }
}
