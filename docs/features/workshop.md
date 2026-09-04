# Workshop: service history and inspections

## Purpose and workflow

Staff look up prior vehicle visits by phone or plate. Technicians create multi-point condition reports, optionally linked to a sale, with a status and note for every inspection point. Reports can be viewed and corrected but do not set prices or create charges.

## Data and code map

- Routes: [`routes/modules/workshop.php`](../../routes/modules/workshop.php)
- History: [`ServiceHistoryController`](../../app/Http/Controllers/ServiceHistoryController.php), [`ServiceHistory`](../../app/Support/ServiceHistory.php), [`service-history/index.blade.php`](../../resources/views/service-history/index.blade.php)
- Inspections: [`InspectionController`](../../app/Http/Controllers/InspectionController.php), [`InspectionRequest`](../../app/Http/Requests/InspectionRequest.php), [`Inspection`](../../app/Models/Inspection.php), [`InspectionItem`](../../app/Models/InspectionItem.php), [`inspection views`](../../resources/views/inspections)
- Schema/enums: [`inspections migration`](../../database/migrations/tenant/2026_08_27_000310_create_inspections_table.php), [`inspection items migration`](../../database/migrations/tenant/2026_08_27_000320_create_inspection_items_table.php), [`InspectionPoint`](../../app/Enums/InspectionPoint.php), [`InspectionStatus`](../../app/Enums/InspectionStatus.php)
- Audit: [`InspectionObserver`](../../app/Observers/InspectionObserver.php)
- Tests: [`ServiceHistoryTest`](../../tests/Feature/ServiceHistoryTest.php), [`InspectionTest`](../../tests/Feature/InspectionTest.php)

## Permissions and invariants

- History uses `service_history.lookup`; inspection listing, creation, and update have separate `inspections.*` permissions. Writes are throttled.
- Service history must hide prices unless the user also has `pricing.view`.
- An inspection has no money columns. Each inspection point occurs at most once per report and retains procedure order through `position`.
- `inspected_by` comes from the session, never request input. Nested sale/vehicle context must be validated, and a deleted sale must not delete an inspection.

