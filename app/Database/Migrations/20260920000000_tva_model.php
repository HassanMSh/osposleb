<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_tva_model extends Migration
{
    /**
     * Add item TVA state and the Lebanese pound display-rate setting.
     */
    public function up(): void
    {
        $this->forge->addColumn('items', [
            'taxable' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
                'after'      => 'tax_category_id',
            ],
            'tax_exemption_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'exempt',
                'null'       => false,
                'after'      => 'taxable',
            ],
        ]);

        $items_table      = $this->db->prefixTable('items');
        $item_taxes_table = $this->db->prefixTable('items_taxes');

        $this->db->query(
            'UPDATE ' . $items_table . ' AS items
             SET taxable = 0
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM ' . $item_taxes_table . ' AS items_taxes
                 WHERE items_taxes.item_id = items.item_id
             )',
        );

        $this->db->table('app_config')->ignore(true)->insert([
            'key'   => 'lbp_exchange_rate',
            'value' => '89500',
        ]);
    }

    /**
     * Remove item TVA state and the Lebanese pound display-rate setting.
     */
    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE ' . $this->db->prefixTable('items') . ' DROP COLUMN `tax_exemption_reason`, DROP COLUMN `taxable`',
        );

        $this->db->table('app_config')->delete(['key' => 'lbp_exchange_rate']);
    }
}
