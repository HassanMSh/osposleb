# ADR 0012: Make the code-style gate safe, and pin till behaviour with tests

- Status: Accepted
- Date: 2026-09-20
- Related: ADR 0005 (TVA model), ADR 0010 (shop lockdown and simplified till)
- Execution order: This ADR is implemented before ADR 0010 and ADR 0011, despite its higher number. ADR numbers record creation order, not execution order.

## Context

Continuous integration runs PHP-CS-Fixer over every changed PHP file with `.php-cs-fixer.no-header.php`. Its shared CodeIgniter4 rules enable `strict_comparison` and `strict_param`. Both rules are risky because they can change program behaviour, not only appearance.

This matters because MySQL returns `DECIMAL` and `TINYINT` values to PHP as strings. The formatter can therefore change comparisons in sale and receipt code without causing an error.

## Existing OSPOS behavior

`app/Libraries/Sale_lib.php:990` sets a free item's price to the string `'0.00'`. In `app/Controllers/Sales.php:1602`, the receipt filter uses a loose comparison:

```php
} elseif ($item['print_option'] == PRINT_PRICED && $item['price'] != 0)
```

Today, `'0.00' != 0` is false, so a free line is excluded as intended. After strict conversion, `'0.00' !== 0` is true and the free line prints. Similar type-sensitive comparisons exist in sale, tax, item and receipt paths.

Measurements taken on 2026-09-20 against `app/Controllers/Sales.php` and `app/Libraries/Sale_lib.php` found:

| Configuration | New strict comparisons introduced | Total lines reformatted |
| --- | ---: | ---: |
| As shipped today | 137 | 1,524 |
| With `strict_comparison` and `strict_param` disabled | 0 | 1,332 |

The 1,332 remaining lines are cosmetic changes. Separately, work already merged into `develop` introduced a net 162 strict comparisons across 18 PHP files. Two defects of this kind were found during Phase 3 review: a null item description rendered an empty block, and an empty company logo rendered a broken image.

## Decision

Disable only these two risky rules in the fork's `$overrides` array:

```php
'strict_comparison' => false,
'strict_param'      => false,
```

All other CodeIgniter4 rules remain active. Existing strict comparisons are unchanged. Future formatting can tidy appearance without silently changing the meaning of database-backed values.

Add characterisation tests under `tests/` before changing the formatter configuration. They pin the current till behaviour for the ADR 0005 basket, zero-priced receipt filtering, null descriptions, empty logos, English and `ar-LB` tax labels, dollar and pound change, pound rounding, return signs, and the `'0'` price override against stored `'0.00'`.

## Alternatives considered

**Check only pull-request lines.** Rejected because it needs custom tooling, does not address the 162 comparisons already merged, and leaves mixed styles.

**Exclude the sale files from the check.** Rejected because it hides the problem and stops those files being tidied.

**Review every formatter rewrite by hand.** Rejected as a standing policy because it requires checking 137 comparisons whenever these files are reformatted.

**Keep routing changes around the sale files.** Rejected because the fast-food phase must use the sale calculation library.

**Review the 162 existing comparisons in this change.** Out of scope so this change stays small and reversible. That review is a separate future pass, starting with database-backed comparisons in the tax library and item model.

## Consequences and risks

- The fork differs from the shared CodeIgniter standard by two configuration overrides.
- Developers must choose strict comparisons deliberately when the value types support them.
- Large upstream reformatting can still create a noisy, cosmetic diff and should remain separate from behaviour changes.
- Characterisation tests preserve current behaviour, including behaviour that may later be corrected deliberately.
- The formatter gate still checks all other enabled rules.

## Test and rollback approach

Run the full suite after the characterisation tests are added, with the formatter configuration untouched. Then disable the two rules, run the full suite again, and require the same result.

Run PHP-CS-Fixer in dry-run mode over `app/Controllers/Sales.php` and `app/Libraries/Sale_lib.php`; the new strict-comparison count must be zero. Also verify that a genuinely misformatted scratch file is rejected, proving the gate still works. No database migration is needed. Rollback is removing the two configuration entries; the tests remain useful.
