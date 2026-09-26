<?php

namespace Tests;

use App\Controllers\Items as ItemsController;
use App\Controllers\Sales as SalesController;
use App\Database\Migrations\Migration_barcode_generation;
use App\Libraries\Sale_lib;
use App\Models\Attribute;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Stock_location;
use App\Models\Supplier;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;
use Config\OSPOS;
use ReflectionClass;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20260922000001_barcode_generation.php';

/**
 * Covers D-005 item barcode generation, preservation, uniqueness, migrations, and operator paths.
 *
 * @internal
 */
final class BarcodeGenerationTest extends CIUnitTestCase
{
    private $database;
    private ?string $migrationStateBackup = null;

    /**
     * Enables empty-barcode generation for the model and controller tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'barcode_generate_if_empty' => '1',
            'lbp_exchange_rate'         => '90000',
            'currency_decimals'         => '2',
            'number_locale'             => 'en_US',
            'thousands_separator'       => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Rolls back transaction-backed tests, restores saved migration state, and clears session state.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null && $this->database->transDepth > 0) {
            $this->database->transRollback();
        }

        session()->remove(['person_id', 'sales_cart']);

        if ($this->database !== null) {
            $this->restoreMigrationStateTable();
        }

        $this->database = null;
        parent::tearDown();
    }

    /**
     * Generates a thirteen-digit EAN-13 and reads the saved value back from the database.
     */
    public function testEmptyBarcodeReceivesGeneratedEan13(): void
    {
        $this->requireDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData(null);

        $this->assertTrue($item->save_value($item_data));
        $saved = $this->database->table('items')
            ->where('item_id', $item_data['item_id'])
            ->get()
            ->getRow('item_number');

        $this->assertSame($item->generate_item_number((int) $item_data['item_id']), $saved);
        $this->assertSame(13, strlen($saved));
        $this->assertStringStartsWith('20', $saved);
        $this->assertSame((string) $this->eanCheckDigit($saved), substr($saved, -1));
        $this->assertSame('2000000000220', $item->generate_item_number(22));
    }

    /**
     * Commits a standalone item save so another database connection can read the new row.
     */
    public function testStandaloneItemSaveCommitsItsTransaction(): void
    {
        $this->connectDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData('standalone-commit-' . bin2hex(random_bytes(4)));

        try {
            $this->assertTrue($item->save_value($item_data));
            $this->assertSame(0, $this->database->transDepth);

            $fresh_database = Database::connect('tests', false);

            try {
                $fresh_database->initialize();
                $this->assertSame(
                    1,
                    $fresh_database->table('items')->where('item_id', $item_data['item_id'])->countAllResults(),
                );
            } finally {
                $fresh_database->close();
            }
        } finally {
            if (isset($item_data['item_id'])) {
                $this->database->table('items')->where('item_id', $item_data['item_id'])->delete();
            }
        }
    }

    /**
     * Preserves leading zeros, spaces, ampersands, and quotes in a manually entered barcode.
     */
    public function testManualBarcodeIsPreservedByteForByte(): void
    {
        $this->requireDatabase();
        $manual    = '000 123 & "quote"';
        $item_data = $this->itemData($manual);
        $item      = $this->makeItem();

        $this->assertTrue($item->save_value($item_data));
        $saved = $this->database->table('items')
            ->where('item_id', $item_data['item_id'])
            ->get()
            ->getRow('item_number');

        $this->assertSame($manual, $saved);
    }

    /**
     * Rejects a duplicate manual barcode before the second item is saved.
     */
    public function testDuplicateBarcodeIsRejectedByTheSharedSavePath(): void
    {
        $this->requireDatabase();
        $item        = $this->makeItem();
        $first_data  = $this->itemData('duplicate-barcode', 'First barcode fixture');
        $second_data = $this->itemData('duplicate-barcode', 'Second barcode fixture');

        $this->assertTrue($item->save_value($first_data));
        $this->assertFalse($item->save_value($second_data));
        $this->assertSame('First barcode fixture', $item->get_item_number_owner('duplicate-barcode')->name);
        $this->assertSame(0, $this->database->table('items')->where('name', 'Second barcode fixture')->countAllResults());
    }

    /**
     * Preserves the database collation's case-insensitive barcode comparison.
     */
    public function testBarcodeDuplicateComparisonRemainsCaseInsensitive(): void
    {
        $this->requireDatabase();
        $item        = $this->makeItem();
        $first_data  = $this->itemData('CaseSensitiveBarcode', 'Case-sensitive owner');
        $second_data = $this->itemData('casesensitivebarcode', 'Case-sensitive duplicate');

        $this->assertTrue($item->save_value($first_data));
        $this->assertFalse($item->save_value($second_data));
        $this->assertSame('Case-sensitive owner', $item->get_item_number_owner('CASESENSITIVEBARCODE')->name);
    }

    /**
     * Rolls back a new item when its generated barcode collides with an existing manual value.
     */
    public function testGeneratedManualCollisionRollsBackTheNewItem(): void
    {
        $this->requireDatabase();
        $item       = $this->makeItem();
        $next_id    = $this->nextItemId();
        $owner_data = $this->itemData($item->generate_item_number($next_id + 1), 'Generated collision owner');
        $new_data   = $this->itemData(null, 'Generated collision new item');

        $this->assertTrue($item->save_value($owner_data));
        $this->assertSame($next_id, (int) $owner_data['item_id']);
        $this->assertFalse($item->save_value($new_data));
        $this->assertSame(0, $this->database->table('items')->where('name', 'Generated collision new item')->countAllResults());
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
        $barcode     = $item_data['item_number'];
        $second_data = ['item_number' => $barcode];

        $this->assertTrue($item->save_value($second_data, (int) $item_data['item_id']));
        $this->assertSame($barcode, $this->database->table('items')->where('item_id', $item_data['item_id'])->get()->getRow('item_number'));
    }

    /**
     * Returns localized duplicate messages for the supported operator locales.
     */
    public function testDuplicateMessagesNameTheExistingItemInAllSupportedLanguages(): void
    {
        foreach (['en', 'ar-EG', 'ar-LB'] as $locale) {
            $message = lang('Items.item_number_duplicate', ['Coffee'], $locale);

            $this->assertStringContainsString('Coffee', $message, $locale);
            $this->assertNotSame('', $message, $locale);
            $csv_message = lang('Items.csv_import_barcode_duplicate', [2, '000 123', 'Coffee'], $locale);
            $this->assertStringContainsString('000 123', $csv_message, $locale);
            $this->assertStringContainsString('Coffee', $csv_message, $locale);
        }
    }

    /**
     * Returns a useful localized duplicate message from the real CSV import path.
     */
    public function testCsvImportDuplicateMessageNamesLineBarcodeAndOwner(): void
    {
        $this->requireDatabase();
        session()->set('person_id', 1);

        $item       = $this->makeItem();
        $owner_data = $this->itemData('000 123 & "quote"', 'Spreadsheet owner');
        $this->assertTrue($item->save_value($owner_data));

        $csv_path = tempnam(sys_get_temp_dir(), 'ospos-barcode-');
        file_put_contents($csv_path, implode("\n", [
            'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN',
            '0,"000 123 & ""quote""",New item,Test,,1,2,,,,,0,Description,0,0,,',
        ]));
        $old_files           = $_FILES;
        $_FILES['file_path'] = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'items.csv',
            'tmp_name' => $csv_path,
            'type'     => 'text/csv',
            'size'     => filesize($csv_path),
        ];

        try {
            $controller = $this->makeItemsController($item);
            ob_start();
            $controller->postImportCsvFile();
            $response = json_decode((string) ob_get_clean(), true);
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        } finally {
            $_FILES = $old_files;
            unlink($csv_path);

            if (isset($owner_data['item_id'])) {
                $this->database->table('items')->where('item_id', $owner_data['item_id'])->delete();
            }
        }

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('2', $response['message']);
        $this->assertStringContainsString('000 123 & "quote"', $response['message']);
        $this->assertStringContainsString('Spreadsheet owner', $response['message']);
    }

    /**
     * Reports the generated barcode and its owner when a CSV row has an empty barcode collision.
     */
    public function testCsvImportGeneratedBarcodeCollisionNamesOwner(): void
    {
        $this->requireDatabase();
        session()->set('person_id', 1);

        $item       = $this->makeItem();
        $next_id    = $this->nextItemId();
        $owner_data = $this->itemData($item->generate_item_number($next_id + 1), 'Generated spreadsheet owner');
        $this->assertTrue($item->save_value($owner_data));

        $generated = $item->generate_item_number($next_id + 1);
        $locations = array_values((new Stock_location())->get_allowed_locations());
        $header    = 'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN';

        foreach ($locations as $location) {
            $header .= ',"location_' . $location . '"';
        }
        $csv_path = tempnam(sys_get_temp_dir(), 'ospos-barcode-');
        file_put_contents($csv_path, implode("\n", [
            $header,
            '0,,New generated item,Test,,1,2,,,,,0,Description,0,0,,' . str_repeat(',0', count($locations)),
        ]));
        $old_files           = $_FILES;
        $_FILES['file_path'] = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'items.csv',
            'tmp_name' => $csv_path,
            'type'     => 'text/csv',
            'size'     => filesize($csv_path),
        ];

        try {
            $controller = $this->makeItemsController($item);
            ob_start();
            $controller->postImportCsvFile();
            $response = json_decode((string) ob_get_clean(), true);
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        } finally {
            $_FILES = $old_files;
            unlink($csv_path);

            if (isset($owner_data['item_id'])) {
                $this->database->table('items')->where('item_id', $owner_data['item_id'])->delete();
            }
        }

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString($generated, $response['message']);
        $this->assertStringContainsString('Generated spreadsheet owner', $response['message']);
    }

    /**
     * Names the existing item when the ordinary item form hits a generated barcode collision.
     */
    public function testItemFormGeneratedBarcodeCollisionNamesOwner(): void
    {
        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'barcode_generate_if_empty' => '1',
            'currency_decimals'         => '2',
            'number_locale'             => 'en_US',
            'quantity_decimals'         => '0',
            'thousands_separator'       => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);

        $item = $this->createMock(Item::class);
        $item->method('save_value')->willReturnCallback(
            static function (array &$item_data): bool {
                $item_data['item_id'] = 123;

                return false;
            },
        );
        $item->method('generate_item_number')->with(123)->willReturn('2000000000123');
        $item->method('get_item_number_owner')->with('2000000000123', '-1')->willReturn((object) ['name' => 'Generated form owner']);

        $request = new IncomingRequest(new App(), new URI('/items/save'), null, new UserAgent());
        $request->setGlobal('post', [
            'name'                 => 'Generated form item',
            'description'          => '',
            'category'             => 'Test',
            'item_type'            => (string) ITEM,
            'stock_type'           => (string) HAS_STOCK,
            'supplier_id'          => '',
            'item_number'          => '',
            'cost_price'           => '1',
            'unit_price'           => '2',
            'reorder_level'        => '0',
            'receiving_quantity'   => '1',
            'qty_per_pack'         => '1',
            'pack_name'            => 'Each',
            'low_sell_item_id'     => '-1',
            'hsn_code'             => '',
            'tax_mode'             => 'inherit',
            'tax_exemption_reason' => 'exempt',
            'tax_category_id'      => '',
        ]);
        $employee = $this->createMock(Employee::class);
        $employee->method('get_logged_in_employee_info')->willReturn((object) ['person_id' => 1]);
        $controller = (new ReflectionClass(ItemsController::class))->newInstanceWithoutConstructor();
        $this->assignProperty($controller, 'request', $request);
        $this->assignProperty($controller, 'employee', $employee);
        $this->assignProperty($controller, 'item', $item);
        $this->assignProperty($controller, 'config', [
            'currency_decimals'         => '2',
            'currency_symbol'           => '$',
            'lbp_exchange_rate'         => '90000',
            'barcode_generate_if_empty' => '1',
            'number_locale'             => 'en_US',
            'quantity_decimals'         => '0',
            'tax_decimals'              => '2',
            'thousands_separator'       => '1',
            'tax_included'              => '1',
            'use_destination_based_tax' => '0',
        ]);

        ob_start();

        try {
            $controller->postSave(NEW_ENTRY);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $response = json_decode((string) $output, true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Generated form owner', $response['message']);
    }

    /**
     * Updates a sales-register barcode with raw posted bytes and returns localized success JSON.
     */
    public function testSalesRegisterPreservesRawBarcodeAndReturnsSuccess(): void
    {
        $this->requireDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData('old-sales-barcode', 'Sales register item');
        $this->assertTrue($item->save_value($item_data));

        $manual       = '000 123 & "quote"';
        $sale_library = $this->makeSaleLibrary([
            ['item_id' => (int) $item_data['item_id'], 'item_number' => 'old-sales-barcode'],
        ]);
        $controller = $this->makeSalesController($item, $sale_library, [
            'item_id'     => (string) $item_data['item_id'],
            'item_number' => $manual,
        ]);

        ob_start();
        $controller->postChangeItemNumber();
        $response = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($response['success']);
        $this->assertSame($manual, $response['item_number']);
        $this->assertSame($manual, $this->database->table('items')->where('item_id', $item_data['item_id'])->get()->getRow('item_number'));
        $this->assertSame($manual, $sale_library->get_cart()[0]['item_number']);
    }

    /**
     * Generates an empty barcode through the sales-register update path.
     */
    public function testSalesRegisterGeneratesAnEmptyBarcode(): void
    {
        $this->requireDatabase();
        $item      = $this->makeItem();
        $item_data = $this->itemData('old-sales-barcode', 'Sales register generated item');
        $this->assertTrue($item->save_value($item_data));

        $sale_library = $this->makeSaleLibrary([
            ['item_id' => (int) $item_data['item_id'], 'item_number' => 'old-sales-barcode'],
        ]);
        $controller = $this->makeSalesController($item, $sale_library, [
            'item_id'     => (string) $item_data['item_id'],
            'item_number' => '',
        ]);

        ob_start();
        $controller->postChangeItemNumber();
        $response  = json_decode((string) ob_get_clean(), true);
        $generated = $item->generate_item_number((int) $item_data['item_id']);

        $this->assertTrue($response['success']);
        $this->assertSame($generated, $response['item_number']);
        $this->assertSame($generated, $this->database->table('items')->where('item_id', $item_data['item_id'])->get()->getRow('item_number'));
        $this->assertSame($generated, $sale_library->get_cart()[0]['item_number']);
    }

    /**
     * Rejects a duplicate barcode from the sales register with a localized operator message.
     */
    public function testSalesRegisterRejectsDuplicateBarcode(): void
    {
        $this->requireDatabase();
        $item        = $this->makeItem();
        $owner_data  = $this->itemData('sales-duplicate', 'Sales duplicate owner');
        $target_data = $this->itemData('sales-target', 'Sales duplicate target');
        $this->assertTrue($item->save_value($owner_data));
        $this->assertTrue($item->save_value($target_data));

        $sale_library = $this->makeSaleLibrary([]);
        $controller   = $this->makeSalesController($item, $sale_library, [
            'item_id'     => (string) $target_data['item_id'],
            'item_number' => 'sales-duplicate',
        ]);

        ob_start();
        $controller->postChangeItemNumber();
        $response = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Sales duplicate owner', $response['message']);
        $this->assertSame('sales-target', $this->database->table('items')->where('item_id', $target_data['item_id'])->get()->getRow('item_number'));
    }

    /**
     * Keeps the register view connected to the barcode endpoint response contract.
     */
    public function testSalesRegisterConsumesBarcodeUpdateResponse(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("data('saved-item-number'", $source);
        $this->assertStringContainsString('message: response.message', $source);
        $this->assertStringContainsString('$input.val(response.item_number)', $source);
        $this->assertStringContainsString('$input.val(previous_item_number)', $source);
    }

    /**
     * Removes the obsolete duplicate-barcode control from the maintained barcode settings screens.
     */
    public function testBarcodeSettingsDoNotOfferDuplicateBarcodes(): void
    {
        $view = file_get_contents(APPPATH . 'Views/configs/barcode_config.php');

        $this->assertIsString($view);
        $this->assertStringNotContainsString('allow_duplicate_barcodes', $view);

        foreach (['en', 'ar-EG', 'ar-LB'] as $locale) {
            $language = file_get_contents(APPPATH . 'Language/' . $locale . '/Config.php');

            $this->assertIsString($language);
            $this->assertStringNotContainsString('allow_duplicate_barcodes', $language, $locale);
        }
    }

    /**
     * Applies the migration against the real database, checks backfill and settings, then restores the old index shape.
     */
    public function testMigrationUpAndDownUseRealDatabaseAndKeepBackfilledValues(): void
    {
        $this->connectDatabase();
        $migration          = $this->makeMigration();
        $original_index     = $this->barcodeIndex();
        $original_settings  = $this->configRows();
        $original_empty_ids = array_column(
            $this->database->table('items')
                ->select('item_id')
                ->groupStart()
                ->where('item_number', null)
                ->orWhere('item_number', '')
                ->groupEnd()
                ->get()
                ->getResultArray(),
            'item_id',
        );
        $fixture_data = $this->itemData(null, 'Migration barcode fixture');
        $this->database->table('items')->insert($fixture_data);
        $fixture_id = (int) $this->database->insertID();
        $this->setConfigRow('barcode_type', null);
        $this->setConfigRow('barcode_generate_if_empty', null);

        try {
            $migration->up();

            $this->assertSame('C128', $this->configValue('barcode_type'));
            $this->assertSame('1', $this->configValue('barcode_generate_if_empty'));
            $this->assertSame((new Item())->generate_item_number($fixture_id), $this->database->table('items')->where('item_id', $fixture_id)->get()->getRow('item_number'));
            $this->assertSame(0, (int) $this->barcodeIndex()['non_unique']);

            $migration->down();

            $this->assertNull($this->configValue('barcode_type'));
            $this->assertNull($this->configValue('barcode_generate_if_empty'));
            $this->assertSame($original_index, $this->barcodeIndex());
            $this->assertSame((new Item())->generate_item_number($fixture_id), $this->database->table('items')->where('item_id', $fixture_id)->get()->getRow('item_number'));
        } finally {
            $this->cleanupMigrationState($migration);
            $this->database->table('items')->where('item_id', $fixture_id)->delete();
            if ($original_empty_ids !== []) {
                $this->database->table('items')->whereIn('item_id', $original_empty_ids)->update(['item_number' => null]);
            }
            $this->restoreConfigRows($original_settings);
            $this->restoreBarcodeIndex($original_index);
        }
    }

    /**
     * Refuses existing and generated/manual conflicts before changing rows, settings, or indexes.
     */
    public function testMigrationPreflightNamesConflictsAndLeavesDatabaseUntouched(): void
    {
        $this->connectDatabase();
        $migration         = $this->makeMigration();
        $original_index    = $this->barcodeIndex();
        $original_settings = $this->configRows();
        $this->dropBarcodeIndex();
        $index_before_migration = $this->barcodeIndex();
        $this->setConfigRow('barcode_type', 'C39');
        $this->setConfigRow('barcode_generate_if_empty', '0');

        $empty_data = $this->itemData(null, 'Migration empty conflict');
        $this->database->table('items')->insert($empty_data);
        $empty_id   = (int) $this->database->insertID();
        $owner_data = $this->itemData((new Item())->generate_item_number($empty_id), 'Migration manual conflict');
        $this->database->table('items')->insert($owner_data);
        $owner_id      = (int) $this->database->insertID();
        $duplicate_one = $this->itemData('existing-duplicate', 'Existing duplicate one');
        $duplicate_two = $this->itemData('existing-duplicate', 'Existing duplicate two');
        $this->database->table('items')->insert($duplicate_one);
        $duplicate_one_id = (int) $this->database->insertID();
        $this->database->table('items')->insert($duplicate_two);
        $duplicate_two_id = (int) $this->database->insertID();

        $exception = null;

        try {
            try {
                $migration->up();
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);

            foreach ([$empty_id, $owner_id, $duplicate_one_id, $duplicate_two_id] as $conflict_id) {
                $this->assertStringContainsString((string) $conflict_id, $exception->getMessage());
            }

            $this->assertNull($this->database->table('items')->where('item_id', $empty_id)->get()->getRow('item_number'));
            $this->assertSame(
                (new Item())->generate_item_number($empty_id),
                $this->database->table('items')->where('item_id', $owner_id)->get()->getRow('item_number'),
            );
            $this->assertSame($index_before_migration, $this->barcodeIndex());
            $this->assertSame('C39', $this->configValue('barcode_type'));
            $this->assertSame('0', $this->configValue('barcode_generate_if_empty'));
            $this->assertSame(2, (int) $this->database->table('items')->where('item_number', 'existing-duplicate')->countAllResults());
        } finally {
            $this->cleanupMigrationState($migration);
            $this->database->table('items')->whereIn('item_id', [$empty_id, $owner_id, $duplicate_one_id, $duplicate_two_id])->delete();
            $this->restoreConfigRows($original_settings);
            $this->restoreBarcodeIndex($original_index);
        }
    }

    /**
     * Completes a migration retry when both the old and temporary indexes remain.
     */
    public function testMigrationRetriesFromOldAndTemporaryIndexes(): void
    {
        $this->connectDatabase();
        $migration          = $this->makeMigration();
        $original_index     = $this->barcodeIndex();
        $original_settings  = $this->configRows();
        $original_empty_ids = array_column(
            $this->database->table('items')
                ->select('item_id')
                ->groupStart()
                ->where('item_number', null)
                ->orWhere('item_number', '')
                ->groupEnd()
                ->get()
                ->getResultArray(),
            'item_id',
        );
        $table_name = $this->database->prefixTable('items');

        try {
            $this->database->table('items')->where('item_number', '')->update(['item_number' => null]);
            $this->dropBarcodeIndex();
            $this->database->query('ALTER TABLE ' . $table_name . ' ADD KEY `item_number` (`item_number`)');
            $this->createMigrationState([
                'index_name' => 'item_number',
                'non_unique' => 1,
                'columns'    => ['item_number'],
            ]);
            $this->database->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `barcode_generation_item_number` (`item_number`)');

            $migration->up();

            $indexes = $this->itemNumberIndexNames();
            $this->assertSame(['item_number'], $indexes);
            $this->assertSame(0, (int) $this->barcodeIndex()['non_unique']);

            $migration->down();

            $this->assertSame(['item_number'], $this->itemNumberIndexNames());
            $this->assertSame(1, (int) $this->barcodeIndex()['non_unique']);
        } finally {
            $this->cleanupMigrationState($migration);
            if ($original_empty_ids !== []) {
                $this->database->table('items')->whereIn('item_id', $original_empty_ids)->update(['item_number' => null]);
            }
            $this->restoreConfigRows($original_settings);
            $this->restoreBarcodeIndex($original_index);
        }
    }

    /**
     * Restores the old index in one operation when rollback sees both partial indexes.
     */
    public function testMigrationDownRestoresPartialIndexState(): void
    {
        $this->connectDatabase();
        $migration      = $this->makeMigration();
        $original_index = $this->barcodeIndex();
        $table_name     = $this->database->prefixTable('items');

        try {
            $this->database->table('items')->where('item_number', '')->update(['item_number' => null]);
            $this->dropBarcodeIndex();
            $this->database->query('ALTER TABLE ' . $table_name . ' ADD KEY `partial_item_number` (`item_number`)');
            $this->createMigrationState([
                'index_name' => 'partial_item_number',
                'non_unique' => 1,
                'columns'    => ['item_number'],
            ]);
            $this->database->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `barcode_generation_item_number` (`item_number`)');

            $migration->down();

            $this->assertSame(['partial_item_number'], $this->itemNumberIndexNames());
            $this->assertSame(1, (int) $this->barcodeIndex()['non_unique']);
        } finally {
            $this->cleanupMigrationState($migration);
            $this->restoreBarcodeIndex($original_index);
        }
    }

    /**
     * Creates an item model connected to the rollback-only test database.
     */
    private function makeItem(): Item
    {
        $item = new Item();
        $this->assignProperty($item, 'db', $this->database);

        return $item;
    }

    /**
     * Connects to the configured test database and starts a rollback-only transaction.
     */
    private function requireDatabase(): void
    {
        $this->connectDatabase();
        $this->database->transBegin();
    }

    /**
     * Connects to the configured test database without starting a transaction for DDL migration tests.
     */
    private function connectDatabase(): void
    {
        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * Builds the minimum current-schema item row used by the database tests.
     */
    private function itemData(?string $item_number, ?string $name = null): array
    {
        return [
            'name'                  => $name ?? 'Barcode fixture',
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
     * Returns the next item ID reported by the database.
     */
    private function nextItemId(): int
    {
        return (int) $this->database->query(
            'SELECT AUTO_INCREMENT FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->database->prefixTable('items')],
        )->getRow('AUTO_INCREMENT');
    }

    /**
     * Creates a sales controller with only the dependencies used by the register barcode endpoint.
     */
    private function makeSalesController(Item $item, Sale_lib $sale_library, array $post): SalesController
    {
        $request = new IncomingRequest(new App(), new URI('/sales/changeItemNumber'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $this->assignProperty($controller, 'request', $request);
        $this->assignProperty($controller, 'item', $item);
        $this->assignProperty($controller, 'sale_lib', $sale_library);
        $this->assignProperty($controller, 'config', ['barcode_generate_if_empty' => '1']);

        return $controller;
    }

    /**
     * Creates a sale library with a real session-backed cart and no unrelated model setup.
     */
    private function makeSaleLibrary(array $cart): Sale_lib
    {
        $sale_library = (new ReflectionClass(Sale_lib::class))->newInstanceWithoutConstructor();
        $this->assignProperty($sale_library, 'session', session());
        $sale_library->set_cart($cart);

        return $sale_library;
    }

    /**
     * Creates the controller dependencies used by the real CSV import endpoint.
     */
    private function makeItemsController(Item $item): ItemsController
    {
        $controller = (new ReflectionClass(ItemsController::class))->newInstanceWithoutConstructor();
        $this->assignProperty($controller, 'employee', new Employee());
        $this->assignProperty($controller, 'stock_location', new Stock_location());
        $this->assignProperty($controller, 'attribute', new Attribute());
        $this->assignProperty($controller, 'supplier', new Supplier());
        $this->assignProperty($controller, 'item', $item);
        $this->assignProperty($controller, 'config', ['barcode_generate_if_empty' => '1']);

        return $controller;
    }

    /**
     * Backs up existing migration state, then binds the migration to the test database connection.
     */
    private function makeMigration(): Migration_barcode_generation
    {
        $this->backupMigrationStateTable();

        $migration = new Migration_barcode_generation();
        $this->assignProperty($migration, 'db', $this->database);

        return $migration;
    }

    /**
     * Renames a pre-existing migration state table so this test gets fresh state.
     */
    private function backupMigrationStateTable(): void
    {
        $state_table = $this->database->prefixTable('barcode_generation_state');

        if (! $this->database->tableExists($state_table, false)) {
            return;
        }

        $this->migrationStateBackup = $this->database->prefixTable('barcode_generation_state_backup_' . bin2hex(random_bytes(6)));
        $this->database->query('RENAME TABLE ' . $state_table . ' TO ' . $this->migrationStateBackup);
    }

    /**
     * Removes this test's state table and restores the table that existed before the test.
     */
    private function restoreMigrationStateTable(): void
    {
        if ($this->migrationStateBackup === null || ! $this->database->tableExists($this->migrationStateBackup, false)) {
            return;
        }

        $state_table = $this->database->prefixTable('barcode_generation_state');

        if ($this->database->tableExists($state_table, false)) {
            $this->database->query('DROP TABLE ' . $state_table);
        }

        $this->database->query('RENAME TABLE ' . $this->migrationStateBackup . ' TO ' . $state_table);
        $this->migrationStateBackup = null;
    }

    /**
     * Sets a private or inherited property for a focused controller/model integration test.
     */
    private function assignProperty(object $object, string $property_name, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $property   = $reflection->getProperty($property_name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Reads the single-column item-number index currently installed in the database.
     */
    private function barcodeIndex(): ?array
    {
        $row = $this->database->query(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique
             FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1
             GROUP BY INDEX_NAME, NON_UNIQUE HAVING COUNT(*) = 1 ORDER BY INDEX_NAME LIMIT 1',
            [$this->database->prefixTable('items'), 'item_number'],
        )->getRowArray();

        return $row ?: null;
    }

    /**
     * Lists the names of all single-column item-number indexes.
     */
    private function itemNumberIndexNames(): array
    {
        $rows = $this->database->query(
            'SELECT DISTINCT INDEX_NAME AS index_name
             FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1
             ORDER BY INDEX_NAME',
            [$this->database->prefixTable('items'), 'item_number'],
        )->getResultArray();

        return array_column($rows, 'index_name');
    }

    /**
     * Creates the migration state table and records a previous item-number index for partial-state tests.
     */
    private function createMigrationState(array $index): void
    {
        $state_table = $this->database->prefixTable('barcode_generation_state');

        $this->database->query(
            'CREATE TABLE IF NOT EXISTS ' . $state_table . ' (
                `state_key` varchar(64) NOT NULL,
                `previous_value` varchar(500) DEFAULT NULL,
                `was_present` tinyint(1) NOT NULL,
                PRIMARY KEY (`state_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $this->database->table($state_table)->insert([
            'state_key'      => '__item_number_index',
            'previous_value' => json_encode($index, JSON_THROW_ON_ERROR),
            'was_present'    => 1,
        ]);
    }

    /**
     * Drops the current item-number index for migration conversion tests.
     */
    private function dropBarcodeIndex(): void
    {
        $index = $this->barcodeIndex();
        if ($index !== null) {
            $name = str_replace('`', '``', $index['index_name']);
            $this->database->query('ALTER TABLE ' . $this->database->prefixTable('items') . ' DROP INDEX `' . $name . '`');
        }
    }

    /**
     * Restores the index shape captured before a migration test.
     */
    private function restoreBarcodeIndex(?array $index): void
    {
        $this->dropBarcodeIndex();
        if ($index === null) {
            return;
        }

        $name = str_replace('`', '``', $index['index_name']);
        $type = (int) $index['non_unique'] === 0 ? 'UNIQUE KEY' : 'KEY';
        $this->database->query('ALTER TABLE ' . $this->database->prefixTable('items') . ' ADD ' . $type . ' `' . $name . '` (`item_number`)');
    }

    /**
     * Reads the two barcode configuration rows before a migration test changes them.
     */
    private function configRows(): array
    {
        return $this->database->table('app_config')
            ->whereIn('key', ['barcode_type', 'barcode_generate_if_empty'])
            ->get()
            ->getResultArray();
    }

    /**
     * Reads one barcode configuration value.
     */
    private function configValue(string $key): ?string
    {
        return $this->database->table('app_config')->where('key', $key)->get()->getRow('value');
    }

    /**
     * Replaces one barcode configuration row, or removes it when the value is null.
     */
    private function setConfigRow(string $key, ?string $value): void
    {
        $this->database->table('app_config')->where('key', $key)->delete();
        if ($value !== null) {
            $this->database->table('app_config')->insert(['key' => $key, 'value' => $value]);
        }
    }

    /**
     * Restores barcode configuration rows captured before a migration test.
     */
    private function restoreConfigRows(array $rows): void
    {
        $this->setConfigRow('barcode_type', null);
        $this->setConfigRow('barcode_generate_if_empty', null);

        foreach ($rows as $row) {
            $this->database->table('app_config')->insert(['key' => $row['key'], 'value' => $row['value']]);
        }
    }

    /**
     * Removes migration state left by a failed migration test.
     */
    private function cleanupMigrationState(Migration_barcode_generation $migration): void
    {
        $state_table = $this->database->prefixTable('barcode_generation_state');
        if ($this->database->tableExists($state_table, false)) {
            $migration->down();
        }
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
