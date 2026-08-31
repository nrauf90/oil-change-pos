<?php

/*
| Expenses & Cash Flow module routes.
| Loaded from routes/web.php inside the 'auth' group.
|
| Every route carries module:expenses so the owner can switch the whole
| feature off from the admin panel, plus the one permission it needs.
*/

use App\Http\Controllers\CashDrawerController;
use App\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:expenses')->group(function (): void {

    /*
    | Expense logging — the counter records outlays on the fly.
    */
    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view_any')->name('expenses.index');

    Route::get('/expenses/create', [ExpenseController::class, 'create'])
        ->middleware('permission:expenses.create')->name('expenses.create');

    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.create')->name('expenses.store');

    Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])
        ->middleware('permission:expenses.update')->name('expenses.edit');

    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.update')->name('expenses.update');

    // Deleting cash history is an owner's call — managers may correct, not erase.
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expenses.delete')->name('expenses.destroy');

    /*
    | Shift / day reconciliation. Technicians hold none of these permissions,
    | so the cash position never reaches the workshop floor.
    */
    Route::get('/cash-drawer', [CashDrawerController::class, 'index'])
        ->middleware('permission:expenses.view_cash_drawer')->name('cash-drawer.index');
});
