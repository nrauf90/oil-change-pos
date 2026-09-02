# Oil Change POS

A point-of-sale system for an automotive oil-change and repair shop, built around one rule:

> **Prices are not fixed by inventory.** The salesperson types every charge by hand, per customer,
> per vehicle. Nothing in the database can set or restrict a price.

## Getting it running

```bash
composer install
npm install

cp .env.example .env          # if .env does not exist yet
php artisan key:generate

touch database/database.sqlite
php artisan migrate --database=central --path=database/migrations/central
php artisan platform:make-super-admin --name="Platform Administrator" --email="admin@example.test"
# The command securely prompts for and confirms the password.

npm run build                 # or `npm run dev` while working on the UI
php artisan serve
```

Fresh SaaS setup creates no default accounts or passwords. The
`platform:make-super-admin` command creates the first platform super administrator. Set
`APP_ENV=production` and `APP_DEBUG=false` in `.env` before exposing the application.

## The two front ends

**`/pos` — the counter.** Blade + Alpine, no build-time framework between the cashier and the
keyboard. Capture customer and vehicle, add product / repair / free-text lines, type each price,
add labour and misc, check out. The **+ New** button next to any item dropdown opens a quick-add
modal that saves to inventory and drops the new item straight into the current row — without a page
reload and without losing a single thing already typed.

**`/admin` — the back office.** Filament 5: inventory with stock levels and low-stock badges, staff
accounts and roles, expenses, cash drawer, profit margins, and the module switchboard.

Both share one login at `/login` (username, not email).

## Roles

| | Admin | Manager | Technician |
|---|---|---|---|
| Ring up a sale | ✅ | ✅ | ❌ |
| See prices / totals | ✅ | ✅ | ❌ |
| Quick-add inventory | ✅ | ✅ | ❌ |
| Delete a sale / item / expense | ✅ | ❌ | ❌ |
| Unit costs & profit margins | ✅ | ❌ | ❌ |
| Read the activity log | ✅ | ❌ | ❌ |
| Expenses & cash drawer | ✅ | ✅ | ❌ |
| Counter scripts | ✅ | ✅ | ✅ |
| Vehicle history & inspections | ✅ | ✅ | ✅ |
| Staff accounts & modules | ✅ | ❌ | ❌ |

Authorization is one named permission per action (`App\Enums\Permission`, 35 cases), bundled into
roles by `App\Enums\Role`. No code checks a role directly.

## Modules (plug and play)

Every feature is a module that can be switched off from **Admin → Modules** with no code change —
its navigation disappears and its routes return 404. Sales, Inventory and Admin are core and stay on.
Reports and Expenses depend on Sales, so the panel refuses to strand them.

To add a feature: write an `App\Modules\Module` subclass, register it in `ModuleServiceProvider`,
and drop a route file in `routes/modules/`. Nothing else needs editing.

## Money

All arithmetic runs through `App\Support\SaleTotalCalculator` in **integer cents**, so `0.1 + 0.2`
is `0.30`. The identical parsing rules are mirrored in the browser, and `MoneyParityTest` executes
the real JavaScript under `node` against the PHP to prove the on-screen total and the saved invoice
cannot drift apart. Amounts accept `1200`, `1200.50` and `1,200.50`; exponent notation like `1e3`
is refused rather than silently billed.

## Tests

```bash
php artisan test              # 510 tests
./vendor/bin/pint             # formatting
```

Built test-first. Where a test passed on the first run, the production code was deliberately mutated
to prove the test actually catches the regression, then restored.

## Where things live

```
app/
  Actions/RecordSale.php        turns a checkout payload into a Sale
  Enums/                        Permission, Role, ItemType, SaleLineType, ExpenseCategory, Inspection*
  Filament/                     the back office (resources, pages, widgets)
  Modules/                      Module base class, ModuleRegistry, Features/*
  Support/                      SaleTotalCalculator, SalesReport, CashDrawer, MarginReport,
                                ServiceHistory, CounterScripts
resources/views/pos/create.blade.php   the counter screen (Alpine cart + quick-add modal)
routes/modules/                 one route file per pluggable feature
```

See `PLAN.md` for the schema and the architectural reasoning, and `requirement.md` for the original
product spec.
