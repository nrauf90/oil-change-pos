<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MarketingSiteController;
use App\Http\Controllers\OrderController;
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

/*
| The public marketing site — the platform's shop window, not a tenant screen.
|
| It is constrained by DOMAIN rather than guarded by EnsureCentralHost, and that
| distinction is load-bearing. Laravel matches a route by URI first and only then
| runs its middleware, so a middleware-guarded "/" registered ahead of the tenant
| group would match on a tenant hostname too and abort there — taking every
| tenant's sign-in screen down with it. A domain constraint participates in
| matching instead, so tenant hosts simply fall through to the group below.
|
| Locally the central host is the same host that serves the default tenant, so
| a domain-constrained "/" would shadow the POS. There it lives at /site.
*/
if (app()->environment('local', 'testing')) {
    Route::get('/site', MarketingSiteController::class)->name('marketing.home');
} else {
    $centralHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    if (is_string($centralHost) && trim($centralHost) !== '') {
        Route::domain($centralHost)
            ->get('/', MarketingSiteController::class)
            ->name('marketing.home');
    }
}

$tenantRoutes = static function (): void {

    /*
    | Guest routes.
    */
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');

        /*
        | Self-service password reset, for staff who have an email address.
        |
        | Throttled hard: the send endpoint is the one that costs money and can
        | be pointed at someone else's inbox, and the reset endpoint is the one
        | worth guessing tokens against. The broker's own 60s per-address
        | throttle does not limit a caller cycling through many addresses.
        */
        Route::get('/forgot-password', [PasswordResetController::class, 'request'])
            ->name('password.request');

        Route::post('/forgot-password', [PasswordResetController::class, 'email'])
            ->middleware('throttle:6,1')->name('password.email');

        Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
            ->name('password.reset');

        Route::post('/reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:6,1')->name('password.update');
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

            /*
            | Draft bills. The workshop runs several bays at once, so several
            | bills stay open at once. Nothing here can be printed — an invoice
            | exists only once a draft has been completed into a sale.
            */
            Route::get('/orders', [OrderController::class, 'index'])
                ->middleware('permission:draft_sales.view_any')->name('orders.index');

            Route::post('/orders', [OrderController::class, 'store'])
                ->middleware(['permission:draft_sales.create', 'throttle:60,1'])->name('orders.store');

            Route::put('/orders/{order}', [OrderController::class, 'update'])
                ->middleware(['permission:draft_sales.create', 'throttle:60,1'])->name('orders.update');

            Route::post('/orders/{order}/complete', [OrderController::class, 'complete'])
                ->middleware(['permission:draft_sales.complete', 'throttle:60,1'])->name('orders.complete');

            // Discarding someone's work is an owner's call.
            Route::delete('/orders/{order}', [OrderController::class, 'destroy'])
                ->middleware('permission:draft_sales.delete')->name('orders.destroy');
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

            // Never served by URL — a photo is streamed back the same way a
            // receipt is, through an authenticated route.
            Route::get('/items/{item}/image', [ItemController::class, 'image'])
                ->middleware('permission:items.view_any')->name('items.image');
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
