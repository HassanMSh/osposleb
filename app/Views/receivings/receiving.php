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
                <?= form_input(['name' => 'item', 'id' => 'item', 'class' => 'form-control input-sm', 'size' => '50', 'tabindex' => '1']) ?>
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

                    <?= form_open("{$controller_name}/editItem/{$line}", ['class' => 'form-horizontal', 'id' => "cart_{$line}"]) ?>

                    <tr>
                        <td><?= anchor("{$controller_name}/deleteItem/{$line}", '<span class="glyphicon glyphicon-trash"></span>') ?></td>
                        <td><?= esc($item['item_number']) ?></td>
                        <td style="text-align: center;">
                            <?= esc($item['name'] . ' ' . implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?><br>
                            <?= '[' . to_quantity_decimals($item['in_stock']) . ' in ' . esc($item['stock_name']) . ']' ?>
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
        $("#item").autocomplete({
            source: '<?= esc("{$controller_name}/stockItemSearch") ?>',
            minChars: 0,
            delay: 10,
            autoFocus: false,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $("#add_item_form").submit();
                return false;
            }
        });

        $('#item').focus();

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item').blur(function() {
            $(this).attr('value', "<?= lang('Sales.start_typing_item_name') ?>");
        });

        $('#comment').keyup(function() {
            $.post('<?= esc("{$controller_name}/setComment") ?>', {
                comment: $('#comment').val()
            });
        });

        $('#recv_reference').keyup(function() {
            $.post('<?= esc("{$controller_name}/setReference") ?>', {
                recv_reference: $('#recv_reference').val()
            });
        });

        $("#recv_print_after_sale").change(function() {
            $.post('<?= esc("{$controller_name}/setPrintAfterSale") ?>', {
                recv_print_after_sale: $(this).is(":checked")
            });
        });

        $('#item').click(function() {
            $(this).attr('value', '');
        });

        dialog_support.init("a.modal-dlg, button.modal-dlg");

        $("#finish_receiving_button").click(function() {
            $('#finish_receiving_form').submit();
        });

        $("#cancel_receiving_button").click(function() {
            if (confirm('<?= lang(ucfirst($controller_name) . '.confirm_cancel_receiving') ?>')) {
                $('#finish_receiving_form').attr('action', '<?= esc("{$controller_name}/cancelReceiving") ?>');
                $('#finish_receiving_form').submit();
            }
        });

        $("#cart_contents input").keypress(function(event) {
            if (event.which == 13) {
                $(this).parents("tr").prevAll("form:first").submit();
            }
        });

        table_support.handle_submit = function(resource, response, stay_open) {
            if (response.success) {
                $("#item").val(response.id);
                if (stay_open) {
                    $("#add_item_form").ajaxSubmit();
                } else {
                    $("#add_item_form").submit();
                }
            }
        }

        $('[name="price"],[name="quantity"],[name="receiving_quantity"],[name="discount"],[name="description"],[name="serialnumber"]').change(function() {
            $(this).parents("tr").prevAll("form:first").submit()
        });

        $('[name="discount_toggle"]').change(function() {
            var input = $("<input>").attr("type", "hidden").attr("name", "discount_type").val(($(this).prop('checked')) ? 1 : 0);
            $('#cart_' + $(this).attr('data-line')).append($(input));
            $('#cart_' + $(this).attr('data-line')).submit();
        });

    });
</script>

<?= view('partial/footer') ?>
