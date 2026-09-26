<?php
/**
 * @var array      $config
 * @var array      $item
 * @var int|string $line
 * @var int        $tabindex
 */
$discount_type = (int) ($item['discount_type'] ?? 0);
$discount_lbp  = round_lbp_to_whole_pound((float) ($item['discount'] ?? 0) * (float) ($config['lbp_exchange_rate'] ?? 0));
?>
<td>
    <div class="input-group">
        <?= form_input([
            'name'     => 'discount',
            'class'    => 'form-control input-sm',
            'value'    => $discount_type ? format_lbp_input($discount_lbp) : to_decimals($item['discount']),
            'tabindex' => $tabindex,
            'onClick'  => 'this.select();',
            'form'     => "cart_{$line}",
        ]) ?>
        <?= form_input([
            'type'  => 'hidden',
            'name'  => 'discount_type',
            'value' => (string) $discount_type,
            'form'  => "cart_{$line}",
        ]) ?>
        <span class="input-group-btn">
            <?= form_checkbox([
                'id'           => "discount_toggle_{$line}",
                'name'         => 'discount_toggle',
                'value'        => 1,
                'data-toggle'  => 'toggle',
                'data-size'    => 'small',
                'data-onstyle' => 'success',
                'data-on'      => '<b>LL</b>',
                'data-off'     => '<b>%</b>',
                'data-line'    => $line,
                'form'         => "cart_{$line}",
                'checked'      => (int) $item['discount_type'] === 1,
            ]) ?>
        </span>
    </div>
</td>
