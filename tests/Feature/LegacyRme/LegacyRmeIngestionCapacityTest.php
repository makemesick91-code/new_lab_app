<?php

declare(strict_types=1);

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeIngestionCapacityService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeIngestionCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-3 — ingestion backpressure.
 *
 * Multi-branch migration multiplies render load onto ONE worker. These tests
 * pin the ceiling that stops several branches from queueing more work than the
 * pipeline can drain, and — just as importantly — pin that throttling only ever
 * refuses NEW intake and never touches work already in flight.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

function capacityService(): LegacyRmeIngestionCapacityService
{
    return app(LegacyRmeIngestionCapacityService::class);
}

/** Put N pending jobs on the dedicated render queue. */
function seedRenderQueue(int $count, int $ageSeconds = 0): void
{
    $queue = (string) config('legacy_rme.processing.queue', 'legacy-rme-documents');

    for ($i = 0; $i < $count; $i++) {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'render']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - $ageSeconds,
            'created_at' => time() - $ageSeconds,
        ]);
    }
}

it('reports headroom when the queue is empty', function () {
    config()->set('queue.default', 'database');

    $capacity = capacityService()->evaluate();

    expect($capacity->available)->toBeTrue()
        ->and($capacity->code)->toBe(LegacyRmeIngestionCapacity::CODE_AVAILABLE);
});

it('closes ingestion once the render queue is too deep', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 3);

    seedRenderQueue(3);

    $capacity = capacityService()->evaluate();

    expect($capacity->isSaturated())->toBeTrue()
        ->and($capacity->code)->toBe(LegacyRmeIngestionCapacity::CODE_QUEUE_DEPTH);
});

it('closes ingestion when the oldest queued document is not draining', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 0);
    config()->set('legacy_rme_rollout.capacity.max_oldest_pending_seconds', 600);

    seedRenderQueue(1, ageSeconds: 900);

    $capacity = capacityService()->evaluate();

    expect($capacity->isSaturated())->toBeTrue()
        ->and($capacity->code)->toBe(LegacyRmeIngestionCapacity::CODE_QUEUE_STALLED);
});

it('counts only the dedicated render queue, never unrelated work', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 2);

    // Busy `default` queue, empty render queue: ingestion must stay open.
    for ($i = 0; $i < 20; $i++) {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'other']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    expect(capacityService()->evaluate()->available)->toBeTrue();
});

it('treats an unmeasurable queue as unmeasurable rather than as headroom or as full', function () {
    // A non-database driver has no jobs table to read.
    config()->set('queue.default', 'sync');

    $capacity = capacityService()->evaluate();

    expect($capacity->measurements['pending_jobs'])->toBeNull()
        // Nothing was measured, so no threshold can have been exceeded — but
        // the report says so honestly instead of claiming a depth of zero.
        ->and($capacity->available)->toBeTrue();
});

it('honours a disabled threshold', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 0);

    seedRenderQueue(50);

    expect(capacityService()->evaluate()->available)->toBeTrue();
});

it('does not throttle at all when backpressure is switched off', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.enforced', false);
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 1);

    seedRenderQueue(50);

    expect(capacityService()->evaluate()->available)->toBeTrue();
});

it('refuses a new upload while the pipeline is saturated, without touching queued work', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 1);

    seedRenderQueue(2);
    $queuedBefore = DB::table('jobs')->count();

    $patient = legacyRmeArchivablePatient([], 'TKM1');
    legacyRmeAdmittedBranches(['TKM1']);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    // Nothing staged, nothing stored, and — critically — the in-flight jobs
    // were not cancelled to make room.
    expect(LegacyRmeImport::query()->count())->toBe(0)
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe([])
        ->and(DB::table('jobs')->count())->toBe($queuedBefore);
});

it('records a throttled intake in the audit trail without patient data', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 1);

    seedRenderQueue(2);

    $patient = legacyRmeArchivablePatient(['medical_record_number' => 'DG-TKM1-2024-9985'], 'TKM1');
    legacyRmeAdmittedBranches(['TKM1']);

    // PRECONDITIONS, asserted rather than assumed. This test's real subject is
    // the audit payload, so it must fail loudly on the SETUP if ambient state
    // from another suite ever leaves the branch un-admitted or the pipeline
    // with headroom — otherwise "no audit row" would be read as a privacy
    // regression when it was really a fixture that never throttled.
    expect(app(LegacyRmeBranchAdmissionService::class)
        ->decide(app(LegacyRmeBranchResolver::class)->resolveForPatient($patient))
        ->admitted)->toBeTrue();
    expect(capacityService()->evaluate()->isSaturated())->toBeTrue();

    try {
        app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        );
    } catch (ValidationException) {
        // expected
    }

    $rows = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::INGESTION_THROTTLED)
        ->get();

    expect($rows)->not->toBeEmpty();

    $payload = $rows->map(static fn ($r): string => (string) $r->new_values)->implode(' ');

    expect($payload)->not->toContain('DG-TKM1-2024-9985')
        ->and($payload)->not->toContain((string) $patient->name);
});

it('reopens ingestion once the queue drains', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.capacity.max_pending_jobs', 1);

    seedRenderQueue(2);
    expect(capacityService()->evaluate()->isSaturated())->toBeTrue();

    DB::table('jobs')->delete();

    // No operator action was needed: backpressure is a transient ceiling, not
    // a latch that has to be cleared by hand.
    expect(capacityService()->evaluate()->available)->toBeTrue();
});
