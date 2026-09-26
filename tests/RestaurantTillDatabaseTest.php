<?php

namespace Tests;

use App\Libraries\Sale_lib;
use App\Models\Item;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use Throwable;

/**
 * Covers restaurant menu filtering and tap-order cart merging.
 *
 * @internal
 */
final class RestaurantTillDatabaseTest extends CIUnitTestCase
{
    private ?BaseConnection $database = null;
    private array $fixture_item_ids   = [];
    private bool $had_till_layout     = false;
    private mixed $saved_till_layout  = null;

    /**
     * Connects to the configured test database, or skips when it is unavailable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * Removes fixture items and restores the cached till layout after each test.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null && $this->fixture_item_ids !== []) {
            $this->database->table('item_quantities')->whereIn('item_id', $this->fixture_item_ids)->delete();
            $this->database->table('items')->whereIn('item_id', $this->fixture_item_ids)->delete();
        }

        $ospos_config = config(OSPOS::class);
        if ($this->had_till_layout) {
            $ospos_config->settings['till_layout'] = $this->saved_till_layout;
        } else {
            unset($ospos_config->settings['till_layout']);
        }

        session()->remove('sales_cart');
        $this->database         = null;
        $this->fixture_item_ids = [];
        parent::tearDown();
    }

    /**
     * Shows only active non-kit, non-temporary menu items in category and name order.
     */
    public function testRestaurantMenuItemsAreActiveAndSorted(): void
    {
        $zesty_id     = $this->createItem('Zesty Burger', 'Burgers');
        $apple_id     = $this->createItem('Apple Burger', 'Burgers');
        $water_id     = $this->createItem('Water', 'Drinks');
        $kit_id       = $this->createItem('Kit Fixture', 'Burgers', ITEM_KIT);
        $temporary_id = $this->createItem('Temporary Fixture', 'Burgers', ITEM_TEMP);
        $deleted_id   = $this->createItem('Deleted Fixture', 'Drinks', ITEM, true);

        $menu_items         = model(Item::class)->get_restaurant_menu_items();
        $fixture_menu_items = array_values(array_filter(
            $menu_items,
            static fn (array $menu_item): bool => in_array((int) $menu_item['item_id'], [$zesty_id, $apple_id, $water_id], true),
        ));
        $menu_item_ids = array_map('intval', array_column($menu_items, 'item_id'));

        $this->assertSame(['Apple Burger', 'Zesty Burger', 'Water'], array_column($fixture_menu_items, 'name'));
        $this->assertSame(['Burgers', 'Burgers', 'Drinks'], array_column($fixture_menu_items, 'category'));
        $this->assertNotContains($kit_id, $menu_item_ids);
        $this->assertNotContains($temporary_id, $menu_item_ids);
        $this->assertNotContains($deleted_id, $menu_item_ids);
    }

    /**
     * Keeps restaurant taps in order while merging only a repeated last line.
     */
    public function testRestaurantTapsMergeOnlyIntoTheLastCartLine(): void
    {
        $item_a                  = $this->createItem('Tap Order A', 'Burgers');
        $item_b                  = $this->createItem('Tap Order B', 'Drinks');
        $ospos_config            = config(OSPOS::class);
        $this->had_till_layout   = array_key_exists('till_layout', $ospos_config->settings);
        $this->saved_till_layout = $ospos_config->settings['till_layout'] ?? null;
        $location_id             = (int) $this->database->table('stock_locations')->select('location_id')->get()->getRow()->location_id;

        try {
            $cart = $this->addTaps('restaurant', [$item_a, $item_b, $item_a], $location_id);
            $this->assertSame([$item_a, $item_b, $item_a], array_map('intval', array_column(array_values($cart), 'item_id')));
            $this->assertSame(['1', '1', '1'], array_column(array_values($cart), 'quantity'));

            $cart = $this->addTaps('restaurant', [$item_a, $item_a], $location_id);
            $this->assertCount(1, $cart);
            $this->assertSame('2', array_values($cart)[0]['quantity']);

            $cart = $this->addTaps('restaurant', [$item_a, $item_b, $item_b], $location_id);
            $this->assertSame([$item_a, $item_b], array_map('intval', array_column(array_values($cart), 'item_id')));
            $this->assertSame(['1', '2'], array_column(array_values($cart), 'quantity'));

            $cart = $this->addTaps('shop', [$item_a, $item_b, $item_a], $location_id);
            $this->assertSame([$item_a, $item_b], array_map('intval', array_column(array_values($cart), 'item_id')));
            $this->assertSame(['2', '1'], array_column(array_values($cart), 'quantity'));
        } finally {
            session()->remove('sales_cart');
        }
    }

    /**
     * Keeps edited restaurant line prices and discounts separate from later taps.
     */
    public function testRestaurantTapsMergeOnlyWhenPriceAndDiscountMatch(): void
    {
        $item_id                 = $this->createItem('Tap Price A', 'Burgers', ITEM, false, 10.00);
        $ospos_config            = config(OSPOS::class);
        $this->had_till_layout   = array_key_exists('till_layout', $ospos_config->settings);
        $this->saved_till_layout = $ospos_config->settings['till_layout'] ?? null;
        $location_id             = (int) $this->database->table('stock_locations')->select('location_id')->get()->getRow()->location_id;
        $previous_bc_scale       = bcscale();

        try {
            bcscale(8);

            $cart         = $this->addTaps('restaurant', [$item_id], $location_id);
            $sale_library = new Sale_lib();
            $sale_library->edit_item('1', '', '', '1', '0', (string) PERCENT, '8.00');
            $this->addTap($sale_library, $item_id, $location_id);
            $cart = $sale_library->get_cart();

            $this->assertCount(2, $cart);
            $this->assertSame([8.0, 10.0], array_map(static fn (array $item): float => (float) $item['price'], array_values($cart)));
            $this->assertSame([8.0, 10.0], array_map(static fn (array $item): float => (float) $item['discounted_total'], array_values($cart)));

            $cart         = $this->addTaps('restaurant', [$item_id], $location_id);
            $sale_library = new Sale_lib();
            $sale_library->edit_item('1', '', '', '1', '10', (string) PERCENT, '10.00');
            $this->addTap($sale_library, $item_id, $location_id);
            $cart = $sale_library->get_cart();

            $this->assertCount(2, $cart);
            $this->assertSame(['10', '0'], array_column(array_values($cart), 'discount'));
            $this->assertSame([9.0, 10.0], array_map(static fn (array $item): float => (float) $item['discounted_total'], array_values($cart)));

            $cart = $this->addTaps('restaurant', [$item_id, $item_id], $location_id);
            $this->assertCount(1, $cart);
            $this->assertSame(2.0, (float) array_values($cart)[0]['quantity']);
            $this->assertSame(20.0, (float) array_values($cart)[0]['total']);
        } finally {
            bcscale($previous_bc_scale);
            session()->remove('sales_cart');
        }
    }

    /**
     * Creates one standard or excluded item fixture at the test database's first location.
     */
    private function createItem(string $name, string $category, int $item_type = ITEM, bool $deleted = false, float $unit_price = 4.5): int
    {
        $location_id = (int) $this->database->table('stock_locations')->select('location_id')->get()->getRow()->location_id;
        $this->database->table('items')->insert([
            'name'                  => $name,
            'category'              => $category,
            'item_number'           => 'RT' . bin2hex(random_bytes(5)),
            'description'           => '',
            'cost_price'            => 0,
            'unit_price'            => $unit_price,
            'reorder_level'         => 0,
            'receiving_quantity'    => 1,
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => $deleted ? 1 : 0,
            'stock_type'            => HAS_NO_STOCK,
            'item_type'             => $item_type,
            'tax_category_id'       => null,
            'taxable'               => 0,
            'tax_exemption_reason'  => 'exempt',
            'qty_per_pack'          => 1,
            'pack_name'             => 'Each',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ]);

        $item_id                  = (int) $this->database->insertID();
        $this->fixture_item_ids[] = $item_id;
        $this->database->table('item_quantities')->insert([
            'item_id'     => $item_id,
            'location_id' => $location_id,
            'quantity'    => 10,
        ]);

        return $item_id;
    }

    /**
     * Adds a tap sequence through Sale_lib and returns the resulting cart.
     */
    private function addTaps(string $layout, array $item_ids, int $location_id): array
    {
        config(OSPOS::class)->settings['till_layout'] = $layout;
        $sale_library                                 = new Sale_lib();
        $sale_library->set_cart([]);

        foreach ($item_ids as $item_id) {
            $item_id  = (string) $item_id;
            $discount = '0';
            $this->assertTrue($sale_library->add_item($item_id, $location_id, '1', $discount));
        }

        return $sale_library->get_cart();
    }

    /**
     * Adds one item tap to an existing sale cart.
     */
    private function addTap(Sale_lib $sale_library, int $item_id, int $location_id): void
    {
        $item_id  = (string) $item_id;
        $discount = '0';
        $this->assertTrue($sale_library->add_item($item_id, $location_id, '1', $discount));
    }
}
