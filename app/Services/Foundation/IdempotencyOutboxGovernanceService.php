<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Schema;

/**
 * ENT-6 — Idempotency & Outbox governance layer.
 *
 * This hardens the shipped QUEUE-1 primitives without enabling external
 * dispatch or wiring business-domain events. It stays separate from the
 * original QueueGovernanceService so QUEUE-1 and ENT-5 contracts remain intact.
 */
class IdempotencyOutboxGovernanceService
{
    public function __construct(
        private readonly QueueGovernanceService $queueGovernance,
        private readonly IdempotencyService $idempotency,
        private readonly OutboxService $outbox,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT6-IO001',
                'title' => 'Idempotency key convention is enforced through hashed storage',
                'description' => 'Raw idempotency keys must never be persisted; services compare only the SHA-256 hash and duplicate reservations within TTL must not reprocess work.',
            ],
            [
                'id' => 'ENT6-IO002',
                'title' => 'Outbox events require schema version and payload classification',
                'description' => 'Every outbox event must carry an event version and an allowed payload classification so future dispatchers can validate compatibility before processing.',
            ],
            [
                'id' => 'ENT6-IO003',
                'title' => 'Outbox payloads are PII and secret safe',
                'description' => 'Outbox payloads must reject keys shaped like KTP, NIK, passwords, tokens, secrets, contact details, or addresses; full identity and clinical content are denied categories.',
            ],
            [
                'id' => 'ENT6-IO004',
                'title' => 'External dispatch remains disabled until a later approved sprint',
                'description' => 'ENT-6 keeps outbox dispatch local and non-external; WhatsApp, API, and vendor sends must not happen directly from a request path or by default.',
            ],
            [
                'id' => 'ENT6-IO005',
                'title' => 'ENT-5 queue retry and failed-job governance remains mandatory',
                'description' => 'Any future queued outbox dispatcher must inherit the ENT-5 retry, backoff, timeout, failed-job, and ShouldQueue conventions before it can run.',
            ],
            [
                'id' => 'ENT6-IO006',
                'title' => 'Critical cross-module integrations use outbox before external side effects',
                'description' => 'Payment, invoice, inventory, lab-candidate, and notification integrations must pass through idempotency and outbox governance before any external side effect.',
            ],
            [
                'id' => 'ENT6-IO007',
                'title' => 'RME payment to lab candidate remains idempotent and non-creating',
                'description' => 'RME payment may produce an idempotent candidate event in a future sprint, but ENT-6 does not auto-create LabOrder and does not change payment rollback behavior.',
            ],
            [
                'id' => 'ENT6-IO008',
                'title' => 'Governance checks are read-only and privacy-safe',
                'description' => 'ENT-6 readiness commands report counts and classifications only; they never print raw keys, payload row data, secrets, or patient identifiers.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $queue = $this->queueGovernance->collect();
        $idempotencyAudit = Schema::hasTable('sys_idempotency_keys')
            ? $this->idempotency->audit()
            : $this->missingTable('IDEMPOTENCY-TABLE-EXISTS', 'sys_idempotency_keys table is missing.');
        $outboxAudit = Schema::hasTable('sys_outbox_events')
            ? $this->outbox->audit()
            : $this->missingTable('OUTBOX-TABLE-EXISTS', 'sys_outbox_events table is missing.');
        $queueRetry = $this->queueRetryGovernance->collect();

        $checks = [];
        $checks[] = $this->decisionCheck('ENT6-IO-QUEUE-GOVERNANCE', $queue['summary']['decision'] ?? 'FAIL', 'QUEUE-1 governance is GO.');
        $checks[] = $this->decisionCheck('ENT6-IO-IDEMPOTENCY-AUDIT', $idempotencyAudit['summary']['decision'] ?? 'FAIL', 'Idempotency audit is GO.');
        $checks[] = $this->decisionCheck('ENT6-IO-OUTBOX-AUDIT', $outboxAudit['summary']['decision'] ?? 'FAIL', 'Outbox audit is GO.');
        $checks[] = $this->decisionCheck('ENT6-IO-ENT5-QUEUE-RETRY', $queueRetry['decision'] ?? 'FAIL', 'ENT-5 queue retry governance is GO.');

        $idempotencyConfig = (array) config('queue_governance.idempotency', []);
        $checks[] = ($idempotencyConfig['enabled_foundation'] ?? false) === true
            && ($idempotencyConfig['key_hashing_required'] ?? false) === true
            && ($idempotencyConfig['raw_key_storage_allowed'] ?? true) === false
            ? $this->pass('ENT6-IO-IDEMPOTENCY-CONFIG', 'Idempotency config requires hashed keys and bans raw key storage.')
            : $this->fail('ENT6-IO-IDEMPOTENCY-CONFIG', 'Idempotency config must require hashed keys and ban raw key storage.');

        $outboxConfig = (array) config('queue_governance.outbox', []);
        $allowed = (array) ($outboxConfig['allowed_event_categories'] ?? []);
        $denied = (array) ($outboxConfig['denied_event_categories'] ?? []);
        $checks[] = ($outboxConfig['schema_version_required'] ?? false) === true
            && ($outboxConfig['payload_classification_required'] ?? false) === true
            && ($outboxConfig['safe_payload_only'] ?? false) === true
            && $allowed !== []
            && $denied !== []
            && array_intersect($allowed, $denied) === []
            ? $this->pass('ENT6-IO-OUTBOX-CONFIG', 'Outbox config requires versioning, classification, safe payloads, and separated allow/deny categories.')
            : $this->fail('ENT6-IO-OUTBOX-CONFIG', 'Outbox config must require versioning, classification, safe payloads, and separated allow/deny categories.');

        $externalDispatch = (bool) ($outboxConfig['external_dispatch_enabled'] ?? true)
            || app(FeatureFlagService::class)->enabled('foundation.queue.external_dispatch_enabled');
        $checks[] = ! $externalDispatch
            ? $this->pass('ENT6-IO-EXTERNAL-DISPATCH-OFF', 'External dispatch remains disabled.')
            : $this->fail('ENT6-IO-EXTERNAL-DISPATCH-OFF', 'External dispatch must remain disabled in ENT-6.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-6',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'idempotency_outbox_ready' : strtolower($decision),
            'idempotency_table_exists' => Schema::hasTable('sys_idempotency_keys'),
            'outbox_table_exists' => Schema::hasTable('sys_outbox_events'),
            'external_dispatch_enabled' => $externalDispatch,
            'queue_governance_decision' => $queue['summary']['decision'] ?? 'UNKNOWN',
            'idempotency_decision' => $idempotencyAudit['summary']['decision'] ?? 'UNKNOWN',
            'idempotency_total_records' => $idempotencyAudit['total_records'] ?? 0,
            'outbox_decision' => $outboxAudit['summary']['decision'] ?? 'UNKNOWN',
            'outbox_total_records' => $outboxAudit['total_records'] ?? 0,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'rules' => self::rules(),
            'commands' => [
                'foundation:idempotency-outbox-check',
                'foundation:idempotency-audit',
                'foundation:outbox-audit',
                'foundation:queue-governance-check',
                'foundation:queue-retry-failed-job-check',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; ENT-6 cannot be GO."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function missingTable(string $checkId, string $message): array
    {
        return [
            'summary' => ['decision' => 'WATCH', 'checks' => 1, 'passed' => 0, 'warnings' => 1, 'errors' => 0],
            'total_records' => 0,
            'checks' => [$this->warn($checkId, $message)],
        ];
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
