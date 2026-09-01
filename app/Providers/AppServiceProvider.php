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
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\SystemDatabaseHostResolver;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DatabaseHostResolver::class, SystemDatabaseHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
