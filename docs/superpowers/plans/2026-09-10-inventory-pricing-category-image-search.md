# Inventory: selling price, categories, item images, live search, vehicle compatibility

## Spec (user request, verbatim intent)

> i want you add selling price option in inventory and add into list view as well. hide unit price
> from list page. Also allow image upload for items. Also add live search for inventory. Also add
> category, make, model option in add, edit update option so user can specify item for vehicles.
> Check if on admin side we have category module or not. if not build this for so admin can create
> categories easily. run separate agent for admin.

No spec file exists; this plan is the binding spec. Deviations from it in a task's own text lose to
this section.

## Current-state findings (already verified by research, do not re-derive)

- Staff-facing inventory CRUD is plain Blade + a little Alpine: `App\Http\Controllers\ItemController`,
  `App\Http\Requests\ItemRequest`, `resources/views/items/{index,create,edit,_form}.blade.php`.
- A second, richer UI already exists for the same `Item` model: the Filament "admin" panel
  (`app/Filament/Resources/Items/*`), auto-discovered by `AdminPanelProvider::discoverResources`.
  It already has full vehicle **make/model/year** compatibility via a `Repeater` bound to the
  `vehicleCompatibilities` relationship, and Filament's `->searchable()` gives it search for free.
  **This plan does not touch make/model compatibility in Filament — it is done there already.**
- `Item` already has `is_universal` (bool) and a `vehicleCompatibilities()` HasMany to
  `ItemVehicleCompatibility` (belongs to `Item` and `VehicleModel`). `VehicleMake` → `VehicleModel` are
  first-class tenant models with an existing Filament `VehicleMakeResource` (admin-only, gated by
  `auth()->user()?->isAdmin()`). None of this needs to be built — only *exposed on the staff-facing
  item form*, which currently has no way to set vehicle compatibility at all.
- There is no "category" concept anywhere (`ExpenseCategory` is unrelated — expense bookkeeping).
  Confirmed absent on both the staff side and the Filament admin side.
- The staff list (`items/index.blade.php`) shows a permission-gated "Unit cost" column
  (`@can('items.view_unit_cost')`) and has a plain `<form method="GET">` filter bar (search/type/status)
  with a submit button — no live search.
- File-upload precedent exists for expense receipts: `App\Actions\AttachExpenseReceipts`,
  `App\Tenancy\TenantStoragePath` (private `local` disk, tenant-prefixed path, streamed back via an
  authenticated route — never a public URL). New item-image storage follows the same pattern, but
  single-image-per-item instead of many-per-parent.
- `Item` is a `TenantModel` (tenant DB connection via `UsesTenantConnection`). New `Category` model
  must be a `TenantModel` too. `Rule::unique(Item::class, ...)` / `Rule::exists(Model::class, ...)`
  already resolve the tenant connection correctly from the model class — always validate this way,
  never `Rule::exists('categories', 'id')` by table name.
- Permission enum: `App\Enums\Permission`. Manager role has `items.view_any`, `items.create`,
  `items.update` but **not** `items.view_unit_cost` / `items.set_unit_cost` — that pair is
  Admin-only. Selling price is operational (what the counter charges), not confidential like cost —
  it needs **no new permission**; it follows the same visibility as the rest of the item form/list
  (`items.view_any` to see it, `items.create`/`items.update` to set it).

## Global Constraints (binding on every task)

1. **Naming/schema, verbatim:**
   - `categories` table (tenant migration): `id`, `string('name', 100)->unique()`, `timestamps()`.
     Also enforce a case-insensitive duplicate check in `Category::booted()`'s `saving` hook, exactly
     like `App\Models\VehicleMake::booted()` (squish + lowercase compare, throw
     `ValidationException::withMessages(['name' => 'This category already exists.'])`).
   - Add to `items` table (one migration): `selling_price` decimal(10,2) nullable, positioned
     `->after('unit_cost')`; `category_id` nullable `foreignId()->after('type')->constrained()->nullOnDelete()`;
     `image_path` nullable string `->after('is_active')`; `image_original_name` nullable string
     `->after('image_path')`; `image_mime_type` nullable string(100) `->after('image_original_name')`.
   - `image_path`, `image_original_name`, `image_mime_type` are **never** added to `Item::$fillable`
     and never assigned from `$request->validated()`. They are set only inside the `AttachItemImage`
     action via direct attribute assignment (`$item->image_path = ...`) + `save()`, mirroring how
     `AttachExpenseReceipts` keeps receipt storage out of mass assignment. `selling_price` and
     `category_id` **do** go into `Item::$fillable` (they're plain owned columns, not files).
2. **Money fields**: same shape as `unit_cost` — `nullable`, `numeric`, `min:0`, `max:99999999`,
   cast `decimal:2`, blank string normalized to `null` in `prepareForValidation()`
   (`ItemRequest::blankToNull()` already exists — reuse it).
3. **Images**: private `local` disk only, via `App\Tenancy\TenantStoragePath`, directory constant
   `'item-images'`. Never `Storage::disk('public')`, never a symlinked/public URL. Served only through
   an authenticated streaming route, exactly like `ExpenseController::receipt()` /
   `routes/modules/expenses.php`'s `expenses.receipts.show`. Validation: `nullable`, `image`,
   `mimes:jpeg,jpg,png,webp`, `max:4096` (KB).
4. **Vehicle compatibility on the staff form**: reuses the existing `is_universal` column and
   `ItemVehicleCompatibility` model as-is — **do not** add new columns or change
   `ItemVehicleCompatibility::booted()`'s invariants (product-only, non-universal-only, year range,
   duplicate checks — it already throws `ValidationException`, which Laravel's default handler
   already turns into a redirect-back-with-errors for a normal web POST, same as any other
   `ValidationException`). Sync strategy on save: delete-all-then-recreate from the submitted rows,
   same approach the Filament `Repeater` already uses. Year bounds: 2000–2026 (matches the existing
   `ItemVehicleCompatibility::MAX_VEHICLE_YEAR` / Filament `VEHICLE_YEAR_MIN`/`MAX` constants — do not
   "fix" this magic number, just match it).
5. **Do not wire `selling_price` into POS checkout, quick-add, or margin reporting.** It is inventory
   reference/list data only in this plan. `PosController`, `QuickItemController`, receipts, and margin
   reports are out of scope — touching them is scope creep.
6. **Do not touch Filament's existing vehicle-compatibility UI** (`ItemForm.php`'s
   `vehicleCompatibilitySection`, `ItemsTable.php`'s compatibility filter/column) — it's already done.
   The only Filament changes in this plan are a brand-new `CategoryResource` and one new `category_id`
   field/column added to the existing `ItemForm`/`ItemsTable` (Task 6). Do not add `selling_price` or
   image upload to Filament — not requested, and out of scope.
7. **Conventions**: PHP 8 constructor promotion, explicit return types, curly braces always, PHPDoc
   over inline comments. Run `vendor/bin/pint --dirty --format agent` before finishing any task with
   PHP changes. Every task adds/updates a test and runs it (`php artisan test --compact <path>` or
   `--filter`), per `tests` boost rule. Follow `testing-best-practices` skill guidance.
8. **No new permissions, no new dependencies.** Reuse `items.view_any` / `items.create` /
   `items.update` / `items.delete` for everything staff-side. Category admin CRUD reuses the
   `isAdmin()` gate pattern from `VehicleMakeResource`, module-gated to `'inventory'` exactly like it.
9. Route names: `items.image` (GET, show), everything else keeps existing route names. New routes go
   in `routes/web.php` inside the existing `Route::middleware('module:inventory')->group(...)` block
   that already holds the other `items.*` routes.

## Task 1 — Foundation: migrations, `Category` model, `Item` model updates

**Files**: new tenant migration `database/migrations/tenant/2026_09_10_HHMMSS_create_categories_table.php`
(pick a timestamp after the latest existing tenant migration, `2026_09_04_203810_...`); new tenant
migration `database/migrations/tenant/2026_09_10_HHMMSS_add_selling_price_category_and_image_to_items_table.php`;
new `app/Models/Category.php`; new `database/factories/CategoryFactory.php`; edit
`app/Models/Item.php`.

**Requirements**:
- `categories` migration and `items` migration exactly as specified in Global Constraint 1. Follow
  the style of `database/migrations/tenant/2026_09_01_135122_create_vehicle_makes_table.php` (create)
  and `2026_08_27_000102_add_stock_columns_to_items_table.php` (add-columns, with `down()` dropping
  the added columns) — anonymous class migrations, typed `up()`/`down()`.
- `Category extends TenantModel`, `use HasFactory`, `protected $fillable = ['name'];`, a `booted()`
  duplicate-name guard copied from `VehicleMake::booted()` (squish + case-insensitive compare,
  `ValidationException::withMessages(['name' => 'This category already exists.'])`), and
  `items(): HasMany` to `Item`.
- `CategoryFactory` mirrors `VehicleMakeFactory` exactly (`'name' => ucfirst($this->faker->unique()->word())`).
- `Item::$fillable`: add `'selling_price'`, `'category_id'`. Do **not** add the three image columns
  (Global Constraint 1). Add `'selling_price' => 'decimal:2'` to `casts()`. Add
  `category(): BelongsTo` to `Category`. Leave everything else in `Item` untouched.
- Update `docs/features/inventory.md`'s data/code map with the new `Category` model and migrations
  (one line each, matching the existing list style) — this doc is explicitly meant to be kept current.

**Tests**: extend or add a small model/migration test proving: a `Category` can be created; a
duplicate name (any case) is rejected; an `Item` can be created with `selling_price` and
`category_id` set and both persist with the right cast/relation. Use `Category::factory()` and
`Item::factory()`. Run `php artisan migrate:fresh` implicitly happens via `RefreshDatabase` — no
manual migration running needed in tests.

## Task 2 — Staff inventory: selling price, category picker, hide unit cost from the list

**Depends on Task 1.**

**Files**: `app/Http/Requests/ItemRequest.php`, `app/Http/Controllers/ItemController.php`,
`resources/views/items/_form.blade.php`, `resources/views/items/index.blade.php`,
`tests/Feature/ItemManagementTest.php` (or a new focused test file if that one is getting long —
match its existing style either way).

**Requirements**:
- `ItemRequest::rules()`: add `'selling_price' => ['nullable', 'numeric', 'min:0', 'max:99999999']`
  and `'category_id' => ['nullable', Rule::exists(\App\Models\Category::class, 'id')]`.
- `ItemRequest::prepareForValidation()`: `selling_price` blank-to-null via the existing
  `blankToNull()` helper, same as `stock_level`/`low_stock_alert`. `category_id` blank string (`''`)
  from an unselected `<select>` must also normalize to `null` before validation.
- `ItemController::create()` and `edit()`: pass `'categories' => \App\Models\Category::query()->orderBy('name')->get()`
  to the view alongside the existing `types`.
- `ItemController::index()`: add `'category'` to the query filter (a `?category=` id, same
  `queryString()` pattern already used for `q`/`type`/`status` — note category is numeric, so parse
  it as an int, not through the string-only `queryString()` helper) and pass
  `'categories' => Category::query()->orderBy('name')->get()` + `'activeCategory'` to the view. Add a
  `Category::scopeCompatibleWith`-style simple filter — actually just
  `->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))` inline in the query chain
  (no new model scope needed for one `where`).
- `_form.blade.php`: add a "Selling price" text input right next to the existing "Reference cost"
  block (same `field-money` input styling, `inputmode="decimal"`, placeholder `0.00`), **not**
  permission-gated (unlike unit cost). Add a "Category" `<select>` (options from `$categories`,
  first option `"— No category —"` value `""`), placed near the Type radio group. Both fields use
  `old(...)` fallback to `$item?->...` exactly like every other field in this form.
- `index.blade.php`: **remove** the `@can('items.view_unit_cost')` "Unit cost" `<th>`/`<td>` pair
  entirely. Add a "Selling price" `<th>`/`<td>` (right-aligned, `number_format($item->selling_price, 2)`
  or `'—'`, no permission gate) and a "Category" `<th>`/`<td>` (`$item->category?->name ?? '—'`).
  Add a category `<select>` to the filter bar next to the existing type/status selects, same markup
  pattern, submitting `?category=`. Update the `colspan` on the empty-state row to match the new
  column count. Eager-load `category` in `ItemController::index()`'s query (`->with('category')`) to
  avoid N+1.

**Tests**: item can be created/updated with a selling price and a category and both persist and
round-trip through the edit form; the index page shows the "Selling price" column and does **not**
show "Unit cost" text/column regardless of the viewer's permissions (assert the column header and a
formatted value are absent, e.g. `assertDontSee('Unit cost')`); filtering the index by `?category=`
returns only matching items. Follow `ItemManagementTest`'s existing `actingAs(User::factory()->admin()->create())`
setup pattern; add a second case acting as a Manager-role user to confirm they see selling price but
still never see unit cost (Manager already lacks `items.view_unit_cost`).

## Task 3 — Staff inventory: image upload

**Depends on Task 1. Independent of Task 2's diff area (different fields) but touches the same files
— must run after Task 2 to avoid clobbering its edits.**

**Files**: new `app/Actions/AttachItemImage.php`; edit `app/Http/Requests/ItemRequest.php`,
`app/Http/Controllers/ItemController.php`, `routes/web.php`, `resources/views/items/_form.blade.php`,
`resources/views/items/index.blade.php`; new/extended test file.

**Requirements**:
- `AttachItemImage` (final readonly class, constructor-promoted `TenantStoragePath $storagePaths`):
  `public const DIRECTORY = 'item-images';`
  - `__invoke(Item $item, ?UploadedFile $file, bool $remove = false): void` — if `$remove` is true,
    delete the old file (if any, via `$this->storagePaths->delete($item->image_path, self::DIRECTORY)`)
    and set all three image columns to `null`, then `$item->save()`. Else if `$file === null`, do
    nothing (edit without touching the image keeps the existing one). Else: delete the old file if
    one exists, store the new file (`$this->storagePaths->store($file, self::DIRECTORY)`), set
    `image_path`/`image_original_name` (`mb_substr($file->getClientOriginalName(), 0, 255)`)/
    `image_mime_type` (`$file->getMimeType() ?? 'application/octet-stream'`), `$item->save()`. Wrap
    the store-and-save in try/catch and delete the just-stored file on failure before rethrowing,
    exactly like `AttachExpenseReceipts`'s rollback.
- `ItemRequest::rules()`: add `'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']`
  and `'remove_image' => ['sometimes', 'boolean']`.
- `ItemController::store()`/`update()`: inject `AttachItemImage $attachImage`; after
  `Item::create(...)`/`$item->update(...)`, call
  `$attachImage($item, $request->file('image'), $request->boolean('remove_image'))`.
- `ItemController::destroy()`: inject `AttachItemImage $attachImage`, call
  `$attachImage($item, null, remove: true)` before `$item->delete()` so the file is cleaned up (the
  three columns being nulled first doesn't matter since the row is about to be deleted — the point is
  the physical file).
- New route in `routes/web.php`, inside the existing `module:inventory` group holding the other
  `items.*` routes: `Route::get('/items/{item}/image', [ItemController::class, 'image'])
  ->middleware('permission:items.view_any')->name('items.image');`
- New `ItemController::image(Item $item, TenantStoragePath $storagePaths): StreamedResponse` — same
  shape as `ExpenseController::receipt()`: resolve the readable path via
  `$storagePaths->readablePath($item->image_path, AttachItemImage::DIRECTORY)`, `abort_if(null, 404)`,
  return `Storage::disk('local')->response($path, $item->image_original_name, ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => $item->image_mime_type]);`.
- `_form.blade.php`: add a file input `name="image"` (`accept="image/*"`) below the name field. If
  `$item?->image_path` is set, show a small thumbnail (`<img src="{{ route('items.image', $item) }}">`,
  capped size via a Tailwind class like the rest of the form) with a "Remove image" checkbox
  (`name="remove_image"`) next to it.
- `index.blade.php`: add a small thumbnail/placeholder in the "Item" column (an `<img>` pointing at
  `route('items.image', $item)` when `$item->image_path` is set, otherwise a simple placeholder box —
  keep it compact, this is a dense table, not a gallery).

**Tests**: uploading a valid image on create/update stores it and the show route streams it back with
the right content type (use `Illuminate\Http\UploadedFile::fake()->image(...)`); an invalid file type
is rejected with a validation error; checking "remove image" on update clears it and the route then
404s; deleting an item removes its stored file (assert via `Storage::disk('local')->assertMissing(...)`
or equivalent, scoping to the tenant path). Follow existing `Storage::fake('local')` conventions if
`AttachExpenseReceipts`'s own test file uses them — check `tests/Feature/` for how expense receipt
tests fake storage and match that setup.

## Task 4 — Staff inventory: live search

**Depends on Task 2 (same index view/controller area) — run after it.**

**Files**: `app/Http/Controllers/ItemController.php`, `resources/views/items/index.blade.php`; new
`resources/views/items/_results.blade.php`.

**Requirements**:
- Extract the current `<div class="card overflow-hidden">…</div>` table markup plus the
  `<div class="mt-4">{{ $items->links() }}</div>` pagination line out of `index.blade.php` into a new
  partial `resources/views/items/_results.blade.php` (same variables: `$items`). `index.blade.php`
  wraps the include in `<div id="items-results">@include('items._results')</div>` so the whole block
  is one addressable DOM node.
- `ItemController::index()`: when the request carries header `X-Inventory-Search: 1`, return
  `response(view('items._results', [...])->render())` (the same `$items` data, same view variables
  the partial needs) instead of the full `items.index` view. Otherwise behave exactly as before
  (full-page render, which itself includes the same partial) — this keeps the no-JS path working
  identically.
- Filter bar in `index.blade.php` gets `x-data` with a debounced watcher: typing in the search box
  (400ms debounce) and changing any filter `<select>` (immediate) triggers a `fetch()` to the form's
  current URL + query string with header `'X-Inventory-Search': '1'`, then sets
  `document.getElementById('items-results').innerHTML` to the response text and calls
  `history.replaceState(null, '', url)` so the address bar and refresh/back stay correct. The
  existing "Filter" submit button and "Clear" link stay as-is (still do a normal full-page GET — pure
  progressive enhancement, no removed functionality if JS is off).
- Do not intercept clicks on the pagination links inside the partial — a normal full-page navigation
  on those is fine and out of scope for "live search" (which covers the search-as-you-type / filter
  experience, not pagination).

**Tests**: a request to `items.index` with the `X-Inventory-Search` header returns only the results
fragment (assert it does **not** contain e.g. `<h1>Inventory</h1>` but does contain the item names/
table), while a plain request returns the full page including that heading; typing a search term via
the fragment endpoint filters correctly (reuse the existing `Item::scopeSearch` behavior — this task
doesn't change search logic, only transport).

## Task 5 — Staff inventory: vehicle make/model/year compatibility on the item form

**Depends on Task 2 and Task 4 (same controller/view files) — run last among the staff tasks.**

**Files**: `app/Http/Requests/ItemRequest.php`, `app/Http/Controllers/ItemController.php`,
`resources/views/items/_form.blade.php`; new/extended test file.

**Requirements**:
- `ItemController::create()`/`edit()`: also pass `'vehicleMakes' => VehicleMake::query()->orderBy('name')->get()`
  and `'vehicleModels' => VehicleModel::query()->orderBy('name')->get(['id', 'vehicle_make_id', 'name'])`
  (small enough tables to send whole — this mirrors how the POS sale screen already ships vehicle
  filter data to the client for client-side cascading, per `resources/views/pos/create.blade.php`;
  read that file's existing `vehicleFilter`/Alpine pattern first and match its style). `edit()` also
  eager-loads `$item->load('vehicleCompatibilities.vehicleModel.vehicleMake')` so existing rows can
  be pre-filled.
- `_form.blade.php`: inside the existing "Type" section area, add (visible only when `type === 'product'`,
  mirroring the Filament form's `showsVehicleCompatibility()` condition — Alpine `x-show`):
  - A "Universal fit" checkbox bound to `is_universal` (default checked/`true` for a new item, same
    default as the DB column and `ItemFactory`).
  - When unchecked, a repeatable set of rows (Alpine array in `x-data`, each row
    `{ makeId: '', modelId: '', yearFrom: '', yearTo: '' }`), each row rendering a Make `<select>`
    (from `$vehicleMakes`), a Model `<select>` filtered client-side to the chosen make (from
    `$vehicleModels`, matching on `vehicle_make_id` — same cascading idea as the POS screen's
    `vehicleFilter`), and two year number inputs. "Add vehicle" / remove-row buttons, matching the
    rest of the form's button styling. On submit, each row serializes to
    `vehicle_compatibilities[INDEX][vehicle_model_id]`, `[year_from]`, `[year_to]` — use `x-for` with
    hidden/visible inputs carrying `:name` built from the loop index (do not use `name="vehicle_compatibilities[][...]"`,
    PHP needs stable per-row keys or an index-based array is fine as long as it's sequential — either
    works, pick the simpler one and be consistent for all three fields per row).
  - On edit, seed the Alpine array from `$item->vehicleCompatibilities` (make id via
    `$compatibility->vehicleModel->vehicle_make_id`) using `old('vehicle_compatibilities', [...])` so
    a failed submission round-trips the user's edits, same as every other field in this form.
- `ItemRequest::rules()`: add `'is_universal' => ['sometimes', 'boolean']`,
  `'vehicle_compatibilities' => ['array']`,
  `'vehicle_compatibilities.*.vehicle_model_id' => ['nullable', 'integer', Rule::exists(\App\Models\VehicleModel::class, 'id')]`,
  `'vehicle_compatibilities.*.year_from' => ['nullable', 'integer', 'min:2000', 'max:2026']`,
  `'vehicle_compatibilities.*.year_to' => ['nullable', 'integer', 'min:2000', 'max:2026']`. Do not
  duplicate the model's own range/duplicate/product-type checks here — `ItemVehicleCompatibility::booted()`
  already enforces those and its `ValidationException` already redirects back with errors.
- `ItemController::store()`/`update()`: after saving the `Item`, sync compatibility rows — delete all
  existing `$item->vehicleCompatibilities()` rows, then, only when the item is a Product and
  `is_universal` is false, create one row per submitted array entry that has a non-blank
  `vehicle_model_id` (skip blank rows silently — an empty trailing row from the UI isn't an error).
  This is a small private controller method, not a new Action class (it's a two-line sync, no file
  I/O, no rollback story — an Action would be over-abstraction here).

**Tests**: creating a non-universal product with one or more make/model/year rows persists them
(assert via `assertDatabaseHas('item_vehicle_compatibilities', ...)` on the `tenant` connection);
switching an item to universal on update clears any existing rows; submitting a row with an invalid
`vehicle_model_id` is rejected; a duplicate row within one submission surfaces the model's own
validation message rather than a 500. Match `PosCategoryRailTest`'s existing use of
`VehicleMake::factory()`/`VehicleModel::factory()`/`ItemVehicleCompatibility::factory()` for fixtures.

## Task 6 — [ADMIN, separate agent] Category management in the Filament panel

**Depends only on Task 1 (the `Category` model). Independent of Tasks 2–5 (entirely different
files) — dispatch this to its own implementer, separate from the staff-inventory tasks, and it may
run any time after Task 1 completes.**

**Files**: new `app/Filament/Resources/Categories/CategoryResource.php`,
`app/Filament/Resources/Categories/Schemas/CategoryForm.php`,
`app/Filament/Resources/Categories/Tables/CategoriesTable.php`,
`app/Filament/Resources/Categories/Pages/{ListCategories,CreateCategory,EditCategory}.php`; edit
`app/Filament/Resources/Items/Schemas/ItemForm.php`, `app/Filament/Resources/Items/Tables/ItemsTable.php`;
new/extended test file.

**Requirements — CategoryResource** (read `app/Filament/Resources/VehicleMakes/VehicleMakeResource.php`
first and copy its shape; a `Category` is flat — no nested repeater like vehicle models, so skip that
complexity entirely, this is much simpler than `VehicleMakeForm`):
- `CategoryResource extends Resource`, `protected static ?string $model = Category::class;`, a
  sensible `Heroicon` (e.g. `OutlinedTag`), `$navigationLabel = 'Categories'`,
  `$modelLabel = 'category'`, `$recordTitleAttribute = 'name'`.
- Gate exactly like `VehicleMakeResource`: `getViewAnyAuthorizationResponse()`,
  `getCreateAuthorizationResponse()`, `getEditAuthorizationResponse()`,
  `getDeleteAuthorizationResponse()`, `getDeleteAnyAuthorizationResponse()` all delegate to a private
  `adminOnly()` = `app(ModuleRegistry::class)->enabled('inventory') && (auth()->user()?->isAdmin() ?? false)`,
  wrapped in the same `gate(bool $granted): Response` helper pattern.
- `CategoryForm`: one `Section::make('Category')` with a single required `TextInput::make('name')`
  (`->maxLength(100)->unique(ignoreRecord: true)`).
- `CategoriesTable`: `TextColumn::make('name')->searchable()->sortable()`, a
  `TextColumn::make('items_count')->label('Items')->counts('items')` (or equivalent
  `->state(fn (Category $record) => $record->items()->count())` if `counts()` needs a
  `withCount`-loaded relation — check which is simpler given `modifyQueryUsing`), standard
  `EditAction`/`DeleteAction`. A `DeleteAction` should warn if the category is in use, following the
  spirit of `VehicleMakeResource::makeDeleteAction()`'s "this affects N products" modal — reuse that
  pattern's shape (list affected item names) but simpler since there's no nested model to worry
  about; deleting a category must not be blocked outright, just nulls `category_id` on affected items
  via the migration's `nullOnDelete()`, so the warning is informational, not a hard block.
- Pages: `ListCategories`, `CreateCategory`, `EditCategory` — copy the three-file shape from
  `app/Filament/Resources/VehicleMakes/Pages/*` (they're thin wrappers).
- Set `protected static ?int $navigationSort` to something after `VehicleMakeResource`'s `11` (use `12`)
  so it lands in a sensible spot in the nav.

**Requirements — Item Filament parity (category only — do not add selling price or image here, see
Global Constraint 6)**:
- `ItemForm.php`: add a `Select::make('category_id')` (label "Category", `searchable()`, `preload()`,
  nullable/no `required()`, placeholder "No category", `options` from
  `Category::query()->orderBy('name')->pluck('name', 'id')`) to the existing `$itemSection`'s schema
  array. Give it a `createOptionForm([TextInput::make('name')->required()->maxLength(100)])` +
  `createOptionAction` gated to `auth()->user()?->isAdmin() ?? false` + `createOptionUsing` doing
  `Category::query()->firstOrCreate(['name' => $data['name']])->getKey()` — copy this shape directly
  from the existing `vehicle_make_id`/`vehicle_model_id` `Select` fields in the same file, they're the
  exact pattern to follow.
- `ItemsTable.php`: add `TextColumn::make('category.name')->label('Category')->placeholder('—')->sortable()`
  to the columns list (near `type`), and a `SelectFilter::make('category_id')->label('Category')->options(...)`
  to the filters list (near the existing `type` `SelectFilter`). Eager-load `category` in the existing
  `modifyQueryUsing` alongside `vehicleCompatibilities...`.

**Tests**: an admin can create/edit/delete a category through `Livewire::test(CreateCategory::class)`
/`EditCategory::class` (match the existing Filament test style already in `ItemManagementTest.php` —
read its Filament-facing test cases for the exact `Livewire::test()` idiom used in this codebase); a
non-admin (e.g. Manager role) cannot access the category resource (mirror however
`VehicleMakeResource`'s admin-only gating is already tested, if such a test exists — check for a
`VehicleMake` Filament authorization test and copy its shape); the Item Filament form's category
`Select` persists `category_id` on create/edit; the Items table shows the category column and filters
correctly by category.

## Verification

Each task runs its own tests plus `vendor/bin/pint --dirty --format agent`. Final whole-branch review
runs the full suite: `php artisan test --compact`.
