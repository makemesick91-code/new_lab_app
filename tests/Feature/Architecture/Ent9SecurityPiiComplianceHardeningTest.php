<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\SecurityComplianceGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the security & PII compliance governance doc with all ENT9 rules', function () {
    $path = base_path('docs/architecture/security-pii-compliance-hardening-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 12) as $n) {
        expect($doc)->toContain(sprintf('ENT9-SEC%03d', $n));
    }

    expect($doc)->toContain('foundation:security-compliance-check')
        ->and($doc)->toContain('security_compliance_governance')
        ->and($doc)->toContain('BranchContext')
        ->and($doc)->toContain('sys_audit_logs');
});

it('keeps the ENT-9 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/security-pii-compliance-hardening-governance.md'),
        base_path('docs/sprints/ent-9-security-pii-compliance-hardening.md'),
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

it('references ENT-9 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/58-security-pii-compliance-hardening.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('security-pii-compliance-hardening-governance.md')
        ->and($freeze)->toContain('foundation:security-compliance-check')
        ->and($cursor)->toContain('BranchContext::requireId')
        ->and($cursor)->toContain('foundation:security-compliance-check --strict')
        ->and($claude)->toContain('ENT-9 — Security & PII Compliance Hardening')
        ->and($claude)->toContain('security_compliance_governance');
});

it('registers the ENT-9 governance pointers in roadmap and security config', function () {
    expect(config('foundation_roadmap.rules.security_compliance_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.security_compliance_governance_doc'))->toBe('docs/architecture/security-pii-compliance-hardening-governance.md')
        ->and(config('security_compliance.enabled'))->toBeTrue()
        ->and(config('security_compliance.pii_fields'))->toContain('ktp_number')
        ->and(config('security_compliance.view_scan.enabled'))->toBeTrue()
        ->and(config('security_compliance.export_gating.enabled'))->toBeTrue()
        ->and(config('security_compliance.branch_isolation.never_trust_request_branch_id'))->toBeTrue();
});

it('registers ENT-9 completed and moves next recommended sprint to ENT-10', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent9 = $sequence->firstWhere('id', 'ENT-9');

    expect($ent9)->not->toBeNull()
        ->and($ent9['status'])->toBe('completed')
        ->and($ent9['category'])->toBe('security')
        ->and($ent9['governance_section'])->toBe('security_compliance_governance')
        ->and($ent9['readiness_command'])->toBe('foundation:security-compliance-check')
        ->and($ent9['policy_doc'])->toBe('docs/architecture/security-pii-compliance-hardening-governance.md')
        ->and($ent9['go_tag'])->toBe('ent-9-security-pii-compliance-hardening-go')
        ->and($ent9['related_shipped_foundations'])->toContain('NSF-10');

    $ent8 = $sequence->firstWhere('id', 'ENT-8');
    expect($ent8['deploy_evidence_commit'])->toBe('982ac44');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-10')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-10 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(10, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned');
    }
});

it('publishes ENT9 rules through the governance service and foundation summary', function () {
    $rules = SecurityComplianceGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT9-SEC%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('security_compliance_governance')
        ->and($summary['security_compliance_governance']['command'])->toBe('foundation:security-compliance-check')
        ->and($summary['security_compliance_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['security_compliance_governance']['rules'], 'id'))->toContain('ENT9-SEC001')
        ->and($summary)->toHaveKeys(['health_check_governance', 'developer_console_governance', 'queue_retry_governance', 'idempotency_outbox_governance']);
});

it('foundation roadmap check stays green after the ENT-9 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-9 ENT-8 ENT-7 ENT-6 and ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
