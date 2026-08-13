<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationOperationsService;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationReconciliationService;
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4 — reconciliation, completion sign-off and governance.
 *
 * THE CENTRAL CLAIM UNDER TEST. A migration is not finished because the queue is
 * empty. ROLL-2 shipped with an empty queue AND zero failed jobs while nothing
 * had been rendered at all. Completion here means a balance that adds up, twice,
 * from two different tables — plus a human who signed for it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

function reconWave(string $code = 'TEST-WAVE'): LegacyRmeMigrationWave
{
    return LegacyRmeMigrationWave::query()->where('code', $code)->firstOrFail();
}

function reconBranch(string $branchCode = 'TKM1'): LegacyRmeWaveBranch
{
    return LegacyRmeWaveBranch::query()->where('branch_code', $branchCode)->firstOrFail();
}

function reconService(): LegacyRmeMigrationReconciliationService
{
    return app(LegacyRmeMigrationReconciliationService::class);
}

function reconGovernance(): LegacyRmeWaveGovernanceService
{
    return app(LegacyRmeWaveGovernanceService::class);
}

/** A governance actor holding both manage and approve. */
function reconGovernor(): User
{
    return userWith([
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_rme_migration_operations',
        'manage_legacy_rme_migration_operations',
        'approve_legacy_rme_migration_wave',
    ]);
}

/**
 * Ingest one archive.
 *
 * Ingestion always runs as the suite's standard archive operator. This file is
 * about reconciliation and governance; the 1A branch scope and the ROLL-4
 * ingestion gates are pinned by their own suites, and borrowing a
 * narrower-scoped actor here would make these tests fail for reasons that have
 * nothing to do with what they assert.
 *
 * Each upload carries DISTINCT bytes, or the 1B exact-duplicate gate would
 * refuse the second one and a reconciliation test would count the wrong thing.
 */
function reconUpload(?Patient $patient = null, string $branchCode = 'TKM1'): LegacyRmeImport
{
    if ($patient === null) {
        $patient = legacyRmeArchivablePatient([], $branchCode);
        legacyRmeNativeVisit($patient, '2024-05-01');
    }

    static $sequence = 0;
    $sequence++;

    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        null,
        legacyRmePdfUpload(sprintf('arsip-%d.pdf', $sequence), $sequence),
        superAdmin(),
    );
}

// ---------------------------------------------------------------------------
// The balance sheet
// ---------------------------------------------------------------------------

it('balances every accepted document into exactly one bucket', function () {
    legacyRmeBranch('TKM1');
    $actor = superAdmin();

    $published = reconUpload();
    $failed = reconUpload();
    $inFlight = reconUpload();

    $published->forceFill(['status' => LegacyRmeImportStatus::PUBLISHED])->save();
    $failed->forceFill(['status' => LegacyRmeImportStatus::FAILED])->save();
    // $inFlight stays QUEUED.

    $reconciliation = reconService()->forBranch(reconWave(), reconBranch());

    expect($reconciliation->accepted)->toBe(3)
        ->and($reconciliation->published)->toBe(1)
        ->and($reconciliation->failedUnresolved)->toBe(1)
        ->and($reconciliation->inFlight)->toBe(1)
        // The remainder is the whole point: zero means no document fell out of
        // the count.
        ->and($reconciliation->unexplained)->toBe(0)
        ->and($reconciliation->quotaDrift)->toBe(0)
        ->and($reconciliation->balanced())->toBeTrue();

    expect($inFlight->fresh()->status)->toBe(LegacyRmeImportStatus::QUEUED);
});

it('detects a quota ledger that disagrees with the documents accepted', function () {
    legacyRmeBranch('TKM1');
    reconUpload();

    // Simulate a hand-edited counter, or a future code path that writes a
    // staging row without reserving quota.
    LegacyRmeMigrationQuota::query()->update(['consumed' => 5]);

    $reconciliation = reconService()->forBranch(reconWave(), reconBranch());

    expect($reconciliation->quotaDrift)->toBe(4)
        ->and($reconciliation->balanced())->toBeFalse()
        ->and($reconciliation->blockers())->toContain('QUOTA_LEDGER_DRIFT');
});

it('attributes documents to the wave that accepted them, never to a later one', function () {
    legacyRmeBranch('TKM1');
    $first = reconUpload();

    // A second wave takes over the same branch.
    $laterWave = LegacyRmeMigrationWave::query()->create([
        'code' => 'TEST-WAVE-2',
        'name' => 'Gelombang Kedua',
        'status' => LegacyRmeWaveStatus::ACTIVE,
        'approval_reference' => (string) config('legacy_rme_rollout.admission.approval_reference'),
        'approved_branch_codes' => (array) config('legacy_rme_rollout.admission.approved_branch_codes'),
    ]);
    LegacyRmeWaveBranch::query()->create([
        'wave_id' => $laterWave->getKey(),
        'branch_id' => reconBranch()->branch_id,
        'branch_code' => 'TKM1',
        'status' => LegacyRmeWaveBranchStatus::ACTIVE,
    ]);

    // The earlier document belongs to the earlier wave and stays there. A
    // date-window guess would have pulled it into this one.
    expect(reconService()->forWave($laterWave)->accepted)->toBe(0)
        ->and(reconService()->forWave(reconWave())->accepted)->toBe(1)
        ->and($first->fresh()->migration_wave_id)->toBe((int) reconWave()->getKey());
});

it('surfaces a stale PROCESSING document without rewriting its status', function () {
    legacyRmeBranch('TKM1');
    $import = reconUpload();

    $import->forceFill([
        'status' => LegacyRmeImportStatus::PROCESSING,
        'updated_at' => now()->subHours(6),
    ])->save();

    $reconciliation = reconService()->forBranch(reconWave(), reconBranch());

    expect($reconciliation->staleProcessing)->toBe(1)
        // Reported, never repaired: rewriting a clinical status from a clock is
        // how evidence quietly becomes wrong.
        ->and($import->fresh()->status)->toBe(LegacyRmeImportStatus::PROCESSING);
});

// ---------------------------------------------------------------------------
// Completion sign-off
// ---------------------------------------------------------------------------

it('refuses to complete a branch while work is still in flight', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    reconUpload();

    $branch = reconBranch();
    reconGovernance()->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::DRAINING, 'Menutup migrasi cabang.');

    expect(fn () => reconGovernance()->completeBranch($actor, $branch->fresh(), 'Semua dokumen selesai diverifikasi.'))
        ->toThrow(ValidationException::class);

    expect($branch->fresh()->status)->toBe(LegacyRmeWaveBranchStatus::DRAINING);
});

it('refuses to complete a branch with unresolved failures', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    $import = reconUpload();
    $import->forceFill(['status' => LegacyRmeImportStatus::FAILED])->save();

    $branch = reconBranch();
    reconGovernance()->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::DRAINING, 'Menutup migrasi cabang.');

    expect(fn () => reconGovernance()->completeBranch($actor, $branch->fresh(), 'Semua dokumen selesai diverifikasi.'))
        ->toThrow(ValidationException::class);
});

it('completes a branch whose books balance and freezes the reconciliation onto it', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    $import = reconUpload();
    $import->forceFill(['status' => LegacyRmeImportStatus::PUBLISHED])->save();

    $branch = reconBranch();
    reconGovernance()->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::DRAINING, 'Menutup migrasi cabang.');

    $completed = reconGovernance()->completeBranch(
        $actor,
        $branch->fresh(),
        'Seluruh dokumen cabang telah diverifikasi dan diterbitkan.',
    );

    expect($completed->status)->toBe(LegacyRmeWaveBranchStatus::COMPLETED)
        ->and($completed->completed_by)->toBe((int) $actor->getKey())
        // The evidence that justified the sign-off is frozen onto the row, so a
        // later reader is not left re-deriving it from data that has moved on.
        ->and($completed->reconciliation_snapshot['accepted'])->toBe(1)
        ->and($completed->reconciliation_snapshot['published'])->toBe(1)
        ->and($completed->reconciliation_snapshot['unexplained'])->toBe(0);
});

it('refuses to complete a branch that is still accepting documents', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();

    // Completion is reachable only from DRAINING: signing off a branch that is
    // still ingesting would measure a moving target.
    expect(fn () => reconGovernance()->completeBranch($actor, reconBranch(), 'Selesai semua dokumen.'))
        ->toThrow(ValidationException::class);
});

it('refuses to close a wave while an enrolled branch is unaccounted for', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');
    $actor = reconGovernor();

    $wave = reconWave();
    reconGovernance()->drain($actor, $wave, 'Mengakhiri gelombang migrasi.');

    // TKM1 is signed off; LDK2 is left hanging.
    $tkm = reconBranch('TKM1')->fresh();
    reconGovernance()->completeBranch($actor, $tkm, 'Cabang TKM1 selesai diverifikasi.');

    expect(fn () => reconGovernance()->completeWave($actor, $wave->fresh(), 'Menutup gelombang migrasi.'))
        ->toThrow(ValidationException::class);

    expect($wave->fresh()->status)->toBe(LegacyRmeWaveStatus::DRAINING);
});

it('closes a wave once every enrolled branch is accounted for', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();

    $wave = reconWave();
    reconGovernance()->drain($actor, $wave, 'Mengakhiri gelombang migrasi.');
    reconGovernance()->completeBranch($actor, reconBranch()->fresh(), 'Cabang selesai diverifikasi.');

    $closed = reconGovernance()->completeWave($actor, $wave->fresh(), 'Gelombang migrasi ditutup.');

    expect($closed->status)->toBe(LegacyRmeWaveStatus::COMPLETED)
        ->and($closed->completed_by)->toBe((int) $actor->getKey());
});

// ---------------------------------------------------------------------------
// Governance rules
// ---------------------------------------------------------------------------

it('refuses to register a wave for a branch the deployment approval does not cover', function () {
    legacyRmeBranch('TKM1');
    legacyRmeApproveWave('ROLL-4-APPROVAL', ['TKM1']);

    expect(fn () => reconGovernance()->createWave(
        reconGovernor(),
        'WAVE-OUTSIDE',
        'Gelombang di luar persetujuan',
        ['TKM1', 'LDK2'],
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeMigrationWave::query()->where('code', 'WAVE-OUTSIDE')->exists())->toBeFalse();
});

it('refuses a daily quota above the declarable ceiling', function () {
    legacyRmeBranch('TKM1');

    // A quota is a safety rail; letting someone type a million turns the rail
    // into decoration.
    expect(fn () => reconGovernance()->setBranchQuota(reconGovernor(), reconBranch(), 999999, null))
        ->toThrow(ValidationException::class);
});

it('refuses a governance reason that is too short to be an audit trail', function () {
    legacyRmeBranch('TKM1');

    expect(fn () => reconGovernance()->pause(reconGovernor(), reconWave(), 'ok'))
        ->toThrow(ValidationException::class);
});

it('enforces approver-is-not-creator when separation of duties is switched on', function () {
    legacyRmeBranch('TKM1');
    config()->set('legacy_rme_operations.require_separate_approver', true);

    $creator = reconGovernor();
    $wave = reconGovernance()->createWave($creator, 'WAVE-SOD', 'Gelombang SoD', ['TKM1']);

    expect(fn () => reconGovernance()->approve($creator, $wave))
        ->toThrow(ValidationException::class);

    // A second staffed account can.
    $approver = reconGovernor();
    expect(reconGovernance()->approve($approver, $wave->fresh())->status)
        ->toBe(LegacyRmeWaveStatus::APPROVED);
});

it('refuses to resume a wave whose approval no longer matches the deployment', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    $wave = reconWave();

    reconGovernance()->pause($actor, $wave, 'Dijeda untuk pemeriksaan.');

    // A pause is exactly when someone changes the deployment's approval.
    legacyRmeApproveWave('SOMETHING-ELSE', ['TKM1']);

    expect(fn () => reconGovernance()->resume($actor, $wave->fresh()))
        ->toThrow(ValidationException::class);
});

it('refuses to assign an operator who cannot import at all', function () {
    legacyRmeBranch('TKM1');

    // An assignment narrows; it never grants. A record implying an authority the
    // user does not have would be worse than no record.
    expect(fn () => reconGovernance()->assignOperator(
        reconGovernor(),
        reconWave(),
        userWith(['view_legacy_rme_imports']),
        reconBranch(),
    ))->toThrow(ValidationException::class);
});

it('refuses to assign an operator to a branch from a different wave', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();

    $otherWave = LegacyRmeMigrationWave::query()->create([
        'code' => 'TEST-WAVE-OTHER',
        'name' => 'Gelombang Lain',
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ]);

    // IDOR boundary, enforced in the service so the CLI is covered too.
    expect(fn () => reconGovernance()->assignOperator(
        $actor,
        $otherWave,
        userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']),
        reconBranch(),
    ))->toThrow(ValidationException::class);
});

it('reactivates an existing assignment instead of creating a second row', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    $assignment = reconGovernance()->assignOperator($actor, reconWave(), $operator, reconBranch());
    reconGovernance()->revokeOperator($actor, $assignment);
    reconGovernance()->assignOperator($actor, reconWave(), $operator, reconBranch());

    expect(LegacyRmeWaveOperator::query()->where('user_id', $operator->getKey())->count())->toBe(1)
        ->and(LegacyRmeWaveOperator::query()->where('user_id', $operator->getKey())->first()->revoked_at)->toBeNull();
});

it('refuses an illegal wave transition', function () {
    legacyRmeBranch('TKM1');

    // ACTIVE cannot jump straight to COMPLETED; it drains first, and the drain
    // is what makes the reconciliation measurable.
    expect(fn () => reconGovernance()->completeWave(reconGovernor(), reconWave(), 'Menutup gelombang migrasi.'))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Zero native clinical side effects
// ---------------------------------------------------------------------------

it('creates no native clinical artifacts while migrating an archive', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();

    // The patient and their NATIVE encounter are FIXTURE — they are the
    // reference point the 1A date rules compare against, and they exist before
    // any migration happens. The snapshot is therefore taken AFTER them, so what
    // the deltas measure is the migration itself rather than the setup.
    $patient = legacyRmeArchivablePatient([], 'TKM1');
    legacyRmeNativeVisit($patient, '2024-05-01');

    $before = [
        'visits' => ClinicVisit::query()->count(),
        'records' => MedicalRecord::query()->count(),
        'invoices' => RmeInvoice::query()->count(),
        'lab_candidates' => LabCaseCandidate::query()->count(),
    ];

    $import = reconUpload($patient);
    $import->forceFill(['status' => LegacyRmeImportStatus::PUBLISHED])->save();

    reconGovernance()->transitionBranch($actor, reconBranch(), LegacyRmeWaveBranchStatus::DRAINING, 'Mengakhiri migrasi cabang.');
    reconGovernance()->completeBranch($actor, reconBranch()->fresh(), 'Cabang selesai diverifikasi.');

    // A legacy archive is never an encounter: uploading, publishing and signing
    // off a whole branch must add nothing to the native clinical domain.
    expect(ClinicVisit::query()->count())->toBe($before['visits'])
        ->and(MedicalRecord::query()->count())->toBe($before['records'])
        ->and(RmeInvoice::query()->count())->toBe($before['invoices'])
        ->and(LabCaseCandidate::query()->count())->toBe($before['lab_candidates']);
});

// ---------------------------------------------------------------------------
// The operations report
// ---------------------------------------------------------------------------

it('reports per-branch progress without inventing a completion percentage', function () {
    legacyRmeBranch('TKM1');
    reconUpload();

    $overview = app(LegacyRmeMigrationOperationsService::class)->overview(reconWave());
    $branch = collect($overview['branches'])->firstWhere('branch_code', 'TKM1');

    // Nobody counted the paper archive, so there is no denominator and therefore
    // no percentage. A fabricated one would make the whole panel a fiction.
    expect($branch['planned_document_count'])->toBeNull()
        ->and($branch['completion_percent'])->toBeNull()
        ->and($branch['accepted'])->toBe(1);
});

it('reports a completion percentage once a human has counted the archive', function () {
    legacyRmeBranch('TKM1');
    $actor = reconGovernor();
    reconUpload();

    reconGovernance()->setBranchQuota($actor, reconBranch(), null, 4);
    LegacyRmeImport::query()->update(['status' => LegacyRmeImportStatus::PUBLISHED]);

    $overview = app(LegacyRmeMigrationOperationsService::class)->overview(reconWave());
    $branch = collect($overview['branches'])->firstWhere('branch_code', 'TKM1');

    expect($branch['planned_document_count'])->toBe(4)
        ->and($branch['completion_percent'])->toBe(25);
});

it('reports measured storage rather than an estimate', function () {
    legacyRmeBranch('TKM1');
    reconUpload();

    $footprint = app(LegacyRmeMigrationOperationsService::class)->storageFootprint(reconWave());

    expect($footprint['measurable'])->toBeTrue()
        ->and($footprint['documents'])->toBe(1)
        ->and($footprint['source_bytes'])->toBeGreaterThan(0)
        ->and($footprint['average_source_bytes'])->toBeGreaterThan(0);
});

it('keeps the QA sample free of patient identity', function () {
    legacyRmeBranch('TKM1');
    $import = reconUpload();

    LegacyRmeRecord::query()->create([
        'uuid' => (string) Str::uuid(),
        'patient_id' => $import->patient_id,
        'origin_branch_id' => $import->origin_branch_id,
        'rme_date' => '2019-04-02',
        'latest_rme_date' => '2019-04-02',
        'source_disk' => $import->source_disk,
        'source_pdf_path' => $import->source_pdf_path,
        'source_pdf_sha256' => $import->source_pdf_sha256,
        'page_count' => 1,
        'status' => LegacyRmeRecordStatus::PUBLISHED,
        'source_import_id' => $import->getKey(),
        'published_at' => now(),
    ]);

    $sample = app(LegacyRmeMigrationOperationsService::class)->qaSample(reconWave());

    expect($sample)->toHaveCount(1);

    // A completeness check, not a clinical review: structural keys only.
    expect(array_keys($sample[0]))->toBe([
        'record_id', 'branch_code', 'rme_date', 'latest_rme_date', 'page_count', 'status', 'source_present',
    ]);
});
