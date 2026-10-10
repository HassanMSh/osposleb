<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\ShopLockdown;
use RuntimeException;
use Throwable;

class Migration_restore_expenses extends Migration
{
    /**
     * Restores Expenses access for active administrators while leaving staff grants unchanged.
     *
     * @throws RuntimeException When no active administrator exists or the transaction fails.
     */
    public function up(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $employees_table   = $this->db->prefixTable('employees');
        $active_admin_ids  = array_values(array_unique(array_map('intval', array_column(
            $this->db->table($grants_table)
                ->select('grants.person_id')
                ->join($employees_table . ' AS employees', 'employees.person_id = grants.person_id')
                ->where('grants.permission_id', 'config')
                ->where('employees.deleted', 0)
                ->get()
                ->getResultArray(),
            'person_id',
        ))));

        if ($active_admin_ids === []) {
            throw new RuntimeException('Cannot restore Expenses: at least one active administrator is required.');
        }

        $modules = [
            'expenses' => [
                'name_lang_key' => 'module_expenses',
                'desc_lang_key' => 'module_expenses_desc',
                'sort'          => 108,
                'module_id'     => 'expenses',
            ],
            'expenses_categories' => [
                'name_lang_key' => 'module_expenses_categories',
                'desc_lang_key' => 'module_expenses_categories_desc',
                'sort'          => 109,
                'module_id'     => 'expenses_categories',
            ],
        ];
        $permissions = [
            'expenses'                    => 'expenses',
            'expenses_categories'         => 'expenses_categories',
            'reports_expenses_categories' => 'reports',
        ];

        $this->db->transStart();

        try {
            foreach ($modules as $module_id => $module) {
                if ($this->db->table($modules_table)->where('module_id', $module_id)->countAllResults() === 0) {
                    $this->db->table($modules_table)->insert($module);
                }
            }

            foreach ($permissions as $permission_id => $module_id) {
                if ($this->db->table($permissions_table)->where('permission_id', $permission_id)->countAllResults() === 0) {
                    $this->db->table($permissions_table)->insert([
                        'permission_id' => $permission_id,
                        'module_id'     => $module_id,
                        'location_id'   => null,
                    ]);
                }
            }

            foreach ($active_admin_ids as $person_id) {
                foreach ($permissions as $permission_id => $module_id) {
                    $menu_group = ShopLockdown::menu_group($permission_id);
                    $grant      = $this->db->table($grants_table)
                        ->where('permission_id', $permission_id)
                        ->where('person_id', $person_id)
                        ->get()
                        ->getRowArray();

                    if ($grant === null) {
                        $this->db->table($grants_table)->insert([
                            'permission_id' => $permission_id,
                            'person_id'     => $person_id,
                            'menu_group'    => $menu_group,
                        ]);
                    } elseif (($grant['menu_group'] ?? null) !== $menu_group) {
                        $this->db->table($grants_table)
                            ->where('permission_id', $permission_id)
                            ->where('person_id', $person_id)
                            ->update(['menu_group' => $menu_group]);
                    }
                }
            }

            $this->db->transComplete();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Cannot restore Expenses: the database transaction failed.');
        }
    }

    /**
     * Removes Expenses grants, permissions, and modules while keeping the existing report permission.
     *
     * @throws RuntimeException When the rollback transaction fails.
     */
    public function down(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $expense_modules   = ['expenses', 'expenses_categories'];
        $permission_rows   = $this->db->table($permissions_table)
            ->select('permission_id')
            ->whereIn('module_id', $expense_modules)
            ->get()
            ->getResultArray();
        $permission_ids = array_values(array_unique(array_merge(
            $expense_modules,
            array_column($permission_rows, 'permission_id'),
        )));

        $this->db->transStart();

        try {
            $this->db->table($grants_table)->whereIn('permission_id', $permission_ids)->delete();
            $this->db->table($permissions_table)->whereIn('module_id', $expense_modules)->delete();
            $this->db->table($modules_table)->whereIn('module_id', $expense_modules)->delete();
            $this->db->transComplete();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Cannot roll back Expenses restoration: the database transaction failed.');
        }
    }
}
