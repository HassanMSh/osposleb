<?php
/**
 * @var array           $config
 * @var string          $comments
 * @var string          $language_code
 * @var int|string      $sale_id_num
 * @var int|string|null $sale_status
 * @var int|string|null $sale_type
 * @var array           $ticket_lines
 * @var string          $transaction_time
 */
$ticket_lines = array_values(array_filter(
    $ticket_lines,
    static fn (array $ticket_line): bool => (float) ($ticket_line['quantity_purchased'] ?? 0) > 0,
));

if (! App\Libraries\Kitchen_ticket::is_eligible($config, $sale_status, $sale_type) || $ticket_lines === []) {
    return;
}
?>

<div id="kitchen_ticket" class="kitchen-ticket" lang="<?= esc($language_code) ?>" dir="<?= esc(text_direction($language_code)) ?>">
    <h2 class="kitchen-ticket-title"><?= esc(lang('Sales.kitchen_ticket', [], $language_code)) ?></h2>
    <div class="kitchen-ticket-meta">
        <div><span class="kitchen-ticket-label"><?= esc(lang('Sales.kitchen_ticket_sale_number', [], $language_code)) ?>:</span> <span dir="ltr"><?= esc($sale_id_num) ?></span></div>
        <div><span class="kitchen-ticket-label"><?= esc(lang('Sales.kitchen_ticket_date', [], $language_code)) ?>:</span> <span dir="ltr"><?= esc($transaction_time) ?></span></div>
    </div>

    <div class="kitchen-ticket-lines">
        <?php foreach ($ticket_lines as $ticket_line): ?>
            <div class="kitchen-ticket-line">
                <span class="kitchen-ticket-qty" dir="ltr"><?= esc(to_quantity_decimals($ticket_line['quantity_purchased'])) ?></span>
                <span class="kitchen-ticket-name">
                    <?= esc($ticket_line['name']) ?>
                    <?php if (! empty($config['receipt_show_description']) && ! empty($ticket_line['description'])): ?>
                        <span class="kitchen-ticket-description"><?= esc($ticket_line['description']) ?></span>
                    <?php endif; ?>
                </span>
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
