<?php

namespace Tests;

use App\Models\Item;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use Throwable;

/**
 * Covers kitchen ticket lines read from saved sale rows.
 *
 * @internal
 */
final class KitchenTicketDatabaseTest extends CIUnitTestCase
{
    private ?BaseConnection $database  = null;
    private array $fixture_item_ids    = [];
    private array $fixture_sale_ids    = [];
    private bool $had_line_sequence    = false;
    private bool $line_sequence_saved  = false;
    private mixed $saved_line_sequence = null;

    /**
     * Connects to the test database, or skips when it is unavailable.
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

        $settings                  = config(OSPOS::class)->settings;
        $this->had_line_sequence   = array_key_exists('line_sequence', $settings);
        $this->saved_line_sequence = $settings['line_sequence'] ?? null;
        $this->line_sequence_saved = true;
    }

    /**
     * Removes saved fixtures and restores the receipt line sequence setting.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null && $this->fixture_sale_ids !== []) {
            foreach (['sales_items_taxes', 'sales_taxes', 'sales_payments', 'sales_items', 'sales'] as $table) {
                $this->database->table($table)->whereIn('sale_id', $this->fixture_sale_ids)->delete();
            }
        }

        if ($this->database !== null && $this->fixture_item_ids !== []) {
            $this->database->table('inventory')->whereIn('trans_items', $this->fixture_item_ids)->delete();
            $this->database->table('item_quantities')->whereIn('item_id', $this->fixture_item_ids)->delete();
            $this->database->table('items')->whereIn('item_id', $this->fixture_item_ids)->delete();
        }

        if ($this->line_sequence_saved) {
            if ($this->had_line_sequence) {
                config(OSPOS::class)->settings['line_sequence'] = $this->saved_line_sequence;
            } else {
                unset(config(OSPOS::class)->settings['line_sequence']);
            }
        }

        $this->database            = null;
        $this->fixture_item_ids    = [];
        $this->fixture_sale_ids    = [];
        $this->line_sequence_saved = false;
        parent::tearDown();
    }

    /**
     * Reads Burger, Cola, Burger in its saved line order even when receipts group by category.
     */
    public function testSavedKitchenTicketLinesKeepOriginalOrder(): void
    {
        $burger_id   = $this->createItem('Kitchen ticket Burger', 'Burgers');
        $cola_id     = $this->createItem('Kitchen ticket Cola', 'Drinks');
        $location_id = (int) $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->orderBy('location_id', 'asc')
            ->get()
            ->getRow()
            ->location_id;

        config(OSPOS::class)->settings['line_sequence'] = '2';

        $items = [];

        foreach ([$burger_id, $cola_id, $burger_id] as $index => $item_id) {
            $line         = $index + 1;
            $items[$line] = [
                'item_id'       => $item_id,
                'line'          => $line,
                'description'   => '',
                'serialnumber'  => '',
                'quantity'      => '1',
                'discount'      => '0.00',
                'discount_type' => PERCENT,
                'cost_price'    => '0.50',
                'price'         => '1.00',
                'item_location' => $location_id,
                'print_option'  => PRINT_YES,
            ];
        }

        $sale_status = COMPLETED;
        $sales_taxes = [[], []];
        $sale_id     = model(Sale::class)->save_value(
            NEW_ENTRY,
            $sale_status,
            $items,
            NEW_ENTRY,
            1,
            'Kitchen ticket saved-order test',
            null,
            null,
            null,
            SALE_TYPE_POS,
            [],
            null,
            $sales_taxes,
        );
        $this->assertGreaterThan(0, $sale_id);
        $this->fixture_sale_ids[] = $sale_id;

        $ticket_lines = model(Sale::class)->get_kitchen_ticket_lines($sale_id);

        $this->assertSame([1, 2, 3], array_map('intval', array_column($ticket_lines, 'line')));
        $this->assertSame([
            'Kitchen ticket Burger',
            'Kitchen ticket Cola',
            'Kitchen ticket Burger',
        ], array_column($ticket_lines, 'name'));
        $this->assertSame(['1.000', '1.000', '1.000'], array_map('strval', array_column($ticket_lines, 'quantity_purchased')));
    }

    /**
     * Creates one non-stock test item and records it for cleanup.
     */
    private function createItem(string $name, string $category): int
    {
        $location_id = (int) $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->orderBy('location_id', 'asc')
            ->get()
            ->getRow()
            ->location_id;
        $item_data = [
            'name'                  => $name,
            'category'              => $category,
            'supplier_id'           => null,
            'item_number'           => 'KT' . bin2hex(random_bytes(5)),
            'description'           => '',
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

        if (! model(Item::class)->save_value($item_data)) {
            $this->fail('Unable to create a kitchen ticket item fixture.');
        }

        $item_id                  = (int) $item_data['item_id'];
        $this->fixture_item_ids[] = $item_id;

        return $item_id;
    }
}
