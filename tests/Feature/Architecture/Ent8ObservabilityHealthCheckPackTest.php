<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\HealthCheckGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the health check pack governance doc with all ENT8 rules', function () {
    $path = base_path('docs/architecture/observability-health-check-pack-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 10) as $n) {
        expect($doc)->toContain(sprintf('ENT8-HC%03d', $n));
    }

    expect($doc)->toContain('foundation:health-check')
        ->and($doc)->toContain('health_check_governance')
        ->and($doc)->toContain('/health/live')
        ->and($doc)->toContain('/health/ready')
        ->and($doc)->toContain('/health/lb');
});

it('keeps the ENT-8 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/observability-health-check-pack-governance.md'),
        base_path('docs/sprints/ent-8-observability-health-check-pack.md'),
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

it('references ENT-8 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/57-observability-health-check-pack.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('observability-health-check-pack-governance.md')
        ->and($freeze)->toContain('foundation:health-check')
        ->and($cursor)->toContain('health.ready')
        ->and($cursor)->toContain('foundation:health-check --strict')
        ->and($claude)->toContain('ENT-8 — Observability & Health Check Pack')
        ->and($claude)->toContain('health_check_governance');
});

it('registers the ENT-8 governance pointers in roadmap and health check config', function () {
    expect(config('foundation_roadmap.rules.health_check_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.health_check_governance_doc'))->toBe('docs/architecture/observability-health-check-pack-governance.md')
        ->and(config('health_check.enabled'))->toBeTrue()
        ->and(config('health_check.liveness.enabled'))->toBeTrue()
        ->and(config('health_check.readiness.enabled'))->toBeTrue()
        ->and(config('health_check.components.database.critical'))->toBeTrue()
        ->and(config('health_check.alerting.external_channel_enabled'))->toBeFalse();
});

it('registers ENT-8 completed and moves next recommended sprint to ENT-9', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent8 = $sequence->firstWhere('id', 'ENT-8');

    expect($ent8)->not->toBeNull()
        ->and($ent8['status'])->toBe('completed')
        ->and($ent8['category'])->toBe('observability')
        ->and($ent8['governance_section'])->toBe('health_check_governance')
        ->and($ent8['readiness_command'])->toBe('foundation:health-check')
        ->and($ent8['policy_doc'])->toBe('docs/architecture/observability-health-check-pack-governance.md')
        ->and($ent8['go_tag'])->toBe('ent-8-observability-health-check-pack-go')
        ->and($ent8['related_shipped_foundations'])->toContain('LB-1');

    $ent7 = $sequence->firstWhere('id', 'ENT-7');
    expect($ent7['deploy_evidence_commit'])->toBe('0250ef4');

    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-11')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-11 through ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(11, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned');
    }
});

it('publishes ENT8 rules through the governance service and foundation summary', function () {
    $rules = HealthCheckGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 10) as $n) {
        expect($ids)->toContain(sprintf('ENT8-HC%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('health_check_governance')
        ->and($summary['health_check_governance']['command'])->toBe('foundation:health-check')
        ->and($summary['health_check_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['health_check_governance']['rules'], 'id'))->toContain('ENT8-HC001')
        ->and($summary)->toHaveKeys(['developer_console_governance', 'queue_retry_governance', 'idempotency_outbox_governance']);
});

it('foundation roadmap check stays green after the ENT-8 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-8 ENT-7 ENT-6 and ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
