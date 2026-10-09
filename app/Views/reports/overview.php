<?php
/**
 * @var bool $can_view_receivings
 */
?>

<style>
    .reports-overview-row {
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    @media (max-width: 767px) {
        .reports-overview-row {
            margin-left: 0;
            margin-right: 0;
        }
    }

    .reports-overview-action,
    .reports-overview-tiles,
    .reports-overview-chart {
        margin-bottom: 15px;
    }

    .reports-overview-action .btn {
        white-space: normal;
    }

    .reports-overview-tiles {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .reports-overview-tile {
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 4px;
        flex: 1 1 145px;
        min-width: 135px;
        padding: 10px;
    }

    .reports-overview-tile-label,
    .reports-overview-tile small {
        display: block;
    }

    .reports-overview-tile-value {
        display: block;
        font-size: 18px;
        font-weight: bold;
        margin: 5px 0;
    }

    .reports-overview-tile small {
        margin-top: 3px;
    }

    .reports-overview-chart {
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px;
    }

    .reports-overview-chart-title {
        font-size: 16px;
        margin: 0 0 10px;
    }

    .reports-overview-chart-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .reports-overview-chart-buttons .btn {
        margin: 0;
    }

    #reports-overview-chart {
        height: 220px;
        margin-top: 12px;
    }

    #reports-overview-chart .ct-label {
        direction: ltr;
    }
</style>

<div class="row reports-overview-row">
    <div class="col-md-2 reports-overview-action">
        <?= anchor('reports/overview/print-today', '<span class="glyphicon glyphicon-print">&nbsp;</span>' . lang('Reports.overview_print_today_sales'), ['class' => 'btn btn-primary btn-block', 'id' => 'reports-overview-print']) ?>
    </div>

    <div class="col-md-5 reports-overview-tiles" id="reports-overview-tiles">
        <div class="reports-overview-tile">
            <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_today') ?></span>
            <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-today-store">—</bdi></strong>
            <small><bdi dir="ltr" id="reports-overview-sales-today-lbp">—</bdi></small>
        </div>
        <div class="reports-overview-tile">
            <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_week') ?></span>
            <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-week-store">—</bdi></strong>
            <small><bdi dir="ltr" id="reports-overview-sales-week-lbp">—</bdi></small>
        </div>
        <div class="reports-overview-tile">
            <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_month') ?></span>
            <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-month-store">—</bdi></strong>
            <small><bdi dir="ltr" id="reports-overview-sales-month-lbp">—</bdi></small>
        </div>
        <?php if ($can_view_receivings) { ?>
            <div class="reports-overview-tile">
                <span class="reports-overview-tile-label"><?= lang('Reports.overview_drawer_cash_today') ?></span>
                <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-drawer-store">—</bdi></strong>
                <small><bdi dir="ltr" id="reports-overview-drawer-lbp">—</bdi></small>
                <small><?= lang('Reports.overview_cash_in') ?>: <bdi dir="ltr" id="reports-overview-cash-in">—</bdi></small>
                <small><?= lang('Reports.overview_cash_receivings') ?>: <bdi dir="ltr" id="reports-overview-cash-receivings">—</bdi></small>
                <small><?= lang('Reports.overview_cash_expenses') ?>: <bdi dir="ltr" id="reports-overview-cash-expenses">—</bdi></small>
            </div>
        <?php } ?>
    </div>

    <div class="col-md-5 reports-overview-chart">
        <h2 class="reports-overview-chart-title"><?= lang('Reports.overview_graph_title') ?></h2>
        <div class="reports-overview-chart-buttons" role="group" aria-label="<?= esc(lang('Reports.overview_graph_title')) ?>">
            <?php foreach (['hour', 'day', 'week', 'month', 'year'] as $period) { ?>
                <button type="button" class="btn btn-default btn-sm<?= $period === 'day' ? ' active' : '' ?>" data-reports-period="<?= esc($period) ?>" aria-pressed="<?= $period === 'day' ? 'true' : 'false' ?>"><?= lang('Reports.overview_period_' . $period) ?></button>
            <?php } ?>
        </div>
        <div id="reports-overview-chart" aria-label="<?= esc(lang('Reports.overview_graph_title')) ?>"></div>
        <div class="text-danger" id="reports-overview-chart-error" role="status" hidden><?= lang('Reports.overview_graph_error') ?></div>
    </div>
</div>

<script>
    $(function() {
        var overviewTotalsUrl = <?= json_encode(site_url('reports/overview/totals')) ?>;
        var overviewGraphUrl = <?= json_encode(site_url('reports/overview/graph')) ?>;
        var overviewChart = null;

        /** Updates a tile's store currency and Lebanese pound values. */
        function setOverviewMoney(prefix, money) {
            $('#' + prefix + '-store').text(money.store);
            $('#' + prefix + '-lbp').text(money.lbp);
        }

        /** Loads and displays all overview totals allowed for this employee. */
        function loadOverviewTotals() {
            $.getJSON(overviewTotalsUrl)
                .done(function(data) {
                    setOverviewMoney('reports-overview-sales-today', data.sales_today);
                    setOverviewMoney('reports-overview-sales-week', data.sales_week);
                    setOverviewMoney('reports-overview-sales-month', data.sales_month);

                    if (data.drawer_cash) {
                        setOverviewMoney('reports-overview-drawer', data.drawer_cash.total);
                        $('#reports-overview-cash-in').text(data.drawer_cash.cash_in.store + ' / ' + data.drawer_cash.cash_in.lbp);
                        $('#reports-overview-cash-receivings').text(data.drawer_cash.receivings.store + ' / ' + data.drawer_cash.receivings.lbp);
                        $('#reports-overview-cash-expenses').text(data.drawer_cash.expenses.store + ' / ' + data.drawer_cash.expenses.lbp);
                    }
                });
        }

        /** Loads the selected chart period from the secured JSON endpoint. */
        function loadOverviewGraph(period) {
            $('#reports-overview-chart-error').prop('hidden', true);
            $.getJSON(overviewGraphUrl, { period: period })
                .done(function(data) {
                    var chartData = { labels: data.labels, series: [data.values] };
                    var chartOptions = {
                        showArea: true,
                        fullWidth: true,
                        chartPadding: { right: 12 },
                        axisX: { labelInterpolationFnc: function(value, index) {
                            return data.labels.length > 12 && index % Math.ceil(data.labels.length / 12) !== 0 ? '' : value;
                        } },
                    };

                    if (overviewChart) {
                        overviewChart.update(chartData, chartOptions);
                    } else {
                        overviewChart = new Chartist.Line('#reports-overview-chart', chartData, chartOptions);
                    }
                })
                .fail(function() {
                    $('#reports-overview-chart-error').prop('hidden', false);
                });
        }

        $('[data-reports-period]').on('click', function() {
            var button = $(this);
            $('[data-reports-period]').removeClass('active').attr('aria-pressed', 'false');
            button.addClass('active').attr('aria-pressed', 'true');
            loadOverviewGraph(button.data('reports-period'));
        });

        loadOverviewTotals();
        loadOverviewGraph('day');
    });
</script>
