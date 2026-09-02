<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\QuickItemController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ScriptController;
use App\Http\Middleware\EnforceReadOnlySupportAccess;
use App\Http\Middleware\InitializeSupportAccess;
use App\Modules\ModuleRegistry;
use App\Tenancy\SupportAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

$tenantRoutes = static function (): void {

    /*
    | Guest routes.
    */
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    /*
    | Everything below requires a signed-in member of staff. Each route also names
    | the single permission it needs — see App\Enums\Permission.
    */
    Route::middleware('auth')->group(function (): void {

        // Sends each member of staff to the first screen their role and the
        // enabled modules actually allow, so nobody signs in straight into a 403
        // or into a module the owner has switched off.
        Route::get('/', function (ModuleRegistry $modules) {
            $first = $modules->navigationFor(auth()->user())[0]['route'] ?? null;

            abort_if($first === null, 403, 'Your account has no screens enabled. Ask the shop owner.');

            return redirect()->route($first);
        })->name('home');

        /*
        | Point of sale — the counter screen.
        */
        Route::middleware('module:sales')->group(function (): void {
            Route::get('/pos', [PosController::class, 'create'])
                ->middleware('permission:pos.use')->name('pos.create');

            Route::get('/customer-vehicles', [PosController::class, 'customerVehicles'])
                ->middleware(['permission:pos.use', 'throttle:120,1'])->name('customer-vehicles.index');

            Route::post('/sales', [SaleController::class, 'store'])
                ->middleware(['permission:sales.create', 'throttle:60,1'])->name('sales.store');

            Route::get('/sales', [SaleController::class, 'index'])
                ->middleware('permission:sales.view_any')->name('sales.index');

            Route::get('/sales/{sale}', [SaleController::class, 'show'])
                ->middleware('permission:sales.view')->name('sales.show');

            Route::get('/sales/{sale}/pdf', [SaleController::class, 'pdf'])
                ->middleware('permission:sales.export_pdf')->name('sales.pdf');

            Route::delete('/sales/{sale}', [SaleController::class, 'destroy'])
                ->middleware('permission:sales.delete')->name('sales.destroy');
        });

        /*
        | On-the-fly inventory: JSON endpoints the sale screen calls without ever
        | navigating away, so no typed-in bill data is lost.
        */
        Route::middleware(['module:inventory', 'throttle:60,1'])->group(function (): void {
            Route::get('/quick-items', [QuickItemController::class, 'index'])
                ->middleware('permission:items.view_any')->name('quick-items.index');

            Route::post('/quick-items', [QuickItemController::class, 'store'])
                ->middleware('permission:items.quick_create')->name('quick-items.store');
        });

        /*
        | Inventory management.
        */
        Route::middleware('module:inventory')->group(function (): void {
            Route::get('/items', [ItemController::class, 'index'])
                ->middleware('permission:items.view_any')->name('items.index');

            Route::get('/items/create', [ItemController::class, 'create'])
                ->middleware('permission:items.create')->name('items.create');

            Route::post('/items', [ItemController::class, 'store'])
                ->middleware('permission:items.create')->name('items.store');

            Route::get('/items/{item}/edit', [ItemController::class, 'edit'])
                ->middleware('permission:items.update')->name('items.edit');

            Route::put('/items/{item}', [ItemController::class, 'update'])
                ->middleware('permission:items.update')->name('items.update');

            Route::delete('/items/{item}', [ItemController::class, 'destroy'])
                ->middleware('permission:items.delete')->name('items.destroy');
        });

        /*
        | Reporting dashboard — daily / weekly / monthly, from actually-charged values.
        */
        Route::get('/reports', [ReportController::class, 'index'])
            ->middleware(['module:reports', 'permission:reports.view_dashboard'])
            ->name('reports.index');

        /*
        | Counter conversation scripts — every role may read these.
        */
        Route::get('/scripts', [ScriptController::class, 'index'])
            ->middleware(['module:scripts', 'permission:scripts.view'])
            ->name('scripts.index');
    });

    /*
    | Module route files. Each pluggable feature owns its own file so adding a
    | module never means editing this one.
    */
    Route::middleware('auth')->group(function (): void {
        foreach (glob(base_path('routes/modules/*.php')) as $moduleRoutes) {
            require $moduleRoutes;
        }
    });
};

if (app()->environment('local', 'testing')) {
    Route::middleware([InitializeSupportAccess::class, 'shop.active', 'tenant', EnforceReadOnlySupportAccess::class])
        ->name('host.')
        ->group($tenantRoutes);

    Route::middleware([InitializeSupportAccess::class, 'shop.active', 'tenant', EnforceReadOnlySupportAccess::class])
        ->prefix('__tenants/{tenant}')
        ->where(['tenant' => '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'])
        ->group($tenantRoutes);
} else {
    Route::middleware([InitializeSupportAccess::class, 'shop.active', 'tenant', EnforceReadOnlySupportAccess::class])
        ->group($tenantRoutes);
}

Route::post('/support-access/exit', function (SupportAccessManager $manager): RedirectResponse {
    $redirectUrl = $manager->centralExitUrl();
    $manager->end();

    return redirect()->away($redirectUrl);
})->middleware([
    InitializeSupportAccess::class,
    'shop.active',
    'tenant',
    EnforceReadOnlySupportAccess::class,
])->name('support-access.exit');
