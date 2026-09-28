<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\ShopLockdown;
use RuntimeException;
use Throwable;

class Migration_restore_receivings extends Migration
{
    /**
     * Restores Receivings access for active administrators and removes it from all other employees.
     *
     * @throws RuntimeException When no active administrator exists or the transaction fails.
     */
    public function up(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $employees_table   = $this->db->prefixTable('employees');
        $locations_table   = $this->db->prefixTable('stock_locations');
        $config_holders    = array_map('intval', array_column(
            $this->db->table($grants_table)
                ->select('grants.person_id')
                ->join($employees_table . ' AS employees', 'employees.person_id = grants.person_id')
                ->where('grants.permission_id', 'config')
                ->where('employees.deleted', 0)
                ->get()
                ->getResultArray(),
            'person_id',
        ));

        if ($config_holders === []) {
            throw new RuntimeException('Cannot restore Receivings: at least one active administrator is required.');
        }

        $locations = $this->db->table($locations_table)
            ->select('location_id, location_name')
            ->where('deleted', 0)
            ->get()
            ->getResultArray();

        $required_permissions = ['receivings' => null];

        foreach ($locations as $location) {
            $required_permissions['receivings_' . str_replace(' ', '_', $location['location_name'])] = (int) $location['location_id'];
        }

        $this->db->transStart();

        try {
            if ($this->db->table($modules_table)->where('module_id', 'receivings')->countAllResults() === 0) {
                $this->db->table($modules_table)->insert([
                    'name_lang_key' => 'module_receivings',
                    'desc_lang_key' => 'module_receivings_desc',
                    'sort'          => 60,
                    'module_id'     => 'receivings',
                ]);
            }

            foreach ($required_permissions as $permission_id => $location_id) {
                if ($this->db->table($permissions_table)->where('permission_id', $permission_id)->countAllResults() === 0) {
                    $this->db->table($permissions_table)->insert([
                        'permission_id' => $permission_id,
                        'module_id'     => 'receivings',
                        'location_id'   => $location_id,
                    ]);
                }
            }

            $permission_rows = $this->db->table($permissions_table)
                ->select('permission_id, module_id')
                ->get()
                ->getResultArray();
            $receivings_permission_ids = [];

            foreach ($permission_rows as $permission_row) {
                $permission_id = (string) $permission_row['permission_id'];
                if ($permission_row['module_id'] === 'receivings' || $permission_id === 'receivings' || str_starts_with($permission_id, 'receivings_')) {
                    $receivings_permission_ids[] = $permission_id;
                }
            }

            $grant_rows = $this->db->table($grants_table)
                ->select('permission_id, person_id')
                ->get()
                ->getResultArray();

            foreach ($grant_rows as $grant_row) {
                $permission_id = (string) $grant_row['permission_id'];
                $is_receivings = in_array($permission_id, $receivings_permission_ids, true)
                    || $permission_id === 'receivings'
                    || str_starts_with($permission_id, 'receivings_');
                if ($is_receivings && ! in_array((int) $grant_row['person_id'], $config_holders, true)) {
                    $this->db->table($grants_table)
                        ->where('permission_id', $permission_id)
                        ->where('person_id', (int) $grant_row['person_id'])
                        ->delete();
                }
            }

            foreach ($config_holders as $person_id) {
                foreach ($required_permissions as $permission_id => $location_id) {
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
            throw new RuntimeException('Cannot restore Receivings: the database transaction failed.');
        }
    }

    /**
     * Removes the restored Receivings grants, permissions, and module.
     *
     * @throws RuntimeException When the rollback transaction fails.
     */
    public function down(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');

        $grant_rows = $this->db->table($grants_table)
            ->select('permission_id')
            ->get()
            ->getResultArray();
        $receivings_permission_ids = [];

        foreach ($grant_rows as $grant_row) {
            $permission_id = (string) $grant_row['permission_id'];
            if ($permission_id === 'receivings' || str_starts_with($permission_id, 'receivings_')) {
                $receivings_permission_ids[] = $permission_id;
            }
        }

        $permission_rows = $this->db->table($permissions_table)
            ->select('permission_id')
            ->where('module_id', 'receivings')
            ->get()
            ->getResultArray();
        $receivings_permission_ids = array_values(array_unique(array_merge(
            $receivings_permission_ids,
            array_column($permission_rows, 'permission_id'),
        )));

        $this->db->transStart();

        try {
            if ($receivings_permission_ids !== []) {
                $this->db->table($grants_table)->whereIn('permission_id', $receivings_permission_ids)->delete();
                $this->db->table($permissions_table)->whereIn('permission_id', $receivings_permission_ids)->delete();
            }

            $this->db->table($modules_table)->where('module_id', 'receivings')->delete();
            $this->db->transComplete();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Cannot roll back Receivings restoration: the database transaction failed.');
        }
    }
}
