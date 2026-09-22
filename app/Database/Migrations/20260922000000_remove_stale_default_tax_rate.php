<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_remove_stale_default_tax_rate extends Migration
{
    /**
     * Removes the unused legacy default tax rate setting.
     */
    public function up(): void
    {
        $this->db->table('app_config')->delete(['key' => 'default_tax_rate']);
    }

    /**
     * Restores the legacy default tax rate setting removed by this migration.
     */
    public function down(): void
    {
        $this->db->table('app_config')->insert([
            'key'   => 'default_tax_rate',
            'value' => '8',
        ]);
    }
}
