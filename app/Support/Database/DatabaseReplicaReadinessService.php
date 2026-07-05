<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REPLICA-1 — read-only database replica readiness audit.
 *
 * This service never mutates database state and never returns secret values.
 */
class DatabaseReplicaReadinessService
{
    /**
     * @param  array{strict?: bool, connect_test?: bool, lag_check?: bool}  $options
     * @return array<string, mixed>
     */
    public function check(array $options = []): array
    {
        $strict = (bool) ($options['strict'] ?? false) || (bool) config('database_scale.replica.strict', false);
        $connectTestRequested = (bool) ($options['connect_test'] ?? false);
        $lagCheckRequested = (bool) ($options['lag_check'] ?? false);

        $defaultConnection = (string) config('database.default');
        $primaryConfig = (array) config("database.connections.{$defaultConnection}", []);
        $replicaConnection = (string) config('database_scale.replica.connection', 'pgsql_read');
        $replicaConfig = (array) config("database.connections.{$replicaConnection}", []);

        $replicaEnabled = (bool) config('database_scale.replica.enabled', false);
        $replicaExpected = (bool) config('database_scale.replica.expected', false);
        $maxLagSeconds = (int) config('database_scale.replica.max_lag_seconds', 5);
        $allowReportingReads = (bool) config('database_scale.replica.allow_reporting_reads', false);
        $deepHealthcheck = (bool) config('database_scale.replica.healthcheck_deep', false);
        $failOnLag = (bool) config('database_scale.replica.fail_on_lag', false);

        $missing = $this->missingReplicaKeys($replicaConfig);
        $warnings = [];

        if ($replicaExpected && ! $replicaEnabled) {
            $warnings[] = 'Replica is expected but DB_REPLICA_ENABLED is false; runtime remains single-primary safe.';
        }

        if ($replicaEnabled && $missing !== []) {
            $warnings[] = 'Replica is enabled but required read connection keys are missing: '.implode(', ', $missing).'.';
        }

        if ($replicaExpected && $this->configured($primaryConfig['host'] ?? null) && $this->configured($replicaConfig['host'] ?? null)
            && (string) $primaryConfig['host'] === (string) $replicaConfig['host']) {
            $warnings[] = 'Replica is expected but primary/read hosts are identical; acceptable for pilot smoke only.';
        }

        if ($maxLagSeconds > 30) {
            $warnings[] = 'Replica max lag threshold is above 30 seconds; keep transaction-critical reads on primary.';
        }

        if ($replicaExpected && ! $deepHealthcheck) {
            $warnings[] = 'Replica is expected but deep healthcheck is disabled.';
        }

        if (! $allowReportingReads) {
            $warnings[] = 'Reporting/analytics read routing is disabled; future scale sprint must opt in through services/repositories.';
        }

        $connectTest = $this->skipResult($replicaEnabled, $connectTestRequested, 'connect_test');
        $recoveryCheck = $this->skipResult($replicaEnabled, $connectTestRequested || $lagCheckRequested, 'recovery_check');
        $lagCheck = $this->skipResult($replicaEnabled, $lagCheckRequested, 'lag_check');
        $lagSeconds = null;

        if ($replicaEnabled && $missing === [] && ($connectTestRequested || $lagCheckRequested)) {
            $connectTest = $this->selectOne($replicaConnection, 'select 1 as ok');
            $recoveryCheck = $this->recoveryCheck($replicaConnection);

            if (($recoveryCheck['in_recovery'] ?? null) === false && $replicaExpected) {
                $warnings[] = 'Read connection is reachable but PostgreSQL reports pg_is_in_recovery() = false.';
            }
        }

        if ($replicaEnabled && $missing === [] && $lagCheckRequested) {
            $lagCheck = $this->lagCheck($replicaConnection);
            $lagSeconds = $lagCheck['lag_seconds'];

            if (is_numeric($lagSeconds) && $lagSeconds > $maxLagSeconds) {
                $warnings[] = "Replica lag {$lagSeconds}s exceeds DB_REPLICA_MAX_LAG_SECONDS {$maxLagSeconds}s.";
            }
        }

        $hasFailingCheck = in_array('failed', [
            $connectTest['status'],
            $recoveryCheck['status'],
            $lagCheck['status'],
        ], true);
        $strictFailure = $strict && ($replicaExpected || $replicaEnabled) && ($missing !== [] || $hasFailingCheck);
        $lagFailure = $failOnLag && is_numeric($lagSeconds) && $lagSeconds > $maxLagSeconds;

        $status = match (true) {
            $strictFailure || $lagFailure => 'fail',
            ! $replicaEnabled && ! $replicaExpected => 'single_primary_ready',
            $warnings !== [] => 'warning',
            $lagCheck['status'] === 'passed' => 'replica_connect_ready',
            $connectTest['status'] === 'passed' => 'replica_connect_ready',
            $replicaEnabled => 'replica_config_ready',
            default => 'single_primary_ready',
        };

        $decision = match ($status) {
            'fail' => 'NO_GO',
            'warning' => 'GO_WITH_WARNINGS',
            default => 'GO',
        };

        return [
            'status' => $status,
            'decision' => $decision,
            'app_env' => (string) config('app.env'),
            'app_debug_safe' => ! ((string) config('app.env') === 'production' && (bool) config('app.debug')),
            'default_connection' => $defaultConnection,
            'primary_driver' => (string) ($primaryConfig['driver'] ?? 'unknown'),
            'primary_host_configured' => $this->configured($primaryConfig['host'] ?? null),
            'replica_enabled' => $replicaEnabled,
            'replica_expected' => $replicaExpected,
            'replica_connection' => $replicaConnection,
            'replica_connection_configured' => $replicaConfig !== [],
            'replica_driver' => (string) ($replicaConfig['driver'] ?? 'unknown'),
            'replica_host_configured' => $this->configured($replicaConfig['host'] ?? null),
            'replica_database_configured' => $this->configured($replicaConfig['database'] ?? null),
            'replica_username_configured' => $this->configured($replicaConfig['username'] ?? null),
            'replica_password_configured_as_boolean_only' => $this->configured($replicaConfig['password'] ?? null),
            'missing_required_config_keys' => $missing,
            'connect_test_status' => $connectTest['status'],
            'connect_test_message' => $connectTest['message'],
            'recovery_check_status' => $recoveryCheck['status'],
            'recovery_check_message' => $recoveryCheck['message'],
            'recovery_check_in_recovery' => $recoveryCheck['in_recovery'] ?? null,
            'lag_check_status' => $lagCheck['status'],
            'lag_check_message' => $lagCheck['message'],
            'lag_seconds' => $lagSeconds,
            'max_lag_seconds' => $maxLagSeconds,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations(),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function missingReplicaKeys(array $config): array
    {
        $required = [
            'DB_READ_HOST' => $config['host'] ?? null,
            'DB_READ_DATABASE' => $config['database'] ?? null,
            'DB_READ_USERNAME' => $config['username'] ?? null,
            'DB_READ_PASSWORD' => $config['password'] ?? null,
        ];

        return collect($required)
            ->filter(fn (mixed $value): bool => ! $this->configured($value))
            ->keys()
            ->values()
            ->all();
    }

    private function configured(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function skipResult(bool $enabled, bool $requested, string $check): array
    {
        if (! $enabled) {
            return ['status' => 'skipped', 'message' => "{$check} skipped because DB_REPLICA_ENABLED is false."];
        }

        if (! $requested) {
            return ['status' => 'skipped', 'message' => "{$check} skipped; pass the matching command option to run it."];
        }

        return ['status' => 'pending', 'message' => "{$check} pending."];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function selectOne(string $connection, string $sql): array
    {
        try {
            DB::connection($connection)->select($sql);

            return ['status' => 'passed', 'message' => 'Read-only select probe succeeded.'];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => 'Read-only select probe failed: '.$this->safeError($e)];
        }
    }

    /**
     * @return array{status: string, message: string, in_recovery: bool|null}
     */
    private function recoveryCheck(string $connection): array
    {
        try {
            $row = DB::connection($connection)->selectOne('select pg_is_in_recovery() as in_recovery');

            return [
                'status' => 'passed',
                'message' => 'PostgreSQL recovery check succeeded.',
                'in_recovery' => (bool) ($row->in_recovery ?? false),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'skipped',
                'message' => 'PostgreSQL recovery check skipped: '.$this->safeError($e),
                'in_recovery' => null,
            ];
        }
    }

    /**
     * @return array{status: string, message: string, lag_seconds: float|int|null}
     */
    private function lagCheck(string $connection): array
    {
        try {
            $row = DB::connection($connection)->selectOne(
                'select extract(epoch from now() - pg_last_xact_replay_timestamp()) as lag_seconds'
            );
            $lag = $row->lag_seconds ?? null;

            return [
                'status' => 'passed',
                'message' => $lag === null
                    ? 'Replica lag check returned null; no replay timestamp available yet.'
                    : 'Replica lag check succeeded.',
                'lag_seconds' => $lag === null ? null : (float) $lag,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'skipped',
                'message' => 'Replica lag check skipped: '.$this->safeError($e),
                'lag_seconds' => null,
            ];
        }
    }

    private function safeError(Throwable $e): string
    {
        return str($e->getMessage())
            ->replaceMatches('/password=[^\\s;]*/i', 'password=[masked]')
            ->replaceMatches('/(DB_READ_PASSWORD|DB_PASSWORD)=\\S+/i', '$1=[masked]')
            ->limit(240)
            ->toString();
    }

    /**
     * @return list<string>
     */
    private function recommendations(): array
    {
        return [
            'Keep all writes on the primary database connection.',
            'Enable a read replica only after PostgreSQL replication is verified outside the app.',
            'Route reporting/analytics reads through explicit service/repository paths in a future scale sprint.',
            'Monitor replica lag before directing any read traffic to a replica.',
            'Keep a rollback plan that restores primary-only reads without destructive migrations.',
        ];
    }
}
