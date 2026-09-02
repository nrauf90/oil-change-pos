<?php

namespace App\Providers;

use App\Http\Middleware\EnforceReadOnlySupportAccess;
use App\Http\Middleware\EnsureFilamentActionMatchesTenant;
use App\Http\Middleware\EnsureLivewireUploadMatchesTenant;
use App\Http\Middleware\InitializeSupportAccess;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Central\PlatformUser;
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
use App\Tenancy\CentralTenantResolver;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\NullTenantConnectionAttestationHook;
use App\Tenancy\OpenedTenantDatabaseIdentityVerifier;
use App\Tenancy\PdoTenantSqliteWitnessConnection;
use App\Tenancy\Provisioning\DatabaseProvisioner;
use App\Tenancy\Provisioning\DatabaseProvisionerManager;
use App\Tenancy\Provisioning\LaravelMySqlServerConnectionFactory;
use App\Tenancy\Provisioning\MySqlServerConnectionFactory;
use App\Tenancy\Provisioning\NullTenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\SupportAccessContext;
use App\Tenancy\SystemDatabaseHostResolver;
use App\Tenancy\TenantConnectionAttestationHook;
use App\Tenancy\TenantConnectionConfigurationFactory;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseAttestor;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use App\Tenancy\TenantPermissionCache;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantRuntimeState;
use App\Tenancy\TenantSqliteAttestationLock;
use App\Tenancy\TenantSqliteWitnessConnection;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\before;
use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    private const LIVEWIRE_SHOP_MEMO = 'tenantShopId';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $runtimeState = new TenantRuntimeState;

        $this->app->bind(DatabaseHostResolver::class, SystemDatabaseHostResolver::class);
        $this->app->bind(TenantResolver::class, CentralTenantResolver::class);
        $this->app->bind(DatabaseProvisioner::class, DatabaseProvisionerManager::class);
        $this->app->bind(
            MySqlServerConnectionFactory::class,
            LaravelMySqlServerConnectionFactory::class,
        );
        $this->app->singleton(TenantProvisioningHook::class, NullTenantProvisioningHook::class);
        $this->app->singleton(
            TenantConnectionConfigurationFactory::class,
            static fn (Application $application): TenantConnectionConfigurationFactory => new TenantConnectionConfigurationFactory(
                $application->make('config'),
            ),
        );
        $this->app->singleton(
            TenantConnectionAttestationHook::class,
            NullTenantConnectionAttestationHook::class,
        );
        $this->app->bind(
            TenantSqliteWitnessConnection::class,
            PdoTenantSqliteWitnessConnection::class,
        );
        $this->app->singleton(
            TenantContext::class,
            static fn (): TenantContext => new TenantContext($runtimeState),
        );
        $this->app->scoped(
            SupportAccessContext::class,
            static fn (): SupportAccessContext => new SupportAccessContext,
        );
        $this->app->singleton(
            TenantPermissionCache::class,
            static fn (Application $application): TenantPermissionCache => new TenantPermissionCache(
                $application->make('config'),
                $application->make(PermissionRegistrar::class),
            ),
        );
        $this->app->singleton(
            TenantConnectionManager::class,
            static fn (Application $application): TenantConnectionManager => new TenantConnectionManager(
                database: $application->make('db'),
                config: $application->make('config'),
                runtimeState: $runtimeState,
                permissionCache: $application->make(TenantPermissionCache::class),
                auth: $application->make('auth'),
                attestor: new TenantDatabaseAttestor(
                    $application->make('config'),
                    new TenantSqliteAttestationLock(
                        (string) $application->make('config')->get('database.tenant_attestation_lock_path'),
                    ),
                    $application->make(TenantSqliteWitnessConnection::class),
                    $application->make(OpenedTenantDatabaseIdentityVerifier::class),
                ),
                moduleRegistry: $application->make(ModuleRegistry::class),
                configurationFactory: $application->make(TenantConnectionConfigurationFactory::class),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerTenantLivewireUploadBoundary();
        $this->registerTenantLivewireSnapshotBoundary();

        Livewire::setUpdateRoute(
            static fn (array|string $handle, string $path): RoutingRoute => Route::post($path, $handle)
                ->middleware([
                    'web',
                    InitializeSupportAccess::class,
                    InitializeTenancy::class.':optional',
                    EnforceReadOnlySupportAccess::class,
                ])
                ->name('livewire.update'),
        );
        FileUploadController::$defaultMiddleware = [
            'web',
            InitializeSupportAccess::class,
            InitializeTenancy::class.':optional',
            EnforceReadOnlySupportAccess::class,
            EnsureLivewireUploadMatchesTenant::class,
        ];
        FilePreviewController::$middleware = [
            'web',
            InitializeSupportAccess::class,
            InitializeTenancy::class.':optional',
            EnforceReadOnlySupportAccess::class,
            EnsureLivewireUploadMatchesTenant::class,
        ];
        $this->app->make(Router::class)->middlewareGroup('filament.actions', [
            'web',
            InitializeSupportAccess::class,
            InitializeTenancy::class.':optional',
            EnforceReadOnlySupportAccess::class,
            EnsureFilamentActionMatchesTenant::class,
        ]);

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
                static fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('role', PlatformUser::ROLE_SUPER_ADMIN),
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

    private function registerTenantLivewireSnapshotBoundary(): void
    {
        on('dehydrate', static function (mixed $_component, ComponentContext $context): void {
            $tenantContext = resolve(TenantContext::class);
            $context->addMemo(
                self::LIVEWIRE_SHOP_MEMO,
                $tenantContext->initialized() ? $tenantContext->id() : null,
            );
        });

        before('snapshot-verified', static function (array $snapshot): void {
            $memo = $snapshot['memo'] ?? [];

            if (! is_array($memo) || ! array_key_exists(self::LIVEWIRE_SHOP_MEMO, $memo)) {
                abort(404);
            }

            $snapshotShopId = $memo[self::LIVEWIRE_SHOP_MEMO];
            $tenantContext = resolve(TenantContext::class);

            if (! $tenantContext->initialized()) {
                abort_if($snapshotShopId !== null, 404);

                return;
            }

            abort_unless(
                is_string($snapshotShopId) && hash_equals($tenantContext->id(), $snapshotShopId),
                404,
            );
        });
    }

    private function registerTenantLivewireUploadBoundary(): void
    {
        GenerateSignedUploadUrlFacade::swap(new TenantLivewireUploadUrlGenerator(
            $this->app->make(GenerateSignedUploadUrl::class),
            $this->app->make(TenantContext::class),
        ));
    }
}
