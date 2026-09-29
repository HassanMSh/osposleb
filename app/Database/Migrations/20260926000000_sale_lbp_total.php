<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_sale_lbp_total extends Migration
{
    /**
     * Add the rounded Lebanese pound total and the exchange rate saved with each completed sale.
     * Existing sales keep NULL in both columns because their collected pound total is unknown.
     */
    public function up(): void
    {
        $columns = [];

        if (! $this->db->fieldExists('lbp_total', 'sales')) {
            $columns['lbp_total'] = [
                'type'    => 'BIGINT',
                'null'    => true,
                'default' => null,
                'after'   => 'sale_type',
            ];
        }

        if (! $this->db->fieldExists('lbp_exchange_rate', 'sales')) {
            $columns['lbp_exchange_rate'] = [
                'type'       => 'DECIMAL',
                'constraint' => '15,4',
                'null'       => true,
                'default'    => null,
                'after'      => 'lbp_total',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('sales', $columns);
        }
    }

    /**
     * Remove the saved Lebanese pound total and exchange rate from sales.
     */
    public function down(): void
    {
        foreach (['lbp_exchange_rate', 'lbp_total'] as $column) {
            if ($this->db->fieldExists($column, 'sales')) {
                $this->forge->dropColumn('sales', $column);
            }
        }
    }
}
