<?php
/**
 * @var array      $config
 * @var array      $item
 * @var int|string $line
 * @var int        $tabindex
 */
?>
<td>
    <div class="input-group">
        <?= form_input([
            'name'     => 'discount',
            'class'    => 'form-control input-sm',
            'value'    => $item['discount_type'] ? to_currency_no_money($item['discount']) : to_decimals($item['discount']),
            'tabindex' => $tabindex,
            'onClick'  => 'this.select();',
            'form'     => "cart_{$line}",
        ]) ?>
        <span class="input-group-btn">
            <?= form_checkbox([
                'id'           => "discount_toggle_{$line}",
                'name'         => 'discount_toggle',
                'value'        => 1,
                'data-toggle'  => 'toggle',
                'data-size'    => 'small',
                'data-onstyle' => 'success',
                'data-on'      => '<b>' . $config['currency_symbol'] . '</b>',
                'data-off'     => '<b>%</b>',
                'data-line'    => $line,
                'form'         => "cart_{$line}",
                'checked'      => (int) $item['discount_type'] === 1,
            ]) ?>
        </span>
    </div>
</td>
