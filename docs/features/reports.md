# Reports

## Purpose and workflow

The reports screen summarizes actually charged revenue over daily, weekly, or monthly windows. The admin dashboard places the financial overview above its chart and shares one Today / This week / This month filter across both. Each overview card shows the previous equivalent period's total plus the signed amount and percentage difference. Today compares with yesterday, week with last week, and month with last month. Solid chart lines are current and dashed lines are previous. Filament pages also provide detailed margin analysis and inventory consumption, including measured-stock groupings and below-cost/uncosted warnings.

## Code map

- Dashboard: [`ReportController`](../../app/Http/Controllers/ReportController.php), [`SalesReport`](../../app/Support/SalesReport.php), [`reports/index.blade.php`](../../resources/views/reports/index.blade.php)
- Admin dashboard metrics: [`AdminDashboardMetrics`](../../app/Support/AdminDashboardMetrics.php), [`TodayFinancialStats`](../../app/Filament/Widgets/TodayFinancialStats.php), [`MonthlyFinancialChart`](../../app/Filament/Widgets/MonthlyFinancialChart.php)
- Margin: [`MarginReportPage`](../../app/Filament/Pages/MarginReportPage.php), [`MarginReport`](../../app/Support/MarginReport.php), [`margin view`](../../resources/views/filament/pages/margin-report.blade.php)
- Consumption: [`ConsumptionReportPage`](../../app/Filament/Pages/ConsumptionReportPage.php), [`ConsumptionReport`](../../app/Support/ConsumptionReport.php), [`consumption view`](../../resources/views/filament/pages/consumption-report.blade.php)
- Route/module: `reports.index` in [`routes/web.php`](../../routes/web.php), [`ReportsModule`](../../app/Modules/Features/ReportsModule.php)
- Tests: [`DashboardTest`](../../tests/Feature/DashboardTest.php), [`AdminDashboardWidgetTest`](../../tests/Feature/AdminDashboardWidgetTest.php), [`MarginReportTest`](../../tests/Feature/MarginReportTest.php), [`ConsumptionReportTest`](../../tests/Feature/ConsumptionReportTest.php), [`MoneyParityTest`](../../tests/Feature/MoneyParityTest.php)

## Permissions and invariants

- `reports.view_dashboard` opens reports; `reports.view_financials` and `reports.view_margins` protect sensitive breakdowns.
- Reports depend on the sales module and must use stored charged-price and cost snapshots, not current catalog values.
- Date windows and timezone boundaries must remain consistent across headline, breakdown, and detail queries.
- Uncosted revenue must be shown explicitly rather than treated as zero-cost profit.
- Admin dashboard financial widgets require `reports.view_margins`. Expenses include every payment method; gross margin excludes sales lines without a recorded cost.
