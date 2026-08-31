<?php

/*
| Administration module routes.
| Loaded from routes/web.php inside the 'auth' group.
|
| The activity log is GET-only on purpose: an audit trail that can be edited
| or cleared through the app is not an audit trail.
*/

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\SupplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:admin')->group(function (): void {
    Route::get('/activity-log', [ActivityLogController::class, 'index'])
        ->middleware('permission:logs.view')->name('activity-log.index');

    Route::middleware(['permission:suppliers.manage', 'throttle:120,1'])->group(function (): void {
        Route::resource('suppliers', SupplierController::class);

        Route::get('/suppliers/{supplier}/supplies/create', [SupplyController::class, 'create'])
            ->name('suppliers.supplies.create');
        Route::post('/suppliers/{supplier}/supplies', [SupplyController::class, 'store'])
            ->name('suppliers.supplies.store');
        Route::get('/suppliers/{supplier}/supplies/{supply}', [SupplyController::class, 'show'])
            ->name('suppliers.supplies.show');
        Route::get('/suppliers/{supplier}/supplies/{supply}/bill', [SupplyController::class, 'bill'])
            ->name('suppliers.supplies.bill');

        Route::post('/suppliers/{supplier}/supplies/{supply}/payments', [SupplierPaymentController::class, 'store'])
            ->name('suppliers.supplies.payments.store');
        Route::get('/suppliers/{supplier}/supplies/{supply}/payments/{payment}/receipt', [SupplierPaymentController::class, 'receipt'])
            ->name('suppliers.supplies.payments.receipt');
    });
});
