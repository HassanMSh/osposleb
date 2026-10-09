<?php
/**
 * @var array  $config
 * @var array  $receipt_data
 * @var string $printed_by
 * @var string $printed_at
 * @var bool   $include_receiving_lines
 */
?>

<?= view('partial/header') ?>

<style media="print">
    @page {
        margin: 0;
    }

    #receipt_wrapper {
        padding: 3mm;
        box-sizing: border-box;
    }
</style>

<?= view('partial/print_receipt', ['print_after_sale' => false, 'selected_printer' => 'receipt_printer']) ?>

<div class="print_hide" style="text-align: right;">
    <a href="javascript:printdoc();" class="btn btn-info btn-sm" id="show_print_button"><span class="glyphicon glyphicon-print">&nbsp;</span><?= lang('Common.print') ?></a>
    <?= anchor('reports', '<span class="glyphicon glyphicon-chevron-left">&nbsp;</span>' . lang('Reports.overview_back_to_reports'), ['class' => 'btn btn-info btn-sm']) ?>
</div>

<div id="receipt_wrapper" style="font-size: <?= esc($config['receipt_font_size']) ?>px;">
    <div id="receipt_header">
        <?php if ($config['receipt_show_company_name']) { ?>
            <div id="company_name"><?= esc($config['company']) ?></div>
        <?php } ?>
        <div id="company_address"><?= nl2br(esc($config['address'])) ?></div>
        <div id="sale_receipt"><?= lang('Reports.overview_today_sales_title') ?></div>
        <div id="sale_time"><?= esc($printed_at) ?></div>
    </div>

    <div id="receipt_general_info">
        <div><?= lang('Reports.overview_printed_by') ?>: <?= esc($printed_by) ?></div>
        <div><?= lang('Reports.overview_sales_count') ?>: <?= esc((string) $receipt_data['sales_count']) ?></div>
        <div><?= lang('Reports.overview_returns_count') ?>: <?= esc((string) $receipt_data['returns_count']) ?></div>
    </div>

    <table id="receipt_items">
        <tbody>
            <tr>
                <td><?= lang('Reports.overview_total_sales') ?></td>
                <td class="total-value">
                    <span dir="ltr"><?= esc(to_currency($receipt_data['sales_total']['total'])) ?></span><br>
                    <span dir="ltr"><?= $receipt_data['sales_total']['lbp_total'] === null ? esc(lang('Reports.overview_lbp_unavailable')) : esc(format_lbp($receipt_data['sales_total']['lbp_total'])) ?></span>
                </td>
            </tr>
            <?php foreach ($receipt_data['payments'] as $payment_type => $payment) { ?>
                <tr>
                    <td><bdi dir="auto"><?= esc($payment_type) ?></bdi></td>
                    <td class="total-value">
                        <span dir="ltr"><?= esc(to_currency($payment['total'])) ?></span><br>
                        <span dir="ltr"><?= esc(format_lbp($payment['lbp_total'])) ?></span>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($include_receiving_lines) { ?>
                <tr>
                    <td><?= lang('Reports.overview_cash_paid_receivings') ?></td>
                    <td class="total-value">
                        <span dir="ltr"><?= esc(to_currency($receipt_data['cash_receivings']['total'])) ?></span><br>
                        <span dir="ltr"><?= $receipt_data['cash_receivings']['lbp_total'] === null ? esc(lang('Reports.overview_lbp_unavailable')) : esc(format_lbp($receipt_data['cash_receivings']['lbp_total'])) ?></span>
                    </td>
                </tr>
                <tr>
                    <td><?= lang('Reports.overview_cash_paid_expenses') ?></td>
                    <td class="total-value">
                        <span dir="ltr"><?= esc(to_currency($receipt_data['cash_expenses']['total'])) ?></span><br>
                        <span dir="ltr"><?= esc(format_lbp($receipt_data['cash_expenses']['lbp_total'])) ?></span>
                    </td>
                </tr>
                <tr>
                    <td><?= lang('Reports.overview_drawer_cash') ?></td>
                    <td class="total-value">
                        <span dir="ltr"><?= esc(to_currency($receipt_data['drawer_cash']['total'])) ?></span><br>
                        <span dir="ltr"><?= esc(format_lbp($receipt_data['drawer_cash']['lbp_total'])) ?></span>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<script>
    $(window).on('load', function() {
        printdoc();
        window.setTimeout(function() {
            window.location.href = <?= json_encode(site_url('reports')) ?>;
        }, <?= (int) $config['print_delay_autoreturn'] * 1000 ?>);
    });
</script>

<?= view('partial/footer') ?>
