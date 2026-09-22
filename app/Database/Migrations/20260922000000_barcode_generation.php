<?php

namespace App\Database\Migrations;

use App\Models\Item;
use CodeIgniter\Database\Migration;

class Migration_barcode_generation extends Migration
{
    /**
     * Backfill empty item barcodes, enable generation, select Code 128, and enforce uniqueness.
     */
    public function up(): void
    {
        $item_model = model(Item::class);
        $items      = $this->db->table('items')
            ->select('item_id')
            ->groupStart()
            ->where('item_number', null)
            ->orWhere('item_number', '')
            ->groupEnd()
            ->get()
            ->getResultArray();

        foreach ($items as $item) {
            $this->db->table('items')
                ->where('item_id', (int) $item['item_id'])
                ->update(['item_number' => $item_model->generate_item_number((int) $item['item_id'])]);
        }

        $this->db->table('app_config')
            ->where('key', 'barcode_type')
            ->where('value', 'C39')
            ->update(['value' => 'C128']);
        $this->db->table('app_config')
            ->where('key', 'barcode_generate_if_empty')
            ->where('value', '0')
            ->update(['value' => '1']);

        $this->ensureUniqueBarcodeIndex();
    }

    /**
     * Restore the previous barcode type and generation setting without undoing uniqueness.
     */
    public function down(): void
    {
        $this->db->table('app_config')
            ->where('key', 'barcode_type')
            ->where('value', 'C128')
            ->update(['value' => 'C39']);
        $this->db->table('app_config')
            ->where('key', 'barcode_generate_if_empty')
            ->where('value', '1')
            ->update(['value' => '0']);
    }

    /**
     * Adds or upgrades the item barcode index while preserving an existing unique index.
     */
    private function ensureUniqueBarcodeIndex(): void
    {
        $table_name = $this->db->prefixTable('items');
        $unique     = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME, NON_UNIQUE
             HAVING COUNT(*) = 1
             AND MAX(CASE WHEN COLUMN_NAME = ? THEN 1 ELSE 0 END) = 1
             LIMIT 1',
            [$table_name, 'item_number'],
        )->getRowArray();

        if ($unique !== null) {
            return;
        }

        $existing = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND INDEX_NAME = ?
             LIMIT 1',
            [$table_name, 'item_number'],
        )->getRowArray();

        if ($existing !== null) {
            $this->db->query('ALTER TABLE ' . $table_name . ' DROP INDEX `item_number`');
        }

        $this->db->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `item_number` (`item_number`)');
    }
}
