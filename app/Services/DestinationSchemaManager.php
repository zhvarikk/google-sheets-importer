<?php

namespace App\Services;

use App\Models\SheetTab;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DestinationSchemaManager
{
    private array $allowedTypes = [
        'string',
        'integer',
        'decimal',
        'date',
        'boolean',
    ];

    public function ensureSchema(SheetTab $sheetTab): void
    {
        $mappings = $sheetTab->columnMappings()->get();

        if ($mappings->isEmpty()) {
            throw new RuntimeException('No mappings configured.');
        }

        $tables = $mappings->groupBy('destination_table');

        foreach ($tables as $tableName => $tableMappings) {
            $this->validateTableName($tableName);

            // Validate mappings before changing the schema.
            $this->validateMappings($tableName, $tableMappings);

            if (!Schema::hasTable($tableName)) {
                $this->createTable($tableName, $tableMappings);
            } else {
                $this->addMissingColumns($tableName, $tableMappings);
            }
        }
    }

    private function validateMappings(string $tableName, $mappings): void
    {
        foreach ($mappings as $mapping) {
            $this->validateColumnName($mapping->destination_column);

            if (!in_array($mapping->data_type, $this->allowedTypes, true)) {
                throw new RuntimeException(
                    "Unsupported type: {$mapping->data_type}"
                );
            }
        }

        // Prevent multiple source columns from targeting the same
        // destination column.
        foreach ($mappings->groupBy('destination_column') as $column => $items) {
            if ($items->count() > 1) {
                throw new RuntimeException(
                    "Multiple mappings target {$tableName}.{$column}."
                );
            }
        }
    }

    private function createTable(string $tableName, $mappings): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($mappings) {
            $table->id();
            $table->timestamps();

            foreach ($mappings as $mapping) {
                $this->addMappedColumn($table, $mapping, false);
            }
        });
    }

    private function addMissingColumns(string $tableName, $mappings): void
    {
        $hasRows = DB::table($tableName)->exists();

        foreach ($mappings as $mapping) {
            $columnName = $mapping->destination_column;

            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use (
                $mapping,
                $hasRows
            ) {
                $this->addMappedColumn($table, $mapping, $hasRows);
            });
        }
    }

    private function addMappedColumn(
        Blueprint $table,
        object $mapping,
        bool $forceNullable
    ): void {
        $name = $mapping->destination_column;

        $column = match ($mapping->data_type) {
            'string' => $table->string($name, 255),
            'integer' => $table->bigInteger($name),
            'decimal' => $table->decimal($name, 18, 4),
            'date' => $table->date($name),
            'boolean' => $table->boolean($name),

            default => throw new RuntimeException(
                "Unsupported type: {$mapping->data_type}"
            ),
        };

        if ($forceNullable || ($mapping->is_nullable && !$mapping->is_required)) {
            $column->nullable();
        }

        if ($mapping->default_value !== null) {
            $column->default($mapping->default_value);
        }
    }

    private function validateTableName(string $name): void
    {
        $this->validateIdentifier($name);

        $reserved = [
            'migrations',
            'users',
            'sessions',
            'jobs',
            'failed_jobs',
            'column_mappings',
            'sheet_tabs',
            'google_sheets',
            'import_logs',
        ];

        if (in_array(strtolower($name), $reserved, true)) {
            throw new RuntimeException(
                "Reserved destination table: {$name}"
            );
        }
    }

    private function validateColumnName(string $name): void
    {
        $this->validateIdentifier($name);

        $reserved = [
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        if (in_array(strtolower($name), $reserved, true)) {
            throw new RuntimeException(
                "Reserved destination column: {$name}"
            );
        }
    }

    private function validateIdentifier(string $name): void
    {
        if (
            strlen($name) > 64 ||
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
        ) {
            throw new RuntimeException(
                "Invalid database identifier: {$name}"
            );
        }
    }
}