<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use ReflectionObject;
use Throwable;

/**
 * The runtime privilege class of every table (rule 245 #6; owner decisions
 * D-Q3-a and D-Q3-b; DMS-SPN4-READY-PILOT-1 R6-B2).
 *
 * Two sources, never both for one table:
 * - config/runtime_privilege_classes.php classifies every table created by a
 *   migration up to and including `historical_through`. Those migrations are
 *   never edited (CG-09).
 * - every later migration declares each table it creates with
 *   #[RuntimePrivilegeClass].
 *
 * verify() compares the union with the live schema and fails closed: a table
 * without a class, a class for a table that does not exist, an unknown class,
 * or an updatable column that does not exist is an error. It reads pg_catalog,
 * which is not filtered by table ownership (unlike information_schema), so the
 * answer is the same for the owner and for a non-owner runtime.
 *
 * Nothing here grants anything. Grants come only from R6-B3 provisioning.
 */
final class RuntimePrivilegeRegistry
{
    /** SQL a migration never runs: privileges and roles are provisioning, never a migration (D-Q3-b, rule 245). */
    public const FORBIDDEN_MIGRATION_SQL = [
        'GRANT' => '/\bGRANT\s+(ALL|SELECT|INSERT|UPDATE|DELETE|TRUNCATE|REFERENCES|TRIGGER|USAGE|EXECUTE|CREATE|CONNECT|TEMP|TEMPORARY|MAINTAIN)\b/i',
        'REVOKE' => '/\bREVOKE\s+(ALL|SELECT|INSERT|UPDATE|DELETE|TRUNCATE|REFERENCES|TRIGGER|USAGE|EXECUTE|CREATE|CONNECT|TEMP|TEMPORARY|MAINTAIN|GRANT\s+OPTION)\b/i',
        'ALTER DEFAULT PRIVILEGES' => '/\bALTER\s+DEFAULT\s+PRIVILEGES\b/i',
        'role DDL' => '/\b(CREATE|ALTER|DROP)\s+(ROLE|USER|GROUP)\b/i',
        'OWNER TO' => '/\bOWNER\s+TO\b/i',
        'REASSIGN OWNED' => '/\bREASSIGN\s+OWNED\b/i',
        'SET ROLE' => '/\bSET\s+(LOCAL\s+|SESSION\s+)?ROLE\b/i',
        'SET SESSION AUTHORIZATION' => '/\bSET\s+SESSION\s+AUTHORIZATION\b/i',
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly string $migrationPath;

    /** @param  array<string, mixed>|null  $config  defaults to config('runtime_privilege_classes') */
    public function __construct(?array $config = null, ?string $migrationPath = null)
    {
        $this->config = $config ?? (array) config('runtime_privilege_classes', []);
        $this->migrationPath = $migrationPath ?? database_path('migrations');
    }

    public function historicalThrough(): string
    {
        return (string) ($this->config['historical_through'] ?? '');
    }

    /**
     * The expected class of every table, and the problems found while reading the sources.
     *
     * @return array{0: array<string, array{class: string, columns: list<string>, source: string}>, 1: list<string>}
     */
    public function expected(): array
    {
        $entries = [];
        $errors = [];
        $cutoff = $this->historicalThrough();
        $files = $this->migrationFiles();

        if ($cutoff === '' || ! in_array($cutoff, array_keys($files), true)) {
            $errors[] = "historical_through '{$cutoff}' is not a migration in {$this->migrationPath}";
        }

        foreach ((array) ($this->config['tables'] ?? []) as $table => $definition) {
            [$class, $columns] = is_array($definition) ? [(string) ($definition[0] ?? ''), array_values((array) ($definition[1] ?? []))] : [(string) $definition, []];
            $entries[(string) $table] = ['class' => $class, 'columns' => $columns, 'source' => 'registry'];
        }

        foreach ($files as $name => $path) {
            if ($cutoff === '' || strcmp($name, $cutoff) <= 0) {
                continue;
            }

            [$declarations, $fileErrors] = $this->declarations($name, $path);
            array_push($errors, ...$fileErrors);

            foreach ($declarations as $table => $declaration) {
                if (isset($entries[$table])) {
                    $errors[] = "{$table} is classified twice: {$entries[$table]['source']} and migration {$name}";

                    continue;
                }
                $entries[$table] = $declaration;
            }
        }

        foreach ($entries as $table => $entry) {
            array_push($errors, ...$this->shapeErrors($table, $entry));
        }

        ksort($entries);

        return [$entries, $errors];
    }

    /**
     * Every problem: the sources, the live schema, and migrations that run privilege SQL.
     *
     * @return list<string>
     */
    public function verify(ConnectionInterface $connection): array
    {
        [$entries, $errors] = $this->expected();
        $schema = $this->schemaColumns($connection);

        foreach (array_keys($schema) as $table) {
            if (! isset($entries[$table])) {
                $errors[] = "{$table} has no runtime privilege class: classify it in config/runtime_privilege_classes.php only if a migration up to {$this->historicalThrough()} created it, otherwise declare #[RuntimePrivilegeClass] on the migration that creates it";
            }
        }

        foreach ($entries as $table => $entry) {
            if (! isset($schema[$table])) {
                $errors[] = "{$table} is classified ({$entry['source']}) but does not exist";

                continue;
            }
            foreach ($entry['columns'] as $column) {
                if (! in_array($column, $schema[$table], true)) {
                    $errors[] = "{$table}: updatable column {$column} does not exist";
                }
            }
        }

        array_push($errors, ...$this->forbiddenSqlErrors());
        sort($errors);

        return array_values(array_unique($errors));
    }

    /**
     * Code lines of every migration that run privilege or role SQL.
     *
     * @return list<string>
     */
    public function forbiddenSqlErrors(): array
    {
        $errors = [];
        foreach ($this->migrationFiles() as $name => $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                $code = ltrim($line);
                if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '#') || str_starts_with($code, '|')) {
                    continue;
                }
                foreach (self::FORBIDDEN_MIGRATION_SQL as $label => $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $errors[] = "migration {$name} line ".($number + 1)." runs {$label}: privileges and roles are provisioning (R6-B3), never a migration (D-Q3-b)";
                    }
                }
            }
        }

        return $errors;
    }

    /** @return array<string, int> class => number of tables */
    public function summary(): array
    {
        [$entries] = $this->expected();
        $counts = array_fill_keys(RuntimePrivilegeClass::CLASSES, 0);
        foreach ($entries as $entry) {
            $counts[$entry['class']] = ($counts[$entry['class']] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, string> migration name => path, in run order */
    private function migrationFiles(): array
    {
        $files = [];
        foreach (glob($this->migrationPath.'/*.php') ?: [] as $path) {
            $files[basename($path, '.php')] = $path;
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array{0: array<string, array{class: string, columns: list<string>, source: string}>, 1: list<string>}
     */
    private function declarations(string $name, string $path): array
    {
        $errors = [];
        $declarations = [];

        try {
            $migration = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $exception) {
            return [[], ["migration {$name} could not be loaded to read its runtime privilege classes: {$exception->getMessage()}"]];
        }

        if (! is_object($migration)) {
            return [[], ["migration {$name} must return an anonymous migration class so its #[RuntimePrivilegeClass] can be read"]];
        }

        foreach ((new ReflectionObject($migration))->getAttributes(RuntimePrivilegeClass::class) as $attribute) {
            try {
                $declared = $attribute->newInstance();
            } catch (Throwable $exception) {
                $errors[] = "migration {$name} has an invalid #[RuntimePrivilegeClass]: {$exception->getMessage()}";

                continue;
            }
            if (isset($declarations[$declared->table])) {
                $errors[] = "{$declared->table} is declared twice in migration {$name}";

                continue;
            }
            $declarations[$declared->table] = ['class' => $declared->class, 'columns' => array_values($declared->columns), 'source' => "migration {$name}"];
        }

        // A table this migration creates must be declared by this migration.
        preg_match_all("/Schema::(?:connection\([^)]*\)->)?create\(\s*['\"]([A-Za-z0-9_]+)['\"]/", (string) file_get_contents($path), $created);
        foreach (array_unique($created[1]) as $table) {
            if (! isset($declarations[$table])) {
                $errors[] = "migration {$name} creates {$table} without #[RuntimePrivilegeClass('{$table}', …)]";
            }
        }

        return [$declarations, $errors];
    }

    /**
     * @param  array{class: string, columns: list<string>, source: string}  $entry
     * @return list<string>
     */
    private function shapeErrors(string $table, array $entry): array
    {
        if (! in_array($entry['class'], RuntimePrivilegeClass::CLASSES, true)) {
            return ["{$table}: unknown runtime privilege class '{$entry['class']}' ({$entry['source']})"];
        }
        if ($entry['class'] === RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS && $entry['columns'] === []) {
            return ["{$table}: APPEND_ONLY_WITH_COLUMNS names no updatable column ({$entry['source']})"];
        }
        if ($entry['class'] !== RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS && $entry['columns'] !== []) {
            return ["{$table}: {$entry['class']} takes no updatable columns ({$entry['source']})"];
        }

        return [];
    }

    /** @return array<string, list<string>> table => columns, for the connection's current schema */
    private function schemaColumns(ConnectionInterface $connection): array
    {
        $columns = [];

        if ($connection->getDriverName() === 'pgsql') {
            $rows = $connection->select(<<<'SQL'
                SELECT c.relname AS table_name, a.attname AS column_name
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
                WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND NOT c.relispartition
                ORDER BY c.relname, a.attnum
                SQL);
            foreach ($rows as $row) {
                $columns[(string) $row->table_name][] = (string) $row->column_name;
            }

            return $columns;
        }

        foreach ($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $name = (string) $table->name;
            $columns[$name] = array_map(fn (object $column): string => (string) $column->name, $connection->select('PRAGMA table_info("'.str_replace('"', '""', $name).'")'));
        }

        return $columns;
    }
}
