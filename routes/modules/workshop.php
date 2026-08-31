<?php

/*
| Workshop Floor module routes (service history + multi-point inspections).
| Loaded from routes/web.php inside the 'auth' group.
|
| Every route carries module:workshop so the owner can switch the whole
| feature off from the admin panel, and one permission: from Permission.
*/

use App\Http\Controllers\InspectionController;
use App\Http\Controllers\ServiceHistoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:workshop')->group(function (): void {

    /*
    | "Lookup previous vehicle service history by phone number" (PRD §1).
    | Read-only, and price-free for anyone without pricing.view.
    */
    Route::get('/service-history', [ServiceHistoryController::class, 'index'])
        ->middleware('permission:service_history.lookup')->name('service-history.index');

    /*
    | "Log multi-point inspection notes" (PRD §1). A condition report — it
    | carries no prices and raises no bill.
    */
    Route::get('/inspections', [InspectionController::class, 'index'])
        ->middleware('permission:inspections.view_any')->name('inspections.index');

    Route::get('/inspections/create', [InspectionController::class, 'create'])
        ->middleware('permission:inspections.create')->name('inspections.create');

    Route::post('/inspections', [InspectionController::class, 'store'])
        ->middleware(['permission:inspections.create', 'throttle:60,1'])->name('inspections.store');

    Route::get('/inspections/{inspection}', [InspectionController::class, 'show'])
        ->middleware('permission:inspections.view_any')->name('inspections.show');

    Route::get('/inspections/{inspection}/edit', [InspectionController::class, 'edit'])
        ->middleware('permission:inspections.update')->name('inspections.edit');

    Route::put('/inspections/{inspection}', [InspectionController::class, 'update'])
        ->middleware(['permission:inspections.update', 'throttle:60,1'])->name('inspections.update');
});
