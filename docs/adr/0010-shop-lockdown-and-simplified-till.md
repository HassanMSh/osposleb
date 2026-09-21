# ADR 0010: Shop lockdown and simplified till

- Status: Accepted
- Date: 2026-09-21
- Decision owners: Project owner and implementation team
- Branch: `fix/shop-lockdown-browser-qa`
- Related: ADR 0004 for RTL and ADR 0005 for TVA

## Context

Stock OSPOS uses the home grid for Items, Reports, Sales, and the Office tile.
The Office tile opens the office grid, which contains Home, Employees, Taxes, Attributes, and Config.
The native `Secure_Controller` permission check denies `/office` to employees without the `office` grant.
The global lockdown filter therefore blocks only the removed modules and does not block `/office`.
The approved permission rows identify `config`, `employees`, `taxes`, `attributes`, and `office` as administrative access.

## Superseded decisions

On 2026-09-20, the project owner approved this original requirement list:

1. Remove the ten unused module rows from menu and permission data, including
   Office.
2. Return HTTP 404 for every removed module route, including directly typed
   URLs.
3. Keep upstream code, views, controllers, and historical data tables on disk.
4. Keep all permissions and grants needed by the administrator.
5. Give non-administrators the agreed cashier grant set.
6. Keep the till cash-only, without discount controls or a customer block.
7. Offer only Sales Receipt, Invoice, and Return register modes.
8. Let invoices complete without customer details.
9. Keep existing sales and historical customer or supplier references valid.

The rationale was to hide unused workflows and direct routes while preserving
upstream data and upgrade paths, and to keep the till small enough for the
shop's daily cashier workflow.

On 2026-09-21, review changed item 1: Office remains an internal upstream
module, and its normal controller guard remains the access boundary. The
change followed verification that the Office area supplies the administrator's
back-to-home navigation and that a non-administrator can satisfy the accepted
criterion while still receiving a valid `home` grant. The cash-only and
historical-data decisions remain unchanged.

### Register modes superseded on 2026-09-21

On 2026-09-21 the project owner replaced item 7 while reviewing Arabic wording.
The register-mode picker must offer exactly three choices: sale, price offer,
and return. Invoice and work order are removed from the picker because neither
is used in a supermarket or fast-food shop.

This supersedes the 2026-09-20 list of Sales Receipt, Invoice, and Return.

No combination of existing settings produces exactly these three. With
invoicing off the picker offers sale and return with no price offer. With
invoicing on it offers four or five entries. Delivering the three-item list
therefore needs a change to the function that builds the option list, not a
settings change.

Invoice and work order remain reachable elsewhere in the application. Removing
them from the picker hides them from the cashier. It does not delete existing
invoices or work orders and does not change how any saved record behaves.

This change is not implemented on this branch. It is recorded here so the
register-mode scope in this ADR is not read as current.

## Decision

- Keep `office` out of `ShopLockdown::REMOVED_MODULES`.
- Leave the upstream Office module row and permission row in place with `sort = 999`.
- Keep the Office tile on the administrator home grid.
- Do not route-block `/office`.
- Deny `/office` to non-administrators through OSPOS's native `Secure_Controller` check because they hold no `office` grant.
- The administrator chooses which permissions an employee has and the system decides where each renders.
- The first hard limit is that a non-administrator cannot hold a permission whose module is in `ShopLockdown::REMOVED_MODULES`.
- The second hard limit is that a non-administrator cannot hold `config`, `employees`, `taxes`, `attributes`, or `office`.
- Filter grants outside either hard limit instead of replacing the employee's whole selection.
- A new employee with no posted grants receives the standard cashier grants.
- A new or existing non-administrator cannot gain administrative access by posting `config` or any other administrative grant.
- An existing administrator keeps the selected grants from the form, may rename the account, and may give up administrative access only when another active `config` holder remains.
- The system ignores every posted menu placement. Module-level permissions use `ShopLockdown::MENU_GROUPS`, and every sub-permission uses `--`.
- Identify an existing administrator by the `config` grant.
- Exclude soft-deleted employees from the lockdown policy.
- Keep stock-location permissions dynamic by deriving `items_` and `sales_` grants from each active location name with spaces replaced by underscores.
- Refuse a save that would remove administrative access from the last active holder of `config`, return `false`, and let the AJAX controller return a clear JSON message.

The accepted trade-off is that operators cannot move a module between Home and
Office from the employee screen. Fixed placement keeps cashier navigation
predictable and prevents a posted form value from changing the navigation policy.

## Scope and non-goals

- The lockdown removes Customers, Item Kits, Suppliers, Receivings, Gift Cards, Messages, Expenses, Expenses Categories, and Cashups from module and permission data.
- The lockdown filter returns HTTP 404 for those removed modules when they are requested directly.
- Historical transaction and operational data tables are not removed.
- The till remains cash-only.
- The register-mode list is no longer the one approved on 2026-09-20. See "Register modes superseded on 2026-09-21" below.
- Kitchen tracking, table management, delivery, multi-branch support, and hardware-specific behavior remain out of scope.

## Migration and rollback

The original migration is corrected in place at `app/Database/Migrations/20260920000002_shop_lockdown.php`.
Any database that already ran the earlier migration must be rebuilt from the approved baseline because the migration is recorded as applied and will not be upgraded in place.
The migration checks for an active `config` holder before any write. It then creates and fills the three snapshot tables for the life of the installation: `shop_lockdown_grants`, `shop_lockdown_modules`, and `shop_lockdown_permissions`.
These tables are included by the normal backup and restore scripts and are not temporary tables.
The grant snapshot records every grant row before the policy is applied.
The module and permission snapshots record every module and permission row that the migration removes.
Rollback first removes module and permission rows that this migration added, then restores the recorded rows and replaces grants only for employees present in the grant snapshot.
Employees created after the snapshot keep their current grants.
Rollback refuses absent snapshots instead of guessing at the original state.
The migration drops all three snapshot tables after a successful rollback, so a later fresh run records a fresh snapshot.
Snapshot-table DDL and snapshot inserts run before the transaction. The policy data changes and rollback data changes run inside database transactions; this avoids claiming that MySQL can roll back the preceding `CREATE TABLE` statements.

## Consequences and risks

Administrators retain their selected remaining administration grants without a custom Office route bypass.
Cashiers receive the permissions selected for them, with the two policy ceilings enforced.
Changing a location name or adding a location changes the derived stock-location permission IDs on the next employee save.
The policy grants the single upstream `items` permission, so item editing and deletion remain coupled by the upstream permission model.
Hardware support remains unverified until the project owner names the devices.

Known limitations:

- Saving or renaming a stock location can recreate a permission row for a
  removed module. Access is unaffected, and the next employee save strips it.
- Reports for removed features remain openable by an administrator because the
  route filter checks only the first URI segment. The owner may still see
  reports for features that no longer exist.
- `migrate` is not in the administrative ceiling because upstream's 3.2.0
  migration already deletes that permission.
- A second administrator must be seeded through the documented database step;
  the employee screen cannot promote a non-administrator.
- If the stock `admin` account has no grant rows, the last-administrator guard
  still recognizes that username as a safety fallback. It cannot grant access;
  a real `config` grant is still required.

To create a second administrator outside the employee screen:

1. Back up the database.
2. Create the employee as a normal cashier in the employee screen and note the username.
3. In the database console, remove any administrative grants for that `person_id`, then add the five grants with their fixed groups:

   ```sql
   DELETE FROM <prefix>grants
    WHERE person_id = <person_id>
      AND permission_id IN ('config', 'employees', 'taxes', 'attributes', 'office');

   INSERT INTO <prefix>grants (permission_id, person_id, menu_group) VALUES
     ('office', <person_id>, 'home'),
     ('config', <person_id>, 'office'),
     ('employees', <person_id>, 'office'),
     ('taxes', <person_id>, 'office'),
     ('attributes', <person_id>, 'office');
   ```

4. Log in as the new administrator and use the employee screen to adjust later grants.
5. Verify that at least two active employees have the `config` grant.

Use the configured table prefix when running the database statements. Do not
remove the only active `config` holder.

## Acceptance tests

- `office` is absent from `REMOVED_MODULES`.
- The administrative ceiling lists the five approved permission IDs.
- The menu-group helper returns the accepted values and `--` for unknown or sub-permission IDs.
- A clean migration leaves the Office module at `sort = 999` and keeps its permission row.
- Administrator home modules are Items, Reports, Sales, and Office in native sort order.
- Administrator office modules include Home, Employees, Taxes, Attributes, and Config.
- Non-administrator home modules are exactly Items, Reports, and Sales in native sort order after the migration.
- Non-administrator office modules exclude Office, Config (Settings), Employees, Taxes, and Attributes after the migration; other valid modules such as Home may remain.
- New and existing non-administrator saves strip all five administrative grants, including a posted `config` grant.
- A new employee with no posted grants receives the standard cashier grants.
- Every saved module grant uses `ShopLockdown::MENU_GROUPS`, regardless of the posted placement, and every sub-permission stores `--`.
- The settings Office-icon lookup returns the saved sort value without a missing-row error.
- Employee creation, non-administrator promotion attempts, administrator updates, demotion, and administrator username changes use the capability policy.
- A cashier's exact Home menu is Items, Reports, and Sales; it has no Home or Settings tile.
- Unknown employee saves and last-administrator changes return a failure without changing data, and the controller returns JSON for the refused last-administrator save.
- Deleting the last administrator is refused through the employee delete path.
- The route filter returns 404 for Expenses, Expense Categories, and Cashups in a fully migrated database.
- The full PHPUnit suite and PHP-CS-Fixer pass.
- Browser checks were run in Chrome on 2026-09-21 against a database rebuilt from empty with every migration applied. All 29 checks passed in English and Arabic.
- Browser checks confirmed the administrator home grid as Items, Reports, Sales, Office; a standard cashier as Items, Reports, Sales; a cashier saved with a trimmed selection as Items, Sales; cashier grants stored as `home:office items:home sales:home`; HTTP 403 for a cashier at `/office`, `/config`, and `/employees`; and `lang="ar-LB" dir="rtl"` on the Arabic till.
- Completing a sale, printing a receipt, and processing a return were not exercised in the browser because the rebuilt test database holds no items. This remains outstanding.
