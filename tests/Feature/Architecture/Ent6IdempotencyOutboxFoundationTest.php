<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\IdempotencyOutboxGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the idempotency outbox governance doc with all ENT6 rules', function () {
    $path = base_path('docs/architecture/idempotency-outbox-foundation-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 8) as $n) {
        expect($doc)->toContain(sprintf('ENT6-IO%03d', $n));
    }

    expect($doc)->toContain('foundation:idempotency-outbox-check')
        ->and($doc)->toContain('idempotency_outbox_governance')
        ->and($doc)->toContain('sys_idempotency_keys')
        ->and($doc)->toContain('sys_outbox_events')
        ->and($doc)->toContain('queue_retry_governance');
});

it('keeps the ENT-6 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/idempotency-outbox-foundation-governance.md'),
        base_path('docs/sprints/ent-6-idempotency-outbox-foundation.md'),
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

it('references ENT-6 governance from durable docs cursor rules and CLAUDE memory', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/55-idempotency-outbox-foundation.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('idempotency-outbox-foundation-governance.md')
        ->and($freeze)->toContain('foundation:idempotency-outbox-check')
        ->and($cursor)->toContain('IdempotencyService')
        ->and($cursor)->toContain('OutboxService')
        ->and($cursor)->toContain('foundation:idempotency-outbox-check --strict')
        ->and($claude)->toContain('ENT-6 — Idempotency & Outbox Foundation')
        ->and($claude)->toContain('idempotency_outbox_governance');
});

it('registers the ENT-6 governance pointers in roadmap and queue governance config', function () {
    expect(config('foundation_roadmap.rules.idempotency_outbox_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.idempotency_outbox_governance_doc'))->toBe('docs/architecture/idempotency-outbox-foundation-governance.md')
        ->and(config('queue_governance.ent6_idempotency_outbox.metadata.sprint'))->toBe('ENT-6')
        ->and(config('queue_governance.ent6_idempotency_outbox.governance_section'))->toBe('idempotency_outbox_governance')
        ->and(config('queue_governance.ent6_idempotency_outbox.readiness_command'))->toBe('foundation:idempotency-outbox-check')
        ->and(config('queue_governance.ent6_idempotency_outbox.external_dispatch_enabled'))->toBeFalse();
});

it('registers ENT-6 completed and moves next recommended sprint to ENT-9', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent6 = $sequence->firstWhere('id', 'ENT-6');

    expect($ent6)->not->toBeNull()
        ->and($ent6['status'])->toBe('completed')
        ->and($ent6['category'])->toBe('queue')
        ->and($ent6['governance_section'])->toBe('idempotency_outbox_governance')
        ->and($ent6['readiness_command'])->toBe('foundation:idempotency-outbox-check')
        ->and($ent6['policy_doc'])->toBe('docs/architecture/idempotency-outbox-foundation-governance.md')
        ->and($ent6['go_tag'])->toBe('ent-6-idempotency-outbox-foundation-go')
        ->and($ent6['related_shipped_foundations'])->toContain('QUEUE-1');

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

it('publishes ENT6 rules through the governance service and foundation summary', function () {
    $rules = IdempotencyOutboxGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 8) as $n) {
        expect($ids)->toContain(sprintf('ENT6-IO%03d', $n));
    }

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('idempotency_outbox_governance')
        ->and($summary['idempotency_outbox_governance']['command'])->toBe('foundation:idempotency-outbox-check')
        ->and($summary['idempotency_outbox_governance']['decision'])->toBe('GO')
        ->and(array_column($summary['idempotency_outbox_governance']['rules'], 'id'))->toContain('ENT6-IO001')
        ->and($summary)->toHaveKeys(['queue_governance', 'idempotency', 'outbox', 'queue_retry_governance']);
});

it('foundation roadmap check stays green after the ENT-6 lock', function () {
    expect(Artisan::call('foundation:roadmap-check', ['--strict' => true]))->toBe(0);
});

it('ENT-6 and ENT-5 strict governance commands pass on the default repo state', function () {
    expect(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0)
        ->and(Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]))->toBe(0);
});
