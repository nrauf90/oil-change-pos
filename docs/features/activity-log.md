# Activity log

## Purpose and workflow

The append-only audit trail answers who changed or deleted important operational records. Authorized administrators filter by actor, action, subject, or time and inspect stored snapshots even after the original record is gone.

## Data and code map

- Route/controller/view: `activity-log.index` in [`routes/modules/admin.php`](../../routes/modules/admin.php), [`ActivityLogController`](../../app/Http/Controllers/ActivityLogController.php), [`activity-log/index.blade.php`](../../resources/views/activity-log/index.blade.php)
- Model/schema: [`ActivityLog`](../../app/Models/ActivityLog.php), [`activity log migration`](../../database/migrations/tenant/2026_08_28_000101_create_activity_logs_table.php), [`filter indexes`](../../database/migrations/tenant/2026_08_31_000101_index_activity_logs_for_filtering.php)
- Producers: [`SaleObserver`](../../app/Observers/SaleObserver.php), [`ItemObserver`](../../app/Observers/ItemObserver.php), [`ExpenseObserver`](../../app/Observers/ExpenseObserver.php), [`InspectionObserver`](../../app/Observers/InspectionObserver.php), [`UserObserver`](../../app/Observers/UserObserver.php)
- Registration: [`AppServiceProvider`](../../app/Providers/AppServiceProvider.php)
- Tests: [`ActivityLogTest`](../../tests/Feature/ActivityLogTest.php), [`DestructiveActionTest`](../../tests/Feature/DestructiveActionTest.php)

## Permissions and invariants

- Listing requires `logs.view`; there are deliberately no application update/delete routes.
- Store an actor-name snapshot because `user_id` may be nulled when staff leave. Store subject type/id loosely because the subject may already be deleted.
- `properties` is a JSON snapshot for audit use and must not contain secrets such as password hashes or private uploaded-file contents.
- Observer logging should describe material actions without creating recursive audit events.

