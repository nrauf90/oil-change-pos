# Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn today's core-with-automotive-baked-in into a core plus one vertical pack, with no behaviour change for the live shop.

**Architecture:** A `VerticalPack` subclass of `Module` declaring nine seams. A `product_types` table whose mode columns select strategy objects. Pack-owned migrations applied when a pack is enabled. Shared POS Blade components that pack screens compose.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js, SQLite (test), MySQL (production).

**Spec:** [`vertical-packs.md`](../../specs/2026-09-05-multi-vertical-pos/vertical-packs.md), [`product-types.md`](../../specs/2026-09-05-multi-vertical-pos/product-types.md), [`pack-scoped-migrations.md`](../../specs/2026-09-05-multi-vertical-pos/pack-scoped-migrations.md), [`pos-screen-composition.md`](../../specs/2026-09-05-multi-vertical-pos/pos-screen-composition.md), [`pack-oil-change.md`](../../specs/2026-09-05-multi-vertical-pos/pack-oil-change.md)

---

## Brainstorm

**What this phase is.** Not a feature. A refactor that creates the seam every later vertical plugs into, proven by extracting the one trade already running.

**Why the live shop is the acceptance test.** Voltera Garage is in production with real sales, expenses and vehicle history. If extraction changes any behaviour there, the seam is wrong. That constraint is more useful than any amount of design review.

**Decisions taken without asking:**

- A shop may enable **several packs**, one marked primary. A petrol station with a mini-mart is normal and a single-pack model would be wrong from day one.
- Disabling a pack **never drops tables**. A mis-click must not be destructive, and re-enabling must be lossless.
- The generic retail pack is the **fallback** for a shop with no vertical. That is what makes rollout safe: existing shops keep working before anyone picks a vertical.
- Product type modes select **strategy objects resolved from the container**, not match statements. A match statement in the core is the dependency rule broken with extra steps.
- The vehicle columns come off `sales` in this phase. They are the one place the core already knows about a trade, and leaving them makes every later task ambiguous about where trade data belongs.

**Deliberately not here:** price book, customer entity, order lifecycle. Each is forced into existence by a later vertical and building them now means designing them without a user.

## Global Constraints

- **Zero behaviour change for the live shop.** Same screens, same invoices, same reports, same permissions.
- The full existing suite passes at every task boundary without weakening an assertion.
- Migrations touching `sales` are additive first, backfilled, verified, and only then subtractive.
- No pack may be referenced from core code once Task 4 lands.

---

### Task 1: `product_types` table and model

**Files:**
- Create: `database/migrations/tenant/*_create_product_types_table.php`
- Create: `app/Models/ProductType.php`
- Create: `database/factories/ProductTypeFactory.php`
- Test: `tests/Feature/ProductTypeTest.php`

**Interfaces:**
- Produces: `ProductType::items(): HasMany`

- [ ] **Step 1: Generate files through Artisan**

`php artisan make:model ProductType --factory --no-interaction`, `php artisan make:migration create_product_types_table --path=database/migrations/tenant --no-interaction`, `php artisan make:test --phpunit ProductTypeTest --no-interaction`.

- [ ] **Step 2: Write failing tests**

```php
public function test_a_product_type_stores_its_stock_pricing_and_unit_modes(): void;
public function test_a_product_type_key_is_unique(): void;
public function test_an_attribute_schema_is_cast_to_an_array(): void;
```

- [ ] **Step 3: Verify red**

- [ ] **Step 4: Implement**

Columns per the spec: `key` unique, `name`, `pack_key` nullable, `stock_mode`, `pricing_mode`, `unit_mode`, `has_variants`, `has_modifiers`, `has_barcode`, `is_sellable`, `attribute_schema` json, `pos_render`, `icon`, `colour`, `sort`. Back the three modes with enums (`StockMode`, `PricingMode`, `UnitMode`) using TitleCase cases.

- [ ] **Step 5: Verify green and format**

---

### Task 2: Link items to product types

**Files:**
- Create: `database/migrations/tenant/*_add_product_type_id_to_items_table.php`
- Modify: `app/Models/Item.php`, `database/factories/ItemFactory.php`
- Test: `tests/Feature/ProductTypeTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_an_item_belongs_to_a_product_type(): void;
public function test_an_item_without_a_product_type_is_still_readable(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Nullable `product_type_id` with `nullOnDelete`. Nullable is required: existing items have no type until Task 3 backfills them.

- [ ] **Step 4: Verify green and format**

---

### Task 3: Backfill existing items onto seeded types

**Files:**
- Create: `database/migrations/tenant/*_backfill_item_product_types.php`
- Test: `tests/Feature/ProductTypeBackfillTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_measured_products_backfill_to_the_oil_type(): void;
public function test_piece_products_backfill_to_the_part_type(): void;
public function test_repairs_backfill_to_the_service_type(): void;
public function test_the_backfill_is_idempotent(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Map by `ItemType` and `UnitOfMeasure`: measured `Product` to `oil`, piece `Product` to `part`, `Repair` to `service` with `stock_mode: none` and `pricing_mode: manual`. Must be re-runnable — adoption requires it.

- [ ] **Step 4: Verify green and format**

---

### Task 4: `VerticalPack` contract and the two architecture tests

**Files:**
- Create: `app/Modules/VerticalPack.php`
- Create: `tests/Architecture/CoreKnowsNoPackTest.php`
- Create: `tests/Architecture/NoVerticalLiteralsTest.php`

**Interfaces:**
- Produces: `VerticalPack::productTypes(): array`, `orderFieldGroups(): array`, `posScreen(): string`, `documents(): array`, `reports(): array`, `seed(TenantSeedContext): void`

- [ ] **Step 1: Write the architecture tests first**

```php
public function test_no_core_class_references_a_pack_namespace(): void;
public function test_no_shared_code_contains_a_pack_key_literal(): void;
```

Both must **fail the build**, not warn. They are the reason the rest of this plan holds.

- [ ] **Step 2: Verify red**

They pass trivially today with no packs. Add a temporary violating fixture, confirm each test catches it, then remove the fixture.

- [ ] **Step 3: Implement `VerticalPack`**

Abstract, extending `Module`. Default every seam to an empty return so a minimal pack is short.

- [ ] **Step 4: Verify green and format**

---

### Task 5: Pack registration and resolution

**Files:**
- Modify: `app/Modules/ModuleRegistry.php`
- Create: `app/Modules/PackRegistry.php`
- Test: `tests/Feature/PackRegistryTest.php`

**Interfaces:**
- Produces: `PackRegistry::packs(): Collection`, `PackRegistry::primaryFor(Shop $shop): VerticalPack`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_registered_pack_is_discoverable_by_key(): void;
public function test_a_shop_with_no_vertical_resolves_to_the_generic_retail_pack(): void;
public function test_an_unknown_pack_key_fails_closed(): void;
public function test_a_pack_cannot_enable_a_module_the_platform_withheld(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Follow the existing registry's fail-closed behaviour for unknown keys.

- [ ] **Step 4: Verify green and format**

---

### Task 6: `vertical` column on shops and the create-form selector

**Files:**
- Create: `database/migrations/central/*_add_vertical_to_shops_table.php`
- Modify: `app/Models/Central/Shop.php`, `app/Filament/Platform/Resources/Shops/Schemas/ShopForm.php`
- Test: `tests/Feature/Platform/ShopVerticalTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_shop_stores_its_vertical(): void;
public function test_the_create_form_offers_every_registered_pack(): void;
public function test_a_shop_created_without_a_vertical_is_valid(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Nullable string. Soft preset: it seeds, it never forbids.

- [ ] **Step 4: Verify green and format**

---

### Task 7: CORE — pack-owned migration directories

**Files:**
- Create: `app/Tenancy/Provisioning/PackMigrationPaths.php`
- Test: `tests/Feature/Tenancy/PackMigrationPathsTest.php`

**Interfaces:**
- Produces: `PackMigrationPaths::for(Shop $shop): array`

- [ ] **Step 1: Write failing tests**

```php
public function test_paths_include_core_migrations_for_every_shop(): void;
public function test_paths_include_only_enabled_packs(): void;
public function test_enabling_a_pack_adds_its_path(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

- [ ] **Step 4: Verify green and format**

---

### Task 8: CORE — `tenants:migrate` computes the expected set per shop

**Files:**
- Modify: `app/Console/Commands/…` (the `tenants:migrate` command)
- Test: `tests/Feature/Console/TenantsMigratePerPackTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_shop_runs_core_plus_its_enabled_pack_migrations(): void;
public function test_a_shop_does_not_run_another_packs_migrations(): void;
public function test_the_command_is_idempotent_per_shop(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

- [ ] **Step 4: Verify green and format**

---

### Task 9: CORE — enabling a pack runs its migrations

**Files:**
- Modify: `app/Actions/Tenancy/UpdateShopFeatureEntitlements.php`
- Create: `app/Actions/Tenancy/InstallPackForShop.php`
- Test: `tests/Feature/Tenancy/InstallPackForShopTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_enabling_a_pack_creates_its_tables(): void;
public function test_enabling_a_pack_twice_is_harmless(): void;
public function test_disabling_a_pack_does_not_drop_its_tables(): void;
public function test_a_failed_pack_install_records_a_failure_message(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Reuse the existing provisioning lease and failure-message mechanism rather than inventing a second one.

- [ ] **Step 4: Verify green and format**

---

### Task 10: CORE — adoption and health report against the per-shop set

**Files:**
- Modify: `app/Actions/Tenancy/AdoptExistingDatabase.php`, `app/Actions/Tenancy/CollectShopHealth.php`
- Modify: `tests/Feature/Console/AdoptExistingTenantTest.php`
- Test: `tests/Feature/Tenancy/PerShopHealthTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_health_reports_current_for_a_shop_missing_only_another_packs_tables(): void;
public function test_health_reports_pending_when_an_enabled_packs_migration_is_missing(): void;
public function test_adoption_validates_against_the_shops_own_expected_set(): void;
```

The second is the likely silent failure: a shop reporting "current" while missing a newly enabled pack's tables. Test it explicitly.

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement, and update the adoption test's fixed expectations to be per-pack**

- [ ] **Step 4: Verify green and format**

---

### Task 11: CORE — extract the POS cart component

**Files:**
- Create: `resources/views/components/pos/cart.blade.php`
- Modify: `resources/views/pos/create.blade.php`
- Test: `tests/Feature/PosCartComponentTest.php`

- [ ] **Step 1: Write a failing render test**

```php
public function test_the_cart_component_renders_lines_and_line_totals(): void;
```

- [ ] **Step 2: Verify red**

- [ ] **Step 3: Implement**

Move markup only. The component must not know which trade renders it.

- [ ] **Step 4: Verify the existing POS tests still pass, then format**

---

### Task 12: CORE — extract the totals component

**Files:**
- Create: `resources/views/components/pos/totals.blade.php`
- Modify: `resources/views/pos/create.blade.php`
- Test: `tests/Feature/PosTotalsComponentTest.php`

- [ ] **Step 1: Write a failing render test** for subtotal, labour, misc and grand total
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 13: CORE — extract the payment component

**Files:**
- Create: `resources/views/components/pos/payment.blade.php`
- Modify: `resources/views/pos/create.blade.php`
- Test: `tests/Feature/PosPaymentComponentTest.php`

- [ ] **Step 1: Write a failing render test**
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 14: CORE — extract the item picker component

**Files:**
- Create: `resources/views/components/pos/item-picker.blade.php`
- Modify: `resources/views/pos/create.blade.php`
- Test: `tests/Feature/PosItemPickerComponentTest.php`

- [ ] **Step 1: Write a failing render test** covering search, type filter and stock badges
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 15: CORE — order field group contract

**Files:**
- Create: `app/Pos/FieldGroup.php`
- Create: `app/Pos/FieldGroupRegistry.php`
- Test: `tests/Feature/Pos/FieldGroupTest.php`

**Interfaces:**
- Produces: `FieldGroup::rules(): array`, `FieldGroup::view(): string`, `FieldGroup::store(Order|Sale $subject, array $data): void`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_field_group_contributes_its_validation_rules(): void;
public function test_a_field_group_stores_to_its_own_entity(): void;
public function test_a_field_group_is_hidden_without_its_permission(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 16: PACK — create the oil-change pack shell

**Files:**
- Create: `app/Packs/OilChange/OilChangePack.php`
- Test: `tests/Feature/Packs/OilChangePackTest.php`

- [ ] **Step 1: Write failing tests** for key, title, and registration in `PackRegistry`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — seams empty for now
- [ ] **Step 4: Verify green and format**

---

### Task 17: PACK — oil-change product types (seam S1)

**Files:**
- Modify: `app/Packs/OilChange/OilChangePack.php`
- Test: `tests/Feature/Packs/OilChangePackTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_the_pack_declares_oil_part_and_service_types(): void;
public function test_the_oil_type_is_measured_and_lot_free(): void;
public function test_the_service_type_holds_no_stock_and_prices_manually(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 18: PACK — move seed data into the pack (seam S9)

**Files:**
- Create: `app/Packs/OilChange/Seed/OilChangeSeeder.php`
- Modify: `database/seeders/TenantReferenceDataSeeder.php`, `app/Actions/Tenancy/ProvisionShop.php`
- Test: `tests/Feature/Packs/OilChangeSeedTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_provisioning_an_oil_change_shop_seeds_oils_parts_and_repairs(): void;
public function test_provisioning_a_shop_without_the_pack_seeds_no_vehicles(): void;
public function test_seeding_is_idempotent(): void;
```

The second test is the point of the whole phase.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — move the oils, parts, repairs and reference-vehicle methods out of the shared seeder
- [ ] **Step 4: Verify green and format**

---

### Task 19: PACK — vehicle field group (seam S3)

**Files:**
- Create: `app/Packs/OilChange/FieldGroups/VehicleFieldGroup.php`
- Create: `resources/views/packs/oil-change/field-groups/vehicle.blade.php`
- Test: `tests/Feature/Packs/VehicleFieldGroupTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_the_vehicle_group_validates_model_plate_and_mileage(): void;
public function test_the_vehicle_group_stores_to_customer_vehicles(): void;
public function test_a_sale_without_vehicle_data_is_still_valid(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 20: Move vehicle data off `sales` — additive step

**Files:**
- Create: `database/migrations/tenant/*_add_customer_vehicle_id_to_sales_table.php`
- Modify: `app/Models/Sale.php`
- Test: `tests/Feature/SaleVehicleMigrationTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_sale_can_reference_a_customer_vehicle(): void;
public function test_existing_sales_keep_their_vehicle_columns_for_now(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — add the nullable reference. Change nothing else.
- [ ] **Step 4: Verify green and format**

---

### Task 21: Move vehicle data off `sales` — backfill step

**Files:**
- Create: `database/migrations/tenant/*_backfill_sale_customer_vehicles.php`
- Test: `tests/Feature/SaleVehicleMigrationTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_every_sale_with_a_plate_gains_a_customer_vehicle(): void;
public function test_sales_sharing_a_plate_share_one_vehicle_record(): void;
public function test_a_sale_with_no_vehicle_data_is_left_alone(): void;
public function test_the_backfill_is_idempotent(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green, and verify against a copy of live data before proceeding**

---

### Task 22: Move vehicle data off `sales` — subtractive step

**Files:**
- Create: `database/migrations/tenant/*_drop_vehicle_columns_from_sales_table.php`
- Modify: `app/Models/Sale.php`, `app/Http/Requests/StoreSaleRequest.php`, invoice and history views
- Test: existing sale, invoice and service-history tests

- [ ] **Step 1: Confirm Task 21 verified on live-shaped data.** Do not start otherwise.
- [ ] **Step 2: Update reads to go through the relationship**
- [ ] **Step 3: Verify the whole suite green**
- [ ] **Step 4: Drop `vehicle_model`, `vehicle_plate`, `mileage`, `next_checkup_mileage`**
- [ ] **Step 5: Verify green and format**

---

### Task 23: PACK — oil-change POS screen (seam S4)

**Files:**
- Create: `resources/views/packs/oil-change/pos.blade.php`
- Modify: `app/Http/Controllers/PosController.php`
- Test: `tests/Feature/PosDispenseUiTest.php`, `tests/Feature/CheckoutTest.php`

- [ ] **Step 1: Write a failing test** that the pack's screen is resolved for an oil-change shop
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — compose the Task 11–14 components plus the vehicle field group
- [ ] **Step 4: Verify every existing POS test passes unchanged, then format**

---

### Task 24: Generic retail fallback pack

**Files:**
- Create: `app/Packs/Retail/GenericRetailPack.php`
- Create: `resources/views/packs/retail/pos.blade.php`
- Test: `tests/Feature/Packs/GenericRetailPackTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_shop_with_no_vertical_gets_the_generic_retail_screen(): void;
public function test_the_generic_screen_shows_no_vehicle_fields(): void;
```

The second is the Phase 0 acceptance criterion.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — a minimal screen: item picker, cart, totals, payment
- [ ] **Step 4: Verify green and format**

---

### Task 25: Phase 0 acceptance

- [ ] **Step 1: Run the full suite.** Every test green, none weakened.
- [ ] **Step 2: Provision a test shop as "General store."** Confirm no vehicle fields, no automotive tables.
- [ ] **Step 3: Enable the oil-change pack on it.** Confirm its tables appear and health still reports current.
- [ ] **Step 4: Compare the live shop's screens, invoices and reports against a pre-refactor capture.** Any difference is a defect.
- [ ] **Step 5: `vendor/bin/pint --dirty --format agent`**
