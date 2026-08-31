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
use App\Observers\ExpenseObserver;
use App\Observers\InspectionObserver;
use App\Observers\ItemObserver;
use App\Observers\SaleObserver;
use App\Observers\SupplierObserver;
use App\Observers\SupplierPaymentObserver;
use App\Observers\SupplyObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
