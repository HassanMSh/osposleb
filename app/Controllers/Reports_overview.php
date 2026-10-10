<?php

namespace App\Controllers;

use App\Models\ReportsOverview;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Serves the Reports overview data and today's receipt.
 */
class Reports_overview extends Secure_Controller
{
    private ?ReportsOverview $overview = null;

    /**
     * Checks access to the Reports module before serving an overview action.
     */
    public function __construct()
    {
        parent::__construct('reports');
    }

    /**
     * Returns the overview tiles as JSON for employees with Sales Reports access.
     */
    public function getTotals(): ResponseInterface
    {
        if (! $this->hasPermission('reports_sales')) {
            return $this->deniedResponse();
        }

        $data      = $this->getOverviewModel()->getOverview($this->getLocalNow(), $this->hasPermission('reports_receivings'));
        $formatted = [
            'sales_today' => $this->formatMoney($data['sales_today']),
            'sales_week'  => $this->formatMoney($data['sales_week']),
            'sales_month' => $this->formatMoney($data['sales_month']),
            'comparisons' => [
                'today' => $this->formatComparison($data['comparisons']['today'], 'today'),
                'week'  => $this->formatComparison($data['comparisons']['week'], 'week'),
                'month' => $this->formatComparison($data['comparisons']['month'], 'month'),
            ],
            'best_sales' => [
                'day'   => $this->formatBestPeriod($data['best_sales']['day'] ?? null, 'day'),
                'week'  => $this->formatBestPeriod($data['best_sales']['week'] ?? null, 'week'),
                'month' => $this->formatBestPeriod($data['best_sales']['month'] ?? null, 'month'),
            ],
        ];

        if (isset($data['drawer_cash'])) {
            $drawer                   = $data['drawer_cash'];
            $formatted['drawer_cash'] = [
                'total'      => $this->formatMoney($drawer),
                'cash_in'    => $this->formatMoney($drawer['cash_in']),
                'receivings' => $this->formatMoney($drawer['receivings']),
                'expenses'   => $this->formatMoney($drawer['expenses']),
            ];
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON($formatted);
    }

    /**
     * Returns a chart's sales buckets as JSON for one supported period.
     */
    public function getGraph(): ResponseInterface
    {
        if (! $this->hasPermission('reports_sales')) {
            return $this->deniedResponse();
        }

        $period = (string) ($this->request->getGet('period') ?? 'day');
        $data   = $this->getOverviewModel()->getGraphData($period, $this->getLocalNow());

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON($data);
    }

    /**
     * Renders and auto-prints today's receipt-width sales summary.
     */
    public function getPrintToday(): ResponseInterface
    {
        if (! $this->hasPermission('reports_sales')) {
            return $this->deniedResponse();
        }

        $now                             = $this->getLocalNow();
        $includeReceivingLines           = $this->hasPermission('reports_receivings');
        $receiptData                     = $this->getOverviewModel()->getTodayReceiptData($now, $includeReceivingLines);
        $employeeInfo                    = $this->employee->get_info((int) session('person_id'));
        $data                            = $this->global_view_data;
        $data['receipt_data']            = $receiptData;
        $data['printed_by']              = trim($employeeInfo->first_name . ' ' . $employeeInfo->last_name);
        $data['printed_at']              = $now->format($this->global_view_data['config']['dateformat'] . ' ' . $this->global_view_data['config']['timeformat']);
        $data['include_receiving_lines'] = $includeReceivingLines;

        return $this->response->setBody(view('reports/today_sales_receipt', $data));
    }

    /**
     * Checks whether the signed-in employee has the requested report permission.
     */
    private function hasPermission(string $permissionId): bool
    {
        return $this->employee->has_grant($permissionId, (int) session('person_id'));
    }

    /**
     * Returns the response used when an employee cannot view Sales Reports.
     */
    private function deniedResponse(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => lang('Reports.overview_no_access')]);
    }

    /**
     * Loads the report overview model on the first request that needs figures.
     */
    private function getOverviewModel(): ReportsOverview
    {
        $this->overview ??= model(ReportsOverview::class);

        return $this->overview;
    }

    /**
     * Returns the current time in the configured application timezone.
     */
    private function getLocalNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Formats a money total for the overview page without guessing missing saved pound totals.
     *
     * @param array{total: float|int, lbp_total: int|null} $amount Total and optional saved pound total.
     *
     * @return array{store: string, lbp: string} Formatted store currency and pound amounts.
     */
    private function formatMoney(array $amount): array
    {
        return [
            'store' => to_currency($amount['total']),
            'lbp'   => $amount['lbp_total'] === null ? lang('Reports.overview_lbp_unavailable') : format_lbp($amount['lbp_total']),
        ];
    }

    /**
     * Formats one comparison line with translated wording and a separate LTR amount.
     *
     * @param array{status: string, difference: float} $comparison Comparison result.
     * @param string                                   $period     Period kind used to select localized wording.
     *
     * @return array<string, string> Localized comparison line and amount parts.
     */
    private function formatComparison(array $comparison, string $period): array
    {
        $status  = $comparison['status'];
        $message = '';
        $before  = '';
        $value   = '';
        $after   = '';
        $icon    = '';

        if (in_array($status, ['better', 'worse'], true)) {
            $templateKey = 'Reports.overview_compare_' . $status . '_' . $period;
            $parts       = $this->splitValueTemplate(lang($templateKey), to_currency($comparison['difference']));
            $before      = $parts['before'];
            $value       = $parts['value'];
            $after       = $parts['after'];
            $icon        = $status === 'better' ? 'up' : 'down';
        } elseif ($status === 'same') {
            $message = lang('Reports.overview_compare_same_' . $period);
        }

        return [
            'status'  => $status,
            'message' => $message,
            'before'  => $before,
            'value'   => $value,
            'after'   => $after,
            'icon'    => $icon,
        ];
    }

    /**
     * Splits a translated sentence around its `{0}` value marker for safe LTR markup.
     *
     * @return array{before: string, value: string, after: string} Sentence parts.
     */
    private function splitValueTemplate(string $template, string $value): array
    {
        $position = strpos($template, '{0}');

        if ($position === false) {
            return ['before' => $template, 'value' => $value, 'after' => ''];
        }

        return [
            'before' => substr($template, 0, $position),
            'value'  => $value,
            'after'  => substr($template, $position + 3),
        ];
    }

    /**
     * Formats a best sales period with the shop date format and saved currency totals.
     *
     * @param array{start: string, end: string, total: float, lbp_total: int|null}|null $periodData Best period row.
     * @param string                                                                    $period     Period kind: day, week, or month.
     *
     * @return array{period: string, store: string, lbp: string, no_sales: bool, message: string} Display data.
     */
    private function formatBestPeriod(?array $periodData, string $period): array
    {
        if ($periodData === null) {
            return [
                'period'   => '—',
                'store'    => '—',
                'lbp'      => '',
                'no_sales' => true,
                'message'  => lang('Reports.overview_no_sales_yet'),
            ];
        }

        $dateFormat = (string) ($this->global_view_data['config']['dateformat'] ?? 'Y-m-d');
        $timezone   = new DateTimeZone(date_default_timezone_get());
        $start      = new DateTimeImmutable($periodData['start'], $timezone);
        $end        = new DateTimeImmutable($periodData['end'], $timezone);
        $label      = match ($period) {
            'week'  => $start->format($dateFormat) . ' – ' . $end->format($dateFormat),
            'month' => lang('Calendar.' . strtolower($start->format('F'))) . ' ' . $start->format('Y'),
            default => $start->format($dateFormat),
        };

        return [
            'period'   => $label,
            'store'    => to_currency($periodData['total']),
            'lbp'      => $periodData['lbp_total'] === null ? lang('Reports.overview_lbp_unavailable') : format_lbp($periodData['lbp_total']),
            'no_sales' => false,
            'message'  => '',
        ];
    }
}
