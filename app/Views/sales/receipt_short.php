<?php
/**
 * @var string $transaction_time
 * @var string $sale_id
 * @var int    $sale_id_num
 * @var string $employee
 * @var float  $discount
 * @var array  $cart
 * @var float  $subtotal
 * @var array  $taxes
 * @var float  $total
 * @var int    $lbp_total
 * @var float  $lbp_rate  Exchange rate used for the pound figures.
 * @var array  $payments
 * @var float  $amount_change
 * @var string $barcode
 * @var array  $config
 */

use App\Libraries\Till_layout;

?>

<div id="receipt_wrapper" style="font-size: <?= esc($config['receipt_font_size']) ?>px;">
    <div id="receipt_header">
        <?php if ((string) $config['company_logo'] !== '') { ?>
            <div id="company_name">
                <img id="image" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="company_logo">
            </div>
        <?php } ?>

        <?php if ($config['receipt_show_company_name']) { ?>
            <div id="company_name"><?= esc($config['company']) ?></div>
        <?php } ?>

        <div id="company_address"><?= nl2br(esc($config['address'])) ?></div>
        <div id="company_phone"><?= esc($config['phone']) ?></div>
        <div id="sale_receipt"><?= lang('Sales.receipt') ?></div>
        <div id="sale_time"><?= esc($transaction_time) ?></div>
    </div>

    <div id="receipt_general_info">
        <?php if (isset($customer)) { ?>
            <div id="customer"><?= lang('Customers.customer') . esc(": {$customer}") ?></div>
        <?php } ?>

        <div id="sale_id"><?= lang('Sales.id') . esc(": {$sale_id_num}") ?></div>

        <?php if (! empty($invoice_number)) { ?>
            <div id="invoice_number"><?= lang('Sales.invoice_number') . esc(": {$invoice_number}") ?></div>
        <?php } ?>

        <div id="employee"><?= lang('Employees.employee') . esc(": {$employee}") ?></div>
    </div>

    <table id="receipt_items">
        <tr>
            <th style="width:50%;"><?= lang('Sales.description_abbrv') ?></th>
            <th style="width:25%;"><?= lang('Sales.quantity') ?></th>
            <th style="width:25%;" class="total-value"><?= lang('Sales.total') ?></th>
        </tr>
        <?php foreach ($cart as $line => $item) { ?>
            <?php $is_addon   = Till_layout::is_addon_category($item['category'] ?? null, $config); ?>
            <?php $tax_marker = format_receipt_tax_marker($item['taxed_flag'] ?? null); ?>
            <tr<?= $is_addon ? ' class="restaurant-addon-line"' : '' ?>>
                <td><?= $is_addon ? '<bdi dir="auto">+ ' . esc(ucfirst($item['name'] . ' ' . $item['attribute_values'])) . '</bdi>' : esc(ucfirst($item['name'] . ' ' . $item['attribute_values'])) ?></td>
                <td><span dir="ltr"><?= to_quantity_decimals($item['quantity']) ?> x <?= to_currency($item['price']) ?></span></td>
                <td class="total-value"><span dir="ltr"><?= to_currency($item[($config['receipt_show_total_discount'] ? 'total' : 'discounted_total')]) ?></span><?= $tax_marker ?></td>
            </tr>
            <?php if (($config['receipt_show_description'] && ! empty($item['description'])) || ($config['receipt_show_serialnumber'] && ! empty($item['serialnumber']))) { ?>
                <tr>
                    <?php if ($config['receipt_show_description'] && ! empty($item['description'])) { ?>
                        <td colspan="2"><?= esc($item['description']) ?></td>
                    <?php } ?>
                    <?php if ($config['receipt_show_serialnumber'] && ! empty($item['serialnumber'])) { ?>
                        <td><?= esc($item['serialnumber']) ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            <?php if ($item['discount'] > 0) { ?>
                <tr>
                    <?php if ((int) $item['discount_type'] === FIXED) { ?>
                        <td colspan="2" class="discount"><?= to_currency($item['discount']) . ' ' . lang('Sales.discount') ?></td>
                    <?php } elseif ((int) $item['discount_type'] === PERCENT) { ?>
                        <td colspan="2" class="discount"><?= to_decimals($item['discount']) . ' ' . lang('Sales.discount_included') ?></td>
                    <?php } ?>
                    <td class="total-value"><?= to_currency($item['discounted_total']) ?></td>
                </tr>
        <?php
            }
        }
?>

        <?php if ($config['receipt_show_total_discount'] && $discount > 0) { ?>
            <tr>
                <td colspan="2" class="receipt-label receipt-border-top"><?= lang('Sales.sub_total') ?></td>
                <td class="total-value receipt-border-top"><span dir="ltr"><?= to_currency($subtotal) ?></span></td>
            </tr>
            <tr>
                <td colspan="2" class="receipt-label"><?= lang('Sales.discount') ?>:</td>
                <td class="total-value"><span dir="ltr"><?= to_currency($discount * -1) ?></span></td>
            </tr>
        <?php } ?>

        <?php if ($config['receipt_show_taxes']) { ?>
            <tr>
                <td colspan="2" class="receipt-label receipt-border-top"><?= lang('Sales.sub_total') ?></td>
                <td class="total-value receipt-border-top"><span dir="ltr"><?= to_currency($subtotal) ?></span></td>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr>
                    <?php $tax_label = format_tax_group_label($tax['tax_group']); ?>
                    <td colspan="2" class="total-value"><?= (float) $tax['tax_rate'] . '% ' . esc($tax_label) ?>:</td>
                    <td class="total-value"><span dir="ltr"><?= to_currency_tax($tax['sale_tax_amount']) ?></span></td>
                </tr>
        <?php
            }
        }
?>

        <?php $border = (! $config['receipt_show_taxes'] && ! ($config['receipt_show_total_discount'] && $discount > 0)); ?>
        <tr>
            <td colspan="2" class="receipt-label<?= $border ? ' receipt-border-top' : '' ?>"><?= lang('Sales.total_to_pay') ?></td>
            <td class="total-value<?= $border ? ' receipt-border-top' : '' ?>"><span dir="ltr"><?= to_currency($total) ?></span></td>
        </tr>
        <tr>
            <td colspan="2"></td>
            <td class="total-value"><span dir="ltr"><?= esc(format_lbp($lbp_total)) ?></span></td>
        </tr>

        <?php foreach ($taxes as $tax) { ?>
            <?php if ((float) $tax['tax_rate'] > 0 && (float) $tax['sale_tax_amount'] > 0) { ?>
                <tr>
                    <?php $vat_label_parts = explode('{0}', lang('Sales.vat_included'), 2); ?>
                    <td colspan="2" class="total-value">
                        <?= esc($vat_label_parts[0]) ?><span dir="ltr"><?= (float) $tax['tax_rate'] ?>%</span><?= esc($vat_label_parts[1] ?? '') ?>
                    </td>
                    <td class="total-value"><span dir="ltr"><?= to_currency($tax['sale_tax_amount']) ?></span></td>
                </tr>
            <?php } ?>
        <?php } ?>

        <?php
$only_sale_check         = false;
$show_giftcard_remainder = false;
$show_payment_rows       = count($payments) !== 1 || $amount_change < 0;

foreach ($payments as $payment) {
    $only_sale_check |= $payment['payment_type'] === lang('Sales.check');
    $splitpayment = explode(':', $payment['payment_type']);
    $show_giftcard_remainder |= $splitpayment[0] === lang('Sales.giftcard');

    if ($show_payment_rows) {
        ?>
            <tr>
                <td colspan="2" class="receipt-label"><?= $splitpayment[0] ?> </td>
                <td class="total-value"><span dir="ltr"><?= to_currency($payment['payment_amount'] * -1) ?></span></td>
            </tr>
        <?php
    }
}
?>

        <?php if (isset($cur_giftcard_value) && $show_giftcard_remainder) { ?>
            <tr>
                <td colspan="2" class="receipt-label"><?= lang('Sales.giftcard_balance') ?></td>
                <td class="total-value"><span dir="ltr"><?= to_currency($cur_giftcard_value) ?></span></td>
            </tr>
        <?php } ?>
        <?php if ($amount_change > 0) { ?>
            <tr>
                <td colspan="2" class="receipt-label"><?= esc(lang($only_sale_check ? 'Sales.check_balance' : 'Sales.change_due')) ?></td>
                <td class="total-value"><span dir="ltr"><?= to_currency($amount_change) ?></span></td>
            </tr>
        <?php } elseif ($amount_change < 0) { ?>
            <tr>
                <td colspan="2" class="receipt-label"><?= lang('Sales.amount_due') ?></td>
                <td class="total-value"><span dir="ltr"><?= to_currency($amount_change) ?></span></td>
            </tr>
        <?php } ?>
        <tr>
            <td colspan="3" class="receipt-label"><?= esc(lang('Sales.lbp_rate')) ?> <span dir="ltr"><?= esc(format_lbp($lbp_rate ?? $config['lbp_exchange_rate'])) ?></span></td>
        </tr>
    </table>

    <?php $return_policy = trim(strip_tags((string) $config['return_policy'])); ?>
    <?php if (preg_match('/[\p{L}\p{N}]/u', $return_policy)) { ?>
        <div id="sale_return_policy">
            <?= nl2br(esc($config['return_policy'])) ?>
        </div>
    <?php } ?>

    <div id="barcode">
        <?= $barcode ?><br>
        <?= $sale_id ?>
    </div>
</div>
