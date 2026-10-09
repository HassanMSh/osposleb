<?php

namespace App\Models;

use App\Models\Reports\Detailed_receivings;
use App\Models\Reports\Summary_payments;
use App\Models\Reports\Summary_sales;
use CodeIgniter\Model;
use Config\OSPOS;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the Reports overview, receipt, and chart figures from existing reports.
 */
class ReportsOverview extends Model
{
    private Summary_sales $summarySales;
    private Summary_payments $summaryPayments;
    private Detailed_receivings $detailedReceivings;

    /**
     * @var array<string, list<string>>
     */
    private array $localizedCashLabels = [];

    /**
     * Loads the report models used to calculate overview figures.
     */
    public function __construct()
    {
        parent::__construct();
        $this->summarySales       = model(Summary_sales::class);
        $this->summaryPayments    = model(Summary_payments::class);
        $this->detailedReceivings = model(Detailed_receivings::class);
    }

    /**
     * Returns sales totals through the current timestamp and drawer cash when allowed.
     *
     * @param bool $includeDrawer Whether Receiving Reports access allows drawer totals.
     *
     * @return array<string, array<string, float|int|null>> Overview figures.
     */
    public function getOverview(DateTimeImmutable $now, bool $includeDrawer): array
    {
        return $this->withTimestampReportBoundaries(function () use ($now, $includeDrawer): array {
            $today    = $now->setTime(0, 0);
            $week     = $today->modify('monday this week');
            $month    = $today->modify('first day of this month');
            $overview = [
                'sales_today' => $this->getSalesSummary($today, $now),
                'sales_week'  => $this->getSalesSummary($week, $now),
                'sales_month' => $this->getSalesSummary($month, $now),
            ];

            if ($includeDrawer) {
                $overview['drawer_cash'] = $this->getDrawerFigures($today, $now);
            }

            return $overview;
        });
    }

    /**
     * Returns today's receipt figures and omits all drawer amounts when access is missing.
     *
     * @param bool $includeDrawer Whether Receiving Reports access allows drawer amounts.
     *
     * @return array<string, mixed> Receipt figures, counts, and payment totals.
     */
    public function getTodayReceiptData(DateTimeImmutable $now, bool $includeDrawer): array
    {
        return $this->withTimestampReportBoundaries(function () use ($now, $includeDrawer): array {
            $today        = $now->setTime(0, 0);
            $salesSummary = $this->getSalesSummary($today, $now);
            $payments     = $this->getPaymentTotals($today, $now);

            $data = [
                'sales_total'   => $salesSummary,
                'sales_count'   => $this->getTodaySaleCount($today, $now, [SALE_TYPE_POS, SALE_TYPE_INVOICE]),
                'returns_count' => $this->getTodaySaleCount($today, $now, [SALE_TYPE_RETURN]),
                'payments'      => $payments,
            ];

            if ($includeDrawer) {
                $drawer                  = $this->getDrawerFigures($today, $now, $payments);
                $data['drawer_cash']     = ['total' => $drawer['total'], 'lbp_total' => $drawer['lbp_total']];
                $data['cash_receivings'] = $drawer['receivings'];
                $data['cash_expenses']   = $drawer['expenses'];
            }

            return $data;
        });
    }

    /**
     * Returns chart labels and completed sale totals through the current timestamp.
     *
     * @return array{labels: list<string>, values: list<float>} Chart buckets, including empty buckets.
     */
    public function getGraphData(string $period, DateTimeImmutable $now): array
    {
        return $this->withTimestampReportBoundaries(function () use ($period, $now): array {
            if (! in_array($period, ['hour', 'day', 'week', 'month', 'year'], true)) {
                $period = 'day';
            }

            $buckets = $this->getGraphBuckets($period, $now);
            $values  = array_fill_keys(array_column($buckets, 'key'), 0.0);
            $start   = $buckets[0]['start'];
            $inputs  = $this->getSalesInputs($start, $now);

            if ($period === 'hour') {
                $this->dropTemporaryTables(['sales_items_taxes_temp', 'sales_payments_temp']);
                $inputs['group_by_hour'] = true;
                $rows                    = $this->summarySales->getData($inputs);

                foreach ($rows as $row) {
                    $values[substr($row['sale_hour'], 11, 2)] = (float) $row['total'];
                }
            } else {
                $this->dropTemporaryTables(['sales_items_taxes_temp', 'sales_payments_temp']);

                foreach ($this->summarySales->getData($inputs) as $row) {
                    $saleDate = new DateTimeImmutable($row['sale_date'], new DateTimeZone(date_default_timezone_get()));
                    $key      = $this->getGraphBucketKey($period, $saleDate);

                    if (array_key_exists($key, $values)) {
                        $values[$key] += (float) $row['total'];
                    }
                }
            }

            return [
                'labels' => array_column($buckets, 'label'),
                'values' => array_values($values),
            ];
        });
    }

    /**
     * Runs report queries with timestamp bounds, then restores the configured date mode.
     *
     * @param callable(): mixed $callback Report calculation to run with timestamp filtering.
     */
    private function withTimestampReportBoundaries(callable $callback): mixed
    {
        $settings                                             = config(OSPOS::class)->settings;
        $hadDateMode                                          = array_key_exists('date_or_time_format', $settings);
        $savedDateMode                                        = $settings['date_or_time_format'] ?? null;
        config(OSPOS::class)->settings['date_or_time_format'] = 'H:i:s';

        try {
            return $callback();
        } finally {
            if ($hadDateMode) {
                config(OSPOS::class)->settings['date_or_time_format'] = $savedDateMode;
            } else {
                unset(config(OSPOS::class)->settings['date_or_time_format']);
            }
        }
    }

    /**
     * Returns the Summary Sales total and its saved pound total for a date range.
     *
     * @return array{total: float, lbp_total: int|null} Sales total and saved pound total.
     */
    private function getSalesSummary(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $this->dropTemporaryTables(['sales_items_taxes_temp', 'sales_payments_temp']);
        $summary = $this->summarySales->getSummaryData($this->getSalesInputs($start, $end));

        return [
            'total'     => (float) ($summary['total'] ?? 0),
            'lbp_total' => $summary['lbp_total'] ?? null,
        ];
    }

    /**
     * Returns the cash payment, cash receiving, and cash expense parts of today's drawer total.
     *
     * @param array<string, array{total: float, lbp_total: int}>|null $payments Loaded sale payment totals, if available.
     *
     * @return array<string, mixed> Drawer total and its three checkable parts.
     */
    private function getDrawerFigures(DateTimeImmutable $start, DateTimeImmutable $end, ?array $payments = null): array
    {
        $payments ??= $this->getPaymentTotals($start, $end);
        $cashSales  = $this->getCashPaymentAmount($payments);
        $receivings = $this->getCashReceivingTotal($start, $end);
        $expenses   = $this->getCashExpenseTotal($start, $end);
        $drawerCash = $cashSales - $receivings['total'] - $expenses;

        return [
            'total'      => $drawerCash,
            'lbp_total'  => to_lbp($drawerCash),
            'cash_in'    => ['total' => $cashSales, 'lbp_total' => to_lbp($cashSales)],
            'receivings' => $receivings,
            'expenses'   => ['total' => $expenses, 'lbp_total' => to_lbp($expenses)],
        ];
    }

    /**
     * Returns sale payments with cash labels merged under the current language's cash label.
     *
     * @return array<string, array{total: float, lbp_total: int}> Payment amounts by label.
     */
    private function getPaymentTotals(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $this->dropTemporaryTables(['sumpay_taxes_temp', 'sumpay_items_temp', 'sumpay_payments_temp']);
        $payments   = [];
        $cashLabels = $this->getLocalizedCashLabels('Sales.php');
        $cashLabel  = trim((string) lang('Sales.cash'));

        foreach ($this->summaryPayments->getData($this->getSalesInputs($start, $end)) as $row) {
            if ($row['trans_group'] != lang('Reports.trans_payments')) {
                continue;
            }

            $amount      = (float) $row['trans_amount'];
            $paymentType = (string) $row['trans_type'];

            if (in_array(trim($paymentType), $cashLabels, true)) {
                $paymentType = $cashLabel;
            }

            $payments[$paymentType] ??= ['total' => 0.0, 'lbp_total' => 0];
            $payments[$paymentType]['total'] += $amount;
            $payments[$paymentType]['lbp_total'] = to_lbp($payments[$paymentType]['total']);
        }

        return $payments;
    }

    /**
     * Returns today's cash receiving amount and its saved pound total.
     *
     * @return array{total: float, lbp_total: int|null} Cash receiving amount and saved pound total.
     */
    private function getCashReceivingTotal(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $this->dropTemporaryTables(['receivings_items_temp']);
        $inputs = [
            'start_date'     => $this->formatReportBoundary($start),
            'end_date'       => $this->formatReportBoundary($end),
            'location_id'    => 'all',
            'receiving_type' => 'all',
            'definition_ids' => [],
        ];
        $this->detailedReceivings->create($inputs);
        $summary      = $this->detailedReceivings->getData($inputs)['summary'];
        $cashLabels   = $this->getLocalizedCashLabels('Sales.php');
        $total        = 0.0;
        $lbpTotal     = 0;
        $missingTotal = false;

        foreach ($summary as $row) {
            if (! in_array(trim((string) $row['payment_type']), $cashLabels, true)) {
                continue;
            }

            $total += (float) $row['total'];

            if ($row['lbp_total'] === null) {
                $missingTotal = true;
            } else {
                $lbpTotal += (int) $row['lbp_total'];
            }
        }

        return ['total' => $total, 'lbp_total' => $missingTotal ? null : $lbpTotal];
    }

    /**
     * Returns expenses paid with a cash label from any installed language.
     *
     * @return float Cash expenses within the requested date range.
     */
    private function getCashExpenseTotal(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        $builder = $this->db->table('expenses');
        $builder->select('payment_type, SUM(amount) AS amount');
        $builder->where('deleted', 0);

        $startDate = $this->formatReportBoundary($start);
        $endDate   = $this->formatReportBoundary($end);

        if (empty(config(OSPOS::class)->settings['date_or_time_format'])) {
            $dateCondition = 'DATE_FORMAT(date, "%Y-%m-%d") BETWEEN '
                . $this->db->escape($startDate)
                . ' AND '
                . $this->db->escape($endDate);
        } else {
            $dateCondition = 'date BETWEEN '
                . $this->db->escape(rawurldecode($startDate))
                . ' AND '
                . $this->db->escape(rawurldecode($endDate));
        }

        $builder->where($dateCondition, null, false);

        $cashLabels = array_values(array_unique(array_merge(
            $this->getLocalizedCashLabels('Sales.php'),
            $this->getLocalizedCashLabels('Expenses.php'),
        )));
        $builder->groupBy('payment_type');
        $payments = $builder->get()->getResultArray();
        $total    = 0.0;

        foreach ($payments as $payment) {
            if (in_array(trim((string) $payment['payment_type']), $cashLabels, true)) {
                $total += (float) $payment['amount'];
            }
        }

        return $total;
    }

    /**
     * Adds sale payments whose stored type matches any language's cash label.
     *
     * @param array<string, array{total: float, lbp_total: int}> $payments Payment totals by label.
     *
     * @return float Total sale payments tagged as cash.
     */
    private function getCashPaymentAmount(array $payments): float
    {
        $cashLabels = $this->getLocalizedCashLabels('Sales.php');
        $total      = 0.0;

        foreach ($payments as $paymentType => $payment) {
            if (in_array(trim($paymentType), $cashLabels, true)) {
                $total += $payment['total'];
            }
        }

        return $total;
    }

    /**
     * Loads and trims the cash label from each installed language file.
     *
     * @param string $languageFileName Language file name, such as Sales.php or Expenses.php.
     *
     * @return list<string> Distinct non-empty cash labels.
     */
    private function getLocalizedCashLabels(string $languageFileName): array
    {
        if (isset($this->localizedCashLabels[$languageFileName])) {
            return $this->localizedCashLabels[$languageFileName];
        }

        $labels = [];

        foreach (glob(APPPATH . 'Language/*/' . $languageFileName) ?: [] as $languagePath) {
            $language = require $languagePath;
            $label    = $language['cash'] ?? null;

            if (is_string($label) && trim($label) !== '') {
                $labels[] = trim($label);
            }
        }

        $this->localizedCashLabels[$languageFileName] = array_values(array_unique($labels));

        return $this->localizedCashLabels[$languageFileName];
    }

    /**
     * Counts today's completed sales or returns using the Summary Sales status and type rules.
     *
     * @param list<int> $saleTypes Sale types to count.
     */
    private function getTodaySaleCount(DateTimeImmutable $start, DateTimeImmutable $end, array $saleTypes): int
    {
        $builder = $this->db->table('sales AS sales');
        $builder->select('COUNT(DISTINCT sales.sale_id) AS sale_count');
        $builder->join('sales_items AS sales_items', 'sales_items.sale_id = sales.sale_id', 'inner');
        $builder->where('sales.sale_status', COMPLETED);
        $builder->whereIn('sales.sale_type', $saleTypes);
        $builder->where($this->getSalesDateCondition($start, $end));

        return (int) $builder->get()->getRow()->sale_count;
    }

    /**
     * Returns all zero-filled date buckets for the selected chart period.
     *
     * @return list<array{key: string, label: string, start: DateTimeImmutable, end: DateTimeImmutable}> Chart buckets.
     */
    private function getGraphBuckets(string $period, DateTimeImmutable $now): array
    {
        $today   = $now->setTime(0, 0);
        $buckets = [];

        if ($period === 'hour') {
            for ($hour = 0; $hour < 24; $hour++) {
                $start     = $today->setTime($hour, 0);
                $buckets[] = [
                    'key'   => $start->format('H'),
                    'label' => $start->format('H'),
                    'start' => $start,
                    'end'   => $start->modify('+1 hour')->modify('-1 second'),
                ];
            }

            return $buckets;
        }

        if ($period === 'day') {
            $first = $today->modify('-29 days');

            for ($index = 0; $index < 30; $index++) {
                $start     = $first->modify('+' . $index . ' days');
                $buckets[] = [
                    'key'   => $start->format('Y-m-d'),
                    'label' => $start->format('d/m'),
                    'start' => $start,
                    'end'   => $start->setTime(23, 59, 59),
                ];
            }

            return $buckets;
        }

        if ($period === 'week') {
            $first = $today->modify('monday this week')->modify('-11 weeks');

            for ($index = 0; $index < 12; $index++) {
                $start     = $first->modify('+' . $index . ' weeks');
                $buckets[] = [
                    'key'   => $start->format('Y-m-d'),
                    'label' => $start->format('d/m'),
                    'start' => $start,
                    'end'   => $start->modify('+6 days')->setTime(23, 59, 59),
                ];
            }

            return $buckets;
        }

        if ($period === 'month') {
            $first = $today->modify('first day of this month')->modify('-11 months');

            for ($index = 0; $index < 12; $index++) {
                $start     = $first->modify('+' . $index . ' months');
                $monthName = lang('Calendar.' . strtolower($start->format('F')));
                $buckets[] = [
                    'key'   => $start->format('Y-m'),
                    'label' => $monthName . ' ' . $start->format('Y'),
                    'start' => $start,
                    'end'   => $start->modify('last day of this month')->setTime(23, 59, 59),
                ];
            }

            return $buckets;
        }

        $firstYear = $today->setDate((int) $today->format('Y') - 4, 1, 1);

        for ($index = 0; $index < 5; $index++) {
            $start     = $firstYear->modify('+' . $index . ' years');
            $buckets[] = [
                'key'   => $start->format('Y'),
                'label' => $start->format('Y'),
                'start' => $start,
                'end'   => $start->setDate((int) $start->format('Y'), 12, 31)->setTime(23, 59, 59),
            ];
        }

        return $buckets;
    }

    /**
     * Returns the chart bucket key for a daily Summary Sales row.
     */
    private function getGraphBucketKey(string $period, DateTimeImmutable $saleDate): string
    {
        if ($period === 'week') {
            return $saleDate->modify('monday this week')->format('Y-m-d');
        }

        return $saleDate->format(match ($period) {
            'month' => 'Y-m',
            'year'  => 'Y',
            default => 'Y-m-d',
        });
    }

    /**
     * Returns Summary Sales filters for all stock locations and completed sales and returns.
     *
     * @return array{start_date: string, end_date: string, sale_type: string, location_id: string} Report filters.
     */
    private function getSalesInputs(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'start_date'  => $this->formatReportBoundary($start),
            'end_date'    => $this->formatReportBoundary($end),
            'sale_type'   => 'complete',
            'location_id' => 'all',
        ];
    }

    /**
     * Returns the date condition used by Summary Sales for completed transaction counts.
     */
    private function getSalesDateCondition(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $startDate = $this->db->escape($this->formatReportBoundary($start));
        $endDate   = $this->db->escape($this->formatReportBoundary($end));

        return empty(config(OSPOS::class)->settings['date_or_time_format'])
            ? "DATE(sales.sale_time) BETWEEN {$startDate} AND {$endDate}"
            : "sales.sale_time BETWEEN {$startDate} AND {$endDate}";
    }

    /**
     * Formats a date bound the same way the existing reports expect it.
     */
    private function formatReportBoundary(DateTimeImmutable $dateTime): string
    {
        return empty(config(OSPOS::class)->settings['date_or_time_format'])
            ? $dateTime->format('Y-m-d')
            : $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * Drops report temporary tables before the next report query on this database connection.
     *
     * @param list<string> $tableNames Names without the configured database prefix.
     */
    private function dropTemporaryTables(array $tableNames): void
    {
        foreach ($tableNames as $tableName) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable($tableName));
        }
    }
}
