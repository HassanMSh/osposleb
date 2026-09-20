<?php
/**
 * @var string $transaction_time
 * @var int    $sale_id
 * @var string $invoice_number
 * @var string $employee
 * @var array  $cart
 * @var float  $discount
 * @var float  $prediscount_subtotal
 * @var float  $subtotal
 * @var array  $taxes
 * @var float  $total
 * @var array  $payments
 * @var float  $amount_change
 * @var string $barcode
 * @var array  $config
 */
?>

<div id="receipt_wrapper" style="font-size: <?= $config['receipt_font_size'] ?>px;">
    <div id="receipt_header">
        <?php if ((string) $config['company_logo'] !== '') { ?>
            <div id="company_name">
                <img id="image" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="company_logo">
            </div>
        <?php } ?>

        <?php if ($config['receipt_show_company_name']) { ?>
            <div id="company_name"><?= $config['company'] ?></div>
        <?php } ?>

        <div id="company_address"><?= nl2br(esc($config['address'])) ?></div>
        <div id="company_phone"><?= esc($config['phone']) ?></div>
        <div id="sale_receipt"><?= lang('Sales.receipt') ?></div>
        <div id="sale_time"><?= ($transaction_time) ?></div>
    </div>

    <div id="receipt_general_info">
        <?php if (isset($customer)) { ?>
            <div id="customer"><?= lang('Customers.customer') . esc(": {$customer}") ?></div>
        <?php } ?>

        <div id="sale_id"><?= lang('Sales.id') . esc(": {$sale_id}") ?></div>

        <?php if (! empty($invoice_number)) { ?>
            <div id="invoice_number"><?= lang('Sales.invoice_number') . esc(": {$invoice_number}") ?></div>
        <?php } ?>

        <div id="employee"><?= lang('Employees.employee') . esc(": {$employee}") ?></div>
    </div>

    <table id="receipt_items">
        <tr>
            <th style="width: 40%;"><?= lang('Sales.description_abbrv') ?></th>
            <th style="width: 20%;"><?= lang('Sales.price') ?></th>
            <th style="width: 20%;"><?= lang('Sales.quantity') ?></th>
            <th style="width: 20%;" class="total-value"><?= lang('Sales.total') ?></th>
        </tr>
        <?php
        foreach ($cart as $line => $item) {
            if ((int) $item['print_option'] === PRINT_YES) {
                $tax_marker = format_receipt_tax_marker($item['taxed_flag'] ?? null);
                ?>
                <tr>
                    <td><?= esc(ucfirst($item['name'] . ' ' . $item['attribute_values'])) ?></td>
                    <td><?= to_currency($item['price']) ?></td>
                    <td><?= to_quantity_decimals($item['quantity']) ?></td>
                    <td class="total-value"><span dir="ltr"><?= to_currency($item[($config['receipt_show_total_discount'] ? 'total' : 'discounted_total')]) ?></span><?= $tax_marker ?></td>
                </tr>
                <tr>
                    <?php if ($config['receipt_show_description']) { ?>
                        <td colspan="2"><?= esc($item['description']) ?></td>
                    <?php } ?>

                    <?php if ($config['receipt_show_serialnumber']) { ?>
                        <td><?= esc($item['serialnumber']) ?></td>
                    <?php } ?>
                </tr>
                <?php if ($item['discount'] > 0) { ?>
                    <tr>
                        <?php if ((int) $item['discount_type'] === FIXED) { ?>
                            <td colspan="3" class="discount"><?= to_currency($item['discount']) . ' ' . lang('Sales.discount') ?></td>
                        <?php } elseif ((int) $item['discount_type'] === PERCENT) { ?>
                            <td colspan="3" class="discount"><?= to_decimals($item['discount']) . ' ' . lang('Sales.discount_included') ?></td>
                        <?php } ?>
                        <td class="total-value"><?= to_currency($item['discounted_total']) ?></td>
                    </tr>
        <?php
                }
            }
        }
?>

        <?php if ($config['receipt_show_total_discount'] && $discount > 0) { ?>
            <tr>
                <td colspan="3" style="text-align: right; border-top: 2px solid #000000;"><?= lang('Sales.sub_total') ?></td>
                <td style="text-align: right; border-top:2px solid #000000;"><?= to_currency($prediscount_subtotal) ?></td>
            </tr>
            <tr>
                <td colspan="3" class="total-value"><?= lang('Sales.customer_discount') ?>:</td>
                <td class="total-value"><?= to_currency($discount * -1) ?></td>
            </tr>
        <?php } ?>

        <?php if ($config['receipt_show_taxes']) { ?>
            <tr>
                <td colspan="3" style="text-align: right; border-top: 2px solid #000000;"><?= lang('Sales.sub_total') ?></td>
                <td style="text-align: right; border-top: 2px solid #000000;"><?= to_currency($subtotal) ?></td>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr>
                    <?php $tax_label = format_tax_group_label($tax['tax_group']); ?>
                    <td colspan="3" class="total-value"><?= (float) $tax['tax_rate'] . '% ' . esc($tax_label) ?>:</td>
                    <td class="total-value"><?= to_currency_tax($tax['sale_tax_amount']) ?></td>
                </tr>
        <?php
            }
        }
?>

        <tr></tr>

        <?php $border = (! $config['receipt_show_taxes'] && ! ($config['receipt_show_total_discount'] && $discount > 0)); ?>
        <tr>
            <td colspan="3" style="text-align: right;<?= $border ? ' border-top: 2px solid black;' : '' ?>"><?= lang('Sales.total_to_pay') ?></td>
            <td style="text-align: right;<?= $border ? ' border-top: 2px solid black;' : '' ?>"><span dir="ltr"><?= to_currency($total) ?></span></td>
        </tr>
        <tr>
            <td colspan="3"></td>
            <td style="text-align: right;"><span dir="ltr"><?= esc(format_lbp(to_lbp($total))) ?></span></td>
        </tr>

        <?php foreach ($taxes as $tax) { ?>
            <?php if ((float) $tax['tax_rate'] > 0 && (float) $tax['sale_tax_amount'] > 0) { ?>
                <tr>
                    <td colspan="3" class="total-value">
                        <?= esc(lang('Sales.vat_included_prefix')) ?>
                        <span dir="ltr"><?= (float) $tax['tax_rate'] ?>%</span>
                        <?= esc(lang('Sales.vat_included_suffix')) ?>
                    </td>
                    <td class="total-value"><span dir="ltr"><?= to_currency($tax['sale_tax_amount']) ?></span></td>
                </tr>
            <?php } ?>
        <?php } ?>

        <tr>
            <td colspan="4">&nbsp;</td>
        </tr>

        <?php
$only_sale_check         = false;
$show_giftcard_remainder = false;

foreach ($payments as $payment_id => $payment) {
    $only_sale_check |= $payment['payment_type'] === lang('Sales.check');
    $splitpayment = explode(':', $payment['payment_type']);    // TODO: The variable splitpayment does not follow naming conventions for this project
    $show_giftcard_remainder |= $splitpayment[0] === lang('Sales.giftcard');
    ?>
            <tr>
                <td colspan="3" style="text-align: right;"><?= $splitpayment[0] ?> </td>
                <td class="total-value"><?= to_currency($payment['payment_amount'] * -1) ?></td>
            </tr>
        <?php } ?>

        <tr>
            <td colspan="4">&nbsp;</td>
        </tr>

        <?php if (isset($cur_giftcard_value) && $show_giftcard_remainder) { ?>
            <tr>
                <td colspan="3" style="text-align: right;"><?= lang('Sales.giftcard_balance') ?></td>
                <td class="total-value"><?= to_currency($cur_giftcard_value) ?></td>
            </tr>
        <?php } ?>
        <tr>
            <td colspan="3" style="text-align: right;"> <?= lang($amount_change >= 0 ? ($only_sale_check ? 'Sales.check_balance' : 'Sales.change_due') : 'Sales.amount_due') ?> </td>
            <td class="total-value"><?= to_currency($amount_change) ?></td>
        </tr>
        <tr>
            <td colspan="4">&nbsp;</td>
        </tr>
        <tr>
            <td colspan="4" style="text-align: right;"><?= esc(lang('Sales.lbp_rate')) ?> <span dir="ltr"><?= esc(format_lbp($config['lbp_exchange_rate'])) ?></span></td>
        </tr>
    </table>

    <div id="sale_return_policy">
        <?= nl2br($config['return_policy']) ?>
    </div>

    <div id="barcode">
        <?= $barcode ?><br>
        <?= $sale_id ?>
    </div>
</div>
