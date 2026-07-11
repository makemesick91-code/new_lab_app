<?php

declare(strict_types=1);

namespace App\Services\Foundation;

use App\Support\Devflow\DevflowScanner;
use App\Support\Devflow\SharedFoundationScanner;

/**
 * DEVFLOW-1 — Safe Sprint Acceleration governance.
 *
 * Publishes the DEVFLOW-R00x rules and an informational `devflow_governance`
 * section for architecture:foundation-governance-summary. Read-only; NOT wired
 * into the blocking combinedDecision (mirrors every prior foundation sprint).
 *
 * Decision: FAIL on a broken foundation / destructive marker / weakened
 * CICD-CTRL-1 invariant; WATCH on a missing non-critical marker; GO otherwise.
 */
final class DevflowGovernanceService
{
    public function __construct(
        private readonly DevflowScanner $scanner,
        private readonly SharedFoundationScanner $sharedFoundations,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $files = $this->scanner->filesPosture();
        $checks[] = $this->check(
            'DEVFLOW-FILES',
            $files['ok'] ? 'passed' : 'failed',
            $files['ok'] ? 'All canonical DEVFLOW-1 files present.' : 'Missing: '.implode('; ', $files['missing']),
        );

        $required = $this->scanner->requiredMarkersPosture();
        $checks[] = $this->check(
            'DEVFLOW-MARKERS',
            $required['ok'] ? 'passed' : 'warning',
            $required['ok'] ? 'Required safety markers present.' : 'Missing markers: '.implode('; ', $required['missing_markers']),
        );

        $forbidden = $this->scanner->forbiddenMarkersPosture();
        $checks[] = $this->check(
            'DEVFLOW-NO-DESTRUCTIVE',
            $forbidden['ok'] ? 'passed' : 'failed',
            $forbidden['ok'] ? 'No destructive markers in release wrapper.' : implode('; ', $forbidden['violations']),
        );

        $invariant = $this->scanner->cicdInvariantPosture();
        $checks[] = $this->check(
            'DEVFLOW-CICD-INVARIANT',
            $invariant['ok'] ? 'passed' : 'failed',
            $invariant['ok'] ? 'CICD-CTRL-1 safety invariant intact.' : implode('; ', $invariant['issues']),
        );

        $shared = $this->sharedFoundations->scan();
        $sharedStatus = $shared['decision'] === 'NO-GO' ? 'failed' : ($shared['decision'] === 'WATCH' ? 'warning' : 'passed');
        $checks[] = $this->check(
            'DEVFLOW-SHARED-REGISTRY',
            $sharedStatus,
            "Shared foundation registry decision: {$shared['decision']} ({$shared['summary']['errors']} errors, {$shared['summary']['warnings']} warnings).",
        );

        $passed = count(array_filter($checks, static fn ($c) => $c['status'] === 'passed'));
        $warnings = count(array_filter($checks, static fn ($c) => $c['status'] === 'warning'));
        $errors = count(array_filter($checks, static fn ($c) => $c['status'] === 'failed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'DEVFLOW-1',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'devflow_ready' : ($decision === 'WATCH' ? 'devflow_watch' : 'devflow_blocked'),
            'checks' => $checks,
            'summary' => [
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'decision' => $decision,
            ],
            'rules' => self::rules(),
            'commands' => [
                'foundation:devflow-check',
                'foundation:shared-service-audit',
                'sprint:prepare', 'sprint:manifest-check', 'sprint:audit-plan',
                'sprint:test-plan', 'sprint:test', 'sprint:scope-audit',
                'sprint:release-check', 'sprint:evidence', 'sprint:new',
            ],
            'privacy' => ['privacy_safe' => true],
        ];
    }

    /**
     * @return list<array{id:string,title:string,description:string}>
     */
    public static function rules(): array
    {
        return [
            ['id' => 'DEVFLOW-R001', 'title' => 'Manifest is the source of truth', 'description' => 'Every sprint carries a validated manifest; tooling and CI derive audit depth, tests, and release requirements from it.'],
            ['id' => 'DEVFLOW-R002', 'title' => 'Fail closed by default', 'description' => 'Unresolved diff, unmatched file, or unknown type escalates to the full required suite; no silent skip.'],
            ['id' => 'DEVFLOW-R003', 'title' => 'Impact-based focused testing', 'description' => 'sprint:test-plan resolves tests from the regression matrix + git diff; escalation categories force the full suite.'],
            ['id' => 'DEVFLOW-R004', 'title' => 'CICD-CTRL-1 never weakened', 'description' => 'docs_only stays the only critical-skipping profile; default profile stays unknown_high_risk.'],
            ['id' => 'DEVFLOW-R005', 'title' => 'Release wrapper is dry-run first', 'description' => 'Any mutation requires an explicit --apply; the wrapper reuses the existing deploy/backup/rollback runners.'],
            ['id' => 'DEVFLOW-R006', 'title' => 'No destructive command in tooling', 'description' => 'migrate:fresh, db:wipe, and force-push are forbidden across the release path (config-declared, scanned).'],
            ['id' => 'DEVFLOW-R007', 'title' => 'GO tag only after deploy + smoke', 'description' => 'sprint:release-check never creates a tag; a GO tag is applied only after a real deploy and smoke.'],
            ['id' => 'DEVFLOW-R008', 'title' => 'Evidence from real results', 'description' => 'sprint:evidence renders actual command output; missing values are marked NOT AVAILABLE, never invented.'],
            ['id' => 'DEVFLOW-R009', 'title' => 'Shared foundations are reused, not duplicated', 'description' => 'Canonical services in shared_foundations.php must be reused; the audit blocks a broken canonical class.'],
            ['id' => 'DEVFLOW-R010', 'title' => 'No arbitrary shell from a manifest', 'description' => 'Manifests select known profiles/commands only; tooling never executes a command string from a manifest.'],
        ];
    }

    /**
     * @return array{check_id:string,status:string,message:string}
     */
    private function check(string $id, string $status, string $message): array
    {
        return ['check_id' => $id, 'status' => $status, 'message' => $message];
    }
}
