<?php
/**
 * @var array      $cart
 * @var string     $comments
 * @var string     $language_code
 * @var int|string $sale_id_num
 * @var string     $transaction_time
 */
$ticket_lines = [];

foreach ($cart as $cart_line => $item) {
    $ticket_lines[] = [
        'line' => (int) ($item['line'] ?? $cart_line),
        'item' => $item,
    ];
}

usort($ticket_lines, static fn (array $left, array $right): int => $left['line'] <=> $right['line']);
?>

<div id="kitchen_ticket" class="kitchen-ticket" lang="<?= esc($language_code) ?>" dir="<?= esc(text_direction($language_code)) ?>">
    <h2 class="kitchen-ticket-title"><?= esc(lang('Sales.kitchen_ticket', [], $language_code)) ?></h2>
    <div class="kitchen-ticket-meta">
        <div><span class="kitchen-ticket-label"><?= esc(lang('Sales.kitchen_ticket_sale_number', [], $language_code)) ?>:</span> <span dir="ltr"><?= esc($sale_id_num) ?></span></div>
        <div><span class="kitchen-ticket-label"><?= esc(lang('Sales.kitchen_ticket_date', [], $language_code)) ?>:</span> <span dir="ltr"><?= esc($transaction_time) ?></span></div>
    </div>

    <div class="kitchen-ticket-lines">
        <?php foreach ($ticket_lines as $ticket_line): ?>
            <?php $item = $ticket_line['item']; ?>
            <div class="kitchen-ticket-line">
                <span class="kitchen-ticket-qty" dir="ltr"><?= esc(to_quantity_decimals($item['quantity'])) ?></span>
                <span class="kitchen-ticket-name"><?= esc($item['name']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (trim((string) $comments) !== ''): ?>
        <div class="kitchen-ticket-comment">
            <span class="kitchen-ticket-label"><?= esc(lang('Sales.kitchen_ticket_comment', [], $language_code)) ?>:</span>
            <div><?= nl2br(esc($comments)) ?></div>
        </div>
    <?php endif; ?>
</div>
