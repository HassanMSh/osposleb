<?php

use App\Libraries\Tax_lib;
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
 * Rounds a pound amount to the nearest 1,000 LL, with 500 LL going away from zero.
 *
 * The amount is first rounded to a whole pound, then integer division applies
 * the thousands rule so floating point noise cannot change a 500 LL boundary.
 *
 * @param float|int|string|null $pound_amount Amount in Lebanese pounds.
 *
 * @return int Amount rounded to thousands.
 */
function round_lbp_to_thousand(float|int|string|null $pound_amount): int
{
    if ($pound_amount === null || ! is_numeric($pound_amount)) {
        return 0;
    }

    $whole_pounds = (int) round((float) $pound_amount, 0, PHP_ROUND_HALF_UP);
    $magnitude    = abs($whole_pounds);
    $rounded      = intdiv($magnitude + 500, 1000) * 1000;

    return $whole_pounds < 0 ? -$rounded : $rounded;
}

/**
 * Rounds a pound amount to a whole pound, with half-pound values away from zero.
 *
 * @param float|int|string|null $pound_amount Amount in Lebanese pounds.
 *
 * @return int Amount rounded to whole pounds.
 */
function round_lbp_to_whole_pound(float|int|string|null $pound_amount): int
{
    if ($pound_amount === null || ! is_numeric($pound_amount)) {
        return 0;
    }

    return (int) round((float) $pound_amount, 0, PHP_ROUND_HALF_UP);
}

/**
 * Returns the exchange rate to save with a completed sale, or null when no usable rate is set.
 *
 * A sale saved with a null rate also saves a null pound total, so reports show it as unknown.
 *
 * @param float|int|string|null $rate Lebanese pounds per dollar.
 *
 * @return float|null Rate above zero, or null.
 */
function lbp_rate_to_save(float|int|string|null $rate): ?float
{
    if ($rate === null || ! is_numeric($rate) || (float) $rate <= 0) {
        return null;
    }

    return (float) $rate;
}

/**
 * Builds display prices for cart lines and the sum of their rounded pound totals.
 *
 * The customer unit includes the discounted line amount and only excluded tax.
 * The separate price unit is the line's editable base price. Zero-quantity lines
 * keep that base price for display and have a zero line total.
 *
 * @param array            $cart       Cart lines keyed by line id.
 * @param array            $item_taxes Per-line tax rows returned by Tax_lib::get_taxes().
 * @param float|int|string $rate       Lebanese pounds per dollar.
 *
 * @return array{lines: array, total: int} Rounded line values and sale total.
 */
function get_lbp_cart_totals(array $cart, array $item_taxes, float|int|string $rate): array
{
    $exchange_rate          = is_numeric($rate) ? (float) $rate : 0.0;
    $excluded_taxes_by_line = [];

    foreach ($item_taxes as $tax) {
        if (($tax['tax_type'] ?? null) !== Tax_lib::TAX_TYPE_EXCLUDED) {
            continue;
        }

        $line                          = (string) ($tax['line'] ?? '');
        $excluded_taxes_by_line[$line] = ($excluded_taxes_by_line[$line] ?? 0.0) + (float) ($tax['item_tax_amount'] ?? 0);
    }

    $lines = [];
    $total = 0;

    foreach ($cart as $line_key => $item) {
        $quantity               = (float) ($item['quantity'] ?? 0);
        $price                  = (float) ($item['price'] ?? 0);
        $discounted_total       = (float) ($item['discounted_total'] ?? 0);
        $excluded_tax_total     = $excluded_taxes_by_line[(string) ($item['line'] ?? $line_key)] ?? 0.0;
        $customer_paid_unit_usd = $quantity == 0.0
            ? $price
            : ($discounted_total + $excluded_tax_total) / $quantity;
        $customer_paid_total_usd = $quantity == 0.0 ? 0.0 : $discounted_total + $excluded_tax_total;
        $price_unit_lbp          = round_lbp_to_thousand($price * $exchange_rate);
        $customer_unit_lbp       = round_lbp_to_thousand($customer_paid_unit_usd * $exchange_rate);
        $line_total_lbp          = $quantity == 0.0
            ? 0
            : (int) round($customer_unit_lbp * $quantity, 0, PHP_ROUND_HALF_UP);

        $lines[$line_key] = [
            'price_unit_lbp'         => $price_unit_lbp,
            'price_unit_usd'         => $price,
            'customer_unit_lbp'      => $customer_unit_lbp,
            'customer_unit_usd'      => $customer_paid_unit_usd,
            'line_total_lbp'         => $line_total_lbp,
            'line_total_usd'         => $customer_paid_total_usd,
            'discounted_total_usd'   => $discounted_total,
            'excluded_tax_total_usd' => $excluded_tax_total,
        ];
        $total += $line_total_lbp;
    }

    return ['lines' => $lines, 'total' => $total];
}

/**
 * Returns a report's pound sum only when every sale in the group has a saved pound total.
 *
 * @param array|null $lbp_row Row with lbp_total (sum) and missing_count (sales without a saved total), or null.
 *
 * @return int|null Pound total, 0 for a group without sales, or null when it is unknown.
 */
function complete_lbp_total(?array $lbp_row): ?int
{
    if ($lbp_row === null || (int) ($lbp_row['missing_count'] ?? 0) > 0) {
        return null;
    }

    return (int) ($lbp_row['lbp_total'] ?? 0);
}

/**
 * Builds the pound figures for a stored sale, such as a reprinted or emailed receipt.
 *
 * A sale saved with its pound total and exchange rate shows that total, and its line figures use the saved rate.
 * A sale without them, such as one completed before they were saved, uses today's rate for everything.
 *
 * @param array                 $cart        Cart lines rebuilt from the stored sale.
 * @param array                 $item_taxes  Per-line tax rows returned by Tax_lib::get_taxes().
 * @param array|null            $sale_info   Sale row with lbp_total and lbp_exchange_rate, or null.
 * @param float|int|string|null $config_rate Current Lebanese pounds per dollar setting.
 *
 * @return array{rate: float|int|string, lbp_totals: array, total: int} Rate used, line values, and the pound total.
 */
function get_stored_sale_lbp_totals(array $cart, array $item_taxes, ?array $sale_info, float|int|string|null $config_rate): array
{
    $saved_rate  = lbp_rate_to_save($sale_info['lbp_exchange_rate'] ?? null);
    $saved_total = $sale_info['lbp_total'] ?? null;
    $has_saved   = $saved_rate !== null && is_numeric($saved_total);
    $rate        = $has_saved ? $saved_rate : ($config_rate ?? 0);
    $lbp_totals  = get_lbp_cart_totals($cart, $item_taxes, $rate);

    return [
        'rate'       => $rate,
        'lbp_totals' => $lbp_totals,
        'total'      => $has_saved ? (int) $saved_total : $lbp_totals['total'],
    ];
}

/**
 * Converts a posted LBP value to a dollar string and preserves an unchanged price.
 *
 * If the posted amount matches the value shown for the stored dollar amount, the
 * stored value is returned as-is. Otherwise, the pounds are divided by the rate
 * and rounded to the configured currency decimals.
 *
 * @param float|int|string      $posted_lbp        Posted amount in pounds.
 * @param float|int|string      $rate              Lebanese pounds per dollar.
 * @param float|int|string|null $stored_dollar     Existing dollar amount, if any.
 * @param bool                  $round_to_thousand Whether the screen uses rule R instead of whole pounds.
 * @param int                   $currency_decimals Number of stored dollar decimals.
 * @param int|null              $shown_lbp         Exact LBP value displayed by a line-total field.
 *
 * @return string Dollar amount for storage.
 */
function lbp_to_dollar_string(float|int|string $posted_lbp, float|int|string $rate, float|int|string|null $stored_dollar = null, bool $round_to_thousand = true, int $currency_decimals = 2, ?int $shown_lbp = null): string
{
    $posted = is_numeric($posted_lbp) ? (float) $posted_lbp : 0.0;
    $rate   = is_numeric($rate) ? (float) $rate : 0.0;

    if ($stored_dollar !== null && is_numeric($stored_dollar) && $rate > 0) {
        $shown = $shown_lbp ?? ($round_to_thousand
            ? round_lbp_to_thousand((float) $stored_dollar * $rate)
            : round_lbp_to_whole_pound((float) $stored_dollar * $rate));

        if ($posted == (float) $shown) {
            return (string) $stored_dollar;
        }
    }

    $dollar = $rate > 0 ? $posted / $rate : 0.0;

    return number_format($dollar, $currency_decimals, '.', '');
}

/**
 * Formats a whole-pound input value without grouping separators.
 *
 * @param float|int|string|null $pound_amount Amount in Lebanese pounds.
 *
 * @return string Plain digits suitable for a numeric form field.
 */
function format_lbp_input(float|int|string|null $pound_amount): string
{
    return (string) round_lbp_to_whole_pound($pound_amount);
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
