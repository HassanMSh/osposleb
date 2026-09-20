<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_default_admin_language extends Migration
{
    /**
     * Set the default admin account to English without overwriting a choice.
     */
    public function up(): void
    {
        $employees_table = $this->db->prefixTable('employees');

        $this->db->table($employees_table)
            ->where('username', 'admin')
            ->where('language', null)
            ->set([
                'language'      => 'english',
                'language_code' => 'en',
            ])
            ->update();
    }

    /**
     * Clear the English language values written for the admin account.
     */
    public function down(): void
    {
        $employees_table = $this->db->prefixTable('employees');

        $this->db->table($employees_table)
            ->where('username', 'admin')
            ->where('language_code', 'en')
            ->set([
                'language'      => null,
                'language_code' => null,
            ])
            ->update();
    }
}
