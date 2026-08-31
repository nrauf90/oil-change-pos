<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Modules\ModuleRegistry;
use App\Support\MarginReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * PRD §2: lets the owner assess profit margins from the manually input sale
 * price against the static product cost. Owner-only — PRD §1 keeps managers
 * away from master margin analytics.
 */
class MarginReportPage extends Page
{
    protected string $view = 'filament.pages.margin-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Margins';

    protected static ?string $title = 'Profit margins';

    protected static ?int $navigationSort = 20;

    public string $period = 'month';

    /* -------- Authorization: module gate, then the permission -------- */

    private static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->enabled('reports');
    }

    /**
     * `reports.view_margins` is contributed by ReportsModule, which is not core.
     * The permission survives the module being switched off — only this gate
     * makes the switchboard's promise that the screens go away actually true.
     */
    public static function canAccess(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::ViewMargins->value) ?? false);
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

    public function getReport(): MarginReport
    {
        if ($this->period === 'all') {
            return new MarginReport;
        }

        $days = match ($this->period) {
            'week' => 7,
            'quarter' => 90,
            default => 30,
        };

        return new MarginReport(Carbon::now()->subDays($days)->startOfDay(), Carbon::now());
    }
}
