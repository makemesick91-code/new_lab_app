<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\CicdEnterpriseGateGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'Cicd');

it('ships the CI/CD enterprise gate governance doc with all ENT10 rules', function () {
    $path = base_path('docs/architecture/cicd-enterprise-gate-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT10-CICD%03d', $n));
    }

    expect($doc)->toContain('foundation:cicd-enterprise-gate-check')
        ->and($doc)->toContain('cicd_enterprise_gate_governance')
        ->and($doc)->toContain('migrate --force')
        ->and($doc)->toContain('cicd-enterprise-gate-check.json');
});

it('keeps the ENT-10 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/cicd-enterprise-gate-governance.md'),
        base_path('docs/sprints/ent-10-cicd-enterprise-gate.md'),
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

it('references ENT-10 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/59-cicd-enterprise-gate.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('cicd-enterprise-gate-governance.md')
        ->and($freeze)->toContain('foundation:cicd-enterprise-gate-check')
        ->and($cursor)->toContain('migrate --force')
        ->and($cursor)->toContain('foundation:cicd-enterprise-gate-check --strict')
        ->and($claude)->toContain('ENT-10 — CI/CD Enterprise Gate')
        ->and($claude)->toContain('cicd_enterprise_gate_governance');
});

it('registers the ENT-10 governance pointers in roadmap and cicd config', function () {
    expect(config('foundation_roadmap.rules.cicd_enterprise_gate_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.cicd_enterprise_gate_governance_doc'))->toBe('docs/architecture/cicd-enterprise-gate-governance.md')
        ->and(config('cicd_enterprise_gate.enabled'))->toBeTrue()
        ->and(config('cicd_enterprise_gate.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('cicd_enterprise_gate.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('cicd_enterprise_gate.required_foundation_commands'))->toContain('foundation:cicd-enterprise-gate-check');
});

it('registers ENT-10 completed with ENT-12 as the eventual next after ENT-11 ships', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent10 = $sequence->firstWhere('id', 'ENT-10');

    expect($ent10)->not->toBeNull()
        ->and($ent10['status'])->toBe('completed')
        ->and($ent10['category'])->toBe('release_safety')
        ->and($ent10['governance_section'])->toBe('cicd_enterprise_gate_governance')
        ->and($ent10['readiness_command'])->toBe('foundation:cicd-enterprise-gate-check')
        ->and($ent10['policy_doc'])->toBe('docs/architecture/cicd-enterprise-gate-governance.md')
        ->and($ent10['go_tag'])->toBe('ent-10-cicd-enterprise-gate-go')
        ->and($ent10['related_shipped_foundations'])->toContain('NSF-10');

    $ent9 = $sequence->firstWhere('id', 'ENT-9');
    expect($ent9['deploy_evidence_commit'])->toBe('8915c17');

    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($governance['stale_next_detected'])->toBeFalse()
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

it('publishes ENT10 rules through the governance service and foundation summary', function () {
    $rules = CicdEnterpriseGateGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT10-CICD%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('cicd_enterprise_gate_governance')
        ->and($summary['cicd_enterprise_gate_governance']['command'])->toBe('foundation:cicd-enterprise-gate-check')
        ->and($summary['cicd_enterprise_gate_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['cicd_enterprise_gate_governance']['rules'], 'id'))->toContain('ENT10-CICD001')
        ->and($summary)->toHaveKeys(['security_compliance_governance', 'health_check_governance', 'developer_console_governance', 'idempotency_outbox_governance', 'queue_retry_governance']);
});

it('requires the ENT-10 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('cicd-enterprise-gate-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('cicd-enterprise-gate-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:cicd-enterprise-gate-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-10');
});

it('keeps the deploy and CI scripts running the ENT-10 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:cicd-enterprise-gate-check')
        ->and($deploy)->toContain('foundation:queue-retry-failed-job-check')
        ->and($ciScript)->toContain('foundation:cicd-enterprise-gate-check');

    foreach (config('cicd_enterprise_gate.forbidden_destructive_patterns', []) as $pattern) {
        expect(stripos($deploy, (string) $pattern))->toBeFalse(
            "deploy script must not contain destructive pattern: {$pattern}"
        );
        expect(stripos($ciScript, (string) $pattern))->toBeFalse(
            "CI script must not contain destructive pattern: {$pattern}"
        );
    }
});

it('foundation roadmap check stays green after the ENT-10 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-10 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
