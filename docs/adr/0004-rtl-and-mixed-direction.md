# ADR 0004: Right-to-left layout and mixed-direction content

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Phase 2 right-to-left support for the approved OSPOS 3.4.1 baseline
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`
- Branch: `feat/arabic-localization`

## Context and current upstream behavior

Phase 1 recorded that the shared layout had no writing-direction behavior and no right-to-left stylesheet, and that views carried fixed left and right alignment. Translating the interface without addressing this produces Arabic text in a left-to-right layout, which reads badly and puts the navigation, totals, and action buttons on the wrong side.

Four properties of the baseline shape what is possible.

### The layout declared the wrong language

`app/Views/partial/header.php` rendered `<html lang="<?= $request->getLocale() ?>">`. That value comes from CodeIgniter's content negotiation against the browser's `Accept-Language` header, not from the language the page is actually rendered in. A browser advertising Arabic could produce `lang="ar-EG"` on a page rendered entirely in English.

### The interface is Bootstrap 3, the login page is Bootstrap 5

The application shell uses Bootstrap 3.4.1, which positions with `float`, `pull-left`, `pull-right`, and directional padding. It has no logical-property support and no right-to-left build in this project. The login page is separate: it loads Bootswatch 5 and its own `public/css/login.css`, and it does not load the application bundle at all.

### Some values must stay left to right inside Arabic text

Barcodes, SKUs, invoice numbers, phone numbers, email addresses, URLs, and money amounts are Latin and numeric. Placed unmarked inside a right-to-left paragraph, the Unicode bidirectional algorithm reorders their leading and trailing characters, so a barcode can display in an order that does not match what is stored and does not survive being copied.

### The sales cart markup carries no hooks

The cart rows in `app/Views/sales/register.php` are generated inside a loop and the cells carry no class of their own.

### The code-style gate constrains which files can be touched

The repository's continuous integration runs PHP-CS-Fixer over every PHP file changed in a pull request, and the configuration disagrees with most of the upstream tree. Touching a view for one attribute therefore requires that whole file to be reformatted. On `app/Views/sales/register.php`, `app/Views/sales/quote_email.php`, and `app/Views/sales/work_order_email.php`, that reformatting rewrites loose comparisons into strict ones on values that come from the database, including `$item['print_option'] === PRINT_YES`, `$item['discount_type'] === FIXED`, and `$config['company_logo'] !== ''`. Upstream has marked several of these with `// TODO: ===` because their types are not verified. Applying them blind would risk items vanishing from emailed quotes and work orders.

## Requirements and non-goals

### Requirements

1. Arabic renders right to left across the application shell.
2. English is untouched, to the byte.
3. Identifiers and numbers keep left-to-right order and remain correct when copied.
4. The direction follows the language the page is actually rendered in.
5. No upstream view is reformatted where that reformatting would change behavior.

### Non-goals

- No replacement of Bootstrap 3 and no upgrade of the application shell.
- No right-to-left work on receipts or barcode sheets. Phase 4 and ADR 0007 own those.
- No mirroring of icons, charts, or the report graphs.
- No change to how amounts are calculated, rounded, or stored.

## Options considered

### Option 1: Adopt a right-to-left Bootstrap build

Swapping in an RTL build of Bootstrap would handle the grid and components wholesale. It was rejected because Bootstrap 3.4.1 has no maintained right-to-left build, because a Bootstrap upgrade would touch every view in the application, and because it would change the English layout, which requirement 2 forbids.

### Option 2: Post-process the built stylesheet with an RTL flipper

A tool such as `rtlcss` can generate a mirrored stylesheet from the existing one. It was rejected because it produces a second full stylesheet that must be selected at request time, doubling what has to be built, served, and kept in sync, and because the generated output is hard to review and to correct by hand where the flip is wrong.

### Option 3: A hand-written direction layer scoped to `[dir="rtl"]`

One additional stylesheet, appended last to the bundle, in which every selector is scoped to `[dir="rtl"]`. In a left-to-right page no selector matches, so the English layout cannot change. The file is reviewable, correctable, and additive.

## Decision

Choose Option 3.

### Direction source

`app/Helpers/locale_helper.php` gains two functions. `text_direction()` returns `rtl` or `ltr` for a language code, comparing the base language against `RIGHT_TO_LEFT_LANGUAGES` in `app/Config/Constants.php`, which lists `ar`, `ckb`, `fa`, `he`, and `ur`. `is_right_to_left()` is the boolean form. Both accept a language code and default to `current_language_code()`, and both ignore case and accept either `ar-LB` or `ar_LB`.

`app/Views/partial/header.php` now renders `<html lang="<?= $language_code ?>" dir="<?= text_direction($language_code) ?>">` from `current_language_code()`, so the declared language, the direction, and the loaded translations all come from one source. This replaces `$request->getLocale()`, which was the wrong source. `app/Views/login.php` gains the same `dir` attribute; it already used `current_language_code()` for `lang`.

### Direction layer

`public/css/ospos_rtl.css` holds the layer. Every rule is scoped to `[dir="rtl"]`, except the two shared helper classes described below. `gulpfile.js` appends it last to both the debug list and the production bundle, so it overrides Bootstrap and the application styles above it. It covers the base direction, the `pull-left` and `pull-right` helpers, every `col-*` float, the navbar and dropdowns, list padding, form controls including checkbox, radio, and input-group, tables including the `fixed-table-*` classes, modal, alert, and close, and the application's own identifiers such as the register wrapper, the sale totals, the payment panel, and the permission list.

### Mixed-direction rules

Two classes are defined without a direction scope, because a value that must read left to right must do so in either interface language.

- `.ltr-value` sets `direction: ltr` and `unicode-bidi: isolate`. It is for identifiers: barcodes, SKUs, invoice numbers, phone numbers, email addresses, URLs. `isolate` rather than `embed` so the value cannot disturb the ordering of the text around it.
- `.numeric-value` does the same and right-aligns, for numeric columns.

Applying them is done by selector rather than by editing views, for the reason given under the code-style gate above. The layer marks `input[type="email"]`, `input[type="url"]`, `input[type="tel"]`, `input[type="number"]`, and the inputs named `item`, `item_number`, `product_id`, `barcode`, `quantity`, `price`, `discount`, and `amount_tendered`. The sales cart's item-number cell is reached as `[dir="rtl"] #cart_contents tr > td:nth-child(2)`, using the stable `cart_contents` identifier on the table body. The empty-cart row has a single spanning cell, so it never matches.

### Files deliberately not changed

`app/Views/sales/register.php`, `app/Views/sales/quote_email.php`, `app/Views/sales/work_order_email.php`, and `app/Views/barcodes/barcode_sheet.php` are left untouched. Everything needed from the first is achieved by selector. The other three are print and email documents that Phase 4 owns, and the formatting their inclusion would force carries the comparison risk described above.

## Data-model, migration, compatibility, and rollback impact

- No schema migration, no data rewrite, and no change to stored transactions.
- The change is one new stylesheet, two helper functions, one constant, two `dir` attributes, and two build-list entries.
- Reverting is removing the stylesheet from `gulpfile.js` and rebuilding. The `dir` attribute alone leaves the browser's native right-to-left text handling, which is a usable state rather than a broken one.
- `app/Views/partial/header.php` and `app/Views/login.php` were reformatted to satisfy the code-style gate. Every strict-comparison rewrite in them was checked against the database before it was accepted: `ospos_modules.module_id` is `varchar(255)`, and `theme`, `login_form`, and `company_logo` are string columns in `ospos_app_config`, so each rewritten comparison has the same result as before.
- The upstream `<html>` tag is restored by reverting these two files.

## Impact assessment

### Security and privacy

No new input path, no new output path, and no change to escaping. The direction helpers take a language code that originates in the application's own configuration or the employee record and emit one of two fixed strings, so the `dir` attribute cannot carry injected content.

### Performance

One stylesheet is concatenated into the existing bundle. The built production bundle grew from about 114.6 KB to about 117.3 KB, roughly 2.7 KB. No additional request is made, because the file is bundled rather than linked separately. No JavaScript runs.

### Localization

Direction is derived from the language rather than hard-coded, so Kurdish Sorani, Persian, Hebrew, and Urdu receive the same treatment if those locales are ever selected. The English path evaluates to `ltr` and matches no rule in the layer.

### Hardware

Not applicable. Receipt printers and barcode scanners are unaffected, because the receipt and barcode templates are out of scope here.

### Operations

The stylesheet must be built into the bundle, so a deployment that skips `npm run build` or `gulp` will serve an Arabic interface without the direction layer. The `dir` attribute still applies, so the result is readable but not laid out. This is a deployment requirement to record alongside the asset requirement already noted in ADR 0001.

## Test and acceptance criteria

Automated:

- `tests/LocaleHelperTest.php` covers `text_direction()` and `is_right_to_left()` for `ar`, `ar-LB`, `ar-EG`, `ckb`, `fa`, `he`, and `ur` returning `rtl`, for `en`, `en-GB`, `fr`, and `de-DE` returning `ltr`, for regional variants following their base language with either separator, for case being ignored, and for an unknown or empty code falling back to `ltr`.
- `tests/LanguageCoverageTest.php` asserts that both Arabic locales report `rtl`.
- Full suite: 20 tests, 36 assertions, green on PHP 8.2.33 with PHPUnit 11.5.15.

Manual, against a disposable MariaDB 10.5 and PHP 8.2 stack:

- With the employee language set to `ar-LB`, ten application pages returned HTTP 200 and rendered `<html lang="ar-LB" dir="rtl">` with Arabic text.
- With the employee language cleared and the system language set to `ar-EG`, the same pages rendered `<html lang="ar-EG" dir="rtl">` with Arabic text, and the login page did the same.
- With English configured, twelve pages returned HTTP 200, rendered `<html lang="en" dir="ltr">`, contained zero Arabic characters, and contained no `dir="rtl"` anywhere.
- The direction layer is present in the served production bundle.

Not yet verified: the rendered visual layout. The browser automation server available to this session failed to connect, so no screenshots were captured. The evidence above covers the markup, the language, the direction, and the presence of the stylesheet, not the pixels.

## Consequences, risks, and follow-up work

### Positive consequences

- Arabic reads right to left across the application shell without touching Bootstrap or the English layout.
- The declared language now matches what is rendered, which also fixes an accessibility defect that predates this phase.
- Barcodes, SKUs, and amounts stay correct on screen and when copied.
- The layer is a single reviewable file that a later phase can extend.

### Risks

- The visual layout is unverified. Individual screens may need additional rules once someone looks at them.
- The cart's item-number cell is selected by column position. If a future change inserts a column before it, the rule follows the wrong cell. The coverage of this is a positional selector, not a class, because adding the class would require reformatting a file whose reformatting is unsafe.
- Bootstrap 3 mirroring by hand is never complete. Components not exercised during verification may still need rules.
- The login page does not load the application bundle, so it gets the browser's native right-to-left handling and not the layer.
- Charts, report graphs, and icons are not mirrored, which is usually correct but should be confirmed with an Arabic-reading reviewer.
- Skipping the asset build leaves an Arabic interface without the layer.

### Follow-up work

- Visual review of the register, item form, reports, and configuration screens in Arabic, with screenshots.
- ADR 0007 for receipt and barcode direction, which needs its own approach because those templates use inline `style="text-align: right"` that class-based rules cannot override.
- Decide whether the login page warrants its own small direction layer or whether native handling is enough.
- Revisit `app/Views/sales/register.php` if the code-style gate is ever settled for the upstream tree, and move the positional selector to a class.

## Links and evidence

- [Phase 1 gap analysis](../gap-analysis.md), the right-to-left and mixed-direction rows.
- [ADR 0002](0002-native-feature-configuration-and-gap-analysis.md), audit method and phase routing.
- [ADR 0003](0003-arabic-translation-ownership-and-terminology.md), the translations this layer is for.
- [ADR 0001](0001-upstream-release-and-baseline.md), the verified baseline and its deployment requirements.
- `app/Config/Constants.php`, the right-to-left language list.
- `app/Helpers/locale_helper.php`, the direction helpers.
- `public/css/ospos_rtl.css`, the direction layer.
- `gulpfile.js`, the bundle entries.
- Implementation branch: `feat/arabic-localization`.
