# Phase 1 native capability and gap analysis

Status: Accepted audit for Phase 1  
Date: 2026-09-20  
Baseline: OSPOS 3.4.1, approved `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`  
Repository: `/home/dev-hassanshd/hassan/pos/osposleb`

This document records what the unchanged baseline already provides and what the
Lebanon supermarket and fast-food deployment still needs. It is the evidence
for [ADR 0002](adr/0002-native-feature-configuration-and-gap-analysis.md).

No application configuration, database, hardware, or application code was
changed for this audit. The source was inspected at the approved baseline.

## Dispositions

Each requirement has one disposition. The terms mean:

- `native/configuration`: the baseline already supports it; use the existing UI
  or deployment configuration.
- `small extension`: a focused change to an existing screen or integration is
  needed; no new business model is expected.
- `custom feature`: new business behavior or a new workflow is required.
- `unsupported/deferred`: not present in the baseline and deliberately left for
  a later decision or phase.
- `requires hardware validation`: the software path exists, but compatibility
  cannot be claimed until the actual device and host are tested.

`native/configuration` means code-supported, not production-configured. A
device is only `verified` after a real-device test. The audit found no verified
printer, scanner, or cash drawer.

## Requirements matrix

| Requirement | Disposition | Code and file evidence | Gap, risk, and phase routing | Acceptance checks |
| --- | --- | --- | --- | --- |
| Arabic locale can be selected system-wide and per employee | `native/configuration` | `app/Helpers/locale_helper.php` (`get_languages`, `current_language_code`); `app/Config/App.php` (`supportedLocales`); `app/Controllers/Employees.php` saves `language_code` and `language`; `app/Views/configs/locale_config.php` exposes the system selector | `ar-EG` and `ar-LB` are present. The shop default is Arabic (Lebanon), while the `admin` account is English. Phase 2 found that the per-employee selection had no effect: `app/Events/Load_config.php` applied the system language only. That is now fixed; see ADR 0003. No schema change was needed. | Change an operator and an employee between English and Arabic; log in again; confirm the selected language follows the employee and system fallback. |
| Arabic coverage for operational screens | `small extension` — **closed in Phase 2** | `app/Language/ar-EG/` and `app/Language/ar-LB/` contain the same main files as English, including `Login.php`, `Sales.php`, `Items.php`, `Receivings.php`, `Reports.php`, and `Config.php` | Of the 1543 English keys, only 1418 carry translatable English text; the rest hold an empty English value or are symbols, numbers, and placeholders. At audit time `ar-LB` was 99.58 percent against those 1418 with 6 real gaps, and `ar-EG` was 96.33 percent with 52 gaps. Phase 2 closed both: each locale now covers 1417 of 1418, the single remaining difference being `Common.software_short`, which is the product name. Empty values still fall back to English through `app/Libraries/MY_Language.php::getLine`, which protects future upstream keys. See ADR 0003. | `tests/LanguageCoverageTest.php` compares both Arabic locales against every English key that contains real words and fails on a missing, empty, or English-identical value. |
| Arabic and English remain available together | `native/configuration` | `app/Config/App.php` lists `en`, `en-GB`, `ar-EG`, and `ar-LB`; `app/Helpers/locale_helper.php::get_languages` lists both language choices | Locale switching remains available as a setting. The shop runs in Arabic (Lebanon) by default, the `admin` account runs in English, and the login page is deliberately English and left to right. | Run the same sale, return, item edit, and report scenario in both locales. |
| Global RTL layout | `custom feature` — **implemented in Phase 2, receipts deferred** | `app/Views/partial/header.php` and `app/Views/login.php` render `lang` and `dir`; `public/css/ospos.css` contains fixed left/right alignment rules and no RTL layer | UTF-8 and locale selection do not provide layout direction. Phase 2 added `text_direction()` in `app/Helpers/locale_helper.php`, a `dir` attribute on the shell and login layouts, and `public/css/ospos_rtl.css`, a layer whose every rule is scoped to `[dir="rtl"]` so the English layout cannot change. The login route now deliberately overrides the shop language to English/LTR for that request. Receipts and barcode sheets are not covered and stay with Phase 4 and ADR 0007. See ADR 0004. | `tests/LocaleHelperTest.php` covers the direction helper. Markup, language, and direction were verified live in both languages. Visual layout is still unverified: the browser automation server did not connect. |
| Mixed Arabic/Latin direction for identifiers and free text | `custom feature` — **implemented in Phase 2 for the shell** | Sales and receipt views render item names, descriptions, item numbers, serial numbers, phone/email values, and totals in ordinary table cells; e.g. `app/Views/sales/register.php` and `app/Views/sales/receipt_default.php` | Phase 2 added `.ltr-value` and `.numeric-value` in `public/css/ospos_rtl.css`, both using `unicode-bidi: isolate`, and applied them by selector to identifier and numeric inputs and to the sales cart's item-number column. Selectors rather than view edits were used because the code-style gate would force unsafe strict-comparison rewrites in `register.php`; see ADR 0004. Receipt views remain uncovered. | Copy and visually inspect Arabic names containing `ABC-123`, EAN values, phone numbers, email addresses, URLs, dates, percentages, and currency values. Not yet done: needs a browser. |
| Existing tax and TVA tables | `native/configuration` | `app/Models/Tax.php` reads `tax_rates`; `app/Controllers/Taxes.php` manages tax codes, categories, jurisdictions, rates, and rounding; `app/Views/taxes/tax_rates_form.php` exposes rate and rounding | The destination-based tax model is already substantial. It must be configured only after the business rules below are accepted. Phase 3, ADR 0005. | Create a test tax code/category/rate in an isolated database; sell, return, discount, and report on an item using it. |
| Per-item TVA assignment | `native/configuration` | `app/Models/Item.php` allows `tax_category_id`; `app/Controllers/Items.php` loads and saves the item tax category; `app/Views/items/form.php` shows either item tax percentages or a tax category | OSPOS has two paths: legacy `items_taxes` percentages when destination-based tax is off, and tax-category assignment when it is on. This is not yet a single agreed TVA model. Phase 3 must choose one path and preserve historical sales. | Test an item with a tax category and an item with no category; compare cart, stored sale taxes, receipt, return, and reports. |
| Optional global TVA default | `native/configuration` | `app/Views/configs/tax_config.php` exposes `default_tax_1_rate`, `default_tax_2_rate`, `tax_included`, and destination defaults; `app/Controllers/Config.php::postSaveTax` saves them; new item forms load the default rates | Native defaults exist, but the project has not decided tax-inclusive versus tax-exclusive prices, explicit opt-out, zero-rated versus exempt, or whether a blank item value means inherit. Phase 3 must obtain those decisions before configuration or schema changes. | Verify new-item inheritance, explicit zero/blank behavior, tax-inclusive and tax-exclusive totals, rounding, returns, discounts, receipts, and historical transactions. |
| Explicit TVA precedence and opt-out semantics | `custom feature` | Native settings expose defaults and item values, but no project rule defines `per-item -> global -> none`, nor a distinct inherit/zero-rated/exempt state; tax calculations live in `app/Libraries/Tax_lib.php` and `app/Libraries/Sale_lib.php` | A silent interpretation could change tax, prices, receipts, and reports. Phase 3, ADR 0005, must document the rule and add calculation tests before changing data. | Test all three states (inherit, zero-rated, exempt) and verify the same result in new sale, return, void, discount, receipt, and report paths. |
| Scanner input for item/barcode lookup | `native/configuration` | `app/Views/sales/register.php` has the `item` input labelled find/scan; `app/Controllers/Sales.php::postAdd` passes it to `Token_lib::parse_barcode`; `app/Models/Item.php` resolves `item_number` and item ID | The expected software model is a scanner that acts as a keyboard and sends a terminator. Focus, layout, duplicate barcode policy, unknown codes, and Arabic keyboard settings still need an operator test. Phase 4, ADR 0006. | Keyboard test with an item number, unknown number, duplicate number, quantity/price token, and receipt code; then test the supplied scanner. |
| Barcode generation and label printing | `native/configuration` | `app/Libraries/Barcode_lib.php`; `app/Views/configs/barcode_config.php`; item and kit barcode generation endpoints | Barcode type, content, size, and duplicate policy are configurable. Label readability and printer compatibility are not proven. Phase 4. | Generate a representative label set, scan every label, and confirm the selected item and price. |
| Receipt and invoice templates | `native/configuration` | `app/Views/sales/receipt.php`, `receipt_default.php`, `receipt_short.php`, and `app/Views/configs/receipt_config.php` support templates, tax display, headers, footers, margins, and auto-return | The HTML receipt path is native. Arabic shaping, 58/80 mm layout, code page, and totals still require printer-specific work. Phase 4, ADR 0007. | Browser preview and test prints for long names, modifiers, taxes, discounts, totals, mixed direction, and both paper widths. |
| Physical receipt/invoice printer selection | `requires hardware validation` | `app/Views/partial/print_receipt.php` uses `jsPrintSetup` when available and otherwise `window.print`; receipt config lists receipt, invoice, and takings printers | The integration depends on a browser extension/host printer list. It has not been tested with a device, operating system, driver, paper width, or Arabic support. Phase 4, ADR 0006/0007. | Record exact model, interface, OS, browser, driver, paper width, and code-page/raster support; test normal print, reprint, failure, and recovery. |
| Cash drawer opening | `unsupported/deferred` | Receipt printing code selects a printer but contains no drawer command, cash-drawer device setting, or payment-type security check; no cash-drawer implementation was found in `app/` | A printer may expose a drawer, but OSPOS does not currently trigger or control it. Do not claim support. Phase 4 must decide whether a confirmed printer/driver can provide the feature or whether a small host integration is needed. | With a supplied drawer, prove open-on-authorized-cash-payment only; prove no open on void, return, unauthorized action, or non-cash payment. |
| Fixed item kits/combos | `native/configuration` | `app/Models/Item_kit.php`, `app/Models/Item_kit_items.php`, `app/Controllers/Item_kits.php`, and `app/Libraries/Sale_lib.php::add_item_kit` support components, quantities, kit discount, price option, and print option | Native kits cover fixed recipes and several price/print choices. They do not implement choice groups or fast-food modifier rules. Phase 5 may configure fixed combos first; ADR 0008 governs extensions. | Create a kit, sell it, refund/void it, verify inventory movement, price, discount, tax, receipt, and report. |
| Item variants and size choices | `unsupported/deferred` | `app/Models/Item.php` has item type, stock, pack fields, and generic attributes, but no variant/SKU/option relationship or choice API | Generic attributes are metadata, not selectable price/stock variants. Phase 5 must decide whether sizes are separate items, kits, or a new small model. | Attempt size selection with a standard item; document the manual workaround and verify no silent inventory or tax change. |
| Generic item attributes visible in sales | `native/configuration` | `app/Models/Attribute.php` defines `SHOW_IN_SALES`; `app/Libraries/Sale_lib.php::add_item` copies visible attributes; `app/Views/sales/register.php` renders attribute values | Attributes are native and can display text, dropdown, date, decimal, and checkbox data. They do not enforce required/maximum selections or add prices. Phase 5 can reuse them for descriptive data. | Define a display attribute, show it in the register and receipt, edit it, and verify sale history remains readable. |
| Paid/free extras, removals, required groups, and duplicate modifiers | `custom feature` | No modifier, add-on, removal, minimum/maximum selection, or per-option price fields occur in `app/Models/Item.php`, `Item_kit.php`, or the sales views | These are core fast-food business rules. Adding them only as free text would lose price, tax, inventory, refund, and reporting integrity. Phase 5, ADR 0008. | Test required and optional groups, paid/free extras, “no onion”, duplicates, correction, refund, void, receipt, report, and inventory effects. |
| Touch-friendly fast-food sales screen | `custom feature` | `app/Views/sales/register.php` is a keyboard/table-based register with small Bootstrap buttons and an item search field; `app/Views/sales/help.php` documents keyboard shortcuts | The current register is usable for keyboard/scanner retail but is not a low-interaction menu board. Phase 5 must add a focused touch workflow without changing the normal English/LTR register. | Run representative orders on the target screen size; measure large targets, correction count, payment speed, and visibility of selected options. |
| Sales totals, discounts, returns, voids, and reprints | `native/configuration` | `app/Controllers/Sales.php` and `app/Libraries/Sale_lib.php` support add/edit/payment/return/delete/restore paths; receipt views support reprint | Native retail transaction paths exist. They must be regression-tested after tax, modifier, and combo changes. Phase 3/5/6. | Complete sale, suspended sale if used, return, void, discount, refund, and reprint with totals matching stored data and reports. |
| Sales, tax, inventory, employee, payment, and receiving reports | `native/configuration` | `app/Controllers/Reports.php` wires summary and detailed report models, including `summary_sales_taxes`; report access is checked per submodule | Native reporting is broad, but new TVA/modifier data will need report columns and agreement with receipts. Phase 3/5 must extend only where required. | Run each operator-needed report for a fixed date range and reconcile totals against sale, tax, payment, and inventory records. |
| Users, modules, subpermissions, and employee language | `native/configuration` | `app/Controllers/Employees.php::getView/postSave`; `app/Models/Employee.php::save_employee/has_grant`; `app/Views/employees/form.php` exposes module and subpermission grants | Per-user access and language are native. Review destructive permissions such as sales delete, price change, config, and reports before deployment. Phase 6 hardening. | Create cashier/manager accounts; prove denied routes and allowed routes; test language selection and no self-delete. |
| Database backup and restore | `unsupported/deferred` | No database backup/restore controller or command exists. `app/Helpers/security_helper.php` only makes a temporary `.env.bak`; `app/Database/resetdatabase.sh` drops/recreates the database | Production backups, retention, off-machine copies, and restore drills are outside the baseline. Phase 6, ADR 0009, must define and test the operational process. Never use `resetdatabase.sh` as a backup. | Take a real database and uploads backup using the deployment tool, restore into an isolated database, run login/sale/report checks, and record recovery time. |
| Database update behavior | `native/configuration` | `app/Controllers/Login.php` checks migration state and runs `latest()` after login; `app/Libraries/MY_Migration.php` converts old migration tables and reports current/latest versions; `app/Config/Migrations.php` enables migrations | Native migrations are applied automatically at login. Many historical migration `down()` methods are empty, and no tested rollback plan exists. Phase 6 must require a backup and rehearsal before upgrades. | Upgrade a copy from the approved schema, inspect migration history, log in, run a sale/report, and restore the pre-upgrade backup. |
| Update rollback and release safety | `unsupported/deferred` | `MY_Migration::up()` and `down()` are intentionally empty overrides; several migrations have empty `down()` methods; `UPGRADE.md` instructs manual code/database/config/uploads steps | Reverting application code alone may not revert schema or data. Phase 6 must define release snapshots, backup verification, and a forward-fix/restore procedure. | Rehearse a failed migration or bad release on a disposable copy and confirm recovery without touching the only production database. |

## Detailed findings by audit category

### Arabic locale coverage

Both Arabic locales have the normal operational language files. `ar-LB` is
already selectable in `get_languages()`, and the employee record can override
the system language. `MY_Language::getLine()` intentionally falls back to the
base language and then English when a value is missing or empty.

Arabic coverage was measured, not estimated, and the first measurement was
wrong in a way worth recording. Counting every key in `app/Language/en/` made
`ar-LB` look 92.9 percent complete with 109 gaps. Most of those keys hold an
empty English value, so there is nothing to translate and the locale is not
behind at all.

Counting only keys whose English value contains real words gives 1418 keys.
Against that set, `ar-LB` is 99.58 percent translated, with 6 genuine gaps:
`Common.no`, `Common.yes`, `Config.system_info`, `Sales.key_function`,
`Sales.selected_customer`, and `Common.software_short`. The last of those is
the product name and should stay in Latin script.

`Sales.selected_customer` is absent from `ar-LB`, `ar-EG`, and 35 other
locales. Upstream added it to English only.

`ar-EG` is 96.33 percent translated. 45 of its 52 gaps are the `Calendar.php`
file, which that locale does not ship at all, plus one login validation
message. `ar-EG` is not the deployment target and English fallback covers it.

Phase 2 is therefore a terminology review plus six strings, not a translation
project. It should close those gaps, confirm Lebanese terminology across the
operational screens, and add an automated check that compares against keys with
real English content rather than against every key. It should not
remove the fallback, because the fallback protects other locales and keeps
upstream upgrades safe when new keys arrive.

### RTL and mixed direction

The baseline emits a locale-aware `lang` attribute and UTF-8 metadata, but no
`dir="rtl"` attribute or RTL stylesheet was found. The main CSS and many views
use fixed `text-align: left/right` values. This is a real layout gap, not a
translation gap. Phase 2 should isolate direction-sensitive identifiers instead
of applying a global text reversal to SKUs, barcodes, numbers, or URLs.

### Tax and TVA

The code contains both an older per-item percentage table and a newer
tax-code/category/rate model. The newer model also records tax type and rounding
in sale tax history. Existing functionality is enough to configure a test tax
code, but not enough to choose the project's final TVA policy safely. In
particular, the following decisions remain open:

- tax-inclusive or tax-exclusive prices;
- whether a missing item tax inherits a global default;
- how an item opts out of a global default;
- zero-rated versus exempt treatment;
- rounding precision and whether rounding is per line or per invoice;
- discount, return, and historical transaction behavior.

These decisions belong in ADR 0005 and require user approval before schema or
calculation changes. No tax setting was changed in this audit.

### Barcode and hardware

The software accepts an item code in the normal sales input, parses configured
barcode tokens, resolves item numbers, and can generate barcode labels. The
expected scanner integration is keyboard input. Scanner focus, terminators,
duplicate policy, and Arabic keyboard layouts still need manual testing.

The receipt path uses `jsPrintSetup` when the browser extension is available and
falls back to `window.print()`. This is code-supported browser printing, not
verified hardware support. No cash-drawer command or drawer permission check
was found. Hardware status is therefore `expected` for the browser printing
path and `unsupported/deferred` for drawer control until a device and host
integration are selected.

### Kits, attributes, modifiers, and sales usability

Item kits support fixed component quantities, kit discounts, price options, and
print options. Attributes can be shown in sales, but they are descriptive and
do not change price or inventory. There are no native modifier groups, paid
extras, removals, required selections, or variant relationships.

The register has a fast keyboard and scanner path, but its table layout and
small controls are not a touch-friendly fast-food menu. Phase 5 should keep
native fixed kits where they fit and add the smallest isolated model/workflow
for priced modifiers and menu choices.

### Reporting and permissions

The reports controller includes sales, tax, inventory, payments, employees,
customers, suppliers, expenses, and detailed reports. Report submodules are
checked with employee grants. The employee form manages module grants,
subpermissions, menu placement, and per-user language.

The native model is suitable for initial deployment. New TVA or modifier fields
must be added to reports only after their calculation and historical semantics
are accepted.

### Backup, restore, and updates

The baseline has no application-level database backup or restore workflow. Its
only `writable/backup` use is a temporary `.env` copy while encryption settings
are repaired. The reset script is destructive. OSPOS migrations run from the
login path, and several migration rollback methods are empty. Phase 6 must
provide an external backup/restore runbook, an isolated restore drill, and a
release rollback procedure before production handoff.

## Source evidence index

The following anchors make the main findings easy to reproduce in the approved
baseline:

- Locale and fallback: `app/Config/App.php:132-176`,
  `app/Helpers/locale_helper.php:12-84`, and
  `app/Libraries/MY_Language.php:10-35`.
- Direction and document language: `app/Views/partial/header.php:15-18`,
  `app/Views/login.php:13-16`, and `public/css/ospos.css:97-101` plus the
  fixed alignment rules in receipt and sales views.
- Tax configuration and item assignment: `app/Views/configs/tax_config.php:31-110`,
  `app/Views/configs/tax_config.php:148-153`,
  `app/Controllers/Config.php:772-794`, and
  `app/Views/items/form.php:217-280`.
- Barcode and sale entry: `app/Controllers/Sales.php:480-545`,
  `app/Libraries/Sale_lib.php:964-1104`, and
  `app/Models/Item.php:49-75`.
- Browser printing: `app/Views/partial/print_receipt.php:12-61` and
  `app/Views/configs/receipt_config.php:340-366`.
- Kits and attributes: `app/Controllers/Item_kits.php:128-208`,
  `app/Models/Item_kit_items.php:20-74`,
  `app/Models/Attribute.php:41-43`, and
  `app/Libraries/Sale_lib.php:1070-1104,1264-1290`.
- Reports and grants: `app/Controllers/Reports.php:86-91,491-520`,
  `app/Controllers/Employees.php:78-163`, and
  `app/Models/Employee.php:130-181,450-476`.
- Migration and update behavior: `app/Controllers/Login.php:22-70`,
  `app/Libraries/MY_Migration.php:14-118`, and
  `app/Config/Migrations.php:7-44`.
- Backup and destructive reset: `app/Helpers/security_helper.php:9-109` and
  `app/Database/resetdatabase.sh:1-4`.

## Configuration recommendation

The shop language decision is settled: the application defaults to Arabic
(Lebanon), the `admin` account is English, and the login route is English/LTR.
The remaining configuration candidates change other operational or tax
decisions:

- `number_locale`, currency, decimals, and timezone change printed and entered
  values;
- `use_destination_based_tax`, tax rates, and `tax_included` change tax
  calculations;
- duplicate barcode policy changes item lookup behavior;
- silent printing and printer selection depend on the actual host and device.

The safe native action is procedural: use the existing configuration screen to
change the shop language when needed and create test tax codes in an isolated
database. Do not apply tax values to the shared baseline or production data
until the relevant ADR and business decisions are accepted.

## Verification record

- Source inspection covered locale helpers and language files, layout views and
  CSS, tax models/controllers/views, sales and barcode libraries, receipt
  printing, kits and attributes, reports, employee grants, migrations, and
  backup/update scripts.
- Arabic coverage was measured programmatically against the English key set
  rather than estimated.
- The baseline was built and run. Frontend dependencies installed, the asset
  build produced its output, the application image built, and a disposable
  MariaDB 10.5 stack served the login page, applied 40 migrations on first
  login, and returned the dashboard, sales, items, customers, suppliers,
  reports, settings, and item-kit pages while authenticated. Full evidence is
  in [ADR 0001](adr/0001-upstream-release-and-baseline.md).
- The repository test command could not run. It points at a bootstrap file that
  this installation layout never creates, and the baseline contains no PHP test
  classes. There is no baseline PHP test result to preserve.
- No application files, repository compose files, or committed configuration
  were changed. The verification stack and its environment file were created
  outside version control and removed afterwards.
- No physical hardware was available or tested. Printer, scanner, and drawer
  status remains unverified.

## Required decisions before later phases

- Accept the RTL and mixed-direction rules in ADR 0004.
- Accept TVA inclusion, precedence, opt-out, exemption, and rounding rules in
  ADR 0005 before changing tax configuration or schema.
- Provide exact scanner, receipt printer, and cash-drawer models, interfaces,
  operating system, browser, drivers, paper width, and Arabic support details
  for ADRs 0006 and 0007.
- Decide whether fast-food sizes and combos can be represented as separate
  items/kits or need the Phase 5 modifier feature in ADR 0008.
- Approve the backup target, retention, restore objective, and update rollback
  process in ADR 0009.

## Phase routing

- Phase 2: Arabic translations, RTL, mixed-direction rendering, and locale
  completeness checks.
- Phase 3: one accepted TVA model, configuration, precedence, rounding, and
  calculation tests.
- Phase 4: device matrix, scanner checks, printer checks, cash-drawer decision,
  and Arabic receipt rendering.
- Phase 5: touch sales, sizes, modifiers, combos, and kitchen ticket routing.
- Phase 6: end-to-end regression, permissions review, backup/restore drill,
  migration rehearsal, and production hardening.

## Phase 2 addendum

This section records what Phase 2 changed and what it deliberately left alone.
The Phase 1 findings above are kept as written, so the audit and the work done
against it can be compared.

### Arabic coverage is closed for both locales

Both `ar-LB` and `ar-EG` now translate 1417 of the 1418 English keys that carry
real words. The one difference is `Common.software_short`, which holds the
product name `OSPOS` and is deliberately left in Latin script.

Closing `ar-LB` took the six strings the audit identified. Closing `ar-EG` took
a new `Calendar.php`, which that locale did not ship, and one login validation
message. `ar-EG` is not the deployment target, but leaving it incomplete means
the base-language fallback can be reached with an English string, and the two
additions are suitable for contribution upstream.

`tests/LanguageCoverageTest.php` now enforces this. It compares against keys
whose English value matches `/[A-Za-z]{2,}/`, which is the correct denominator
and the thing the first measurement got wrong. It also asserts the English
source still yields more than 1000 translatable keys, so a broken loader cannot
make the check pass by finding nothing to compare.

### Lebanese month names replaced Egyptian ones

`app/Language/ar-LB/Calendar.php` shipped the international month names used in
Egypt and the Gulf. Lebanon uses the Levantine names, so the file now reads
`كانون الثاني`, `شباط`, `آذار`, `نيسان`, `أيار`, `حزيران`, `تموز`, `آب`,
`أيلول`, `تشرين الأول`, `تشرين الثاني`, and `كانون الأول`. Monday is now spelled
`الاثنين` rather than the Egyptian colloquial `الإتنين`. This is a judgement
call, it is confined to that one file, and it is reversible on its own.

### The per-employee language did not work

This was not in the Phase 1 findings and was found while verifying the
direction work. `app/Helpers/locale_helper.php::current_language_code()` prefers
the logged-in employee's language, but `app/Events/Load_config.php` ignored the
helper and called `setLocale()` with the system setting. An employee who chose
Arabic still got the system language, so the employee language picker and the
`language_code` column had no effect on translations.

The audit row for locale selection said this was `native/configuration` and
safe to use. That was right about the schema and wrong about the behavior. The
event now calls `current_language_code()`, so the employee's language applies
when set and the system language applies otherwise.

Anyone reading the Phase 1 row should treat the fix as part of Phase 2's scope
rather than as a separate defect.

### What direction support covers

`text_direction()` and `is_right_to_left()` in `app/Helpers/locale_helper.php`
derive the direction from the base language, against the list in
`RIGHT_TO_LEFT_LANGUAGES` in `app/Config/Constants.php`. The shell and login
layouts now render `dir` from the same source as `lang`, which is
`current_language_code()` rather than `$request->getLocale()`. The old source
was browser content negotiation and could declare a language the page was not
rendered in.

`public/css/ospos_rtl.css` is the layer. Every rule is scoped to `[dir="rtl"]`
except `.ltr-value` and `.numeric-value`, which must hold in either language.
`gulpfile.js` appends it last to the debug and production bundles.

### What direction support does not cover

Receipts and barcode sheets are untouched and stay with Phase 4 and ADR 0007.
Their templates use inline `style="text-align: right"`, which class-based rules
cannot override, so they need their own approach.

The login page loads Bootswatch 5 and its own stylesheet rather than the
application bundle, so it gets the browser's native right-to-left handling from
the `dir` attribute and not the layer.

Charts, report graphs, and icons are not mirrored.

### Files not changed on purpose

`app/Views/sales/register.php`, `app/Views/sales/quote_email.php`,
`app/Views/sales/work_order_email.php`, and `app/Views/barcodes/barcode_sheet.php`
were reverted after an initial attempt to add classes to them.

The repository's continuous integration runs PHP-CS-Fixer over every PHP file
changed in a pull request, and the configuration disagrees with 1274 of the
1329 files in the tree. Changing one attribute in a view therefore requires
reformatting that whole file. On these four, the reformatting rewrites loose
comparisons into strict ones on values that come from the database, including
`$item['print_option'] === PRINT_YES`, `$item['discount_type'] === FIXED`, and
`$config['company_logo'] !== ''`. Upstream has marked several with
`// TODO: ===` because the types are not verified. A wrong strict comparison
there makes items disappear from emailed quotes and work orders.

Everything needed from `register.php` is achieved by selector instead. The
other three are print and email documents that Phase 4 owns.

For the two files that were changed and reformatted,
`app/Views/partial/header.php` and `app/Views/login.php`, every strict
comparison the formatter introduced was checked against the database first:
`ospos_modules.module_id` is `varchar(255)`, and `theme`, `login_form`, and
`company_logo` are string columns in `ospos_app_config`.

### Verification performed

Against a disposable MariaDB 10.5 and PHP 8.2 stack:

- Employee language `ar-LB`: ten pages returned HTTP 200 with
  `<html lang="ar-LB" dir="rtl">` and Arabic text.
- Employee language cleared, system language `ar-EG`: the same pages and the
  login page returned HTTP 200 with `<html lang="ar-EG" dir="rtl">` and Arabic
  text, which confirms the fallback.
- English configured: twelve pages returned HTTP 200 with
  `<html lang="en" dir="ltr">`, zero Arabic characters, and no `dir="rtl"`.
- The direction layer is present in the served production bundle, which grew
  from about 114.6 KB to about 117.3 KB.
- Test suite: 20 tests, 36 assertions, green on PHP 8.2.33 with PHPUnit
  11.5.15.
- PHP-CS-Fixer reports 0 of 15 changed PHP files needing changes.

Still unverified: the rendered visual layout. The browser automation server
available to the session failed to connect, so no screenshots exist. The
evidence covers markup, language, direction, and stylesheet delivery, not
pixels.
