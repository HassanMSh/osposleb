<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_receivings_lbp_total extends Migration
{
    /**
     * Add the Lebanese pound total and exchange rate saved with each new receiving.
     */
    public function up(): void
    {
        $this->db->resetDataCache();
        $columns = [];

        if (! $this->db->fieldExists('lbp_total', 'receivings')) {
            $columns['lbp_total'] = [
                'type'    => 'BIGINT',
                'null'    => true,
                'default' => null,
                'after'   => 'reference',
            ];
        }

        if (! $this->db->fieldExists('lbp_exchange_rate', 'receivings')) {
            $columns['lbp_exchange_rate'] = [
                'type'       => 'DECIMAL',
                'constraint' => '15,4',
                'null'       => true,
                'default'    => null,
                'after'      => 'lbp_total',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('receivings', $columns);
            $this->db->resetDataCache();
        }
    }

    /**
     * Remove the saved Lebanese pound total and exchange rate from receivings.
     */
    public function down(): void
    {
        $this->db->resetDataCache();

        foreach (['lbp_exchange_rate', 'lbp_total'] as $column) {
            if ($this->db->fieldExists($column, 'receivings')) {
                $this->forge->dropColumn('receivings', $column);
                $this->db->resetDataCache();
            }
        }
    }
}
