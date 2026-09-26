<?php

namespace Tests;

use App\Database\Migrations\Migration_sale_lbp_total;
use App\Models\Item;
use App\Models\Reports\Summary_sales;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20260926000000_sale_lbp_total.php';

/**
 * Covers the saved pound total through the migration, the sale model and the sales summary report.
 *
 * @internal
 */
final class SaleLbpTotalDatabaseTest extends CIUnitTestCase
{
    private const REPORT_DAY_ONE = '2031-03-01';
    private const REPORT_DAY_TWO = '2031-03-02';

    private $database;
    private ?array $fixture = null;
    private array $sale_ids = [];

    /**
     * Connects to the test database and applies the migration, or skips when no database is reachable.
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

        // A failing migration must fail the test, not skip it.
        $this->migration()->up();
    }

    /**
     * Removes the sales and item created by the test and leaves the migration applied.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->migration()->up();
            $this->removeFixture();

            foreach (['sales_items_taxes_temp', 'sales_payments_temp', 'sales_items_temp'] as $table) {
                $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable($table));
            }
        }

        $this->database = null;
        $this->fixture  = null;
        $this->sale_ids = [];
        parent::tearDown();
    }

    /**
     * Adds both columns, removes them on rollback, and can add them again.
     */
    public function testMigrationAddsAndRemovesColumns(): void
    {
        $this->assertTrue($this->database->fieldExists('lbp_total', 'sales'));
        $this->assertTrue($this->database->fieldExists('lbp_exchange_rate', 'sales'));

        if ($this->database->table('sales')->where('lbp_total IS NOT NULL')->countAllResults() > 0) {
            $this->markTestSkipped('Rolling back would erase saved pound totals in this database.');
        }

        $this->migration()->up();
        $this->migration()->down();
        $this->database->resetDataCache();
        $this->assertFalse($this->database->fieldExists('lbp_total', 'sales'));
        $this->assertFalse($this->database->fieldExists('lbp_exchange_rate', 'sales'));

        $this->migration()->down();
        $this->migration()->up();
        $this->database->resetDataCache();
        $this->assertTrue($this->database->fieldExists('lbp_total', 'sales'));
        $this->assertTrue($this->database->fieldExists('lbp_exchange_rate', 'sales'));
    }

    /**
     * Saves the pound total and rate of a completed sale and clears them when the row is saved as suspended.
     */
    public function testSaveWritesAndClearsPoundValues(): void
    {
        $sale_id = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_POS, [1], 380000, 90000.0);

        $row = $this->getSaleRow($sale_id);
        $this->assertSame('380000', (string) $row['lbp_total']);
        $this->assertSame('90000.0000', (string) $row['lbp_exchange_rate']);

        $info = (new Sale())->get_info($sale_id)->getRowArray();
        $this->assertSame('380000', (string) $info['lbp_total']);
        $this->assertSame('90000.0000', (string) $info['lbp_exchange_rate']);

        $this->saveSale($sale_id, SUSPENDED, SALE_TYPE_POS, [1]);

        $row = $this->getSaleRow($sale_id);
        $this->assertNull($row['lbp_total']);
        $this->assertNull($row['lbp_exchange_rate']);
    }

    /**
     * Counts each sale once however many item lines it has, and nets returns against sales.
     */
    public function testSummaryCountsEachSaleOnceAndNetsReturns(): void
    {
        $sale_id   = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_POS, [1, 2, 3], 300000, 90000.0);
        $return_id = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_RETURN, [-1], -100000, 90000.0);
        $this->moveSaleToDay($sale_id, self::REPORT_DAY_ONE);
        $this->moveSaleToDay($return_id, self::REPORT_DAY_ONE);

        $report = new Summary_sales();
        $inputs = $this->reportInputs(self::REPORT_DAY_ONE, self::REPORT_DAY_ONE, 'complete');
        $rows   = $report->getData($inputs);

        $this->assertCount(1, $rows);
        $this->assertSame(200000, $rows[0]['lbp_total']);
        $this->assertSame(200000, $report->getSummaryData($inputs)['lbp_total']);

        $sales_only = $this->reportInputs(self::REPORT_DAY_ONE, self::REPORT_DAY_ONE, 'sales');
        $this->assertSame(300000, $report->get_lbp_totals($sales_only, false)[0]['lbp_total'] + 0);

        $returns_only = $this->reportInputs(self::REPORT_DAY_ONE, self::REPORT_DAY_ONE, 'returns');
        $this->assertSame(-100000, $report->get_lbp_totals($returns_only, false)[0]['lbp_total'] + 0);
    }

    /**
     * Leaves the pound total blank for a day that has a sale without a saved pound total.
     */
    public function testDayWithOlderSaleShowsBlankPoundTotal(): void
    {
        $new_sale_id = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_POS, [1], 90000, 90000.0);
        $old_sale_id = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_POS, [1]);
        $day_two_id  = $this->saveSale(NEW_ENTRY, COMPLETED, SALE_TYPE_POS, [2], 180000, 90000.0);
        $this->moveSaleToDay($new_sale_id, self::REPORT_DAY_ONE);
        $this->moveSaleToDay($old_sale_id, self::REPORT_DAY_ONE);
        $this->moveSaleToDay($day_two_id, self::REPORT_DAY_TWO);

        $report = new Summary_sales();
        $inputs = $this->reportInputs(self::REPORT_DAY_ONE, self::REPORT_DAY_TWO, 'complete');
        $rows   = array_column($report->getData($inputs), 'lbp_total', 'sale_date');

        $this->assertArrayHasKey(self::REPORT_DAY_ONE, $rows);
        $this->assertNull($rows[self::REPORT_DAY_ONE]);
        $this->assertSame(180000, $rows[self::REPORT_DAY_TWO]);
        $this->assertNull($report->getSummaryData($inputs)['lbp_total']);
    }

    /**
     * Returns the migration under test, bound to the test database.
     */
    private function migration(): Migration_sale_lbp_total
    {
        return new Migration_sale_lbp_total(Database::forge('tests'));
    }

    /**
     * Saves a sale with one cart line per quantity and remembers it for cleanup.
     *
     * @param list<int> $quantities Quantity of each cart line; negative for a return.
     */
    private function saveSale(int $sale_id, int $status, int $sale_type, array $quantities, ?int $lbp_total = null, ?float $lbp_rate = null): int
    {
        $fixture     = $this->getFixture();
        $sale_status = (string) $status;
        $items       = [];

        foreach ($quantities as $index => $quantity) {
            $items[] = [
                'item_id'       => $fixture['item_id'],
                'line'          => $index + 1,
                'description'   => 'Pound total fixture',
                'serialnumber'  => '',
                'quantity'      => $quantity,
                'discount'      => '0.00',
                'discount_type' => PERCENT,
                'cost_price'    => '0.50',
                'price'         => '1.00',
                'item_location' => $fixture['location_id'],
                'print_option'  => PRINT_YES,
            ];
        }

        $taxes    = [[], []];
        $saved_id = (new Sale())->save_value(
            $sale_id,
            $sale_status,
            $items,
            NEW_ENTRY,
            1,
            'Pound total test',
            null,
            null,
            null,
            $sale_type,
            [],
            null,
            $taxes,
            $lbp_total,
            $lbp_rate,
        );

        $this->assertGreaterThan(0, $saved_id);
        $this->sale_ids[] = $saved_id;

        return $saved_id;
    }

    /**
     * Reads one sale row straight from the table.
     *
     * @return array<string, mixed>
     */
    private function getSaleRow(int $sale_id): array
    {
        $row = $this->database->table('sales')->where('sale_id', $sale_id)->get()->getRowArray();
        if ($row === null) {
            throw new RuntimeException('The saved sale is missing.');
        }

        return $row;
    }

    /**
     * Moves a sale to a fixed future day so report totals include only this test's sales.
     */
    private function moveSaleToDay(int $sale_id, string $day): void
    {
        $this->database->table('sales')->where('sale_id', $sale_id)->update(['sale_time' => $day . ' 12:00:00']);
    }

    /**
     * Builds sales summary report filters.
     *
     * @return array{start_date: string, end_date: string, sale_type: string, location_id: string}
     */
    private function reportInputs(string $start_date, string $end_date, string $sale_type): array
    {
        return [
            'start_date'  => $start_date,
            'end_date'    => $end_date,
            'sale_type'   => $sale_type,
            'location_id' => 'all',
        ];
    }

    /**
     * Creates one untracked-stock item in the first active stock location.
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

        $item_data = [
            'name'                  => 'Pound total fixture',
            'category'              => 'Test',
            'supplier_id'           => null,
            'item_number'           => 'lbp-total-' . bin2hex(random_bytes(4)),
            'description'           => 'Pound total fixture',
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
        $item = new Item();
        if (! $item->save_value($item_data)) {
            throw new RuntimeException('Unable to create the pound total item fixture.');
        }

        $this->fixture = [
            'item_id'     => (int) $item_data['item_id'],
            'location_id' => (int) $location->location_id,
        ];

        return $this->fixture;
    }

    /**
     * Deletes the sales and the item created by the test.
     */
    private function removeFixture(): void
    {
        $sale_ids = array_values(array_unique($this->sale_ids));

        if ($sale_ids !== []) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_taxes', 'sales_items', 'sales'] as $table) {
                $this->database->table($table)->whereIn('sale_id', $sale_ids)->delete();
            }
        }

        if ($this->fixture !== null) {
            $item_id = $this->fixture['item_id'];
            $this->database->table('inventory')->where('trans_items', $item_id)->delete();
            $this->database->table('item_quantities')->where('item_id', $item_id)->delete();
            $this->database->table('items')->where('item_id', $item_id)->delete();
        }
    }
}
