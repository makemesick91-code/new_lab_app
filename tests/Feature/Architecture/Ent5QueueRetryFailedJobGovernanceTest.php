<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use App\Services\Foundation\QueueRetryFailedJobGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the queue retry & failed job governance doc with all ENT5 rules', function () {
    $path = base_path('docs/architecture/queue-retry-failed-job-governance.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 8) as $n) {
        $ruleId = sprintf('ENT5-Q%03d', $n);
        expect($doc)->toContain($ruleId);
    }

    expect($doc)->toContain('EnterpriseQueueJob')
        ->and($doc)->toContain('EnterpriseQueueRetryDefaults')
        ->and($doc)->toContain('foundation:queue-retry-failed-job-check')
        ->and($doc)->toContain('database-uuids')
        ->and($doc)->toContain('failed_jobs')
        ->and($doc)->toContain('idempotent')
        ->and($doc)->toContain('queue_retry_governance')
        ->and($doc)->toContain('ShouldQueue');
});

it('ships the worker operations runbook with safe operator commands only', function () {
    $path = base_path('docs/architecture/queue-worker-operations-runbook.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('queue:work --queue=default --tries=3 --backoff=10 --timeout=120')
        ->and($doc)->toContain('queue:failed')
        ->and($doc)->toContain('queue:retry <uuid>')
        ->and($doc)->toContain('queue:forget <uuid>')
        ->and($doc)->toContain('queue:restart')
        ->and($doc)->toContain('supervisor')
        ->and($doc)->toContain('worker-ready')
        ->and($doc)->toContain('MANUAL ONLY');
});

it('keeps the ENT-5 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/queue-retry-failed-job-governance.md'),
        base_path('docs/architecture/queue-worker-operations-runbook.md'),
        base_path('docs/sprints/ent-5-queue-retry-failed-job-governance.md'),
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

it('references ENT-5 governance from durable docs and cursor rules', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/54-queue-retry-failed-job-governance.mdc'));
    $claude = file_get_contents(base_path('CLAUDE.md'));

    expect($freeze)->toContain('queue-retry-failed-job-governance.md')
        ->and($freeze)->toContain('queue-worker-operations-runbook.md')
        ->and($cursor)->toContain('EnterpriseQueueJob')
        ->and($cursor)->toContain('foundation:queue-retry-failed-job-check')
        ->and($claude)->toContain('ENT-5 — Queue, Retry & Failed Job Governance')
        ->and($claude)->toContain('queue-retry-failed-job-governance.md')
        ->and($claude)->toContain('queue-worker-operations-runbook.md');
});

it('registers the ENT-5 governance pointers in roadmap rules', function () {
    expect(config('foundation_roadmap.rules.queue_retry_failed_job_governance_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.queue_retry_failed_job_governance_doc'))->toBe('docs/architecture/queue-retry-failed-job-governance.md')
        ->and(config('foundation_roadmap.rules.queue_worker_operations_runbook_doc'))->toBe('docs/architecture/queue-worker-operations-runbook.md');
});

it('records ENT-4 deploy evidence and registers ENT-5 completed with governance pointers', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent4 = $sequence->firstWhere('id', 'ENT-4');
    $ent5 = $sequence->firstWhere('id', 'ENT-5');

    expect($ent4)->not->toBeNull()
        ->and($ent4['deploy_evidence_commit'])->toBe('321c05d')
        ->and($ent5)->not->toBeNull()
        ->and($ent5['status'])->toBe('completed')
        ->and($ent5['category'])->toBe('queue')
        ->and($ent5['governance_section'])->toBe('queue_retry_governance')
        ->and($ent5['readiness_command'])->toBe('foundation:queue-retry-failed-job-check')
        ->and($ent5['policy_doc'])->toBe('docs/architecture/queue-retry-failed-job-governance.md')
        ->and($ent5['worker_runbook_doc'])->toBe('docs/architecture/queue-worker-operations-runbook.md')
        ->and($ent5['go_tag'])->toBe('ent-5-queue-retry-failed-job-governance-go')
        ->and($ent5['related_shipped_foundations'])->toContain('QUEUE-1');
});

it('publishes ENT5-Q001..Q008 rules through the governance service', function () {
    $rules = QueueRetryFailedJobGovernanceService::rules();
    $ids = array_column($rules, 'id');

    foreach (range(1, 8) as $n) {
        expect($ids)->toContain(sprintf('ENT5-Q%03d', $n));
    }

    foreach ($rules as $rule) {
        expect($rule['title'])->not->toBeEmpty()
            ->and($rule['description'])->not->toBeEmpty();

        foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
            expect(str_contains($rule['title'].' '.$rule['description'], $pattern))->toBeFalse();
        }
    }
});

it('includes the queue_retry_governance section in the foundation governance summary without touching queue_governance', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('queue_retry_governance')
        ->and($summary['queue_retry_governance']['command'])->toBe('foundation:queue-retry-failed-job-check')
        ->and($summary['queue_retry_governance']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and(array_column($summary['queue_retry_governance']['rules'], 'id'))->toContain('ENT5-Q001')
        ->and($summary)->toHaveKey('queue_governance')
        ->and($summary['queue_governance']['command'])->toBe('foundation:queue-governance-check');
});

it('moves next recommended sprint to ENT-9 without staleness', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-13')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['missing_metadata'])->toBe([])
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-9..ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(13, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('foundation:roadmap-check --strict stays green after the ENT-5 governance lock', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});

it('foundation:queue-retry-failed-job-check --strict passes on the repo default state', function () {
    $exitCode = Artisan::call('foundation:queue-retry-failed-job-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
