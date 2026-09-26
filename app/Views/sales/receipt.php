<?php
/**
 * @var int             $sale_id_num
 * @var bool            $print_after_sale
 * @var array           $config
 * @var int|string|null $sale_status
 * @var int|string|null $sale_type
 */

use App\Libraries\Kitchen_ticket;
use App\Models\Employee;
use App\Models\Sale;

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

<?php
if (isset($error_message)) {
    echo '<div class="alert alert-dismissible alert-danger">' . $error_message . '</div>';

    exit;
}
?>

<?php if (! empty($customer_email)): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            var send_email = function() {
                $.get('<?= site_url() . esc("/sales/sendPdf/{$sale_id_num}/receipt") ?>',
                    function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        })
                    }, 'json'
                );
            };

            $("#show_email_button").click(send_email);

            <?php if (! empty($email_receipt)): ?>
                send_email();
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?= view('partial/print_receipt', ['print_after_sale' => $print_after_sale, 'selected_printer' => 'receipt_printer']) ?>

<div class="print_hide" id="control_buttons" style="text-align: right;">
    <a href="javascript:printdoc();">
        <div class="btn btn-info btn-sm" id="show_print_button"><?= '<span class="glyphicon glyphicon-print">&nbsp;</span>' . lang('Common.print') ?></div>
    </a>
    <?php if (! empty($customer_email)): ?>
        <a href="javascript:void(0);">
            <div class="btn btn-info btn-sm" id="show_email_button"><?= '<span class="glyphicon glyphicon-envelope">&nbsp;</span>' . lang('Sales.send_receipt') ?></div>
        </a>
    <?php endif; ?>
    <?= anchor('sales', '<span class="glyphicon glyphicon-shopping-cart">&nbsp;</span>' . lang('Sales.register'), ['class' => 'btn btn-info btn-sm', 'id' => 'show_sales_button']) ?>
    <?php
    $employee = model(Employee::class);
if ($employee->has_grant('reports_sales', session('person_id'))): ?>
        <?= anchor('sales/manage', '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang('Sales.takings'), ['class' => 'btn btn-info btn-sm', 'id' => 'show_takings_button']) ?>
    <?php endif; ?>
</div>

<?= view('sales/' . $config['receipt_template']) ?><?php
if (Kitchen_ticket::is_eligible($config, $sale_status ?? null, $sale_type ?? null)) {
    echo view('sales/kitchen_ticket', [
        'config'           => $config,
        'comments'         => $comments,
        'language_code'    => current_language_code(),
        'sale_id_num'      => $sale_id_num,
        'sale_status'      => $sale_status,
        'sale_type'        => $sale_type,
        'ticket_lines'     => model(Sale::class)->get_kitchen_ticket_lines((int) $sale_id_num),
        'transaction_time' => $transaction_time,
    ]);
}
?>

<?= view('partial/footer') ?>
