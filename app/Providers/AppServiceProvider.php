<?php

namespace App\Providers;

use App\Http\Middleware\EnsureLivewireUploadMatchesTenant;
use App\Http\Middleware\InitializeTenancy;
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
use App\Tenancy\PdoTenantSqliteWitnessConnection;
use App\Tenancy\SystemDatabaseHostResolver;
use App\Tenancy\TenantConnectionAttestationHook;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseAttestor;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantRuntimeState;
use App\Tenancy\TenantSqliteAttestationLock;
use App\Tenancy\TenantSqliteWitnessConnection;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Imports\Http\Controllers\DownloadImportFailureCsv;
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
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\before;
use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    private const LIVEWIRE_SHOP_MEMO = 'tenantShopId';

    private const TENANT_SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $runtimeState = new TenantRuntimeState;

        $this->app->bind(DatabaseHostResolver::class, SystemDatabaseHostResolver::class);
        $this->app->bind(TenantResolver::class, CentralTenantResolver::class);
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
        $this->app->singleton(
            TenantConnectionManager::class,
            static fn (Application $application): TenantConnectionManager => new TenantConnectionManager(
                database: $application->make('db'),
                config: $application->make('config'),
                runtimeState: $runtimeState,
                permissionRegistrar: $application->make(PermissionRegistrar::class),
                auth: $application->make('auth'),
                attestor: new TenantDatabaseAttestor(
                    $application->make('config'),
                    new TenantSqliteAttestationLock(
                        (string) $application->make('config')->get('database.tenant_attestation_lock_path'),
                    ),
                    $application->make(TenantSqliteWitnessConnection::class),
                ),
                moduleRegistry: $application->make(ModuleRegistry::class),
            ),
        );
        $this->app->booting(function (): void {
            $this->registerLocalTenantPackageRoutes();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerTenantLivewireUploadBoundary();
        $this->registerTenantLivewireSnapshotBoundary();

        if ($this->app->environment('local', 'testing')) {
            Livewire::setUpdateRoute(
                static fn (array|string $handle, string $path): RoutingRoute => Route::post($path, $handle)
                    ->middleware(['web', InitializeTenancy::class.':optional'])
                    ->name('tenant.host.livewire.update'),
            );
        }

        Livewire::setUpdateRoute(
            static fn (array|string $handle, string $path): RoutingRoute => Route::post(
                self::tenantPackagePath($path),
                $handle,
            )
                ->middleware(['web', InitializeTenancy::class.':optional'])
                ->where('tenant', self::TENANT_SLUG_PATTERN)
                ->name('tenant.livewire.update'),
        );
        FileUploadController::$defaultMiddleware = [
            'web',
            InitializeTenancy::class.':optional',
            EnsureLivewireUploadMatchesTenant::class,
        ];
        FilePreviewController::$middleware = [
            'web',
            InitializeTenancy::class.':optional',
            EnsureLivewireUploadMatchesTenant::class,
        ];
        $this->app->make(Router::class)->middlewareGroup('filament.actions', [
            'web',
            InitializeTenancy::class.':optional',
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

    private function registerLocalTenantPackageRoutes(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            return;
        }

        Route::post(self::tenantPackagePath(EndpointResolver::uploadPath()), [FileUploadController::class, 'handle'])
            ->middleware(['web', InitializeTenancy::class.':optional'])
            ->where('tenant', self::TENANT_SLUG_PATTERN)
            ->name('livewire.upload-file');

        Route::get(self::tenantPackagePath(EndpointResolver::previewPath()), [FilePreviewController::class, 'handle'])
            ->middleware(['web', InitializeTenancy::class.':optional'])
            ->where('tenant', self::TENANT_SLUG_PATTERN)
            ->name('livewire.preview-file');

        Route::middleware('filament.actions')
            ->name('filament.')
            ->prefix('__tenants/{tenant}/'.trim((string) config('filament.system_route_prefix', 'filament'), '/'))
            ->where(['tenant' => self::TENANT_SLUG_PATTERN])
            ->group(function (): void {
                Route::get('/exports/{export}/download', DownloadExport::class)
                    ->name('exports.download');

                Route::get('/imports/{import}/failed-rows/download', DownloadImportFailureCsv::class)
                    ->name('imports.failed-rows.download');
            });
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

    private static function tenantPackagePath(string $path): string
    {
        if (! app()->environment('local', 'testing')) {
            return $path;
        }

        return '__tenants/{tenant}/'.ltrim($path, '/');
    }
}
