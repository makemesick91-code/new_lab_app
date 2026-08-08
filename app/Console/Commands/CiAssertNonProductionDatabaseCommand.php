<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * CICD-CTRL-3 — production database isolation guard.
 *
 * Hard safety gate that must run BEFORE any migration or test step in CI,
 * on GitHub-hosted and self-hosted runners alike. A persistent self-hosted
 * runner keeps state between jobs, so a misconfigured environment could in
 * principle point a destructive migration at a real database. This command
 * makes that impossible to do silently.
 *
 * The check is rule-based, not denylist-based: a CI database MUST be local and
 * MUST carry a CI/test name. That blocks every remote database — including the
 * ones nobody thought to enumerate — while the explicit production denylist
 * catches a local restore of a production dump.
 *
 * Read-only: it inspects configuration and never opens a database connection,
 * never writes, and never prints a credential.
 */
class CiAssertNonProductionDatabaseCommand extends Command
{
    protected $signature = 'ci:assert-non-production-database
        {--json : Emit the verdict as JSON}';

    protected $description = 'CICD-CTRL-3: fail closed unless the active database is a local, non-production CI database.';

    public function handle(): int
    {
        $guard = (array) config('ci_runner.database_guard', []);

        $connection = (string) config('database.default', '');
        $connConfig = (array) config("database.connections.{$connection}", []);

        $appEnv = (string) config('app.env', '');
        $host = (string) ($connConfig['host'] ?? '');
        $database = (string) ($connConfig['database'] ?? '');
        $driver = (string) ($connConfig['driver'] ?? '');

        $failures = [];

        // 1. Environment must be a testing environment.
        $allowedEnvs = (array) ($guard['allowed_app_envs'] ?? ['testing']);
        if (! in_array($appEnv, $allowedEnvs, true)) {
            $failures[] = sprintf(
                'APP_ENV is "%s"; CI requires one of: %s.',
                $appEnv === '' ? '(empty)' : $appEnv,
                implode(', ', $allowedEnvs)
            );
        }

        /*
         * SQLite (including :memory:) is file-local by definition and cannot
         * reach a remote production server, so host/name rules do not apply.
         */
        $isSqlite = $driver === 'sqlite';

        if (! $isSqlite) {
            // 2. The database host must be local.
            $allowedHosts = (array) ($guard['allowed_hosts'] ?? []);
            if ($host === '' || ! in_array($host, $allowedHosts, true)) {
                $failures[] = sprintf(
                    'Database host "%s" is not a permitted CI host (%s). A CI database must be local.',
                    $host === '' ? '(empty)' : $host,
                    implode(', ', $allowedHosts)
                );
            }

            // 3. The database name must be explicitly allowed.
            if (! $this->databaseNameAllowed($database, $guard)) {
                $failures[] = sprintf(
                    'Database name "%s" is not a permitted CI database name.',
                    $database === '' ? '(empty)' : $database
                );
            }

            // 4. The database name must not be production, even if allowlisted.
            foreach ((array) ($guard['denied_databases'] ?? []) as $denied) {
                if ($denied !== '' && strcasecmp($database, (string) $denied) === 0) {
                    $failures[] = sprintf('Database name "%s" is a known production database.', $database);
                }
            }

            $lowerDatabase = strtolower($database);
            foreach ((array) ($guard['denied_database_substrings'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($lowerDatabase, strtolower((string) $needle))) {
                    $failures[] = sprintf(
                        'Database name "%s" contains the production marker "%s".',
                        $database,
                        $needle
                    );
                }
            }
        }

        $failures = array_values(array_unique($failures));
        $ok = $failures === [];

        // Never emit username or password — host/name/env are enough to debug.
        $payload = [
            'sprint' => 'CICD-CTRL-3',
            'check' => 'non_production_database',
            'status' => $ok ? 'PASS' : 'FAIL',
            'app_env' => $appEnv,
            'connection' => $connection,
            'driver' => $driver,
            'host' => $isSqlite ? '(local file)' : $host,
            'database' => $database,
            'failures' => $failures,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('CICD-CTRL-3 production database guard');
        $this->line("  app_env   : {$appEnv}");
        $this->line("  connection: {$connection} ({$driver})");
        $this->line('  host      : '.$payload['host']);
        $this->line("  database  : {$database}");

        if ($ok) {
            $this->info('PASS — the active database is a local, non-production CI database.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error("  - {$failure}");
        }
        $this->error('FAIL — refusing to run migrations or tests against this database.');

        return self::FAILURE;
    }

    private function databaseNameAllowed(string $database, array $guard): bool
    {
        if ($database === '') {
            return false;
        }

        foreach ((array) ($guard['allowed_databases'] ?? []) as $allowed) {
            if ($allowed !== '' && strcasecmp($database, (string) $allowed) === 0) {
                return true;
            }
        }

        foreach ((array) ($guard['allowed_database_patterns'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $database) === 1) {
                return true;
            }
        }

        return false;
    }
}
