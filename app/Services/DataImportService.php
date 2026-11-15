<?php

namespace App\Services;

use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DataImportService
{
    public function __construct(
        private DestinationSchemaManager $schemaManager
    ) {
    }

    public function import(SheetTab $sheetTab, array $records): array
    {
        // First, ensure destination tables and columns exist.
        $this->schemaManager->ensureSchema($sheetTab);

        // Build an allowlist from the configured mappings.
        $mappings = $sheetTab->columnMappings()->get();

        $allowed = [];

        foreach ($mappings as $mapping) {
            $allowed[$mapping->destination_table][] =
                $mapping->destination_column;
        }

        // Validate destination tables and columns.
        foreach ($records as $tableName => $tableRecords) {
            if (!isset($allowed[$tableName])) {
                throw new RuntimeException(
                    "Unconfigured destination table: {$tableName}"
                );
            }

            foreach ($tableRecords as $record) {
                $unexpected = array_diff(
                    array_keys($record),
                    $allowed[$tableName]
                );

                if (!empty($unexpected)) {
                    throw new RuntimeException(
                        'Unexpected destination columns: ' .
                        implode(', ', $unexpected)
                    );
                }
            }
        }

        $insertedCounts = [];

        // Insert all destination records in one transaction.
        DB::transaction(function () use (
            $records,
            &$insertedCounts
        ) {
            foreach ($records as $tableName => $tableRecords) {
                if (empty($tableRecords)) {
                    $insertedCounts[$tableName] = 0;
                    continue;
                }

                $now = now();

                // Populate timestamps where the destination table has them.
                foreach ($tableRecords as &$record) {
                    if (Schema::hasColumn($tableName, 'created_at')) {
                        $record['created_at'] = $now;
                    }

                    if (Schema::hasColumn($tableName, 'updated_at')) {
                        $record['updated_at'] = $now;
                    }
                }

                unset($record);

                $count = 0;

                // Process large datasets in batches.
                foreach (array_chunk($tableRecords, 500) as $chunk) {
                    DB::table($tableName)->insert($chunk);
                    $count += count($chunk);
                }

                $insertedCounts[$tableName] = $count;
            }
        });

        return [
            'success' => true,
            'inserted' => $insertedCounts,
            'total_inserted' => array_sum($insertedCounts),
        ];
    }
}