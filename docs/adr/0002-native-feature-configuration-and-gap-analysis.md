# ADR 0002: Native feature configuration and gap-analysis method

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Phase 1 native capability audit for the approved OSPOS 3.4.1 baseline
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`
- Branch: `chore/ospos-baseline`

## Context and current upstream behavior

The project fork is based on OSPOS 3.4.1. Phase 1 requires an audit before
custom code or database changes. The audit must distinguish existing OSPOS
behavior from project-specific gaps and must not claim hardware support without
real-device testing.

The baseline already includes these relevant native paths:

- Locale selection in `app/Helpers/locale_helper.php`, including `ar-EG`,
  `ar-LB`, employee-level language, and system fallback.
- `app/Config/App.php` lists Arabic and English supported locales.
- `app/Libraries/MY_Language.php::getLine` falls back from a regional locale to
  its base locale and then English when a translation is missing or empty.
- Tax codes, categories, jurisdictions, rates, and rounding in `app/Models/Tax.php`,
  `app/Controllers/Taxes.php`, `app/Views/taxes/`, and `app/Libraries/Tax_lib.php`.
- Item tax percentages or tax-category assignment in
  `app/Models/Item.php`, `app/Controllers/Items.php`, and
  `app/Views/items/form.php`.
- Barcode input/token parsing in `app/Views/sales/register.php`,
  `app/Controllers/Sales.php::postAdd`, and `app/Libraries/Token_lib.php`.
- Barcode label generation in `app/Libraries/Barcode_lib.php` and the barcode
  configuration view.
- Browser receipt printing in `app/Views/partial/print_receipt.php`, using
  `jsPrintSetup` when present and `window.print()` otherwise.
- Fixed item kits in `app/Models/Item_kit.php`,
  `app/Models/Item_kit_items.php`, `app/Controllers/Item_kits.php`, and
  `app/Libraries/Sale_lib.php`.
- Generic item attributes, including the `SHOW_IN_SALES` flag, in
  `app/Models/Attribute.php` and the attributes views.
- Sales, tax, inventory, payment, employee, customer, supplier, and receiving
  reports in `app/Controllers/Reports.php` and the report models.
- Employee module and subpermission grants in `app/Models/Employee.php`,
  `app/Controllers/Employees.php`, and `app/Views/employees/form.php`.
- Database migrations run by the login path in `app/Controllers/Login.php` and
  `app/Libraries/MY_Migration.php`.

The audit also found material gaps:

- Arabic files contain many empty values, which intentionally fall back to
  English.
- The shared layout has no `dir="rtl"` behavior or RTL stylesheet, and views
  contain fixed left/right alignment rules.
- Tax support has both a legacy item-percentage path and a destination-based
  tax-category path. Project-specific TVA precedence, exemption, inclusion,
  and rounding rules are not yet accepted.
- The sales input is suitable for keyboard/scanner entry, but the register is
  not a touch-first fast-food workflow.
- Item kits are fixed recipes. Generic attributes do not provide priced
  modifiers, variant choices, required groups, or removals.
- The receipt path is browser/host dependent. No cash-drawer command or drawer
  security rule exists in the baseline.
- No database backup/restore workflow exists. The `writable/backup` helper is
  only for a temporary `.env` backup, and the database reset script is
  destructive.
- Migrations are applied on login and several historical `down()` methods are
  empty, so an application rollback is not a complete database rollback.

The full evidence matrix is in [gap-analysis.md](../gap-analysis.md).

## Requirements and non-goals

### Requirements

This decision must produce a repeatable Phase 1 method that:

1. Audits Arabic coverage, RTL/mixed direction, tax, barcode, receipt and
   drawer behavior, kits/variants/attributes/modifiers, sales usability,
   reports, users/permissions, backup/restore, and update behavior.
2. Assigns exactly one disposition to every audited requirement:
   `native/configuration`, `small extension`, `custom feature`,
   `unsupported/deferred`, or `requires hardware validation`.
3. Records precise source evidence, risks, phase routing, and automated or
   manual acceptance checks.
4. Separates code-supported behavior from device-verified behavior.
5. Identifies safe native configuration actions without silently changing tax,
   prices, inventory, receipts, permissions, or historical data.
6. Preserves the English/LTR path and existing data while leaving later feature
   work isolated for upstream upgrades.

### Non-goals

- No application code, generated dependency, database, `.env`, or hardware
  configuration changes in Phase 1 audit documentation.
- No choice of TVA inclusion, precedence, exemption, or rounding policy.
- No default-language change for production.
- No claim that any scanner, printer, or cash drawer is compatible or verified.
- No modifier, kitchen-status, kitchen-display, delivery, dine-in, or
  multi-branch implementation.
- No backup deletion, database reset, migration rollback, or other destructive
  operation.

## Options considered

### Option 1: Start custom feature work from the requirements list

This would move quickly but risks duplicating native tax, kit, attribute,
report, and permission behavior. It could change historical transaction meaning
before the project has accepted TVA rules.

### Option 2: Configure every plausible native setting immediately

This would demonstrate more screens, but values such as tax inclusion, default
rates, number locale, currency, default language, silent printing, and duplicate
barcode policy affect real operations. Applying them without a shop decision
would be an unsafe data and workflow change.

### Option 3: Audit native behavior first and route each gap explicitly

This makes the existing code the starting point, keeps configuration changes
reversible, and provides a focused scope for ADRs 0003 through 0009. It also
allows device work to wait for exact hardware details.

## Decision

Choose Option 3.

The project will use the following audit workflow:

1. Inspect the unchanged baseline source, schema/migrations, views, language
   files, tests, and supported build/operation documentation.
2. Record one disposition per requirement in `docs/gap-analysis.md`.
3. Treat a behavior as native only when the source demonstrates it. Treat a
   device as verified only after a test on the actual supplied device.
4. Use the existing configuration screens for isolated test data only. Do not
   commit default-language, number/currency, tax, barcode, or printer values
   until their owner decisions are recorded in the later ADR.
5. Route translations and direction to Phase 2, TVA semantics to Phase 3,
   physical hardware and receipts to Phase 4, fast-food workflow to Phase 5,
   and backup/update hardening to Phase 6.
6. Require focused automated tests for new business logic and manual tests for
   layout, scanners, printers, drawers, and restore drills.

The only safe native action identified for the current audit is procedural:
select `ar-LB` for a test employee and create test tax records in an isolated
database. Neither action is committed as application configuration.

## Data-model, migration, compatibility, and rollback impact

- This ADR and the gap analysis add documentation only. There is no schema
  migration, data rewrite, or application rollback required for these files.
- Existing tax tables, item tax data, item kits, attributes, sales history,
  reports, grants, and locale fields remain unchanged.
- Any later TVA or modifier schema change must first be accepted in its own
  workstream ADR, tested against a backup copy, and have a forward migration
  plus an explicit restore/rollback path.
- OSPOS migration files are applied from the login path. The migration runner
  has an explicit migration history, but multiple historical `down()` methods
  are empty. Restoring a known-good database backup is therefore the default
  rollback strategy for schema-affecting later work.
- The audit preserves upstream compatibility by documenting existing seams
  rather than changing core controllers, models, or templates.

## Impact assessment

### Security and privacy

No secrets, credentials, customer data, database, or backup files were added.
The audit keeps the existing grant model in scope and flags destructive sales,
price-change, configuration, and report grants for Phase 6 review. A production
backup design must protect database and `.env` secrets and must never expose
them through the web root.

### Performance

The chosen method adds no runtime work. Later direction and translation work
must avoid global scripts that slow the register, and later modifier/report
work must keep queries focused on the shop workflow.

### Localization

The existing regional locale and English fallback are preserved. Phase 2 must
replace empty operational Arabic values with reviewed translations while
keeping fallback behavior for non-operational or newly added keys during
upgrades.

### Hardware

The source demonstrates a browser print path and keyboard-style barcode entry;
it does not prove a physical device works. Printer, scanner, and drawer status
remains unverified. Hardware-specific claims are deferred to ADRs 0006 and
0007 after the owner provides device details.

### Operations

No configuration is silently changed. Operators must back up the database and
uploads before any later migration rehearsal. Phase 6 will define backup
retention, restore checks, update rehearsal, logs, and release rollback.

## Test and acceptance criteria

The Phase 1 audit is accepted when:

- [gap-analysis.md](../gap-analysis.md) covers every Phase 1 category and each
  row has exactly one allowed disposition.
- Code evidence identifies the existing native path and its limitation.
- Code-supported behavior and real-device verification are clearly separated.
- No application, database, or production configuration change is hidden in
  the audit.
- The next workstream and its required user decisions are named.

Planned checks for later work include:

- Arabic/English key completeness, login, register, item, inventory, customer,
  payment, return, report, error, and receipt review.
- LTR/RTL visual checks and mixed-direction copy/read checks.
- TVA calculations for inheritance, opt-out, inclusion, rounding, discounts,
  returns, receipts, reports, and historical transactions.
- Scanner focus, terminator, unknown code, duplicate code, quantity/price token,
  and Arabic keyboard tests.
- Printer preview/print/reprint/failure tests and drawer authorization tests on
  actual devices.
- Kit, attribute, modifier, combo, inventory, receipt, report, void, and refund
  tests.
- Permission-denial tests and isolated backup/restore and migration rehearsals.

The documentation-only verification record is in the gap analysis. No PHPUnit
run was attempted because the available environment has no PHP runtime.

## Consequences, risks, and follow-up work

### Positive consequences

- Native OSPOS behavior is reused where it is already sufficient.
- Later feature work has a smaller, explicit scope and clear phase ownership.
- Tax, hardware, and restore decisions are not hidden inside a premature
  configuration commit.
- Future upstream rebases can compare changes against documented native seams.

### Risks

- Empty Arabic translations can leave operators in mixed Arabic/English until
  Phase 2 is complete.
- Choosing a tax setting before ADR 0005 could change receipt and report totals.
- Browser printing may require a host extension or may behave differently on the
  target printer and OS.
- The current migration path can leave no simple down migration; a bad upgrade
  without a backup could be difficult to recover.
- Treating attributes or kits as modifiers would lose price, tax, inventory, or
  refund detail.

### Follow-up work

- ADR 0003: Arabic terminology and translation completeness.
- ADR 0004: RTL and mixed-direction rules.
- ADR 0005: TVA model and business semantics.
- ADR 0006: scanner, printer, cash drawer, and deployment topology.
- ADR 0007: Arabic receipt output.
- ADR 0008: touch fast-food, modifiers, combos, and kitchen ticket routing.
- ADR 0009: backup, restore, update, and production hardening.

## Links and evidence

- [Implementation plan](../OSPOS_IMPLEMENTATION_PLAN.md), Phase 1 and ADR
  requirements.
- [Phase 1 gap analysis](../gap-analysis.md), source-by-source matrix and test
  routing.
- [Baseline ADR](0001-upstream-release-and-baseline.md), approved release and
  repository layout.
- [OSPOS README](../../README.md), native feature list and hardware wiki link.
- [OSPOS installation guide](../../INSTALL.md), supported PHP/MySQL/Apache
  baseline and manual installation flow.
- [OSPOS upgrade guide](../../UPGRADE.md), manual upgrade sequence and the
  warning that the document is stale for CodeIgniter 4.
- Implementation branch: `chore/ospos-baseline`.
- Commit and pull-request links: to be added by the parent agent when the
  documentation commit is created and reviewed.
