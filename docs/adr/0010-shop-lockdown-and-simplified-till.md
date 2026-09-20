# ADR 0010: Shop lockdown and simplified till

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Shop lockdown and cash-only till for the approved OSPOS 3.4.1 baseline
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`
- Branch: `feat/shop-lockdown`
- Related: ADR 0004 (RTL layer), ADR 0005 (TVA model)

## Context and current upstream behavior

The fork serves one small Lebanese supermarket and fast-food counter. OSPOS also
contains customer accounts, item kits, suppliers, receiving, gift cards,
messaging, expenses, cashups, and an office module. The shop does not use these
areas. They add menu entries, permissions, support work, and routes that a
cashier can reach by typing an address.

OSPOS stores modules, permissions, and employee grants in
`ospos_modules`, `ospos_permissions`, and `ospos_grants`. The register
normally offers receipt, quote, invoice, work order, and return modes depending
on settings. Its payment list can contain cash, debit, credit, due, check,
gift card, and reward points. The register also shows a per-line discount
column and a customer block. Invoice mode normally asks for a customer.

The project owner settled the following scope on 2026-09-20:

- Nine unused areas disappear from the application: customers, item kits,
  suppliers, receivings, gift cards, messages, expenses, cashups, and office.
  Expenses categories is the tenth module row removed with expenses.
- Non-admin employees can work with items, reports, and sales only.
- The till accepts cash only, has no discount controls, and has no customer
  block.
- The register offers Sales Receipt, Invoice, and Return only.

## Requirements and non-goals

### Requirements

1. Remove the ten module rows from the menu and permission data.
2. Make every removed module route return 404, including a directly typed URL.
3. Keep the upstream controllers, models, views, and historical data tables on
   disk.
4. Keep all permissions and grants needed by the admin account.
5. Give every non-admin employee exactly the accepted grant set below.
6. Make the register cash-only and prevent a crafted payment-type request from
   adding another payment type.
7. Remove the per-line discount controls and the customer block.
8. Allow exactly `sale`, `sale_invoice`, and `return` register modes.
9. Let an invoice complete without a customer and print without buyer details.
10. Keep existing sales and historical customer or supplier references valid.

### Non-goals

- Deleting upstream PHP files, views, models, or controllers.
- Dropping customer, supplier, sales, or other historical-data tables.
- Adding a new customer workflow or free-text buyer name.
- Reworking the upstream sales model to remove old quote, work-order, gift-card,
  or customer methods.
- Changing the TVA, pricing, inventory, return, or reporting calculations.
- Claiming printer, scanner, or cash-drawer support before real-device testing.

## Decision

### Removal means unreachable, not deleted

The migration removes these module IDs from `ospos_modules`:

`customers`, `item_kits`, `suppliers`, `receivings`, `giftcards`,
`messages`, `expenses`, `expenses_categories`, `cashups`, and
`office`.

It removes their matching permission rows, including the
`receivings_stock` subpermission, and their grants. It does not drop any
application data table. A global `ShopLockdownFilter` checks the first URI
segment and returns HTTP 404 for each removed module. The filter is registered
globally before routing, so hiding a menu entry is not the security boundary.

The settings screen no longer shows the reward configuration tab, which belongs
to the removed customer and gift-card workflow.

The migration creates a temporary grant backup table while it is applied. It
stores every grant before the non-admin policy is changed. The down migration
uses it to restore every deleted grant and then removes the temporary table.
This table is not an application data table and is not part of the normal
schema after rollback.

### Permissions

The admin account keeps all remaining permissions. Every other employee gets
exactly these grants:

| Permission | Granted | Reason |
| --- | --- | --- |
| `items` | yes | Add, change, and read items. |
| `items_stock` | no | Stock adjustment is an owner job. |
| `reports` | yes | Report module access. |
| `reports_items`, `reports_inventory`, `reports_sales`, `reports_sales_taxes`, `reports_taxes`, `reports_payments`, `reports_categories` | yes | Reports needed by the shop. |
| `reports_customers`, `reports_suppliers`, `reports_receivings`, `reports_discounts`, `reports_expenses_categories`, `reports_employees` | no | Removed areas or other employees' data. |
| `sales` | yes | Work the till. |
| `sales_change_price`, `sales_delete`, `sales_stock` | no | Prevent price overrides, deletion, and stock changes. |
| `home` | yes | Landing page. |
| `employees`, `config`, `taxes`, `attributes` | no | Owner-only administration. |

**Accepted limitation:** OSPOS has one `items` permission for add, change,
and delete. Granting item editing therefore also grants item deletion. A
separate guard would diverge from upstream and is not justified for this shop.

### Simplified till

`get_payment_options()` returns cash alone. The payment-type dropdown and
gift-card inputs are removed from the register. The payment box contains only
the amount tendered. The controller also ignores a posted payment type and
records cash, so the UI is not the only protection.

The register view removes the per-line discount field, its currency/percent
toggle, and the customer discount row. Existing server-side discount code stays
in place because this ADR removes the till controls and does not change the
upstream sales model.

`Sale_lib::get_register_mode_options()` returns exactly:

- `sale` — Sales Receipt
- `sale_invoice` — Invoice
- `return` — Return

The mode endpoint rejects every other value with 404. Old quote and work-order
sale types loaded from suspended data map to the normal receipt mode instead of
reopening an unavailable register mode. Invoice mode does not set a customer
requirement. A new invoice therefore has no buyer details to print.

## Alternatives considered

**Delete the upstream code.** Rejected. Future OSPOS updates would conflict on
those files, and restoring a module would require rewriting it.

**Only revoke non-admin grants.** Rejected. The admin menu would still show
unused areas, and direct routes would remain reachable.

**Keep a free-text customer name for invoices.** Rejected. The owner chose an
invoice without buyer details.

**Drop invoice mode.** Rejected. The owner requires both Sales Receipt and
Invoice.

**Remove all customer and gift-card code from Sales.** Rejected. That is a
larger upstream rewrite than this shop needs. The remaining code is unreachable
from the simplified register and the removed-module route filter.

## Consequences and risks

Positive results:

- Cashiers see only the workflows used by the shop.
- Direct navigation to the removed modules is closed.
- The admin retains the remaining management capability.
- The register has one payment path and three clear modes.
- No historical tables or sales references are destroyed.

Risks and accepted limitations:

- A TVA invoice without buyer details may not satisfy a business customer or
  auditor. This is the owner's accepted choice and can be revisited later.
- Removing the discount UI does not remove every upstream server-side discount
  path. A future hardening phase can add that guard if needed.
- Gift-card balances, if any already exist, become unreachable. The test shop
  has none. Confirm this before deploying to a trading shop.
- Existing reports for removed areas may remain available to admin and may show
  no new activity. They are not rewritten.
- The non-admin policy applies when this migration runs to current employees.
  Any future employee-creation workflow must preserve this policy.

## Data-model, migration, compatibility, and rollback impact

- The migration is `20260920000002_shop_lockdown.php`.
- It deletes rows only from the module, permission, and grant tables.
- It does not drop customer, supplier, sales, item, or other historical tables.
- The down migration restores the exact upstream module and permission values,
  including sort order and the `receivings_stock` location row.
- The down migration restores every grant captured before the policy change,
  including grants that were removed only because they were outside the
  non-admin set.
- Run a database backup before production migration, as required by ADR 0002.
- Rollback is the migration down step followed by reverting the application
  commit if the code must also be removed.

## Test and acceptance approach

Automated tests cover:

- the ten removed module IDs;
- a 404 from the route filter for each removed module;
- global filter registration;
- cash as the only payment option;
- the exact three register modes;
- the exact non-admin grant set.

Verification also includes the full PHPUnit suite, PHP-CS-Fixer dry-run checks
for every changed PHP file, and an up/down migration rehearsal against the
verification MariaDB database. The rehearsal compares the module, permission,
grant, and migration state before and after rollback. It must leave the live
verification database unchanged.

Manual acceptance remains:

- log in as admin and a non-admin;
- confirm the menu contains only the remaining permitted areas;
- type every removed module address and confirm 404;
- complete a cash receipt, invoice without a customer, and return;
- confirm the invoice has no buyer block;
- verify Arabic and English register layout in Chrome.

Hardware behavior is not accepted by this ADR. Scanner, printer, and drawer
tests remain in Phase 4 after the project owner names the devices.

## Links and evidence

- [ADR 0002](0002-native-feature-configuration-and-gap-analysis.md), audit and
  migration practice.
- [ADR 0004](0004-rtl-and-mixed-direction-rules.md), language and direction
  behavior.
- [ADR 0005](0005-tva-model.md), the unchanged TVA and currency model.
- `docs/adr/adr-0010-draft.md\), the accepted decision source.
- `app/Config/ShopLockdown.php`, the removed-module and non-admin policy lists.
- `app/Filters/ShopLockdownFilter.php`, the route boundary.
- `app/Database/Migrations/20260920000002_shop_lockdown.php`, data changes.
