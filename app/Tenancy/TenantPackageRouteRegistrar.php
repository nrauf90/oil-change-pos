<?php

namespace App\Tenancy;

use App\Http\Controllers\Auth\PlatformPanelLogoutController;
use App\Http\Controllers\Auth\TenantPanelLogoutController;
use App\Http\Middleware\EnforceReadOnlySupportAccess;
use App\Http\Middleware\InitializeSupportAccess;
use App\Http\Middleware\InitializeTenancy;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Imports\Http\Controllers\DownloadImportFailureCsv;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;

final readonly class TenantPackageRouteRegistrar
{
    private const TENANT_SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    public function __construct(private Application $application) {}

    public function register(): void
    {
        $this->registerPlatformPanelLogoutController();
        $this->registerTenantPanelLogoutController();
        $this->registerTenantBoundFilamentActionRoutes();

        if (! $this->application->environment('local', 'testing')) {
            return;
        }

        Route::post(self::tenantPackagePath(EndpointResolver::updatePath()), [HandleRequests::class, 'handleUpdate'])
            ->middleware([
                'web',
                RequireLivewireHeaders::class,
                InitializeSupportAccess::class,
                InitializeTenancy::class.':optional',
                EnforceReadOnlySupportAccess::class,
            ])
            ->where('tenant', self::TENANT_SLUG_PATTERN)
            ->name('tenant.local.livewire.update');

        Route::post(self::tenantPackagePath(EndpointResolver::uploadPath()), [FileUploadController::class, 'handle'])
            ->middleware([
                'web',
                InitializeSupportAccess::class,
                InitializeTenancy::class.':optional',
                EnforceReadOnlySupportAccess::class,
            ])
            ->where('tenant', self::TENANT_SLUG_PATTERN)
            ->name('tenant.local.livewire.upload-file');

        Route::get(self::tenantPackagePath(EndpointResolver::previewPath()), [FilePreviewController::class, 'handle'])
            ->middleware([
                'web',
                InitializeSupportAccess::class,
                InitializeTenancy::class.':optional',
                EnforceReadOnlySupportAccess::class,
            ])
            ->where('tenant', self::TENANT_SLUG_PATTERN)
            ->name('tenant.local.livewire.preview-file');

        Route::middleware('filament.actions')
            ->name('tenant.local.filament.')
            ->prefix('__tenants/{tenant}/'.trim((string) config('filament.system_route_prefix', 'filament'), '/'))
            ->where(['tenant' => self::TENANT_SLUG_PATTERN])
            ->group(function (): void {
                Route::get('/exports/{export}/download/{tenant_shop_id?}', DownloadExport::class)
                    ->whereUuid(TenantLivewireUploadUrlGenerator::TENANT_CLAIM)
                    ->name('exports.download');

                Route::get('/imports/{import}/failed-rows/download/{tenant_shop_id?}', DownloadImportFailureCsv::class)
                    ->whereUuid(TenantLivewireUploadUrlGenerator::TENANT_CLAIM)
                    ->name('imports.failed-rows.download');
            });
    }

    private function registerTenantBoundFilamentActionRoutes(): void
    {
        foreach ([
            'filament.exports.download' => [
                'filament.package.exports.download',
                DownloadExport::class,
            ],
            'filament.imports.failed-rows.download' => [
                'filament.package.imports.failed-rows.download',
                DownloadImportFailureCsv::class,
            ],
        ] as $canonicalName => [$packageName, $controller]) {
            $packageRoute = $this->routeNamed($canonicalName);

            if (! $packageRoute instanceof RoutingRoute) {
                continue;
            }

            $action = $packageRoute->getAction();
            $action['as'] = $packageName;
            $packageRoute->setAction($action);

            Route::get($packageRoute->uri().'/{tenant_shop_id?}', $controller)
                ->middleware('filament.actions')
                ->whereUuid(TenantLivewireUploadUrlGenerator::TENANT_CLAIM)
                ->name($canonicalName);
        }

        Route::getRoutes()->refreshNameLookups();
    }

    private function registerTenantPanelLogoutController(): void
    {
        $logoutRoute = $this->routeNamed('filament.admin.auth.logout');

        if ($logoutRoute instanceof RoutingRoute) {
            $logoutRoute->uses(TenantPanelLogoutController::class);
            Route::getRoutes()->refreshActionLookups();
        }
    }

    private function registerPlatformPanelLogoutController(): void
    {
        $logoutRoute = $this->routeNamed('filament.platform.auth.logout');

        if ($logoutRoute instanceof RoutingRoute) {
            $logoutRoute->uses(PlatformPanelLogoutController::class);
            Route::getRoutes()->refreshActionLookups();
        }
    }

    private function routeNamed(string $name): ?RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    private static function tenantPackagePath(string $path): string
    {
        return '__tenants/{tenant}/'.ltrim($path, '/');
    }
}
