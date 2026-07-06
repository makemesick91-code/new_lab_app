<?php

namespace App\Services\Foundation;

use App\Support\Security\SecurityComplianceScanner;

/**
 * ENT-9 — Security & PII Compliance Hardening governance layer.
 *
 * Locks KTP/NIK masking coverage, export permission gating, audit coverage,
 * and branch isolation to the enterprise standard, and confirms the ENT-5 /
 * ENT-6 / ENT-7 / ENT-8 foundations it builds on remain GO. It stays separate
 * from the sibling governance services so their contracts remain intact and is
 * informational only (not wired into the blocking combined decision).
 */
class SecurityComplianceGovernanceService
{
    public function __construct(
        private readonly SecurityComplianceScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT9-SEC001',
                'title' => 'Full KTP/NIK never render, export, or log',
                'description' => 'KTP/NIK identifiers are server-side only; UI, reports, exports, and logs expose masked values only. The sidebar is never a security boundary — every gate is enforced server-side.',
            ],
            [
                'id' => 'ENT9-SEC002',
                'title' => 'Masking helpers stay present and enabled',
                'description' => 'The declared masking helpers (patient KTP masker, developer-console sensitive-value masker) must exist and stay enabled; removing/renaming one or disabling console masking fails this check.',
            ],
            [
                'id' => 'ENT9-SEC003',
                'title' => 'No unmasked KTP/NIK display echo in Blade',
                'description' => 'A read-only view scan flags any raw KTP/NIK display echo; form inputs and value bindings are excluded because they are not display leaks. New views must not introduce an unmasked identifier echo.',
            ],
            [
                'id' => 'ENT9-SEC004',
                'title' => 'Every data-export route is auth/permission gated',
                'description' => 'Every route whose name/URI indicates a data export must carry an auth/permission gate in its fully resolved middleware stack (group middleware included). No export surface is reachable without a permission gate.',
            ],
            [
                'id' => 'ENT9-SEC005',
                'title' => 'Immutable audit trail remains in place',
                'description' => 'The immutable sys_audit_logs trail must remain; sensitive access (e.g. the developer console, patient audit) writes an audit row and never mutates history.',
            ],
            [
                'id' => 'ENT9-SEC006',
                'title' => 'Branch isolation trusts BranchContext, never the request',
                'description' => 'Branch-owned queries scope through BranchContext::requireId(); a request-supplied branch_id is never trusted for scoping, and cross-branch analytics stay permission-gated and read-only.',
            ],
            [
                'id' => 'ENT9-SEC007',
                'title' => 'No cross-branch patient search by name/KTP',
                'description' => 'Patient lookup stays branch-scoped; cross-branch search by name or KTP is out of scope and must not be added without an explicit governance sprint.',
            ],
            [
                'id' => 'ENT9-SEC008',
                'title' => 'Governance check is read-only and privacy-safe',
                'description' => 'foundation:security-compliance-check reports posture only; its output never contains raw KTP/NIK, patient rows, secrets, or credentials, and it mutates nothing.',
            ],
            [
                'id' => 'ENT9-SEC009',
                'title' => 'No existing gate may be weakened',
                'description' => 'Security/PII hardening only tightens posture; it never weakens an existing permission, policy, consent, room, or completion gate.',
            ],
            [
                'id' => 'ENT9-SEC010',
                'title' => 'ENT-5/ENT-6/ENT-7/ENT-8 governance remain mandatory',
                'description' => 'The security pack surfaces queue/failed-job, idempotency/outbox, developer-console, and health-check governance without weakening them; those strict checks must stay GO.',
            ],
            [
                'id' => 'ENT9-SEC011',
                'title' => 'Scan patterns live in config, not app code',
                'description' => 'PII field names and scan regexes live in config/security_compliance.php so app code never carries the sensitive patterns inline; new PII surfaces register here.',
            ],
            [
                'id' => 'ENT9-SEC012',
                'title' => 'New PII/export/audit surfaces require governance and tests first',
                'description' => 'Any future PII field, export route, or audited surface must extend this contract with coverage and tests and pass this governance check before shipping.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('security_compliance.enabled', true);
        if (! $enabled) {
            $checks[] = $this->warn('ENT9-SEC-ENABLED', 'Security & PII compliance governance is disabled by configuration.');
        } else {
            $checks[] = $this->pass('ENT9-SEC-ENABLED', 'Security & PII compliance governance is enabled.');
        }

        $masking = $this->scanner->maskingPosture();
        $checks[] = $masking['ok']
            ? $this->pass('ENT9-SEC-MASKING', 'Masking helpers present and developer-console masking enabled.')
            : $this->fail('ENT9-SEC-MASKING', 'Masking posture failed: '.($masking['missing'] !== [] ? 'missing '.implode(', ', $masking['missing']) : 'developer-console masking disabled').'.');

        $viewScan = $this->scanner->viewScanPosture();
        $checks[] = $viewScan['ok']
            ? $this->pass('ENT9-SEC-VIEW-SCAN', 'No unmasked KTP/NIK display echo found ('.$viewScan['files_scanned'].' views scanned).')
            : $this->fail('ENT9-SEC-VIEW-SCAN', 'Unmasked KTP/NIK display echo found in '.count($viewScan['findings']).' location(s).');

        $exportGating = $this->scanner->exportGatingPosture();
        $checks[] = $exportGating['ok']
            ? $this->pass('ENT9-SEC-EXPORT-GATING', 'All '.$exportGating['export_routes'].' data-export routes are auth/permission gated.')
            : $this->fail('ENT9-SEC-EXPORT-GATING', 'Ungated export route(s): '.implode(', ', $exportGating['ungated']).'.');

        $auditIsolation = $this->scanner->auditAndIsolationPosture();
        $checks[] = $auditIsolation['ok']
            ? $this->pass('ENT9-SEC-AUDIT-ISOLATION', 'Immutable audit trail and branch-isolation context present.')
            : $this->fail('ENT9-SEC-AUDIT-ISOLATION', 'Audit/branch-isolation posture failed (audit table or BranchContext missing).');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT9-SEC-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT9-SEC-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT9-SEC-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT9-SEC-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-9',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'security_compliance_ready' : strtolower($decision),
            'security_compliance_enabled' => $enabled,
            'pii_fields' => (array) config('security_compliance.pii_fields', []),
            'masking_helpers_ok' => $masking['ok'],
            'developer_console_masking_enabled' => $masking['developer_console_masking_enabled'],
            'view_scan_ok' => $viewScan['ok'],
            'view_scan_findings' => $viewScan['findings'],
            'views_scanned' => $viewScan['files_scanned'],
            'export_gating_ok' => $exportGating['ok'],
            'export_routes_total' => $exportGating['export_routes'],
            'ungated_export_routes' => $exportGating['ungated'],
            'audit_table_exists' => $auditIsolation['audit_table_exists'],
            'branch_context_exists' => $auditIsolation['branch_context_exists'],
            'never_trust_request_branch_id' => $auditIsolation['never_trust_request_branch_id'],
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'health_check_decision' => $healthCheck['decision'] ?? 'UNKNOWN',
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
                'foundation:security-compliance-check',
                'foundation:health-check',
                'foundation:developer-console-check',
                'foundation:idempotency-outbox-check',
                'foundation:queue-retry-failed-job-check',
                'architecture:foundation-governance-summary',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; ENT-9 cannot be GO."),
        };
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
