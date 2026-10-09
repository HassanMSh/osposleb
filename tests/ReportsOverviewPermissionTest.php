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
     * Leaves the drawer total out of the tile JSON when Receiving Reports access is missing.
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
            ]);
        $controller = $this->makeController(['reports_sales' => true, 'reports_receivings' => false], $overview);

        $response = $controller->getTotals();
        $data     = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('drawer_cash', $data);
        $this->assertArrayHasKey('sales_today', $data);
    }

    /**
     * Hides drawer cash on the printed receipt without Receiving Reports access.
     */
    public function testReceiptHidesDrawerWhenReceivingReportPermissionIsMissing(): void
    {
        $viewEmployee = $this->createMock(Employee::class);
        $viewEmployee->method('is_logged_in')->willReturn(false);
        Factories::injectMock('models', Employee::class, $viewEmployee);

        $overview = $this->createMock(ReportsOverview::class);
        $overview->expects($this->once())
            ->method('getTodayReceiptData')
            ->with($this->isInstanceOf(DateTimeImmutable::class), false)
            ->willReturn([
                'sales_total'     => ['total' => 2.0, 'lbp_total' => 180000],
                'sales_count'     => 1,
                'returns_count'   => 0,
                'payments'        => ['Cash' => ['total' => 2.0, 'lbp_total' => 180000]],
                'drawer_cash'     => ['total' => 2.0, 'lbp_total' => 180000],
                'cash_receivings' => ['total' => 0.0, 'lbp_total' => 0],
                'cash_expenses'   => ['total' => 0.0, 'lbp_total' => 0],
            ]);
        $controller                   = $this->makeController(['reports_sales' => true, 'reports_receivings' => false], $overview);
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
                'timeformat'                 => 'H:i',
                'theme'                      => 'flatly',
            ],
        ];
        view('viewData', $controller->global_view_data);

        $response = $controller->getPrintToday();
        $body     = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(lang('Reports.overview_total_sales'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_drawer_cash'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_cash_paid_receivings'), $body);
        $this->assertStringNotContainsString(lang('Reports.overview_cash_paid_expenses'), $body);
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
