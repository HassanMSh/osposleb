<?php

use Config\OSPOS;

/**
 * Converts a dollar amount to Lebanese pounds, rounded to a whole pound.
 *
 * The returned amount is for display only. It must not be stored as a sale,
 * payment, report, or cash-drawer value.
 *
 * @param float|int|string|null $dollar_amount Dollar amount to convert.
 *
 * @return int Lebanese pound amount.
 */
function to_lbp(float|int|string|null $dollar_amount): int
{
    if ($dollar_amount === null || ! is_numeric($dollar_amount)) {
        return 0;
    }

    $config = config(OSPOS::class)->settings;
    $rate   = (float) ($config['lbp_exchange_rate'] ?? 0);

    return (int) round((float) $dollar_amount * $rate, 0, PHP_ROUND_HALF_UP);
}

/**
 * Formats a Lebanese pound amount with grouping and the LL marker.
 *
 * @param float|int|string|null $pound_amount Lebanese pound amount to format.
 *
 * @return string Formatted Lebanese pound amount.
 */
function format_lbp(float|int|string|null $pound_amount): string
{
    return number_format((float) $pound_amount, 0, '.', ',') . ' LL';
}

/**
 * Converts the cart's existing tax marker to the receipt asterisk.
 *
 * @param string|null $taxed_flag Tax marker assigned while the cart is taxed.
 *
 * @return string Asterisk for a taxed line, or an empty string for an untaxed line.
 */
function format_receipt_tax_marker(?string $taxed_flag): string
{
    return trim((string) $taxed_flag) === '' ? '' : '*';
}

/**
 * Returns the label to display for a tax group.
 *
 * Translates the internal exemption reasons recorded on a sale and passes a
 * shop-defined tax name through unchanged.
 *
 * @param string|null $tax_group Tax group name held on the sale.
 *
 * @return string Label to display.
 */
function format_tax_group_label(?string $tax_group): string
{
    return match ($tax_group) {
        'exempt'     => lang('Items.tax_reason_exempt'),
        'zero-rated' => lang('Items.tax_reason_zero_rated'),
        default      => (string) $tax_group,
    };
}
