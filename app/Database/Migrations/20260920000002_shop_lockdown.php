<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\ShopLockdown;
use RuntimeException;
use Throwable;

class Migration_shop_lockdown extends Migration
{
    /**
     * Snapshots the access tables, removes unused modules, and applies the grant policy.
     *
     * @throws RuntimeException When a snapshot is incomplete or no active administrator exists.
     */
    public function up(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $employees_table   = $this->db->prefixTable('employees');
        $locations_table   = $this->db->prefixTable('stock_locations');
        $backup_table      = $this->db->prefixTable('shop_lockdown_grants');
        $module_backup     = $this->db->prefixTable('shop_lockdown_modules');
        $permission_backup = $this->db->prefixTable('shop_lockdown_permissions');
        $backup_tables     = [$backup_table, $module_backup, $permission_backup];
        $existing_backups  = array_filter($backup_tables, fn (string $table): bool => $this->db->tableExists($table, false));
        $snapshot_exists   = count($existing_backups) === count($backup_tables);

        if ($existing_backups !== [] && ! $snapshot_exists) {
            throw new RuntimeException('Cannot apply shop lockdown: snapshot tables are incomplete.');
        }

        $config_holders = array_column(
            $this->db->table($grants_table)
                ->select('grants.person_id')
                ->join($employees_table . ' AS employees', 'employees.person_id = grants.person_id')
                ->where('grants.permission_id', 'config')
                ->where('employees.deleted', 0)
                ->get()
                ->getResultArray(),
            'person_id',
        );
        $config_holders = array_map('intval', $config_holders);

        if ($config_holders === []) {
            throw new RuntimeException('Cannot apply shop lockdown: at least one active administrator is required.');
        }

        if (! $snapshot_exists) {
            $this->db->query(
                'CREATE TABLE ' . $backup_table . ' (
                    permission_id varchar(255) NOT NULL,
                    person_id int(10) NOT NULL,
                    menu_group varchar(32) DEFAULT NULL,
                    PRIMARY KEY (permission_id, person_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            );
            $this->db->query(
                'CREATE TABLE ' . $module_backup . ' (
                    name_lang_key varchar(255) NOT NULL,
                    desc_lang_key varchar(255) NOT NULL,
                    sort int(10) NOT NULL,
                    module_id varchar(255) NOT NULL,
                    PRIMARY KEY (module_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            );
            $this->db->query(
                'CREATE TABLE ' . $permission_backup . ' (
                    permission_id varchar(255) NOT NULL,
                    module_id varchar(255) NOT NULL,
                    location_id int(10) DEFAULT NULL,
                    PRIMARY KEY (permission_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            );

            $this->db->query(
                'INSERT INTO ' . $backup_table . ' (permission_id, person_id, menu_group)
                 SELECT permission_id, person_id, menu_group
                 FROM ' . $grants_table,
            );
            $this->db->query(
                'INSERT INTO ' . $module_backup . ' (name_lang_key, desc_lang_key, sort, module_id)
                 SELECT name_lang_key, desc_lang_key, sort, module_id
                 FROM ' . $modules_table,
            );
            $this->db->query(
                'INSERT INTO ' . $permission_backup . ' (permission_id, module_id, location_id)
                 SELECT permission_id, module_id, location_id
                 FROM ' . $permissions_table,
            );
        }

        $this->db->transStart();

        try {
            $removed_modules = ShopLockdown::REMOVED_MODULES;

            $removed_permission_ids = array_column(
                $this->db->table($permissions_table)
                    ->select('permission_id')
                    ->whereIn('module_id', $removed_modules)
                    ->get()
                    ->getResultArray(),
                'permission_id',
            );

            if ($removed_permission_ids !== []) {
                $this->db->table($grants_table)->whereIn('permission_id', $removed_permission_ids)->delete();
                $this->db->table($permissions_table)->whereIn('permission_id', $removed_permission_ids)->delete();
            }

            $this->db->table($modules_table)->whereIn('module_id', $removed_modules)->delete();

            foreach ([
                ['name_lang_key' => 'module_home', 'desc_lang_key' => 'module_home_desc', 'sort' => 1, 'module_id' => 'home'],
                ['name_lang_key' => 'module_employees', 'desc_lang_key' => 'module_employees_desc', 'sort' => 80, 'module_id' => 'employees'],
                ['name_lang_key' => 'module_taxes', 'desc_lang_key' => 'module_taxes_desc', 'sort' => 105, 'module_id' => 'taxes'],
                ['name_lang_key' => 'module_attributes', 'desc_lang_key' => 'module_attributes_desc', 'sort' => 107, 'module_id' => 'attributes'],
                ['name_lang_key' => 'module_config', 'desc_lang_key' => 'module_config_desc', 'sort' => 110, 'module_id' => 'config'],
                ['name_lang_key' => 'module_office', 'desc_lang_key' => 'module_office_desc', 'sort' => 999, 'module_id' => 'office'],
            ] as $module_row) {
                if ($this->db->table($modules_table)->where('module_id', $module_row['module_id'])->countAllResults() === 0) {
                    $this->db->table($modules_table)->insert($module_row);
                }
            }

            $this->db->table($modules_table)
                ->where('module_id', 'office')
                ->update(['sort' => 999]);

            foreach (['home', 'employees', 'taxes', 'attributes', 'config', 'office'] as $permission_id) {
                if ($this->db->table($permissions_table)->where('permission_id', $permission_id)->countAllResults() === 0) {
                    $this->db->table($permissions_table)->insert([
                        'permission_id' => $permission_id,
                        'module_id'     => $permission_id,
                        'location_id'   => null,
                    ]);
                }
            }

            $permission_rows = $this->db->table($permissions_table)
                ->select('permission_id')
                ->get()
                ->getResultArray();
            $available_permissions = array_fill_keys(array_column($permission_rows, 'permission_id'), true);
            $active_employees      = $this->db->table($employees_table)
                ->select('person_id')
                ->where('deleted', 0)
                ->get()
                ->getResultArray();

            foreach ($active_employees as $employee) {
                $person_id = (int) $employee['person_id'];
                $is_admin  = in_array($person_id, $config_holders, true);

                if ($is_admin) {
                    foreach (array_keys($available_permissions) as $permission_id) {
                        $menu_group = ShopLockdown::menu_group($permission_id);
                        $grant      = $this->db->table($grants_table)
                            ->where('person_id', $person_id)
                            ->where('permission_id', $permission_id)
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
                                ->where('person_id', $person_id)
                                ->where('permission_id', $permission_id)
                                ->update(['menu_group' => $menu_group]);
                        }
                    }

                    continue;
                }

                $this->db->table($grants_table)->where('person_id', $person_id)->delete();
                $policy_permissions = ShopLockdown::STANDARD_CASHIER_GRANTS;
                $locations          = $this->db->table($locations_table)
                    ->select('location_name')
                    ->where('deleted', 0)
                    ->get()
                    ->getResultArray();

                foreach ($locations as $location) {
                    $location_name        = str_replace(' ', '_', $location['location_name']);
                    $policy_permissions[] = 'items_' . $location_name;
                    $policy_permissions[] = 'sales_' . $location_name;
                }

                $grant_rows = [];

                foreach (array_unique($policy_permissions) as $permission_id) {
                    if (! isset($available_permissions[$permission_id])) {
                        continue;
                    }

                    $grant_rows[] = [
                        'permission_id' => $permission_id,
                        'person_id'     => $person_id,
                        'menu_group'    => ShopLockdown::menu_group($permission_id),
                    ];
                }

                if ($grant_rows !== []) {
                    $this->db->table($grants_table)->insertBatch($grant_rows);
                }
            }

            $this->db->transComplete();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Cannot apply shop lockdown: the database transaction failed.');
        }
    }

    /**
     * Removes migration-created access rows, restores snapshots, and leaves employees created after them unchanged.
     *
     * @throws RuntimeException When a snapshot is missing or the rollback transaction fails.
     */
    public function down(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $backup_table      = $this->db->prefixTable('shop_lockdown_grants');
        $module_backup     = $this->db->prefixTable('shop_lockdown_modules');
        $permission_backup = $this->db->prefixTable('shop_lockdown_permissions');
        $backup_tables     = [$backup_table, $module_backup, $permission_backup];

        foreach ($backup_tables as $table) {
            if (! $this->db->tableExists($table, false)) {
                throw new RuntimeException('Cannot roll back shop lockdown: backup table ' . $table . ' does not exist.');
            }
        }

        $grant_rows              = $this->db->table($backup_table)->get()->getResultArray();
        $module_rows             = $this->db->table($module_backup)->get()->getResultArray();
        $permission_rows         = $this->db->table($permission_backup)->get()->getResultArray();
        $baseline_module_ids     = array_column($module_rows, 'module_id');
        $baseline_permission_ids = array_column($permission_rows, 'permission_id');
        $created_module_ids      = array_values(array_diff(
            ['home', 'employees', 'taxes', 'attributes', 'config', 'office'],
            $baseline_module_ids,
        ));
        $created_permission_ids = array_values(array_diff(
            ['home', 'employees', 'taxes', 'attributes', 'config', 'office'],
            $baseline_permission_ids,
        ));

        $this->db->transStart();

        try {
            if ($created_permission_ids !== []) {
                $this->db->table($grants_table)->whereIn('permission_id', $created_permission_ids)->delete();
                $this->db->table($permissions_table)->whereIn('permission_id', $created_permission_ids)->delete();
            }

            if ($created_module_ids !== []) {
                $this->db->table($modules_table)->whereIn('module_id', $created_module_ids)->delete();
            }

            foreach ($module_rows as $module_row) {
                $module_id      = $module_row['module_id'];
                $module_builder = $this->db->table($modules_table);
                $module_exists  = $module_builder->where('module_id', $module_id)->countAllResults() > 0;

                if ($module_exists) {
                    $this->db->table($modules_table)
                        ->where('module_id', $module_id)
                        ->update([
                            'name_lang_key' => $module_row['name_lang_key'],
                            'desc_lang_key' => $module_row['desc_lang_key'],
                            'sort'          => $module_row['sort'],
                        ]);
                } else {
                    $this->db->table($modules_table)->insert($module_row);
                }
            }

            foreach ($permission_rows as $permission_row) {
                $permission_id      = $permission_row['permission_id'];
                $permission_builder = $this->db->table($permissions_table);
                $permission_exists  = $permission_builder->where('permission_id', $permission_id)->countAllResults() > 0;

                if ($permission_exists) {
                    $this->db->table($permissions_table)
                        ->where('permission_id', $permission_id)
                        ->update([
                            'module_id'   => $permission_row['module_id'],
                            'location_id' => $permission_row['location_id'],
                        ]);
                } else {
                    $this->db->table($permissions_table)->insert($permission_row);
                }
            }

            $snapshot_person_ids = array_values(array_unique(array_column($grant_rows, 'person_id')));
            if ($snapshot_person_ids !== []) {
                $this->db->table($grants_table)->whereIn('person_id', $snapshot_person_ids)->delete();
                if ($grant_rows !== []) {
                    $this->db->table($grants_table)->insertBatch($grant_rows);
                }
            }

            $this->db->transComplete();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Cannot roll back shop lockdown: the database transaction failed.');
        }

        foreach ($backup_tables as $table) {
            $this->db->query('DROP TABLE ' . $table);
        }
    }
}
