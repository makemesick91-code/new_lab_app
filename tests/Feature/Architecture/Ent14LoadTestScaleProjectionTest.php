<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\LoadTestScaleProjectionGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'LoadTestScaleProjection');

it('ships the load test scale projection governance doc with all ENT14 rules', function () {
    $path = base_path('docs/architecture/load-test-scale-projection-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT14-SP%03d', $n));
    }

    expect($doc)->toContain('foundation:load-test-scale-projection-check')
        ->and($doc)->toContain('load_test_scale_projection_governance')
        ->and($doc)->toContain('scripts/load-test-scale-projection.sh')
        ->and($doc)->toContain('loadtest:scale-projection-run')
        ->and($doc)->toContain('load-test-scale-projection-check.json')
        ->and($doc)->toContain('modeled')
        ->and($doc)->toContain('LB-1')
        ->and($doc)->toContain('REPLICA-1');
});

it('keeps the ENT-14 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/load-test-scale-projection-governance.md'),
        base_path('docs/sprints/ent-14-load-test-scale-projection.md'),
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

it('references ENT-14 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/63-load-test-scale-projection.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('load-test-scale-projection-governance.md')
        ->and($freeze)->toContain('foundation:load-test-scale-projection-check')
        ->and($cursor)->toContain('scripts/load-test-scale-projection.sh')
        ->and($cursor)->toContain('foundation:load-test-scale-projection-check --strict')
        ->and($claude)->toContain('ENT-14 — Load Test Scale Projection')
        ->and($claude)->toContain('load_test_scale_projection_governance');
});

it('registers the ENT-14 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.load_test_scale_projection_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.load_test_scale_projection_governance_doc'))->toBe('docs/architecture/load-test-scale-projection-governance.md')
        ->and(config('load_test_scale_projection.enabled'))->toBeTrue()
        ->and(config('load_test_scale_projection.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('load_test_scale_projection.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('load_test_scale_projection.evidence.artifact'))->toBe('load-test-scale-projection-check.json')
        ->and(config('load_test_scale_projection.baseline_source'))->toBe('load_test_baseline')
        ->and(config('load_test_scale_projection.related_shipped_foundations'))->toContain('LB-1')
        ->and(config('load_test_scale_projection.related_shipped_foundations'))->toContain('REPLICA-1')
        ->and(config('load_test_scale_projection.required_tier_branch_counts'))->toContain(20);
});

it('registers ENT-14 completed and moves next recommended sprint to ENT-15', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent14 = $sequence->firstWhere('id', 'ENT-14');

    expect($ent14)->not->toBeNull()
        ->and($ent14['status'])->toBe('completed')
        ->and($ent14['category'])->toBe('performance')
        ->and($ent14['governance_section'])->toBe('load_test_scale_projection_governance')
        ->and($ent14['readiness_command'])->toBe('foundation:load-test-scale-projection-check')
        ->and($ent14['policy_doc'])->toBe('docs/architecture/load-test-scale-projection-governance.md')
        ->and($ent14['go_tag'])->toBe('ent-14-load-test-scale-projection-go')
        ->and($ent14['related_shipped_foundations'])->toContain('LB-1')
        ->and($ent14['related_shipped_foundations'])->toContain('REPLICA-1');

    $ent13 = $sequence->firstWhere('id', 'ENT-13');
    expect($ent13['deploy_evidence_commit'])->toBe('c7f39f0');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-16 planned until it earns its own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $entry = $sequence->firstWhere('id', 'MON-1');
    expect($entry)->not->toBeNull()
        ->and($entry['status'])->toBe('planned');
});

it('publishes ENT14 rules through the governance service and foundation summary', function () {
    $rules = LoadTestScaleProjectionGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT14-SP%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('load_test_scale_projection_governance')
        ->and($summary['load_test_scale_projection_governance']['command'])->toBe('foundation:load-test-scale-projection-check')
        ->and($summary['load_test_scale_projection_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['load_test_scale_projection_governance']['rules'], 'id'))->toContain('ENT14-SP001')
        ->and($summary)->toHaveKeys(['load_test_baseline_governance', 'backup_dr_governance', 'deployment_rollback_governance']);
});

it('requires the ENT-14 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('load-test-scale-projection-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('load-test-scale-projection-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:load-test-scale-projection-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-14');
});

it('ships a safe non-production scale-projection harness that carries no destructive command', function () {
    $path = base_path('scripts/load-test-scale-projection.sh');
    expect(file_exists($path))->toBeTrue();

    $harness = file_get_contents($path);

    expect($harness)->toContain('set -euo pipefail')
        ->and($harness)->toContain('must not run against production')
        ->and($harness)->toContain('php artisan loadtest:scale-projection-run')
        ->and($harness)->toContain('storage/app/load-test');

    foreach (config('load_test_scale_projection.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($harness, (string) $pattern))->toBeFalse(
            "scale-projection harness must not contain destructive pattern: {$pattern}"
        );
    }
});

it('keeps the deploy and CI scripts running the ENT-14 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:load-test-scale-projection-check')
        ->and($deploy)->toContain('load-test-scale-projection-check.json')
        ->and($ciScript)->toContain('foundation:load-test-scale-projection-check');
});

it('fails ENT-14 governance when the release-safety gate is missing', function () {
    config(['release_safety.required_pre_deploy_gates' => []]);

    $report = app(LoadTestScaleProjectionGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('foundation roadmap check stays green after the ENT-14 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-14 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:load-test-scale-projection-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
