<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_barcode_content_number extends Migration
{
    /**
     * Set barcode sheets to use item numbers and keep upstream empty-barcode generation disabled.
     */
    public function up(): void
    {
        $config_values = [
            'barcode_content' => ['old' => 'id', 'new' => 'number'],
            // The upstream generation path does not generate anything, so keep this setting off.
            'barcode_generate_if_empty' => ['old' => '1', 'new' => '0'],
        ];

        foreach ($config_values as $key => $values) {
            $config = $this->db->table('app_config')->where('key', $key)->get()->getRowArray();

            if ($config === null) {
                $this->db->table('app_config')->insert(['key' => $key, 'value' => $values['new']]);
            } elseif ($config['value'] === $values['old']) {
                $this->db->table('app_config')->where('key', $key)->update(['value' => $values['new']]);
            }
        }
    }

    /**
     * Restore baseline barcode settings where the current value matches one written by this migration.
     * This also reverts an operator value changed manually to the same setting.
     */
    public function down(): void
    {
        $config_values = [
            'barcode_content'           => ['old' => 'number', 'new' => 'id'],
            'barcode_generate_if_empty' => ['old' => '0', 'new' => '1'],
        ];

        foreach ($config_values as $key => $values) {
            $this->db->table('app_config')
                ->where('key', $key)
                ->where('value', $values['old'])
                ->update(['value' => $values['new']]);
        }
    }
}
