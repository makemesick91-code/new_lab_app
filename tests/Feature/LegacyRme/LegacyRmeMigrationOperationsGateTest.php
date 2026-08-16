<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeOperationsGateService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeOperationsDecision;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\Patient\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4 — the migration operations gate.
 *
 * WHAT THESE TESTS PIN. ROLL-3 answered "may this BRANCH migrate?". ROLL-4 adds
 * "under which wave, by WHOM, and HOW MUCH today?" — and the whole safety
 * argument is that it can only ever NARROW that answer, never widen it.
 *
 * Every test below therefore starts from a state ROLL-3 already admits, and
 * asserts that the operations layer either refuses it for an operational reason
 * or lets it through unchanged. There is deliberately no test asserting that
 * ROLL-4 rescues a ROLL-3 denial, because no such path exists.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

function opsGate(): LegacyRmeOperationsGateService
{
    return app(LegacyRmeOperationsGateService::class);
}

/** The wave the fixtures register, refreshed from the database. */
function opsWave(string $code = 'TEST-WAVE'): LegacyRmeMigrationWave
{
    return LegacyRmeMigrationWave::query()->where('code', $code)->firstOrFail();
}

function opsWaveBranch(string $branchCode = 'TKM1'): LegacyRmeWaveBranch
{
    return LegacyRmeWaveBranch::query()->where('branch_code', $branchCode)->firstOrFail();
}

/**
 * Upload one archive through the real service, as a real actor.
 *
 * Returns the created import, or throws the ValidationException a refused gate
 * produced — which is what most of these tests assert on.
 */
function opsUpload(User $actor, ?Patient $patient = null, string $branchCode = 'TKM1'): LegacyRmeImport
{
    $patient ??= legacyRmeArchivablePatient([], $branchCode);
    legacyRmeNativeVisit($patient, '2024-05-01');

    // Each upload must carry DISTINCT bytes. legacyRmePdfUpload() is
    // deterministic, so two identical calls produce the same SHA-256 and the 1B
    // exact-duplicate gate refuses the second — which would make a quota test
    // pass for entirely the wrong reason. Varying the page count varies the
    // content hash.
    static $sequence = 0;
    $sequence++;

    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(sprintf('arsip-%d.pdf', $sequence), $sequence),
        $actor,
    );
}

// ---------------------------------------------------------------------------
// The layer is REQUIRED, not opt-in
// ---------------------------------------------------------------------------

it('refuses an admitted branch when no operational wave is registered', function () {
    // ROLL-3 admits the branch; ROLL-4 has no record of a wave to run it under.
    // Fail closed: an unregistered wave has no operators, no quota and no
    // completion path, so migrating under it would be uncontrolled by
    // construction.
    legacyRmeBranch('TKM1');
    LegacyRmeWaveOperator::query()->delete();
    LegacyRmeWaveBranch::query()->delete();
    LegacyRmeMigrationWave::query()->delete();

    $decision = opsGate()->decide(superAdmin(), 'TKM1');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_WAVE_NOT_REGISTERED);
});

it('refuses when config declares no wave at all', function () {
    legacyRmeBranch('TKM1');
    config()->set('legacy_rme_rollout.admission.wave', '');

    $decision = opsGate()->decide(superAdmin(), 'TKM1');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_WAVE_NOT_DECLARED);
});

it('clears an admitted branch under a running, correctly bound wave', function () {
    legacyRmeBranch('TKM1');

    $decision = opsGate()->decide(superAdmin(), 'TKM1');

    expect($decision->cleared)->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_CLEARED)
        ->and($decision->wave)->toBe('TEST-WAVE');
});

// ---------------------------------------------------------------------------
// Approval binding — a governance record that disagrees with the deployment
// ---------------------------------------------------------------------------

it('refuses when the wave records a different approval reference from the deployment', function () {
    legacyRmeBranch('TKM1');

    // The deployment's approval moved on; the governance record did not.
    legacyRmeApproveWave('ROLL-4-NEW-APPROVAL', ['TKM1']);

    $decision = opsGate()->decide(superAdmin(), 'TKM1');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_WAVE_BINDING_MISMATCH);
});

it('refuses when the approved branch set is widened without updating the wave', function () {
    legacyRmeBranch('TKM1');
    $reference = (string) config('legacy_rme_rollout.admission.approval_reference');

    // Same reference, wider scope. A reference alone must never stretch to cover
    // a branch set nobody approved in that shape.
    legacyRmeApproveWave($reference, ['TKM1', 'LDK2']);

    $decision = opsGate()->decide(superAdmin(), 'TKM1');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_WAVE_BINDING_MISMATCH);
});

it('treats the approved branch set as a set, not a sequence', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');

    $wave = opsWave();
    $reference = (string) config('legacy_rme_rollout.admission.approval_reference');

    // Same members, reversed order — the same approval, so it must still bind.
    legacyRmeApproveWave($reference, ['LDK2', 'TKM1']);
    $wave->forceFill(['approved_branch_codes' => ['TKM1', 'LDK2']])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->cleared)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Enrollment and branch state
// ---------------------------------------------------------------------------

it('refuses a branch that is admitted but never enrolled in the wave', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');

    // Admitted and approved by ROLL-3, but its enrollment row is gone.
    LegacyRmeWaveBranch::query()->where('branch_code', 'LDK2')->delete();

    $decision = opsGate()->decide(superAdmin(), 'LDK2');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ENROLLED);
});

it('refuses a paused branch while the rest of the wave keeps running', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');

    opsWaveBranch('LDK2')->forceFill(['status' => LegacyRmeWaveBranchStatus::PAUSED])->save();

    expect(opsGate()->decide(superAdmin(), 'LDK2')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_BRANCH_PAUSED)
        // Isolation: pausing one clinic must not stop the others.
        ->and(opsGate()->decide(superAdmin(), 'TKM1')->cleared)->toBeTrue();
});

it('refuses a draining branch', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['status' => LegacyRmeWaveBranchStatus::DRAINING])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_BRANCH_DRAINING);
});

// ---------------------------------------------------------------------------
// Wave state — pause, drain, close
// ---------------------------------------------------------------------------

it('refuses every branch while the wave is paused', function () {
    legacyRmeBranch('TKM1');
    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::PAUSED])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_WAVE_PAUSED);
});

it('refuses new intake while the wave is draining', function () {
    legacyRmeBranch('TKM1');
    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::DRAINING])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_WAVE_DRAINING);
});

it('refuses a closed wave', function () {
    legacyRmeBranch('TKM1');
    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::COMPLETED])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_WAVE_CLOSED);
});

it('reports a not-yet-started wave distinctly from a paused one', function () {
    legacyRmeBranch('TKM1');
    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::APPROVED])->save();

    expect(opsGate()->decide(superAdmin(), 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_WAVE_NOT_ACTIVE);
});

// ---------------------------------------------------------------------------
// Operator assignment — the boundary a permission cannot express
// ---------------------------------------------------------------------------

it('refuses an intake operator who holds the permission but no assignment', function () {
    legacyRmeBranch('TKM1');

    // Holds create_legacy_rme_imports and nothing else: permitted to migrate,
    // not cleared for THIS branch.
    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    $decision = opsGate()->decide($operator, 'TKM1');

    expect($decision->denied())->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_OPERATOR_NOT_ASSIGNED);
});

it('clears an intake operator once they are assigned to that branch', function () {
    legacyRmeBranch('TKM1');
    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    legacyRmeAssignOperator($operator, 'TKM1');

    expect(opsGate()->decide($operator, 'TKM1')->cleared)->toBeTrue();
});

it('confines an assigned operator to the branch they were assigned to', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');

    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    legacyRmeAssignOperator($operator, 'TKM1');

    // The point of the whole mechanism: a permission says "may migrate", an
    // assignment says "may migrate THIS clinic".
    expect(opsGate()->decide($operator, 'TKM1')->cleared)->toBeTrue()
        ->and(opsGate()->decide($operator, 'LDK2')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_OPERATOR_NOT_ASSIGNED);
});

it('refuses an operator whose assignment has been revoked', function () {
    legacyRmeBranch('TKM1');
    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    legacyRmeAssignOperator($operator, 'TKM1');

    LegacyRmeWaveOperator::query()
        ->where('user_id', $operator->getKey())
        ->update(['revoked_at' => now()]);

    expect(opsGate()->decide($operator, 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_OPERATOR_NOT_ASSIGNED);
});

it('refuses an unauthenticated actor', function () {
    legacyRmeBranch('TKM1');

    expect(opsGate()->decide(null, 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_OPERATOR_NOT_ASSIGNED);
});

it('clears a wave governor without a separate assignment, because they can self-assign', function () {
    legacyRmeBranch('TKM1');

    // Documented exemption: a holder of manage_legacy_rme_migration_operations
    // can assign themselves to any enrolled branch unilaterally, so requiring
    // the assignment would remove a step rather than a capability. Every other
    // gate still applies to them — proven by the next test.
    $governor = userWith([
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'manage_legacy_rme_migration_operations',
    ]);

    expect(opsGate()->decide($governor, 'TKM1')->cleared)->toBeTrue();
});

it('still refuses a wave governor when the wave itself is paused', function () {
    legacyRmeBranch('TKM1');
    $governor = userWith([
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'manage_legacy_rme_migration_operations',
    ]);

    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::PAUSED])->save();

    // The assignment exemption reaches exactly one gate, and not this one.
    expect(opsGate()->decide($governor, 'TKM1')->code)
        ->toBe(LegacyRmeOperationsDecision::CODE_WAVE_PAUSED);
});

// ---------------------------------------------------------------------------
// Quota
// ---------------------------------------------------------------------------

it('accepts documents while the branch is below its daily ceiling', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => 2])->save();

    $import = opsUpload(superAdmin());

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and($import->migration_wave_id)->toBe((int) opsWave()->getKey());
});

it('refuses a document once the branch daily ceiling is reached', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => 1])->save();

    $actor = superAdmin();
    opsUpload($actor);

    expect(fn () => opsUpload($actor))
        ->toThrow(ValidationException::class);

    // Exactly one document was accepted, and the ledger says so.
    expect(LegacyRmeImport::query()->count())->toBe(1)
        ->and((int) LegacyRmeMigrationQuota::query()->sum('consumed'))->toBe(1);
});

it('refuses a document once the wave-wide daily ceiling is reached', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');
    opsWave()->forceFill(['daily_quota' => 1])->save();

    $actor = superAdmin();
    opsUpload($actor, null, 'TKM1');

    // A different branch, but the same wave-wide budget.
    expect(fn () => opsUpload($actor, null, 'LDK2'))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(1);
});

it('treats a null ceiling as unlimited rather than as zero', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => null])->save();
    opsWave()->forceFill(['daily_quota' => null])->save();

    $actor = superAdmin();
    opsUpload($actor);
    opsUpload($actor);

    expect(LegacyRmeImport::query()->count())->toBe(2);
});

it('treats a zero ceiling as closed', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => 0])->save();

    expect(fn () => opsUpload(superAdmin()))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});

it('does not charge quota again when a document is retried', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => 1])->save();

    $actor = superAdmin();
    $import = opsUpload($actor);

    expect((int) LegacyRmeMigrationQuota::query()->sum('consumed'))->toBe(1);

    // A retry re-renders an ALREADY accepted document. Charging it again would
    // silently cost the branch capacity it never used.
    app(LegacyRmeImportService::class)->queue($import->fresh(), $actor, true);

    expect((int) LegacyRmeMigrationQuota::query()->sum('consumed'))->toBe(1)
        ->and(LegacyRmeImport::query()->count())->toBe(1);
});

it('releases the reserved quota when the staging write rolls back', function () {
    legacyRmeBranch('TKM1');
    opsWaveBranch('TKM1')->forceFill(['daily_quota' => 5])->save();

    $patient = legacyRmeArchivablePatient([], 'TKM1');
    legacyRmeNativeVisit($patient, '2024-05-01');
    $actor = superAdmin();

    // The reservation shares the transaction with the row it counts, so an
    // aborted insert takes its quota with it and needs no compensating write.
    try {
        DB::transaction(function () use ($patient, $actor): void {
            app(LegacyRmeImportService::class)->createFromUpload(
                $patient,
                '2019-04-02',
                $patient->medical_record_number,
                null,
                legacyRmePdfUpload(),
                $actor,
            );

            throw new RuntimeException('abort after the import was written');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LegacyRmeImport::query()->count())->toBe(0)
        ->and((int) LegacyRmeMigrationQuota::query()->sum('consumed'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Ingestion path — the gates compose in the documented order
// ---------------------------------------------------------------------------

it('refuses an upload while the wave is paused and writes no staging row', function () {
    legacyRmeBranch('TKM1');
    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::PAUSED])->save();

    expect(fn () => opsUpload(superAdmin()))->toThrow(ValidationException::class);

    // A refused intake leaves nothing behind: no staging row, no quota, and the
    // refusal is audited under its own action.
    expect(LegacyRmeImport::query()->count())->toBe(0)
        ->and((int) LegacyRmeMigrationQuota::query()->sum('consumed'))->toBe(0)
        ->and(DB::table('sys_audit_logs')
            ->where('action', LegacyRmeAuditEvent::IMPORT_OPERATIONS_REJECTED)
            ->exists())->toBeTrue();
});

it('refuses a retry while the wave is draining', function () {
    legacyRmeBranch('TKM1');
    $actor = superAdmin();
    $import = opsUpload($actor);

    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::DRAINING])->save();

    expect(fn () => app(LegacyRmeImportService::class)->queue($import->fresh(), $actor, true))
        ->toThrow(ValidationException::class);
});

it('still allows publishing an already accepted document while the wave is draining', function () {
    // NORMAL DRAIN preserves the lifecycle of work already accepted — stranding
    // reviewed clinical evidence in staging is a worse outcome than letting an
    // admitted document finish.
    legacyRmeBranch('TKM1');
    $actor = superAdmin();
    $import = opsUpload($actor);

    opsWave()->forceFill(['status' => LegacyRmeWaveStatus::DRAINING])->save();

    // The operations gate governs the START of work, so it is not consulted by
    // publish at all.
    expect(opsGate()->decideForRetry('TKM1')->denied())->toBeTrue()
        ->and($import->fresh()->status)->toBe(LegacyRmeImportStatus::QUEUED);
});

// ---------------------------------------------------------------------------
// Emergency stop — the capability switch withdraws everything
// ---------------------------------------------------------------------------

it('refuses every operational decision once the capability is switched off', function () {
    legacyRmeBranch('TKM1');
    legacyRmeArchiveFlag(false);

    expect(fn () => opsUpload(superAdmin()))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// ROLL-4 narrows; it never widens
// ---------------------------------------------------------------------------

it('cannot rescue a branch that ROLL-3 refuses', function () {
    legacyRmeBranch('TKM1');

    // LDK2 is fully enrolled and ACTIVE in the running wave, so the operations
    // layer has every reason to say yes.
    legacyRmeBranch('LDK2');
    expect(opsWaveBranch('LDK2')->status)->toBe(LegacyRmeWaveBranchStatus::ACTIVE);

    // The patient is created BEFORE the allowlist is narrowed: creating one
    // afterwards would re-admit LDK2 through the fixture and quietly undo the
    // very condition under test.
    $patient = legacyRmeArchivablePatient([], 'LDK2');
    legacyRmeNativeVisit($patient, '2024-05-01');

    // Now ROLL-3's allowlist is narrowed to TKM1 alone. Admission runs first and
    // refuses, and nothing downstream may overturn that.
    legacyRmeAdmittedBranches(['TKM1']);
    legacyRmeApproveWave((string) config('legacy_rme_rollout.admission.approval_reference'), ['TKM1']);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});
