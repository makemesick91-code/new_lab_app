<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\EnterpriseDocumentationGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Support\Documentation\EnterpriseDocumentationScanner;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation', 'EnterpriseDocumentation', 'EnterpriseRunbook');

it('ships the enterprise documentation governance doc with all ENT15 rules', function () {
    $path = base_path('docs/architecture/enterprise-documentation-runbook-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT15-DOC%03d', $n));
    }

    expect($doc)->toContain('foundation:enterprise-documentation-check')
        ->and($doc)->toContain('enterprise_documentation_governance')
        ->and($doc)->toContain('docs:enterprise-runbook-summary')
        ->and($doc)->toContain('enterprise-documentation-check.json')
        ->and($doc)->toContain('docs/runbooks/');
});

it('keeps the ENT-15 governance docs and runbooks free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/enterprise-documentation-runbook-governance.md'),
        base_path('docs/sprints/ent-15-enterprise-documentation-runbook.md'),
        base_path('docs/runbooks/enterprise-operations-runbook.md'),
        base_path('docs/runbooks/vps-deploy-rollback-runbook.md'),
        base_path('docs/runbooks/backup-dr-restore-rehearsal-runbook.md'),
        base_path('docs/runbooks/release-evidence-smoke-runbook.md'),
        base_path('docs/runbooks/performance-load-test-runbook.md'),
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

it('references ENT-15 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/64-enterprise-documentation-runbook.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('enterprise-documentation-runbook-governance.md')
        ->and($freeze)->toContain('foundation:enterprise-documentation-check')
        ->and($cursor)->toContain('docs/runbooks/')
        ->and($cursor)->toContain('foundation:enterprise-documentation-check --strict')
        ->and($claude)->toContain('ENT-15 — Enterprise Documentation & Runbook')
        ->and($claude)->toContain('enterprise_documentation_governance');
});

it('registers the ENT-15 governance pointers in roadmap and config', function () {
    expect(config('foundation_roadmap.rules.enterprise_documentation_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.enterprise_documentation_governance_doc'))->toBe('docs/architecture/enterprise-documentation-runbook-governance.md')
        ->and(config('enterprise_documentation.enabled'))->toBeTrue()
        ->and(config('enterprise_documentation.forbidden_destructive_patterns'))->toContain('migrate:fresh')
        ->and(config('enterprise_documentation.forbidden_destructive_patterns'))->toContain('db:wipe')
        ->and(config('enterprise_documentation.evidence.artifact'))->toBe('enterprise-documentation-check.json')
        ->and(config('enterprise_documentation.summary_command'))->toBe('docs:enterprise-runbook-summary')
        ->and(config('enterprise_documentation.required_topics'))->toContain('deploy')
        ->and(config('enterprise_documentation.required_topics'))->toContain('restore_rehearsal')
        ->and(config('enterprise_documentation.required_topics'))->toContain('scale_projection');
});

it('registers ENT-15 completed and moves next recommended sprint to ENT-16', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent15 = $sequence->firstWhere('id', 'ENT-15');

    expect($ent15)->not->toBeNull()
        ->and($ent15['status'])->toBe('completed')
        ->and($ent15['category'])->toBe('documentation')
        ->and($ent15['governance_section'])->toBe('enterprise_documentation_governance')
        ->and($ent15['readiness_command'])->toBe('foundation:enterprise-documentation-check')
        ->and($ent15['policy_doc'])->toBe('docs/architecture/enterprise-documentation-runbook-governance.md')
        ->and($ent15['go_tag'])->toBe('ent-15-enterprise-documentation-runbook-go');

    $ent14 = $sequence->firstWhere('id', 'ENT-14');
    expect($ent14['deploy_evidence_commit'])->toBe('86ef921');

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

it('publishes ENT15 rules through the governance service and foundation summary', function () {
    $rules = EnterpriseDocumentationGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT15-DOC%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('enterprise_documentation_governance')
        ->and($summary['enterprise_documentation_governance']['command'])->toBe('foundation:enterprise-documentation-check')
        ->and($summary['enterprise_documentation_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['enterprise_documentation_governance']['rules'], 'id'))->toContain('ENT15-DOC001')
        ->and($summary)->toHaveKeys(['load_test_scale_projection_governance', 'load_test_baseline_governance', 'backup_dr_governance']);
});

it('requires the ENT-15 evidence artifact + pre-deploy gate + CI-gate registry entry', function () {
    expect(config('release_evidence.profiles.ci.required_artifacts'))->toContain('enterprise-documentation-check.json')
        ->and(config('release_evidence.profiles.vps.required_artifacts'))->toContain('enterprise-documentation-check.json')
        ->and(config('release_safety.required_pre_deploy_gates'))->toContain('foundation:enterprise-documentation-check')
        ->and(config('foundation_governance.ci_evidence_gates.gates'))->toHaveKey('ENT-15');
});

it('declares a runbook set covering every required topic with all files present', function () {
    $report = app(EnterpriseDocumentationGovernanceService::class)->collect();

    expect($report['registry_ok'])->toBeTrue()
        ->and($report['runbook_files_ok'])->toBeTrue()
        ->and($report['required_sections_ok'])->toBeTrue()
        ->and($report['forbidden_declaration_ok'])->toBeTrue()
        ->and($report['destructive_safety_ok'])->toBeTrue()
        ->and($report['sensitive_content_ok'])->toBeTrue()
        ->and($report['foundation_linkage_ok'])->toBeTrue();

    foreach (config('enterprise_documentation.required_topics') as $topic) {
        expect($report['covered_topics'])->toContain($topic);
    }
});

it('detects a destructive command that appears outside a forbidden/warning section', function () {
    $scanner = app(EnterpriseDocumentationScanner::class);

    $unsafe = "## Safe Commands\n\nRun `php artisan migrate:fresh` to reset.\n";
    $safe = "## Forbidden Commands\n\nNever run `php artisan migrate:fresh`.\n";

    expect($scanner->scanContentForUnsafeDestructive($unsafe))->not->toBe([])
        ->and($scanner->scanContentForUnsafeDestructive($safe))->toBe([]);
});

it('detects secret/PII-shaped content in a runbook body', function () {
    $scanner = app(EnterpriseDocumentationScanner::class);

    expect($scanner->scanContentForSensitive('APP_KEY=base64:secret'))->not->toBe([])
        ->and($scanner->scanContentForSensitive('patient id 1234567890123456'))->not->toBe([])
        ->and($scanner->scanContentForSensitive('This runbook is non-sensitive.'))->toBe([]);
});

it('fails ENT-15 governance when a mandatory runbook file is missing', function () {
    config(['enterprise_documentation.mandatory_runbooks.enterprise_operations.path' => 'docs/runbooks/does-not-exist.md']);

    $report = app(EnterpriseDocumentationGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['runbook_files_ok'])->toBeFalse();
});

it('fails ENT-15 governance when the release-safety gate is missing', function () {
    config(['release_safety.required_pre_deploy_gates' => []]);

    $report = app(EnterpriseDocumentationGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('keeps the deploy and CI scripts running the ENT-15 gate without destructive commands', function () {
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $ciScript = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    expect($deploy)->toContain('foundation:enterprise-documentation-check')
        ->and($deploy)->toContain('enterprise-documentation-check.json')
        ->and($ciScript)->toContain('foundation:enterprise-documentation-check');
});

it('foundation roadmap check stays green after the ENT-15 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-15 through ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:enterprise-documentation-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:load-test-scale-projection-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
