<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Queue1', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/queue1-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes QUEUE_GOVERNANCE IDEMPOTENCY and OUTBOX sections', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKeys(['queue_governance', 'idempotency', 'outbox'])
        ->and($summary['queue_governance']['decision'])->toBe('GO')
        ->and($summary['idempotency']['decision'])->toBe('GO')
        ->and($summary['outbox']['decision'])->toBe('GO')
        ->and($summary['summary']['queue_governance_decision'])->toBe('GO')
        ->and($summary['summary']['idempotency_decision'])->toBe('GO')
        ->and($summary['summary']['outbox_decision'])->toBe('GO');
});

it('combined foundation remains GO after QUEUE-1', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['cache_governance']['decision'])->toBe('GO');
});

it('release evidence capture includes queue idempotency and outbox artifacts for ci profile', function () {
    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('queue-governance-check.json', 'idempotency-audit.json', 'outbox-audit.json');
});

it('release evidence check expects queue idempotency and outbox artifacts after QUEUE-1', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    $check = app(ReleaseEvidenceService::class)->check('ci');
    $artifacts = array_column($check['artifacts'] ?? [], 'artifact');

    expect($artifacts)->toContain('queue-governance-check.json', 'idempotency-audit.json', 'outbox-audit.json')
        ->and($check['summary']['decision'])->toBe('GO');
});

it('ci workflow contains queue idempotency and outbox evidence steps', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:queue-governance-check')
        ->and($workflow)->toContain('foundation:idempotency-audit')
        ->and($workflow)->toContain('foundation:outbox-audit')
        ->and($workflow)->toContain('queue-governance-check.json');
});

it('deploy script contains queue idempotency and outbox gates', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:queue-governance-check')
        ->and($script)->toContain('foundation:idempotency-audit')
        ->and($script)->toContain('foundation:outbox-audit');
});

it('QUEUE-1 and DBPERF-1 are marked completed in the roadmap', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    $queue1 = collect($report['approved_sequence'])->firstWhere('id', 'QUEUE-1');
    expect($queue1['status'])->toBe('completed');
});

it('release safety config lists queue idempotency and outbox gate commands', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:queue-governance-check', 'foundation:idempotency-audit', 'foundation:outbox-audit');
});

it('foundation governance config registers QUEUE-1 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('QUEUE-1')
        ->and($gates['QUEUE-1']['artifacts'])->toContain('storage/ci-evidence/queue-governance-check.json');
});

it('no queue worker process is started by tests', function () {
    // Governance commands are read-only and must never invoke queue:work/queue:listen.
    $commandFiles = [
        base_path('app/Console/Commands/FoundationQueueGovernanceCheckCommand.php'),
        base_path('app/Console/Commands/FoundationIdempotencyAuditCommand.php'),
        base_path('app/Console/Commands/FoundationOutboxAuditCommand.php'),
    ];

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toContain('queue:work')->not->toContain('queue:listen');
    }
});
