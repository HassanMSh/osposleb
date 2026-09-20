<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\ShopLockdown;

class Migration_shop_lockdown extends Migration
{
    /**
     * Creates the first grant backup, removes unused modules, and applies the grant policy.
     */
    public function up(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $employees_table   = $this->db->prefixTable('employees');
        $backup_table      = $this->db->prefixTable('shop_lockdown_grants');

        $backup_exists = $this->db->tableExists($backup_table, false);
        if (! $backup_exists) {
            $this->db->query(
                'CREATE TABLE ' . $backup_table . ' (
                    permission_id varchar(255) NOT NULL,
                    person_id int(10) NOT NULL,
                    menu_group varchar(32) DEFAULT NULL,
                    PRIMARY KEY (permission_id, person_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            );
        }

        $removed_permissions = [
            'customers',
            'item_kits',
            'suppliers',
            'receivings',
            'receivings_stock',
            'giftcards',
            'messages',
            'expenses',
            'expenses_categories',
            'cashups',
            'office',
        ];
        $permission_list = implode(',', array_map([$this->db, 'escape'], $removed_permissions));

        if (! $backup_exists) {
            $this->db->query(
                'INSERT INTO ' . $backup_table . ' (permission_id, person_id, menu_group)
                 SELECT permission_id, person_id, menu_group
                 FROM ' . $grants_table,
            );
        }

        $this->db->query(
            'DELETE FROM ' . $grants_table . '
             WHERE permission_id IN (' . $permission_list . ')',
        );

        $this->db->table($permissions_table)->whereIn('permission_id', $removed_permissions)->delete();
        $this->db->table($modules_table)->whereIn('module_id', ShopLockdown::REMOVED_MODULES)->delete();

        $module_grants = ['items', 'reports', 'sales', 'home'];
        $admin         = $this->db->table($employees_table)
            ->select('person_id')
            ->where('username', 'admin')
            ->get()
            ->getRowArray();

        if ($admin !== null) {
            $admin_grants = [];

            foreach ($this->db->table($permissions_table)->select('permission_id')->get()->getResultArray() as $permission) {
                $admin_grants[] = [
                    'permission_id' => $permission['permission_id'],
                    'person_id'     => $admin['person_id'],
                    'menu_group'    => in_array($permission['permission_id'], $module_grants, true) ? 'home' : '--',
                ];
            }

            if ($admin_grants !== []) {
                $this->db->table($grants_table)->ignore(true)->insertBatch($admin_grants);
            }
        }

        $non_admins = $this->db->table($employees_table)
            ->select('person_id')
            ->where('username !=', 'admin')
            ->get()
            ->getResultArray();

        if ($non_admins !== []) {
            $this->db->table($grants_table)->whereIn('person_id', array_column($non_admins, 'person_id'))->delete();
        }

        $grant_rows = [];

        foreach ($non_admins as $employee) {
            foreach (ShopLockdown::NON_ADMIN_GRANTS as $permission_id) {
                $grant_rows[] = [
                    'permission_id' => $permission_id,
                    'person_id'     => $employee['person_id'],
                    'menu_group'    => in_array($permission_id, $module_grants, true) ? 'home' : '--',
                ];
            }
        }

        if ($grant_rows !== []) {
            $this->db->table($grants_table)->insertBatch($grant_rows);
        }
    }

    /**
     * Restores upstream module rows and the exact grant snapshot.
     *
     * @throws RuntimeException When the grant backup is missing.
     */
    public function down(): void
    {
        $modules_table     = $this->db->prefixTable('modules');
        $permissions_table = $this->db->prefixTable('permissions');
        $grants_table      = $this->db->prefixTable('grants');
        $backup_table      = $this->db->prefixTable('shop_lockdown_grants');

        if (! $this->db->tableExists($backup_table, false)) {
            throw new \RuntimeException('Cannot roll back shop lockdown: backup table ' . $backup_table . ' does not exist.');
        }

        $this->db->table($modules_table)->ignore(true)->insertBatch([
            ['name_lang_key' => 'module_customers', 'desc_lang_key' => 'module_customers_desc', 'sort' => 10, 'module_id' => 'customers'],
            ['name_lang_key' => 'module_item_kits', 'desc_lang_key' => 'module_item_kits_desc', 'sort' => 30, 'module_id' => 'item_kits'],
            ['name_lang_key' => 'module_suppliers', 'desc_lang_key' => 'module_suppliers_desc', 'sort' => 40, 'module_id' => 'suppliers'],
            ['name_lang_key' => 'module_receivings', 'desc_lang_key' => 'module_receivings_desc', 'sort' => 60, 'module_id' => 'receivings'],
            ['name_lang_key' => 'module_giftcards', 'desc_lang_key' => 'module_giftcards_desc', 'sort' => 90, 'module_id' => 'giftcards'],
            ['name_lang_key' => 'module_messages', 'desc_lang_key' => 'module_messages_desc', 'sort' => 98, 'module_id' => 'messages'],
            ['name_lang_key' => 'module_expenses', 'desc_lang_key' => 'module_expenses_desc', 'sort' => 108, 'module_id' => 'expenses'],
            ['name_lang_key' => 'module_expenses_categories', 'desc_lang_key' => 'module_expenses_categories_desc', 'sort' => 109, 'module_id' => 'expenses_categories'],
            ['name_lang_key' => 'module_cashups', 'desc_lang_key' => 'module_cashups_desc', 'sort' => 110, 'module_id' => 'cashups'],
            ['name_lang_key' => 'module_office', 'desc_lang_key' => 'module_office_desc', 'sort' => 999, 'module_id' => 'office'],
        ]);

        $this->db->table($permissions_table)->ignore(true)->insertBatch([
            ['permission_id' => 'customers', 'module_id' => 'customers', 'location_id' => null],
            ['permission_id' => 'item_kits', 'module_id' => 'item_kits', 'location_id' => null],
            ['permission_id' => 'suppliers', 'module_id' => 'suppliers', 'location_id' => null],
            ['permission_id' => 'receivings', 'module_id' => 'receivings', 'location_id' => null],
            ['permission_id' => 'receivings_stock', 'module_id' => 'receivings', 'location_id' => 1],
            ['permission_id' => 'giftcards', 'module_id' => 'giftcards', 'location_id' => null],
            ['permission_id' => 'messages', 'module_id' => 'messages', 'location_id' => null],
            ['permission_id' => 'expenses', 'module_id' => 'expenses', 'location_id' => null],
            ['permission_id' => 'expenses_categories', 'module_id' => 'expenses_categories', 'location_id' => null],
            ['permission_id' => 'cashups', 'module_id' => 'cashups', 'location_id' => null],
            ['permission_id' => 'office', 'module_id' => 'office', 'location_id' => null],
        ]);

        $this->db->table($grants_table)->emptyTable();

        if ($this->db->table($backup_table)->countAllResults() > 0) {
            $backup_grants = $this->db->table($backup_table)->get()->getResultArray();
            $this->db->table($grants_table)->insertBatch($backup_grants);
        }

        $this->db->query('DROP TABLE IF EXISTS ' . $backup_table);
    }
}
