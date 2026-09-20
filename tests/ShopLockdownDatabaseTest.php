<?php

namespace Tests;

use App\Database\Migrations\Migration_shop_lockdown;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\ShopLockdown;
use RuntimeException;
use Throwable;

require_once APPPATH . 'Database/Migrations/20260920000002_shop_lockdown.php';

/**
 * Covers the shop grant policy through the employee database save path.
 *
 * @internal
 */
final class ShopLockdownDatabaseTest extends CIUnitTestCase
{
    private $database;

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
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * Releases the test database connection after each database test.
     */
    protected function tearDown(): void
    {
        $this->database = null;
        parent::tearDown();
    }

    /**
     * Persists only allowed grants when a non-admin employee is saved.
     */
    public function testNonAdminEmployeeSaveUsesTheConfiguredGrantPolicy(): void
    {
        $this->database->transStart();

        $employee    = new Employee();
        $person_data = [
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
        $employee_data = [
            'username'      => 'lockdown-' . bin2hex(random_bytes(4)),
            'password'      => password_hash('password', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language'      => 'english',
            'language_code' => 'en',
        ];
        $grants_data = [
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'reports_sales', 'menu_group' => '--'],
            ['permission_id' => 'config', 'menu_group' => 'office'],
        ];

        try {
            $this->assertTrue($employee->save_employee($person_data, $employee_data, $grants_data));

            $saved_grants = $this->database->table('grants')
                ->select('permission_id')
                ->where('person_id', $employee_data['person_id'])
                ->orderBy('permission_id', 'asc')
                ->get()
                ->getResultArray();
            $saved_permission_ids = array_column($saved_grants, 'permission_id');

            $expected_permission_ids = ['reports_sales', 'sales'];
            sort($expected_permission_ids);

            $this->assertSame($expected_permission_ids, $saved_permission_ids);
            $this->assertNotContains('config', array_column($grants_data, 'permission_id'));
        } finally {
            $this->database->transRollback();
        }
    }

    /**
     * Applies and rolls back the migration while checking grants from the database.
     */
    public function testMigrationRestoresTheExactGrantSetAndCompletesAdminGrants(): void
    {
        $original_grants = $this->grantRows();
        $employee_id     = $this->database->table('employees')
            ->select('person_id')
            ->where('username !=', 'admin')
            ->orderBy('person_id', 'asc')
            ->get()
            ->getRow('person_id');
        $removed_grant = $this->database->table('grants')
            ->where('person_id', $employee_id)
            ->where('permission_id', 'config')
            ->get()
            ->getRowArray();

        $this->assertNotNull($employee_id);
        $this->assertNotEmpty($removed_grant);
        $this->database->table('grants')->delete([
            'person_id'     => $employee_id,
            'permission_id' => 'config',
        ]);
        $before_migration_grants = $this->grantRows();
        $migration               = new Migration_shop_lockdown();

        try {
            $migration->up();

            $backup_grants_before_replay = $this->backupRows();
            $migration->up();
            $this->assertSame($backup_grants_before_replay, $this->backupRows());

            $permission_ids = array_column(
                $this->database->table('permissions')->select('permission_id')->orderBy('permission_id', 'asc')->get()->getResultArray(),
                'permission_id',
            );
            $admin_id = $this->database->table('employees')
                ->select('person_id')
                ->where('username', 'admin')
                ->get()
                ->getRow('person_id');
            $admin_grant_ids = array_column(
                $this->database->table('grants')->select('permission_id')->where('person_id', $admin_id)->orderBy('permission_id', 'asc')->get()->getResultArray(),
                'permission_id',
            );
            $employee_grant_ids = array_column(
                $this->database->table('grants')->select('permission_id')->where('person_id', $employee_id)->orderBy('permission_id', 'asc')->get()->getResultArray(),
                'permission_id',
            );
            $expected_non_admin_grants = ShopLockdown::NON_ADMIN_GRANTS;
            sort($expected_non_admin_grants);

            $this->assertSame($permission_ids, $admin_grant_ids);
            $this->assertSame($expected_non_admin_grants, $employee_grant_ids);

            $migration->down();

            $this->assertSame($before_migration_grants, $this->grantRows());
        } finally {
            if ($this->database->tableExists($this->database->prefixTable('shop_lockdown_grants'), false)) {
                $migration->down();
            }

            if ($removed_grant !== []) {
                $this->database->table('grants')->ignore(true)->insert($removed_grant);
            }
        }

        $this->assertSame($original_grants, $this->grantRows());
    }

    /**
     * Fails with a clear message when rollback has no grant backup.
     */
    public function testMigrationRollbackReportsMissingBackup(): void
    {
        $migration = new Migration_shop_lockdown();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backup table ospos_shop_lockdown_grants does not exist');
        $migration->down();
    }

    /**
     * Reads all grant rows in a stable order for exact before-and-after checks.
     *
     * @return list<array<string, mixed>>
     */
    private function grantRows(): array
    {
        return $this->database->table('grants')
            ->select('permission_id, person_id, menu_group')
            ->orderBy('permission_id', 'asc')
            ->orderBy('person_id', 'asc')
            ->get()
            ->getResultArray();
    }

    /**
     * Reads the migration backup rows in a stable order for replay checks.
     *
     * @return list<array<string, mixed>>
     */
    private function backupRows(): array
    {
        return $this->database->table('shop_lockdown_grants')
            ->select('permission_id, person_id, menu_group')
            ->orderBy('permission_id', 'asc')
            ->orderBy('person_id', 'asc')
            ->get()
            ->getResultArray();
    }
}
