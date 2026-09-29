<?php

namespace Tests;

use App\Controllers\Receivings as ReceivingsController;
use App\Database\Migrations\Migration_receivings_lbp_total;
use App\Database\Migrations\Migration_restore_receivings;
use App\Libraries\Barcode_lib;
use App\Libraries\Receiving_lib;
use App\Libraries\Token_lib;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Item_quantity;
use App\Models\Receiving;
use App\Models\Reports\Detailed_receivings;
use App\Models\Stock_location;
use CodeIgniter\Database\BaseConnection;
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

require_once APPPATH . 'Database/Migrations/20260928000000_restore_receivings.php';
require_once APPPATH . 'Database/Migrations/20260928000001_receivings_lbp_total.php';

/**
 * Covers Receivings access, saved pound totals, and stock changes.
 *
 * @internal
 */
final class KitchenStockDatabaseTest extends CIUnitTestCase
{
    private ?BaseConnection $database  = null;
    private array $fixture             = [];
    private array $receiving_ids       = [];
    private array $employee_ids        = [];
    private array $location_ids        = [];
    private bool $had_average_price    = false;
    private mixed $saved_average_price = null;
    private bool $average_price_saved  = false;
    private bool $had_lbp_rate         = false;
    private mixed $saved_lbp_rate      = null;
    private bool $lbp_rate_saved       = false;
    private array $session_keys        = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
    private array $initial_session     = [];

    /**
     * Connects to the test database and makes sure the new schema and access migration are available.
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
        $this->had_average_price   = array_key_exists('receiving_calculate_average_price', $settings);
        $this->saved_average_price = $settings['receiving_calculate_average_price'] ?? null;
        $this->average_price_saved = true;
        $this->had_lbp_rate        = array_key_exists('lbp_exchange_rate', $settings);
        $this->saved_lbp_rate      = $settings['lbp_exchange_rate'] ?? null;
        $this->lbp_rate_saved      = true;
        $this->initial_session     = $this->captureSessionValues($this->session_keys);

        $this->lbpMigration()->up();
        $this->restoreMigration()->up();
    }

    /**
     * Removes test data and leaves both migrations applied for the next test.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->restoreMigration()->up();
            $this->lbpMigration()->up();
            $this->removeFixtures();

            foreach (['receivings_items_temp'] as $table) {
                $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable($table));
            }
        }

        if ($this->average_price_saved) {
            if ($this->had_average_price) {
                config(OSPOS::class)->settings['receiving_calculate_average_price'] = $this->saved_average_price;
            } else {
                unset(config(OSPOS::class)->settings['receiving_calculate_average_price']);
            }
        }

        if ($this->lbp_rate_saved) {
            if ($this->had_lbp_rate) {
                config(OSPOS::class)->settings['lbp_exchange_rate'] = $this->saved_lbp_rate;
            } else {
                unset(config(OSPOS::class)->settings['lbp_exchange_rate']);
            }
        }

        $this->restoreSessionValues($this->session_keys, $this->initial_session);

        $this->database            = null;
        $this->fixture             = [];
        $this->receiving_ids       = [];
        $this->employee_ids        = [];
        $this->location_ids        = [];
        $this->average_price_saved = false;
        $this->had_lbp_rate        = false;
        $this->lbp_rate_saved      = false;
        $this->initial_session     = [];
        parent::tearDown();
    }

    /**
     * Restores Receivings permissions for active administrators and removes non-administrator grants.
     */
    public function testRestoreMigrationGrantsAdministratorsOnly(): void
    {
        $cashier_id = $this->createCashier();
        $this->seedReceivingGrants($cashier_id);

        $this->restoreMigration()->up();

        $module = $this->database->table('modules')->where('module_id', 'receivings')->get()->getRowArray();
        $this->assertNotNull($module);
        $this->assertSame('module_receivings', $module['name_lang_key']);
        $this->assertSame('module_receivings_desc', $module['desc_lang_key']);
        $this->assertSame('60', (string) $module['sort']);

        $admins = $this->database->table('grants')
            ->select('grants.person_id')
            ->join('employees', 'employees.person_id = grants.person_id')
            ->where('grants.permission_id', 'config')
            ->where('employees.deleted', 0)
            ->get()
            ->getResultArray();
        $admin_ids = array_map('intval', array_column($admins, 'person_id'));
        $this->assertNotEmpty($admin_ids);
        $this->assertSame(0, $this->receivingGrantCount($cashier_id));

        $locations = $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->get()
            ->getResultArray();

        foreach ($locations as $location) {
            $permission_id = 'receivings_' . str_replace(' ', '_', $location['location_name']);
            $permission    = $this->database->table('permissions')->where('permission_id', $permission_id)->get()->getRowArray();
            $this->assertNotNull($permission);
            $this->assertSame('receivings', $permission['module_id']);
            $this->assertSame((string) $location['location_id'], (string) $permission['location_id']);

            foreach ($admin_ids as $admin_id) {
                $this->assertSame('--', $this->grantMenuGroup($admin_id, $permission_id));
            }
        }

        foreach ($admin_ids as $admin_id) {
            $this->assertNotNull($this->grantMenuGroup($admin_id, 'receivings'));
            $this->assertSame('home', $this->grantMenuGroup($admin_id, 'receivings'));
        }

        $this->assertGreaterThan(0, $this->database->table('grants')
            ->whereIn('person_id', $admin_ids)
            ->where('permission_id', 'reports_receivings')
            ->countAllResults());
        $this->assertGreaterThan(0, $this->database->table('permissions')
            ->where('permission_id', 'reports_receivings')
            ->countAllResults());
        $this->assertStringContainsString("'reports_receivings' => 'detailed'", file_get_contents(APPPATH . 'Views/reports/listing.php'));
    }

    /**
     * Removes Receivings access on rollback and restores it again on migration rerun.
     */
    public function testRestoreMigrationCanRollBackAndRunAgain(): void
    {
        $this->restoreMigration()->down();

        $this->assertSame(0, $this->database->table('modules')->where('module_id', 'receivings')->countAllResults());
        $this->assertSame(0, $this->database->table('permissions')->where('module_id', 'receivings')->countAllResults());
        $this->assertSame(0, $this->database->table('grants')->like('permission_id', 'receivings', 'after')->countAllResults());

        $this->restoreMigration()->up();

        $this->assertSame(1, $this->database->table('modules')->where('module_id', 'receivings')->countAllResults());
        $this->assertGreaterThan(0, $this->database->table('permissions')->where('module_id', 'receivings')->countAllResults());
    }

    /**
     * Adds, drops, and restores the nullable pound columns when no saved values would be lost.
     */
    public function testPoundColumnsCanRollBackAndBeAddedAgain(): void
    {
        if ($this->database->table('receivings')->where('lbp_total IS NOT NULL')->countAllResults() > 0) {
            $this->markTestSkipped('Rolling back would erase saved pound totals in this database.');
        }

        $this->lbpMigration()->down();
        $this->database->resetDataCache();
        $this->assertFalse($this->database->fieldExists('lbp_total', 'receivings'));
        $this->assertFalse($this->database->fieldExists('lbp_exchange_rate', 'receivings'));

        $this->lbpMigration()->up();
        $this->database->resetDataCache();
        $this->assertTrue($this->database->fieldExists('lbp_total', 'receivings'));
        $this->assertTrue($this->database->fieldExists('lbp_exchange_rate', 'receivings'));
    }

    /**
     * Reprints the saved reference from the receiving row and escapes it once for HTML.
     */
    public function testReprintedReceivingShowsItsSavedReferenceOnceEscaped(): void
    {
        $this->fixture = $this->createStockFixture();

        $cart = [1 => [
            'item_id'            => $this->fixture['item_id'],
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity'           => 1,
            'receiving_quantity' => 1,
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'price'              => '4.00',
            'total'              => '4.00',
            'item_location'      => $this->fixture['location_id'],
        ]];
        $admin_id     = $this->getActiveAdministratorId();
        $receiving_id = (new Receiving())->save_value(
            $cart,
            null,
            $admin_id,
            'Saved reference reprint test',
            'A&B',
            'Cash',
            $this->fixture['location_id'],
            null,
            null,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        $session_keys       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session      = $this->captureSessionValues($session_keys);
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $admin_id);
        session()->remove('recv_reference');

        $employee_info = (new Employee())->get_info($admin_id);
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => config(OSPOS::class)->settings,
            'amount_change'   => null,
            'amount_tendered' => null,
        ]);

        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'receiving_lib', new Receiving_lib());
        $this->setControllerProperty($controller, 'receiving', new Receiving());
        $this->setControllerProperty($controller, 'stock_location', new Stock_location());
        $this->setControllerProperty($controller, 'barcode_lib', new Barcode_lib());
        $this->setControllerProperty($controller, 'employee', new Employee());

        $starting_buffer_level = ob_get_level();

        try {
            ob_start();
            $controller->getReceipt($receiving_id);
            $html = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $starting_buffer_level) {
                ob_end_clean();
            }

            $this->restoreSessionValues($session_keys, $saved_session);
            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        preg_match('/<div id="reference">(.*?)<\/div>/s', $html, $reference_match);
        $this->assertCount(2, $reference_match);
        $this->assertSame(1, substr_count($reference_match[1], 'A&amp;B'));
        $this->assertStringNotContainsString('A&B', $reference_match[1]);
    }

    /**
     * Saves a pound-priced receiving without a supplier, adds its quantity, and lists its saved totals in the report.
     */
    public function testReceivingSavesPoundTotalAndUpdatesStock(): void
    {
        $this->fixture                                                      = $this->createStockFixture();
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = false;

        $cart = [1 => [
            'item_id'            => $this->fixture['item_id'],
            'line'               => 1,
            'description'        => 'Chicken (kg)',
            'serialnumber'       => '',
            'quantity'           => 5,
            'receiving_quantity' => 1,
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'price'              => '0.95',
            'total'              => '4.75',
            'item_location'      => $this->fixture['location_id'],
        ]];
        $lbp_total    = get_receiving_lbp_totals($cart, 89_500)['total'];
        $admin_id     = $this->getActiveAdministratorId();
        $receiving_id = (new Receiving())->save_value(
            $cart,
            null,
            $admin_id,
            'Kitchen stock test',
            'issue-192-test',
            'Cash',
            $this->fixture['location_id'],
            $lbp_total,
            89_500.0,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        $row = $this->database->table('receivings')->where('receiving_id', $receiving_id)->get()->getRowArray();
        $this->assertNull($row['supplier_id']);
        $this->assertSame('425000', (string) $row['lbp_total']);
        $this->assertSame('89500.0000', (string) $row['lbp_exchange_rate']);

        $stock = $this->database->table('item_quantities')
            ->where('item_id', $this->fixture['item_id'])
            ->where('location_id', $this->fixture['location_id'])
            ->get()
            ->getRowArray();
        $this->assertSame('5.000', (string) $stock['quantity']);

        $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable('receivings_items_temp'));
        $report = new Detailed_receivings();
        $report->create(['receiving_id' => (string) $receiving_id]);
        $inputs     = ['location_id' => 'all', 'receiving_type' => 'receiving', 'definition_ids' => []];
        $report_row = $report->getDataByReceivingId((string) $receiving_id);
        $this->assertSame('425000', (string) $report_row['lbp_total']);
        $this->assertSame(425_000, $report->getSummaryData($inputs)['lbp_total']);
    }

    /**
     * Refuses a receiving without a valid exchange rate before saving or changing stock.
     */
    public function testCompletingReceivingWithoutExchangeRateDoesNotSaveOrChangeStock(): void
    {
        $this->fixture                                                      = $this->createStockFixture();
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = false;

        $cart = [1 => [
            'item_id'                    => $this->fixture['item_id'],
            'line'                       => 1,
            'item_number'                => 'issue-192-missing-rate',
            'name'                       => 'Kitchen stock chicken',
            'attribute_values'           => '',
            'attribute_dtvalues'         => '',
            'description'                => '',
            'serialnumber'               => '',
            'quantity'                   => 5,
            'receiving_quantity'         => 1,
            'receiving_quantity_choices' => [1 => '1'],
            'discount'                   => '0',
            'discount_type'              => PERCENT,
            'price'                      => '0.95',
            'total'                      => '4.75',
            'item_location'              => $this->fixture['location_id'],
            'in_stock'                   => 0,
            'stock_name'                 => 'Issue 192 test location',
            'allow_alt_description'      => 0,
        ]];
        $reference     = 'issue-192-missing-rate-' . bin2hex(random_bytes(3));
        $receiving_lib = $this->getMockBuilder(Receiving_lib::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_cart', 'get_stock_source', 'get_stock_destination', 'get_total', 'get_mode', 'get_comment', 'get_reference', 'is_print_after_sale'])
            ->getMock();
        $receiving_lib->method('get_cart')->willReturn($cart);
        $receiving_lib->method('get_stock_source')->willReturn($this->fixture['location_id']);
        $receiving_lib->method('get_stock_destination')->willReturn((string) $this->fixture['location_id']);
        $receiving_lib->method('get_total')->willReturn('4.75');
        $receiving_lib->method('get_mode')->willReturn('receive');
        $receiving_lib->method('get_comment')->willReturn('Missing rate test');
        $receiving_lib->method('get_reference')->willReturn($reference);
        $receiving_lib->method('is_print_after_sale')->willReturn(false);

        $stock_location = $this->getMockBuilder(Stock_location::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_allowed_locations', 'is_allowed_location', 'show_locations'])
            ->getMock();
        $stock_location->method('get_allowed_locations')->willReturn([
            $this->fixture['location_id'] => 'Issue 192 test location',
        ]);
        $stock_location->method('is_allowed_location')->willReturn(true);
        $stock_location->method('show_locations')->willReturn(false);

        $settings = config(OSPOS::class)->settings;
        unset($settings['lbp_exchange_rate']);
        $admin_id           = $this->getActiveAdministratorId();
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $admin_id);

        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);
        $this->setControllerProperty($controller, 'request', new IncomingRequest(new App(), new URI('/receivings/complete'), null, new UserAgent()));
        $this->setControllerProperty($controller, 'employee', new Employee());
        $this->setControllerProperty($controller, 'receiving', new Receiving());
        $this->setControllerProperty($controller, 'stock_location', $stock_location);
        $this->setControllerProperty($controller, 'config', $settings);

        $employee_info = (new Employee())->get_info($admin_id);
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => $settings,
        ]);

        $quantity_before        = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);
        $receiving_count_before = $this->database->table('receivings')->countAllResults();
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();

        ob_start();

        try {
            $controller->postComplete();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        $saved_receivings = $this->database->table('receivings')->where('reference', $reference)->get()->getResultArray();

        foreach ($saved_receivings as $saved_receiving) {
            $this->receiving_ids[] = (int) $saved_receiving['receiving_id'];
        }

        $this->assertStringContainsString(lang('Common.lbp_rate_missing'), $html);
        $this->assertSame([], $saved_receivings);
        $this->assertSame($receiving_count_before, $this->database->table('receivings')->countAllResults());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($quantity_before, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
    }

    /**
     * Refuses negative-price and zero-quantity receivings before saving or changing average item cost.
     */
    public function testCompletingInvalidReceivingDoesNotSaveOrChangeAverageCost(): void
    {
        $this->fixture                                                      = $this->createStockFixture();
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = true;
        config(OSPOS::class)->settings['lbp_exchange_rate']                 = 89_500;

        $cart = [1 => [
            'item_id'                    => $this->fixture['item_id'],
            'line'                       => 1,
            'item_number'                => 'issue-192-negative-price',
            'name'                       => 'Kitchen stock chicken',
            'attribute_values'           => '',
            'attribute_dtvalues'         => '',
            'description'                => '',
            'serialnumber'               => '',
            'quantity'                   => 5,
            'receiving_quantity'         => 1,
            'receiving_quantity_choices' => [1 => '1'],
            'discount'                   => '0',
            'discount_type'              => PERCENT,
            'price'                      => '-0.95',
            'total'                      => '-4.75',
            'item_location'              => $this->fixture['location_id'],
            'in_stock'                   => 0,
            'stock_name'                 => 'Issue 192 test location',
            'allow_alt_description'      => 0,
        ]];
        $reference     = 'issue-192-negative-price-' . bin2hex(random_bytes(3));
        $receiving_lib = $this->getMockBuilder(Receiving_lib::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_cart', 'get_stock_source', 'get_stock_destination', 'get_total', 'get_mode', 'get_comment', 'get_reference', 'is_print_after_sale'])
            ->getMock();
        $receiving_lib->method('get_cart')->willReturnCallback(static function () use (&$cart): array {
            return $cart;
        });
        $receiving_lib->method('get_stock_source')->willReturn($this->fixture['location_id']);
        $receiving_lib->method('get_stock_destination')->willReturn((string) $this->fixture['location_id']);
        $receiving_lib->method('get_total')->willReturn('-4.75');
        $receiving_lib->method('get_mode')->willReturn('receive');
        $receiving_lib->method('get_comment')->willReturn('Negative price test');
        $receiving_lib->method('get_reference')->willReturn($reference);
        $receiving_lib->method('is_print_after_sale')->willReturn(false);

        $stock_location = $this->getMockBuilder(Stock_location::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_allowed_locations', 'is_allowed_location', 'show_locations'])
            ->getMock();
        $stock_location->method('get_allowed_locations')->willReturn([
            $this->fixture['location_id'] => 'Issue 192 test location',
        ]);
        $stock_location->method('is_allowed_location')->willReturn(true);
        $stock_location->method('show_locations')->willReturn(false);

        $settings           = config(OSPOS::class)->settings;
        $admin_id           = $this->getActiveAdministratorId();
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $admin_id);
        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);
        $this->setControllerProperty($controller, 'request', new IncomingRequest(new App(), new URI('/receivings/complete'), null, new UserAgent()));
        $this->setControllerProperty($controller, 'employee', new Employee());
        $this->setControllerProperty($controller, 'receiving', new Receiving());
        $this->setControllerProperty($controller, 'stock_location', $stock_location);
        $this->setControllerProperty($controller, 'config', $settings);

        $employee_info = (new Employee())->get_info($admin_id);
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => $settings,
        ]);

        $quantity_before        = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);
        $cost_before            = $this->getItemCostPrice($this->fixture['item_id']);
        $receiving_count_before = $this->database->table('receivings')->countAllResults();
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();

        $starting_buffer_level = ob_get_level();

        try {
            ob_start();
            $controller->postComplete();
            $negative_price_html = (string) ob_get_contents();
            ob_end_clean();

            $cart[1]['price']    = '4.00';
            $cart[1]['quantity'] = '0.000';
            ob_start();
            $controller->postComplete();
            $zero_quantity_html = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $starting_buffer_level) {
                ob_end_clean();
            }

            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        $saved_receivings = $this->database->table('receivings')->where('reference', $reference)->get()->getResultArray();

        foreach ($saved_receivings as $saved_receiving) {
            $this->receiving_ids[] = (int) $saved_receiving['receiving_id'];
        }

        $this->assertStringContainsString(lang('Receivings.price_non_negative'), $negative_price_html);
        $this->assertStringContainsString(lang('Receivings.quantity_zero'), $zero_quantity_html);
        $this->assertSame([], $saved_receivings);
        $this->assertSame($receiving_count_before, $this->database->table('receivings')->countAllResults());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($quantity_before, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
        $this->assertSame($cost_before, $this->getItemCostPrice($this->fixture['item_id']));
    }

    /**
     * Shows the native no-permission message when a Receivings grant has no location grant.
     */
    public function testReceivingScreenRefusesEmployeeWithoutLocationGrant(): void
    {
        $cashier_id = $this->createCashier();
        $this->database->table('grants')->insert([
            'permission_id' => 'receivings',
            'person_id'     => $cashier_id,
            'menu_group'    => 'home',
        ]);
        $this->assertSame(1, $this->receivingGrantCount($cashier_id));

        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $cashier_id);
        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'stock_location', new Stock_location());

        ob_start();

        try {
            $controller->getIndex();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        $this->assertStringContainsString(lang('Error.no_permission_module'), $html);
        $this->assertStringContainsString('receivings_<location>', $html);
        $this->assertStringNotContainsString('register_wrapper', $html);
    }

    /**
     * Clears an open receiving after one of two location grants is revoked, then completes at the remaining location.
     */
    public function testReceivingScreenRecoversAfterOneLocationGrantIsRevoked(): void
    {
        $this->fixture      = $this->createStockFixture();
        $second_location_id = $this->createAdditionalStockLocation('Issue 192 Recovery');
        $cashier_id         = $this->createCashier();
        $this->grantReceivingLocation($cashier_id, $this->fixture['location_id']);
        $this->grantReceivingLocation($cashier_id, $second_location_id);

        $session_keys       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session      = $this->captureSessionValues($session_keys);
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $cashier_id);

        $receiving_lib = new Receiving_lib();
        $receiving_lib->set_stock_source($this->fixture['location_id']);
        $receiving_lib->set_stock_destination((string) $this->fixture['location_id']);
        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 5, $this->fixture['location_id'], 0, FIXED));
        $receiving_lib->set_reference('issue-192-recovery-before');

        $receiving_count_before = $this->database->table('receivings')->countAllResults();
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();
        $first_location_stock   = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);
        $second_location_stock  = $this->getStockQuantity($this->fixture['item_id'], $second_location_id);

        $first_permission = $this->database->table('permissions')
            ->where('module_id', 'receivings')
            ->where('location_id', $this->fixture['location_id'])
            ->get()
            ->getRowArray();
        $this->assertNotNull($first_permission);
        $this->database->table('grants')
            ->where('person_id', $cashier_id)
            ->where('permission_id', $first_permission['permission_id'])
            ->delete();
        $this->assertSame(2, $this->receivingGrantCount($cashier_id));

        config(OSPOS::class)->settings['lbp_exchange_rate'] = 89_500;
        $controller                                         = $this->createReceivingsController($receiving_lib, $cashier_id, ['payment_type' => 'Cash']);
        $html                                               = $this->captureReceivingAction(static function () use ($controller): void {
            $controller->getIndex();
        });

        $this->assertStringContainsString(lang('Receivings.locations_changed'), $html);
        $this->assertSame([], $receiving_lib->get_cart());
        $this->assertSame($second_location_id, $receiving_lib->get_stock_source());
        $this->assertSame((string) $second_location_id, $receiving_lib->get_stock_destination());
        $this->assertSame('', $receiving_lib->get_reference());
        $this->assertSame($receiving_count_before, $this->database->table('receivings')->countAllResults());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($first_location_stock, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
        $this->assertSame($second_location_stock, $this->getStockQuantity($this->fixture['item_id'], $second_location_id));

        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 5, $second_location_id, 0, FIXED));
        $receiving_lib->set_reference('issue-192-recovery-after');
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postComplete();
        });

        $this->assertSame('5.000', $this->getStockQuantity($this->fixture['item_id'], $second_location_id));
        $this->assertSame(1, $this->database->table('receivings')->where('reference', 'issue-192-recovery-after')->countAllResults());
        $this->receiving_ids[] = (int) $this->database->table('receivings')->select('receiving_id')->where('reference', 'issue-192-recovery-after')->get()->getRow()->receiving_id;

        $this->restoreSessionValues($session_keys, $saved_session);
        if ($previous_person_id === null) {
            session()->remove('person_id');
        } else {
            session()->set('person_id', $previous_person_id);
        }
    }

    /**
     * Refuses to load a saved receiving when any saved line uses a location the employee cannot access.
     */
    public function testReturnOfReceivingAtRevokedLocationLoadsNoLines(): void
    {
        $this->fixture      = $this->createStockFixture();
        $second_location_id = $this->createAdditionalStockLocation('Issue 192 Return');
        $admin_id           = $this->getActiveAdministratorId();
        $reference          = 'issue-192-return-source-' . bin2hex(random_bytes(3));
        $receiving_id       = (new Receiving())->save_value(
            $this->makeReceivingCart($this->fixture['item_id'], $this->fixture['location_id'], 5),
            null,
            $admin_id,
            'Return access test',
            $reference,
            'Cash',
            $this->fixture['location_id'],
            null,
            null,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        $cashier_id = $this->createCashier();
        $this->grantReceivingLocation($cashier_id, $second_location_id);
        $session_keys       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session      = $this->captureSessionValues($session_keys);
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $cashier_id);

        $receiving_lib = new Receiving_lib();
        $receiving_lib->set_stock_source($second_location_id);
        $receiving_lib->set_stock_destination((string) $second_location_id);
        $receiving_lib->set_mode('return');
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();
        $receiving_count_before = $this->database->table('receivings')->countAllResults();
        $first_location_stock   = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);

        $controller = $this->createReceivingsController($receiving_lib, $cashier_id, ['item' => 'RECV ' . $receiving_id]);
        $html       = $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postAdd();
        });

        $this->assertStringContainsString(lang('Receivings.unable_to_add_item'), $html);
        $this->assertSame([], $receiving_lib->get_cart());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($receiving_count_before, $this->database->table('receivings')->countAllResults());
        $this->assertSame($first_location_stock, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));

        $this->restoreSessionValues($session_keys, $saved_session);
        if ($previous_person_id === null) {
            session()->remove('person_id');
        } else {
            session()->set('person_id', $previous_person_id);
        }
    }

    /**
     * Confirms an administrator can receive stock, return a saved receiving, and transfer stock by requisition.
     */
    public function testAdministratorCompletesReceivingReturnAndRequisition(): void
    {
        $this->fixture                                                      = $this->createStockFixture();
        config(OSPOS::class)->settings['lbp_exchange_rate']                 = 89_500;
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = false;
        $second_location_id                                                 = $this->createAdditionalStockLocation('Issue 192 Admin');
        $admin_id                                                           = $this->getActiveAdministratorId();
        $session_keys                                                       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session                                                      = $this->captureSessionValues($session_keys);
        $previous_person_id                                                 = session()->get('person_id');
        session()->set('person_id', $admin_id);

        $receiving_lib = new Receiving_lib();
        $receiving_lib->set_stock_source($this->fixture['location_id']);
        $receiving_lib->set_stock_destination((string) $second_location_id);
        $controller = $this->createReceivingsController($receiving_lib, $admin_id, ['payment_type' => 'Cash']);

        $receiving_lib->set_reference('issue-192-admin-receive');
        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 10, $this->fixture['location_id'], 0, FIXED));
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postComplete();
        });
        $this->assertSame('10.000', $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
        $first_receiving_id = (int) $this->database->table('receivings')->select('receiving_id')->where('reference', 'issue-192-admin-receive')->get()->getRow()->receiving_id;
        $this->assertGreaterThan(0, $first_receiving_id);
        $this->receiving_ids[] = $first_receiving_id;

        $receiving_lib->set_reference('issue-192-admin-return-source');
        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 2, $this->fixture['location_id'], 0, FIXED));
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postComplete();
        });
        $second_receiving_id = (int) $this->database->table('receivings')->select('receiving_id')->where('reference', 'issue-192-admin-return-source')->get()->getRow()->receiving_id;
        $this->assertGreaterThan(0, $second_receiving_id);
        $this->receiving_ids[] = $second_receiving_id;
        $this->assertSame('12.000', $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));

        $receiving_lib->set_mode('return');
        $receiving_lib->set_reference('issue-192-admin-return');
        $controller_request = $this->makeReceivingRequest(['item' => 'RECV ' . $second_receiving_id, 'payment_type' => 'Cash']);
        $this->setControllerProperty($controller, 'request', $controller_request);
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postAdd();
        });
        $this->assertSame(-2, $receiving_lib->get_cart()[1]['quantity']);
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postComplete();
        });
        $this->assertSame('10.000', $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
        $return_receiving_id = (int) $this->database->table('receivings')->select('receiving_id')->where('reference', 'issue-192-admin-return')->get()->getRow()->receiving_id;
        $this->assertGreaterThan(0, $return_receiving_id);
        $this->receiving_ids[] = $return_receiving_id;

        $receiving_lib->set_mode('requisition');
        $receiving_lib->set_stock_source($this->fixture['location_id']);
        $receiving_lib->set_stock_destination((string) $second_location_id);
        $receiving_lib->set_reference('issue-192-admin-requisition');
        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 3, $this->fixture['location_id'], 0, FIXED));
        $this->captureReceivingAction(static function () use ($controller): void {
            $controller->postRequisitionComplete();
        });
        $this->assertSame('7.000', $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
        $this->assertSame('3.000', $this->getStockQuantity($this->fixture['item_id'], $second_location_id));
        $requisition_id = (int) $this->database->table('receivings')->select('receiving_id')->where('reference', 'issue-192-admin-requisition')->get()->getRow()->receiving_id;
        $this->assertGreaterThan(0, $requisition_id);
        $this->receiving_ids[] = $requisition_id;

        $this->restoreSessionValues($session_keys, $saved_session);
        if ($previous_person_id === null) {
            session()->remove('person_id');
        } else {
            session()->set('person_id', $previous_person_id);
        }
    }

    /**
     * Refuses completion after a cashier's location grant is revoked and leaves stock unchanged.
     */
    public function testCompletingReceivingAfterLocationGrantRevokedDoesNotSaveOrChangeStock(): void
    {
        $this->fixture = $this->createStockFixture();
        $cashier_id    = $this->createCashier();
        $permission    = $this->database->table('permissions')
            ->where('module_id', 'receivings')
            ->where('location_id', $this->fixture['location_id'])
            ->get()
            ->getRowArray();
        $this->assertNotNull($permission);

        $this->database->table('grants')->insert([
            'permission_id' => 'receivings',
            'person_id'     => $cashier_id,
            'menu_group'    => 'home',
        ]);
        $this->database->table('grants')->insert([
            'permission_id' => $permission['permission_id'],
            'person_id'     => $cashier_id,
            'menu_group'    => '--',
        ]);

        $session_keys       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session      = $this->captureSessionValues($session_keys);
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $cashier_id);

        $receiving_lib = new Receiving_lib();
        $receiving_lib->set_stock_source($this->fixture['location_id']);
        $this->assertTrue($receiving_lib->add_item((string) $this->fixture['item_id'], 5, $this->fixture['location_id'], 0, PERCENT));
        $reference = 'issue-192-revoked-' . bin2hex(random_bytes(3));
        $receiving_lib->set_reference($reference);

        $quantity_before        = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);
        $receiving_count_before = $this->database->table('receivings')->countAllResults();
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();

        $this->database->table('grants')
            ->where('person_id', $cashier_id)
            ->where('permission_id', $permission['permission_id'])
            ->delete();
        $this->assertSame(1, $this->receivingGrantCount($cashier_id));

        $employee_info = (new Employee())->get_info($cashier_id);
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => config(OSPOS::class)->settings,
        ]);

        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);
        $this->setControllerProperty($controller, 'stock_location', new Stock_location());

        ob_start();

        try {
            $controller->postComplete();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $this->restoreSessionValues($session_keys, $saved_session);
            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        $this->assertStringContainsString(lang('Error.no_permission_module'), $html);
        $this->assertStringContainsString('receivings_<location>', $html);
        $this->assertSame([], $this->database->table('receivings')->where('reference', $reference)->get()->getResultArray());
        $this->assertSame($receiving_count_before, $this->database->table('receivings')->countAllResults());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($quantity_before, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
    }

    /**
     * Refuses to delete a saved receiving after its stock-location grant is revoked.
     */
    public function testDeletingReceivingAfterLocationGrantRevokedDoesNotChangeStock(): void
    {
        $this->fixture = $this->createStockFixture();
        $cashier_id    = $this->createCashier();
        $permission    = $this->database->table('permissions')
            ->where('module_id', 'receivings')
            ->where('location_id', $this->fixture['location_id'])
            ->get()
            ->getRowArray();
        $this->assertNotNull($permission);

        $this->database->table('grants')->insert([
            'permission_id' => 'receivings',
            'person_id'     => $cashier_id,
            'menu_group'    => 'home',
        ]);
        $this->database->table('grants')->insert([
            'permission_id' => $permission['permission_id'],
            'person_id'     => $cashier_id,
            'menu_group'    => '--',
        ]);

        $cart = [1 => [
            'item_id'            => $this->fixture['item_id'],
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity'           => 3,
            'receiving_quantity' => 1,
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'price'              => '4.00',
            'total'              => '12.00',
            'item_location'      => $this->fixture['location_id'],
        ]];
        $admin_id     = $this->getActiveAdministratorId();
        $receiving_id = (new Receiving())->save_value(
            $cart,
            null,
            $admin_id,
            'Revoked location delete test',
            'issue-192-delete-' . bin2hex(random_bytes(3)),
            'Cash',
            $this->fixture['location_id'],
            null,
            null,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        $session_keys       = ['person_id', 'recv_cart', 'recv_stock_source', 'recv_stock_destination', 'recv_mode', 'recv_comment', 'recv_reference', 'recv_print_after_sale'];
        $saved_session      = $this->captureSessionValues($session_keys);
        $previous_person_id = session()->get('person_id');
        session()->set('person_id', $cashier_id);

        $quantity_before        = $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']);
        $inventory_count_before = $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults();

        $this->database->table('grants')
            ->where('person_id', $cashier_id)
            ->where('permission_id', $permission['permission_id'])
            ->delete();

        $employee_info = (new Employee())->get_info($cashier_id);
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => config(OSPOS::class)->settings,
        ]);

        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'receiving', new Receiving());
        $this->setControllerProperty($controller, 'stock_location', new Stock_location());
        $this->setControllerProperty($controller, 'employee', new Employee());

        ob_start();

        try {
            $controller->postDelete($receiving_id);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $this->restoreSessionValues($session_keys, $saved_session);
            if ($previous_person_id === null) {
                session()->remove('person_id');
            } else {
                session()->set('person_id', $previous_person_id);
            }
        }

        $this->assertStringContainsString(lang('Error.no_permission_module'), $html);
        $this->assertNotNull($this->database->table('receivings')->where('receiving_id', $receiving_id)->get()->getRowArray());
        $this->assertSame($inventory_count_before, $this->database->table('inventory')->where('trans_items', $this->fixture['item_id'])->countAllResults());
        $this->assertSame($quantity_before, $this->getStockQuantity($this->fixture['item_id'], $this->fixture['location_id']));
    }

    /**
     * Leaves the pound total blank for a location-filtered partial receiving and in its footer.
     */
    public function testLocationFilteredReportHidesPartialReceivingPoundTotal(): void
    {
        $this->fixture                                                      = $this->createStockFixture();
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = false;

        $location_model       = new Stock_location();
        $second_location_data = ['location_name' => 'Issue 192 Second'];
        $this->assertTrue($location_model->save_value($second_location_data, NEW_ENTRY));
        $second_location = $this->database->table('stock_locations')->where('location_name', 'Issue 192 Second')->get()->getRowArray();
        $this->assertNotNull($second_location);
        $second_location_id   = (int) $second_location['location_id'];
        $this->location_ids[] = $second_location_id;

        $cart = [
            1 => [
                'item_id'            => $this->fixture['item_id'],
                'line'               => 1,
                'description'        => '',
                'serialnumber'       => '',
                'quantity'           => 5,
                'receiving_quantity' => 1,
                'discount'           => '0.00',
                'discount_type'      => PERCENT,
                'price'              => '0.95',
                'total'              => '4.75',
                'item_location'      => $this->fixture['location_id'],
            ],
            2 => [
                'item_id'            => $this->fixture['item_id'],
                'line'               => 2,
                'description'        => '',
                'serialnumber'       => '',
                'quantity'           => 5,
                'receiving_quantity' => 1,
                'discount'           => '0.00',
                'discount_type'      => PERCENT,
                'price'              => '0.95',
                'total'              => '4.75',
                'item_location'      => $second_location_id,
            ],
        ];
        $admin_id     = $this->getActiveAdministratorId();
        $lbp_total    = get_receiving_lbp_totals($cart, 89_500)['total'];
        $receiving_id = (new Receiving())->save_value(
            $cart,
            null,
            $admin_id,
            'Split location test',
            'issue-192-split-location',
            'Cash',
            $this->fixture['location_id'],
            $lbp_total,
            89_500.0,
        );
        $this->assertGreaterThan(0, $receiving_id);
        $this->receiving_ids[] = $receiving_id;

        $this->database->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->database->prefixTable('receivings_items_temp'));
        $report = new Detailed_receivings();
        $report->create(['receiving_id' => (string) $receiving_id]);

        $partial_inputs = [
            'location_id'    => (string) $this->fixture['location_id'],
            'receiving_type' => 'receiving',
            'definition_ids' => [],
        ];
        $partial_data = $report->getData($partial_inputs);
        $this->assertCount(1, $partial_data['summary']);
        $this->assertNull($partial_data['summary'][0]['lbp_total']);
        $this->assertNull($report->getSummaryData($partial_inputs)['lbp_total']);

        $all_inputs = [
            'location_id'    => 'all',
            'receiving_type' => 'receiving',
            'definition_ids' => [],
        ];
        $all_data = $report->getData($all_inputs);
        $this->assertSame((string) $lbp_total, (string) $all_data['summary'][0]['lbp_total']);
        $this->assertSame($lbp_total, (int) $report->getSummaryData($all_inputs)['lbp_total']);
    }

    /**
     * Gives a later stock location to active administrators and leaves it unticked for a cashier.
     */
    public function testNewLocationReceivingsPermissionOnlyGoesToAdministrators(): void
    {
        $cashier_id     = $this->createCashier();
        $location_data  = ['location_name' => 'Issue 192 Kitchen'];
        $location_model = new Stock_location();
        $this->assertTrue($location_model->save_value($location_data, NEW_ENTRY));

        $location = $this->database->table('stock_locations')->where('location_name', 'Issue 192 Kitchen')->get()->getRowArray();
        $this->assertNotNull($location);
        $location_id          = (int) $location['location_id'];
        $this->location_ids[] = $location_id;
        $permission_id        = 'receivings_Issue_192_Kitchen';

        $this->assertSame('home', $this->grantMenuGroup($this->getActiveAdministratorId(), $permission_id));
        $this->assertSame(0, $this->receivingGrantCount($cashier_id));
    }

    /**
     * Creates an active stock location with the Receivings permission migration already applied.
     */
    private function createAdditionalStockLocation(string $name): int
    {
        $location_name  = $name . ' ' . bin2hex(random_bytes(3));
        $location_data  = ['location_name' => $location_name];
        $location_model = new Stock_location();
        if (! $location_model->save_value($location_data, NEW_ENTRY)) {
            throw new RuntimeException('Unable to create an additional test stock location.');
        }

        $location = $this->database->table('stock_locations')->where('location_name', $location_name)->get()->getRowArray();
        if ($location === null) {
            throw new RuntimeException('The additional test stock location was not saved.');
        }

        $location_id          = (int) $location['location_id'];
        $this->location_ids[] = $location_id;

        return $location_id;
    }

    /**
     * Grants a cashier access to one Receivings location and its module menu entry.
     */
    private function grantReceivingLocation(int $person_id, int $location_id): void
    {
        if ($this->database->table('grants')->where('person_id', $person_id)->where('permission_id', 'receivings')->countAllResults() === 0) {
            $this->database->table('grants')->insert([
                'permission_id' => 'receivings',
                'person_id'     => $person_id,
                'menu_group'    => 'home',
            ]);
        }

        $permission = $this->database->table('permissions')
            ->where('module_id', 'receivings')
            ->where('location_id', $location_id)
            ->get()
            ->getRowArray();
        if ($permission === null) {
            throw new RuntimeException('The test stock location has no Receivings permission.');
        }

        $this->database->table('grants')->insert([
            'permission_id' => $permission['permission_id'],
            'person_id'     => $person_id,
            'menu_group'    => '--',
        ]);
    }

    /**
     * Returns the controller and its database-backed dependencies for a receiving action test.
     */
    private function createReceivingsController(Receiving_lib $receiving_lib, int $person_id, array $post): ReceivingsController
    {
        $employee      = new Employee();
        $employee_info = $employee->get_info($person_id);
        $settings      = config(OSPOS::class)->settings;
        view('viewData', [
            'user_info'       => $employee_info,
            'allowed_modules' => [],
            'controller_name' => 'receivings',
            'config'          => $settings,
            'amount_change'   => null,
            'amount_tendered' => null,
        ]);

        $controller = (new ReflectionClass(ReceivingsController::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'request', $this->makeReceivingRequest($post));
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);
        $this->setControllerProperty($controller, 'token_lib', new Token_lib());
        $this->setControllerProperty($controller, 'barcode_lib', new Barcode_lib());
        $this->setControllerProperty($controller, 'inventory', new Inventory());
        $this->setControllerProperty($controller, 'item', new Item());
        $this->setControllerProperty($controller, 'item_kit', new Item_kit());
        $this->setControllerProperty($controller, 'receiving', new Receiving());
        $this->setControllerProperty($controller, 'stock_location', new Stock_location());
        $this->setControllerProperty($controller, 'employee', $employee);
        $this->setControllerProperty($controller, 'config', $settings);

        return $controller;
    }

    /**
     * Builds an incoming request for a receiving controller action.
     *
     * @param array<string, mixed> $post
     */
    private function makeReceivingRequest(array $post): IncomingRequest
    {
        $request = new IncomingRequest(new App(), new URI('/receivings'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        return $request;
    }

    /**
     * Captures the rendered response from one receiving action.
     */
    private function captureReceivingAction(callable $action): string
    {
        $starting_buffer_level = ob_get_level();
        ob_start();

        try {
            $action();

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $starting_buffer_level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Builds a minimal receiving cart row for stock-change tests.
     *
     * @return array<int, array<string, float|int|string>>
     */
    private function makeReceivingCart(int $item_id, int $location_id, int $quantity): array
    {
        return [1 => [
            'item_id'            => $item_id,
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity'           => $quantity,
            'receiving_quantity' => 1,
            'discount'           => 0,
            'discount_type'      => FIXED,
            'price'              => '4.00',
            'total'              => (string) ($quantity * 4),
            'item_location'      => $location_id,
        ]];
    }

    /**
     * Returns the access migration bound to the test database.
     */
    private function restoreMigration(): Migration_restore_receivings
    {
        return new Migration_restore_receivings(Database::forge('tests'));
    }

    /**
     * Returns the pound-column migration bound to the test database.
     */
    private function lbpMigration(): Migration_receivings_lbp_total
    {
        return new Migration_receivings_lbp_total(Database::forge('tests'));
    }

    /**
     * Adds a cashier without grants for migration cleanup checks.
     */
    private function createCashier(): int
    {
        $person_data = [
            'first_name'   => 'Kitchen',
            'last_name'    => 'Cashier',
            'gender'       => null,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ];
        $employee_data = [
            'username'      => 'issue-192-' . bin2hex(random_bytes(4)),
            'password'      => password_hash('password', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language'      => 'english',
            'language_code' => 'en',
        ];
        $grants_data = [];
        if (! (new Employee())->save_employee($person_data, $employee_data, $grants_data)) {
            throw new RuntimeException('Unable to create the kitchen stock cashier fixture.');
        }

        $person_id            = (int) $employee_data['person_id'];
        $this->employee_ids[] = $person_id;
        if ($this->receivingGrantCount($person_id) !== 0) {
            throw new RuntimeException('A new kitchen stock cashier received a Receivings grant.');
        }

        return $person_id;
    }

    /**
     * Seeds every current Receivings permission for a cashier before rerunning the cleanup migration.
     */
    private function seedReceivingGrants(int $person_id): void
    {
        $permissions = $this->database->table('permissions')
            ->where('module_id', 'receivings')
            ->get()
            ->getResultArray();

        foreach ($permissions as $permission) {
            $this->database->table('grants')->insert([
                'permission_id' => $permission['permission_id'],
                'person_id'     => $person_id,
                'menu_group'    => $permission['permission_id'] === 'receivings' ? 'home' : '--',
            ]);
        }
    }

    /**
     * Returns the active administrator used for native receiving saves.
     */
    private function getActiveAdministratorId(): int
    {
        $row = $this->database->table('grants')
            ->select('grants.person_id')
            ->join('employees', 'employees.person_id = grants.person_id')
            ->where('grants.permission_id', 'config')
            ->where('employees.deleted', 0)
            ->orderBy('grants.person_id', 'asc')
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new RuntimeException('The test database has no active administrator.');
        }

        return (int) $row['person_id'];
    }

    /**
     * Returns the menu group saved for one employee permission.
     */
    private function grantMenuGroup(int $person_id, string $permission_id): ?string
    {
        $row = $this->database->table('grants')
            ->select('menu_group')
            ->where('person_id', $person_id)
            ->where('permission_id', $permission_id)
            ->get()
            ->getRowArray();

        return $row['menu_group'] ?? null;
    }

    /**
     * Counts all base and per-location Receivings grants held by an employee.
     */
    private function receivingGrantCount(int $person_id): int
    {
        return $this->database->table('grants')
            ->where('person_id', $person_id)
            ->like('permission_id', 'receivings', 'after')
            ->countAllResults();
    }

    /**
     * Reads the saved stock quantity for one item and location.
     */
    private function getStockQuantity(int $item_id, int $location_id): string
    {
        $row = $this->database->table('item_quantities')
            ->select('quantity')
            ->where('item_id', $item_id)
            ->where('location_id', $location_id)
            ->get()
            ->getRowArray();

        return (string) $row['quantity'];
    }

    /**
     * Reads the current item cost price.
     */
    private function getItemCostPrice(int $item_id): string
    {
        $row = $this->database->table('items')
            ->select('cost_price')
            ->where('item_id', $item_id)
            ->get()
            ->getRowArray();

        return (string) $row['cost_price'];
    }

    /**
     * Sets an initialized or uninitialized controller property for direct action tests.
     */
    private function setControllerProperty(object $controller, string $name, mixed $value): void
    {
        $property = (new ReflectionClass($controller))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }

    /**
     * Saves selected session values so controller tests can restore their starting state.
     *
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    private function captureSessionValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (session()->has($key)) {
                $values[$key] = session()->get($key);
            }
        }

        return $values;
    }

    /**
     * Restores selected session values after a controller test.
     *
     * @param array<int, string>   $keys
     * @param array<string, mixed> $values
     */
    private function restoreSessionValues(array $keys, array $values): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                session()->set($key, $values[$key]);
            } else {
                session()->remove($key);
            }
        }
    }

    /**
     * Creates one stocked item and a zero quantity row at the first active location.
     *
     * @return array{item_id: int, location_id: int}
     */
    private function createStockFixture(): array
    {
        $location = $this->database->table('stock_locations')
            ->where('deleted', 0)
            ->orderBy('location_id', 'asc')
            ->get()
            ->getRow();
        if ($location === null) {
            throw new RuntimeException('The test database has no active stock location.');
        }

        $item_data = [
            'name'                  => 'Kitchen stock chicken ' . bin2hex(random_bytes(3)),
            'category'              => 'Kitchen stock',
            'supplier_id'           => null,
            'item_number'           => 'issue-192-' . bin2hex(random_bytes(4)),
            'description'           => 'Kitchen stock test item',
            'cost_price'            => '4.00',
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
            'pack_name'             => 'kg',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ];
        $item = new Item();
        if (! $item->save_value($item_data)) {
            throw new RuntimeException('Unable to create the kitchen stock item fixture.');
        }

        $fixture = [
            'item_id'     => (int) $item_data['item_id'],
            'location_id' => (int) $location->location_id,
            'quantity'    => 0,
        ];
        if (! (new Item_quantity())->save_value($fixture, $fixture['item_id'], $fixture['location_id'])) {
            throw new RuntimeException('Unable to create the kitchen stock quantity fixture.');
        }

        return $fixture;
    }

    /**
     * Removes receiving, stock item, location, employee, and temporary report rows created by these tests.
     */
    private function removeFixtures(): void
    {
        foreach ($this->receiving_ids as $receiving_id) {
            $this->database->table('inventory')->where('trans_comment', 'RECV ' . $receiving_id)->delete();
            $this->database->table('receivings_items')->where('receiving_id', $receiving_id)->delete();
            $this->database->table('receivings')->where('receiving_id', $receiving_id)->delete();
        }

        if ($this->fixture !== []) {
            $this->database->table('item_quantities')->where('item_id', $this->fixture['item_id'])->delete();
            $this->database->table('items_taxes')->where('item_id', $this->fixture['item_id'])->delete();
            $this->database->table('items')->where('item_id', $this->fixture['item_id'])->delete();
        }

        foreach ($this->location_ids as $location_id) {
            $this->database->table('item_quantities')->where('location_id', $location_id)->delete();
            $this->database->table('grants')->whereIn('permission_id', array_column(
                $this->database->table('permissions')->select('permission_id')->where('location_id', $location_id)->get()->getResultArray(),
                'permission_id',
            ))->delete();
            $this->database->table('permissions')->where('location_id', $location_id)->delete();
            $this->database->table('stock_locations')->where('location_id', $location_id)->delete();
        }

        foreach ($this->employee_ids as $person_id) {
            $this->database->table('grants')->where('person_id', $person_id)->delete();
            $this->database->table('employees')->where('person_id', $person_id)->delete();
            $this->database->table('people')->where('person_id', $person_id)->delete();
        }
    }
}
