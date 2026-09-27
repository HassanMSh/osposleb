<?php
/**
 * @var array      $config
 * @var array      $item
 * @var int|string $line
 * @var int        $tabindex
 */
$discount_type  = (int) ($item['discount_type'] ?? 0);
$discount       = (float) ($item['discount'] ?? 0);
$discount_lbp   = round_lbp_to_thousand((float) ($item['discount'] ?? 0) * (float) ($config['lbp_exchange_rate'] ?? 0));
$discount_minus = max(0, $discount - 5);
$discount_plus  = min(100, $discount + 5);
?>
<td>
    <div class="input-group restaurant-discount-control" dir="ltr">
        <?php if ($discount_type === PERCENT) { ?>
            <button
                type="button"
                class="btn btn-default restaurant-line-step"
                data-step-target="discount"
                data-step-value="<?= esc(to_decimals((string) $discount_minus)) ?>"
                aria-label="<?= esc(lang('Sales.discount_decrease_step')) ?>"
                title="<?= esc(lang('Sales.discount_decrease_step')) ?>"<?= $discount <= 0 ? ' disabled' : '' ?>>−</button>
        <?php } ?>
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
        <?php if ($discount_type === PERCENT) { ?>
            <button
                type="button"
                class="btn btn-default restaurant-line-step"
                data-step-target="discount"
                data-step-value="<?= esc(to_decimals((string) $discount_plus)) ?>"
                aria-label="<?= esc(lang('Sales.discount_increase_step')) ?>"
                title="<?= esc(lang('Sales.discount_increase_step')) ?>"<?= $discount >= 100 ? ' disabled' : '' ?>>+</button>
        <?php } ?>
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
