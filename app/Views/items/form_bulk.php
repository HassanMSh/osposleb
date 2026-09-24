<?php
/**
 * @var array  $suppliers
 * @var array  $allow_alt_description_choices
 * @var array  $serialization_choices
 * @var string $controller_name
 * @var array  $config
 */
?>

<div id="required_fields_message"><?= lang('Items.edit_fields_you_want_to_update') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('items/bulkUpdate/', ['id' => 'item_form', 'class' => 'form-horizontal']) ?>
    <fieldset id="bulk_item_basic_info">

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.name'), 'name', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_input([
                    'name'  => 'name',
                    'id'    => 'name',
                    'class' => 'form-control input-sm',
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.category'), 'category', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <div class="input-group">
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-tag"></span></span>
                    <?= form_input([
                        'name'  => 'category',
                        'id'    => 'category',
                        'class' => 'form-control input-sm',
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.supplier'), 'supplier', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_dropdown('supplier_id', $suppliers, '', ['class' => 'form-control']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.cost_price'), 'cost_price', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'cost_price',
                        'id'    => 'cost_price',
                        'class' => 'form-control input-sm',
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group">
            <?= form_label(lang('Items.unit_price'), 'unit_price', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'unit_price',
                        'id'    => 'unit_price',
                        'class' => 'form-control input-sm',
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.tax_1'), 'tax_percent_1', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <?= form_input([
                    'name'  => 'tax_names[]',
                    'id'    => 'tax_name_1',
                    'class' => 'form-control input-sm',
                    'value' => $config['default_tax_1_name'],
                ]) ?>
            </div>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'  => 'tax_percents[]',
                        'id'    => 'tax_percent_name_1',
                        'class' => 'form-control input-sm',
                        'value' => to_tax_decimals($config['default_tax_1_rate']),
                    ]) ?>
                    <span class="input-group input-group-addon"><b>%</b></span>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.tax_2'), 'tax_percent_2', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <?= form_input([
                    'name'  => 'tax_names[]',
                    'id'    => 'tax_name_2',
                    'class' => 'form-control input-sm',
                    'value' => $config['default_tax_2_name'],
                ]) ?>
            </div>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'  => 'tax_percents[]',
                        'id'    => 'tax_percent_name_2',
                        'class' => 'form-control input-sm',
                        'value' => to_tax_decimals($config['default_tax_2_rate']),
                    ]) ?>
                    <span class="input-group input-group-addon"><b>%</b></span>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.reorder_level'), 'reorder_level', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <?= form_input([
                    'name'  => 'reorder_level',
                    'id'    => 'reorder_level',
                    'class' => 'form-control input-sm',
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.description'), 'description', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control input-sm',
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.allow_alt_description'), 'allow_alt_description', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_dropdown('allow_alt_description', $allow_alt_description_choices, '', ['class' => 'form-control']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.is_serialized'), 'is_serialized', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_dropdown('is_serialized', $serialization_choices, '', ['class' => 'form-control']) ?>
            </div>
        </div>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $.validator.addMethod('maxItemNameLength', function(value, element) {
            return Array.from(value).length <= 255;
        }, "<?= esc(lang('Items.name_max_length'), 'js') ?>");

        $.validator.addMethod('nonNegativePrice', function(value, element) {
            var trimmed_value = value.trim();

            if (trimmed_value === '') {
                return true;
            }

            var locale_minus_sign = new Intl.NumberFormat().formatToParts(-1).find(function(part) {
                return part.type === 'minusSign';
            }).value;
            var minus_signs = ['-', '−', locale_minus_sign];
            var matched_minus_sign = minus_signs.find(function(sign) {
                return trimmed_value.startsWith(sign);
            });

            if (matched_minus_sign === undefined) {
                return true;
            }

            var price_digits = trimmed_value.slice(matched_minus_sign.length).replace(/[.,\s\u00a0\u202f]/g, '');

            if (!/^\d+$/.test(price_digits)) {
                return true;
            }

            return !/[1-9]/.test(price_digits);
        }, "<?= esc(lang('Items.unit_price_non_negative'), 'js') ?>");

        $('#category').autocomplete({
            source: "<?= 'items/suggestCategory' ?>",
            appendTo: '.modal-content',
            delay: 10
        });

        var confirm_message = false;
        $('#tax_percent_name_2, #tax_name_2').prop('disabled', true),
            $('#tax_percent_name_1, #tax_name_1').blur(function() {
                var disabled = !($('#tax_percent_name_1').val() + $('#tax_name_1').val());
                $('#tax_percent_name_2, #tax_name_2').prop('disabled', disabled);
                confirm_message = disabled ? '' : "<?= lang('Items.confirm_bulk_edit_wipe_taxes') ?>";
            });

        $('#item_form').validate($.extend({
            submitHandler: function(form) {
                if (!confirm_message || confirm(confirm_message)) {
                    $(form).ajaxSubmit({
                        beforeSubmit: function(arr, $form, options) {
                            arr.push({
                                name: 'item_ids',
                                value: table_support.selected_ids().join(":")
                            });
                        },
                        success: function(response) {
                            dialog_support.hide();
                            table_support.handle_submit("<?= esc($controller_name) ?>", response);
                        },
                        dataType: 'json'
                    });
                }
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: {
                    maxItemNameLength: true
                },
                cost_price: {
                    nonNegativePrice: true,
                    remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                },
                unit_price: {
                    number: true,
                    nonNegativePrice: true,
                    remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                },
                tax_percent: {
                    number: true
                },
                quantity: {
                    number: true
                },
                reorder_level: {
                    number: true
                }
            },

            messages: {
                name: {
                    maxItemNameLength: "<?= esc(lang('Items.name_max_length'), 'js') ?>"
                },
                cost_price: {
                    number: "<?= lang('Items.cost_price_number') ?>",
                    remote: "<?= lang('Items.cost_price_number') ?>",
                    nonNegativePrice: "<?= esc(lang('Items.cost_price_non_negative'), 'js') ?>"
                },
                unit_price: {
                    number: "<?= lang('Items.unit_price_number') ?>",
                    remote: "<?= lang('Items.unit_price_number') ?>",
                    nonNegativePrice: "<?= esc(lang('Items.unit_price_non_negative'), 'js') ?>"
                },
                tax_percent: {
                    number: "<?= lang('Items.tax_percent_number') ?>"
                },
                quantity: {
                    number: "<?= lang('Items.quantity_number') ?>"
                },
                reorder_level: {
                    number: "<?= lang('Items.reorder_level_number') ?>"
                }
            }
        }, form_support.error));
    });
</script>
