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
}
