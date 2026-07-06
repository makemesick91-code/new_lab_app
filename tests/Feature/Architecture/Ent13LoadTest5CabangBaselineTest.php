<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\LoadTestBaselineGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'LoadTestBaseline');

it('ships the load test 5 cabang baseline governance doc with all ENT13 rules', function () {
    $path = base_path('docs/architecture/load-test-5-cabang-baseline-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT13-LT%03d', $n));
    }

    expect($doc)->toContain('foundation:load-test-baseline-check')
        ->and($doc)->toContain('load_test_baseline_governance')
        ->and($doc)->toContain('scripts/load-test-baseline.sh')
        ->and($doc)->toContain('loadtest:baseline-run')
        ->and($doc)->toContain('load-test-baseline-check.json')
        ->and($doc)->toContain('200-300ms')
        ->and($doc)->toContain('stress:seed-');
});

it('keeps the ENT-13 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/load-test-5-cabang-baseline-governance.md'),
        base_path('docs/sprints/ent-13-load-test-5-cabang-baseline.md'),
    ];

    foreach ($paths as $path) {
        $doc = file_get_contents($path);

        foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
            expect(str_contains($doc, $pattern))->toBeFalse(
                basename($path)." must not contain forbidden release-evidence literal: {$pattern}"
            );
        }

        foreach (config('release_evidence.forbidden_regex', []) as $regex) {
            expect(preg_match($regex, $doc))->toBe(0);
        }
    }
});

it('references ENT-13 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/62-load-test-5-cabang-baseline.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('load-test-5-cabang-baseline-governance.md')
        ->and($freeze)->toContain('foundation:load-test-baseline-check')
        ->and($cursor)->toContain('scripts/load-test-baseline.sh')
        ->and($cursor)->toContain('foundation:load-test-baseline-check --strict')
        ->and($claude)->toContain('ENT-13 — Load Test 5 Cabang Baseline')
        ->and($claude)->toContain('load_test_baseline_governance');
});

it('registers the ENT-13 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.load_test_baseline_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.load_test_baseline_governance_doc'))->toBe('docs/architecture/load-test-5-cabang-baseline-governance.md')
        ->and(config('load_test_baseline.enabled'))->toBeTrue()
        ->and(config('load_test_baseline.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('load_test_baseline.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('load_test_baseline.evidence.artifact'))->toBe('load-test-baseline-check.json')
        ->and(config('load_test_baseline.branch_count'))->toBe(5)
        ->and(config('load_test_baseline.latency_targets.p50_target_ms'))->toBeGreaterThan(0)
        ->and(config('load_test_baseline.latency_targets.p95_target_ms'))->toBeGreaterThan(0);
});

it('registers ENT-13 completed and moves next recommended sprint to ENT-14', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent13 = $sequence->firstWhere('id', 'ENT-13');

    expect($ent13)->not->toBeNull()
        ->and($ent13['status'])->toBe('completed')
        ->and($ent13['category'])->toBe('performance')
        ->and($ent13['governance_section'])->toBe('load_test_baseline_governance')
        ->and($ent13['readiness_command'])->toBe('foundation:load-test-baseline-check')
        ->and($ent13['policy_doc'])->toBe('docs/architecture/load-test-5-cabang-baseline-governance.md')
        ->and($ent13['go_tag'])->toBe('ent-13-load-test-5-cabang-baseline-go')
        ->and($ent13['related_shipped_foundations'])->toContain('DBPERF-1');

    $ent12 = $sequence->firstWhere('id', 'ENT-12');
    expect($ent12['deploy_evidence_commit'])->toBe('c8fad61');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-15 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $entry = $sequence->firstWhere('id', 'MON-1');
    expect($entry)->not->toBeNull()
        ->and($entry['status'])->toBe('planned');
});

it('publishes ENT13 rules through the governance service and foundation summary', function () {
    $rules = LoadTestBaselineGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT13-LT%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('load_test_baseline_governance')
        ->and($summary['load_test_baseline_governance']['command'])->toBe('foundation:load-test-baseline-check')
        ->and($summary['load_test_baseline_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['load_test_baseline_governance']['rules'], 'id'))->toContain('ENT13-LT001')
        ->and($summary)->toHaveKeys(['backup_dr_governance', 'deployment_rollback_governance', 'cicd_enterprise_gate_governance']);
});

it('requires the ENT-13 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('load-test-baseline-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('load-test-baseline-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:load-test-baseline-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-13');
});

it('ships a safe non-production load-test harness that carries no destructive command', function () {
    $path = base_path('scripts/load-test-baseline.sh');
    expect(file_exists($path))->toBeTrue();

    $harness = file_get_contents($path);

    expect($harness)->toContain('set -euo pipefail')
        ->and($harness)->toContain('must not run against production')
        ->and($harness)->toContain('php artisan loadtest:baseline-run')
        ->and($harness)->toContain('storage/app/load-test');

    foreach (config('load_test_baseline.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($harness, (string) $pattern))->toBeFalse(
            "load-test harness must not contain destructive pattern: {$pattern}"
        );
    }
});

it('keeps the deploy and CI scripts running the ENT-13 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:load-test-baseline-check')
        ->and($deploy)->toContain('load-test-baseline-check.json')
        ->and($ciScript)->toContain('foundation:load-test-baseline-check');
});

it('foundation roadmap check stays green after the ENT-13 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-13 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
