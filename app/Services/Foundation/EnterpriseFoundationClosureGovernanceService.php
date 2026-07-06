<?php

namespace App\Services\Foundation;

use App\Support\Foundation\EnterpriseFoundationClosureScanner;

/**
 * ENT-16 — Enterprise Foundation Closure GO/NO-GO governance layer.
 *
 * The closure gate that ends the enterprise foundation sequence. It aggregates
 * every mandatory ENT-5..15 foundation governance decision, the ENT-1..ENT-16
 * roadmap completion + GO-evidence state, the closure evidence/safety/CI wiring,
 * the operational scripts + runbooks, and the final closure tag declaration, and
 * evaluates the 13 canonical closure criteria (freeze-rules §21) into an explicit
 * GO / WATCH / NO-GO decision.
 *
 * It NEVER weakens a sibling gate — it only reads them. It is informational in
 * the foundation governance summary (published as `enterprise_foundation_closure_governance`,
 * not wired into the blocking combinedDecision) and kept as a separate service
 * from every sibling governance service so their contracts stay intact.
 */
class EnterpriseFoundationClosureGovernanceService
{
    public function __construct(
        private readonly EnterpriseFoundationClosureScanner $scanner,
        private readonly EnterpriseDocumentationGovernanceService $enterpriseDocumentationGovernance,
        private readonly FoundationRoadmapGovernanceService $roadmapGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT16-CLOSE001',
                'title' => 'Closure verifies every mandatory ENT-5..15 foundation gate is GO',
                'description' => 'The closure gate re-runs the queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, deployment-rollback, backup/DR, load-test-baseline, scale-projection, and enterprise-documentation governance. Any non-GO mandatory gate is a NO-GO for closure — closure never declares GO with a foundation gate failing.',
            ],
            [
                'id' => 'ENT16-CLOSE002',
                'title' => 'Every ENT-1..ENT-16 roadmap entry is completed with GO evidence',
                'description' => 'config/foundation_roadmap.php must carry ENT-1..ENT-16 as completed with a non-empty go_tag. A missing entry, a non-completed status, or a missing GO tag is a NO-GO. ENT-16 itself must earn its own GO tag before closure GO.',
            ],
            [
                'id' => 'ENT16-CLOSE003',
                'title' => 'The 13 canonical closure criteria are evaluated with evidence',
                'description' => 'The freeze-rules §21 criteria (architecture, DB performance, cache, queue/idempotency/outbox, observability + developer console, security/PII, CI/CD gate, deploy/rollback, backup/restore rehearsal, load test + scale projection, documentation/runbook, closure evidence pack, final GO tag) each map to a real gate/scanner posture. An unmet criterion is a NO-GO.',
            ],
            [
                'id' => 'ENT16-CLOSE004',
                'title' => 'next_recommended_sprint is not stale after closure',
                'description' => 'With ENT-16 completed, the roadmap next_recommended_sprint must resolve to a still-planned sprint (MON-1) and never remain pinned at a completed ENT-16. A stale next is a WATCH.',
            ],
            [
                'id' => 'ENT16-CLOSE005',
                'title' => 'Closure evidence is required per release profile and pre-deploy gate',
                'description' => 'The ci and vps release-evidence profiles require the enterprise-closure-check.json artifact, foundation:enterprise-closure-check is a release-safety pre-deploy gate, and foundation_governance registers the ENT-16 CI gate — so closure readiness is re-verified before every release.',
            ],
            [
                'id' => 'ENT16-CLOSE006',
                'title' => 'The operational chain scripts and runbooks remain present',
                'description' => 'The deploy, rollback, backup, restore-rehearsal, and load-test scripts plus the mandatory enterprise runbooks must remain present at closure. Closure verifies the chain is intact; it NEVER runs a deploy, backup, restore, or load test.',
            ],
            [
                'id' => 'ENT16-CLOSE007',
                'title' => 'The final closure tag is declared and ends the freeze',
                'description' => 'The final enterprise-foundation-go tag is declared in config and referenced by the freeze rules doc. It is created ONLY on a GO closure decision; until it exists, all application changes stay under the Enterprise Foundation Freeze Rules.',
            ],
            [
                'id' => 'ENT16-CLOSE008',
                'title' => 'Closure evidence and docs are non-sensitive',
                'description' => 'No closure doc or evidence artifact contains a secret/credential/environment value or an unmasked KTP/NIK. Every closure doc passes the release-evidence forbidden-pattern/regex scan. A leaked secret/PII is a NO-GO.',
            ],
            [
                'id' => 'ENT16-CLOSE009',
                'title' => 'Closure never weakens a sibling foundation gate',
                'description' => 'The closure gate is read-only: it aggregates sibling governance decisions and static config/file postures. It never mutates a driver, migration, route, permission, or business workflow, and never relaxes a sibling gate to reach GO.',
            ],
            [
                'id' => 'ENT16-CLOSE010',
                'title' => 'No destructive command drift in the operational chain',
                'description' => 'Closure inherits ENT-10 (deploy no-destructive-command) and ENT-11 (rollback no-destructive-command / no-auto-restore) verification; migrate:fresh, db:wipe, schema:drop, migrate:reset must never be executable in the deploy/rollback/backup path. A destructive drift keeps those gates non-GO and blocks closure.',
            ],
            [
                'id' => 'ENT16-CLOSE011',
                'title' => 'Foundation freeze inheritance is locked for future work',
                'description' => 'After closure GO, all later DaengtisiaMS work inherits ENT-5..16: queue retry/idempotency, developer console read-only + audit, health endpoints minimal/non-sensitive, PII/KTP/NIK masking, CI/CD + release-evidence gates, backup-first deploy, rollback without auto data restore, restore rehearsal non-production only, load test non-production only, scale projection modeled/estimated, and mandatory docs/runbooks. Queue worker stays not-enabled unless a later approved sprint rolls it out.',
            ],
            [
                'id' => 'ENT16-CLOSE012',
                'title' => 'WATCH is only allowed for explicitly documented, non-blocking conditions',
                'description' => 'A WATCH closure decision is permitted only for a configured allowed_watch_condition (e.g. closure deferred with a named blocking ENT sprint). Under --strict / --fail-on-warning any WATCH blocks. A NO-GO is never downgraded to WATCH.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $enterpriseDocumentationReport  Pre-computed
     *                                                                    ENT-15 report to reuse (avoids re-running the ENT-5..15 governance
     *                                                                    cascade a second time when called from the foundation summary).
     * @return array<string, mixed>
     */
    public function collect(?array $enterpriseDocumentationReport = null): array
    {
        $checks = [];

        $enabled = (bool) config('enterprise_foundation_closure.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT16-CLOSE-ENABLED', 'Enterprise foundation closure governance is enabled.')
            : $this->warn('ENT16-CLOSE-ENABLED', 'Enterprise foundation closure governance is disabled by configuration.');

        // --- Mandatory ENT-5..15 gate decisions ---------------------------------
        // The ENT-15 documentation governance report already embeds every
        // ENT-5..14 sub-gate decision plus its own (ENT-15) decision, so a single
        // collect() resolves all 11 mandatory gates without re-running each
        // governance cascade independently (keeps the closure gate cheap).
        $doc = $enterpriseDocumentationReport ?? $this->enterpriseDocumentationGovernance->collect();
        $gateDecisions = [
            'ENT-5' => (string) ($doc['queue_retry_decision'] ?? 'FAIL'),
            'ENT-6' => (string) ($doc['idempotency_outbox_decision'] ?? 'FAIL'),
            'ENT-7' => (string) ($doc['developer_console_decision'] ?? 'FAIL'),
            'ENT-8' => (string) ($doc['health_check_decision'] ?? 'FAIL'),
            'ENT-9' => (string) ($doc['security_compliance_decision'] ?? 'FAIL'),
            'ENT-10' => (string) ($doc['cicd_enterprise_gate_decision'] ?? 'FAIL'),
            'ENT-11' => (string) ($doc['deployment_rollback_decision'] ?? 'FAIL'),
            'ENT-12' => (string) ($doc['backup_dr_decision'] ?? 'FAIL'),
            'ENT-13' => (string) ($doc['load_test_baseline_decision'] ?? 'FAIL'),
            'ENT-14' => (string) ($doc['load_test_scale_projection_decision'] ?? 'FAIL'),
            'ENT-15' => (string) ($doc['decision'] ?? 'FAIL'),
        ];

        foreach ($gateDecisions as $id => $decision) {
            $checks[] = $this->decisionCheck("ENT16-CLOSE-GATE-{$id}", $decision, "{$id} foundation gate is GO.");
        }

        // --- Roadmap posture ----------------------------------------------------
        $roadmap = $this->scanner->roadmapPosture();
        $checks[] = $roadmap['ok']
            ? $this->pass('ENT16-CLOSE-ROADMAP', 'ENT-1..ENT-16 are completed with GO evidence.')
            : $this->fail('ENT16-CLOSE-ROADMAP', 'Roadmap completion posture failed: '.implode('; ', $roadmap['issues']).'.');

        $gatesConfig = $this->scanner->mandatoryGatesConfigPosture();
        $checks[] = $gatesConfig['ok']
            ? $this->pass('ENT16-CLOSE-GATES-CONFIG', 'Every mandatory gate declares section/command/tag and is registered.')
            : $this->fail('ENT16-CLOSE-GATES-CONFIG', 'Mandatory gate config posture failed: '.implode('; ', $gatesConfig['issues']).'.');

        $roadmapGovernance = $this->roadmapGovernance->collect();
        $staleNext = (bool) ($roadmapGovernance['stale_next_detected'] ?? false);
        $checks[] = $staleNext
            ? $this->warn('ENT16-CLOSE-NEXT-SPRINT', 'next_recommended_sprint is stale (points at a completed sprint).')
            : $this->pass('ENT16-CLOSE-NEXT-SPRINT', 'next_recommended_sprint is not stale ('.(string) ($roadmapGovernance['next_recommended_sprint'] ?? 'n/a').').');

        // --- Evidence / safety / CI wiring --------------------------------------
        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT16-CLOSE-EVIDENCE', 'Release-evidence ci/vps profiles require the closure artifact.')
            : $this->fail('ENT16-CLOSE-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT16-CLOSE-RELEASE-SAFETY', 'Release-safety includes the closure pre-deploy gate.')
            : $this->fail('ENT16-CLOSE-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $ciGate = $this->scanner->ciGateRegistryPosture();
        $checks[] = $ciGate['ok']
            ? $this->pass('ENT16-CLOSE-CI-GATE', 'foundation_governance registers the ENT-16 CI evidence gate.')
            : $this->fail('ENT16-CLOSE-CI-GATE', 'CI-gate registry posture failed: '.implode('; ', $ciGate['issues']).'.');

        // --- Operational chain --------------------------------------------------
        $scripts = $this->scanner->scriptsPosture();
        $checks[] = $scripts['ok']
            ? $this->pass('ENT16-CLOSE-SCRIPTS', 'Deploy/rollback/backup/restore/load-test scripts are present.')
            : $this->fail('ENT16-CLOSE-SCRIPTS', 'Operational script posture failed: '.implode('; ', $scripts['issues']).'.');

        $runbooks = $this->scanner->runbooksPosture();
        $checks[] = $runbooks['ok']
            ? $this->pass('ENT16-CLOSE-RUNBOOKS', 'Mandatory enterprise runbooks are present.')
            : $this->fail('ENT16-CLOSE-RUNBOOKS', 'Runbook posture failed: '.implode('; ', $runbooks['issues']).'.');

        $closureDocs = $this->scanner->closureDocsPosture();
        $checks[] = $closureDocs['ok']
            ? $this->pass('ENT16-CLOSE-DOCS', 'Closure policy + runbook docs are present.')
            : $this->fail('ENT16-CLOSE-DOCS', 'Closure doc posture failed: '.implode('; ', $closureDocs['issues']).'.');

        // --- Final tag + non-sensitivity ----------------------------------------
        $finalTag = $this->scanner->finalClosureTagPosture();
        $checks[] = $finalTag['ok']
            ? $this->pass('ENT16-CLOSE-FINAL-TAG', 'Final closure tag ('.$finalTag['final_closure_tag'].') is declared and referenced.')
            : $this->fail('ENT16-CLOSE-FINAL-TAG', 'Final closure tag posture failed: '.implode('; ', $finalTag['issues']).'.');

        $sensitive = $this->scanner->sensitiveContentPosture();
        $checks[] = $sensitive['ok']
            ? $this->pass('ENT16-CLOSE-SENSITIVE', 'No closure doc leaks a secret/PII pattern.')
            : $this->fail('ENT16-CLOSE-SENSITIVE', 'Sensitive-content posture failed: '.implode('; ', $sensitive['issues']).'.');

        // --- The 13 canonical closure criteria ----------------------------------
        $criteria = $this->evaluateClosureCriteria($gateDecisions, $roadmap, $evidence, $releaseSafety, $ciGate, $finalTag);
        foreach ($criteria as $criterion) {
            $checks[] = $criterion['met']
                ? $this->pass('ENT16-CRIT-'.$criterion['number'], "Closure criterion {$criterion['number']} met: {$criterion['title']}.")
                : $this->fail('ENT16-CRIT-'.$criterion['number'], "Closure criterion {$criterion['number']} NOT met: {$criterion['title']}.");
        }

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'NO-GO' : ($warnings > 0 ? 'WATCH' : 'GO');

        $criteriaMet = count(array_filter($criteria, fn (array $c) => $c['met']));

        return [
            'sprint' => 'ENT-16',
            'decision' => $decision,
            'closure_decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'enterprise_foundation_closure_ready' : strtolower(str_replace('-', '_', $decision)),
            'enterprise_foundation_closure_enabled' => $enabled,
            'final_closure_tag' => (string) config('enterprise_foundation_closure.final_closure_tag', ''),
            'mandatory_gate_decisions' => $gateDecisions,
            'roadmap_ok' => $roadmap['ok'],
            'roadmap_completed_count' => $roadmap['completed_count'] ?? 0,
            'mandatory_gates_config_ok' => $gatesConfig['ok'],
            'next_recommended_sprint' => $roadmapGovernance['next_recommended_sprint'] ?? null,
            'stale_next_detected' => $staleNext,
            'evidence_profiles_ok' => $evidence['ok'],
            'evidence_artifact' => $evidence['artifact'],
            'release_safety_ok' => $releaseSafety['ok'],
            'ci_gate_registry_ok' => $ciGate['ok'],
            'scripts_ok' => $scripts['ok'],
            'runbooks_ok' => $runbooks['ok'],
            'closure_docs_ok' => $closureDocs['ok'],
            'final_tag_ok' => $finalTag['ok'],
            'sensitive_content_ok' => $sensitive['ok'],
            'closure_criteria' => $criteria,
            'closure_criteria_total' => count($criteria),
            'closure_criteria_met' => $criteriaMet,
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
                'foundation:enterprise-closure-check',
                'architecture:foundation-governance-summary',
                'foundation:roadmap-check',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * Evaluate the 13 canonical closure criteria (freeze-rules §21) against the
     * live gate decisions and scanner postures.
     *
     * @param  array<string, string>  $gateDecisions
     * @param  array<string, mixed>  $roadmap
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $releaseSafety
     * @param  array<string, mixed>  $ciGate
     * @param  array<string, mixed>  $finalTag
     * @return list<array{number: int, key: string, title: string, met: bool}>
     */
    private function evaluateClosureCriteria(array $gateDecisions, array $roadmap, array $evidence, array $releaseSafety, array $ciGate, array $finalTag): array
    {
        $byId = collect(config('foundation_roadmap.approved_sequence', []))->keyBy('id');
        $completed = fn (string $id): bool => (string) ($byId->get($id)['status'] ?? '') === 'completed';
        $go = fn (string $id): bool => ($gateDecisions[$id] ?? 'FAIL') === 'GO';

        $satisfied = [
            'architecture_governance' => $completed('ENT-1'),
            'database_performance_baseline' => $completed('ENT-2'),
            'cache_governance' => $completed('ENT-4'),
            'queue_idempotency_outbox' => $go('ENT-5') && $go('ENT-6'),
            'observability_developer_console' => $go('ENT-7') && $go('ENT-8'),
            'security_pii' => $go('ENT-9'),
            'cicd_gate' => $go('ENT-10'),
            'deploy_rollback' => $go('ENT-11'),
            'backup_restore_rehearsal' => $go('ENT-12'),
            'load_test_scale_projection' => $go('ENT-13') && $go('ENT-14'),
            'documentation_runbook' => $go('ENT-15'),
            'closure_evidence_pack' => $evidence['ok'] && $releaseSafety['ok'] && $ciGate['ok'],
            'final_closure_tag_declared' => $finalTag['ok'],
        ];

        $result = [];
        foreach ((array) config('enterprise_foundation_closure.closure_criteria', []) as $number => $criterion) {
            $key = (string) ($criterion['key'] ?? '');
            $result[] = [
                'number' => (int) $number,
                'key' => $key,
                'title' => (string) ($criterion['title'] ?? ''),
                'met' => (bool) ($satisfied[$key] ?? false),
            ];
        }

        return $result;
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict closure should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; closure cannot be GO."),
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
