# Feature map

Start here before changing behavior. Each page describes the workflow, ownership, persistence, permissions, invariants, and test coverage, with repository-relative links.

## Platform (central database)

| Area | Document |
|---|---|
| Shops, connections, and isolation | [Multi-tenancy](multi-tenancy.md) |
| Shop provisioning, entitlements, health, and support access | [Platform control plane](platform-control-plane.md) |

## Shop (tenant database)

| Area | Document | Module switch |
|---|---|---|
| Sign-in and staff access | [Authentication and authorization](authentication-and-authorization.md) | Administration is core |
| Feature switches and admin UI | [Administration](administration.md) | `admin` (core) |
| Counter checkout and saved vehicles | [Point of sale](point-of-sale.md) | `sales` (core) |
| Invoices and sale history | [Sales](sales.md) | `sales` (core) |
| Products, services, and stock | [Inventory](inventory.md) | `inventory` (core) |
| Which vehicles a product fits | [Vehicle product compatibility](vehicle-product-compatibility.md) | `inventory` (core) |
| Revenue, consumption, and margins | [Reports](reports.md) | `reports` |
| Outlays and drawer reconciliation | [Expenses and cash drawer](expenses-and-cash-drawer.md) | `expenses` |
| Vehicle history and inspections | [Workshop](workshop.md) | `workshop` |
| Staff talk tracks | [Counter scripts](counter-scripts.md) | `scripts` |
| Append-only operational history | [Activity log](activity-log.md) | `admin` (core) |
| Suppliers, deliveries, and payments | [Supplier ledger](supplier-ledger.md) | `admin` (core) |

## Cross-cutting rules

- All application screens require authentication except login. Route middleware provides the first authorization boundary; UI hiding is not security.
- Every shop screen runs inside an initialized tenant context. Operational models fail closed without one; central models never resolve on the tenant connection. See [Multi-tenancy](multi-tenancy.md).
- A module is available to a shop only when both the platform entitlement and the shop's own module preference allow it. The platform ceiling wins.
- Use permission values from [`app/Enums/Permission.php`](../../app/Enums/Permission.php), never role-name checks. Defaults are defined by [`app/Enums/Role.php`](../../app/Enums/Role.php).
- Module availability and navigation come from [`app/Modules/ModuleRegistry.php`](../../app/Modules/ModuleRegistry.php). Unknown module keys fail closed.
- Financial values are stored as decimal snapshots. A historical sale must not be recomputed from a current item record.
- Uploaded supplier documents are private local files and must only be served through authorized controller actions.
- Add or update focused PHPUnit feature tests for every behavioral change.
