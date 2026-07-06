<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\EnterpriseFoundationClosureGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Support\Foundation\EnterpriseFoundationClosureScanner;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'EnterpriseFoundationClosure', 'ClosureGoNoGo');

it('ships the enterprise foundation closure governance doc with all ENT16 rules', function () {
    $path = base_path('docs/architecture/enterprise-foundation-closure-go-no-go.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT16-CLOSE%03d', $n));
    }

    expect($doc)->toContain('foundation:enterprise-closure-check')
        ->and($doc)->toContain('enterprise_foundation_closure_governance')
        ->and($doc)->toContain('enterprise-closure-check.json')
        ->and($doc)->toContain('enterprise-foundation-go')
        ->and($doc)->toContain('MON-1');
});

it('keeps the ENT-16 governance docs and runbook free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/enterprise-foundation-closure-go-no-go.md'),
        base_path('docs/sprints/ent-16-enterprise-foundation-closure-go-no-go.md'),
        base_path('docs/runbooks/enterprise-foundation-closure-runbook.md'),
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

it('references ENT-16 closure governance from durable docs, cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/65-enterprise-foundation-closure-go-no-go.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('enterprise-foundation-closure-go-no-go.md')
        ->and($freeze)->toContain('foundation:enterprise-closure-check')
        ->and($cursor)->toContain('foundation:enterprise-closure-check --strict')
        ->and($claude)->toContain('ENT-16 — Enterprise Foundation Closure GO/NO-GO')
        ->and($claude)->toContain('enterprise_foundation_closure_governance');
});

it('registers the ENT-16 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.enterprise_foundation_closure_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.enterprise_foundation_closure_governance_doc'))->toBe('docs/architecture/enterprise-foundation-closure-go-no-go.md')
        ->and(config('foundation_roadmap.rules.enterprise_foundation_baseline_locked_ent_5_to_16'))->toBeTrue()
        ->and(config('enterprise_foundation_closure.enabled'))->toBeTrue()
        ->and(config('enterprise_foundation_closure.final_closure_tag'))->toBe('enterprise-foundation-go')
        ->and(config('enterprise_foundation_closure.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('enterprise_foundation_closure.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('enterprise_foundation_closure.evidence.artifact'))->toBe('enterprise-closure-check.json')
        ->and(config('enterprise_foundation_closure.required_completed_roadmap_ids'))->toContain('ENT-1')
        ->and(config('enterprise_foundation_closure.required_completed_roadmap_ids'))->toContain('ENT-16')
        ->and(config('enterprise_foundation_closure.closure_criteria'))->toHaveCount(13);
});

it('registers ENT-16 completed and moves next recommended sprint to MON-1', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent16 = $sequence->firstWhere('id', 'ENT-16');

    expect($ent16)->not->toBeNull()
        ->and($ent16['status'])->toBe('completed')
        ->and($ent16['category'])->toBe('governance')
        ->and($ent16['governance_section'])->toBe('enterprise_foundation_closure_governance')
        ->and($ent16['readiness_command'])->toBe('foundation:enterprise-closure-check')
        ->and($ent16['policy_doc'])->toBe('docs/architecture/enterprise-foundation-closure-go-no-go.md')
        ->and($ent16['go_tag'])->toBe('ent-16-enterprise-foundation-closure-go-no-go-go')
        ->and($ent16['final_closure_tag'])->toBe('enterprise-foundation-go');

    $ent15 = $sequence->firstWhere('id', 'ENT-15');
    expect($ent15['deploy_evidence_commit'])->toBe('9c82638');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('closes the ENT sequence without inventing an ENT-17', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    expect($sequence->firstWhere('id', 'ENT-17'))->toBeNull()
        ->and($sequence->firstWhere('id', 'MON-1')['status'])->toBe('planned')
        ->and($sequence->last()['id'])->toBe('RC-1');
});

it('publishes ENT16 rules through the governance service and foundation summary', function () {
    $rules = EnterpriseFoundationClosureGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT16-CLOSE%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('enterprise_foundation_closure_governance')
        ->and($summary['enterprise_foundation_closure_governance']['command'])->toBe('foundation:enterprise-closure-check')
        ->and($summary['enterprise_foundation_closure_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['enterprise_foundation_closure_governance']['rules'], 'id'))->toContain('ENT16-CLOSE001')
        ->and($summary)->toHaveKeys(['enterprise_documentation_governance', 'load_test_scale_projection_governance', 'backup_dr_governance']);
});

it('requires the ENT-16 evidence artifact, pre-deploy gate and CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('enterprise-closure-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('enterprise-closure-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:enterprise-closure-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-16');
});

it('returns a GO closure decision on the default repo state with 13/13 criteria met', function () {
    $report = app(EnterpriseFoundationClosureGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['closure_decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('enterprise_foundation_closure_ready')
        ->and($report['roadmap_ok'])->toBeTrue()
        ->and($report['roadmap_completed_count'])->toBe(16)
        ->and($report['next_recommended_sprint'])->toBe('MON-1')
        ->and($report['stale_next_detected'])->toBeFalse()
        ->and($report['closure_criteria_total'])->toBe(13)
        ->and($report['closure_criteria_met'])->toBe(13)
        ->and($report['scripts_ok'])->toBeTrue()
        ->and($report['runbooks_ok'])->toBeTrue()
        ->and($report['sensitive_content_ok'])->toBeTrue();

    foreach ($report['mandatory_gate_decisions'] as $decision) {
        expect($decision)->toBe('GO');
    }
});

it('is NO-GO when a mandatory prior foundation gate is missing (release-safety gate cleared)', function () {
    config(['release_safety.required_pre_deploy_gates' => []]);

    $report = app(EnterpriseFoundationClosureGovernanceService::class)->collect();

    expect($report['decision'])->toBe('NO-GO')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('is NO-GO when the closure evidence artifact is not declared in a release profile', function () {
    config(['release_evidence.profiles.ci.required_artifacts' => ['foundation-roadmap-check.json']]);

    $report = app(EnterpriseFoundationClosureGovernanceService::class)->collect();

    expect($report['decision'])->toBe('NO-GO')
        ->and($report['evidence_profiles_ok'])->toBeFalse();
});

it('is NO-GO when a required ENT roadmap entry is not completed', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'))->map(function (array $entry) {
        if (($entry['id'] ?? null) === 'ENT-10') {
            $entry['status'] = 'planned';
        }

        return $entry;
    })->all();
    config(['foundation_roadmap.approved_sequence' => $sequence]);

    $report = app(EnterpriseFoundationClosureGovernanceService::class)->collect();

    expect($report['decision'])->toBe('NO-GO')
        ->and($report['roadmap_ok'])->toBeFalse();
});

it('is NO-GO when a mandatory operational script is missing', function () {
    config(['enterprise_foundation_closure.required_scripts.rollback' => 'scripts/does-not-exist.sh']);

    $report = app(EnterpriseFoundationClosureGovernanceService::class)->collect();

    expect($report['decision'])->toBe('NO-GO')
        ->and($report['scripts_ok'])->toBeFalse();
});

it('detects secret/PII-shaped content in closure docs', function () {
    $scanner = app(EnterpriseFoundationClosureScanner::class);

    expect($scanner->scanContentForSensitive('DB_PASSWORD=secret'))->not->toBe([])
        ->and($scanner->scanContentForSensitive('nik 1234567890123456'))->not->toBe([])
        ->and($scanner->scanContentForSensitive('This closure doc is non-sensitive.'))->toBe([]);
});

it('keeps the deploy and CI scripts running the ENT-16 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:enterprise-closure-check')
        ->and($deploy)->toContain('enterprise-closure-check.json')
        ->and($ciScript)->toContain('foundation:enterprise-closure-check')
        ->and($deploy)->not->toContain('migrate:fresh')
        ->and($deploy)->not->toContain('db:wipe');
});

it('foundation roadmap check stays green after the ENT-16 closure lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('the ENT-16 strict closure command and every mandatory ENT-5..15 gate pass on the default repo state', function () {
    expect(Artisan::call('foundation:enterprise-closure-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:enterprise-documentation-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:load-test-scale-projection-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
