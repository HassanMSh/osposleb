<?php

namespace Tests;

use App\Database\Migrations\Migration_restore_expenses;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20261010000000_restore_expenses.php';

/**
 * Covers the Expenses restoration migration against the test database.
 *
 * @internal
 */
final class RestoreExpensesDatabaseTest extends CIUnitTestCase
{
    private $database;
    private ?Migration_restore_expenses $migration = null;
    private array $original_report_permission      = [];
    private array $original_report_grants          = [];
    private array $cashier_ids                     = [];
    private array $expense_category_ids            = [];
    private array $expense_ids                     = [];

    /**
     * Connects to the test database, selects English, and returns Expenses to its locked state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        service('request')->setLocale('en');
        service('language')->setLocale('en');

        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }

        $this->migration                  = new Migration_restore_expenses(Database::forge('tests'));
        $this->original_report_permission = $this->database->table('permissions')
            ->where('permission_id', 'reports_expenses_categories')
            ->get()
            ->getRowArray() ?? [];
        $this->original_report_grants = $this->database->table('grants')
            ->where('permission_id', 'reports_expenses_categories')
            ->get()
            ->getResultArray();

        $this->migration->down();
    }

    /**
     * Restores the migrated state and removes test expense, category, and employee rows.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null && $this->migration !== null) {
            $this->migration->up();

            if ($this->expense_ids !== []) {
                $this->database->table('expenses')->whereIn('expense_id', $this->expense_ids)->delete();
            }

            if ($this->expense_category_ids !== []) {
                $this->database->table('expense_categories')->whereIn('expense_category_id', $this->expense_category_ids)->delete();
            }

            foreach ($this->cashier_ids as $person_id) {
                $this->database->table('grants')->where('person_id', $person_id)->delete();
                $this->database->table('employees')->where('person_id', $person_id)->delete();
                $this->database->table('people')->where('person_id', $person_id)->delete();
            }

            $this->database->table('grants')->where('permission_id', 'reports_expenses_categories')->delete();
            $this->database->table('permissions')->where('permission_id', 'reports_expenses_categories')->delete();

            if ($this->original_report_permission !== []) {
                $this->database->table('permissions')->insert($this->original_report_permission);
            }
            if ($this->original_report_grants !== []) {
                $this->database->table('grants')->insertBatch($this->original_report_grants);
            }
        }

        $this->database             = null;
        $this->migration            = null;
        $this->cashier_ids          = [];
        $this->expense_category_ids = [];
        $this->expense_ids          = [];
        parent::tearDown();
    }

    /**
     * Creates the missing rows, grants administrators, tolerates a second run, and rolls back cleanly.
     */
    public function testMigrationRestoresRowsTwiceAndRollsBack(): void
    {
        $cashier_id = $this->createCashier();
        $this->database->table('grants')->where('permission_id', 'reports_expenses_categories')->delete();
        $this->database->table('permissions')->where('permission_id', 'reports_expenses_categories')->delete();

        $this->migration->up();

        $this->assertSame([
            ['module_id' => 'expenses', 'name_lang_key' => 'module_expenses', 'desc_lang_key' => 'module_expenses_desc', 'sort' => '108'],
            ['module_id' => 'expenses_categories', 'name_lang_key' => 'module_expenses_categories', 'desc_lang_key' => 'module_expenses_categories_desc', 'sort' => '109'],
        ], $this->database->table('modules')
            ->select('module_id, name_lang_key, desc_lang_key, sort')
            ->whereIn('module_id', ['expenses', 'expenses_categories'])
            ->orderBy('sort', 'asc')
            ->get()
            ->getResultArray());

        $expected_permissions = [
            'expenses'                    => 'expenses',
            'expenses_categories'         => 'expenses_categories',
            'reports_expenses_categories' => 'reports',
        ];

        foreach ($expected_permissions as $permission_id => $module_id) {
            $permission = $this->database->table('permissions')
                ->where('permission_id', $permission_id)
                ->get()
                ->getRowArray();
            $this->assertSame($module_id, $permission['module_id']);
            $this->assertNull($permission['location_id']);
        }

        $admin_ids = array_map('intval', array_column(
            $this->database->table('grants')
                ->select('grants.person_id')
                ->join('employees AS employees', 'employees.person_id = grants.person_id')
                ->where('grants.permission_id', 'config')
                ->where('employees.deleted', 0)
                ->get()
                ->getResultArray(),
            'person_id',
        ));
        $this->assertNotEmpty($admin_ids);

        foreach ($admin_ids as $person_id) {
            $this->assertSame('home', $this->getMenuGroup($person_id, 'expenses'));
            $this->assertSame('office', $this->getMenuGroup($person_id, 'expenses_categories'));
            $this->assertSame('--', $this->getMenuGroup($person_id, 'reports_expenses_categories'));
        }
        $this->assertSame(0, $this->database->table('grants')
            ->where('person_id', $cashier_id)
            ->whereIn('permission_id', array_keys($expected_permissions))
            ->countAllResults());

        $this->migration->up();

        foreach (array_keys($expected_permissions) as $permission_id) {
            $this->assertSame(1, $this->database->table('permissions')->where('permission_id', $permission_id)->countAllResults());
        }

        foreach ($admin_ids as $person_id) {
            foreach (array_keys($expected_permissions) as $permission_id) {
                $this->assertSame(1, $this->database->table('grants')
                    ->where(['person_id' => $person_id, 'permission_id' => $permission_id])
                    ->countAllResults());
            }
        }

        $this->migration->down();

        $this->assertSame(0, $this->database->table('modules')->whereIn('module_id', ['expenses', 'expenses_categories'])->countAllResults());
        $this->assertSame(0, $this->database->table('permissions')->whereIn('module_id', ['expenses', 'expenses_categories'])->countAllResults());
        $this->assertSame(0, $this->database->table('grants')->whereIn('permission_id', ['expenses', 'expenses_categories'])->countAllResults());
        $this->assertSame(1, $this->database->table('permissions')->where('permission_id', 'reports_expenses_categories')->countAllResults());
        $this->assertSame('reports', $this->database->table('permissions')
            ->where('permission_id', 'reports_expenses_categories')
            ->get()
            ->getRow('module_id'));
    }

    /**
     * Refuses to restore Expenses when no active administrator has the config grant.
     */
    public function testMigrationRequiresAnActiveAdministrator(): void
    {
        $config_grants = $this->database->table('grants')->where('permission_id', 'config')->get()->getResultArray();
        $this->assertNotEmpty($config_grants);
        $this->database->table('grants')->where('permission_id', 'config')->delete();

        try {
            $this->migration->up();
            $this->fail('Expenses restoration should require an active administrator.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('at least one active administrator', $exception->getMessage());
        } finally {
            $this->database->table('grants')->insertBatch($config_grants);
        }

        $this->assertSame(0, $this->database->table('modules')->whereIn('module_id', ['expenses', 'expenses_categories'])->countAllResults());
        $this->assertSame(0, $this->database->table('permissions')->whereIn('module_id', ['expenses', 'expenses_categories'])->countAllResults());
    }

    /**
     * Lets a cashier with the Expenses grant open that module on its own.
     */
    public function testExpenseGrantIsNotTreatedAsAnExpenseCategorySubpermission(): void
    {
        $this->migration->up();
        $cashier_id = $this->createCashier();
        $this->database->table('grants')->insert([
            'permission_id' => 'expenses',
            'person_id'     => $cashier_id,
            'menu_group'    => 'home',
        ]);

        $employee = new Employee();

        $this->assertTrue($employee->has_module_grant('expenses', $cashier_id));
        $this->assertFalse($employee->has_module_grant('expenses_categories', $cashier_id));
    }

    /**
     * Keeps Expenses closed to a cashier who was given only Expense categories.
     */
    public function testExpenseCategoryGrantDoesNotOpenExpenses(): void
    {
        $this->migration->up();
        $cashier_id = $this->createCashier();
        $this->database->table('grants')->insert([
            'permission_id' => 'expenses_categories',
            'person_id'     => $cashier_id,
            'menu_group'    => 'office',
        ]);

        $employee = new Employee();

        $this->assertTrue($employee->has_module_grant('expenses_categories', $cashier_id));
        $this->assertFalse($employee->has_module_grant('expenses', $cashier_id));
    }

    /**
     * Keeps exact module grants, location child grants, and report submodule checks separate.
     */
    public function testModuleGrantsMatchOnlyTheirModuleAndChildren(): void
    {
        $employee = new Employee();

        $config_cashier = $this->createCashier();
        $this->database->table('grants')->where('person_id', $config_cashier)->delete();
        $this->database->table('grants')->insert([
            'permission_id' => 'config',
            'person_id'     => $config_cashier,
            'menu_group'    => 'office',
        ]);
        $this->assertTrue($employee->has_module_grant('config', $config_cashier));

        $items_cashier = $this->createCashier();
        $this->database->table('grants')->where('person_id', $items_cashier)->delete();
        $this->database->table('grants')->insertBatch([
            ['permission_id' => 'items', 'person_id' => $items_cashier, 'menu_group' => 'home'],
            ['permission_id' => 'items_stock', 'person_id' => $items_cashier, 'menu_group' => '--'],
        ]);
        $this->assertTrue($employee->has_module_grant('items', $items_cashier));

        $location_only_cashier = $this->createCashier();
        $this->database->table('grants')->where('person_id', $location_only_cashier)->delete();
        $this->database->table('grants')->insert([
            'permission_id' => 'items_stock',
            'person_id'     => $location_only_cashier,
            'menu_group'    => '--',
        ]);
        // The old prefix check also denied this case because items has a location child permission.
        $this->assertFalse($employee->has_module_grant('items', $location_only_cashier));

        $reports_cashier = $this->createCashier();
        $this->database->table('grants')->where('person_id', $reports_cashier)->delete();
        $this->database->table('grants')->insertBatch([
            ['permission_id' => 'reports', 'person_id' => $reports_cashier, 'menu_group' => 'home'],
            ['permission_id' => 'reports_sales', 'person_id' => $reports_cashier, 'menu_group' => '--'],
        ]);
        $this->assertTrue($employee->has_module_grant('reports', $reports_cashier));
        $this->assertTrue($employee->has_module_grant('reports_sales', $reports_cashier));
        $this->assertFalse($employee->has_module_grant('reports_sales_taxes', $reports_cashier));

        $taxes_report_cashier = $this->createCashier();
        $this->database->table('grants')->where('person_id', $taxes_report_cashier)->delete();
        $this->database->table('grants')->insert([
            'permission_id' => 'reports_sales_taxes',
            'person_id'     => $taxes_report_cashier,
            'menu_group'    => '--',
        ]);
        $this->assertFalse($employee->has_module_grant('reports_sales', $taxes_report_cashier));
        $this->assertTrue($employee->has_module_grant('reports_sales_taxes', $taxes_report_cashier));
    }

    /**
     * Finds English and Arabic Sales.cash values in the cash filter and payment summary.
     */
    public function testCashFiltersMatchStoredLabelsAcrossLocales(): void
    {
        $cashier_id    = $this->createCashier();
        $cashier       = $this->database->table('people')->where('person_id', $cashier_id)->get()->getRowArray();
        $category_name = 'Cash filter ' . bin2hex(random_bytes(4));

        $this->database->table('expense_categories')->insert([
            'category_name'        => $category_name,
            'category_description' => 'Cash filter database test',
            'deleted'              => 0,
        ]);
        $category_id                  = (int) $this->database->insertID();
        $this->expense_category_ids[] = $category_id;

        $payment_labels = [
            lang('Sales.cash'),
            lang('Sales.cash', [], 'ar-LB'),
        ];
        $expected_ids = [];

        foreach ($payment_labels as $index => $payment_label) {
            $this->database->table('expenses')->insert([
                'date'                => date('Y-m-d H:i:s'),
                'amount'              => $index === 0 ? '12345.67' : '76543.21',
                'payment_type'        => $payment_label,
                'expense_category_id' => $category_id,
                'description'         => 'Cash label filter test',
                'employee_id'         => $cashier_id,
                'deleted'             => 0,
            ]);
            $expense_id          = (int) $this->database->insertID();
            $this->expense_ids[] = $expense_id;
            $expected_ids[]      = $expense_id;
        }

        $filters = [
            'only_debit'  => false,
            'only_credit' => false,
            'only_cash'   => true,
            'only_due'    => false,
            'only_check'  => false,
            'is_deleted'  => false,
            'start_date'  => date('Y-m-d'),
            'end_date'    => date('Y-m-d'),
        ];
        $expense_model = new \App\Models\Expense();
        $found_ids     = array_map(
            'intval',
            array_column($expense_model->search($cashier['first_name'], $filters)->getResultArray(), 'expense_id'),
        );

        sort($expected_ids);
        sort($found_ids);
        $this->assertSame($expected_ids, $found_ids);

        $summary_by_label = array_column($expense_model->get_payments_summary('', $filters), null, 'payment_type');

        foreach ($payment_labels as $payment_label) {
            $this->assertArrayHasKey($payment_label, $summary_by_label);
            $this->assertSame(1, (int) $summary_by_label[$payment_label]['count']);
        }
    }

    /**
     * Creates a cashier through the employee model without ticking any optional module grants.
     */
    private function createCashier(): int
    {
        $person_data = [
            'first_name'   => 'Expenses',
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
            'username'      => 'expenses-cashier-' . bin2hex(random_bytes(4)),
            'password'      => password_hash('password', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language'      => 'english',
            'language_code' => 'en',
        ];
        $grants_data = [];

        if (! (new Employee())->save_employee($person_data, $employee_data, $grants_data)) {
            throw new RuntimeException('Unable to create the Expenses cashier fixture.');
        }

        $person_id           = (int) $employee_data['person_id'];
        $this->cashier_ids[] = $person_id;

        return $person_id;
    }

    /**
     * Reads the menu group saved for one employee permission.
     */
    private function getMenuGroup(int $person_id, string $permission_id): ?string
    {
        $row = $this->database->table('grants')
            ->select('menu_group')
            ->where(['person_id' => $person_id, 'permission_id' => $permission_id])
            ->get()
            ->getRowArray();

        return $row['menu_group'] ?? null;
    }
}
