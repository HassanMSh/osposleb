# ADR 0003: Arabic translation ownership, terminology, and completeness

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Phase 2 Arabic localization for the approved OSPOS 3.4.1 baseline
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`
- Branch: `feat/arabic-localization`

## Context and current upstream behavior

Phase 1 recorded that the baseline already ships two Arabic locales, `ar-LB` and `ar-EG`, and that `app/Libraries/MY_Language.php::getLine` falls back from a regional locale to its base locale and then to English when a value is missing or empty. The audit also recorded that many Arabic values were empty, so operators saw a mixed Arabic and English interface.

Three further facts were established while implementing this phase and they change what "complete" means.

### The key count that matters

`app/Language/en` holds 1543 string keys. Of those, 116 have an empty English value and a further 9 hold only symbols, digits, or format placeholders. Those 125 keys carry nothing a translator can act on, and an empty Arabic value for them is correct rather than missing. The number of keys that genuinely need Arabic is therefore 1418.

The first coverage measurement taken in Phase 1 counted all 1543 keys and reported `ar-LB` at 92.9% with 109 gaps. That measurement was wrong. Counting only the 1418 translatable keys put the same files at 99.58% with 6 real gaps. The corrected figure was published in [gap-analysis.md](../gap-analysis.md) on commit `9396fefbd`.

### The per-employee language was not applied

`app/Helpers/locale_helper.php::current_language_code()` prefers the logged-in employee's `language_code` and falls back to the system setting. `app/Events/Load_config.php` ignored that helper and called `setLocale()` with `$config->settings['language_code']`, the system value, so an employee who selected Arabic still received the system language. The employee language picker in `app/Views/employees/form.php` and the `language_code` column on the employee record therefore had no effect on translations.

### The Lebanese locale carried Egyptian month names

`app/Language/ar-LB/Calendar.php` shipped the international month names used in Egypt and the Gulf (`يناير`, `فبراير`, `مارس`). Lebanon uses the Levantine names (`كانون الثاني`, `شباط`, `آذار`). The file also spelled Monday `الإتنين`, an Egyptian colloquial form, rather than `الاثنين`.

## Requirements and non-goals

### Requirements

1. Every English key that carries real words has an Arabic value in `ar-LB`.
2. Terminology suits a Lebanese supermarket and fast-food counter, not a generic Modern Standard Arabic glossary.
3. The English interface is byte-for-byte unaffected.
4. An employee who selects Arabic receives Arabic.
5. Coverage is enforced automatically so a future upstream merge cannot silently reintroduce gaps.
6. Product names stay in Latin script.

### Non-goals

- No change to the tax, price, inventory, or receipt semantics that ADRs 0005 and 0007 own.
- No change to the set of supported locales in `app/Config/App.php`.
- No translation of the receipt and barcode templates, which Phase 4 owns.
- No default-language change for production. The deployment language is set per site, not in the repository.

## Options considered

### Option 1: Fill only the operator-facing screens

The register, item entry, and payment screens are what a cashier sees all day, and they were close to complete already. This would have been the smallest change. It was rejected because the remaining gaps sat in configuration and reporting, which is where a Lebanese manager who does not read English needs the most help, and because a partial locale gives no defensible completion criterion.

### Option 2: Replace both Arabic locales with a single reviewed `ar` base locale

Collapsing `ar-LB` and `ar-EG` into one base locale would remove duplicate maintenance and let the existing fallback serve both. It was rejected because it deletes upstream locales that other OSPOS users depend on, it would be rejected upstream, and it discards the regional distinctions this fork exists to get right.

### Option 3: Complete both regional locales and enforce completeness with a test

This keeps upstream's structure, fixes the Lebanese terminology, closes the Egyptian gaps as a side effect, and makes the completion criterion machine-checkable.

## Decision

Choose Option 3.

### Translation ownership

`ar-LB` is the fork's deployment locale and the project owns it. `ar-EG` is upstream's locale; the fork keeps it complete so the base-language fallback is never reached with an English string, and the changes to it are suitable for contribution upstream.

### Terminology rules

- Use the register vocabulary a Lebanese cashier uses, not literal dictionary equivalents.
- Use the Levantine month names. `app/Language/ar-LB/Calendar.php` now reads `كانون الثاني`, `شباط`, `آذار`, `نيسان`, `أيار`, `حزيران`, `تموز`, `آب`, `أيلول`, `تشرين الأول`, `تشرين الثاني`, `كانون الأول`.
- Abbreviate only the four compound month names, as `ك٢`, `ت١`, `ت٢`, `ك١`. The other eight are already short enough to print in full.
- Spell Monday `الاثنين`.
- Keep `Common.software_short` as `OSPOS`. It is a product name, not translatable text.

### Fallback behavior

The upstream chain is unchanged: a regional locale falls back to its base language, and the base language falls back to English. The fork does not rely on it for any key that has English words today. It remains the safety net for keys that upstream adds in a future release, which is the case an empty value is for.

### Per-employee language

`app/Events/Load_config.php` now calls `current_language_code()`, so the locale that is loaded is the employee's language when they have one and the system language otherwise. This restores the behavior the employee form and the `language_code` column always implied.

### Completeness enforcement

`tests/LanguageCoverageTest.php` compares every English key that matches `/[A-Za-z]{2,}/` against both Arabic locales and fails when a value is absent, empty, or still equal to the English string. `Common.software_short` is the single declared exception. The test also asserts that the English source still holds more than 1000 translatable keys, so a broken loader cannot make the check pass by finding nothing to compare.

## Data-model, migration, compatibility, and rollback impact

- No schema migration, no data rewrite, and no change to stored transactions.
- The `language_code` column on the employee record is unchanged; it now takes effect where it previously did not.
- Sites that relied on the system language overriding a per-employee selection will see that employee's language change at the next login. No stored value changes, so reverting the `Load_config` commit restores the previous behavior exactly.
- Language files are plain PHP arrays. Reverting the commit restores the previous strings.
- An upstream merge that adds keys will surface as a failing coverage test rather than as English text in an Arabic interface.

## Impact assessment

### Security and privacy

No secrets, credentials, customer data, or database contents are involved. Translations are static strings and are escaped by the same `esc()` calls as before. No new user input path is introduced.

### Performance

Language files are loaded by the framework exactly as before. The `Load_config` change replaces one array read with one helper call that was already being made twice in the same method, so the request cost is unchanged or slightly lower.

### Localization

`ar-LB` and `ar-EG` both cover all 1418 translatable keys. The measured figure is 1417 of 1418, with the single difference being the deliberately untranslated product name.

### Hardware

Not applicable. No device behavior changes.

### Operations

Site language is chosen in the application's configuration screen or per employee. The settings array is cached, so a language change made directly in the database requires the settings cache to be cleared; a change made through the application clears it through `Config\OSPOS::update_settings()`.

## Test and acceptance criteria

Automated:

- `tests/LanguageCoverageTest.php` passes for `ar-LB` and `ar-EG`.
- `tests/LocaleHelperTest.php` passes for the direction helpers.
- The full suite is 20 tests and 36 assertions, green on PHP 8.2.33 with PHPUnit 11.5.15.

Manual, performed against a disposable MariaDB 10.5 and PHP 8.2 stack:

- With the employee language set to `ar-LB`, twelve application pages returned HTTP 200 and rendered Arabic, with between 499 and 6110 Arabic characters per page.
- With the employee language cleared and the system language set to `ar-EG`, the same pages rendered Arabic, which confirms the fallback to the system language.
- With both set to English, the same twelve pages returned HTTP 200 and contained zero Arabic characters.
- The login page follows the system language, because no employee is logged in yet.

## Consequences, risks, and follow-up work

### Positive consequences

- A Lebanese operator can run the whole application in Arabic with no English text.
- The per-employee language picker works, so one terminal can serve an Arabic-reading cashier and an English-reading manager.
- Coverage is a build-time fact rather than a claim in a document.
- The `ar-EG` fixes are self-contained and can be offered upstream.

### Risks

- Levantine month names are a judgement call. Some Lebanese users are equally used to the international names. The change is confined to `app/Language/ar-LB/Calendar.php` and can be reverted on its own without touching anything else.
- The coverage test treats any English-identical value as a gap. A future key whose correct Arabic really is the Latin string would need to be added to the exception list in the test.
- Making the per-employee language effective is a visible behavior change for any site that had employees with a language set and did not expect it to apply.
- Translation quality beyond completeness is not machine-checkable. A native reviewer should walk the register and configuration screens before go-live.

### Follow-up work

- ADR 0004 covers the writing direction and mixed-direction rules that go with these translations.
- ADR 0007 covers receipt and barcode templates, which remain untranslated and unstyled for Arabic.
- A native-speaker review of register and configuration terminology before go-live.
- Optional upstream pull request for the `ar-EG` calendar and login additions.

## Links and evidence

- [Phase 1 gap analysis](../gap-analysis.md), Arabic coverage rows and the corrected measurement.
- [ADR 0002](0002-native-feature-configuration-and-gap-analysis.md), audit method and phase routing.
- [ADR 0004](0004-rtl-and-mixed-direction.md), direction and layout decisions.
- `app/Helpers/locale_helper.php`, language-code resolution.
- `app/Events/Load_config.php`, locale selection at request start.
- `app/Libraries/MY_Language.php`, regional to base to English fallback.
- `tests/LanguageCoverageTest.php`, the enforced completeness check.
- Implementation branch: `feat/arabic-localization`.
