<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\DeploymentRollbackGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'DeploymentRollback');

it('ships the deployment & rollback automation governance doc with all ENT11 rules', function () {
    $path = base_path('docs/architecture/deployment-rollback-automation-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT11-DR%03d', $n));
    }

    expect($doc)->toContain('foundation:deployment-rollback-check')
        ->and($doc)->toContain('deployment_rollback_governance')
        ->and($doc)->toContain('scripts/rollback-vps.sh')
        ->and($doc)->toContain('migrate --force')
        ->and($doc)->toContain('deployment-rollback-check.json');
});

it('keeps the ENT-11 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/deployment-rollback-automation-governance.md'),
        base_path('docs/sprints/ent-11-deployment-rollback-automation.md'),
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

it('references ENT-11 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/60-deployment-rollback-automation.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('deployment-rollback-automation-governance.md')
        ->and($freeze)->toContain('foundation:deployment-rollback-check')
        ->and($cursor)->toContain('scripts/rollback-vps.sh')
        ->and($cursor)->toContain('foundation:deployment-rollback-check --strict')
        ->and($claude)->toContain('ENT-11 — Deployment & Rollback Automation')
        ->and($claude)->toContain('deployment_rollback_governance');
});

it('registers the ENT-11 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.deployment_rollback_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.deployment_rollback_governance_doc'))->toBe('docs/architecture/deployment-rollback-automation-governance.md')
        ->and(config('deployment_rollback.enabled'))->toBeTrue()
        ->and(config('deployment_rollback.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('deployment_rollback.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('deployment_rollback.evidence.artifact'))->toBe('deployment-rollback-check.json');
});

it('registers ENT-11 completed and moves next recommended sprint to ENT-12', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent11 = $sequence->firstWhere('id', 'ENT-11');

    expect($ent11)->not->toBeNull()
        ->and($ent11['status'])->toBe('completed')
        ->and($ent11['category'])->toBe('release_safety')
        ->and($ent11['governance_section'])->toBe('deployment_rollback_governance')
        ->and($ent11['readiness_command'])->toBe('foundation:deployment-rollback-check')
        ->and($ent11['policy_doc'])->toBe('docs/architecture/deployment-rollback-automation-governance.md')
        ->and($ent11['go_tag'])->toBe('ent-11-deployment-rollback-automation-go')
        ->and($ent11['related_shipped_foundations'])->toContain('NSF-10');

    $ent10 = $sequence->firstWhere('id', 'ENT-10');
    expect($ent10['deploy_evidence_commit'])->toBe('858f457');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-15')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-15 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(15, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned');
    }
});

it('publishes ENT11 rules through the governance service and foundation summary', function () {
    $rules = DeploymentRollbackGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT11-DR%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('deployment_rollback_governance')
        ->and($summary['deployment_rollback_governance']['command'])->toBe('foundation:deployment-rollback-check')
        ->and($summary['deployment_rollback_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['deployment_rollback_governance']['rules'], 'id'))->toContain('ENT11-DR001')
        ->and($summary)->toHaveKeys(['cicd_enterprise_gate_governance', 'security_compliance_governance', 'health_check_governance']);
});

it('requires the ENT-11 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('deployment-rollback-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('deployment-rollback-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:deployment-rollback-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-11');
});

it('ships a safe, fail-fast rollback script that backs up before checkout and re-verifies gates', function () {
    $path = base_path('scripts/rollback-vps.sh');
    expect(file_exists($path))->toBeTrue();

    $rollback = file_get_contents($path);

    expect($rollback)->toContain('set -euo pipefail')
        ->and($rollback)->toContain('git rev-parse HEAD')
        ->and($rollback)->toContain('pg_dump')
        ->and($rollback)->toContain('git checkout')
        ->and($rollback)->toContain('php artisan foundation:deployment-rollback-check')
        ->and($rollback)->toContain('php artisan release:automated-smoke');

    // Backup must appear before the rollback checkout.
    expect(strpos($rollback, 'pg_dump'))->toBeLessThan(strpos($rollback, 'git checkout'));

    foreach (config('deployment_rollback.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($rollback, (string) $pattern))->toBeFalse(
            "rollback script must not contain destructive pattern: {$pattern}"
        );
    }
});

it('keeps the deploy and CI scripts running the ENT-11 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:deployment-rollback-check')
        ->and($deploy)->toContain('deployment-rollback-check.json')
        ->and($ciScript)->toContain('foundation:deployment-rollback-check');

    foreach (config('deployment_rollback.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($deploy, (string) $pattern))->toBeFalse(
            "deploy script must not contain destructive pattern: {$pattern}"
        );
    }
});

it('foundation roadmap check stays green after the ENT-11 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-11 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
