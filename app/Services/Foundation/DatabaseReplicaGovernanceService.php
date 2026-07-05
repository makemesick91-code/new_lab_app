<?php

namespace App\Services\Foundation;

use App\Support\Database\DatabaseReplicaReadinessService;

/**
 * REPLICA-1 — read-only database replica governance rule catalog.
 */
class DatabaseReplicaGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'REPLICA-R001',
                'title' => 'Single-primary safe default',
                'description' => 'Default runtime must remain single-primary safe until a replica is explicitly configured and approved.',
            ],
            [
                'id' => 'REPLICA-R002',
                'title' => 'Writes stay on primary',
                'description' => 'All write operations must use the primary database connection; replica connections are for read-only workloads only.',
            ],
            [
                'id' => 'REPLICA-R003',
                'title' => 'Reporting read path readiness',
                'description' => 'Heavy report and analytics reads must use explicit service/repository paths before any future read routing change.',
            ],
            [
                'id' => 'REPLICA-R004',
                'title' => 'Non-destructive readiness command',
                'description' => 'The DB replica readiness command must be read-only and must not run insert, update, delete, DDL, or workflow mutations.',
            ],
            [
                'id' => 'REPLICA-R005',
                'title' => 'No secret exposure',
                'description' => 'Database secret values must not appear in command output, documentation, logs, tests, or governance summaries.',
            ],
            [
                'id' => 'REPLICA-R006',
                'title' => 'Replica lag audit before routing',
                'description' => 'Replica lag must be auditable before any read traffic is directed to a replica.',
            ],
            [
                'id' => 'REPLICA-R007',
                'title' => 'Strict missing config NO-GO',
                'description' => 'When a replica is expected, missing read host, database, username, or password must be NO-GO in strict mode.',
            ],
            [
                'id' => 'REPLICA-R008',
                'title' => 'Same host warning when expected',
                'description' => 'Primary/read hosts may be identical for pilot smoke, but must produce a warning when a replica is expected.',
            ],
            [
                'id' => 'REPLICA-R009',
                'title' => 'Rollback without destructive migration',
                'description' => 'Database scale deploys must keep a rollback path back to primary-only reads without destructive migrations.',
            ],
            [
                'id' => 'REPLICA-R010',
                'title' => 'Foundation summary integration',
                'description' => 'Foundation governance summary must surface DB replica readiness without weakening STORAGE, STATELESS, LB, NSF, or DMO governance.',
            ],
            [
                'id' => 'REPLICA-R011',
                'title' => 'Branch and permission isolation for read analytics',
                'description' => 'Cross-branch read analytics must continue to respect permissions, policies, and branch isolation.',
            ],
            [
                'id' => 'REPLICA-R012',
                'title' => 'No stale reads for critical workflows',
                'description' => 'Stale replica reads must not be used for payment, stock movement, visit completion, or finalization workflows.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(DatabaseReplicaReadinessService::class)->check();

        $decision = match ($readiness['decision']) {
            'NO_GO' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'replica_enabled' => $readiness['replica_enabled'],
            'replica_expected' => $readiness['replica_expected'],
            'replica_connection' => $readiness['replica_connection'],
            'replica_host_configured' => $readiness['replica_host_configured'],
            'replica_database_configured' => $readiness['replica_database_configured'],
            'replica_username_configured' => $readiness['replica_username_configured'],
            'replica_password_configured_as_boolean_only' => $readiness['replica_password_configured_as_boolean_only'],
            'warnings' => $readiness['warnings'],
        ];
    }
}
