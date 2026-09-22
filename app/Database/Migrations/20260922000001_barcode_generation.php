<?php

namespace App\Database\Migrations;

use App\Models\Item;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

class Migration_barcode_generation extends Migration
{
    private const INDEX_STATE_KEY = '__item_number_index';

    /**
     * Preflights barcode conflicts, backfills empty values, enforces uniqueness, and sets barcode defaults.
     *
     * Backfilled barcode values are intentionally not reverted by down().
     *
     * @throws RuntimeException When existing barcode data or a database update prevents the migration.
     */
    public function up(): void
    {
        $generated_numbers = $this->preflightBarcodeConflicts();
        $this->createStateTable();

        if (! $this->db->transBegin()) {
            throw new RuntimeException('Cannot apply barcode generation migration: could not start the backfill transaction.');
        }

        try {
            foreach ($generated_numbers as $item_id => $item_number) {
                $updated = $this->db->table('items')
                    ->where('item_id', $item_id)
                    ->update(['item_number' => $item_number]);

                if (! $updated) {
                    throw new RuntimeException('Cannot apply barcode generation migration: failed to backfill item ID ' . $item_id . '.');
                }
            }

            if (! $this->db->transStatus()) {
                throw new RuntimeException('Cannot apply barcode generation migration: the barcode backfill transaction failed.');
            }

            if (! $this->db->transCommit()) {
                throw new RuntimeException('Cannot apply barcode generation migration: could not commit the barcode backfill transaction.');
            }
        } catch (Throwable $exception) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            throw $exception;
        }

        $this->ensureUniqueBarcodeIndex();
        $this->setConfigValue('barcode_type', 'C128');
        $this->setConfigValue('barcode_generate_if_empty', '1');
    }

    /**
     * Restores settings and the previous item-number index shape without removing backfilled barcode values.
     */
    public function down(): void
    {
        $state_table = $this->db->prefixTable('barcode_generation_state');

        if (! $this->db->tableExists($state_table, false)) {
            return;
        }

        $states = $this->db->table($state_table)->get()->getResultArray();

        foreach ($states as $state) {
            if ($state['state_key'] === self::INDEX_STATE_KEY) {
                $this->restoreBarcodeIndex($state);
            }
        }

        foreach ($states as $state) {
            if (! in_array($state['state_key'], ['barcode_type', 'barcode_generate_if_empty'], true)) {
                continue;
            }

            $builder = $this->db->table('app_config')->where('key', $state['state_key']);

            if ((int) $state['was_present'] === 1) {
                if (! $builder->update(['value' => $state['previous_value']])) {
                    throw new RuntimeException('Cannot roll back barcode generation: failed to restore ' . $state['state_key'] . '.');
                }
            } elseif (! $builder->delete()) {
                throw new RuntimeException('Cannot roll back barcode generation: failed to remove ' . $state['state_key'] . '.');
            }
        }

        $this->db->query('DROP TABLE ' . $state_table);
    }

    /**
     * Finds duplicate existing barcodes and generated/manual collisions before changing database data or indexes.
     *
     * @return array<int, string> Generated barcode values keyed by empty item ID.
     *
     * @throws RuntimeException When conflicting item IDs are found.
     */
    private function preflightBarcodeConflicts(): array
    {
        $items_table = $this->db->prefixTable('items');
        $conflicts   = [];

        $duplicate_rows = $this->db->query(
            'SELECT GROUP_CONCAT(item_id ORDER BY item_id) AS item_ids
             FROM ' . $items_table . '
             WHERE item_number IS NOT NULL AND item_number <> \'\'
             GROUP BY item_number
             HAVING COUNT(*) > 1',
        )->getResultArray();

        foreach ($duplicate_rows as $duplicate_row) {
            foreach (explode(',', (string) $duplicate_row['item_ids']) as $item_id) {
                $conflicts[] = (int) $item_id;
            }
        }

        $empty_items = $this->db->table('items')
            ->select('item_id')
            ->groupStart()
            ->where('item_number', null)
            ->orWhere('item_number', '')
            ->groupEnd()
            ->get()
            ->getResultArray();
        $generated_numbers = [];
        $numbers_by_value  = [];
        $item_model        = model(Item::class);

        foreach ($empty_items as $empty_item) {
            $item_id     = (int) $empty_item['item_id'];
            $item_number = $item_model->generate_item_number($item_id);
            if (isset($numbers_by_value[$item_number])) {
                $conflicts[] = $item_id;
                $conflicts[] = $numbers_by_value[$item_number];
            }

            $numbers_by_value[$item_number] = $item_id;
            $generated_numbers[$item_id]    = $item_number;
        }

        foreach ($generated_numbers as $item_id => $item_number) {
            $manual_rows = $this->db->table('items')
                ->select('item_id')
                ->where('item_number', $item_number)
                ->where('item_id !=', $item_id)
                ->get()
                ->getResultArray();

            foreach ($manual_rows as $manual_row) {
                $conflicts[] = (int) $manual_row['item_id'];
                $conflicts[] = (int) $item_id;
            }
        }

        if ($conflicts !== []) {
            $conflicts = array_values(array_unique(array_map('intval', $conflicts)));
            sort($conflicts);

            throw new RuntimeException(
                'Cannot apply barcode generation migration: conflicting barcode item IDs: ' . implode(', ', $conflicts) . '.',
            );
        }

        return $generated_numbers;
    }

    /**
     * Creates the temporary migration state table used to make down() restore only changed settings and indexes.
     */
    private function createStateTable(): void
    {
        $state_table = $this->db->prefixTable('barcode_generation_state');

        $this->db->query(
            'CREATE TABLE IF NOT EXISTS ' . $state_table . ' (
                `state_key` varchar(64) NOT NULL,
                `previous_value` varchar(500) DEFAULT NULL,
                `was_present` tinyint(1) NOT NULL,
                PRIMARY KEY (`state_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * Adds a unique barcode index while keeping the old index until the new index exists.
     */
    private function ensureUniqueBarcodeIndex(): void
    {
        $table_name = $this->db->prefixTable('items');
        $unique     = $this->findBarcodeIndex(true);

        if ($unique !== null) {
            return;
        }

        $existing = $this->findBarcodeIndex(false);
        $this->saveState(self::INDEX_STATE_KEY, $existing === null ? null : json_encode($existing, JSON_THROW_ON_ERROR), $existing !== null);

        if ($existing === null) {
            $this->db->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `item_number` (`item_number`)');

            return;
        }

        $old_index = str_replace('`', '``', (string) $existing['index_name']);
        $this->db->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `barcode_generation_item_number` (`item_number`)');
        $this->db->query(
            'ALTER TABLE ' . $table_name
            . ' DROP INDEX `' . $old_index . '`'
            . ', RENAME INDEX `barcode_generation_item_number` TO `item_number`',
        );
    }

    /**
     * Finds a single-column item-number index with the requested uniqueness.
     */
    private function findBarcodeIndex(bool $unique): ?array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name
             FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND NON_UNIQUE = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->prefixTable('items'), $unique ? 0 : 1],
        )->getResultArray();
        $indexes = [];

        foreach ($rows as $row) {
            $index_name = (string) $row['index_name'];
            $indexes[$index_name] ??= [
                'index_name' => $index_name,
                'non_unique' => (int) $row['non_unique'],
                'columns'    => [],
            ];
            $indexes[$index_name]['columns'][] = (string) $row['column_name'];
        }

        foreach ($indexes as $index) {
            if ($index['columns'] === ['item_number']) {
                return [
                    'index_name' => $index['index_name'],
                    'non_unique' => $index['non_unique'],
                ];
            }
        }

        return null;
    }

    /**
     * Stores one original setting or index value unless it was already recorded during a failed retry.
     */
    private function saveState(string $state_key, ?string $previous_value, bool $was_present): void
    {
        $state_table = $this->db->prefixTable('barcode_generation_state');

        if ($this->db->table($state_table)->where('state_key', $state_key)->countAllResults() > 0) {
            return;
        }

        if (! $this->db->table($state_table)->insert([
            'state_key'      => $state_key,
            'previous_value' => $previous_value,
            'was_present'    => $was_present ? 1 : 0,
        ])) {
            throw new RuntimeException('Cannot apply barcode generation migration: failed to save migration state.');
        }
    }

    /**
     * Sets a barcode configuration value and records only values changed by this migration.
     */
    private function setConfigValue(string $key, string $value): void
    {
        $row = $this->db->table('app_config')->where('key', $key)->get()->getRowArray();

        if ($row === null) {
            $this->saveState($key, null, false);

            if (! $this->db->table('app_config')->insert(['key' => $key, 'value' => $value])) {
                throw new RuntimeException('Cannot apply barcode generation migration: failed to insert ' . $key . '.');
            }

            return;
        }

        if ((string) $row['value'] === $value) {
            return;
        }

        $this->saveState($key, (string) $row['value'], true);

        if (! $this->db->table('app_config')->where('key', $key)->update(['value' => $value])) {
            throw new RuntimeException('Cannot apply barcode generation migration: failed to update ' . $key . '.');
        }
    }

    /**
     * Restores the pre-migration barcode index shape recorded in migration state.
     */
    private function restoreBarcodeIndex(array $state): void
    {
        $current = $this->findBarcodeIndex(true);

        if ($current !== null) {
            $table_name   = $this->db->prefixTable('items');
            $current_name = str_replace('`', '``', (string) $current['index_name']);
            $this->db->query('ALTER TABLE ' . $table_name . ' DROP INDEX `' . $current_name . '`');
        }

        if ((int) $state['was_present'] !== 1) {
            return;
        }

        $previous   = json_decode((string) $state['previous_value'], true, 512, JSON_THROW_ON_ERROR);
        $index_name = str_replace('`', '``', (string) $previous['index_name']);
        $table_name = $this->db->prefixTable('items');

        $this->db->query('ALTER TABLE ' . $table_name . ' ADD KEY `' . $index_name . '` (`item_number`)');
    }
}
