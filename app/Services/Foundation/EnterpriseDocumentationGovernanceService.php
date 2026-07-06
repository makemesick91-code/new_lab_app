<?php

namespace App\Services\Foundation;

use App\Support\Documentation\EnterpriseDocumentationScanner;

/**
 * ENT-15 — Enterprise Documentation & Runbook governance layer.
 *
 * Locks the mandatory enterprise runbook set (deploy, rollback, backup/DR,
 * restore rehearsal, release evidence/smoke, security/PII, health, developer
 * console, queue/outbox, load-test baseline, scale projection) as durable,
 * testable governance. It validates the runbook registry, the runbook files,
 * their required actionable sections, the forbidden-command declarations, the
 * destructive-command safety (config-not-code), the sensitive-content safety,
 * the foundation-command linkage, the summary command, the release-evidence
 * profiles, and the release-safety pre-deploy gate, and confirms the ENT-5..14
 * foundations it consolidates remain GO. It is informational only (not wired
 * into the blocking combined decision) and stays separate from the sibling
 * governance services so their contracts remain intact.
 */
class EnterpriseDocumentationGovernanceService
{
    public function __construct(
        private readonly EnterpriseDocumentationScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGateGovernance,
        private readonly DeploymentRollbackGovernanceService $deploymentRollbackGovernance,
        private readonly BackupDrGovernanceService $backupDrGovernance,
        private readonly LoadTestBaselineGovernanceService $loadTestBaselineGovernance,
        private readonly LoadTestScaleProjectionGovernanceService $loadTestScaleProjectionGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT15-DOC001',
                'title' => 'The enterprise runbook set is a governed, mandatory registry',
                'description' => 'config/enterprise_documentation.php declares the mandatory runbooks and the topics they must collectively cover (operations, deploy, rollback, backup/DR, restore rehearsal, release evidence, smoke, security/PII, health, developer console, queue/outbox, load-test baseline, scale projection). Every required topic must map to at least one runbook; a missing topic is a NO-GO.',
            ],
            [
                'id' => 'ENT15-DOC002',
                'title' => 'Every mandatory runbook file exists',
                'description' => 'Each declared runbook path resolves to a real file under docs/runbooks/. A missing runbook is a hard FAIL — documentation completeness is enforced, not assumed.',
            ],
            [
                'id' => 'ENT15-DOC003',
                'title' => 'Runbooks are actionable, not merely descriptive',
                'description' => 'Every runbook carries the required sections: purpose, when to use, prerequisites, safe commands, forbidden commands, evidence to capture, rollback/fallback, troubleshooting, smoke verification, security/PII notes, owner/reviewer, and review cadence.',
            ],
            [
                'id' => 'ENT15-DOC004',
                'title' => 'Runbooks document the destructive commands as forbidden',
                'description' => 'Each runbook names the destructive commands (migrate:fresh, db:wipe, schema:drop, migrate:reset) explicitly as forbidden and references its required foundation readiness commands so operators are pointed at the real gates.',
            ],
            [
                'id' => 'ENT15-DOC005',
                'title' => 'Destructive commands never appear as runnable examples',
                'description' => 'A destructive literal may only appear inside a runbook forbidden/warning section. Any destructive literal outside a safe-zone section is a violation. Every destructive literal lives in config, so the scanner source carries none inline (config-not-code).',
            ],
            [
                'id' => 'ENT15-DOC006',
                'title' => 'Documentation is non-sensitive (no secret / PII)',
                'description' => 'No runbook or ENT-15 governance doc contains a secret/credential/environment value or a KTP/NIK-shaped value; every doc passes the release-evidence forbidden-pattern/regex scan. Full KTP/NIK is never written in documentation.',
            ],
            [
                'id' => 'ENT15-DOC007',
                'title' => 'The runbook set links every foundation readiness command',
                'description' => 'The runbook set collectively references every ENT-5..15 readiness command (queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, deployment-rollback, backup/DR, load-test-baseline, scale-projection, enterprise-documentation), so the docs consolidate the whole foundation chain.',
            ],
            [
                'id' => 'ENT15-DOC008',
                'title' => 'A read-only runbook summary command is registered',
                'description' => 'docs:enterprise-runbook-summary renders the registry (runbooks, topics, coverage) read-only — no deploy, DB, or queue side effect. Documentation is testable governance, not a docs-only drop.',
            ],
            [
                'id' => 'ENT15-DOC009',
                'title' => 'Documentation readiness evidence is required per release profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-15 enterprise-documentation-check.json artifact plus the ENT-12..14 sibling artifacts; release:evidence-capture/check produce and validate them. The evidence pack is non-sensitive.',
            ],
            [
                'id' => 'ENT15-DOC010',
                'title' => 'Documentation readiness is a mandatory pre-deploy gate',
                'description' => 'foundation:enterprise-documentation-check is listed in the release-safety pre-deploy gates and runs (with evidence capture) in the deploy script and CI so runbook readiness is re-verified before every release.',
            ],
            [
                'id' => 'ENT15-DOC011',
                'title' => 'Documentation governance consolidates and preserves ENT-5..14',
                'description' => 'The gate re-verifies the queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, deployment-rollback, backup/DR, load-test-baseline, and scale-projection governance; those strict checks must stay GO for the documentation gate to be GO.',
            ],
            [
                'id' => 'ENT15-DOC012',
                'title' => 'New runbooks/sections register here with tests first',
                'description' => 'Any future runbook, required section, topic, or foundation command reference must extend config/enterprise_documentation.php with coverage and tests and pass foundation:enterprise-documentation-check before shipping. Docs never contain secrets or unmasked PII.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('enterprise_documentation.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT15-DOC-ENABLED', 'Enterprise documentation governance is enabled.')
            : $this->warn('ENT15-DOC-ENABLED', 'Enterprise documentation governance is disabled by configuration.');

        $registry = $this->scanner->registryPosture();
        $checks[] = $registry['ok']
            ? $this->pass('ENT15-DOC-REGISTRY', 'Runbook registry covers every required topic.')
            : $this->fail('ENT15-DOC-REGISTRY', 'Runbook registry posture failed: '.implode('; ', $registry['issues']).'.');

        $files = $this->scanner->runbookFilesPosture();
        $checks[] = $files['ok']
            ? $this->pass('ENT15-DOC-FILES', 'Every mandatory runbook file exists.')
            : $this->fail('ENT15-DOC-FILES', 'Runbook file posture failed: '.implode('; ', $files['issues']).'.');

        $sections = $this->scanner->requiredSectionsPosture();
        $checks[] = $sections['ok']
            ? $this->pass('ENT15-DOC-SECTIONS', 'Every runbook carries the required actionable sections.')
            : $this->fail('ENT15-DOC-SECTIONS', 'Runbook section posture failed: '.implode('; ', $sections['issues']).'.');

        $forbidden = $this->scanner->forbiddenCommandDeclarationPosture();
        $checks[] = $forbidden['ok']
            ? $this->pass('ENT15-DOC-FORBIDDEN', 'Runbooks document forbidden commands and reference required foundation commands.')
            : $this->fail('ENT15-DOC-FORBIDDEN', 'Forbidden-command declaration posture failed: '.implode('; ', $forbidden['issues']).'.');

        $safety = $this->scanner->destructiveCommandSafetyPosture();
        $checks[] = $safety['ok']
            ? $this->pass('ENT15-DOC-SAFETY', 'No destructive command appears outside a forbidden/warning section.')
            : $this->fail('ENT15-DOC-SAFETY', 'Destructive-command safety posture failed: '.implode('; ', $safety['issues']).'.');

        $sensitive = $this->scanner->sensitiveContentPosture();
        $checks[] = $sensitive['ok']
            ? $this->pass('ENT15-DOC-SENSITIVE', 'No runbook or governance doc leaks a secret/PII pattern.')
            : $this->fail('ENT15-DOC-SENSITIVE', 'Sensitive-content posture failed: '.implode('; ', $sensitive['issues']).'.');

        $linkage = $this->scanner->foundationCommandLinkagePosture();
        $checks[] = $linkage['ok']
            ? $this->pass('ENT15-DOC-LINKAGE', 'Runbook set references every required foundation command.')
            : $this->fail('ENT15-DOC-LINKAGE', 'Foundation command linkage failed: '.implode('; ', $linkage['issues']).'.');

        $summary = $this->scanner->summaryCommandPosture();
        $checks[] = $summary['ok']
            ? $this->pass('ENT15-DOC-SUMMARY', 'Runbook summary command (docs:enterprise-runbook-summary) is registered.')
            : $this->fail('ENT15-DOC-SUMMARY', 'Summary command posture failed: '.implode('; ', $summary['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT15-DOC-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-15 artifact and ENT-12..14 siblings.')
            : $this->fail('ENT15-DOC-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT15-DOC-RELEASE-SAFETY', 'Release-safety includes the ENT-15 pre-deploy gate.')
            : $this->fail('ENT15-DOC-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $cicdEnterpriseGate = $this->cicdEnterpriseGateGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT10-CICD-ENTERPRISE-GATE', (string) ($cicdEnterpriseGate['decision'] ?? 'FAIL'), 'ENT-10 CI/CD enterprise gate governance is GO.');

        $deploymentRollback = $this->deploymentRollbackGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT11-DEPLOYMENT-ROLLBACK', (string) ($deploymentRollback['decision'] ?? 'FAIL'), 'ENT-11 deployment/rollback governance is GO.');

        $backupDr = $this->backupDrGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT12-BACKUP-DR', (string) ($backupDr['decision'] ?? 'FAIL'), 'ENT-12 backup/DR governance is GO.');

        $loadTestBaseline = $this->loadTestBaselineGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT13-LOAD-TEST-BASELINE', (string) ($loadTestBaseline['decision'] ?? 'FAIL'), 'ENT-13 load-test baseline governance is GO.');

        $loadTestScaleProjection = $this->loadTestScaleProjectionGovernance->collect();
        $checks[] = $this->decisionCheck('ENT15-DOC-ENT14-LOAD-TEST-SCALE-PROJECTION', (string) ($loadTestScaleProjection['decision'] ?? 'FAIL'), 'ENT-14 load-test scale projection governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-15',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'enterprise_documentation_ready' : strtolower($decision),
            'enterprise_documentation_enabled' => $enabled,
            'registry_ok' => $registry['ok'],
            'runbook_count' => $registry['runbook_count'] ?? 0,
            'covered_topics' => $registry['covered_topics'] ?? [],
            'runbook_files_ok' => $files['ok'],
            'required_sections_ok' => $sections['ok'],
            'forbidden_declaration_ok' => $forbidden['ok'],
            'destructive_safety_ok' => $safety['ok'],
            'sensitive_content_ok' => $sensitive['ok'],
            'foundation_linkage_ok' => $linkage['ok'],
            'summary_command_ok' => $summary['ok'],
            'summary_command_registered' => $summary['registered'] ?? false,
            'evidence_profiles_ok' => $evidence['ok'],
            'evidence_artifact' => $evidence['artifact'],
            'release_safety_ok' => $releaseSafety['ok'],
            'pre_deploy_gate_ok' => $releaseSafety['pre_deploy_gate_ok'] ?? false,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'health_check_decision' => $healthCheck['decision'] ?? 'UNKNOWN',
            'security_compliance_decision' => $securityCompliance['decision'] ?? 'UNKNOWN',
            'cicd_enterprise_gate_decision' => $cicdEnterpriseGate['decision'] ?? 'UNKNOWN',
            'deployment_rollback_decision' => $deploymentRollback['decision'] ?? 'UNKNOWN',
            'backup_dr_decision' => $backupDr['decision'] ?? 'UNKNOWN',
            'load_test_baseline_decision' => $loadTestBaseline['decision'] ?? 'UNKNOWN',
            'load_test_scale_projection_decision' => $loadTestScaleProjection['decision'] ?? 'UNKNOWN',
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
                'foundation:enterprise-documentation-check',
                'docs:enterprise-runbook-summary',
                'release:evidence-check',
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
            default => $this->fail($id, "{$id} is {$decision}; ENT-15 cannot be GO."),
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
