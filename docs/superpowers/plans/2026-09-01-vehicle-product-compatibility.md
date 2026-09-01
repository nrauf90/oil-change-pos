# Vehicle Product Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff classify products as universal or compatible with multiple make/model/year ranges and filter products consistently in inventory and POS.

**Architecture:** Normalize makes, models, and item compatibility ranges. Put matching rules in one `Item` query scope, expose a shared compatibility payload to both interfaces, and save quick-added product compatibility transactionally.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js, SQLite.

**Spec:** `docs/features/vehicle-product-compatibility.md`

## Global Constraints

- Existing products become universal during migration so the current catalogue remains visible.
- Repair items cannot store vehicle compatibility.
- Universal products cannot retain compatibility rows.
- Year bounds are nullable and inclusive; `year_from` cannot exceed `year_to`.
- Make/model names are normalized and unique without case-only duplicates.
- Do not change dependencies.
- Preserve unrelated working-tree changes, especially the next-checkup additions in the POS and sale flow.
- Every behavior change follows red-green-refactor and every changed PHP file is formatted with `vendor/bin/pint --dirty --format agent`.

---

### Task 1: Compatibility schema and domain contracts

**Files:**
- Create: `database/migrations/*_create_vehicle_makes_table.php`
- Create: `database/migrations/*_create_vehicle_models_table.php`
- Create: `database/migrations/*_add_is_universal_to_items_table.php`
- Create: `database/migrations/*_create_item_vehicle_compatibilities_table.php`
- Create: `app/Models/VehicleMake.php`
- Create: `app/Models/VehicleModel.php`
- Create: `app/Models/ItemVehicleCompatibility.php`
- Create: `database/factories/VehicleMakeFactory.php`
- Create: `database/factories/VehicleModelFactory.php`
- Create: `database/factories/ItemVehicleCompatibilityFactory.php`
- Modify: `app/Models/Item.php`
- Modify: `database/factories/ItemFactory.php`
- Test: `tests/Feature/VehicleProductCompatibilityTest.php`

**Interfaces:**
- Produces: `Item::vehicleCompatibilities(): HasMany`
- Produces: `Item::scopeCompatibleWith(Builder $query, ?int $makeId, ?int $modelId, ?int $year): void`
- Produces: `VehicleMake::vehicleModels(): HasMany`
- Produces: `VehicleModel::vehicleMake(): BelongsTo`
- Produces: `VehicleModel::itemCompatibilities(): HasMany`
- Produces: `ItemVehicleCompatibility::item(): BelongsTo` and `vehicleModel(): BelongsTo`

- [ ] **Step 1: Read test guidance and generate files through Artisan**

Read `test-driven-development/writing-good-tests.md` and the project `testing-best-practices` skill. Use `php artisan make:model ... --factory --no-interaction`, `php artisan make:migration ... --no-interaction`, and `php artisan make:test --phpunit VehicleProductCompatibilityTest --no-interaction`.

- [ ] **Step 2: Write failing domain tests**

Cover these exact behaviors with real database records:

```php
public function test_a_product_can_have_several_model_and_year_compatibilities(): void;
public function test_vehicle_model_names_are_unique_within_a_make(): void;
public function test_compatibility_rejects_an_inverted_year_range(): void;
public function test_vehicle_filter_includes_universal_and_matching_specific_products(): void;
public function test_vehicle_filter_honours_open_and_inclusive_year_bounds(): void;
public function test_vehicle_filter_excludes_non_matching_products(): void;
```

- [ ] **Step 3: Verify red**

Run `php artisan test --compact tests/Feature/VehicleProductCompatibilityTest.php`. Confirm failures come from missing tables, relationships, and scope.

- [ ] **Step 4: Implement the normalized schema and models**

Use foreign keys with cascade deletion for owned compatibility data and restrict/cascade semantics that prevent orphan models. Add indexes for make/model/year filtering. In the items migration, add `is_universal` default `false`, then update existing product rows to `true` without changing repairs.

Implement matching as:

```php
$query->where(function (Builder $items) use ($makeId, $modelId, $year): void {
    $items->where('is_universal', true)
        ->orWhereHas('vehicleCompatibilities', function (Builder $compatibilities) use ($makeId, $modelId, $year): void {
            $compatibilities
                ->when($makeId, fn (Builder $q) => $q->whereHas('vehicleModel', fn (Builder $models) => $models->where('vehicle_make_id', $makeId)))
                ->when($modelId, fn (Builder $q) => $q->where('vehicle_model_id', $modelId))
                ->when($year, fn (Builder $q) => $q
                    ->where(fn (Builder $bounds) => $bounds->whereNull('year_from')->orWhere('year_from', '<=', $year))
                    ->where(fn (Builder $bounds) => $bounds->whereNull('year_to')->orWhere('year_to', '>=', $year)));
        });
});
```

Do not apply the scope when all filter inputs are empty.

- [ ] **Step 5: Verify green and commit the base**

Run the focused test, migrate from a fresh test database, format changed PHP, rerun the focused test, and commit only Task 1 files with `feat: add vehicle compatibility domain`.

---

### Task 2: Inventory create/edit and filters

**Files:**
- Modify: `app/Filament/Resources/Items/Schemas/ItemForm.php`
- Modify: `app/Filament/Resources/Items/Tables/ItemsTable.php`
- Modify: `app/Filament/Resources/Items/Pages/CreateItem.php`
- Modify: `app/Filament/Resources/Items/Pages/EditItem.php`
- Test: `tests/Feature/ItemManagementTest.php`

**Interfaces:**
- Consumes: Task 1 relationships and `scopeCompatibleWith`.
- Produces: persisted `is_universal` and `vehicleCompatibilities` from Filament item forms.

- [ ] **Step 1: Write failing Filament feature tests**

Add tests proving an owner can create a universal product, create a vehicle-specific product with multiple compatibility rows, edit rows without leaving stale links, cannot submit an inverted range, and can filter by make/model/year while universal products remain listed.

- [ ] **Step 2: Verify red**

Run `php artisan test --compact tests/Feature/ItemManagementTest.php` and confirm the new assertions fail because controls and persistence are absent.

- [ ] **Step 3: Implement the product-only compatibility form**

Use a live universal toggle and a relationship-backed repeater. Each row has a non-dehydrated make selector, dependent model selector, and nullable year bounds. Add create-option forms for missing makes and models. Hide and clear compatibility state for repairs and universal products. Sync rows in the resource page mutation/after-save hooks inside a transaction.

- [ ] **Step 4: Implement inventory filters and summary**

Add Make, dependent Model, and Year controls to a custom table filter that calls `compatibleWith`. Add a compact compatibility column such as `Universal` or `Toyota Corolla 2009–2013, Honda Civic all years`.

- [ ] **Step 5: Verify, format, and commit**

Run the focused ItemManagement tests, format changed PHP, rerun them, and commit Task 2 files with `feat: manage product vehicle compatibility`.

---

### Task 3: POS quick-add compatibility API

**Files:**
- Modify: `app/Http/Requests/QuickItemRequest.php`
- Modify: `app/Http/Controllers/QuickItemController.php`
- Modify: `app/Http/Controllers/PosController.php`
- Test: `tests/Feature/QuickAddItemTest.php`

**Interfaces:**
- Consumes: Task 1 models and relationships.
- Produces request fields `is_universal: bool` and `compatibilities: array<int, {vehicle_make_id?: int, vehicle_make_name?: string, vehicle_model_id?: int, vehicle_model_name?: string, year_from?: int, year_to?: int}>`.
- Produces POS payload keys `vehicle_makes` and item key `is_universal` plus compatibility IDs/ranges.

- [ ] **Step 1: Write failing request and transaction tests**

Cover universal quick-add, multiple existing model links, creation of missing make/model, reuse of names without case-only duplication, invalid model ownership, inverted ranges, required rows for specific products, ignored/rejected compatibility for repairs, and rollback when any row fails.

- [ ] **Step 2: Verify red**

Run `php artisan test --compact tests/Feature/QuickAddItemTest.php` and confirm the missing validation and persistence cause the failures.

- [ ] **Step 3: Implement validation and transactional persistence**

Validate conditional arrays in `QuickItemRequest`, then use one database transaction in `QuickItemController`. Resolve an existing make/model by ID or normalize and `firstOrCreate` a supplied name. Confirm selected models belong to selected makes before creating compatibility rows. Return the created product through `PosController::present` with its compatibility loaded.

- [ ] **Step 4: Expose filter reference data**

Load ordered makes with ordered models in `PosController::create`. Keep unit-cost data conditional on the existing permission.

- [ ] **Step 5: Verify, format, and commit**

Run QuickAddItem and Authorization tests, format changed PHP, rerun both, and commit Task 3 files with `feat: save compatibility from POS quick add`.

---

### Task 4: POS vehicle filters and quick-add interface

**Files:**
- Modify: `resources/views/pos/create.blade.php`
- Modify: `tests/Feature/PosCategoryRailTest.php`
- Modify: `tests/Feature/PosDispenseUiTest.php`

**Interfaces:**
- Consumes: Task 3 `vehicle_makes` config and item compatibility payload.
- Produces: Alpine state `vehicleFilter = { makeId: '', modelId: '', year: '' }` and compatibility rows in `quickAdd`.

- [ ] **Step 1: Write failing rendered-UI tests**

Assert the POS receives make/model/year controls, an All vehicles reset, product-only universal/specific controls, repeatable compatibility rows, and payload field names. Assert existing next-checkup fields remain present.

- [ ] **Step 2: Verify red**

Run the two focused POS UI test files and confirm failures identify missing controls and state.

- [ ] **Step 3: Implement filtering**

Add dependent Make, Model, and numeric Year controls near the product search/category rail. The computed item list must keep services governed by existing filters, include universal products under active vehicle filters, and include specific products only when a compatibility row matches every supplied field and inclusive year bounds.

- [ ] **Step 4: Implement quick-add compatibility rows**

For products, show Universal by default and allow switching to Vehicle specific. Rows select or create make/model names and optional bounds. Preserve modal values after HTTP 422, display nested validation errors, and update local make/model options after successful creation. Do not alter the cart, customer vehicle, next-checkup, pricing, or measured-stock behavior.

- [ ] **Step 5: Verify and commit**

Run the focused POS UI and QuickAddItem tests, run `npm run build`, and commit Task 4 files with `feat: filter POS products by vehicle`.

---

### Task 5: Seed reference vehicles and update feature documentation

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `docs/features/vehicle-product-compatibility.md`
- Test: `tests/Feature/VehicleProductCompatibilityTest.php`

**Interfaces:**
- Consumes: Task 1 make/model contracts.
- Produces: idempotent starter makes/models and documented operator behavior.

- [ ] **Step 1: Write a failing idempotency test**

Run the staff/catalogue seeder twice in the test and assert each starter make/model appears once and existing item compatibility is not overwritten.

- [ ] **Step 2: Verify red**

Run the compatibility test file and confirm the expected starter vehicles are absent.

- [ ] **Step 3: Add conservative starter reference data**

Seed only makes/models already named by the application catalogue, using `firstOrCreate`. Do not guess compatibility assignments for existing parts; migrated products stay universal until staff classifies them.

- [ ] **Step 4: Update operator-facing feature documentation**

Add exact instructions for marking a product universal, assigning several model/year ranges, creating a missing make/model, and using filters in POS and inventory. Keep the design decisions already recorded in the document.

- [ ] **Step 5: Verify and commit**

Run the focused compatibility test, format the seeder, rerun the test, and commit Task 5 files with `docs: explain vehicle compatibility workflow`.

---

### Task 6: Integration, independent review, and final verification

**Files:**
- Review: complete diff from commit `48d7861` through implementation HEAD plus any uncommitted task changes.
- Modify: only files required to address confirmed review findings.

- [ ] **Step 1: Integrate parallel task commits**

Inspect each task diff for overlapping edits and preserve the pre-existing next-checkup work. Run focused suites after resolving any overlap.

- [ ] **Step 2: Run focused verification**

Run:

```text
php artisan test --compact tests/Feature/VehicleProductCompatibilityTest.php
php artisan test --compact tests/Feature/ItemManagementTest.php
php artisan test --compact tests/Feature/QuickAddItemTest.php
php artisan test --compact tests/Feature/PosCategoryRailTest.php tests/Feature/PosDispenseUiTest.php
php artisan test --compact tests/Feature/AuthorizationTest.php tests/Feature/UnitCostConfidentialityTest.php
```

- [ ] **Step 3: Dispatch a separate read-only review agent**

Give the review agent the implementation diff and instruct it to report defect-first findings only, ordered P0–P3. It must inspect migrations, authorization, validation, query behavior, UI state preservation, and tests without editing files.

- [ ] **Step 4: Resolve every confirmed finding through TDD**

For each valid finding, add or adjust a failing regression test, verify red, apply the smallest production fix, and verify green. Record rejected findings with concrete code/test evidence.

- [ ] **Step 5: Final verification**

Run `vendor/bin/pint --dirty --format agent`, the full `php artisan test --compact`, and `npm run build`. Inspect `git diff --check` and `git status --short`. Commit only feature-owned files, leaving unrelated user changes uncommitted.

