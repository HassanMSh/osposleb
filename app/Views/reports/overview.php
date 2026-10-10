<?php
/**
 * @var bool  $can_view_receivings
 * @var array $config
 */
?>

<style>
    .reports-overview-row {
        align-items: flex-start;
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    @media (max-width: 991px) {
        .reports-overview-row {
            flex-direction: column;
        }

        .reports-overview-left,
        .reports-overview-chart-column {
            flex: 0 0 100%;
            width: 100%;
        }

        .reports-overview-row ~ .row > .col-md-4 {
            width: 100%;
        }
    }

    @media (max-width: 767px) {
        .reports-overview-row {
            margin-left: 0;
            margin-right: 0;
        }

        .reports-overview-left,
        .reports-overview-chart-column {
            padding-left: 0;
            padding-right: 0;
        }
    }

    .reports-overview-left,
    .reports-overview-chart-column {
        margin-bottom: 15px;
    }

    .reports-overview-left {
        align-self: flex-start;
    }

    .reports-overview-action {
        margin-bottom: 10px;
    }

    .reports-overview-action .btn {
        white-space: normal;
    }

    /* Every tile takes the height of the tallest one; the column itself still keeps its natural height next to the graph */
    .reports-overview-tiles {
        align-content: start;
        align-items: stretch;
        display: grid;
        grid-auto-rows: 1fr;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .reports-overview-tile {
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 4px;
        min-width: 0;
        padding: 10px;
    }

    .reports-overview-tile-label,
    .reports-overview-tile small {
        display: block;
    }

    .reports-overview-comparison {
        color: #666;
        font-size: 12px;
        line-height: 1.4;
        margin-top: 5px;
        overflow-wrap: anywhere;
    }

    .reports-overview-comparison-up {
        color: #287d3c;
    }

    .reports-overview-comparison-down {
        color: #b02a37;
    }

    /* Page section headings: the sales dashboard on top, the report links below it */
    .reports-overview-section-title {
        border-bottom: 1px solid #ddd;
        font-size: 20px;
        margin: 0 0 15px;
        padding-bottom: 8px;
    }

    .reports-overview-best {
        margin-bottom: 20px;
    }

    .reports-overview-best-title {
        font-size: 16px;
        margin: 0 0 10px;
    }

    .reports-overview-best-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .reports-overview-best-tile {
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 4px;
        min-width: 0;
        padding: 10px;
    }

    .reports-overview-best-tile span,
    .reports-overview-best-tile small {
        display: block;
        overflow-wrap: anywhere;
    }

    .reports-overview-best-value {
        font-size: 18px;
        margin: 5px 0;
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

    /* The chart is always drawn left to right, so the axis title stays on the left above the amounts in both directions */
    .reports-overview-axis-title {
        color: #555;
        direction: ltr;
        font-size: 12px;
        margin-top: 12px;
        text-align: left;
    }

    #reports-overview-chart {
        height: 220px;
    }

    #reports-overview-chart .ct-label {
        direction: ltr;
    }

    /* reports.css rotates every x-axis label by 60 degrees, which pushes them into the plot; keep them flat here */
    #reports-overview-chart .ct-label.ct-horizontal {
        -webkit-transform: none;
        transform: none;
        filter: none;
        white-space: nowrap;
    }

    @media (max-width: 767px) {
        .reports-overview-best-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 400px) {
        .reports-overview-tiles,
        .reports-overview-best-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>

<h2 class="reports-overview-section-title"><?= esc(lang('Reports.overview_dashboard_title')) ?></h2>

<div class="row reports-overview-row">
    <div class="col-md-5 reports-overview-left">
        <div class="reports-overview-action">
            <?= anchor('reports/overview/print-today', '<span class="glyphicon glyphicon-print">&nbsp;</span>' . lang('Reports.overview_print_today_sales'), ['class' => 'btn btn-primary btn-block', 'id' => 'reports-overview-print']) ?>
        </div>

        <div class="reports-overview-tiles" id="reports-overview-tiles">
            <div class="reports-overview-tile">
                <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_today') ?></span>
                <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-today-store">—</bdi></strong>
                <small><bdi dir="ltr" id="reports-overview-sales-today-lbp">—</bdi></small>
                <small class="reports-overview-comparison" id="reports-overview-comparison-today"></small>
            </div>
            <div class="reports-overview-tile">
                <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_week') ?></span>
                <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-week-store">—</bdi></strong>
                <small><bdi dir="ltr" id="reports-overview-sales-week-lbp">—</bdi></small>
                <small class="reports-overview-comparison" id="reports-overview-comparison-week"></small>
            </div>
            <div class="reports-overview-tile">
                <span class="reports-overview-tile-label"><?= lang('Reports.overview_sales_month') ?></span>
                <strong class="reports-overview-tile-value"><bdi dir="ltr" id="reports-overview-sales-month-store">—</bdi></strong>
                <small><bdi dir="ltr" id="reports-overview-sales-month-lbp">—</bdi></small>
                <small class="reports-overview-comparison" id="reports-overview-comparison-month"></small>
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
    </div>

    <div class="col-md-7 reports-overview-chart-column">
        <div class="reports-overview-chart">
            <h2 class="reports-overview-chart-title"><?= lang('Reports.overview_graph_title') ?></h2>
            <div class="reports-overview-chart-buttons" role="group" aria-label="<?= esc(lang('Reports.overview_graph_title')) ?>">
                <?php foreach (['hour', 'day', 'week', 'month', 'year'] as $period) { ?>
                    <button type="button" class="btn btn-default btn-sm<?= $period === 'day' ? ' active' : '' ?>" data-reports-period="<?= esc($period) ?>" aria-pressed="<?= $period === 'day' ? 'true' : 'false' ?>"><?= lang('Reports.overview_period_' . $period) ?></button>
                <?php } ?>
            </div>
            <div class="reports-overview-axis-title"><span dir="auto"><?= esc(lang('Reports.overview_graph_axis', [$config['currency_symbol']])) ?></span></div>
            <div id="reports-overview-chart" aria-label="<?= esc(lang('Reports.overview_graph_title')) ?>"></div>
            <div class="text-danger" id="reports-overview-chart-error" role="status" hidden><?= lang('Reports.overview_graph_error') ?></div>
        </div>
    </div>
</div>

<section class="reports-overview-best" aria-labelledby="reports-overview-best-title">
    <h2 class="reports-overview-best-title" id="reports-overview-best-title"><?= esc(lang('Reports.overview_best_sales')) ?></h2>
    <div class="reports-overview-best-grid">
        <?php foreach (['day', 'week', 'month'] as $bestPeriod) { ?>
            <div class="reports-overview-best-tile" id="reports-overview-best-<?= esc($bestPeriod) ?>">
                <span><?= esc(lang('Reports.overview_best_' . $bestPeriod)) ?></span>
                <small dir="auto" id="reports-overview-best-<?= esc($bestPeriod) ?>-period">—</small>
                <strong class="reports-overview-best-value"><bdi dir="ltr" id="reports-overview-best-<?= esc($bestPeriod) ?>-store">—</bdi></strong>
                <small><bdi dir="ltr" id="reports-overview-best-<?= esc($bestPeriod) ?>-lbp"></bdi></small>
                <small id="reports-overview-best-<?= esc($bestPeriod) ?>-message"></small>
            </div>
        <?php } ?>
    </div>
</section>

<h2 class="reports-overview-section-title"><?= esc(lang('Reports.overview_reports_title')) ?></h2>

<script>
    $(function() {
        var overviewTotalsUrl = <?= json_encode(site_url('reports/overview/totals')) ?>;
        var overviewGraphUrl = <?= json_encode(site_url('reports/overview/graph')) ?>;
        var overviewChart = null;
        var overviewData = null;
        var overviewCurrencySymbol = <?= json_encode($config['currency_symbol']) ?>;
        var overviewCurrencyOnRight = <?= json_encode(is_right_side_currency_symbol()) ?>;
        var overviewUseGrouping = <?= json_encode(! empty($config['thousands_separator'])) ?>;

        /** Formats a y-axis value as a whole store currency amount, such as $1,500 or -$3, following the thousands separator setting. */
        function formatOverviewAxisMoney(value) {
            var number = Number(value);
            var amount = Math.abs(number).toLocaleString('en-US', { maximumFractionDigits: 0, useGrouping: overviewUseGrouping });
            var sign = number < 0 ? '-' : '';
            return sign + (overviewCurrencyOnRight ? amount + ' ' + overviewCurrencySymbol : overviewCurrencySymbol + amount);
        }

        /** Returns the width in pixels of the widest text in the list, measured in the chart's 12px label font. */
        function measureOverviewText(texts) {
            var context = document.createElement('canvas').getContext('2d');
            context.font = '12px ' + $('#reports-overview-chart').css('font-family');

            var widest = 0;
            $.each(texts, function(index, text) {
                widest = Math.max(widest, context.measureText(String(text)).width);
            });

            return widest;
        }

        /** Updates a tile's store currency and Lebanese pound values. */
        function setOverviewMoney(prefix, money) {
            $('#' + prefix + '-store').text(money.store);
            $('#' + prefix + '-lbp').text(money.lbp);
        }

        /** Adds one translated comparison line and keeps its currency amount in an LTR bdi element. */
        function setOverviewComparison(period, data) {
            var headline = $('#reports-overview-comparison-' + period).empty();
            if (data.icon) {
                var iconClass = data.icon === 'up' ? 'glyphicon-arrow-up reports-overview-comparison-up' : 'glyphicon-arrow-down reports-overview-comparison-down';
                headline.append($('<span>').addClass('glyphicon ' + iconClass).attr('aria-hidden', 'true'));
                headline.append(document.createTextNode(' '));
            }

            if (data.message) {
                headline.append(document.createTextNode(data.message));
            } else if (data.value) {
                headline.append(document.createTextNode(data.before));
                headline.append($('<bdi>').attr('dir', 'ltr').text(data.value));
                headline.append(document.createTextNode(data.after));
            }

        }

        /** Displays the date range and currency amounts for one all-time best period. */
        function setOverviewBestPeriod(period, data) {
            $('#reports-overview-best-' + period + '-period').text(data.period);
            $('#reports-overview-best-' + period + '-store').text(data.store);
            $('#reports-overview-best-' + period + '-lbp').text(data.lbp);
            $('#reports-overview-best-' + period + '-message').text(data.message);
        }

        /** Loads and displays all overview totals allowed for this employee. */
        function loadOverviewTotals() {
            $.getJSON(overviewTotalsUrl)
                .done(function(data) {
                    setOverviewMoney('reports-overview-sales-today', data.sales_today);
                    setOverviewMoney('reports-overview-sales-week', data.sales_week);
                    setOverviewMoney('reports-overview-sales-month', data.sales_month);
                    setOverviewComparison('today', data.comparisons.today);
                    setOverviewComparison('week', data.comparisons.week);
                    setOverviewComparison('month', data.comparisons.month);
                    setOverviewBestPeriod('day', data.best_sales.day);
                    setOverviewBestPeriod('week', data.best_sales.week);
                    setOverviewBestPeriod('month', data.best_sales.month);

                    if (data.drawer_cash) {
                        setOverviewMoney('reports-overview-drawer', data.drawer_cash.total);
                        $('#reports-overview-cash-in').text(data.drawer_cash.cash_in.store + ' / ' + data.drawer_cash.cash_in.lbp);
                        $('#reports-overview-cash-receivings').text(data.drawer_cash.receivings.store + ' / ' + data.drawer_cash.receivings.lbp);
                        $('#reports-overview-cash-expenses').text(data.drawer_cash.expenses.store + ' / ' + data.drawer_cash.expenses.lbp);
                    }
                });
        }

        /**
         * Returns which x-axis labels to show (every step-th, at most 12) so the widest measured label fits its slot,
         * and the right padding that keeps the last shown label inside the chart.
         * Widths are measured with the current font, so call it again after a resize or once web fonts have loaded.
         */
        function getOverviewLabelLayout(labels, axisYOffset) {
            var widest = measureOverviewText(labels);
            var gaps = Math.max(1, labels.length - 1);
            var plotWidth = Math.max(1, $('#reports-overview-chart').width() - axisYOffset - 10 - widest);
            var slotWidth = plotWidth / gaps;
            var step = Math.max(1, Math.ceil((widest + 10) / slotWidth), Math.ceil(labels.length / 12));
            var lastShownIndex = Math.floor((labels.length - 1) / step) * step;
            var overhang = widest + 4 - (labels.length - 1 - lastShownIndex) * slotWidth;

            return { step: step, rightPadding: Math.max(12, Math.ceil(overhang)) };
        }

        /** Draws or redraws the chart for the last loaded data with a label layout that fits the current width. */
        function drawOverviewChart() {
            if (!overviewData) {
                return;
            }

            // Below $1 the whole-dollar ticks would only show $0, so keep the scale at least $0 to $1.
            // The y-axis is as wide as the largest amount it can label (ticks stay within twice the highest and lowest values).
            var highest = Math.max.apply(null, [0].concat(overviewData.values));
            var lowest = Math.min.apply(null, [0].concat(overviewData.values));
            var axisYOffset = Math.max(40, Math.ceil(measureOverviewText([
                formatOverviewAxisMoney(Math.ceil(highest * 2)),
                formatOverviewAxisMoney(Math.floor(lowest * 2)),
            ])) + 12);
            var labelLayout = getOverviewLabelLayout(overviewData.labels, axisYOffset);
            var chartData = { labels: overviewData.labels, series: [overviewData.values] };
            var chartOptions = {
                showArea: true,
                fullWidth: true,
                chartPadding: { top: 14, right: labelLayout.rightPadding },
                axisX: { labelInterpolationFnc: function(value, index) {
                    return index % labelLayout.step !== 0 ? '' : value;
                } },
                axisY: { onlyInteger: true, high: highest < 1 ? 1 : undefined, offset: axisYOffset, labelInterpolationFnc: formatOverviewAxisMoney },
            };

            if (overviewChart) {
                overviewChart.update(chartData, chartOptions);
            } else {
                overviewChart = new Chartist.Line('#reports-overview-chart', chartData, chartOptions);
            }
        }

        /** Loads the selected chart period from the secured JSON endpoint. */
        function loadOverviewGraph(period) {
            $('#reports-overview-chart-error').prop('hidden', true);
            $.getJSON(overviewGraphUrl, { period: period })
                .done(function(data) {
                    overviewData = data;
                    drawOverviewChart();
                })
                .fail(function() {
                    $('#reports-overview-chart-error').prop('hidden', false);
                });
        }

        var overviewResizeTimer;
        $(window).on('resize', function() {
            clearTimeout(overviewResizeTimer);
            overviewResizeTimer = setTimeout(drawOverviewChart, 150);
        });

        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(drawOverviewChart);
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
