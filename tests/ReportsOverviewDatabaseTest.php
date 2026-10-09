<?php

namespace Tests;

use App\Models\Item;
use App\Models\Reports\Summary_sales;
use App\Models\ReportsOverview;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20260926000000_sale_lbp_total.php';

/**
 * Checks the overview figures against seeded sales, returns, receivings, and expenses.
 *
 * @internal
 */
final class ReportsOverviewDatabaseTest extends CIUnitTestCase
{
    private const REPORT_NOW = '2031-03-03 12:30:00';

    private ?BaseConnection $database    = null;
    private ?array $fixture              = null;
    private array $saleIds               = [];
    private array $receivingIds          = [];
    private array $expenseIds            = [];
    private bool $createdExpenseCategory = false;
    private int $expenseCategoryId       = 0;
    private int $employeeId              = 0;
    private mixed $savedRate             = null;
    private bool $hadRate                = false;
    private mixed $savedDateMode         = null;
    private bool $hadDateMode            = false;
    private bool $settingsChanged        = false;

    /**
     * Connects to the test database and saves the settings used by these report checks.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->database = null;
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }

        $settings                                             = config(OSPOS::class)->settings;
        $this->hadRate                                        = array_key_exists('lbp_exchange_rate', $settings);
        $this->savedRate                                      = $settings['lbp_exchange_rate'] ?? null;
        $this->hadDateMode                                    = array_key_exists('date_or_time_format', $settings);
        $this->savedDateMode                                  = $settings['date_or_time_format'] ?? null;
        $this->settingsChanged                                = true;
        config(OSPOS::class)->settings['lbp_exchange_rate']   = '90000';
        config(OSPOS::class)->settings['date_or_time_format'] = '';

        (new \App\Database\Migrations\Migration_sale_lbp_total(Database::forge('tests')))->up();

        $employee = $this->database->table('employees')
            ->select('employees.person_id')
            ->where('employees.deleted', 0)
            ->orderBy('employees.person_id', 'asc')
            ->get()
            ->getRow();
        if ($employee === null) {
            throw new RuntimeException('The test database has no active employee.');
        }
        $this->employeeId = (int) $employee->person_id;

        $category = $this->database->table('expense_categories')
            ->select('expense_category_id')
            ->orderBy('expense_category_id', 'asc')
            ->get()
            ->getRow();
        if ($category === null) {
            $this->database->table('expense_categories')->insert([
                'category_name'        => 'Reports overview fixture',
                'category_description' => '',
                'deleted'              => 0,
            ]);
            $this->expenseCategoryId      = (int) $this->database->insertID();
            $this->createdExpenseCategory = true;
        } else {
            $this->expenseCategoryId = (int) $category->expense_category_id;
        }
    }

    /**
     * Removes only this test's rows and restores the saved application settings.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            if ($this->expenseIds !== []) {
                $this->database->table('expenses')->whereIn('expense_id', $this->expenseIds)->delete();
            }

            foreach ($this->receivingIds as $receivingId) {
                $this->database->table('receivings_items')->where('receiving_id', $receivingId)->delete();
                $this->database->table('receivings')->where('receiving_id', $receivingId)->delete();
            }

            $this->removeSales();

            if ($this->createdExpenseCategory) {
                $this->database->table('expense_categories')->where('expense_category_id', $this->expenseCategoryId)->delete();
            }

            foreach ([
                'sales_items_taxes_temp',
                'sales_payments_temp',
                'sumpay_taxes_temp',
                'sumpay_items_temp',
                'sumpay_payments_temp',
                'receivings_items_temp',
            ] as $tableName) {
                $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable($tableName));
            }
        }

        if ($this->settingsChanged) {
            if ($this->hadRate) {
                config(OSPOS::class)->settings['lbp_exchange_rate'] = $this->savedRate;
            } else {
                unset(config(OSPOS::class)->settings['lbp_exchange_rate']);
            }

            if ($this->hadDateMode) {
                config(OSPOS::class)->settings['date_or_time_format'] = $this->savedDateMode;
            } else {
                unset(config(OSPOS::class)->settings['date_or_time_format']);
            }
        }

        $this->database = null;
        parent::tearDown();
    }

    /**
     * Uses the Summary Sales report for all tile ranges and subtracts cash receivings and expenses.
     */
    public function testTilesMatchSummarySalesAndDrawerFormula(): void
    {
        $this->seedSales();
        $this->addReceiving('2031-03-03 08:00:00', 3.0, lang('Sales.cash'), 270000);
        $this->addReceiving('2031-03-03 08:30:00', 5.0, lang('Sales.credit'), 450000);
        $this->addExpense('2031-03-03 09:00:00', 2.0, lang('Expenses.cash'));
        $this->addExpense('2031-03-03 09:30:00', 4.0, lang('Expenses.due'));

        $model    = new ReportsOverview();
        $now      = $this->reportNow();
        $overview = $model->getOverview($now, true);

        $this->assertSame(45.0, $overview['sales_today']['total']);
        $this->assertSame(45.0, $overview['sales_week']['total']);
        $this->assertSame(55.0, $overview['sales_month']['total']);
        $this->assertSame(4050000, $overview['sales_today']['lbp_total']);
        $this->assertSame(20.0, $overview['drawer_cash']['total']);
        $this->assertSame(1800000, $overview['drawer_cash']['lbp_total']);
        $this->assertSame(25.0, $overview['drawer_cash']['cash_in']['total']);
        $this->assertSame(3.0, $overview['drawer_cash']['receivings']['total']);
        $this->assertSame(270000, $overview['drawer_cash']['receivings']['lbp_total']);
        $this->assertSame(2.0, $overview['drawer_cash']['expenses']['total']);
        $this->assertSame(180000, $overview['drawer_cash']['expenses']['lbp_total']);

        $this->assertEqualsWithDelta($overview['sales_today']['total'], $this->summarySalesTotal('2031-03-03', '2031-03-03'), 0.001);
        $this->assertEqualsWithDelta($overview['sales_week']['total'], $this->summarySalesTotal('2031-03-03', '2031-03-03'), 0.001);
        $this->assertEqualsWithDelta($overview['sales_month']['total'], $this->summarySalesTotal('2031-03-01', '2031-03-03'), 0.001);

        $receipt = $model->getTodayReceiptData($now, true);
        $this->assertSame(2, $receipt['sales_count']);
        $this->assertSame(1, $receipt['returns_count']);
        $this->assertSame(25.0, $receipt['payments'][lang('Sales.cash')]['total']);
        $this->assertSame(20.0, $receipt['payments'][lang('Sales.credit')]['total']);
        $this->assertArrayHasKey('cash_receivings', $receipt);
        $this->assertArrayHasKey('cash_expenses', $receipt);

        $withoutReceivingAccess        = $model->getOverview($now, false);
        $receiptWithoutReceivingAccess = $model->getTodayReceiptData($now, false);
        $this->assertArrayNotHasKey('drawer_cash', $withoutReceivingAccess);
        $this->assertArrayNotHasKey('cash_receivings', $receiptWithoutReceivingAccess);
        $this->assertArrayNotHasKey('cash_expenses', $receiptWithoutReceivingAccess);
    }

    /**
     * Omits drawer cash and its parts from receipt data without Receiving Reports access.
     */
    public function testReceiptOmitsDrawerCashWhenReceivingReportPermissionIsMissing(): void
    {
        $this->seedSales();

        $receipt = (new ReportsOverview())->getTodayReceiptData($this->reportNow(), false);

        $this->assertArrayNotHasKey('drawer_cash', $receipt);
        $this->assertArrayNotHasKey('cash_receivings', $receipt);
        $this->assertArrayNotHasKey('cash_expenses', $receipt);
        $this->assertArrayHasKey('sales_total', $receipt);
        $this->assertArrayHasKey('payments', $receipt);
    }

    /**
     * Excludes future-timestamped sales from tiles, receipt figures, and every graph range.
     */
    public function testDateOnlyReportsStopAtTheCurrentTimestamp(): void
    {
        $this->saveSale('2031-03-03 13:00:00', 99.0, lang('Sales.cash'), 8910000);
        $model = new ReportsOverview();
        $now   = $this->reportNow();

        $overview = $model->getOverview($now, true);
        $this->assertSame(0.0, $overview['sales_today']['total']);
        $this->assertSame(0.0, $overview['sales_week']['total']);
        $this->assertSame(0.0, $overview['sales_month']['total']);
        $this->assertSame(0.0, $overview['drawer_cash']['total']);
        $this->assertSame(0.0, $overview['drawer_cash']['cash_in']['total']);

        $receipt = $model->getTodayReceiptData($now, true);
        $this->assertSame(0.0, $receipt['sales_total']['total']);
        $this->assertSame(0, $receipt['sales_count']);
        $this->assertSame([], $receipt['payments']);
        $this->assertSame(0.0, $receipt['drawer_cash']['total']);

        foreach (['hour', 'day', 'week', 'month', 'year'] as $period) {
            $graph = $model->getGraphData($period, $now);
            $this->assertSame(0.0, array_sum($graph['values']), $period);
        }

        $this->assertSame('', config(OSPOS::class)->settings['date_or_time_format']);
    }

    /**
     * Returns empty periods as zero and fills every requested chart bucket for all five ranges.
     */
    public function testGraphReturnsTheRightSalesInEveryBucket(): void
    {
        $this->seedSales();
        $model = new ReportsOverview();
        $now   = $this->reportNow();

        $hour = $model->getGraphData('hour', $now);
        $this->assertCount(24, $hour['labels']);
        $this->assertSame(30.0, $hour['values'][9]);
        $this->assertSame(20.0, $hour['values'][10]);
        $this->assertSame(-5.0, $hour['values'][11]);
        $this->assertSame(0.0, $hour['values'][23]);

        $day = $model->getGraphData('day', $now);
        $this->assertCount(30, $day['labels']);
        $this->assertSame(45.0, $this->valueForLabel($day, '03/03'));
        $this->assertSame(10.0, $this->valueForLabel($day, '02/03'));
        $this->assertSame(7.0, $this->valueForLabel($day, '28/02'));
        $this->assertSame(0.0, $day['values'][0]);

        $week = $model->getGraphData('week', $now);
        $this->assertCount(12, $week['labels']);
        $this->assertSame(45.0, $this->valueForLabel($week, '03/03'));
        $this->assertSame(17.0, $this->valueForLabel($week, '24/02'));
        $this->assertSame(0.0, $week['values'][0]);

        $month = $model->getGraphData('month', $now);
        $this->assertCount(12, $month['labels']);
        $this->assertSame(7.0, $this->valueForLabel($month, lang('Calendar.february') . ' 2031'));
        $this->assertSame(55.0, $this->valueForLabel($month, lang('Calendar.march') . ' 2031'));
        $this->assertSame(0.0, $month['values'][0]);

        $year = $model->getGraphData('year', $now);
        $this->assertCount(5, $year['labels']);
        $this->assertSame(62.0, $this->valueForLabel($year, '2031'));
        $this->assertSame(0.0, $this->valueForLabel($year, '2027'));
    }

    /**
     * Leaves the pound total unknown when any completed sale has no saved pound amount.
     */
    public function testMissingSavedSalePoundTotalRemainsUnknown(): void
    {
        $this->seedSales();
        $this->saveSale('2031-03-03 12:00:00', 1.0, lang('Sales.cash'), null);

        $overview = (new ReportsOverview())->getOverview($this->reportNow(), false);

        $this->assertSame(46.0, $overview['sales_today']['total']);
        $this->assertNull($overview['sales_today']['lbp_total']);
    }

    /**
     * Counts cash payment labels from English and Arabic for both request languages and merges receipt rows.
     */
    public function testCashPaymentsAreRecognizedAcrossLanguagesAndMergedOnReceipt(): void
    {
        $this->saveSale('2031-03-03 09:00:00', 8.0, 'Cash', 720000);
        $this->saveSale('2031-03-03 09:30:00', 12.0, 'نقدي', 1080000);
        $this->saveSale('2031-03-03 10:00:00', 4.0, 'Credit Card', 360000);
        $this->addReceiving('2031-03-03 08:00:00', 2.0, 'Cash', 180000);
        $this->addReceiving('2031-03-03 08:30:00', 3.0, 'نقدي', 270000);
        $this->addReceiving('2031-03-03 08:45:00', 10.0, 'Credit Card', 900000);
        $this->addExpense('2031-03-03 09:00:00', 1.0, 'Cash');
        $this->addExpense('2031-03-03 09:15:00', 2.0, 'نقدي');
        $this->addExpense('2031-03-03 09:30:00', 4.0, 'نقدا');
        $this->addExpense('2031-03-03 09:45:00', 10.0, 'Credit Card');

        $language     = service('language');
        $request      = service('request');
        $savedLocale  = $language->getLocale();
        $savedRequest = $request->getLocale();
        $now          = $this->reportNow();

        try {
            foreach ([['en', 'Cash'], ['ar-LB', 'نقدي']] as [$locale, $cashLabel]) {
                $language->setLocale($locale);
                $request->setLocale($locale);

                $model    = new ReportsOverview();
                $overview = $model->getOverview($now, true);
                $receipt  = $model->getTodayReceiptData($now, true);

                $this->assertSame(8.0, $overview['drawer_cash']['total'], $locale);
                $this->assertSame(20.0, $overview['drawer_cash']['cash_in']['total'], $locale);
                $this->assertSame(5.0, $overview['drawer_cash']['receivings']['total'], $locale);
                $this->assertSame(7.0, $overview['drawer_cash']['expenses']['total'], $locale);
                $this->assertSame(20.0, $receipt['payments'][$cashLabel]['total'], $locale);
                $this->assertCount(2, $receipt['payments'], $locale);
                $this->assertSame(5.0, $receipt['cash_receivings']['total'], $locale);
                $this->assertSame(7.0, $receipt['cash_expenses']['total'], $locale);
            }
        } finally {
            $language->setLocale($savedLocale);
            $request->setLocale($savedRequest);
        }
    }

    /**
     * Seeds sales on Sunday, Monday, and the last day of the previous month.
     */
    private function seedSales(): void
    {
        $this->saveSale('2031-03-02 14:00:00', 10.0, lang('Sales.cash'), 900000);
        $this->saveSale('2031-03-03 09:00:00', 30.0, lang('Sales.cash'), 2700000);
        $this->saveSale('2031-03-03 10:00:00', 20.0, lang('Sales.credit'), 1800000);
        $this->saveSale('2031-03-03 11:00:00', 5.0, lang('Sales.cash'), -450000, SALE_TYPE_RETURN);
        $this->saveSale('2031-02-28 12:00:00', 7.0, lang('Sales.cash'), 630000);
    }

    /**
     * Saves a completed sale or return, sets its report date and stores its payment type.
     */
    private function saveSale(string $saleTime, float $amount, string $paymentType, ?int $lbpTotal, int $saleType = SALE_TYPE_POS): int
    {
        $fixture    = $this->getFixture();
        $saleStatus = (string) COMPLETED;
        $quantity   = $saleType === SALE_TYPE_RETURN ? -1 : 1;
        $paidAmount = $quantity * $amount;
        $items      = [[
            'item_id'       => $fixture['item_id'],
            'line'          => 1,
            'description'   => 'Reports overview fixture',
            'serialnumber'  => '',
            'quantity'      => $quantity,
            'discount'      => '0.00',
            'discount_type' => PERCENT,
            'cost_price'    => '0.50',
            'price'         => number_format($amount, 2, '.', ''),
            'item_location' => $fixture['location_id'],
            'print_option'  => PRINT_YES,
        ]];
        $payments = [[
            'payment_type'    => lang('Sales.cash'),
            'payment_amount'  => $paidAmount,
            'cash_refund'     => 0,
            'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
        ]];
        $taxes  = [[], []];
        $saleId = (new Sale())->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $this->employeeId,
            'Reports overview fixture',
            null,
            null,
            null,
            $saleType,
            $payments,
            null,
            $taxes,
            $lbpTotal,
            $lbpTotal === null ? null : 90000.0,
        );

        if ($saleId < 1) {
            throw new RuntimeException('Unable to create a Reports overview sale fixture.');
        }

        $this->saleIds[] = $saleId;
        $this->database->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $saleTime]);
        $this->database->table('sales_payments')->where('sale_id', $saleId)->update(['payment_type' => $paymentType]);

        return $saleId;
    }

    /**
     * Adds a saved receiving total for the report's cash payment filter.
     */
    private function addReceiving(string $receivingTime, float $amount, string $paymentType, int $lbpTotal): void
    {
        $fixture = $this->getFixture();
        $this->database->table('receivings')->insert([
            'receiving_time'    => $receivingTime,
            'supplier_id'       => null,
            'employee_id'       => $this->employeeId,
            'comment'           => 'Reports overview fixture',
            'payment_type'      => $paymentType,
            'reference'         => null,
            'lbp_total'         => $lbpTotal,
            'lbp_exchange_rate' => 90000,
        ]);
        $receivingId          = (int) $this->database->insertID();
        $this->receivingIds[] = $receivingId;
        $this->database->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $fixture['item_id'],
            'description'        => 'Reports overview fixture',
            'serialnumber'       => '',
            'line'               => 1,
            'quantity_purchased' => 1,
            'item_cost_price'    => 0.5,
            'item_unit_price'    => $amount,
            'discount'           => 0,
            'discount_type'      => PERCENT,
            'item_location'      => $fixture['location_id'],
            'receiving_quantity' => 1,
        ]);
    }

    /**
     * Adds a saved expense for a payment type and date.
     */
    private function addExpense(string $date, float $amount, string $paymentType): void
    {
        $this->database->table('expenses')->insert([
            'date'                => $date,
            'amount'              => $amount,
            'payment_type'        => $paymentType,
            'expense_category_id' => $this->expenseCategoryId,
            'description'         => 'Reports overview fixture',
            'employee_id'         => $this->employeeId,
            'deleted'             => 0,
        ]);
        $this->expenseIds[] = (int) $this->database->insertID();
    }

    /**
     * Returns the current Summary Sales total for a date range.
     */
    private function summarySalesTotal(string $start, string $end): float
    {
        foreach (['sales_items_taxes_temp', 'sales_payments_temp'] as $tableName) {
            $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable($tableName));
        }

        $summary = (new Summary_sales())->getSummaryData([
            'start_date'  => $start,
            'end_date'    => $end,
            'sale_type'   => 'complete',
            'location_id' => 'all',
        ]);

        return (float) ($summary['total'] ?? 0);
    }

    /**
     * Returns the seeded item and first active location, creating the item once per test.
     *
     * @return array{item_id: int, location_id: int}
     */
    private function getFixture(): array
    {
        if ($this->fixture !== null) {
            return $this->fixture;
        }

        $location = $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->orderBy('location_id', 'asc')
            ->get()
            ->getRow();
        if ($location === null) {
            throw new RuntimeException('The test database has no active stock location.');
        }

        $itemData = [
            'name'                  => 'Reports overview fixture',
            'category'              => 'Test',
            'supplier_id'           => null,
            'item_number'           => 'reports-overview-' . bin2hex(random_bytes(4)),
            'description'           => 'Reports overview fixture',
            'cost_price'            => '0.50',
            'unit_price'            => '1.00',
            'reorder_level'         => 0,
            'receiving_quantity'    => 1,
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => 0,
            'stock_type'            => HAS_NO_STOCK,
            'item_type'             => ITEM,
            'tax_category_id'       => null,
            'taxable'               => 0,
            'tax_exemption_reason'  => 'exempt',
            'pic_filename'          => '',
            'qty_per_pack'          => 1,
            'pack_name'             => 'Each',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ];
        if (! (new Item())->save_value($itemData)) {
            throw new RuntimeException('Unable to create the Reports overview item fixture.');
        }

        $this->fixture = [
            'item_id'     => (int) $itemData['item_id'],
            'location_id' => (int) $location->location_id,
        ];

        return $this->fixture;
    }

    /**
     * Removes the sales, payment rows, inventory entries, and item created by this test.
     */
    private function removeSales(): void
    {
        if ($this->saleIds !== []) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_taxes', 'sales_items', 'sales'] as $tableName) {
                $this->database->table($tableName)->whereIn('sale_id', $this->saleIds)->delete();
            }

            foreach ($this->saleIds as $saleId) {
                $this->database->table('inventory')->where('trans_comment', 'POS ' . $saleId)->delete();
            }
        }

        if ($this->fixture !== null) {
            $itemId = $this->fixture['item_id'];
            $this->database->table('inventory')->where('trans_items', $itemId)->delete();
            $this->database->table('item_quantities')->where('item_id', $itemId)->delete();
            $this->database->table('items_taxes')->where('item_id', $itemId)->delete();
            $this->database->table('items')->where('item_id', $itemId)->delete();
        }
    }

    /**
     * Builds the fixed test time in the configured application timezone.
     */
    private function reportNow(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::REPORT_NOW, new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Returns one chart value by its display label.
     *
     * @param array{labels: list<string>, values: list<float>} $graph Chart data.
     */
    private function valueForLabel(array $graph, string $label): float
    {
        $index = array_search($label, $graph['labels'], true);

        if ($index === false) {
            throw new RuntimeException('The chart does not contain label ' . $label . '.');
        }

        return $graph['values'][$index];
    }
}
