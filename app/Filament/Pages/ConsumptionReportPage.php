<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Modules\ModuleRegistry;
use App\Support\ConsumptionReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * "Quantity will be added for record to see how much Litres we sell and how
 * much left." — the shop owner. This is that screen.
 *
 * Gated on `items.view_stock`, which Admin and Manager hold and Technician does
 * not. Deliberately *not* gated on margins: the counter has to be able to see
 * that the oil is nearly out, and nothing here reveals a price.
 */
class ConsumptionReportPage extends Page
{
    protected string $view = 'filament.pages.consumption-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Consumption & stock';

    protected static ?string $title = 'Consumption & stock';

    protected static ?string $slug = 'consumption-report';

    protected static ?int $navigationSort = 15;

    public string $period = 'month';

    /* -------- Authorization: module gate, then the permission -------- */

    /**
     * The gate matches MarginReportPage's shape, but `inventory` is a **core**
     * module: ModuleRegistry::enabled() short-circuits core modules to true and
     * setEnabled() refuses to write them, so this check can never actually
     * close. It stays anyway — if inventory is ever demoted from core, the page
     * closes with it instead of being left as the one screen that ignored the
     * switchboard.
     */
    private static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->enabled('inventory');
    }

    public static function canAccess(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::ViewStock->value) ?? false);
    }

    /** @return array<string, string> */
    public function getPeriodOptions(): array
    {
        return [
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
            'quarter' => 'Last 90 days',
            'all' => 'All time',
        ];
    }

    public function getPeriodLabel(): string
    {
        return $this->getPeriodOptions()[$this->period] ?? 'Last 30 days';
    }

    public function getReport(): ConsumptionReport
    {
        if ($this->period === 'all') {
            return new ConsumptionReport;
        }

        $days = match ($this->period) {
            'week' => 7,
            'quarter' => 90,
            default => 30,
        };

        return new ConsumptionReport(Carbon::now()->subDays($days)->startOfDay(), Carbon::now());
    }
}
