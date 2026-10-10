<?php

namespace Tests;

use App\Controllers\Reports_overview;
use App\Controllers\Secure_Controller;
use App\Models\Employee;
use App\Models\ReportsOverview;
use CodeIgniter\Config\Factories;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use DateTimeImmutable;
use ReflectionClass;

/**
 * Checks that the overview endpoints enforce report permissions.
 *
 * @internal
 */
final class ReportsOverviewPermissionTest extends CIUnitTestCase
{
    /**
     * Loads currency settings in memory so permission checks work without a database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('currency');
        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'   => 2,
            'currency_symbol'     => '$',
            'lbp_exchange_rate'   => '90000',
            'language_code'       => 'en',
            'number_locale'       => 'en_US',
            'thousands_separator' => '1',
            'theme'               => 'flatly',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Refuses the graph and receipt endpoints without Sales Reports access.
     */
    public function testSalesReportPermissionIsRequiredForNewEndpoints(): void
    {
        $controller = $this->makeController(['reports_sales' => false]);

        $graphResponse = $controller->getGraph();
        $this->assertDeniedWithoutData($graphResponse);

        $totalsResponse = $controller->getTotals();
        $this->assertDeniedWithoutData($totalsResponse);

        $receiptResponse = $controller->getPrintToday();
        $this->assertDeniedWithoutData($receiptResponse);
    }

    /**
     * Formats each comparison as one money line and omits drawer totals without Receiving Reports access.
     */
    public function testTotalsOmitDrawerWhenReceivingReportPermissionIsMissing(): void
    {
        $overview = $this->createMock(ReportsOverview::class);
        $overview->expects($this->once())
            ->method('getOverview')
            ->with($this->isInstanceOf(DateTimeImmutable::class), false)
            ->willReturn([
                'sales_today' => ['total' => 2.0, 'lbp_total' => 180000],
                'sales_week'  => ['total' => 2.0, 'lbp_total' => 180000],
                'sales_month' => ['total' => 2.0, 'lbp_total' => 180000],
                'comparisons' => [
                    'today' => ['status' => 'better', 'difference' => 12.0],
                    'week'  => ['status' => 'worse', 'difference' => 9.0],
                    'month' => ['status' => 'same', 'difference' => 0.0],
                ],
                'best_sales' => ['day' => null, 'week' => null, 'month' => null],
            ]);
        $controller = $this->makeController(['reports_sales' => true, 'reports_receivings' => false], $overview);

        $response = $controller->getTotals();
        $data     = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('drawer_cash', $data);
        $this->assertArrayHasKey('sales_today', $data);
        $this->assertArrayHasKey('comparisons', $data);
        $this->assertArrayHasKey('best_sales', $data);

        $todayComparison = $data['comparisons']['today'];
        $weekComparison  = $data['comparisons']['week'];
        $monthComparison = $data['comparisons']['month'];

        $this->assertSame(str_replace('{0}', '$12.00', lang('Reports.overview_compare_better_today')), $todayComparison['before'] . $todayComparison['value'] . $todayComparison['after']);
        $this->assertSame(str_replace('{0}', '$9.00', lang('Reports.overview_compare_worse_week')), $weekComparison['before'] . $weekComparison['value'] . $weekComparison['after']);
        $this->assertSame(lang('Reports.overview_compare_same_month'), $monthComparison['message']);
        $this->assertSame('up', $todayComparison['icon']);
        $this->assertSame('down', $weekComparison['icon']);
        $this->assertSame('', $monthComparison['icon']);
        $this->assertSame(['status', 'message', 'before', 'value', 'after', 'icon'], array_keys($todayComparison));
        $this->assertStringNotContainsString('%', $response->getBody());
    }

    /**
     * Formats drawer values as dollars while keeping saved pound values on the sales tiles.
     */
    public function testTotalsKeepPoundsOnlyOnSalesTiles(): void
    {
        $overview = $this->createMock(ReportsOverview::class);
        $overview->expects($this->once())
            ->method('getOverview')
            ->with($this->isInstanceOf(DateTimeImmutable::class), true)
            ->willReturn([
                'sales_today' => ['total' => 4.0, 'lbp_total' => 360000],
                'sales_week'  => ['total' => 4.0, 'lbp_total' => 360000],
                'sales_month' => ['total' => 4.0, 'lbp_total' => 360000],
                'comparisons' => [
                    'today' => ['status' => 'same', 'difference' => 0.0],
                    'week'  => ['status' => 'same', 'difference' => 0.0],
                    'month' => ['status' => 'same', 'difference' => 0.0],
                ],
                'best_sales'  => ['day' => null, 'week' => null, 'month' => null],
                'drawer_cash' => [
                    'total'      => 4.0,
                    'cash_in'    => ['total' => 6.0],
                    'receivings' => ['total' => 1.0],
                    'expenses'   => ['total' => 1.0],
                ],
            ]);
        $controller = $this->makeController(['reports_sales' => true, 'reports_receivings' => true], $overview);

        $response = $controller->getTotals();
        $data     = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('$4.00', $data['sales_today']['store']);
        $this->assertSame(format_lbp(360000), $data['sales_today']['lbp']);
        $this->assertSame([
            'total'      => '$4.00',
            'cash_in'    => '$6.00',
            'receivings' => '$1.00',
            'expenses'   => '$1.00',
        ], $data['drawer_cash']);
    }

    /**
     * Hides drawer cash on the printed receipt without Receiving Reports access.
     */
    public function testReceiptHidesDrawerWhenReceivingReportPermissionIsMissing(): void
    {
        $response = $this->makeReceiptResponse([
            'sales_total'     => ['total' => 2.0, 'lbp_total' => 180000],
            'sales_count'     => 1,
            'returns_count'   => 0,
            'payments'        => ['Cash' => ['total' => 2.0]],
            'drawer_cash'     => ['total' => 2.0],
            'cash_receivings' => ['total' => 0.0],
            'cash_expenses'   => ['total' => 0.0],
        ], false, 'shop');
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(lang('Reports.overview_total_sales'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_drawer_cash'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_cash_paid_receivings'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_cash_paid_expenses'), $body);
    }

    /**
     * Prints saved pounds only for Total sales and one dollar amount for payment and drawer rows in both till layouts.
     */
    public function testPrintedReceiptShowsPoundsOnlyForTotalSalesInBothTillLayouts(): void
    {
        $savedSettings = config(OSPOS::class)->settings;

        try {
            foreach (['shop', 'restaurant'] as $layout) {
                config(OSPOS::class)->settings['till_layout'] = $layout;
                $receiptData                                  = [
                    'sales_total'   => ['total' => 4.0, 'lbp_total' => 360000],
                    'sales_count'   => 2,
                    'returns_count' => 0,
                    'payments'      => [
                        'Cash' => ['total' => 6.0],
                        'Card' => ['total' => 2.0],
                    ],
                    'drawer_cash'     => ['total' => 4.0],
                    'cash_receivings' => ['total' => 1.0],
                    'cash_expenses'   => ['total' => 1.0],
                ];
                $response = $this->makeReceiptResponse($receiptData, true, $layout);
                $body     = $response->getBody();

                $this->assertSame(200, $response->getStatusCode(), $layout);
                preg_match_all('/<tr\b[^>]*>.*?<\/tr>/s', $body, $rowMatches);
                $salesRows   = array_values(array_filter($rowMatches[0], static fn (string $row): bool => str_contains($row, esc(lang('Reports.overview_total_sales')))));
                $poundFigure = esc(format_lbp(360000));

                $this->assertCount(1, $salesRows, $layout);
                $this->assertStringContainsString($poundFigure, $salesRows[0], $layout);
                $this->assertSame(2, substr_count($salesRows[0], '<span dir="ltr">'), $layout);

                $dollarOnlyLabels = array_merge(
                    array_keys($receiptData['payments']),
                    [
                        lang('Reports.overview_cash_paid_receivings'),
                        lang('Reports.overview_cash_paid_expenses'),
                        lang('Reports.overview_drawer_cash'),
                    ],
                );

                foreach ($dollarOnlyLabels as $label) {
                    $labelMarkup  = '<bdi dir="auto">' . esc($label) . '</bdi>';
                    $matchingRows = array_values(array_filter($rowMatches[0], static fn (string $row): bool => str_contains($row, $labelMarkup)));

                    $this->assertCount(1, $matchingRows, $layout . ': ' . $label);
                    $this->assertSame(1, substr_count($matchingRows[0], '<span dir="ltr">'), $layout . ': ' . $label);
                    $this->assertStringNotContainsString($poundFigure, $matchingRows[0], $layout . ': ' . $label);
                }

                $this->assertSame(1, substr_count($body, $poundFigure), $layout);
            }
        } finally {
            config(OSPOS::class)->settings = $savedSettings;
        }
    }

    /**
     * Renders today's receipt through a mocked report model for the selected permissions and till layout.
     *
     * @param array<string, mixed> $receiptData Receipt rows returned by the report model.
     */
    private function makeReceiptResponse(array $receiptData, bool $includeReceivingLines, string $tillLayout): ResponseInterface
    {
        $viewEmployee = $this->createMock(Employee::class);
        $viewEmployee->method('is_logged_in')->willReturn(false);
        Factories::injectMock('models', Employee::class, $viewEmployee);

        $overview = $this->createMock(ReportsOverview::class);
        $overview->expects($this->once())
            ->method('getTodayReceiptData')
            ->with($this->isInstanceOf(DateTimeImmutable::class), $includeReceivingLines)
            ->willReturn($receiptData);
        $controller                   = $this->makeController(['reports_sales' => true, 'reports_receivings' => $includeReceivingLines], $overview);
        $controller->global_view_data = [
            'allowed_modules' => [],
            'user_info'       => (object) ['person_id' => 42, 'first_name' => 'Test', 'last_name' => 'Employee'],
            'config'          => [
                'address'                    => 'Test address',
                'company'                    => 'Test shop',
                'dateformat'                 => 'Y-m-d',
                'notify_horizontal_position' => 'right',
                'notify_vertical_position'   => 'top',
                'print_bottom_margin'        => '0',
                'print_delay_autoreturn'     => 0,
                'print_footer'               => false,
                'print_header'               => false,
                'print_left_margin'          => '0',
                'print_right_margin'         => '0',
                'print_silently'             => false,
                'print_top_margin'           => '0',
                'receipt_font_size'          => 12,
                'receipt_show_company_name'  => true,
                'theme'                      => 'flatly',
                'timeformat'                 => 'H:i',
                'till_layout'                => $tillLayout,
            ],
        ];
        view('viewData', $controller->global_view_data);

        return $controller->getPrintToday();
    }

    /**
     * Builds a controller action instance with permission and response services set for the test.
     *
     * @param array<string, bool> $grants Grants to return for the test employee.
     */
    private function makeController(array $grants, ?ReportsOverview $overview = null): Reports_overview
    {
        session()->set('person_id', 42);
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->willReturnCallback(static fn (string $permissionId, ?int $personId): bool => $grants[$permissionId] ?? false);
        $employee->method('get_info')->willReturn((object) ['first_name' => 'Test', 'last_name' => 'Employee']);

        $reflection = new ReflectionClass(Reports_overview::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        (new ReflectionClass(Secure_Controller::class))->getProperty('employee')->setValue($controller, $employee);
        (new ReflectionClass(Controller::class))->getProperty('response')->setValue($controller, service('response'));
        $response = service('response');
        $response->setStatusCode(200)->setBody('');

        if ($overview !== null) {
            $reflection->getProperty('overview')->setValue($controller, $overview);
        }

        return $controller;
    }

    /**
     * Asserts that a denied endpoint returns no report data.
     */
    private function assertDeniedWithoutData(ResponseInterface $response): void
    {
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayNotHasKey('sales_today', $data);
        $this->assertArrayNotHasKey('labels', $data);
    }
}
