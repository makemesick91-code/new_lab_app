<?php

declare(strict_types=1);

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeAdmissionDecision;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-3 — controlled multi-branch admission.
 *
 * THE DEFECT UNDER TEST. ROLL-2 declared one pilot branch that only the
 * readiness REPORT ever read. No runtime path consulted it, so switching the
 * capability on cleared EVERY RME-enabled branch to import at once. These tests
 * pin the server-side gate that replaced that arrangement, and in particular
 * that it cannot be widened by a request, by a prefix, or by an empty config.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

/** Resolve a branch the way the RM resolver would, without touching a patient. */
function admissionResolutionFor(string $branchCode): LegacyRmeBranchResolution
{
    return LegacyRmeBranchResolution::success(1, $branchCode, 'Cabang '.$branchCode);
}

function admissionService(): LegacyRmeBranchAdmissionService
{
    return app(LegacyRmeBranchAdmissionService::class);
}

// ---------------------------------------------------------------------------
// Capability × admission matrix
// ---------------------------------------------------------------------------

it('denies an admitted branch while the capability is off', function () {
    legacyRmeArchiveFlag(false);
    legacyRmeAdmittedBranches(['TLK1']);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_FEATURE_DISABLED);
});

it('admits a branch that is on the allowlist while the capability is on', function () {
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave('ROLL-3-TEST-APPROVAL', ['TLK1']);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    expect($decision->admitted)->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_ADMITTED);
});

it('denies a branch that is not on the allowlist', function () {
    legacyRmeAdmittedBranches(['TLK1']);

    $decision = admissionService()->decide(admissionResolutionFor('LDK2'));

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_BRANCH_NOT_ADMITTED);
});

it('fails closed when the allowlist is empty', function () {
    legacyRmeAdmittedBranches([]);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_NO_BRANCH_ADMITTED);
});

it('never admits the administrative MAIN branch even when it is declared', function () {
    legacyRmeAdmittedBranches(['MAIN', 'TLK1']);

    $decision = admissionService()->decide(admissionResolutionFor('MAIN'));

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_BRANCH_FORBIDDEN);
});

it('denies when the branch could not be derived from the Nomor RM', function () {
    legacyRmeAdmittedBranches(['TLK1']);

    $unresolved = LegacyRmeBranchResolution::failure(
        LegacyRmeBranchResolution::CODE_RM_MISSING,
        'Nomor RM pasien kosong.',
    );

    $decision = admissionService()->decide($unresolved);

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_BRANCH_UNRESOLVED);
});

// ---------------------------------------------------------------------------
// Exact-token matching — a prefix or a substring must never widen a rollout
// ---------------------------------------------------------------------------

it('matches an admitted branch code exactly and rejects prefixes or extensions', function (string $admitted, string $candidate, bool $expected) {
    legacyRmeAdmittedBranches([$admitted]);
    legacyRmeApproveWave('ROLL-3-TEST-APPROVAL', [$admitted]);

    expect(admissionService()->decide(admissionResolutionFor($candidate))->admitted)->toBe($expected);
})->with([
    'exact match admits' => ['TLK1', 'TLK1', true],
    'a shorter prefix does not admit the longer code' => ['TLK', 'TLK1', false],
    'an admitted code does not admit its own prefix' => ['TLK1', 'TLK', false],
    'a suffixed code is a different branch' => ['TLK1', 'TLK1-EXTRA', false],
    'an admitted suffixed code does not admit the base' => ['TLK1-EXTRA', 'TLK1', false],
    'case is normalized, not loosened' => ['TLK1', 'tlk1', true],
    'a deprecated code names the same branch, in any case' => ['TLK1', 'tkm1', true],
    'a deprecated declaration still admits the canonical branch' => ['TKM1', 'TLK1', true],
]);

it('normalizes a declared allowlist into canonical tokens without re-parsing it later', function () {
    config()->set('legacy_rme_rollout.admission.admitted_branch_codes', [' tkm1 ', 'LDK2', 'tkm1', '']);

    expect(admissionService()->admittedBranchCodes())->toEqualCanonicalizing(['TLK1', 'LDK2']);
});

// ---------------------------------------------------------------------------
// Admission is server-side: no client input may influence it
// ---------------------------------------------------------------------------

it('ignores a submitted branch id and admits only the RM-derived branch', function () {
    $admittedBranch = legacyRmeBranch('LDK2', 'Cabang Landak');
    legacyRmeAdmittedBranches(['LDK2']);

    // The patient's RM says TLK1; the request claims the admitted LDK2.
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['LDK2']);

    $actor = superAdmin();

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        (int) $admittedBranch->getKey(),
        legacyRmePdfUpload(),
        $actor,
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Ingestion enforcement — the gate is real, not advisory
// ---------------------------------------------------------------------------

it('refuses an upload for a non-admitted branch and stores nothing at all', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['LDK2']);

    $actor = superAdmin();

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        $actor,
    ))->toThrow(ValidationException::class);

    // No staging row, no orphan file, no queued render job.
    expect(LegacyRmeImport::query()->count())->toBe(0)
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe([]);

    Bus::assertNothingDispatched();
});

it('records a refused intake as an admission rejection, not as a created import', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches([]);

    try {
        app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        );
    } catch (ValidationException) {
        // expected
    }

    $actions = DB::table('sys_audit_logs')->pluck('action')->all();

    expect($actions)->toContain(LegacyRmeAuditEvent::IMPORT_ADMISSION_REJECTED)
        ->and($actions)->not->toContain(LegacyRmeAuditEvent::IMPORT_CREATED);
});

it('never writes a patient identifier into the admission audit trail', function () {
    $patient = legacyRmeArchivablePatient(['medical_record_number' => 'DG-TLK1-2024-9985'], 'TLK1');
    legacyRmeAdmittedBranches([]);

    try {
        app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        );
    } catch (ValidationException) {
        // expected
    }

    $payloads = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::IMPORT_ADMISSION_REJECTED)
        ->get()
        ->map(static fn ($row): string => (string) $row->old_values.' '.(string) $row->new_values)
        ->implode(' ');

    expect($payloads)->not->toContain('DG-TLK1-2024-9985')
        ->and($payloads)->not->toContain((string) $patient->name);
});

it('allows an upload once the branch is admitted', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['TLK1']);

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED);
});

// ---------------------------------------------------------------------------
// NORMAL DRAIN vs EMERGENCY STOP — two different operational controls
// ---------------------------------------------------------------------------

it('denies a NEW upload for a branch that was drained out of the wave', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['TLK1']);

    app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    // The wave rolls back: TLK1 is removed from admission.
    legacyRmeAdmittedBranches(['LDK2']);

    $second = legacyRmeArchivablePatient(['medical_record_number' => 'DG-TLK1-2024-1002'], 'TLK1');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $second,
        '2019-05-02',
        $second->medical_record_number,
        null,
        legacyRmePdfUpload('kedua.pdf'),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    // The already-admitted import survives untouched — draining preserves
    // evidence, it never deletes or corrupts it.
    expect(LegacyRmeImport::query()->count())->toBe(1);
});

it('refuses to re-queue a retry for a branch that was drained out of the wave', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['TLK1']);

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    legacyRmeAdmittedBranches([]);

    expect(fn () => app(LegacyRmeImportService::class)->queue(
        $import->fresh(),
        superAdmin(),
        true,
    ))->toThrow(ValidationException::class);
});

it('separates emergency stop from drain: the capability switch withdraws everything', function () {
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave('ROLL-3-TEST-APPROVAL', ['TLK1']);

    expect(admissionService()->decide(admissionResolutionFor('TLK1'))->admitted)->toBeTrue();

    // EMERGENCY STOP — the global capability, not the wave allowlist.
    legacyRmeArchiveFlag(false);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    expect($decision->admitted)->toBeFalse()
        // The reported reason is the capability, not the wave: an operator must
        // be able to tell an incident stop from a routine rollback.
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_FEATURE_DISABLED);
});

// ---------------------------------------------------------------------------
// Configuration posture
// ---------------------------------------------------------------------------

it('ships with enforcement on and no branch admitted by default', function () {
    $shipped = require base_path('config/legacy_rme_rollout.php');

    expect($shipped['admission']['enforced'])->toBeTrue()
        ->and($shipped['admission']['admitted_branch_codes'])->toBe([])
        ->and($shipped['admission']['forbidden_branch_codes'])->toContain('MAIN');
});

it('resolves the allowlist from config rather than reading the environment at runtime', function () {
    $source = file_get_contents(app_path('Modules/LegacyRme/Services/LegacyRmeBranchAdmissionService.php'));

    // ROLL-1 rule: a runtime env() read returns nothing under config:cache, so
    // the value would silently fall back to the default and the gate would
    // quietly change behaviour on a cached deployment.
    expect($source)->not->toContain('env(');
});

// ---------------------------------------------------------------------------
// ROLL-3 CORRECTIVE — a wave must carry its OWN approval, bound to its scope.
//
// Found during the Wave-1 checkpoint: admitting ATG3/LDK2/SPN4 while the only
// approval on record was ROLL-2's single-branch TLK1 pilot produced a GREEN
// readiness report that misdescribed what was authorized. That is the same
// "config nobody enforces" defect ROLL-3 exists to remove, one level up.
// ---------------------------------------------------------------------------

// (A) no admitted branches + no approval reference → the closed state is valid
it('needs no wave approval while nothing is admitted', function () {
    legacyRmeAdmittedBranches([]);
    legacyRmeApproveWave('', []);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    // Denied because nothing is admitted — never because approval is missing.
    expect($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_NO_BRANCH_ADMITTED);
});

// (B) admitted branches + blank approval → FAIL CLOSED
it('refuses an admitted branch when the wave has no approval reference', function () {
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave('', ['TLK1']);

    $decision = admissionService()->decide(admissionResolutionFor('TLK1'));

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED);
});

// (C) whitespace-only approval is not an approval
it('treats a whitespace-only approval reference as absent', function () {
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave("   \t  ", ['TLK1']);

    expect(admissionService()->approvalReference())->toBe('')
        ->and(admissionService()->decide(admissionResolutionFor('TLK1'))->code)
        ->toBe(LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED);
});

// (D) admitted + current approval covering it → PASS
it('admits a branch covered by the current wave approval', function () {
    legacyRmeAdmittedBranches(['ATG3', 'LDK2', 'SPN4']);
    legacyRmeApproveWave('ROLL-3-WAVE-1-OWNER-APPROVAL', ['ATG3', 'LDK2', 'SPN4']);

    foreach (['ATG3', 'LDK2', 'SPN4'] as $code) {
        expect(admissionService()->decide(admissionResolutionFor($code))->admitted)->toBeTrue();
    }
});

// (E) ROLL-2's historical single-branch approval must not authorize a later wave
it('never lets the historical ROLL-2 pilot approval authorize a different wave', function () {
    // Exactly the production state found at the Wave-1 checkpoint.
    config()->set('legacy_rme_rollout.pilot_scope.approved', true);
    config()->set('legacy_rme_rollout.pilot_scope.approval_reference', 'ROLL-2-OWNER-APPROVAL-2026-08-11');
    config()->set('legacy_rme_rollout.pilot_scope.branch_code', 'TLK1');

    // Wave-1 admits three OTHER branches and records no approval of its own.
    legacyRmeAdmittedBranches(['ATG3', 'LDK2', 'SPN4']);
    legacyRmeApproveWave('', []);

    foreach (['ATG3', 'LDK2', 'SPN4'] as $code) {
        expect(admissionService()->decide(admissionResolutionFor($code))->code)
            ->toBe(LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED);
    }

    // Even pasting the ROLL-2 reference does not help: its scope is TLK1.
    legacyRmeApproveWave('ROLL-2-OWNER-APPROVAL-2026-08-11', ['TLK1']);

    expect(admissionService()->decide(admissionResolutionFor('ATG3'))->code)
        ->toBe(LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED);
});

// (F) widening the admitted set after approval → FAIL CLOSED for the new branch
it('fails closed when the admitted set is widened beyond the approved scope', function () {
    legacyRmeAdmittedBranches(['ATG3', 'LDK2']);
    legacyRmeApproveWave('ROLL-3-WAVE-1-OWNER-APPROVAL', ['ATG3', 'LDK2']);

    expect(admissionService()->decide(admissionResolutionFor('ATG3'))->admitted)->toBeTrue();

    // A fourth branch is slipped into the allowlist; the approval is untouched.
    legacyRmeAdmittedBranches(['ATG3', 'LDK2', 'SPN4']);

    expect(admissionService()->unapprovedAdmittedBranchCodes())->toBe(['SPN4'])
        // The new branch is refused...
        ->and(admissionService()->decide(admissionResolutionFor('SPN4'))->code)
        ->toBe(LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED)
        // ...while the genuinely approved ones keep working.
        ->and(admissionService()->decide(admissionResolutionFor('ATG3'))->admitted)->toBeTrue();
});

it('refuses an upload for an admitted but unapproved branch, storing nothing', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave('', []);

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

it('ships with no wave approval recorded by default', function () {
    $shipped = require base_path('config/legacy_rme_rollout.php');

    expect($shipped['admission']['approval_reference'])->toBe('')
        ->and($shipped['admission']['approved_branch_codes'])->toBe([]);
});
