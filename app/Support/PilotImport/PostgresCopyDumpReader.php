<?php

namespace App\Support\PilotImport;

use InvalidArgumentException;
use RuntimeException;

class PostgresCopyDumpReader
{
    public const WHITELISTED_TABLES = [
        'mst_branches',
        'mst_doctors',
        'mst_patients',
        'mst_lab_services',
    ];

    /** @var list<string> */
    public const PROTECTED_TABLES = [
        'migrations',
        'roles',
        'permissions',
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
        'users',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'job_batches',
        'password_reset_tokens',
        'sys_audit_logs',
        'sys_attachments',
        'trx_invoices',
        'trx_invoice_items',
        'trx_payments',
        'trx_rme_invoices',
        'trx_rme_invoice_items',
        'trx_rme_payments',
        'trx_clinic_visits',
        'trx_medical_records',
        'trx_odontograms',
    ];

    private const COPY_PATTERN = '/^COPY public\.([a-z0-9_]+)\s*\(([^)]+)\)\s+FROM stdin;\s*$/i';

    /**
     * @return array{
     *     tables: array<string, list<array<string, mixed>>>,
     *     skipped_tables: array<string, int>,
     *     whitelisted_row_counts: array<string, int>
     * }
     */
    public function read(string $filePath): array
    {
        if (! is_readable($filePath)) {
            throw new InvalidArgumentException("Backup file is not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open backup file: {$filePath}");
        }

        $tables = array_fill_keys(self::WHITELISTED_TABLES, []);
        $skippedTables = [];
        $currentTable = null;
        $skippedTableName = null;
        $currentColumns = [];
        $buffer = '';

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line, "\r\n");

                if ($currentTable === null) {
                    if (preg_match(self::COPY_PATTERN, $trimmed, $matches)) {
                        $table = $matches[1];
                        if (in_array($table, self::WHITELISTED_TABLES, true)) {
                            $currentTable = $table;
                            $currentColumns = array_map(
                                static fn (string $column): string => trim($column),
                                explode(',', $matches[2])
                            );
                            $buffer = '';
                        } else {
                            $currentTable = '__skipped__';
                            $skippedTableName = $table;
                            $skippedTables[$table] = $skippedTables[$table] ?? 0;
                            $currentColumns = [];
                            $buffer = '';
                        }
                    }

                    continue;
                }

                if ($trimmed === '\\.') {
                    if ($currentTable !== '__skipped__' && $buffer !== '') {
                        $this->appendRow($tables[$currentTable], $currentColumns, $buffer);
                    }

                    $currentTable = null;
                    $skippedTableName = null;
                    $currentColumns = [];
                    $buffer = '';

                    continue;
                }

                if ($currentTable === '__skipped__') {
                    if ($trimmed !== '' && $skippedTableName !== null) {
                        $skippedTables[$skippedTableName]++;
                    }

                    continue;
                }

                $buffer = $buffer === '' ? $trimmed : $buffer."\n".$trimmed;

                if ($this->fieldCount($buffer) >= count($currentColumns)) {
                    $this->appendRow($tables[$currentTable], $currentColumns, $buffer);
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }

        $whitelistedRowCounts = [];
        foreach (self::WHITELISTED_TABLES as $table) {
            $whitelistedRowCounts[$table] = count($tables[$table]);
        }

        return [
            'tables' => $tables,
            'skipped_tables' => array_filter($skippedTables, static fn (int $count): bool => $count > 0),
            'whitelisted_row_counts' => $whitelistedRowCounts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $target
     * @param  list<string>  $columns
     */
    private function appendRow(array &$target, array $columns, string $line): void
    {
        $values = $this->splitCopyFields($line, count($columns));
        $row = [];

        foreach ($columns as $index => $column) {
            $row[$column] = $values[$index] ?? null;
        }

        $target[] = $row;
    }

    private function fieldCount(string $line): int
    {
        return count($this->splitCopyFields($line, PHP_INT_MAX));
    }

    /**
     * @return list<string|null>
     */
    private function splitCopyFields(string $line, int $expectedColumns): array
    {
        $fields = [];
        $field = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $field .= match ($line[$i + 1]) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    'b' => "\b",
                    'f' => "\f",
                    'v' => "\v",
                    '\\' => '\\',
                    default => '\\'.$line[$i + 1],
                };
                $i++;

                continue;
            }

            if ($char === "\t") {
                $fields[] = $this->normalizeCopyValue($field);
                $field = '';

                if (count($fields) === $expectedColumns - 1) {
                    $fields[] = $this->normalizeCopyValue(substr($line, $i + 1));

                    return $fields;
                }

                continue;
            }

            $field .= $char;
        }

        $fields[] = $this->normalizeCopyValue($field);

        return $fields;
    }

    private function normalizeCopyValue(string $value): ?string
    {
        return $value === '\\N' ? null : $value;
    }
}
