<?php

namespace Tests;

use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Receiving;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use RuntimeException;
use Throwable;

/**
 * Covers receiving cost averages, first deliveries, returns, and invalid rates.
 *
 * @internal
 */
final class ReceivingAverageCostDatabaseTest extends CIUnitTestCase
{
    private ?BaseConnection $database    = null;
    private array $item_ids              = [];
    private array $receiving_ids         = [];
    private bool $had_average_setting    = false;
    private mixed $saved_average_setting = null;
    private bool $had_rate_setting       = false;
    private mixed $saved_rate_setting    = null;
    private int $location_id             = 0;
    private int $administrator_id        = 0;

    /**
     * Connects to the test database and saves the settings that these tests change.
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

        $settings                                                           = config(OSPOS::class)->settings;
        $this->had_average_setting                                          = array_key_exists('receiving_calculate_average_price', $settings);
        $this->saved_average_setting                                        = $settings['receiving_calculate_average_price'] ?? null;
        $this->had_rate_setting                                             = array_key_exists('lbp_exchange_rate', $settings);
        $this->saved_rate_setting                                           = $settings['lbp_exchange_rate'] ?? null;
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = true;
        config(OSPOS::class)->settings['lbp_exchange_rate']                 = '90000';

        $location = $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->orderBy('location_id', 'asc')
            ->get()
            ->getRow();
        if ($location === null) {
            throw new RuntimeException('The test database has no active stock location.');
        }
        $this->location_id = (int) $location->location_id;

        $administrator = $this->database->table('employees')
            ->select('employees.person_id')
            ->join('grants', 'grants.person_id = employees.person_id')
            ->where('employees.deleted', 0)
            ->where('grants.permission_id', 'config')
            ->orderBy('employees.person_id', 'asc')
            ->get()
            ->getRow();
        if ($administrator === null) {
            throw new RuntimeException('The test database has no active administrator.');
        }
        $this->administrator_id = (int) $administrator->person_id;
    }

    /**
     * Removes test items and receipts, then restores the saved application settings.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->transRollback();

            foreach ($this->receiving_ids as $receiving_id) {
                $this->database->table('inventory')->where('trans_comment', 'RECV ' . $receiving_id)->delete();
                $this->database->table('receivings_items')->where('receiving_id', $receiving_id)->delete();
                $this->database->table('receivings')->where('receiving_id', $receiving_id)->delete();
            }

            foreach ($this->item_ids as $item_id) {
                $this->database->table('item_quantities')->where('item_id', $item_id)->delete();
                $this->database->table('items_taxes')->where('item_id', $item_id)->delete();
                $this->database->table('items')->where('item_id', $item_id)->delete();
            }
        }

        if ($this->had_average_setting) {
            config(OSPOS::class)->settings['receiving_calculate_average_price'] = $this->saved_average_setting;
        } else {
            unset(config(OSPOS::class)->settings['receiving_calculate_average_price']);
        }

        if ($this->had_rate_setting) {
            config(OSPOS::class)->settings['lbp_exchange_rate'] = $this->saved_rate_setting;
        } else {
            unset(config(OSPOS::class)->settings['lbp_exchange_rate']);
        }

        $this->database = null;
        parent::tearDown();
    }

    /**
     * Rounds the case 1 average of exactly 91,500 LL up to 92,000 LL, saved as $1.02.
     *
     * The issue table lists 91,494 LL, the old code's average cut to 4 decimals.
     */
    public function testCase01ExactHalfAverageRoundsUp(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        $this->completeReceiving($item_id, 2, '1.10');

        $this->assertSame('1.02', $this->savedCost($item_id));
    }

    /**
     * Rounds an average of 91,350 LL down to 91,000 LL, saved as $1.01.
     */
    public function testAverageRoundsDown(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        $this->completeReceiving($item_id, 2, '1.09');

        $this->assertSame('1.01', $this->savedCost($item_id));
    }

    /**
     * Rounds the case 2 average up to $1.67 at a 90,000 LL exchange rate.
     */
    public function testCase02RoundsUp(): void
    {
        $item_id = $this->createStockItem('1.50', 10);
        $this->completeReceiving($item_id, 5, '2.00');

        $this->assertSame('1.67', $this->savedCost($item_id));
    }

    /**
     * Rounds the case 3 exact half upward to $1.26 at a 90,000 LL exchange rate.
     */
    public function testCase03ExactHalfRoundsUp(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        $this->completeReceiving($item_id, 10, '1.50');

        $this->assertSame('1.26', $this->savedCost($item_id));
    }

    /**
     * Keeps the case 4 average on its existing 1,000 LL step.
     */
    public function testCase04AlreadyOnStep(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        $this->completeReceiving($item_id, 10, '2.00');

        $this->assertSame('1.50', $this->savedCost($item_id));
    }

    /**
     * Rounds the case 5 cheap average to $0.09.
     */
    public function testCase05CheapItemRoundsToEightThousandPounds(): void
    {
        $item_id = $this->createStockItem('0.08', 10);
        $this->completeReceiving($item_id, 10, '0.09');

        $this->assertSame('0.09', $this->savedCost($item_id));
    }

    /**
     * Rounds the case 6 cheap average to $0.02.
     */
    public function testCase06CheapItemRoundsToTwoThousandPounds(): void
    {
        $item_id = $this->createStockItem('0.02', 10);
        $this->completeReceiving($item_id, 10, '0.03');

        $this->assertSame('0.02', $this->savedCost($item_id));
    }

    /**
     * Rounds the case 7 supplier return average to $1.50.
     */
    public function testCase07ReturnRoundsToOneHundredThirtyFiveThousandPounds(): void
    {
        $item_id = $this->createStockItem('1.67', 15);
        $this->completeReceiving($item_id, -5, '2.00');

        $this->assertSame('1.50', $this->savedCost($item_id));
    }

    /**
     * Leaves the case 8 cost unchanged when the average setting is off.
     */
    public function testCase08UntickedSettingLeavesCostUnchanged(): void
    {
        $item_id                                                            = $this->createStockItem('1.00', 10);
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = false;
        $this->completeReceiving($item_id, 2, '1.10');

        $this->assertSame('1.00', $this->savedCost($item_id));
    }

    /**
     * Leaves the case 9 cost unchanged when the paid price matches the current cost.
     */
    public function testCase09SamePriceLeavesCostUnchanged(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        $this->completeReceiving($item_id, 10, '1.00');

        $this->assertSame('1.00', $this->savedCost($item_id));
    }

    /**
     * Sets the case 10 cost to the first delivery price without averaging.
     */
    public function testCase10FirstDeliveryUsesPricePaid(): void
    {
        $item_id = $this->createStockItem('0.00', 0);
        $this->completeReceiving($item_id, 10, '1.20');

        $this->assertSame('1.20', $this->savedCost($item_id));
    }

    /**
     * Sets the case 11 cost to the paid price when starting stock is negative.
     */
    public function testCase11NegativeStockUsesPricePaid(): void
    {
        $item_id = $this->createStockItem('1.00', -5);
        $this->completeReceiving($item_id, 10, '2.00');

        $this->assertSame('2.00', $this->savedCost($item_id));
    }

    /**
     * Completes the case 12 full supplier return and keeps its cost unchanged.
     */
    public function testCase12FullReturnLeavesCostUnchangedAndCompletes(): void
    {
        $item_id      = $this->createStockItem('1.67', 5);
        $receiving_id = $this->completeReceiving($item_id, -5, '2.00');

        $this->assertGreaterThan(0, $receiving_id);
        $this->assertSame('1.67', $this->savedCost($item_id));
        $this->assertSame('0.000', $this->savedQuantity($item_id));
    }

    /**
     * Completes a delivery that brings negative stock exactly to zero without changing cost.
     */
    public function testDeliveryThatCoversNegativeStockToZeroLeavesCostUnchanged(): void
    {
        $item_id      = $this->createStockItem('1.00', -10);
        $receiving_id = $this->completeReceiving($item_id, 10, '2.00');

        $this->assertGreaterThan(0, $receiving_id);
        $this->assertSame('1.00', $this->savedCost($item_id));
        $this->assertSame('0.000', $this->savedQuantity($item_id));
    }

    /**
     * Leaves the cost unchanged when a supplier return takes total stock below zero.
     */
    public function testReturnBelowZeroLeavesCostUnchanged(): void
    {
        $item_id      = $this->createStockItem('1.00', 2);
        $receiving_id = $this->completeReceiving($item_id, -3, '2.00');

        $this->assertGreaterThan(0, $receiving_id);
        $this->assertSame('1.00', $this->savedCost($item_id));
        $this->assertSame('-1.000', $this->savedQuantity($item_id));
    }

    /**
     * Keeps the unrounded dollar average when the configured exchange rate is missing.
     */
    public function testMissingExchangeRateKeepsDollarAverage(): void
    {
        $item_id = $this->createStockItem('1.00', 10);
        unset(config(OSPOS::class)->settings['lbp_exchange_rate']);
        $this->completeReceiving($item_id, 2, '1.10', null);

        $this->assertSame('1.02', $this->savedCost($item_id));
    }

    /**
     * Keeps the unrounded dollar average when the configured exchange rate is zero.
     */
    public function testZeroExchangeRateKeepsDollarAverage(): void
    {
        $item_id                                            = $this->createStockItem('1.00', 10);
        config(OSPOS::class)->settings['lbp_exchange_rate'] = '0';
        $this->completeReceiving($item_id, 2, '1.10', 0.0);

        $this->assertSame('1.02', $this->savedCost($item_id));
    }

    /**
     * Raises a non-zero negative average to the 1,000 LL minimum.
     */
    public function testNegativeAverageUsesOneThousandPoundMinimum(): void
    {
        $item_id = $this->createStockItem('1.00', 5);
        $this->completeReceiving($item_id, -4, '3.00');

        $this->assertSame('0.01', $this->savedCost($item_id));
    }

    /**
     * Keeps an exact zero average at zero instead of applying the minimum.
     */
    public function testExactZeroAverageRemainsZero(): void
    {
        $item_id = $this->createStockItem('1.00', 5);
        $this->completeReceiving($item_id, -4, '1.25');

        $this->assertSame('0.00', $this->savedCost($item_id));
    }

    /**
     * Rounds an average of exactly 90,500 LL up to 91,000 LL, saved as $1.01.
     */
    public function testExactHalfAverageNotCutBeforeRounding(): void
    {
        $item_id = $this->createStockItem('1.00', 4);
        $this->completeReceiving($item_id, 5, '1.01');

        $this->assertSame('1.01', $this->savedCost($item_id));
    }

    /**
     * Rounds an average of 91,499.5 LL down to 91,000 LL instead of rounding to a whole pound first.
     */
    public function testAverageJustBelowHalfRoundsDown(): void
    {
        $item_id = $this->createStockItem('1.01', 601);
        $this->completeReceiving($item_id, 1199, '1.02');

        $this->assertSame('1.01', $this->savedCost($item_id));
    }

    /**
     * Applies the 1,000 LL minimum to a tiny non-zero average left by a return.
     */
    public function testTinyReturnAverageUsesOneThousandPoundMinimum(): void
    {
        $item_id = $this->createStockItem('1.00', 5);
        $this->completeReceiving($item_id, -4, '1.2499');

        $this->assertSame('0.01', $this->savedCost($item_id));
    }

    /**
     * Saves case 1 as $1.02 when 91,000 LL is converted at the 89,500 rate.
     */
    public function testCase01At89500ExchangeRate(): void
    {
        $item_id                                            = $this->createStockItem('1.00', 10);
        config(OSPOS::class)->settings['lbp_exchange_rate'] = '89500';
        $this->completeReceiving($item_id, 2, '1.10', 89_500.0);

        $this->assertSame('1.02', $this->savedCost($item_id));
    }

    /**
     * Creates one item with the requested starting cost and quantity.
     */
    private function createStockItem(string $cost_price, float $quantity): int
    {
        $item_data = [
            'name'                  => 'Average cost test ' . bin2hex(random_bytes(3)),
            'category'              => 'Average cost test',
            'supplier_id'           => null,
            'item_number'           => 'average-cost-' . bin2hex(random_bytes(4)),
            'description'           => 'Average cost test item',
            'cost_price'            => $cost_price,
            'unit_price'            => '0.00',
            'reorder_level'         => 0,
            'receiving_quantity'    => 1,
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => 0,
            'stock_type'            => HAS_STOCK,
            'item_type'             => ITEM,
            'tax_category_id'       => null,
            'taxable'               => 0,
            'tax_exemption_reason'  => 'exempt',
            'pic_filename'          => '',
            'qty_per_pack'          => 1,
            'pack_name'             => 'unit',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ];
        $item = new Item();
        if (! $item->save_value($item_data)) {
            throw new RuntimeException('Unable to create an average cost test item.');
        }

        $item_id          = (int) $item_data['item_id'];
        $this->item_ids[] = $item_id;
        $quantity_data    = ['quantity' => $quantity, 'item_id' => $item_id, 'location_id' => $this->location_id];
        if (! (new Item_quantity())->save_value($quantity_data, $item_id, $this->location_id)) {
            throw new RuntimeException('Unable to create an average cost test stock row.');
        }

        return $item_id;
    }

    /**
     * Saves one receipt or supplier return and records its new database id for cleanup.
     */
    private function completeReceiving(int $item_id, float $quantity, string $price, ?float $rate = 90_000.0): int
    {
        $line_total = number_format($quantity * (float) $price, 2, '.', '');
        $cart       = [1 => [
            'item_id'            => $item_id,
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity'           => $quantity,
            'receiving_quantity' => 1,
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'price'              => $price,
            'total'              => $line_total,
            'item_location'      => $this->location_id,
        ]];

        $receiving_id = (new Receiving())->save_value(
            $cart,
            null,
            $this->administrator_id,
            'Average cost test',
            'average-cost-test',
            'Cash',
            NEW_ENTRY,
            null,
            $rate,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        return $receiving_id;
    }

    /**
     * Returns the saved two-decimal item cost.
     */
    private function savedCost(int $item_id): string
    {
        return (string) $this->database->table('items')
            ->select('cost_price')
            ->where('item_id', $item_id)
            ->get()
            ->getRow()
            ->cost_price;
    }

    /**
     * Returns the saved stock quantity for the test item's location.
     */
    private function savedQuantity(int $item_id): string
    {
        return (string) $this->database->table('item_quantities')
            ->select('quantity')
            ->where('item_id', $item_id)
            ->where('location_id', $this->location_id)
            ->get()
            ->getRow()
            ->quantity;
    }
}
