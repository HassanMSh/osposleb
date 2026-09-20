# ADR 0005: TVA model and business semantics

- Status: Proposed
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Phase 3 TVA model for the approved OSPOS 3.4.1 baseline
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`
- Branch: `feat/tva-model`

## Context and current upstream behavior

Phase 1 recorded that OSPOS carries two separate tax paths and that the project had not yet chosen its rules. The project owner has now stated the intended behaviour:

1. Prices include TVA, but an item can be sold tax-exclusive.
2. A global rate applies to every item.
3. An item can opt out, or carry its own rate.
4. The opt-out is an on/off switch on the item.

Reading the code shows that two of these do not match how OSPOS works today. The mismatches are the substance of this decision.

### The two tax paths

`use_destination_based_tax` selects between them.

When it is off, `app/Libraries/Tax_lib.php::get_taxes()` reads `items_taxes` rows for each item. That table is `(item_id, name, percent)` and holds up to two named rates per item. This is the simple path and is the one this project should use, because there is no jurisdiction problem to solve in a single Lebanese shop.

When it is on, tax comes from tax codes, categories, jurisdictions and rates, matched against the customer's city and state. It is built for United States destination sales tax. It is heavier than this project needs.

### Tax inclusion is global only

`tax_included` is a single application setting. It is read in eleven places across `Sale.php`, `Sale_lib.php`, `Tax_lib.php` and three report models. There is no column on the item and no per-item flag anywhere. Requirement 1 therefore has no native support for its second half.

### The global rate does not apply to every item

`default_tax_1_rate` and `default_tax_2_rate` look like a global rate, but they are not. `app/Controllers/Items.php` loads them only when `$item_id === NEW_ENTRY`, and `app/Views/items/form.php` uses them only to pre-fill an empty field on the new-item form. When the item is saved, the value is copied into `items_taxes` as that item's own rate.

The consequence is that the setting is a *default for new items*, not a rate that applies to existing ones. Changing it later changes nothing for any item already in the catalogue. If the Lebanese TVA rate ever moves from 11 percent, every item would have to be edited. Requirement 2 therefore has no native support at all.

### Opting out today means deleting rows

`Item_taxes::save_value()` deletes all rows for the item and re-inserts whatever the form sent. An item with no rows is untaxed. So opting out is possible, but it is expressed by clearing a rate field rather than by a switch, and an item with no rows is indistinguishable from an item whose rate was never set. Requirement 4 has no native support.

### What rounding means here, and what the code does now

TVA is a percentage, so the amount almost never lands on a whole number of cents. Eleven percent of a 4.50 line is 0.495. The receipt has to print one number. Rounding is the rule that picks it, and it has two separate parts.

**Where the rounding happens.** Either each line is rounded and the rounded line amounts are added up, or the lines are added up in full precision and the total is rounded once. These give different answers. On a fourteen-item basket totalling 149.80 at 11 percent inclusive, rounding per line gives 14.81 and rounding once gives 14.85. Four cents. The receipt must add up, and a tax authority comparing declared TVA against declared turnover will use one of these methods.

**Which way a tie goes.** When the amount lands exactly halfway, such as 0.495, the rule decides between 0.49 and 0.50. `app/Models/Enums/Rounding_mode.php` offers half up, half down, half even, half odd, always up, always down, and nearest five.

What the code does today, on the simple path:

- Tax-exclusive: each line is rounded to `tax_decimals` with half up, then the lines are summed, then `round_taxes()` rounds the sum again to `currency_decimals`. The line amounts are rounded twice.
- Tax-inclusive: the line amount is computed as `total - total / (1 + rate)` and is **not** rounded at all. `get_included_tax()` accepts a decimals argument and a rounding argument and uses neither; upstream marks this with a `TODO`. The accumulated total is then rounded once to `tax_decimals`.

So on the path this project will use, OSPOS already rounds once at the end, which is the better of the two methods. The rounding mode on this path is hard-coded to half up and is not configurable.

### Currency and decimals

`totals_decimals()` returns `currency_decimals` and `tax_decimals()` returns `tax_decimals`; both default to 0 when unset. A shop pricing in dollars needs 2. A shop pricing in Lebanese pounds needs 0, and its smallest practical note is far larger than one pound, which is a separate cash-rounding question this ADR does not settle.

## Requirements and non-goals

### Requirements

1. Prices include TVA by default.
2. An item can be sold tax-exclusive.
3. One global rate applies to every item that has not been given its own.
4. An item can carry its own rate.
5. An item can be switched to no TVA.
6. Changing the global rate must not alter the TVA recorded on sales that already happened.
7. Receipts, reports and returns must agree with the register to the cent.

### Non-goals

- No move to the destination-based tax model.
- No jurisdiction, city or state tax matching.
- No cash-rounding rule for Lebanese pound notes. That belongs with Phase 4 receipts or Phase 6 operations.
- No change to how discounts are applied, only to how tax is computed on the discounted amount.
- No retroactive recalculation of historical sales.

## Options considered

### Option 1: Accept upstream behaviour and treat the setting as a new-item default

Set `tax_included` on, set the default rate to 11 percent, and let every item carry its own copied rate. Nothing is built.

This satisfies requirements 1, 4 and 5 and fails 2 and 3. Its real cost appears on the day the rate changes or the day someone adds an item and does not notice the pre-filled field. It also leaves "no rate" ambiguous between "not taxed" and "nobody filled it in".

### Option 2: Live inheritance with a per-item switch

Give the item a tax mode that is one of three states, and resolve the rate at calculation time rather than at item-creation time:

- **Inherit** — no rate stored on the item; the global rate applies. This is the default for every item.
- **Own rate** — a rate stored on the item overrides the global one.
- **No TVA** — the switch is off; no tax is charged and the line is marked as exempt.

The global rate becomes a real global rate. Changing it changes future sales for every inheriting item and touches nothing already sold, because sales store their own tax rows.

### Option 3: Move to the destination-based tax-category model

Model the rate as a tax category and assign categories to items. This is the path upstream is investing in, so it would drift less over time. It is also considerably more machinery — codes, categories, jurisdictions, rates and a customer address — for a single shop with one rate.

## Decision

**Proposed: Option 2**, with the specifics below. This section is a proposal and needs the project owner's confirmation before any code is written, because it changes stored data and tax calculation.

### Rate resolution

At calculation time, for each line, in order:

1. If the item's tax switch is off, no TVA. The line prints as exempt.
2. Otherwise, if the item has its own rate, use it.
3. Otherwise, use the global rate.

### Inclusion

`tax_included` stays the global default and is set on. Requirement 2, selling a specific item tax-exclusive, needs a per-item inclusion flag that does not exist today. This is the part of the request with the largest blast radius: `tax_included` is read in eleven places, including three report models, and making it per-line means every one of those has to handle a mixed basket.

**Open question for the owner.** Is requirement 2 actually needed for this shop, or does "can be excluded per item" mean "an item can be sold without TVA", which requirement 5 already covers? These are different things. A mixed inclusive-and-exclusive basket is unusual in retail and is the single most expensive item in this phase.

### Exempt versus zero-rated

Both mean the customer pays no TVA, but they are reported differently. An exempt item is outside TVA. A zero-rated item is inside TVA at 0 percent, which matters for reclaiming input TVA and typically applies to exports.

This ADR proposes treating the off switch as **exempt**, and recording it as such on the sale so the TVA report can separate exempt turnover from taxed turnover. Zero-rating can be added later as a second switch state if the shop starts exporting.

### Rounding

**Accepted by the project owner on 2026-09-20.** Compute each line at full precision, sum the lines per tax rate, and round once, half up, to the currency's decimal places.

This is what the tax-inclusive path already does, so it is the smallest change. Half up is the ordinary commercial convention and the one a Lebanese accountant will expect. Rounding once rather than per line keeps the printed TVA consistent with the printed total, which is what an auditor checks first.

The rounding mode stays fixed rather than becoming a setting. A configurable rounding mode invites a shop to change it halfway through a tax period and produce two incompatible sets of figures.

### Historical safety

Sales already store their own tax rows in `sales_taxes` and `sales_items_taxes`, including the rate and the rounding code that was used. Nothing in this proposal reads the item's current rate when displaying or reporting a past sale, so requirement 6 holds without extra work. This will be proven with a test, not assumed.

## Data-model, migration, compatibility, and rollback impact

This section describes the proposal, not work that has been done.

- A migration adds one column to `ospos_items` to hold the tax switch. Existing rows default to the switch being on, which preserves current behaviour.
- Items that already carry rows in `items_taxes` keep them, so they continue to use their own rate and nothing changes for them on day one.
- The global rate only reaches items that have no rows, which today is the set of items somebody deliberately cleared. **This is the migration's one real risk**: an item that was deliberately untaxed by clearing its rate would start inheriting the global rate. The migration must therefore switch off the tax flag for every item that currently has no `items_taxes` rows, so today's untaxed items stay untaxed.
- No change to `sales`, `sales_items`, `sales_taxes` or `sales_items_taxes`. Historical data is read-only here.
- Rollback is the down migration plus reverting the calculation change. Because no historical row is rewritten, a rollback restores previous behaviour exactly. A database backup is still required before running the migration, per ADR 0002.

## Impact assessment

### Security and privacy

No new input path beyond one item-form field, which is validated as a boolean and a decimal and stored with the existing parameterised queries. No customer data is involved.

### Performance

Rate resolution adds one array lookup per line against a setting that is already cached in memory. Removing the per-line rounding on the tax-exclusive path removes work rather than adding it.

### Localization

The item form gains one label and one help string, which must be added to every `app/Language/*` variant with real Arabic per ADR 0003. The coverage test will fail until Arabic is supplied, which is the intended behaviour.

### Hardware

None directly. Receipt layout for exempt lines is Phase 4 and ADR 0007.

### Operations

The global rate becomes operationally meaningful: changing it changes every inheriting item's tax from that moment. That is the point of the change, and it must be documented so nobody edits it casually mid-period.

## Test and acceptance criteria

To be written with the implementation. The suite must cover:

- An inheriting item picks up the global rate, and picks up a changed global rate.
- An item with its own rate ignores the global rate.
- An item with the switch off is charged no TVA and is reported as exempt.
- Tax-inclusive line maths: a known price and rate produce a known net and TVA.
- Rounding: the fourteen-item basket above produces 14.85, not 14.81.
- A sale recorded before a rate change still reports its original TVA afterwards.
- Returns, voids and discounts produce the TVA the original sale did.
- Receipt totals equal the register totals equal the report totals, to the cent.

## Consequences, risks, and follow-up work

### Positive consequences

- The global rate behaves the way the owner expects, so a rate change is one edit rather than a catalogue-wide job.
- "Untaxed" becomes an explicit, visible state instead of an empty field.
- Rounding is decided once, in writing, and is testable.

### Risks

- The migration must correctly identify today's deliberately untaxed items. Getting this wrong silently starts charging TVA on them. This is the highest risk in the phase and needs a rehearsal against a copy of real data.
- Per-item tax inclusion, if it is really required, touches eleven call sites including reports, and is where this phase could grow well beyond its estimate.
- Exempt versus zero-rated affects what the TVA report can prove. Choosing exempt now and needing zero-rated later means a second migration.
- Removing the per-line rounding on the tax-exclusive path changes totals by a cent or two compared with today. This matters only if the shop has already been running tax-exclusive, which it has not.

### Follow-up work

- Cash rounding to the smallest available Lebanese pound note, if the shop takes cash in pounds.
- Whether prices are held in dollars or pounds, and what `currency_decimals` should be.
- ADR 0007 for how an exempt line prints on an Arabic receipt.

## Open questions for the project owner

1. **Per-item tax inclusion.** Under review by the owner; both options were costed on 2026-09-20. Does an item genuinely need to be sold tax-exclusive while others are tax-inclusive in the same basket, or is "no TVA on this item" enough?
3. **Exempt or zero-rated.** Is the off switch "outside TVA" (exempt), or "inside TVA at 0 percent" (zero-rated)?
4. **Currency.** Are prices held in dollars or Lebanese pounds, and how many decimal places?

## Links and evidence

- [Phase 1 gap analysis](../gap-analysis.md), the tax rows and the open TVA questions.
- [ADR 0002](0002-native-feature-configuration-and-gap-analysis.md), audit method and phase routing.
- `app/Libraries/Tax_lib.php`, `get_taxes()`, `get_included_tax()`, `get_tax_for_amount()` and `round_taxes()`.
- `app/Models/Enums/Rounding_mode.php`, the available rounding modes.
- `app/Models/Item_taxes.php`, per-item rate storage.
- `app/Controllers/Items.php` and `app/Views/items/form.php`, where the default rate is applied to new items only.
- `app/Views/configs/tax_config.php`, the tax settings screen.
- Implementation branch: `feat/tva-model`.
