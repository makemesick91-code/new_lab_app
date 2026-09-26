<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\Patient\Models\Patient;
use App\Support\Legacy\LegacyVerificationMode;
use App\Support\Legacy\LegacyVisitAttestation;
use App\Support\Legacy\LegacyVisitBindingService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — the ODONTOGRAM half.
 *
 * WRITTEN AGAINST THIS ARCHIVE'S REAL GOVERNANCE, NOT RME'S.
 *
 * The legacy odontogram archive has NO `SeparatePublisherGuard`: its
 * `review()`/`publish()` never compare the actor against `uploaded_by`. Its
 * duty separation is PERMISSION-BASED — `review_legacy_odontogram_imports` and
 * `publish_legacy_odontogram_imports` are separate named permissions, and the
 * front-desk cohort holds neither.
 *
 * So this file deliberately does NOT assert an identity-level
 * "uploader cannot publish" rule here. Asserting one would be testing a control
 * that does not exist, and adding one purely for symmetry with RME was
 * explicitly ruled out. What IS pinned is the control that genuinely governs
 * this archive: the front-desk cohort cannot review or publish at all.
 *
 * ONE DATE, NOT A RANGE. This archive models a document as a single
 * representative clinical date, so there is no `verified_latest_date` to test.
 */
beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/** A front-desk operator: may file and attest, may NOT review or publish. */
function vbpOdoOperator(): User
{
    $user = lodoOperator([
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
        'verify_legacy_dates_at_ingestion',
        'view_clinic_visits',
        'manage_clinic_visits',
    ]);

    $user->forceFill(['branch_id' => lodoBranch()->id])->save();

    return $user;
}

function vbpOdoVisit(Patient $patient, string $visitDate = '2024-06-10'): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => lodoBranch()->id,
        'visit_date' => $visitDate,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
}

function vbpOdoAttestation(ClinicVisit $visit, User $actor): LegacyVisitAttestation
{
    return app(LegacyVisitBindingService::class)->resolve((int) $visit->getKey(), $actor, true);
}

function vbpOdoStage(
    User $actor,
    string $documentDate = '2019-06-01',
    string $visitDate = '2024-06-10',
    ?Patient $patient = null,
): LegacyOdontogramImport {
    $patient ??= lodoPatient();
    $visit = vbpOdoVisit($patient, $visitDate);

    return app(LegacyOdontogramImportService::class)->createFromUpload(
        $patient,
        $documentDate,
        lodoPdfUpload(),
        $actor,
        vbpOdoAttestation($visit, $actor),
    );
}

/*
|--------------------------------------------------------------------------
| The attestation itself — one date, bound to the file
|--------------------------------------------------------------------------
*/

it('records a single-date attestation bound to the visit and the source hash', function () {
    $operator = vbpOdoOperator();
    $import = vbpOdoStage($operator)->refresh();

    expect($import->verification_mode)->toBe(LegacyVerificationMode::VISIT_PREVERIFIED)
        ->and($import->isVisitPreverified())->toBeTrue()
        ->and($import->hasCompleteVisitAttestation())->toBeTrue()
        ->and((int) $import->verified_by)->toBe((int) $operator->getKey())
        ->and($import->verified_selected_date->toDateString())->toBe('2019-06-01')
        ->and($import->verification_visit_date->toDateString())->toBe('2024-06-10')
        ->and($import->verified_source_sha256)->toBe($import->source_pdf_sha256);
});

it('has no latest-date column, because this archive models one date', function () {
    // Pinning the ABSENCE on purpose: a future "symmetry with RME" change that
    // adds a range here would create a field with no source of truth.
    $import = vbpOdoStage(vbpOdoOperator())->refresh();

    expect($import->getAttributes())->not->toHaveKey('verified_latest_date');
});

it('leaves the attestation columns null on the ordinary backlog path', function () {
    $patient = lodoPatient();
    $import = lodoStageImport($patient, '2019-06-01', vbpOdoOperator())->refresh();

    expect($import->verification_mode)->toBeNull()
        ->and($import->isVisitPreverified())->toBeFalse()
        ->and($import->verified_source_sha256)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The strict visit boundary
|--------------------------------------------------------------------------
*/

it('refuses a document dated ON the visit date', function () {
    expect(fn () => vbpOdoStage(vbpOdoOperator(), '2024-06-10', '2024-06-10'))
        ->toThrow(ValidationException::class);
});

it('refuses a document dated AFTER the visit date', function () {
    expect(fn () => vbpOdoStage(vbpOdoOperator(), '2024-07-01', '2024-06-10'))
        ->toThrow(ValidationException::class);
});

it('accepts a document strictly before the visit date', function () {
    expect(vbpOdoStage(vbpOdoOperator(), '2024-06-09', '2024-06-10')->exists)->toBeTrue();
});

it('names the visit boundary with its own stable rule code', function () {
    $result = app(LegacyOdontogramDateRuleService::class)
        ->evaluate(lodoPatient(), '2024-06-10', '2024-06-10');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_VISIT);
});

it('still enforces the native odontogram boundary on the visit-bound path', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2021-03-10');

    expect(fn () => vbpOdoStage(vbpOdoOperator(), '2022-01-01', '2024-06-10', $patient))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Duty separation — permission-based, which is what this archive actually has
|--------------------------------------------------------------------------
*/

it('gives the date-attestation cohort no review, publish or void authority', function () {
    // THE odontogram control. There is no identity SOD here, so the role split
    // IS the maker-checker boundary — if this ever loosens, the archive loses
    // its only separation.
    $operator = vbpOdoOperator();

    expect($operator->can('verify_legacy_dates_at_ingestion'))->toBeTrue()
        ->and($operator->can('create_legacy_odontogram_imports'))->toBeTrue()
        ->and($operator->can('review_legacy_odontogram_imports'))->toBeFalse()
        ->and($operator->can('publish_legacy_odontogram_imports'))->toBeFalse()
        ->and($operator->can('void_legacy_odontogram_records'))->toBeFalse();
});

it('confirms this archive has no identity-level separate-publisher guard', function () {
    // AN EXPLICIT RECORD OF THE AUDITED DIFFERENCE from the RME archive, so a
    // later reader does not assume the RME guard applies here, and a later
    // author does not "restore" a rule that never existed.
    //
    // Asserted against the real source of the publish service rather than a
    // class_exists() guess: what matters is that publish/review do not compare
    // the actor with the uploader, which is exactly what the RME guard does.
    $source = file_get_contents(
        base_path('app/Modules/LegacyOdontogram/Services/LegacyOdontogramPublishService.php')
    );

    expect($source)
        ->not->toContain('SeparatePublisherGuard')
        ->not->toContain('uploaded_by === ')
        ->not->toContain('$locked->uploaded_by === $actor');

    // And the RME archive DOES have it — the asymmetry is real and deliberate.
    $rmeSource = file_get_contents(
        base_path('app/Modules/LegacyRme/Services/LegacyRmePublishService.php')
    );

    expect($rmeSource)->toContain('SeparatePublisherGuard');
});

/*
|--------------------------------------------------------------------------
| Binding integrity
|--------------------------------------------------------------------------
*/

it('refuses an upload whose attestation names a different patient', function () {
    $operator = vbpOdoOperator();
    $patientA = lodoPatient();
    $patientB = lodoPatient();
    $visitA = vbpOdoVisit($patientA);

    expect(fn () => app(LegacyOdontogramImportService::class)->createFromUpload(
        $patientB,
        '2019-06-01',
        lodoPdfUpload(),
        $operator,
        vbpOdoAttestation($visitA, $operator),
    ))->toThrow(ValidationException::class);
});

it('never creates a visit while ingesting a legacy odontogram', function () {
    $operator = vbpOdoOperator();
    $patient = lodoPatient();
    $visit = vbpOdoVisit($patient);

    $before = ClinicVisit::count();

    app(LegacyOdontogramImportService::class)->createFromUpload(
        $patient,
        '2019-06-01',
        lodoPdfUpload(),
        $operator,
        vbpOdoAttestation($visit, $operator),
    );

    expect(ClinicVisit::count())->toBe($before);
});

it('keeps the archive branch RM-derived rather than adopting the visit branch', function () {
    $import = vbpOdoStage(vbpOdoOperator())->refresh();

    expect($import->origin_branch_id)->toBe((int) lodoBranch()->id)
        ->and($import->source_branch_code)->toBe('TLK1');
});
