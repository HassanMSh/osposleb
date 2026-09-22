<?php

namespace App\Database\Migrations;

use App\Models\Item;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

class Migration_barcode_generation extends Migration
{
    private const INDEX_STATE_KEY = '__item_number_index';
    private const TEMP_INDEX_NAME = 'barcode_generation_item_number';

    /**
     * Preflights barcode conflicts, enforces uniqueness, backfills empty values, and sets barcode defaults.
     *
     * Backfilled barcode values are intentionally not reverted by down().
     *
     * @throws RuntimeException When existing barcode data or a database update prevents the migration.
     */
    public function up(): void
    {
        $this->preflightBarcodeConflicts();
        $this->createStateTable();
        $this->normalizeEmptyBarcodes();
        $this->ensureUniqueBarcodeIndex();

        // Re-check after the unique index is in place so a concurrent write cannot leave a duplicate behind.
        $generated_numbers = $this->preflightBarcodeConflicts();

        $this->backfillBarcodeNumbers($generated_numbers);
        $this->setConfigValue('barcode_type', 'C128');
        $this->setConfigValue('barcode_generate_if_empty', '1');
    }

    /**
     * Backfills generated barcode values in one transaction after uniqueness is enforced.
     *
     * @param array<int, string> $generated_numbers Generated barcode values keyed by item ID.
     *
     * @throws RuntimeException When a database update or transaction operation fails.
     */
    private function backfillBarcodeNumbers(array $generated_numbers): void
    {
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
     * Converts empty barcode strings to NULL so the unique index allows unassigned values during the backfill.
     *
     * @throws RuntimeException When the empty-value update fails.
     */
    private function normalizeEmptyBarcodes(): void
    {
        if (! $this->db->table('items')->where('item_number', '')->update(['item_number' => null])) {
            throw new RuntimeException('Cannot apply barcode generation migration: failed to normalize empty barcodes.');
        }
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
     * Establishes the unique barcode index and resumes safely from an earlier temporary-index state.
     */
    private function ensureUniqueBarcodeIndex(): void
    {
        $table_name = $this->db->prefixTable('items');
        $indexes    = $this->findBarcodeIndexes();
        $temporary  = null;
        $unique     = null;
        $existing   = null;

        foreach ($indexes as $index) {
            if ($index['index_name'] === self::TEMP_INDEX_NAME && (int) $index['non_unique'] === 0) {
                $temporary = $index;
            } elseif ((int) $index['non_unique'] === 0 && $unique === null) {
                $unique = $index;
            } elseif ((int) $index['non_unique'] === 1 && $existing === null) {
                $existing = $index;
            }
        }

        if ($temporary !== null) {
            $this->saveState(
                self::INDEX_STATE_KEY,
                $existing === null ? null : json_encode($existing, JSON_THROW_ON_ERROR),
                $existing !== null,
            );

            $temporary_name = $this->quoteIndexName((string) $temporary['index_name']);

            if ($existing !== null) {
                $old_index = $this->quoteIndexName((string) $existing['index_name']);
                $this->db->query(
                    'ALTER TABLE ' . $table_name
                    . ' DROP INDEX ' . $old_index
                    . ', RENAME INDEX ' . $temporary_name . ' TO `item_number`',
                );
            } elseif ($temporary['index_name'] !== 'item_number') {
                $this->db->query(
                    'ALTER TABLE ' . $table_name
                    . ' RENAME INDEX ' . $temporary_name . ' TO `item_number`',
                );
            }

            return;
        }

        if ($unique !== null) {
            return;
        }

        if ($existing === null) {
            $this->saveState(self::INDEX_STATE_KEY, null, false);
            $this->db->query('ALTER TABLE ' . $table_name . ' ADD UNIQUE KEY `item_number` (`item_number`)');

            return;
        }

        $old_index = $this->quoteIndexName((string) $existing['index_name']);
        $this->saveState(self::INDEX_STATE_KEY, json_encode($existing, JSON_THROW_ON_ERROR), true);
        $this->db->query(
            'ALTER TABLE ' . $table_name
            . ' DROP INDEX ' . $old_index
            . ', ADD UNIQUE KEY `item_number` (`item_number`)',
        );
    }

    /**
     * Quotes an index name for use in a migration ALTER TABLE statement.
     */
    private function quoteIndexName(string $index_name): string
    {
        return '`' . str_replace('`', '``', $index_name) . '`';
    }

    /**
     * Finds all single-column indexes on item_number, including temporary migration indexes.
     *
     * @return array<int, array{index_name: string, non_unique: int, columns: array<int, string>}>
     */
    private function findBarcodeIndexes(): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name
             FROM information_schema.statistics
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->prefixTable('items')],
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

        return array_values(array_filter(
            $indexes,
            static fn (array $index): bool => $index['columns'] === ['item_number'],
        ));
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
        $table_name       = $this->db->prefixTable('items');
        $current_indexes  = $this->findBarcodeIndexes();
        $drop_indexes     = [];
        $add_previous     = false;
        $previous         = null;
        $previous_present = (int) $state['was_present'] === 1;

        if ($previous_present) {
            $previous = json_decode((string) $state['previous_value'], true, 512, JSON_THROW_ON_ERROR);
        }

        foreach ($current_indexes as $index) {
            if (! $previous_present || (int) $index['non_unique'] === 0) {
                $drop_indexes[] = $index['index_name'];
            }
        }

        if ($previous_present) {
            $add_previous = true;

            foreach ($current_indexes as $index) {
                if ((int) $index['non_unique'] === 1 && $index['index_name'] === $previous['index_name']) {
                    $add_previous = false;

                    break;
                }
            }
        }

        $clauses = [];

        foreach (array_unique($drop_indexes) as $index_name) {
            $clauses[] = 'DROP INDEX ' . $this->quoteIndexName((string) $index_name);
        }

        if ($add_previous) {
            $clauses[] = 'ADD KEY ' . $this->quoteIndexName((string) $previous['index_name']) . ' (`item_number`)';
        }

        if ($clauses !== []) {
            $this->db->query('ALTER TABLE ' . $table_name . ' ' . implode(', ', $clauses));
        }
    }
}
