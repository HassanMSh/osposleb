<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_remove_stale_default_tax_rate extends Migration
{
    private const LEGACY_KEY = 'default_tax_rate';
    private const BACKUP_KEY = '__migration_20260922000000_default_tax_rate';

    /**
     * Moves the unused legacy default tax rate to a rollback-only backup key.
     */
    public function up(): void
    {
        $config = $this->db->table('app_config')->where('key', self::LEGACY_KEY)->get()->getRowArray();

        if ($config === null) {
            return;
        }

        $this->db->table('app_config')
            ->where('key', self::LEGACY_KEY)
            ->update(['key' => self::BACKUP_KEY]);
    }

    /**
     * Restores the legacy setting when its rollback-only backup still exists.
     */
    public function down(): void
    {
        $backup = $this->db->table('app_config')->where('key', self::BACKUP_KEY)->get()->getRowArray();

        if ($backup === null) {
            return;
        }

        $current = $this->db->table('app_config')->where('key', self::LEGACY_KEY)->get()->getRowArray();

        if ($current !== null) {
            $this->db->table('app_config')->delete(['key' => self::BACKUP_KEY]);

            return;
        }

        $this->db->table('app_config')
            ->where('key', self::BACKUP_KEY)
            ->update(['key' => self::LEGACY_KEY]);
    }
}
