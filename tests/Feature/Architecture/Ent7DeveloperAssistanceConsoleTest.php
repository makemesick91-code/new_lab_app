<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\DeveloperConsoleGovernanceService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the developer console governance doc with all ENT7 rules', function () {
    $path = base_path('docs/architecture/developer-assistance-console-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 10) as $n) {
        expect($doc)->toContain(sprintf('ENT7-DC%03d', $n));
    }

    expect($doc)->toContain('foundation:developer-console-check')
        ->and($doc)->toContain('developer_console_governance')
        ->and($doc)->toContain('view_developer_console')
        ->and($doc)->toContain('sys_audit_logs')
        ->and($doc)->toContain('queue_retry_governance')
        ->and($doc)->toContain('idempotency_outbox_governance');
});

it('keeps the ENT-7 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/developer-assistance-console-governance.md'),
        base_path('docs/sprints/ent-7-developer-assistance-console.md'),
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

it('references ENT-7 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/56-developer-assistance-console.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('developer-assistance-console-governance.md')
        ->and($freeze)->toContain('foundation:developer-console-check')
        ->and($cursor)->toContain('view_developer_console')
        ->and($cursor)->toContain('SensitiveValueMasker')
        ->and($cursor)->toContain('foundation:developer-console-check --strict')
        ->and($claude)->toContain('ENT-7 — Developer Assistance Console')
        ->and($claude)->toContain('developer_console_governance');
});

it('registers the ENT-7 governance pointers in roadmap and console config', function () {
    expect(config('foundation_roadmap.rules.developer_console_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.developer_console_governance_doc'))->toBe('docs/architecture/developer-assistance-console-governance.md')
        ->and(config('developer_console.metadata.sprint'))->toBe('ENT-7')
        ->and(config('developer_console.governance_section'))->toBe('developer_console_governance')
        ->and(config('developer_console.readiness_command'))->toBe('foundation:developer-console-check')
        ->and(config('developer_console.read_only'))->toBeTrue()
        ->and(config('developer_console.permission'))->toBe('view_developer_console');
});

it('registers ENT-7 completed and moves next recommended sprint to ENT-9', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent7 = $sequence->firstWhere('id', 'ENT-7');

    expect($ent7)->not->toBeNull()
        ->and($ent7['status'])->toBe('completed')
        ->and($ent7['category'])->toBe('observability')
        ->and($ent7['governance_section'])->toBe('developer_console_governance')
        ->and($ent7['readiness_command'])->toBe('foundation:developer-console-check')
        ->and($ent7['policy_doc'])->toBe('docs/architecture/developer-assistance-console-governance.md')
        ->and($ent7['go_tag'])->toBe('ent-7-developer-assistance-console-go')
        ->and($ent7['related_shipped_foundations'])->toContain('OBS-1');

    $ent6 = $sequence->firstWhere('id', 'ENT-6');
    expect($ent6['go_commit'])->toBe('46f41e8893a1892b9c725daefbf43d41ced967cd');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-10')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-9 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(10, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('publishes ENT7 rules through the governance service and foundation summary', function () {
    $rules = DeveloperConsoleGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 10) as $n) {
        expect($ids)->toContain(sprintf('ENT7-DC%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('developer_console_governance')
        ->and($summary['developer_console_governance']['command'])->toBe('foundation:developer-console-check')
        ->and($summary['developer_console_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['developer_console_governance']['rules'], 'id'))->toContain('ENT7-DC001')
        ->and($summary)->toHaveKeys(['queue_governance', 'idempotency', 'outbox', 'queue_retry_governance', 'idempotency_outbox_governance']);
});

it('foundation roadmap check stays green after the ENT-7 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-7 ENT-6 and ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
