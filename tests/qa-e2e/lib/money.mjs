/**
 * Converts a dollar fixture to plain LBP digits for an item form.
 *
 * @param {number|string} dollarAmount Dollar amount to prepare for the form.
 * @param {number} rate Lebanese pounds per dollar in the QA shop.
 * @returns {string} Whole-pound input without grouping separators.
 */
export function dollarsToLbpInput(dollarAmount, rate = 89500) {
    return String(Math.round(Number(dollarAmount) * rate));
}
