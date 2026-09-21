<?php

namespace Tests;

use App\Controllers\Employees as EmployeesController;
use App\Database\Migrations\Migration_shop_lockdown;
use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;
use ReflectionClass;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20260920000002_shop_lockdown.php';

/**
 * Covers the shop lockdown policy through the database and model paths.
 *
 * @internal
 */
final class ShopLockdownDatabaseTest extends CIUnitTestCase
{
    private $database;
    private ?int $fixture_person_id = null;

    /**
     * Connects to the configured test database, or skips database tests when it is unavailable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->database = Database::connect('tests');
            $this->database->initialize();
            $this->database->query('SELECT 1');
            $this->resetLockdownMigration();
            $this->ensureCurrentSchema();
            $this->ensureBaselineEmployee();
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * Releases the test database connection after each database test.
     */
    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->cleanupSnapshotTables();
            $this->removeBaselineEmployee();
        }

        $this->database          = null;
        $this->fixture_person_id = null;
        parent::tearDown();
    }

    /**
     * Strips every administrative grant from a new employee and uses the exact cashier home menu.
     */
    public function testNewEmployeeCannotReceiveAdministrativeGrants(): void
    {
        $this->database->transStart();
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('lockdown-' . bin2hex(random_bytes(4)));
        $grants_data   = [
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'items', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'config', 'menu_group' => 'home'],
            ['permission_id' => 'employees', 'menu_group' => 'home'],
            ['permission_id' => 'taxes', 'menu_group' => 'home'],
            ['permission_id' => 'attributes', 'menu_group' => 'home'],
            ['permission_id' => 'office', 'menu_group' => 'home'],
            ['permission_id' => 'customers', 'menu_group' => 'home'],
        ];

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data));

            $saved_grants = $this->database->table('grants')
                ->select('permission_id, menu_group')
                ->where('person_id', $employee_data['person_id'])
                ->orderBy('permission_id', 'asc')
                ->get()
                ->getResultArray();
            $expected_grants = [
                ['permission_id' => 'home', 'menu_group' => 'office'],
                ['permission_id' => 'items', 'menu_group' => 'home'],
                ['permission_id' => 'sales', 'menu_group' => 'home'],
            ];

            $this->assertSame($expected_grants, $saved_grants);
            $this->assertSame(['items', 'sales'], array_column(
                (new Module())->get_allowed_home_modules((int) $employee_data['person_id'])->getResultArray(),
                'module_id',
            ));
            $this->assertNotContains('home', array_column(
                (new Module())->get_allowed_home_modules((int) $employee_data['person_id'])->getResultArray(),
                'module_id',
            ));
            $this->assertNotContains('config', array_column($saved_grants, 'permission_id'));
            $this->assertNotContains('employees', array_column($saved_grants, 'permission_id'));
            $this->assertNotContains('taxes', array_column($saved_grants, 'permission_id'));
            $this->assertNotContains('attributes', array_column($saved_grants, 'permission_id'));
            $this->assertNotContains('office', array_column($saved_grants, 'permission_id'));
            $this->assertNotContains('customers', array_column($saved_grants, 'permission_id'));
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Applies the standard cashier grants only when a new employee has no posted grants.
     */
    public function testNewEmployeeWithoutPostedGrantsUsesStandardCashierSet(): void
    {
        $this->database->transStart();
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('cashier-' . bin2hex(random_bytes(4)));
        $grants_data   = [];

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data));

            $saved_grants = $this->database->table('grants')
                ->select('permission_id, menu_group')
                ->where('person_id', $employee_data['person_id'])
                ->orderBy('permission_id', 'asc')
                ->get()
                ->getResultArray();

            $saved_permission_ids = array_column($saved_grants, 'permission_id');
            $this->assertContains('items', $saved_permission_ids);
            $this->assertContains('reports', $saved_permission_ids);
            $this->assertContains('sales', $saved_permission_ids);
            $cashier_home = array_column(
                (new Module())->get_allowed_home_modules((int) $employee_data['person_id'])->getResultArray(),
                'module_id',
            );
            $this->assertSame(['items', 'reports', 'sales'], $cashier_home);
            $this->assertNotContains('home', $cashier_home);
            $this->assertNotContains('config', $cashier_home);
            $this->assertSame(0, $this->database->table('grants')
                ->join('permissions', 'permissions.permission_id = grants.permission_id')
                ->where('grants.person_id', $employee_data['person_id'])
                ->whereIn('permissions.module_id', ['customers', 'item_kits', 'suppliers', 'receivings', 'giftcards', 'messages', 'expenses', 'expenses_categories', 'cashups'])
                ->countAllResults());
            $this->assertSame(0, $this->database->table('grants')
                ->where('person_id', $employee_data['person_id'])
                ->whereIn('permission_id', ['config', 'employees', 'taxes', 'attributes', 'office'])
                ->countAllResults());
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Ignores posted module placement and uses the fixed policy map for real menu queries.
     */
    public function testEmployeeSaveIgnoresPostedMenuGroups(): void
    {
        $this->database->transStart();
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('menu-' . bin2hex(random_bytes(4)));
        $grants_data   = [
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'items', 'menu_group' => 'invalid'],
            ['permission_id' => 'reports'],
            ['permission_id' => 'reports_sales', 'menu_group' => 'both'],
            ['permission_id' => 'sales', 'menu_group' => 'office'],
        ];

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data));
            $saved_grants = $this->database->table('grants')
                ->select('permission_id, menu_group')
                ->where('person_id', $employee_data['person_id'])
                ->orderBy('permission_id', 'asc')
                ->get()
                ->getResultArray();

            $this->assertSame([
                ['permission_id' => 'home', 'menu_group' => 'office'],
                ['permission_id' => 'items', 'menu_group' => 'home'],
                ['permission_id' => 'reports', 'menu_group' => 'home'],
                ['permission_id' => 'reports_sales', 'menu_group' => '--'],
                ['permission_id' => 'sales', 'menu_group' => 'home'],
            ], $saved_grants);
            $this->assertSame(['items', 'reports', 'sales'], array_column(
                (new Module())->get_allowed_home_modules((int) $employee_data['person_id'])->getResultArray(),
                'module_id',
            ));
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Preserves administrator grants on updates, including a username change.
     */
    public function testAdministratorSaveUsesCapabilityAndAllowsUsernameChange(): void
    {
        $this->database->transStart();
        $employee      = new Employee();
        $admin_id      = (int) $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('renamed-admin-' . bin2hex(random_bytes(4)));
        $grants_data   = [
            ['permission_id' => 'config', 'menu_group' => 'home'],
            ['permission_id' => 'employees', 'menu_group' => 'home'],
            ['permission_id' => 'taxes', 'menu_group' => 'home'],
        ];

        $this->database->table('grants')->where('person_id', $admin_id)->delete();
        $this->database->table('grants')->insertBatch([
            ['person_id' => $admin_id, 'permission_id' => 'config', 'menu_group' => 'office'],
            ['person_id' => $admin_id, 'permission_id' => 'employees', 'menu_group' => 'office'],
            ['person_id' => $admin_id, 'permission_id' => 'taxes', 'menu_group' => 'office'],
        ]);

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data, $admin_id));
            $this->assertSame($employee_data['username'], $this->database->table('employees')->where('person_id', $admin_id)->get()->getRow('username'));
            $this->assertSame(['config', 'employees', 'taxes'], array_column(
                $this->database->table('grants')->select('permission_id')->where('person_id', $admin_id)->orderBy('permission_id', 'asc')->get()->getResultArray(),
                'permission_id',
            ));

            $this->assertSame(['employees', 'taxes', 'config'], array_column(
                (new Module())->get_allowed_office_modules($admin_id)->getResultArray(),
                'module_id',
            ));
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Refuses to promote an existing non-administrator when the saved form includes Settings.
     */
    public function testEmployeeSaveDoesNotPromoteExistingNonAdministrator(): void
    {
        $this->database->transStart();
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('promote-' . bin2hex(random_bytes(4)));
        $grants_data   = [];

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data));
            $employee_id = (int) $employee_data['person_id'];

            $posted_grants = [
                ['permission_id' => 'config', 'menu_group' => 'office'],
                ['permission_id' => 'employees', 'menu_group' => 'home'],
                ['permission_id' => 'taxes', 'menu_group' => 'home'],
                ['permission_id' => 'attributes', 'menu_group' => 'home'],
                ['permission_id' => 'office', 'menu_group' => 'home'],
                ['permission_id' => 'items', 'menu_group' => 'home'],
            ];
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $posted_grants, $employee_id));
            $this->assertSame(0, $this->database->table('grants')
                ->where('person_id', $employee_id)
                ->whereIn('permission_id', ['config', 'employees', 'taxes', 'attributes', 'office'])
                ->countAllResults());
            $this->assertSame(['items'], array_column(
                (new Module())->get_allowed_home_modules($employee_id)->getResultArray(),
                'module_id',
            ));
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Allows an administrator to lose access when another administrator remains.
     */
    public function testEmployeeSaveAllowsRemovingAdministratorWithAnotherAdministrator(): void
    {
        $employee        = new Employee();
        $person_data     = $this->personData();
        $second_admin_id = $this->insertLaterEmployee();

        $this->database->table('grants')->insert([
            'permission_id' => 'config',
            'person_id'     => $second_admin_id,
            'menu_group'    => 'office',
        ]);
        $this->database->transStart();

        try {
            $admin_id            = (int) $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
            $admin_person_data   = $this->personData();
            $admin_employee_data = $this->employeeData('admin');
            $admin_grants        = [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
            ];

            $this->assertTrue($employee->save_employee($admin_person_data, $admin_employee_data, $admin_grants, $admin_id));
            $this->assertSame(0, $this->database->table('grants')->where(['person_id' => $admin_id, 'permission_id' => 'config'])->countAllResults());
            $this->assertSame(1, $this->database->table('grants')->where(['person_id' => $admin_id, 'permission_id' => 'sales'])->countAllResults());
            $this->assertSame(['config'], array_column(
                (new Module())->get_allowed_office_modules($second_admin_id)->getResultArray(),
                'module_id',
            ));
        } finally {
            $this->database->transRollback();
            $this->database->table('grants')->where('person_id', $second_admin_id)->delete();
            $this->database->table('employees')->where('person_id', $second_admin_id)->delete();
            $this->database->table('people')->where('person_id', $second_admin_id)->delete();
        }
    }

    /**
     * Refuses unknown employee updates without creating a replacement row.
     */
    public function testEmployeeSaveFailsForUnknownEmployee(): void
    {
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('unknown-' . bin2hex(random_bytes(4)));
        $grants_data   = [];

        $this->assertFalse($employee->save_employee($person_data, $employee_data, $grants_data, PHP_INT_MAX));
        $this->assertSame(0, $this->database->table('employees')->where('username', $employee_data['username'])->countAllResults());
    }

    /**
     * Refuses to remove the last active administrator capability.
     */
    public function testEmployeeSaveRefusesToRemoveLastAdministrator(): void
    {
        $admin_id = $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
        $this->assertNotNull($admin_id);
        $employee      = new Employee();
        $person_data   = $this->personData();
        $employee_data = $this->employeeData('admin');
        $grants_data   = [];

        $this->assertFalse($employee->save_employee($person_data, $employee_data, $grants_data, (int) $admin_id));
        $this->assertSame('last_administrator', $employee->get_save_failure_reason());
        $this->assertSame(1, $this->database->table('grants')->where([
            'person_id'     => $admin_id,
            'permission_id' => 'config',
        ])->countAllResults());
    }

    /**
     * Refuses to delete the last active administrator through the delete path.
     */
    public function testEmployeeDeleteRefusesLastAdministrator(): void
    {
        $admin_id     = (int) $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
        $logged_in_id = (int) $this->database->table('employees')
            ->where('username !=', 'admin')
            ->where('deleted', 0)
            ->get()
            ->getRow('person_id');
        $this->assertNotSame(0, $admin_id);
        $this->assertNotSame(0, $logged_in_id);

        session()->set('person_id', $logged_in_id);

        try {
            $this->assertFalse((new Employee())->delete($admin_id));
            $this->assertSame(0, (int) $this->database->table('employees')->where('person_id', $admin_id)->get()->getRow('deleted'));
        } finally {
            session()->remove('person_id');
        }
    }

    /**
     * Returns a JSON failure when the controller refuses to remove the last administrator.
     */
    public function testEmployeeControllerReturnsJsonForLastAdministratorRefusal(): void
    {
        $admin_id = (int) $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
        $request  = new IncomingRequest(new App(), new URI('/employees/save/' . $admin_id), null, new UserAgent());
        $request->setGlobal('post', [
            'first_name'   => 'Admin',
            'last_name'    => 'User',
            'gender'       => '',
            'email'        => '',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'username'     => 'admin',
            'language'     => 'en:english',
        ]);
        $controller       = (new ReflectionClass(EmployeesController::class))->newInstanceWithoutConstructor();
        $reflection       = new ReflectionClass($controller);
        $request_property = $reflection->getProperty('request');
        $request_property->setAccessible(true);
        $request_property->setValue($controller, $request);
        $employee_property = $reflection->getProperty('employee');
        $employee_property->setAccessible(true);
        $employee_property->setValue($controller, new Employee());
        $module_property = $reflection->getProperty('module');
        $module_property->setAccessible(true);
        $module_property->setValue($controller, new Module());

        ob_start();

        try {
            $controller->postSave($admin_id);
            $response = json_decode((string) ob_get_clean(), true);
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertSame(lang('Employees.cannot_remove_last_administrator'), $response['message']);
    }

    /**
     * Applies the migration and checks rows, real menu builders, and the office icon lookup.
     */
    public function testMigrationProducesCorrectRowsAndMenus(): void
    {
        $migration = new Migration_shop_lockdown();
        $this->assertFalse($this->hasSnapshotTables());

        try {
            $migration->up();

            $this->assertGreaterThan(
                $this->database->table('modules')->countAllResults(),
                $this->database->table('shop_lockdown_modules')->countAllResults(),
            );
            $this->assertGreaterThan(
                $this->database->table('permissions')->countAllResults(),
                $this->database->table('shop_lockdown_permissions')->countAllResults(),
            );
            $this->assertGreaterThan(0, $this->database->table('shop_lockdown_grants')->countAllResults());
            $this->assertSame(999, (int) $this->database->table('modules')->where('module_id', 'office')->get()->getRow('sort'));
            $this->assertSame(1, $this->database->table('permissions')->where('permission_id', 'office')->countAllResults());
            $this->assertSame('home', $this->grantGroup('admin', 'office'));

            foreach (['config', 'employees', 'taxes', 'attributes'] as $permission_id) {
                $this->assertSame('office', $this->grantGroup('admin', $permission_id));
            }

            $admin_id     = (int) $this->database->table('employees')->where('username', 'admin')->get()->getRow('person_id');
            $non_admin_id = (int) $this->database->table('employees AS employees')
                ->select('employees.person_id AS non_admin_id')
                ->join('grants AS grants', "grants.person_id = employees.person_id AND grants.permission_id = 'config'", 'left')
                ->where('employees.deleted', 0)
                ->where('grants.person_id IS NULL', null, false)
                ->get()
                ->getRow('non_admin_id');
            $this->assertNotSame(0, $non_admin_id);
            $this->assertSame(0, $this->database->table('grants')->where(['person_id' => $non_admin_id, 'permission_id' => 'office'])->countAllResults());
            $this->assertSame('office', $this->grantGroupByPerson($non_admin_id, 'home'));

            $module           = new Module();
            $admin_home       = array_column($module->get_allowed_home_modules($admin_id)->getResultArray(), 'module_id');
            $admin_office     = array_column($module->get_allowed_office_modules($admin_id)->getResultArray(), 'module_id');
            $non_admin_home   = array_column($module->get_allowed_home_modules($non_admin_id)->getResultArray(), 'module_id');
            $non_admin_office = array_column($module->get_allowed_office_modules($non_admin_id)->getResultArray(), 'module_id');

            $this->assertSame(['items', 'reports', 'sales', 'office'], $admin_home);
            $this->assertNotContains('home', $admin_home);
            $this->assertSame(['home', 'employees', 'taxes', 'attributes', 'config'], $admin_office);
            $this->assertSame(['items', 'reports', 'sales'], $non_admin_home);
            $this->assertNotContains('home', $non_admin_home);
            $this->assertNotContains('config', $non_admin_home);

            foreach (['office', 'config', 'employees', 'taxes', 'attributes'] as $module_id) {
                $this->assertNotContains($module_id, $non_admin_office);
            }

            $this->assertSame(999, (new Module())->get_show_office_group());
        } finally {
            if ($this->hasSnapshotTables()) {
                $migration->down();
            }
        }
    }

    /**
     * Preserves a later employee on rollback and creates a fresh snapshot on replay.
     */
    public function testMigrationRollbackPreservesLaterEmployeesAndReplaysSafely(): void
    {
        $migration            = new Migration_shop_lockdown();
        $later_employee_id    = null;
        $baseline_modules     = array_column($this->database->table('modules')->get()->getResultArray(), 'module_id');
        $baseline_permissions = array_column($this->database->table('permissions')->get()->getResultArray(), 'permission_id');
        $created_modules      = array_values(array_diff(['home', 'employees', 'taxes', 'attributes', 'config', 'office'], $baseline_modules));
        $created_permissions  = array_values(array_diff(['home', 'employees', 'taxes', 'attributes', 'config', 'office'], $baseline_permissions));

        try {
            $migration->up();
            $later_employee_id = $this->insertLaterEmployee();
            $this->database->transStart();
            $this->database->table('grants')->insert([
                'permission_id' => 'items',
                'person_id'     => $later_employee_id,
                'menu_group'    => 'home',
            ]);
            $this->database->transComplete();
            $this->assertTrue($this->database->transStatus());

            $migration->down();

            $this->assertSame(1, $this->database->table('employees')->where('person_id', $later_employee_id)->countAllResults());
            $this->assertSame(1, $this->database->table('grants')->where(['person_id' => $later_employee_id, 'permission_id' => 'items'])->countAllResults());

            foreach ($created_modules as $module_id) {
                $this->assertSame(0, $this->database->table('modules')->where('module_id', $module_id)->countAllResults());
            }

            foreach ($created_permissions as $permission_id) {
                $this->assertSame(0, $this->database->table('permissions')->where('permission_id', $permission_id)->countAllResults());
            }

            $migration->up();
            $this->assertTrue($this->hasSnapshotTables());
            $migration->down();
        } finally {
            if ($this->hasSnapshotTables()) {
                $migration->down();
            }

            if ($later_employee_id !== null) {
                $this->database->transStart();
                $this->database->table('grants')->where('person_id', $later_employee_id)->delete();
                $this->database->table('employees')->where('person_id', $later_employee_id)->delete();
                $this->database->table('people')->where('person_id', $later_employee_id)->delete();
                $this->database->transComplete();
            }
        }
    }

    /**
     * Fails with a clear, prefix-aware message when rollback has no snapshot.
     */
    public function testMigrationRollbackReportsMissingSnapshot(): void
    {
        $migration = new Migration_shop_lockdown();
        $this->assertFalse($this->hasSnapshotTables());
        $message = 'backup table ' . $this->database->prefixTable('shop_lockdown_grants') . ' does not exist';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $migration->down();
    }

    /**
     * Checks for an active administrator before creating any snapshot table.
     */
    public function testMigrationRequiresAnActiveAdministratorBeforeWriting(): void
    {
        $admin_grant = $this->database->table('grants')->where([
            'person_id'     => 1,
            'permission_id' => 'config',
        ])->get()->getRowArray();
        $this->assertNotNull($admin_grant);
        $this->database->table('grants')->where([
            'person_id'     => 1,
            'permission_id' => 'config',
        ])->delete();

        try {
            (new Migration_shop_lockdown())->up();
            $this->fail('The migration should require an active administrator.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('at least one active administrator', $exception->getMessage());
        } finally {
            $this->database->table('grants')->insert($admin_grant);
        }

        $this->assertFalse($this->hasSnapshotTables());
    }

    /**
     * Builds person fields for employee save tests.
     *
     * @return array<string, mixed>
     */
    private function personData(): array
    {
        return [
            'first_name'   => 'Lockdown',
            'last_name'    => 'Test',
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
    }

    /**
     * Builds employee fields for employee save tests.
     *
     * @return array<string, mixed>
     */
    private function employeeData(string $username): array
    {
        return [
            'username'      => $username,
            'password'      => password_hash('password', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language'      => 'english',
            'language_code' => 'en',
        ];
    }

    /**
     * Inserts an employee after the migration snapshot for rollback checks.
     *
     * @return int New employee person id.
     */
    private function insertLaterEmployee(): int
    {
        $this->database->transStart();
        $this->database->table('people')->insert($this->personData());
        $person_id                  = (int) $this->database->insertID();
        $employee_data              = $this->employeeData('later-' . bin2hex(random_bytes(4)));
        $employee_data['person_id'] = $person_id;
        $employee_data['deleted']   = 0;
        $this->database->table('employees')->insert($employee_data);
        $this->database->transComplete();

        if (! $this->database->transStatus()) {
            throw new RuntimeException('Unable to create the later employee test fixture.');
        }

        return $person_id;
    }

    /**
     * Reads one grant group by employee username and permission.
     */
    private function grantGroup(string $username, string $permission_id): ?string
    {
        $row = $this->database->table('grants')
            ->select('menu_group')
            ->join('employees', 'employees.person_id = grants.person_id')
            ->where('employees.username', $username)
            ->where('grants.permission_id', $permission_id)
            ->get()
            ->getRow();

        return $row?->menu_group;
    }

    /**
     * Reads one grant group by employee id and permission.
     */
    private function grantGroupByPerson(int $person_id, string $permission_id): ?string
    {
        return $this->database->table('grants')
            ->select('menu_group')
            ->where(['person_id' => $person_id, 'permission_id' => $permission_id])
            ->get()
            ->getRow('menu_group');
    }

    /**
     * Checks whether all persistent lockdown snapshot tables exist.
     */
    private function hasSnapshotTables(): bool
    {
        foreach (['shop_lockdown_grants', 'shop_lockdown_modules', 'shop_lockdown_permissions'] as $table) {
            if (! $this->database->tableExists($this->database->prefixTable($table), false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes complete or partial migration snapshots left by a failed test.
     */
    private function cleanupSnapshotTables(): void
    {
        $tables = ['shop_lockdown_grants', 'shop_lockdown_modules', 'shop_lockdown_permissions'];
        if ($this->hasSnapshotTables()) {
            (new Migration_shop_lockdown())->down();

            return;
        }

        foreach ($tables as $table) {
            $qualified_table = $this->database->prefixTable($table);
            if ($this->database->tableExists($qualified_table, false)) {
                $this->database->query('DROP TABLE ' . $qualified_table);
            }
        }
    }

    /**
     * Rolls back the applied lockdown migration so each test starts from its snapshot baseline.
     */
    private function resetLockdownMigration(): void
    {
        if ($this->hasSnapshotTables()) {
            (new Migration_shop_lockdown())->down();

            return;
        }

        foreach (['shop_lockdown_grants', 'shop_lockdown_modules', 'shop_lockdown_permissions'] as $table) {
            $qualified_table = $this->database->prefixTable($table);
            if ($this->database->tableExists($qualified_table, false)) {
                $this->database->query('DROP TABLE ' . $qualified_table);
            }
        }
    }

    /**
     * Adds current access columns to a legacy throwaway test schema when needed.
     */
    private function ensureCurrentSchema(): void
    {
        $employees_table = $this->database->prefixTable('employees');
        $employee_fields = $this->database->getFieldNames('employees');
        if (! in_array('language', $employee_fields, true)) {
            $this->database->query('ALTER TABLE ' . $employees_table . ' ADD COLUMN language varchar(48) DEFAULT NULL');
        }

        if (! in_array('language_code', $employee_fields, true)) {
            $this->database->query('ALTER TABLE ' . $employees_table . ' ADD COLUMN language_code varchar(8) DEFAULT NULL');
        }

        $grants_table = $this->database->prefixTable('grants');
        if (! in_array('menu_group', $this->database->getFieldNames('grants'), true)) {
            $this->database->query("ALTER TABLE {$grants_table} ADD COLUMN menu_group varchar(32) DEFAULT 'home'");
        }
    }

    /**
     * Creates one temporary non-administrator for migration policy checks.
     */
    private function ensureBaselineEmployee(): void
    {
        $username = 'shop-lockdown-fixture';
        $existing = $this->database->table('employees')
            ->where('username', $username)
            ->where('deleted', 0)
            ->get()
            ->getRow('person_id');
        if ($existing !== null) {
            return;
        }

        $deleted_existing = $this->database->table('employees')->where('username', $username)->get()->getRow();
        if ($deleted_existing !== null) {
            $this->database->table('employees')
                ->where('person_id', $deleted_existing->person_id)
                ->update(['deleted' => 0]);
            $this->fixture_person_id = (int) $deleted_existing->person_id;

            return;
        }

        $this->database->transStart();
        $this->database->table('people')->insert($this->personData());
        $person_id                  = (int) $this->database->insertID();
        $employee_data              = $this->employeeData($username);
        $employee_data['person_id'] = $person_id;
        $employee_data['deleted']   = 0;
        $this->database->table('employees')->insert($employee_data);
        $this->database->transComplete();

        if (! $this->database->transStatus()) {
            throw new RuntimeException('Unable to create the shop lockdown migration fixture.');
        }

        $this->fixture_person_id = $person_id;
    }

    /**
     * Removes the temporary migration fixture and its access rows.
     */
    private function removeBaselineEmployee(): void
    {
        if ($this->fixture_person_id === null) {
            return;
        }

        $this->database->transStart();
        $this->database->table('grants')->where('person_id', $this->fixture_person_id)->delete();
        $this->database->table('employees')->where('person_id', $this->fixture_person_id)->delete();
        $this->database->table('people')->where('person_id', $this->fixture_person_id)->delete();
        $this->database->transComplete();
    }
}
