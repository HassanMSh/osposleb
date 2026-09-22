<?php

namespace Tests;

use App\Models\Item;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use ReflectionClass;
use Throwable;

/**
 * Covers D-005 item barcode generation, preservation, uniqueness, and migration decisions.
 *
 * @internal
 */
final class BarcodeGenerationTest extends CIUnitTestCase
{
    private $database;

    /**
     * Enables empty-barcode generation for the model tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = ['barcode_generate_if_empty' => '1'];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Rolls back every item created by the barcode tests.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->transRollback();
        }

        $this->database = null;
        parent::tearDown();
    }

    /**
     * Generates a thirteen-digit EAN-13 from the inserted item ID.
     */
    public function testEmptyBarcodeReceivesGeneratedEan13(): void
    {
        $this->requireDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData(null);

        $this->assertTrue($item->save_value($item_data));
        $this->assertSame($item->generate_item_number((int) $item_data['item_id']), $item_data['item_number']);
        $this->assertSame(13, strlen($item_data['item_number']));
        $this->assertStringStartsWith('20', $item_data['item_number']);
        $this->assertSame((string) $this->eanCheckDigit($item_data['item_number']), substr($item_data['item_number'], -1));
        $this->assertSame('2000000000220', $item->generate_item_number(22));
    }

    /**
     * Preserves a manually entered invalid EAN value exactly as entered.
     */
    public function testManualBarcodeIsPreservedByteForByte(): void
    {
        $this->requireDatabase();
        $manual    = 'supplier-code-not-ean';
        $item_data = $this->itemData($manual);
        $item      = $this->makeItem();

        $this->assertTrue($item->save_value($item_data));
        $this->assertSame($manual, $item_data['item_number']);
        $saved = $this->database->table('items')
            ->where('item_id', $item_data['item_id'])
            ->get()
            ->getRow()
            ->item_number;

        $this->assertSame($manual, $saved);
    }

    /**
     * Rejects a duplicate manual barcode before the second item is saved.
     */
    public function testDuplicateBarcodeIsRejectedByTheSharedSavePath(): void
    {
        $this->requireDatabase();
        $item        = $this->makeItem();
        $first_data  = $this->itemData('duplicate-barcode');
        $second_data = $this->itemData('duplicate-barcode');

        $this->assertTrue($item->save_value($first_data));
        $this->assertFalse($item->save_value($second_data));
        $this->assertSame('First barcode fixture', $item->get_item_number_owner('duplicate-barcode')->name);
    }

    /**
     * Keeps an assigned barcode unchanged when the item is saved again.
     */
    public function testGeneratingAnAssignedBarcodeAgainDoesNotChangeIt(): void
    {
        $this->requireDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData(null);

        $this->assertTrue($item->save_value($item_data));
        $barcode = $item_data['item_number'];

        $second_data = ['item_number' => $barcode];
        $this->assertTrue($item->save_value($second_data, (int) $item_data['item_id']));
        $saved = $this->database->table('items')
            ->where('item_id', $item_data['item_id'])
            ->get()
            ->getRow()
            ->item_number;

        $this->assertSame($barcode, $saved);
    }

    /**
     * Keeps duplicate errors localized and names the item that owns the barcode.
     */
    public function testDuplicateMessagesNameTheExistingItemInAllSupportedLanguages(): void
    {
        foreach (['en', 'ar-EG', 'ar-LB'] as $locale) {
            $message = lang('Items.item_number_duplicate', ['Coffee'], $locale);

            $this->assertStringContainsString('Coffee', $message, $locale);
            $this->assertNotSame('', $message, $locale);
        }
    }

    /**
     * Pins the migration settings, backfill, barcode type, and existing unique index.
     */
    public function testMigrationAndSchemaContainTheD005Decisions(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/20260922000000_barcode_generation.php');
        $schema    = file_get_contents(APPPATH . 'Database/tables.sql');

        $this->assertIsString($migration);
        $this->assertIsString($schema);
        $this->assertStringContainsString("->where('value', '0')", $migration);
        $this->assertStringContainsString("->update(['value' => '1'])", $migration);
        $this->assertStringContainsString("->where('value', '1')", $migration);
        $this->assertStringContainsString("->update(['value' => '0'])", $migration);
        $this->assertStringContainsString("['value' => 'C128']", $migration);
        $this->assertStringContainsString("['value' => 'C39']", $migration);
        $this->assertStringContainsString("->orWhere('item_number', '')", $migration);
        $this->assertStringContainsString('generate_item_number', $migration);
        $this->assertStringContainsString('ADD UNIQUE KEY `item_number`', $migration);
        $this->assertStringContainsString('UNIQUE KEY `item_number` (`item_number`)', $schema);
    }

    /**
     * Creates an item model connected to the rollback-only test database.
     */
    private function makeItem(): Item
    {
        $item     = new Item();
        $property = (new ReflectionClass(Item::class))->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($item, $this->database);

        return $item;
    }

    /**
     * Connects to the configured test database and starts a rollback-only transaction.
     */
    private function requireDatabase(): void
    {
        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
            $this->database->transBegin();
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * Builds the minimum current-schema item row used by the model tests.
     */
    private function itemData(?string $item_number): array
    {
        return [
            'name'                  => $item_number === 'duplicate-barcode' ? 'First barcode fixture' : 'Barcode fixture',
            'category'              => 'Test',
            'supplier_id'           => null,
            'item_number'           => $item_number,
            'description'           => 'Barcode fixture',
            'cost_price'            => '1.00',
            'unit_price'            => '2.00',
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
            'pack_name'             => 'Each',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ];
    }

    /**
     * Calculates the EAN-13 check digit for a thirteen-digit barcode.
     */
    private function eanCheckDigit(string $barcode): int
    {
        $sum = 0;

        for ($position = 0; $position < 12; $position++) {
            $weight = $position % 2 === 0 ? 1 : 3;
            $sum += (int) $barcode[$position] * $weight;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
