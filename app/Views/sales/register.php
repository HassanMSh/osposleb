<?php
/**
 * @var string    $controller_name
 * @var array     $modes
 * @var array     $mode
 * @var array     $empty_tables
 * @var array     $selected_table
 * @var array     $stock_locations
 * @var array     $stock_location
 * @var array     $cart
 * @var bool      $items_module_allowed
 * @var bool      $change_price
 * @var float|int $item_count
 * @var float|int $total_units
 * @var float     $subtotal
 * @var array     $taxes
 * @var float     $total
 * @var float     $payments_total
 * @var float     $amount_due
 * @var bool      $payments_cover_total
 * @var bool      $pos_mode
 * @var array     $payments
 * @var string    $mode_label
 * @var string    $comment
 * @var bool      $print_after_sale
 * @var string    $invoice_number
 * @var int       $cash_mode
 * @var float     $non_cash_total
 * @var float     $cash_amount_due
 * @var array     $config
 */

use App\Models\Employee;

?>

<?= view('partial/header') ?>

<?php
if (isset($error)) {
    echo '<div class="alert alert-dismissible alert-danger">' . esc($error) . '</div>';
}

if (! empty($warning)) {
    echo '<div class="alert alert-dismissible alert-warning">' . esc($warning) . '</div>';
}

if (isset($success)) {
    echo '<div class="alert alert-dismissible alert-success">' . esc($success) . '</div>';
}
?>

<div id="register_wrapper">

    <!-- Top register controls -->
    <?= form_open("{$controller_name}/changeMode", ['id' => 'mode_form', 'class' => 'form-horizontal panel panel-default']) ?>
        <div class="panel-body form-group">
            <ul>
                <li class="pull-left first_li">
                    <label class="control-label"><?= lang(ucfirst($controller_name) . '.mode') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_dropdown('mode', $modes, $mode, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                </li>
                <?php if ($config['dinner_table_enable']) { ?>
                    <li class="pull-left first_li">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.table') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('dinner_table', $empty_tables, $selected_table, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>
                <?php if (count($stock_locations) > 1) { ?>
                    <li class="pull-left">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.stock_location') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('stock_location', $stock_locations, $stock_location, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>

                <li class="pull-right">
                    <button class="btn btn-default btn-sm modal-dlg" id="show_suspended_sales_button" data-href="<?= esc("{$controller_name}/suspended") ?>"
                        title="<?= lang(ucfirst($controller_name) . '.suspended_sales') ?>">
                        <span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspended_sales') ?>
                    </button>
                </li>

                <?php
                $employee = model(Employee::class);
if ($employee->has_grant('reports_sales', session('person_id'))) {
    ?>
                    <li class="pull-right">
                        <?= anchor(
                            "{$controller_name}/manage",
                            '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang(ucfirst($controller_name) . '.takings'),
                            ['class' => 'btn btn-primary btn-sm', 'id' => 'sales_takings_button', 'title' => lang(ucfirst($controller_name) . '.takings')],
                        ) ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?= form_close() ?>

    <?php $tabindex = 0; ?>

    <?= form_open("{$controller_name}/add", ['id' => 'add_item_form', 'class' => 'form-horizontal panel panel-default']) ?>
        <div class="panel-body form-group">
            <ul>
                <li class="pull-left first_li">
                    <label for="item" class="control-label"><?= lang(ucfirst($controller_name) . '.find_or_scan_item_or_receipt') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_input(['name' => 'item', 'id' => 'item', 'class' => 'form-control input-sm', 'size' => '50', 'tabindex' => ++$tabindex, 'placeholder' => lang(ucfirst($controller_name) . '.start_typing_item_name')]) ?>
                    <span class="ui-helper-hidden-accessible" role="status"></span>
                </li>
                <li class="pull-right">
                    <button id="new_item_button" class="btn btn-info btn-sm pull-right modal-dlg" data-btn-new="<?= lang('Common.new') ?>" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= 'items/view' ?>" title="<?= lang(ucfirst($controller_name) . '.new_item') ?>">
                        <span class="glyphicon glyphicon-tag">&nbsp;</span><?= lang(ucfirst($controller_name) . '.new_item') ?>
                    </button>
                </li>
            </ul>
        </div>
    <?= form_close() ?>


    <!-- Sale Items List -->

    <table class="sales_table_100" id="register">
        <thead>
            <tr>
                <th style="width: 5%;"><?= lang('Common.delete') ?></th>
                <th style="width: 15%;"><?= lang(ucfirst($controller_name) . '.item_number') ?></th>
                <th style="width: 30%;"><?= lang(ucfirst($controller_name) . '.item_name') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.price') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.quantity') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.total') ?></th>
                <th style="width: 5%;"><?= lang(ucfirst($controller_name) . '.update') ?></th>
            </tr>
        </thead>

        <tbody id="cart_contents">
            <?php if (count($cart) === 0) { ?>
                <tr>
                    <td colspan="7">
                        <div class="alert alert-dismissible alert-info"><?= lang(ucfirst($controller_name) . '.no_items_in_cart') ?></div>
                    </td>
                </tr>
            <?php
            } else {
                foreach (array_reverse($cart, true) as $line => $item) {
                    ?>
                    <?= form_open("{$controller_name}/editItem/{$line}", ['class' => 'form-horizontal', 'id' => "cart_{$line}"]) ?>
                        <tr>
                            <td>
                                <?= anchor("{$controller_name}/deleteItem/{$line}", '<span class="glyphicon glyphicon-trash"></span>');
                    echo form_input(['type' => 'hidden', 'name' => 'location', 'value' => (string) $item['item_location'], 'form' => "cart_{$line}"]);
                    echo form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id'], 'form' => "cart_{$line}"]);
                    ?>
                            </td>
                            <?php if ((int) $item['item_type'] === ITEM_TEMP) { ?>
                                <td><?= form_input(['name' => 'item_number', 'id' => 'item_number', 'class' => 'form-control input-sm', 'value' => $item['item_number'], 'tabindex' => ++$tabindex, 'form' => "cart_{$line}"]) ?></td>
                                <td style="align: center;">
                                    <?= form_input(['name' => 'name', 'id' => 'name', 'class' => 'form-control input-sm', 'value' => $item['name'], 'tabindex' => ++$tabindex, 'form' => "cart_{$line}"]) ?>
                                </td>
                            <?php } else { ?>
                                <td><?= esc($item['item_number']) ?></td>
                                <td style="align: center;">
                                    <?= esc($item['name']) . ' ' . implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']]) ?>
                                    <br>
                                    <?php if ((string) $item['stock_type'] === '0'): echo '[' . to_quantity_decimals($item['in_stock']) . ' in ' . $item['stock_name'] . ']';
                                    endif; ?>
                                </td>
                            <?php } ?>

                            <td>
                                <?php
                                if ($items_module_allowed && $change_price) {
                                    echo form_input(['name' => 'price', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['price']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => "cart_{$line}"]);
                                } else {
                                    echo to_currency($item['price']);
                                    echo form_input(['type' => 'hidden', 'name' => 'price', 'value' => to_currency_no_money($item['price']), 'form' => "cart_{$line}"]);
                                }
                    ?>
                            </td>

                            <td>
                                <?php
                    if ($item['is_serialized']) {
                        echo to_quantity_decimals($item['quantity']);
                        echo form_input(['type' => 'hidden', 'name' => 'quantity', 'value' => $item['quantity'], 'form' => "cart_{$line}"]);
                    } else {
                        echo form_input(['name' => 'quantity', 'class' => 'form-control input-sm', 'value' => to_quantity_decimals($item['quantity']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => "cart_{$line}"]);
                    }
                    ?>
                            </td>

                            <td>
                                <?php
                    if ((int) $item['item_type'] === ITEM_AMOUNT_ENTRY) {
                        echo form_input(['name' => 'discounted_total', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['discounted_total']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => "cart_{$line}"]);
                    } else {
                        echo to_currency($item['discounted_total']);
                    }
                    ?>
                            </td>

                            <td>
                                <a href="javascript:document.getElementById('<?= "cart_{$line}" ?>').submit();" title="<?= lang(ucfirst($controller_name) . '.update') ?>">
                                    <span class="glyphicon glyphicon-refresh"></span>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <?php if ((int) $item['item_type'] === ITEM_TEMP) { ?>
                                <td><?= form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id'], 'form' => "cart_{$line}"]) ?></td>
                                <td style="align: center;" colspan="5">
                                    <?= form_input(['name' => 'item_description', 'id' => 'item_description', 'class' => 'form-control input-sm', 'value' => $item['description'], 'tabindex' => ++$tabindex, 'form' => "cart_{$line}"]) ?>
                                </td>
                                <td> </td>
                            <?php } else { ?>
                                <td>&nbsp;</td>
                                <?php if ($item['allow_alt_description']) { ?>
                                    <td style="color: #2F4F4F;"><?= lang(ucfirst($controller_name) . '.description_abbrv') ?></td>
                                <?php } ?>

                                <td colspan="2" style="text-align: left;">
                                    <?php
                        if ($item['allow_alt_description']) {
                            echo form_input(['name' => 'description', 'class' => 'form-control input-sm', 'value' => $item['description'], 'onClick' => 'this.select();', 'form' => "cart_{$line}"]);
                        } else {
                            if ((string) $item['description'] !== '') {
                                echo $item['description'];
                                echo form_input(['type' => 'hidden', 'name' => 'description', 'value' => $item['description'], 'form' => "cart_{$line}"]);
                            } else {
                                echo lang(ucfirst($controller_name) . '.no_description');
                                echo form_input(['type' => 'hidden', 'name' => 'description', 'value' => '', 'form' => "cart_{$line}"]);
                            }
                        }
                                ?>
                                </td>
                                <td>&nbsp;</td>
                                <td style="color: #2F4F4F;">
                                    <?php
                                if ($item['is_serialized']) {
                                    echo lang(ucfirst($controller_name) . '.serial');
                                }
                                ?>
                                </td>
                                <td colspan="4" style="text-align: left;">
                                    <?php
                                if ($item['is_serialized']) {
                                    echo form_input(['name' => 'serialnumber', 'class' => 'form-control input-sm', 'value' => $item['serialnumber'], 'onClick' => 'this.select();', 'form' => "cart_{$line}"]);
                                } else {
                                    echo form_input(['type' => 'hidden', 'name' => 'serialnumber', 'value' => '', 'form' => "cart_{$line}"]);
                                }
                                ?>
                                </td>
                            <?php } ?>
                        </tr>
                    <?= form_close() ?>
            <?php
                }
            }
?>
        </tbody>
    </table>
</div>

<!-- Overall Sale -->

<div id="overall_sale" class="panel panel-default" data-change-helper-total="<?= esc((string) (float) $total, 'attr') ?>">
    <div class="panel-body">
        <table class="sales_table_100" id="sale_totals">
            <tr>
                <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.quantity_of_items', [$item_count]) ?></th>
                <th style="width: 45%; text-align: right;"><?= $total_units ?></th>
            </tr>
            <tr>
                <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.sub_total') ?></th>
                <th style="width: 45%; text-align: right;"><?= to_currency($subtotal) ?></th>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr>
                    <th style="width: 55%;"><?= (float) $tax['tax_rate'] . '% ' . esc(format_tax_group_label($tax['tax_group'])) ?></th>
                    <th style="width: 45%; text-align: right;"><?= to_currency_tax($tax['sale_tax_amount']) ?></th>
                </tr>
            <?php } ?>
            <tr>
                <th style="width: 55%; font-size: 150%"><?= lang('Sales.total_to_pay') ?></th>
                <th style="width: 45%; font-size: 150%; text-align: right;"><span id="sale_total"><?= to_currency($total) ?></span></th>
            </tr>
            <tr>
                <th style="width: 55%;"></th>
                <th style="width: 45%; text-align: right;"><span dir="ltr" id="sale_total_lbp"><?= esc(format_lbp(to_lbp($total))) ?></span></th>
            </tr>
        </table>

        <?php if (count($cart) > 0) { // Only show this part if there are Items already in the register?>
            <table class="sales_table_100" id="payment_totals">
                <tr>
                    <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.payments_total') ?></th>
                    <th style="width: 45%; text-align: right;"><?= to_currency($payments_total) ?></th>
                </tr>
                <tr>
                    <th style="width: 55%; font-size: 120%"><?= lang(ucfirst($controller_name) . '.amount_due') ?></th>
                    <th style="width: 45%; font-size: 120%; text-align: right;"><span id="sale_amount_due"><?= to_currency($amount_due) ?></span></th>
                </tr>
            </table>

            <table class="sales_table_100" id="change_helper">
                <tr>
                    <th colspan="2"><?= lang('Sales.change_helper') ?></th>
                </tr>
                <tr>
                    <td><?= lang('Sales.change_helper_currency') ?></td>
                    <td>
                        <select id="change_helper_currency" class="form-control input-sm">
                            <option value="usd"><?= lang('Sales.dollars') ?></option>
                            <option value="lbp"><?= lang('Sales.pounds') ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><?= lang('Sales.change_helper_tendered') ?></td>
                    <td>
                        <input type="number" id="change_helper_amount" class="form-control input-sm" min="0" step="any" inputmode="decimal" value="">
                    </td>
                </tr>
                <tr>
                    <td><?= lang('Sales.change_helper_dollars') ?></td>
                    <td style="text-align: right;"><span dir="ltr" id="change_helper_dollars"><?= to_currency('0') ?></span></td>
                </tr>
                <tr>
                    <td><?= lang('Sales.change_helper_pounds') ?></td>
                    <td style="text-align: right;"><span dir="ltr" id="change_helper_pounds"><?= esc(format_lbp('0')) ?></span></td>
                </tr>
            </table>

            <div id="payment_details">
                <?php if ($payments_cover_total) { // Show Complete sale button instead of Add Payment if there is no amount due left?>
                    <?= form_open("{$controller_name}/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <table class="sales_table_100">
                            <tr>
                                <td><span id="amount_tendered_label"><?= lang(ucfirst($controller_name) . '.amount_tendered') ?></span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm disabled', 'disabled' => 'disabled', 'value' => '0', 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <?php
                    // Only show this part if in sale or return mode
                    if ($pos_mode) {
                        ?>
                            <div class="btn btn-sm btn-success pull-right" id="finish_sale_button" tabindex="<?= ++$tabindex ?>">
                                <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang(ucfirst($controller_name) . '.complete_sale') ?>
                            </div>
                    <?php
                    }
                    ?>
                <?php } else { ?>
                    <?= form_open("{$controller_name}/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <table class="sales_table_100">
                            <tr>
                                <td><span id="amount_tendered_label"><?= lang(ucfirst($controller_name) . '.amount_tendered') ?></span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <div class="btn btn-sm btn-success pull-right" id="add_payment_button" tabindex="<?= ++$tabindex ?>">
                        <span class="glyphicon glyphicon-credit-card">&nbsp;</span><?= lang(ucfirst($controller_name) . '.add_payment') ?>
                    </div>
                <?php } ?>

                <?php if (count($payments) > 0) { // Only show this part if there is at least one payment entered.?>
                    <table class="sales_table_100" id="register">
                        <thead>
                            <tr>
                                <th style="width: 10%;"><?= lang('Common.delete') ?></th>
                                <th style="width: 60%;"><?= lang(ucfirst($controller_name) . '.payment_type') ?></th>
                                <th style="width: 20%;"><?= lang(ucfirst($controller_name) . '.payment_amount') ?></th>
                            </tr>
                        </thead>

                        <tbody id="payment_contents">
                            <?php foreach ($payments as $payment_id => $payment) { ?>
                                <tr>
                                    <td><?= anchor("{$controller_name}/deletePayment/" . encode_payment_id($payment_id), '<span class="glyphicon glyphicon-trash"></span>') ?></td>
                                    <td><?= $payment['payment_type'] ?></td>
                                    <td style="text-align: right;"><?= to_currency($payment['payment_amount']) ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </div>

            <?= form_open("{$controller_name}/cancel", ['id' => 'buttons_form']) ?>
            <div class="form-group" id="buttons_sale">
                <div class="btn btn-sm btn-default pull-left" id="suspend_sale_button"><span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspend_sale') ?></div>
                <?php if (! $pos_mode) { // Quote mode does not require payment details.?>
                    <div class="btn btn-sm btn-success" id="finish_invoice_button"><span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc($mode_label) ?></div>
                <?php } ?>

                <div class="btn btn-sm btn-danger pull-right" id="cancel_sale_button"><span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang(ucfirst($controller_name) . '.cancel_sale') ?></div>
            </div>
            <?= form_close() ?>

            <?php if ($payments_cover_total || ! $pos_mode) { // Only show this part if the payment cover the total?>
                <div class="container-fluid">
                    <div class="no-gutter row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-12">
                                <?= form_label(lang('Common.comments'), 'comments', ['class' => 'control-label', 'id' => 'comment_label', 'for' => 'comment']) ?>
                                <?= form_textarea(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control input-sm', 'value' => $comment, 'rows' => '2']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-6">
                                <label for="sales_print_after_sale" class="control-label checkbox">
                                    <?= form_checkbox(['name' => 'sales_print_after_sale', 'id' => 'sales_print_after_sale', 'value' => 1, 'checked' => $print_after_sale]) ?>
                                    <?= lang(ucfirst($controller_name) . '.print_after_sale') ?>
                                </label>
                            </div>

                        </div>
                    </div>
                    <?php if (($mode === 'sale_invoice') && $config['invoice_enable']) { ?>
                        <div class="row">
                            <div class="form-group form-group-sm">
                                <div class="col-xs-6">
                                    <label for="sales_invoice_number" class="control-label checkbox">
                                        <?= lang(ucfirst($controller_name) . '.invoice_enable') ?>
                                    </label>
                                </div>

                                <div class="col-xs-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-addon input-sm">#</span>
                                        <?= form_input(['name' => 'sales_invoice_number', 'id' => 'sales_invoice_number', 'class' => 'form-control input-sm', 'value' => $invoice_number]) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
        <?php
            }
        }
?>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const redirect = function() {
            window.location.href = "<?= site_url('sales'); ?>";
        };

        let addItemInFlight = false;
        const pendingItemScans = [];
        const maxPendingItemScans = 20;
        const registerRecoveryNoticeKey = 'ospos_register_recovery_notice';
        let registerRecoveryInProgress = false;
        const itemAddFailureMessage = <?= json_encode(lang(ucfirst($controller_name) . '.unable_to_add_item')) ?>;
        const registerReloadedMessage = <?= json_encode(lang(ucfirst($controller_name) . '.register_reloaded_scan_again')) ?>;
        const queuedScansMessageTemplate = <?= json_encode(lang(ucfirst($controller_name) . '.queued_scans_not_submitted')) ?>;

        /**
         * Reloads the register after an uncertain add and tells the cashier which queued scans were dropped.
         */
        const recoverRegister = function(message, additionalDiscardedScans = 0) {
            if (registerRecoveryInProgress) {
                return;
            }

            registerRecoveryInProgress = true;
            const discardedScanCount = pendingItemScans.length + additionalDiscardedScans;
            pendingItemScans.length = 0;
            const queuedScanMessage = discardedScanCount > 0
                ? ` ${queuedScansMessageTemplate.replace('{0}', discardedScanCount)}`
                : '';
            const recoveryMessage = `${message} ${registerReloadedMessage}${queuedScanMessage}`;

            try {
                sessionStorage.setItem(registerRecoveryNoticeKey, recoveryMessage);
            } catch (error) {
                // The visible notification below still warns the cashier if storage is unavailable.
            }

            $.notify({ message: recoveryMessage }, { type: 'danger' });
            window.location.replace("<?= site_url('sales'); ?>");
        };

        /**
         * Shows the recovery warning saved before the last authoritative register reload.
         */
        const showRecoveryNotice = function() {
            let recoveryMessage = null;

            try {
                recoveryMessage = sessionStorage.getItem(registerRecoveryNoticeKey);
                sessionStorage.removeItem(registerRecoveryNoticeKey);
            } catch (error) {
                return;
            }

            if (recoveryMessage) {
                $.notify({ message: recoveryMessage }, { type: 'danger' });
            }
        };

        /**
         * Validates the response, keeps the entered tender, and refreshes the helper from the returned total.
         */
        const renderAddItemResponse = function(response) {
            if (typeof response !== 'string') {
                recoverRegister(itemAddFailureMessage);

                return false;
            }

            const scanBuffer = $('#item').val();
            const responseDocument = document.implementation.createHTMLDocument('register-response');
            responseDocument.documentElement.innerHTML = response;
            const $response = $(responseDocument);
            const $register = $response.find('#register_wrapper');
            const $sale = $response.find('#overall_sale');
            const $message = $response.find('.alert-danger, .alert-warning, .alert-success').first();
            const totalValue = $sale.attr('data-change-helper-total');
            const responseTotal = Number(totalValue);

            if (
                $register.length !== 1 ||
                $sale.length !== 1 ||
                totalValue === undefined ||
                totalValue.trim() === '' ||
                !Number.isFinite(responseTotal)
            ) {
                recoverRegister(itemAddFailureMessage);

                return false;
            }

            const changeHelperAmount = $('#change_helper_amount').val();
            const changeHelperCurrency = $('#change_helper_currency').val();

            $('#register_wrapper').prevAll('.alert').remove();
            if ($message.length) {
                $('#register_wrapper').before($message.clone());
            }
            $('#register_wrapper').replaceWith($register);
            $('#overall_sale').replaceWith($sale);
            changeHelperTotal = responseTotal;
            if (changeHelperAmount !== undefined) {
                $('#change_helper_amount').val(changeHelperAmount);
                $('#change_helper_currency').val(changeHelperCurrency);
            }

            bindRegisterHandlers();
            $('#item').val(scanBuffer).focus();

            return true;
        };

        /**
         * Queues an item add from scanner input or a newly created item through the register AJAX path.
         */
        const submitItemScan = function(item, scanBuffer = '') {
            const itemValue = String(item).trim();
            if (itemValue === '') {
                return;
            }

            if (addItemInFlight) {
                if (pendingItemScans.length >= maxPendingItemScans) {
                    recoverRegister(itemAddFailureMessage, 1);

                    return;
                }

                pendingItemScans.push(itemValue);
                $('#item').val('');

                return;
            }

            addItemInFlight = true;
            const $form = $('#add_item_form');
            const $input = $('#item');
            $input.val(itemValue);
            $form.ajaxSubmit({
                dataType: 'html',
                timeout: 10000,
                beforeSubmit: function() {
                    $input.val(scanBuffer);
                },
                success: function(response) {
                    renderAddItemResponse(response);
                },
                error: function() {
                    recoverRegister(itemAddFailureMessage);
                },
                complete: function() {
                    if (registerRecoveryInProgress) {
                        return;
                    }

                    addItemInFlight = false;
                    if (pendingItemScans.length > 0) {
                        const nextScan = pendingItemScans.shift();
                        const nextScanBuffer = $('#item').val();
                        submitItemScan(nextScan, nextScanBuffer);
                    } else {
                        $('#item').focus();
                    }
                }
            });
        };

        /**
         * Rebinds controls after an AJAX response replaces the register fragments.
         */
        const bindRegisterHandlers = function() {
            $(".delete_item_button").off('click.register').on('click.register', function() {
                const item_id = $(this).data('item-id');
                $.post("<?= site_url('sales/deleteItem/'); ?>" + item_id, redirect);
            });

            $(".delete_payment_button").off('click.register').on('click.register', function() {
                const item_id = $(this).data('payment-id');
                $.post("<?= site_url('sales/deletePayment/'); ?>" + item_id, redirect);
            });

            $("input[name='item_number']").each(function() {
                $(this).data('saved-item-number', $(this).val());
            });

            $("input[name='item_number']").off('change.register').on('change.register', function() {
                var $input              = $(this);
                var item_id             = $input.parents('tr').find("input[name='item_id']").val();
                var item_number         = $input.val();
                var previous_item_number = $input.data('saved-item-number');
                $.ajax({
                    url: "<?= site_url('sales/changeItemNumber') ?>",
                    method: 'post',
                    data: {
                        'item_id': item_id,
                        'item_number': item_number,
                    },
                    dataType: 'json',
                    success: function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        });

                        if (response.success) {
                            $input.val(response.item_number);
                            $input.data('saved-item-number', response.item_number);
                        } else {
                            $input.val(previous_item_number);
                        }
                    },
                    error: function() {
                        $input.val(previous_item_number);
                    }
                });
            });

            $("input[name='name']").off('change.register').on('change.register', function() {
                var item_id = $(this).parents('tr').find("input[name='item_id']").val();
                var item_name = $(this).val();
                $.ajax({
                    url: "<?= site_url('sales/changeItemName') ?>",
                    method: 'post',
                    data: {
                        'item_id': item_id,
                        'item_name': item_name,
                    },
                    dataType: 'json'
                });
            });

            $("input[name='item_description']").off('change.register').on('change.register', function() {
                var item_id = $(this).parents('tr').find("input[name='item_id']").val();
                var item_description = $(this).val();
                $.ajax({
                    url: "<?= site_url('sales/changeItemDescription') ?>",
                    method: 'post',
                    data: {
                        'item_id': item_id,
                        'item_description': item_description,
                    },
                    dataType: 'json'
                });
            });

            $('#add_item_form').off('submit.register').on('submit.register', function(event) {
                event.preventDefault();
                submitItemScan($('#item').val());

                return false;
            });

            $('#item').autocomplete({
                source: "<?= esc("{$controller_name}/itemSearch") ?>",
                minLength: 1,
                autoFocus: false,
                delay: 500,
                select: function(a, ui) {
                    $(this).val(ui.item.value);
                    submitItemScan($(this).val());

                    return false;
                }
            });

            $('#item').off('keypress.register').on('keypress.register', function(event) {
                if (event.which == 13) {
                    submitItemScan($(this).val());

                    return false;
                }
            });

            $('#item').off('dblclick.register').on('dblclick.register', function() {
                $(this).autocomplete('search');
            });

            $('#comment').off('keyup.register').on('keyup.register', function() {
                $.post("<?= esc(site_url("{$controller_name}/setComment")) ?>", {
                    comment: $('#comment').val()
                });
            });

            <?php if ($config['invoice_enable']) { ?>
                $('#sales_invoice_number').off('keyup.register').on('keyup.register', function() {
                    $.post("<?= esc(site_url("{$controller_name}/setInvoiceNumber")) ?>", {
                        sales_invoice_number: $('#sales_invoice_number').val()
                    });
                });

            <?php } ?>

            $('#sales_print_after_sale').off('change.register').on('change.register', function() {
                $.post("<?= esc(site_url("{$controller_name}/setPrintAfterSale")) ?>", {
                    sales_print_after_sale: $(this).is(':checked')
                });
            });

            $('#finish_sale_button').off('click.register').on('click.register', function() {
                $('#buttons_form').attr('action', "<?= "{$controller_name}/complete" ?>");
                $('#buttons_form').submit();
            });

            $('#finish_invoice_button').off('click.register').on('click.register', function() {
                $('#buttons_form').attr('action', "<?= "{$controller_name}/complete" ?>");
                $('#buttons_form').submit();
            });

            $('#suspend_sale_button').off('click.register').on('click.register', function() {
                $('#buttons_form').attr('action', "<?= site_url("{$controller_name}/suspend") ?>");
                $('#buttons_form').submit();
            });

            $('#cancel_sale_button').off('click.register').on('click.register', function() {
                if (confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
                    $('#buttons_form').attr('action', "<?= site_url("{$controller_name}/cancel") ?>");
                    $('#buttons_form').submit();
                }
            });

            $('#add_payment_button').off('click.register').on('click.register', function() {
                $('#add_payment_form').submit();
            });

            $('#cart_contents input').off('keypress.register').on('keypress.register', function(event) {
                if (event.which == 13) {
                    $(this).parents('tr').prevAll('form:first').submit();
                }
            });

            $('#amount_tendered').off('keypress.register').on('keypress.register', function(event) {
                if (event.which == 13) {
                    $('#add_payment_form').submit();
                }
            });

            $('#finish_sale_button').off('keypress.register').on('keypress.register', function(event) {
                if (event.which == 13) {
                    $('#finish_sale_form').submit();
                }
            });

            $('[name="price"],[name="quantity"],[name="description"],[name="serialnumber"],[name="discounted_total"]')
                .off('change.register')
                .on('change.register', function() {
                    $(this).parents('tr').prevAll('form:first').submit();
                });

            $('#change_helper_amount, #change_helper_currency')
                .off('input.register change.register')
                .on('input.register change.register', updateChangeHelper);
            updateChangeHelper();
            dialog_support.init('a.modal-dlg, button.modal-dlg');
        };

        /**
         * Adds a newly created item through the same queued register path as scanner input.
         */
        table_support.handle_submit = function(resource, response, stay_open) {
            $.notify({
                message: response.message
            }, {
                type: response.success ? 'success' : 'danger'
            });

            if (response.success) {
                var $stock_location = $("select[name='stock_location']").val();
                $('#item_location').val($stock_location);
                submitItemScan(response.id, $('#item').val());
            }
        };

        showRecoveryNotice();
        bindRegisterHandlers();
        $('#item').focus();
    });

    let changeHelperTotal = <?= json_encode((float) $total) ?>;

    /**
     * Updates both change figures from one dollar change calculation.
     */
    function updateChangeHelper() {
        const enteredAmount = $('#change_helper_amount').val();

        if (enteredAmount === '') {
            $('#change_helper_dollars').text(formatChangeDollars(0));
            $('#change_helper_pounds').text(formatChangePounds(0));

            return;
        }

        const amount = Number(enteredAmount);

        if (!Number.isFinite(amount)) {
            $('#change_helper_dollars').text(formatChangeDollars(0));
            $('#change_helper_pounds').text(formatChangePounds(0));

            return;
        }

        const rate = <?= json_encode((float) $config['lbp_exchange_rate']) ?>;
        const tenderedDollars = $('#change_helper_currency').val() === 'lbp' ? amount / rate : amount;
        const changeDollars = tenderedDollars - changeHelperTotal;
        const changePounds = Math.round((changeDollars * rate) / 5000) * 5000;

        $('#change_helper_dollars').text(formatChangeDollars(changeDollars));
        $('#change_helper_pounds').text(formatChangePounds(changePounds));
    }

    /**
     * Formats a dollar amount using the register's configured symbol and decimals.
     */
    function formatChangeDollars(amount) {
        const formatter = new Intl.NumberFormat(<?= json_encode(str_replace('_', '-', $config['number_locale'])) ?>, {
            minimumFractionDigits: <?= (int) $config['currency_decimals'] ?>,
            maximumFractionDigits: <?= (int) $config['currency_decimals'] ?>,
            useGrouping: <?= json_encode($config['thousands_separator'] !== '0') ?>
        });
        const value = formatter.format(amount);
        const symbol = <?= json_encode($config['currency_symbol']) ?>;

        return <?= json_encode(is_right_side_currency_symbol()) ?> ? value + symbol : symbol + value;
    }

    /**
     * Formats a Lebanese pound amount with grouping and the LL marker.
     */
    function formatChangePounds(amount) {
        return new Intl.NumberFormat(<?= json_encode(str_replace('_', '-', $config['number_locale'])) ?>, {
            maximumFractionDigits: 0,
            useGrouping: true
        }).format(amount) + ' LL';
    }

    // Add Keyboard Shortcuts/Hotkeys to Sale Register
    document.body.onkeyup = function(e) {
        switch (event.altKey && event.keyCode) {
            case 49: // Alt + 1 Items Seach
                $("#item").focus();
                $("#item").select();
                break;
            case 51: // Alt + 3 Suspend Current Sale
                $("#suspend_sale_button").click();
                break;
            case 52: // Alt + 4 Check Suspended
                $("#show_suspended_sales_button").click();
                break;
            case 53: // Alt + 5 Edit Amount Tendered Value
                $("#amount_tendered").focus();
                $("#amount_tendered").select();
                break;
            case 54: // Alt + 6 Add Payment
                $("#add_payment_button").click();
                break;
            case 55: // Alt + 7 Add Payment and Complete Sales/Invoice
                $("#add_payment_button").click();
                window.location.href = "<?= 'sales/complete' ?>";
                break;
            case 56: // Alt + 8 Finish Invoice without payment
                $("#finish_invoice_button").click();
                break;
        }

        switch (event.keyCode) {
            case 27: // ESC Cancel Current Sale
                $("#cancel_sale_button").click();
                break;
        }
    }
</script>

<?= view('partial/footer') ?>
