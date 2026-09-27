<?php
/**
 * @var array      $item
 * @var int|string $line
 * @var int        $tabindex
 */
$quantity       = (float) $item['quantity'];
$quantity_minus = max(1, $quantity - 1);
$quantity_plus  = $quantity + 1;
?>
<div class="input-group restaurant-line-stepper" dir="ltr">
    <button
        type="button"
        class="btn btn-default restaurant-line-step"
        data-step-target="quantity"
        data-step-value="<?= esc(to_quantity_decimals((string) $quantity_minus)) ?>"
        aria-label="<?= esc(lang('Sales.quantity_decrease_step')) ?>"
        title="<?= esc(lang('Sales.quantity_decrease_step')) ?>"<?= $quantity <= 1 ? ' disabled' : '' ?>>−</button>
    <?= form_input([
        'name'     => 'quantity',
        'class'    => 'form-control input-sm',
        'value'    => to_quantity_decimals($item['quantity']),
        'tabindex' => $tabindex,
        'onClick'  => 'this.select();',
        'form'     => "cart_{$line}",
    ]) ?>
    <button
        type="button"
        class="btn btn-default restaurant-line-step"
        data-step-target="quantity"
        data-step-value="<?= esc(to_quantity_decimals((string) $quantity_plus)) ?>"
        aria-label="<?= esc(lang('Sales.quantity_increase_step')) ?>"
        title="<?= esc(lang('Sales.quantity_increase_step')) ?>">+</button>
</div>
