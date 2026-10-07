<?php
/**
 * @var string     $controller_name
 * @var array      $modes
 * @var string     $mode
 * @var bool       $show_stock_locations
 * @var array      $stock_locations
 * @var int        $stock_source
 * @var string     $stock_destination
 * @var array      $cart
 * @var bool       $items_module_allowed
 * @var float      $total
 * @var string     $comment
 * @var bool       $print_after_sale
 * @var string     $reference
 * @var array      $payment_options
 * @var array      $config
 * @var array      $lbp_totals
 * @var int|null   $lbp_total
 * @var float|null $lbp_rate
 */
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

            <?php if ($show_stock_locations) { ?>
                <li class="pull-left">
                    <label class="control-label"><?= lang(ucfirst($controller_name) . '.stock_source') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_dropdown('stock_source', $stock_locations, $stock_source, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                </li>

                <?php if ($mode == 'requisition') { ?>
                    <li class="pull-left">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.stock_destination') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('stock_destination', $stock_locations, $stock_destination, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
            <?php
                }
            }
?>
        </ul>
    </div>

    <?= form_close() ?>

    <?= form_open("{$controller_name}/add", ['id' => 'add_item_form', 'class' => 'form-horizontal panel panel-default']) ?>

    <div class="panel-body form-group">
        <ul>
            <li class="pull-left first_li">
                <label for="item" class="control-label">
                    <?php if ($mode == 'receive' || $mode == 'requisition') { ?>
                        <?= lang(ucfirst($controller_name) . '.find_or_scan_item') ?>
                    <?php } else { ?>
                        <?= lang(ucfirst($controller_name) . '.find_or_scan_item_or_receipt') ?>
                    <?php } ?>
                </label>
            </li>

            <li class="pull-left">
                <?= form_input([
                    'name'        => 'item',
                    'id'          => 'item',
                    'class'       => 'form-control input-sm',
                    'size'        => '50',
                    'tabindex'    => '1',
                    'placeholder' => lang(ucfirst($controller_name) . '.' . ($mode == 'receive' || $mode == 'requisition'
                        ? 'find_or_scan_item'
                        : 'find_or_scan_item_or_receipt')),
                ]) ?>
            </li>

            <li class="pull-right">
                <button id="new_item_button" class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-btn-new="<?= lang('Common.new') ?>" data-href="<?= 'items/view' ?>" title="<?= lang('Sales.new_item') ?>">
                    <span class="glyphicon glyphicon-tag">&nbsp;</span><?= lang('Sales.new_item') ?>
                </button>
            </li>
        </ul>
    </div>

    <?= form_close() ?>

    <!-- Receiving Items List -->

    <table class="sales_table_100" id="register">
        <thead>
            <tr>
                <th style="width: 5%;"><?= lang('Common.delete') ?></th>
                <th style="width: 15%;"><?= lang('Sales.item_number') ?></th>
                <th style="width: 23%;"><?= lang(ucfirst($controller_name) . '.item_name') ?></th>
                <th style="width: 10%;"><?= lang('Receivings.cost_lbp') ?></th>
                <th style="width: 8%;"><?= lang(ucfirst($controller_name) . '.quantity') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.ship_pack') ?></th>
                <th style="width: 14%;"><?= lang(ucfirst($controller_name) . '.discount') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.total') ?></th>
                <th style="width: 5%;"><?= lang(ucfirst($controller_name) . '.update') ?></th>
            </tr>
        </thead>

        <tbody id="cart_contents">
            <?php if (count($cart) == 0) { ?>
                <tr>
                    <td colspan="9">
                        <div class="alert alert-dismissible alert-info"><?= lang('Sales.no_items_in_cart') ?></div>
                    </td>
                </tr>
                <?php
            } else {
                foreach (array_reverse($cart, true) as $line => $item) {
                    $lbp_line = $lbp_totals['lines'][$line] ?? ['price_lbp' => 0, 'total_lbp' => 0];
                    ?>

                    <?= form_open("{$controller_name}/editItem/{$line}", [
                        'class'        => 'form-horizontal',
                        'id'           => "cart_{$line}",
                        'data-line'    => (string) $line,
                        'data-item-id' => (string) $item['item_id'],
                    ]) ?>

                    <tr>
                        <td><?= anchor("{$controller_name}/deleteItem/{$line}", '<span class="glyphicon glyphicon-trash"></span>') ?></td>
                        <td><?= esc($item['item_number']) ?></td>
                        <td style="text-align: center;">
                            <?= esc($item['name'] . ' ' . implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?><br>
                            <?= '[' . esc(lang('Receivings.stock_in_location', [to_quantity_decimals($item['in_stock']), $item['stock_name']])) . ']' ?>
                            <?= form_input(['type' => 'hidden', 'name' => 'location', 'value' => (string) $item['item_location'], 'form' => "cart_{$line}"]) ?>
                        </td>

                        <?php if ($items_module_allowed && $mode != 'requisition') { ?>
                            <td>
                                <?= form_input([
                                    'name'    => 'price',
                                    'class'   => 'form-control input-sm',
                                    'value'   => format_lbp_input($lbp_line['price_lbp']),
                                    'dir'     => 'ltr',
                                    'onClick' => 'this.select();',
                                    'form'    => "cart_{$line}",
                                ]) ?>
                                <?php if ($lbp_rate !== null) { ?><small class="text-muted" dir="ltr"><?= to_currency($item['price']) ?></small><?php } ?>
                            </td>
                        <?php } else { ?>
                            <td>
                                <?php if ($lbp_rate !== null) { ?><div dir="ltr"><?= esc(format_lbp($lbp_line['price_lbp'])) ?></div><?php } ?>
                                <small class="text-muted" dir="ltr"><?= to_currency($item['price']) ?></small>
                                <?= form_input(['type' => 'hidden', 'name' => 'price', 'value' => format_lbp_input($lbp_line['price_lbp']), 'form' => "cart_{$line}"]) ?>
                            </td>
                        <?php } ?>

                        <td>
                            <?= form_input(['name' => 'quantity', 'class' => 'form-control input-sm', 'value' => to_quantity_decimals($item['quantity']), 'onClick' => 'this.select();', 'form' => "cart_{$line}"]) ?>
                        </td>
                        <td>
                            <?= form_dropdown(
                                'receiving_quantity',
                                $item['receiving_quantity_choices'],
                                $item['receiving_quantity'],
                                ['class' => 'form-control input-sm', 'form' => "cart_{$line}"],
                            ) ?>
                        </td>

                        <?php if ($items_module_allowed && $mode != 'requisition') { ?>
                            <td>
                                <div class="input-group">
                                    <?= form_input([
                                        'name'  => 'discount',
                                        'class' => 'form-control input-sm',
                                        'value' => $item['discount_type'] == FIXED
                                            ? format_lbp_input(round_lbp_to_thousand((float) $item['discount'] * (float) ($lbp_rate ?? 0)))
                                            : to_decimals($item['discount']),
                                        'dir'     => 'ltr',
                                        'onClick' => 'this.select();',
                                        'form'    => "cart_{$line}",
                                    ]) ?>
                                    <span class="input-group-btn">
                                        <?= form_checkbox([
                                            'id'           => 'discount_toggle',
                                            'name'         => 'discount_toggle',
                                            'value'        => 1,
                                            'data-toggle'  => 'toggle',
                                            'data-size'    => 'small',
                                            'data-onstyle' => 'success',
                                            'data-on'      => '<b>LL</b>',
                                            'data-off'     => '<b>%</b>',
                                            'data-line'    => $line,
                                            'checked'      => $item['discount_type'] == 1,
                                            'form'         => "cart_{$line}",
                                        ]) ?>
                                    </span>
                                </div>
                                <?php if ($item['discount_type'] == FIXED && $lbp_rate !== null) { ?><small class="text-muted" dir="ltr"><?= to_currency($item['discount']) ?></small><?php } ?>
                            </td>
                        <?php } else { ?>
                            <td>
                                <div dir="ltr"><?= $item['discount_type'] == FIXED
                                    ? esc(format_lbp(round_lbp_to_thousand((float) $item['discount'] * (float) ($lbp_rate ?? 0))))
                                    : esc(to_decimals($item['discount'])) ?></div>
                                <?php if ($item['discount_type'] == FIXED && $lbp_rate !== null) { ?><small class="text-muted" dir="ltr"><?= to_currency($item['discount']) ?></small><?php } ?>
                            </td>
                            <?= form_input([
                                'type'  => 'hidden',
                                'name'  => 'discount',
                                'value' => $item['discount_type'] == FIXED
                                    ? format_lbp_input(round_lbp_to_thousand((float) $item['discount'] * (float) ($lbp_rate ?? 0)))
                                    : (string) $item['discount'],
                                'form' => "cart_{$line}",
                            ]) ?>
                        <?php } ?>
                        <td>
                            <?php if ($lbp_rate !== null) { ?><div dir="ltr"><?= esc(format_lbp($lbp_line['total_lbp'])) ?></div><?php } ?>
                            <small class="text-muted" dir="ltr"><?= to_currency($item['total']) ?></small>
                        </td>
                        <td>
                            <a href="javascript:$('#<?= esc("cart_{$line}", 'js') ?>').submit();" title=<?= lang(ucfirst($controller_name) . '.update') ?>>
                                <span class="glyphicon glyphicon-refresh"></span>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <?php if ($item['allow_alt_description'] == 1) {    // TODO: ===?
                            ?>
                            <td style="color: #2F4F4F;"><?= lang('Sales.description_abbrv') . ':' ?></td>
                        <?php } ?>
                        <td colspan="2" style="text-align: left;">
                            <?php
                                if ($item['allow_alt_description'] == 1) {    // TODO: ===?
                                    echo form_input([
                                        'name'  => 'description',
                                        'class' => 'form-control input-sm',
                                        'value' => $item['description'],
                                        'form'  => "cart_{$line}",
                                    ]);
                                } else {
                                    if ($item['description'] != '') {    // TODO: !==?
                                        echo esc($item['description']);
                                        echo form_input(['type' => 'hidden', 'name' => 'description', 'value' => $item['description'], 'form' => "cart_{$line}"]);
                                    } else {
                                        echo '<i>' . lang('Sales.no_description') . '</i>';
                                        echo form_input(['type' => 'hidden', 'name' => 'description', 'value' => '', 'form' => "cart_{$line}"]);
                                    }
                                }
                    ?>
                        </td>
                        <td colspan="7"><?= form_input(['type' => 'hidden', 'name' => 'serialnumber', 'value' => $item['serialnumber'], 'form' => "cart_{$line}"]) ?></td>
                    </tr>

                    <?= form_close() ?>

            <?php
                }
            }
?>
        </tbody>
    </table>
</div>

<!-- Overall Receiving -->

<div id="overall_sale" class="panel panel-default">
    <div class="panel-body">
        <table class="sales_table_100" id="sale_totals">
            <tr>
                <?php if ($mode != 'requisition') { ?>
                    <th style="width: 55%;"><?= lang('Sales.total') ?></th>
                    <th style="width: 45%; text-align: right;">
                        <?php if ($lbp_rate !== null) { ?><div dir="ltr"><?= esc(format_lbp($lbp_total ?? 0)) ?></div><?php } ?>
                        <small class="text-muted" dir="ltr"><?= to_currency($total) ?></small>
                    </th>
                <?php } else { ?>
                    <th style="width: 55%;"></th>
                    <th style="width: 45%; text-align: right;"></th>
                <?php } ?>
            </tr>
        </table>

        <?php if (count($cart) > 0) { ?>
            <div id="finish_sale">
                <?php if ($mode == 'requisition') { ?>

                    <?= form_open("{$controller_name}/requisitionComplete", ['id' => 'finish_receiving_form', 'class' => 'form-horizontal']) ?>

                    <div class="form-group form-group-sm">
                        <label id="comment_label" for="comment"><?= lang('Common.comments') ?></label>
                        <?= form_textarea([
                            'name'  => 'comment',
                            'id'    => 'comment',
                            'class' => 'form-control input-sm',
                            'value' => $comment,
                            'rows'  => '4',
                        ]) ?>

                        <div class="btn btn-sm btn-danger pull-left" id="cancel_receiving_button">
                            <span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang(ucfirst($controller_name) . '.cancel_receiving') ?>
                        </div>
                        <div class="btn btn-sm btn-success pull-right" id="finish_receiving_button">
                            <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang(ucfirst($controller_name) . '.complete_receiving') ?>
                        </div>
                    </div>

                    <?= form_close() ?>

                <?php } else { ?>

                    <?= form_open("{$controller_name}/complete", ['id' => 'finish_receiving_form', 'class' => 'form-horizontal']) ?>

                    <div class="form-group form-group-sm">
                        <label id="comment_label" for="comment"><?= lang('Common.comments') ?></label>
                        <?= form_textarea([
                            'name'  => 'comment',
                            'id'    => 'comment',
                            'class' => 'form-control input-sm',
                            'value' => $comment,
                            'rows'  => '4',
                        ]) ?>
                        <div id="payment_details">
                            <table class="sales_table_100">
                                <tr>
                                    <td><?= lang(ucfirst($controller_name) . '.print_after_sale') ?></td>
                                    <td>
                                        <?= form_checkbox([
                                            'name'    => 'recv_print_after_sale',
                                            'id'      => 'recv_print_after_sale',
                                            'class'   => 'checkbox',
                                            'value'   => 1,
                                            'checked' => $print_after_sale == 1,
                                        ]) ?>
                                    </td>
                                </tr>
                                <?php if ($mode == 'receive') { ?>
                                    <tr>
                                        <td><?= lang(ucfirst($controller_name) . '.reference') ?></td>
                                        <td>
                                            <?= form_input([
                                                'name'  => 'recv_reference',
                                                'id'    => 'recv_reference',
                                                'class' => 'form-control input-sm',
                                                'value' => $reference,
                                                'size'  => 5,
                                            ]) ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                <tr>
                                    <td><?= lang('Sales.payment') ?></td>
                                    <td>
                                        <?= form_dropdown(
                                            'payment_type',
                                            $payment_options,
                                            [],
                                            [
                                                'id'         => 'payment_types',
                                                'class'      => 'selectpicker show-menu-arrow',
                                                'data-style' => 'btn-default btn-sm',
                                                'data-width' => 'auto',
                                            ],
                                        ) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><?= lang('Sales.amount_tendered') ?></td>
                                    <td>
                                        <?= form_input([
                                            'name'  => 'amount_tendered',
                                            'value' => '',
                                            'class' => 'form-control input-sm',
                                            'size'  => '5',
                                        ]) ?>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="btn btn-sm btn-danger pull-left" id="cancel_receiving_button">
                            <span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang(ucfirst($controller_name) . '.cancel_receiving') ?>
                        </div>
                        <div class="btn btn-sm btn-success pull-right" id="finish_receiving_button">
                            <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang(ucfirst($controller_name) . '.complete_receiving') ?>
                        </div>
                    </div>

                    <?= form_close() ?>

                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        let addItemInFlight = false;
        const pendingItemScans = [];
        const maxPendingItemScans = 20;
        const receivingRecoveryNoticeKey = 'ospos_receivings_recovery_notice';
        let receivingRecoveryInProgress = false;
        let focusItemAfterAdd = true;
        let deferredReceivingAction = null;
        let runningDeferredReceivingAction = false;
        let lastModeFormValues = {};
        const itemAddFailureMessage = <?= json_encode(lang(ucfirst($controller_name) . '.unable_to_add_item')) ?>;
        const receivingReloadedMessage = <?= json_encode(lang(ucfirst($controller_name) . '.register_reloaded_scan_again')) ?>;
        const queuedScansMessageTemplate = <?= json_encode(lang(ucfirst($controller_name) . '.queued_scans_not_submitted')) ?>;
        const pendingScansActionMessage = <?= json_encode(lang(ucfirst($controller_name) . '.pending_scans_action_wait')) ?>;
        const pendingReceivingActionMessage = <?= json_encode(lang(ucfirst($controller_name) . '.pending_receiving_action')) ?>;
        const pendingActionWaitForItemMessage = <?= json_encode(lang(ucfirst($controller_name) . '.pending_action_wait_for_item')) ?>;
        const failedAddCancelledActionMessage = <?= json_encode(lang(ucfirst($controller_name) . '.failed_add_cancelled_action')) ?>;
        const changedLineCancelledActionMessage = <?= json_encode(lang(ucfirst($controller_name) . '.changed_line_cancelled_action')) ?>;

        /**
         * Returns true while an add or queued scan can still change the server cart.
         */
        const receivingHasPendingScans = function() {
            return addItemInFlight || pendingItemScans.length > 0;
        };

        /**
         * Saves operator values with the server values and cart item identity they started from.
         */
        const captureInputState = function($inputs, onlyChanged = false) {
            const seenNames = {};
            const inputState = [];

            $inputs.each(function() {
                const name = this.name || '';
                const formId = this.form ? this.form.id : '';
                const stateKey = formId + ':' + name;
                const nameIndex = seenNames[stateKey] || 0;
                seenNames[stateKey] = nameIndex + 1;
                const isChoice = this.type === 'checkbox' || this.type === 'radio';
                const isSelect = this.tagName.toLowerCase() === 'select';
                const hasChanged = isChoice
                    ? this.checked !== this.defaultChecked
                    : isSelect
                        ? Array.from(this.options).some(function(option) {
                            return option.selected !== option.defaultSelected;
                        })
                        : this.value !== this.defaultValue;

                if (onlyChanged && !hasChanged) {
                    return;
                }

                inputState.push({
                    id: this.id || '',
                    name: name,
                    formId: formId,
                    nameIndex: nameIndex,
                    type: this.type || this.tagName.toLowerCase(),
                    value: $(this).val(),
                    checked: this.checked,
                    serverValue: this.type === 'checkbox' || this.type === 'radio'
                        ? this.defaultChecked
                        : this.tagName.toLowerCase() === 'select'
                            ? Array.from(this.options).filter(function(option) {
                                return option.defaultSelected;
                            }).map(function(option) {
                                return option.value;
                            })
                            : this.defaultValue,
                    itemId: this.form ? this.form.getAttribute('data-item-id') || '' : '',
                });
            });

            return inputState;
        };

        /**
         * Returns the value the server last sent for a field.
         */
        const fieldServerValue = function(field) {
            if (field.type === 'checkbox' || field.type === 'radio') {
                return field.defaultChecked;
            }

            if (field.tagName.toLowerCase() === 'select') {
                return Array.from(field.options).filter(function(option) {
                    return option.defaultSelected;
                }).map(function(option) {
                    return option.value;
                });
            }

            return field.defaultValue;
        };

        /**
         * Checks whether the server changed a field the operator also changed in a delayed line edit.
         */
        const lineEditConflictsWithServer = function(form, inputState) {
            return inputState.some(function(state) {
                const operatorChanged = state.type === 'checkbox' || state.type === 'radio'
                    ? state.checked !== state.serverValue
                    : JSON.stringify(state.value) !== JSON.stringify(state.serverValue);
                if (!operatorChanged || state.name === '') {
                    return false;
                }

                const field = Array.from(form.elements).filter(function(element) {
                    return element.name === state.name;
                })[state.nameIndex];

                return field !== undefined && JSON.stringify(fieldServerValue(field)) !== JSON.stringify(state.serverValue);
            });
        };

        /**
         * Restores saved values to matching fields, optionally only while the server value is unchanged.
         */
        const restoreInputState = function($container, inputState, onlyIfServerValueMatches = false) {
            const $inputs = $container.filter(':input').add($container.find(':input'));

            inputState.forEach(function(state) {
                let $matches = $();

                if (state.formId !== '' && state.name !== '') {
                    $matches = $inputs.filter(function() {
                        return this.name === state.name && (this.form ? this.form.id : '') === state.formId;
                    });
                } else if (state.id !== '') {
                    $matches = $inputs.filter(function() {
                        return this.id === state.id;
                    });
                } else if (state.name !== '') {
                    $matches = $inputs.filter(function() {
                        return this.name === state.name && (this.form ? this.form.id : '') === state.formId;
                    });
                }

                const $field = $matches.eq(state.nameIndex);
                if (!$field.length) {
                    return;
                }

                const field = $field[0];
                if (state.itemId !== '' && (!field.form || field.form.getAttribute('data-item-id') !== state.itemId)) {
                    return;
                }

                if (onlyIfServerValueMatches && JSON.stringify(fieldServerValue(field)) !== JSON.stringify(state.serverValue)) {
                    return;
                }

                if (state.type === 'checkbox' || state.type === 'radio') {
                    if ($field.attr('data-toggle') === 'toggle') {
                        $field.bootstrapToggle('destroy');
                        $field.prop('checked', state.checked);
                        $field.bootstrapToggle();
                    } else {
                        $field.prop('checked', state.checked);
                    }
                } else {
                    $field.val(state.value);
                }

                if ($field.hasClass('selectpicker')) {
                    $field.selectpicker('refresh');
                }

            });
        };

        /**
         * Records the focused input and cursor so a fragment swap can restore them.
         */
        const captureReceivingFocus = function() {
            const activeElement = document.activeElement;
            const $activeElement = $(activeElement);
            const root = $activeElement.closest('#register_wrapper, #overall_sale');

            if (!root.length || !activeElement.name && !activeElement.id) {
                return null;
            }

            let selectionStart = null;
            let selectionEnd = null;
            let selectionDirection = null;
            try {
                selectionStart = activeElement.selectionStart;
                selectionEnd = activeElement.selectionEnd;
                selectionDirection = activeElement.selectionDirection;
            } catch (error) {
                // Some controls do not expose a text selection.
            }

            return {
                rootId: root.attr('id'),
                id: activeElement.id || '',
                name: activeElement.name || '',
                formId: activeElement.form ? activeElement.form.id : '',
                selectionStart: selectionStart,
                selectionEnd: selectionEnd,
                selectionDirection: selectionDirection,
            };
        };

        /**
         * Restores focus and text selection to the matching control after a fragment swap.
         */
        const restoreReceivingFocus = function(focusState) {
            if (!focusState) {
                $('#item').focus();

                return;
            }

            const $root = $('#' + focusState.rootId);
            let $field = $();

            if (focusState.formId !== '' && focusState.name !== '') {
                $field = $root.find(':input').filter(function() {
                    return this.name === focusState.name && (this.form ? this.form.id : '') === focusState.formId;
                }).first();
            }

            if (!$field.length && focusState.id !== '') {
                $field = $root.find(':input').filter(function() {
                    return this.id === focusState.id;
                }).first();
            }

            if (!$field.length && focusState.name !== '') {
                $field = $root.find(':input').filter(function() {
                    return this.name === focusState.name && (this.form ? this.form.id : '') === focusState.formId;
                }).first();
            }

            if (!$field.length) {
                $('#item').focus();

                return;
            }

            $field.trigger('focus');
            const field = $field[0];
            if (typeof field.setSelectionRange === 'function' && focusState.selectionStart !== null && focusState.selectionEnd !== null) {
                try {
                    field.setSelectionRange(focusState.selectionStart, focusState.selectionEnd, focusState.selectionDirection || 'none');
                } catch (error) {
                    // The saved control may no longer allow a text selection.
                }
            }
        };

        /**
         * Reads mode and location values before a form submit is delayed behind scans.
         */
        const readModeFormValues = function() {
            const values = {};
            $('#mode_form').serializeArray().forEach(function(field) {
                values[field.name] = field.value;
            });

            return values;
        };

        /**
         * Restores the last server-confirmed mode and location selections.
         */
        const restoreModeFormValues = function(values) {
            Object.keys(values).forEach(function(name) {
                const $field = $('#mode_form').find(':input').filter(function() {
                    return this.name === name;
                });
                $field.val(values[name]);
                if ($field.hasClass('selectpicker')) {
                    $field.selectpicker('refresh');
                }
            });
        };

        /**
         * Queues one form or navigation action until all item adds have finished.
         */
        const deferReceivingAction = function(action) {
            if (runningDeferredReceivingAction) {
                return false;
            }

            if (!receivingHasPendingScans() && deferredReceivingAction === null && !receivingRecoveryInProgress) {
                return false;
            }

            if (deferredReceivingAction !== null) {
                $.notify({ message: pendingReceivingActionMessage }, { type: 'warning' });

                return true;
            }

            deferredReceivingAction = action;
            $.notify({ message: pendingScansActionMessage }, { type: 'info' });

            return true;
        };

        /**
         * Reloads Receivings when its cart may be out of sync and records any dropped scans.
         */
        const recoverReceiving = function(additionalDiscardedScans = 0) {
            if (receivingRecoveryInProgress) {
                return;
            }

            receivingRecoveryInProgress = true;
            const discardedScanCount = pendingItemScans.length + additionalDiscardedScans;
            pendingItemScans.length = 0;
            deferredReceivingAction = null;
            const queuedScanMessage = discardedScanCount > 0
                ? ' ' + queuedScansMessageTemplate.replace('{0}', discardedScanCount)
                : '';
            const recoveryMessage = [itemAddFailureMessage, receivingReloadedMessage + queuedScanMessage].join(' ');

            try {
                sessionStorage.setItem(receivingRecoveryNoticeKey, recoveryMessage);
            } catch (error) {
                // The visible notification below still warns the user if storage is unavailable.
            }

            $.notify({ message: recoveryMessage }, { type: 'danger' });
            window.location.replace("<?= site_url('receivings') ?>");
        };

        /**
         * Shows the warning saved before the last authoritative Receivings reload.
         */
        const showReceivingRecoveryNotice = function() {
            let recoveryMessage = null;

            try {
                recoveryMessage = sessionStorage.getItem(receivingRecoveryNoticeKey);
                sessionStorage.removeItem(receivingRecoveryNoticeKey);
            } catch (error) {
                return;
            }

            if (recoveryMessage) {
                $.notify({ message: recoveryMessage }, { type: 'danger' });
            }
        };

        /**
         * Runs a saved action after scans settle and drops line actions if their item moved.
         */
        const runDeferredReceivingAction = function() {
            if (deferredReceivingAction === null || receivingHasPendingScans() || receivingRecoveryInProgress) {
                return;
            }

            if (String($('#item').val() || '').trim() !== '') {
                if (!deferredReceivingAction.waitingForItem) {
                    deferredReceivingAction.waitingForItem = true;
                    $.notify({ message: pendingActionWaitForItemMessage }, { type: 'warning' });
                }

                return;
            }

            if ($('.modal:visible').length > 0) {
                return;
            }

            const action = deferredReceivingAction;
            deferredReceivingAction = null;

            if ((action.type === 'edit' || action.type === 'delete') && !isReceivingLineStillCurrent(action)) {
                $.notify({ message: changedLineCancelledActionMessage }, { type: 'warning' });

                return;
            }

            runningDeferredReceivingAction = true;

            if (action.type === 'mode') {
                restoreModeFormValues(action.values);
                $('#mode_form').trigger('submit');
            } else if (action.type === 'finish') {
                $('#finish_receiving_form').trigger('submit');
            } else if (action.type === 'cancel') {
                if (confirm('<?= lang(ucfirst($controller_name) . '.confirm_cancel_receiving') ?>')) {
                    $('#finish_receiving_form').attr('action', '<?= esc("{$controller_name}/cancelReceiving") ?>').trigger('submit');
                }
            } else if (action.type === 'edit') {
                const form = document.getElementById(action.formId);
                if (form && lineEditConflictsWithServer(form, action.values)) {
                    $.notify({ message: changedLineCancelledActionMessage }, { type: 'warning' });
                } else if (form) {
                    // The discount toggle adds discount_type on the fly; the swapped-in form no longer has it.
                    action.values.forEach(function(state) {
                        if (state.name === 'discount_type' && form.elements.namedItem('discount_type') === null) {
                            $(form).append($('<input>').attr('type', 'hidden').attr('name', 'discount_type').val(state.value));
                        }
                    });
                    // Keep values a queued scan changed on the server, such as a merged quantity.
                    restoreInputState($(form.elements), action.values, true);
                    $(form).trigger('submit');
                }
            } else if (action.type === 'delete') {
                window.location.href = action.href;
            }

            runningDeferredReceivingAction = false;
        };

        /**
         * Checks the add response, replaces cart fragments, and keeps only still-valid operator values.
         */
        const renderAddItemResponse = function(response) {
            if (typeof response !== 'string') {
                recoverReceiving();

                return false;
            }

            const scanBuffer = $('#item').val();
            const focusState = captureReceivingFocus();
            focusItemAfterAdd = !focusState || focusState.id === 'item';
            const cartInputState = captureInputState($('#cart_contents :input'), true);
            const overallSaleInputState = captureInputState($('#overall_sale :input'), true);
            const responseDocument = document.implementation.createHTMLDocument('receiving-response');
            responseDocument.documentElement.innerHTML = response;
            const $response = $(responseDocument);
            const $register = $response.find('#register_wrapper');
            const $sale = $response.find('#overall_sale');
            const $item = $response.find('#add_item_form #item');
            const $message = $response.find('.alert-danger, .alert-warning, .alert-success').first();
            const addHasErrorOrWarning = $response.find('.alert-danger, .alert-warning').length > 0;

            if ($register.length !== 1 || $sale.length !== 1 || $item.length !== 1 || !$('#register_wrapper').length || !$('#overall_sale').length) {
                recoverReceiving();

                return false;
            }

            $('#register_wrapper').prevAll('.alert').remove();
            if ($message.length) {
                $('#register_wrapper').before($message.clone());
            }
            $('#register_wrapper').replaceWith($register);
            $('#overall_sale').replaceWith($sale);

            bindReceivingHandlers();
            restoreInputState($('#register_wrapper'), cartInputState, true);
            restoreInputState($('#overall_sale'), overallSaleInputState, true);
            $('#item').val(scanBuffer);
            restoreReceivingFocus(focusState);

            if (addHasErrorOrWarning && deferredReceivingAction !== null) {
                deferredReceivingAction = null;
                $.notify({ message: failedAddCancelledActionMessage }, { type: 'warning' });
            }

            return true;
        };

        /**
         * Checks that a delayed line action still points to the same cart line and item.
         */
        const isReceivingLineStillCurrent = function(action) {
            const form = document.getElementById('cart_' + action.line);

            return action.line !== '' && action.itemId !== '' && form !== null
                && form.getAttribute('data-line') === action.line
                && form.getAttribute('data-item-id') === action.itemId;
        };

        /**
         * Sends one item through the background add path and queues scans that arrive while it runs.
         */
        const submitItemScan = function(item, scanBuffer = '') {
            const itemValue = String(item).trim();
            if (itemValue === '' || receivingRecoveryInProgress) {
                return;
            }

            if (addItemInFlight) {
                if (pendingItemScans.length >= maxPendingItemScans) {
                    recoverReceiving(1);

                    return;
                }

                pendingItemScans.push(itemValue);
                if (String($('#item').val() || '').trim() === itemValue) {
                    $('#item').val('');
                }

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
                    recoverReceiving();
                },
                complete: function() {
                    if (receivingRecoveryInProgress) {
                        return;
                    }

                    addItemInFlight = false;
                    if (pendingItemScans.length > 0) {
                        const nextScan = pendingItemScans.shift();
                        submitItemScan(nextScan, $('#item').val());
                    } else {
                        if (deferredReceivingAction !== null) {
                            runDeferredReceivingAction();
                        } else if (focusItemAfterAdd) {
                            $('#item').focus();
                        }
                    }
                }
            });
        };

        /**
         * Binds Receivings controls again after the returned cart fragments replace the old ones.
         */
        const bindReceivingHandlers = function() {
            $('#mode_form').off('submit.receiving').on('submit.receiving', function(event) {
                if (runningDeferredReceivingAction) {
                    return true;
                }

                const requestedValues = readModeFormValues();
                if (deferReceivingAction({ type: 'mode', values: requestedValues })) {
                    event.preventDefault();
                    restoreModeFormValues(lastModeFormValues);

                    return false;
                }

                return true;
            });

            $('#register_wrapper').off('submit.receiving', 'form[id^="cart_"]').on('submit.receiving', 'form[id^="cart_"]', function(event) {
                if (runningDeferredReceivingAction) {
                    return true;
                }

                const action = {
                    type: 'edit',
                    formId: this.id,
                    line: this.getAttribute('data-line') || '',
                    itemId: this.getAttribute('data-item-id') || '',
                    values: captureInputState($(this.elements)),
                };
                if (deferReceivingAction(action)) {
                    event.preventDefault();

                    return false;
                }

                return true;
            });

            $('#register_wrapper a[href*="deleteItem"]').off('click.receiving').on('click.receiving', function(event) {
                const match = this.href.match(/\/deleteItem\/([^/?#]+)/);
                const line = match ? match[1] : '';
                const form = line === '' ? null : document.getElementById('cart_' + line);
                const action = {
                    type: 'delete',
                    href: this.href,
                    line: form ? form.getAttribute('data-line') || '' : '',
                    itemId: form ? form.getAttribute('data-item-id') || '' : '',
                };
                if (deferReceivingAction(action)) {
                    event.preventDefault();

                    return false;
                }

                return true;
            });

            $('#finish_receiving_form').off('submit.receiving').on('submit.receiving', function(event) {
                if (runningDeferredReceivingAction) {
                    return true;
                }

                const isCancel = this.action.indexOf('/cancelReceiving') !== -1;
                const action = { type: isCancel ? 'cancel' : 'finish' };
                if (deferReceivingAction(action)) {
                    event.preventDefault();

                    return false;
                }

                return true;
            });

            $('#add_item_form').off('submit.receiving').on('submit.receiving', function(event) {
                event.preventDefault();
                submitItemScan($('#item').val());

                return false;
            });

            $('#item').autocomplete({
                source: '<?= esc("{$controller_name}/stockItemSearch") ?>',
                minLength: 0,
                delay: 10,
                autoFocus: false,
                select: function(event, ui) {
                    $(this).val(ui.item.value);
                    submitItemScan(ui.item.value);

                    return false;
                }
            });

            $('#item').off('keypress.receiving').on('keypress.receiving', function(event) {
                if (event.which == 13) {
                    submitItemScan($(this).val());

                    return false;
                }
            });

            $('#item').off('input.receiving').on('input.receiving', function() {
                if (deferredReceivingAction !== null) {
                    runDeferredReceivingAction();
                }
            });

            $('#item').off('dblclick.receiving').on('dblclick.receiving', function() {
                $(this).autocomplete('search');
            });

            $('#comment').off('keyup.receiving').on('keyup.receiving', function() {
                $.post('<?= esc("{$controller_name}/setComment") ?>', {
                    comment: $('#comment').val()
                });
            });

            $('#recv_reference').off('keyup.receiving').on('keyup.receiving', function() {
                $.post('<?= esc("{$controller_name}/setReference") ?>', {
                    recv_reference: $('#recv_reference').val()
                });
            });

            $('#recv_print_after_sale').off('change.receiving').on('change.receiving', function() {
                $.post('<?= esc("{$controller_name}/setPrintAfterSale") ?>', {
                    recv_print_after_sale: $(this).is(':checked')
                });
            });

            $('#finish_receiving_button').off('click.receiving').on('click.receiving', function() {
                if (deferReceivingAction({ type: 'finish' })) {
                    return false;
                }

                $('#finish_receiving_form').trigger('submit');
            });

            $('#cancel_receiving_button').off('click.receiving').on('click.receiving', function() {
                if (deferReceivingAction({ type: 'cancel' })) {
                    return false;
                }

                if (confirm('<?= lang(ucfirst($controller_name) . '.confirm_cancel_receiving') ?>')) {
                    $('#finish_receiving_form').attr('action', '<?= esc("{$controller_name}/cancelReceiving") ?>');
                    $('#finish_receiving_form').trigger('submit');
                }
            });

            $('#cart_contents input').off('keypress.receiving').on('keypress.receiving', function(event) {
                if (event.which == 13) {
                    $(this).parents('tr').prevAll('form:first').submit();
                }
            });

            $('[name="price"],[name="quantity"],[name="receiving_quantity"],[name="discount"],[name="description"],[name="serialnumber"]')
                .off('change.receiving')
                .on('change.receiving', function() {
                    $(this).parents('tr').prevAll('form:first').submit();
                });

            $('[name="discount_toggle"]').off('change.receiving').on('change.receiving', function() {
                const input = $('<input>').attr('type', 'hidden').attr('name', 'discount_type').val($(this).prop('checked') ? 1 : 0);
                $('#cart_' + $(this).attr('data-line')).append(input);
                $('#cart_' + $(this).attr('data-line')).submit();
            });

            $('.selectpicker').selectpicker();
            // Only the checkboxes: the plugin wrapper div also carries data-toggle="toggle", and setting it
            // up again on a page load nests a second switch with the default On/Off labels (issue #212).
            $('input[type=checkbox][data-toggle="toggle"]').bootstrapToggle();
            dialog_support.init('a.modal-dlg, button.modal-dlg');
            $(document).off('hidden.bs.modal.receiving').on('hidden.bs.modal.receiving', function() {
                if (deferredReceivingAction !== null) {
                    runDeferredReceivingAction();
                }
            });
        };

        /**
         * Adds a newly created item through the same queued path as a barcode scan.
         */
        table_support.handle_submit = function(resource, response, stay_open) {
            if (response.success) {
                submitItemScan(response.id, $('#item').val());
            }
        };

        showReceivingRecoveryNotice();
        lastModeFormValues = readModeFormValues();
        bindReceivingHandlers();
        $('#item').focus();
    });
</script>

<?= view('partial/footer') ?>
