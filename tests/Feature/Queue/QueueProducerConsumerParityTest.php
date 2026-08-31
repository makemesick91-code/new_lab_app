<?php

declare(strict_types=1);

use App\Modules\LegacyOdontogram\Jobs\ProcessLegacyOdontogramPdfImport;
use App\Services\Foundation\QueueRetryFailedJobGovernanceService;
use App\Support\Queue\QueueProducerConsumerContractScanner;
use App\Support\Queue\QueueRetryFailedJobReadinessService;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1 — the producer ↔ consumer contract.
 *
 * The defect these tests exist for is the quiet kind. A legacy odontogram PDF
 * was uploaded to production, the job landed on `legacy-odontogram-documents`,
 * and no worker consumed that queue. attempts stayed 0, reserved_at stayed
 * NULL, failed_jobs stayed empty, no exception was logged, and the import sat
 * at QUEUED looking exactly like work still in progress.
 *
 * It was the second time. LEGACY-RME-PDF-ROLL-2 hit the same shape and answered
 * with a check scoped to one module, which Legacy Odontogram then did not
 * inherit. So the tests below deliberately spend most of their effort NOT on
 * the odontogram queue but on whether the guard would catch the NEXT module —
 * a queue nobody has written yet.
 */
function contractScanner(): QueueProducerConsumerContractScanner
{
    return new QueueProducerConsumerContractScanner;
}

function ent5Check(string $id): ?array
{
    foreach ((new QueueRetryFailedJobReadinessService)->collect()['checks'] as $check) {
        if ($check['check_id'] === $id) {
            return $check;
        }
    }

    return null;
}

function parityStatus(): string
{
    return (string) (ent5Check('ENT5-Q009-PRODUCER-CONSUMER-PARITY')['status'] ?? 'missing');
}

/**
 * A throwaway worker unit for a single assertion.
 *
 * Allocated through the owned temp-artifact registry rather than tempnam, so
 * the global drain reclaims it even when an expectation fails before any
 * cleanup line is reached (FIX-TEST-TEMPFILE-SIBLING-LEAKS-1).
 */
function writeUnitFixture(string $queueArgument, string $startLimitSection = 'Unit'): string
{
    $path = tempArtifactFile('qpc1-unit-');
    $startLimit = 'StartLimitIntervalSec=0';

    file_put_contents($path, implode("\n", array_filter([
        '[Unit]',
        'Description=fixture',
        $startLimitSection === 'Unit' ? $startLimit : null,
        '',
        '[Service]',
        'Type=simple',
        $startLimitSection === 'Service' ? $startLimit : null,
        'ExecStart=/usr/bin/php artisan queue:work database \\',
        '    '.$queueArgument.' \\',
        '    --sleep=3',
        'Restart=always',
        '',
    ], static fn ($line) => $line !== null)));

    return $path;
}

function registerProducedQueue(array $entry): void
{
    $existing = (array) config('queue_governance.ent5_retry_failed_job.producer_consumer_contract.produced_queues');
    $existing[] = $entry;
    config()->set('queue_governance.ent5_retry_failed_job.producer_consumer_contract.produced_queues', $existing);
}

// ---------------------------------------------------------------------------
// The defect itself
// ---------------------------------------------------------------------------

it('routes legacy odontogram imports to their dedicated queue', function () {
    $job = new ProcessLegacyOdontogramPdfImport(1);

    expect($job->queue)->toBe('legacy-odontogram-documents')
        ->and(config('legacy_odontogram.processing.queue'))->toBe('legacy-odontogram-documents');
});

it('consumes the legacy odontogram queue in the production worker unit', function () {
    $unit = (string) file_get_contents(base_path(
        config('queue_governance.ent5_retry_failed_job.producer_consumer_contract.worker_unit_file')
    ));

    expect(contractScanner()->execStartQueues($unit))
        ->toContain('legacy-odontogram-documents');
});

it('declares the legacy odontogram queue in the ENT-5 allowed queue names', function () {
    expect(config('queue_governance.ent5_retry_failed_job.allowed_queue_names'))
        ->toContain('legacy-odontogram-documents');
});

it('keeps producer, governance allowlist and worker consumer in step', function () {
    expect(parityStatus())->toBe('passed');
});

// ---------------------------------------------------------------------------
// Would it catch the NEXT module? This is the point of the sprint.
// ---------------------------------------------------------------------------

it('fails when a future module produces a queue nothing consumes', function () {
    // A queue that is declared and legitimate in every other respect, but that
    // no worker takes. This is the exact shape of both production incidents.
    config()->set('queue_governance.ent5_retry_failed_job.allowed_queue_names', array_merge(
        (array) config('queue_governance.ent5_retry_failed_job.allowed_queue_names'),
        ['future-module-documents'],
    ));

    registerProducedQueue([
        'id' => 'future-module',
        'jobs' => [ProcessLegacyOdontogramPdfImport::class],
        'literal' => 'future-module-documents',
    ]);

    expect(parityStatus())->toBe('failed')
        ->and(ent5Check('ENT5-Q009-PRODUCER-CONSUMER-PARITY')['message'])
        ->toContain('future-module-documents')
        ->toContain('no consumer');
});

it('fails when a produced queue is not a declared queue name', function () {
    registerProducedQueue([
        'id' => 'undeclared-module',
        'jobs' => [ProcessLegacyOdontogramPdfImport::class],
        'literal' => 'undeclared-documents',
    ]);

    expect(parityStatus())->toBe('failed')
        ->and(ent5Check('ENT5-Q009-PRODUCER-CONSUMER-PARITY')['message'])
        ->toContain('undeclared-documents');
});

it('fails when the worker consumes a queue outside the governance allowlist', function () {
    config()->set(
        'queue_governance.ent5_retry_failed_job.allowed_queue_names',
        ['default', 'reports', 'notifications', 'maintenance', 'legacy-rme-documents', 'legacy-odontogram-documents'],
    );
    config()->set(
        'queue_governance.ent5_retry_failed_job.producer_consumer_contract.worker_unit_file',
        writeUnitFixture('--queue=default,reports,notifications,maintenance,legacy-rme-documents,legacy-odontogram-documents,rogue-queue'),
    );

    expect(parityStatus())->toBe('failed')
        ->and(ent5Check('ENT5-Q009-PRODUCER-CONSUMER-PARITY')['message'])->toContain('rogue-queue');
});

it('fails when a producing source file is absent from the registry', function () {
    // Emptying the registry is how a future module "forgets" to register: the
    // source still routes work to a queue, the contract just cannot see it.
    config()->set('queue_governance.ent5_retry_failed_job.producer_consumer_contract.produced_queues', []);

    $check = ent5Check('ENT5-Q009-PRODUCER-REGISTRY-COMPLETE');

    expect($check['status'])->toBe('failed')
        ->and($check['message'])->toContain('ProcessLegacyOdontogramPdfImport.php');
});

it('fails closed when a scanned source file cannot be read', function () {
    // An unreadable file cannot be shown NOT to route work to a queue. Folding
    // that into "routes nothing" would be a fail-open in the one check whose job
    // is to notice an unregistered producer — which is exactly what casting a
    // suppressed read to a string used to do here.
    $dir = tempArtifactDir('qpc1-scan-');
    $unreadable = $dir.'/UnreadableProducer.php';
    file_put_contents($unreadable, "<?php\n");
    chmod($unreadable, 0o000);

    if (is_readable($unreadable)) {
        // Running as a user that bypasses file permissions (root in a container);
        // the branch is unreachable here and a pass would be meaningless.
        expect(true)->toBeTrue();

        return;
    }

    config()->set('queue_governance.ent5_retry_failed_job.producer_consumer_contract.queue_assignment_scan.paths', [$dir]);

    $check = ent5Check('ENT5-Q009-PRODUCER-REGISTRY-COMPLETE');

    expect($check['status'])->toBe('failed')
        ->and($check['message'])->toContain('could not be read');

    chmod($unreadable, 0o600);
});

it('resolves the produced queue name at runtime rather than from a source literal', function () {
    // Every dedicated queue here is env-overridable. A literal scan would keep
    // asserting the default while production ran something else.
    config()->set('legacy_odontogram.processing.queue', 'renamed-odontogram-queue');

    expect(contractScanner()->posture()['produced_queues'])
        ->toContain('renamed-odontogram-queue')
        ->not->toContain('legacy-odontogram-documents');
});

// ---------------------------------------------------------------------------
// The unit parser must read what systemd runs, not what the file says nearby
// ---------------------------------------------------------------------------

it('reads the queue list from ExecStart and never from a comment', function () {
    $unit = <<<'UNIT'
    [Service]
    # --queue=default,reports,legacy-odontogram-documents
    ExecStart=/usr/bin/php artisan queue:work database --queue=default
    UNIT;

    expect(contractScanner()->execStartQueues($unit))->toBe(['default']);
});

it('takes the queue list only from ExecStart, not from any other directive', function () {
    // The comment case above and this one each pin HALF of the parser: skipping
    // comments and anchoring on ExecStart independently mask each other, so a
    // fixture with only a commented queue list cannot prove the anchor exists.
    // A non-comment directive can carry the flag too — an Environment= value, or
    // an ExecStartPre= helper — and neither is what the worker consumes.
    $unit = <<<'UNIT'
    [Service]
    Environment="LEGACY_ARGS=--queue=default,reports,ghost-queue"
    ExecStartPre=/usr/bin/php artisan about --queue=another-ghost
    ExecStart=/usr/bin/php artisan queue:work database --queue=default,reports
    UNIT;

    expect(contractScanner()->execStartQueues($unit))->toBe(['default', 'reports']);
});

it('joins systemd line continuations when reading the queue list', function () {
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work database \\\n    --queue=default,legacy-odontogram-documents \\\n    --sleep=3\n";

    expect(contractScanner()->execStartQueues($unit))
        ->toBe(['default', 'legacy-odontogram-documents']);
});

it('does not let a comment swallow the ExecStart that follows it', function () {
    // systemd continues a COMMENT on a trailing backslash too. A naive joiner
    // would fold the next line into the comment, and the real ExecStart would
    // vanish — the parser would then report "no queue list" for a unit that
    // plainly has one. Dropping the comment before joining is what prevents it.
    $unit = "[Service]\n# a wrapped comment ending in a backslash \\\n"
        ."ExecStart=/usr/bin/php artisan queue:work database --queue=default,reports\n";

    expect(contractScanner()->execStartQueues($unit))->toBe(['default', 'reports']);
});

it('matches queue names exactly rather than by substring', function () {
    // `legacy-odontogram-documents-archive` must not satisfy a requirement for
    // `legacy-odontogram-documents`.
    config()->set(
        'queue_governance.ent5_retry_failed_job.producer_consumer_contract.worker_unit_file',
        writeUnitFixture('--queue=default,reports,notifications,maintenance,legacy-rme-documents,legacy-odontogram-documents-archive'),
    );

    expect(parityStatus())->toBe('failed');
});

it('reports no queue list when the unit declares no ExecStart', function () {
    expect(contractScanner()->execStartQueues("[Service]\nType=simple\n"))->toBeNull();
});

// ---------------------------------------------------------------------------
// systemd directive placement
// ---------------------------------------------------------------------------

it('declares start limit directives where systemd honours them', function () {
    expect(ent5Check('ENT5-Q010-WORKER-UNIT-DIRECTIVE-SECTIONS')['status'])->toBe('passed');
});

it('fails when a start limit directive sits in a section systemd ignores', function () {
    // Exactly the production state: the file asked for 0, systemd applied its
    // own default and logged "Unknown key name ... ignoring".
    config()->set(
        'queue_governance.ent5_retry_failed_job.producer_consumer_contract.worker_unit_file',
        writeUnitFixture(
            '--queue=default,reports,notifications,maintenance,legacy-rme-documents,legacy-odontogram-documents',
            'Service',
        ),
    );

    $check = ent5Check('ENT5-Q010-WORKER-UNIT-DIRECTIVE-SECTIONS');

    expect($check['status'])->toBe('failed')
        ->and($check['message'])->toContain('StartLimitIntervalSec')
        ->toContain('silently ignored');
});

// ---------------------------------------------------------------------------
// Disabled producers, and the flag that un-suspends them
// ---------------------------------------------------------------------------

it('suspends the consumer requirement only while the producing feature is off', function () {
    expect(config('satusehat.enabled'))->toBeFalse()
        ->and(parityStatus())->toBe('passed');

    // Turning the feature on must bring the requirement back by itself — the
    // suspension is a live read of the same flag the runtime uses, not an
    // exemption someone can park a queue on.
    config()->set('satusehat.enabled', true);

    expect(parityStatus())->toBe('failed')
        ->and(ent5Check('ENT5-Q009-PRODUCER-CONSUMER-PARITY')['message'])->toContain('satusehat');
});

// ---------------------------------------------------------------------------
// Installed-unit drift is operational, never a build failure
// ---------------------------------------------------------------------------

it('reports installed-unit drift without moving the readiness decision', function () {
    config()->set(
        'queue_governance.ent5_retry_failed_job.producer_consumer_contract.installed_worker_unit_file',
        writeUnitFixture('--queue=default,reports,notifications,maintenance,legacy-rme-documents'),
    );

    $report = (new QueueRetryFailedJobReadinessService)->collect();

    // Reported, so an operator can see the activation step is still pending...
    expect($report['producer_consumer_contract']['worker_unit']['installed_drift'])->toBeTrue()
        ->and($report['producer_consumer_contract']['warnings'][0])
        ->toContain('legacy-odontogram-documents');

    // ...but NOT a check, and therefore not a WATCH.
    //
    // This is the shape production rejected. The deploy is forbidden from
    // installing or starting a worker (ENT5-Q006), so a unit-changing deploy
    // ALWAYS precedes activation. When drift was a warning, this decision went
    // WATCH, cascaded through ENT-8/9/10/11, and scripts/deploy-vps.sh — which
    // asserts ENT-11 is GO — aborted. The deploy could not finish because the
    // unit was not installed, and the unit could not be installed until the
    // deploy finished.
    expect($report['decision'])->toBe('GO')
        ->and(array_column($report['checks'], 'check_id'))
        ->not->toContain('ENT5-Q009-INSTALLED-WORKER-DRIFT')
        ->and(parityStatus())->toBe('passed');
});

// ---------------------------------------------------------------------------
// Governance publication
// ---------------------------------------------------------------------------

it('publishes the producer-consumer rules through ENT-5 governance', function () {
    $ids = array_column(QueueRetryFailedJobGovernanceService::rules(), 'id');

    expect($ids)->toContain('ENT5-Q009')->toContain('ENT5-Q010');
});

it('keeps the approved worker queue list identical to what the unit consumes', function () {
    // A third copy of the same fact had already drifted: the approved list still
    // named four queues while the unit had consumed five since ROLL-2.
    $unit = (string) file_get_contents(base_path(
        config('queue_governance.ent5_retry_failed_job.producer_consumer_contract.worker_unit_file')
    ));

    expect(config('enterprise_foundation_runtime_hardening.queue_worker.approved_queues'))
        ->toEqualCanonicalizing(contractScanner()->execStartQueues($unit));
});
