<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR1 — a FINAL RME sheet is locked
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 (rule 240 §1): a finalized medical record is no longer
| editable; a correction goes through a supervised amendment (built in later PRs
| of the same release). This reverses Sprint 59 for the sheet: its SOAP fields,
| notes, structured diagnoses and the emergency diagnosis override.
|
| Three layers: the route (manage_clinic_visits), MedicalRecordPolicy::editSheet
| (scope first, then final ⇒ deny) and the services, which re-decide "final ⇒
| refuse" on the row they lock inside the transaction (visit row first, rule 223
| §1 item 6). The service layer binds Super Admin, whom Gate::before lets past the
| policy (CG-06). The handwriting book is NOT sealed here (PR2).
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both runner variants
| and declared in critical_gate_mandatory_suites (CG-19). Helper prefix `m33l` is
| unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRequirementOverride;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->author = rmeClinicalAuthor([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'override_diagnosis_requirement',
    ]);
});

/** A sheet on a live, consented encounter, with the handwriting finalization needs. */
function m33lSheet(Branch $branch, array $attributes = []): array
{
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    rmeActiveConsentedEncounter($visit);

    $record = MedicalRecord::factory()->create($attributes + [
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    return [$visit->fresh(), $record->fresh()];
}

/** Finalize through the canonical service, as the given author. */
function m33lFinalize(MedicalRecord $record, $actor): MedicalRecord
{
    test()->actingAs($actor);

    return app(MedicalRecordService::class)->finalize($record->fresh());
}

function m33lDiagnosis(MedicalRecord $record, string $role = MedicalRecordDiagnosis::ROLE_PRIMARY): MedicalRecordDiagnosis
{
    return MedicalRecordDiagnosis::create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $record->clinic_visit_id,
        'branch_id' => $record->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => $role,
        'diagnosed_at' => now(),
    ]);
}

/**
 * True only when the write is refused BY THE FINAL LOCK — a ValidationException
 * keyed `medical_record` carrying the lock message. Any other refusal (consent,
 * branch, validation) returns false, so it can never pass as the lock.
 */
function m33lLocked(callable $write): bool
{
    try {
        $write();
    } catch (ValidationException $e) {
        return ($e->errors()['medical_record'][0] ?? null) === MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED;
    }

    return false;
}

/** @return array<string, mixed> */
function m33lSnapshot(MedicalRecord $record): array
{
    $fresh = $record->fresh();

    return $fresh->only(['subjective', 'objective', 'assessment', 'plan', 'notes', 'status', 'finalized_by'])
        + ['finalized_at' => $fresh->finalized_at?->toIso8601String(), 'updated_at' => $fresh->updated_at?->toIso8601String()];
}

// ─── Happy path: a draft stays editable and finalization still works ─────────

it('keeps a DRAFT sheet editable through the service and HTTP PATCH, then finalizes it', function () {
    [$visit, $record] = m33lSheet($this->branch);
    $this->actingAs($this->author);

    $updated = app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'catatan draft']);
    expect($updated->notes)->toBe('catatan draft');

    $this->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['subjective' => 'keluhan draft'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $final = m33lFinalize($record, $this->author);

    expect($final->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($final->finalized_at)->not->toBeNull()
        ->and($final->finalized_by)->toBe($this->author->id)
        ->and($final->notes)->toBe('catatan draft')
        ->and($final->subjective)->toBe('keluhan draft')
        // Finalizing a document never moves the visit (AIR-RMC-05).
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// ─── The sheet: service and HTTP ─────────────────────────────────────────────

it('refuses updateDraft on a FINAL sheet in the service and writes nothing', function () {
    [, $record] = m33lSheet($this->branch, ['subjective' => 'keluhan awal', 'notes' => 'catatan awal']);
    $final = m33lFinalize($record, $this->author);
    $before = m33lSnapshot($final);

    expect(m33lLocked(fn () => app(MedicalRecordService::class)->updateDraft($final, [
        'subjective' => 'diubah', 'objective' => 'diubah', 'assessment' => 'diubah', 'plan' => 'diubah', 'notes' => 'diubah',
    ])))->toBeTrue()
        ->and(m33lSnapshot($final))->toBe($before);
});

it('refuses HTTP PATCH on a FINAL sheet with 403, changes no field and blanks nothing', function () {
    [$visit, $record] = m33lSheet($this->branch, ['subjective' => 'keluhan awal', 'plan' => 'rencana awal']);
    m33lFinalize($record, $this->author);
    $before = m33lSnapshot($record);

    $this->actingAs($this->author)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'revisi setelah final'])
        ->assertForbidden();

    expect(m33lSnapshot($record))->toBe($before)
        ->and($record->fresh()->subjective)->toBe('keluhan awal')
        ->and($record->fresh()->plan)->toBe('rencana awal');
});

it('locks a sheet that was already final before this rule by its status alone (no backfill)', function () {
    // Production rows finalized under Sprint 59 carry status=final; nothing else.
    [$visit, $record] = m33lSheet($this->branch, ['status' => MedicalRecord::STATUS_FINAL, 'notes' => 'lama']);
    $this->actingAs($this->author);

    expect(m33lLocked(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'baru'])))->toBeTrue()
        ->and($record->fresh()->notes)->toBe('lama');

    $this->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'baru'])->assertForbidden();
});

it('decides the lock on the row it locked, not on a stale copy loaded before the finalize', function () {
    [, $record] = m33lSheet($this->branch, ['notes' => 'awal']);
    $stale = $record->fresh();
    m33lFinalize($record, $this->author);

    expect($stale->status)->toBe(MedicalRecord::STATUS_DRAFT)
        ->and(m33lLocked(fn () => app(MedicalRecordService::class)->updateDraft($stale, ['notes' => 'menimpa final'])))->toBeTrue()
        ->and($record->fresh()->notes)->toBe('awal');
});

it('never rewrites a final sheet when it is finalized again, even from a stale draft copy', function () {
    [, $record] = m33lSheet($this->branch);
    $stale = $record->fresh();
    m33lFinalize($record, $this->author);
    $first = $record->fresh();

    $other = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
    $this->travel(5)->minutes();
    $this->actingAs($other);
    // The stale copy still reads `draft`; the service must re-read the row.
    $again = app(MedicalRecordService::class)->finalize($stale);

    expect($again->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($record->fresh()->finalized_by)->toBe($first->finalized_by)
        ->and($record->fresh()->finalized_at?->toIso8601String())->toBe($first->finalized_at?->toIso8601String());
});

// ─── Structured diagnoses and the emergency override ─────────────────────────

it('refuses recording, promoting and removing a structured diagnosis on a FINAL sheet (service)', function () {
    [, $record] = m33lSheet($this->branch);
    $primary = m33lDiagnosis($record);
    $secondary = m33lDiagnosis($record, MedicalRecordDiagnosis::ROLE_SECONDARY);
    m33lFinalize($record, $this->author);
    $service = app(MedicalRecordDiagnosisService::class);

    expect(m33lLocked(fn () => $service->record($record->fresh(), [
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
    ], $this->author)))->toBeTrue()
        ->and(m33lLocked(fn () => $service->makePrimary($record->fresh(), $secondary, $this->author)))->toBeTrue()
        ->and(m33lLocked(fn () => $service->remove($record->fresh(), $primary, $this->author)))->toBeTrue();

    expect(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(2)
        ->and($primary->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_PRIMARY)
        ->and($secondary->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_SECONDARY);
});

it('refuses the diagnosis routes on a FINAL sheet with 403 and writes nothing', function () {
    [$visit, $record] = m33lSheet($this->branch);
    $primary = m33lDiagnosis($record);
    $secondary = m33lDiagnosis($record, MedicalRecordDiagnosis::ROLE_SECONDARY);
    m33lFinalize($record, $this->author);

    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.diagnoses.store', [$visit, $record]), [
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
        ])->assertForbidden();
    $this->post(route('rme.visits.medical-record.diagnoses.make-primary', [$visit, $record, $secondary]))->assertForbidden();
    $this->delete(route('rme.visits.medical-record.diagnoses.destroy', [$visit, $record, $primary]))->assertForbidden();

    expect(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(2)
        ->and($primary->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_PRIMARY);
});

it('still records a structured diagnosis on a DRAFT sheet (positive control)', function () {
    [$visit, $record] = m33lSheet($this->branch);

    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.diagnoses.store', [$visit, $record]), [
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
        ])->assertRedirect()->assertSessionHasNoErrors();

    expect(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(1);
});

it('refuses the emergency diagnosis override on a FINAL sheet (service and HTTP)', function () {
    [$visit, $record] = m33lSheet($this->branch);
    app(DiagnosisRolloutService::class)->setMode(
        $this->branch,
        DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'pilot enforcement cabang ini untuk uji kunci final',
        userWith(['configure_diagnosis_rollout']),
    );
    // The sheet was finalized before the pilot switched on; it is final now.
    $record->forceFill(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()])->save();

    expect(m33lLocked(fn () => app(DiagnosisRolloutService::class)
        ->grantOverride($record->fresh(), 'Pasien emergensi, koding menyusul nanti.', $this->author)))->toBeTrue();

    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.diagnosis-override', [$visit, $record]), [
            'reason' => 'Pasien emergensi, koding menyusul nanti.',
        ])->assertForbidden();

    expect(DiagnosisRequirementOverride::query()->where('medical_record_id', $record->id)->count())->toBe(0);
});

// ─── Super Admin: Gate::before passes the policy, the service still refuses ───

it('refuses a Super Admin at the service layer on a FINAL sheet (sheet, diagnoses, HTTP)', function () {
    [$visit, $record] = m33lSheet($this->branch, ['notes' => 'awal']);
    $primary = m33lDiagnosis($record);
    m33lFinalize($record, $this->author);
    $admin = superAdmin();
    $this->actingAs($admin);

    expect(m33lLocked(fn () => app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'admin'])))->toBeTrue()
        ->and(m33lLocked(fn () => app(MedicalRecordDiagnosisService::class)->remove($record->fresh(), $primary, $admin)))->toBeTrue();

    // The policy is skipped for Super Admin, so the HTTP refusal is the service's.
    $this->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'admin via http'])
        ->assertRedirect()
        ->assertSessionHasErrors(['medical_record' => MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED]);
    $this->post(route('rme.visits.medical-record.diagnoses.store', [$visit, $record]), [
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
    ])->assertSessionHasErrors('medical_record');

    expect($record->fresh()->notes)->toBe('awal')
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(1);
});

// ─── Rule 240 §1.8: the final lock answers before the PTM (rule 241 §2) ──────

/**
 * A sheet on a live encounter whose PTM is executed, awaiting the doctor (patient
 * signature only) or awaiting the patient (none), as fixtures: the PTM state
 * machine is pinned through the services by RmeVisitConsentTwoSignatureGateTest;
 * here the subject is only which refusal answers.
 */
function m33lSheetWithPtm(Branch $branch, string $ptm, string $status): array
{
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS]);

    if ($ptm === RmeVisitConsentService::PTM_EXECUTED) {
        rmeSignedConsentFor($visit);
    } elseif ($ptm === RmeVisitConsentService::PTM_AWAITING_DOCTOR) {
        RmeVisitConsent::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien',
        ]);
    }

    expect(app(RmeVisitConsentService::class)->ptmStateFor($visit->fresh()))->toBe($ptm);

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'notes' => 'awal',
        'status' => $status,
        'finalized_at' => $status === MedicalRecord::STATUS_FINAL ? now() : null,
    ]);

    return [$visit->fresh(), $record->fresh()];
}

/** The validation errors a write answers with; [] when it is not refused. */
function m33lErrors(callable $write): array
{
    try {
        $write();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/** Every write on an existing sheet — notes, the three diagnosis writes, the override — keyed by name. */
function m33lSheetWrites(MedicalRecord $record, $actor): array
{
    $primary = m33lDiagnosis($record);
    $secondary = m33lDiagnosis($record, MedicalRecordDiagnosis::ROLE_SECONDARY);
    $diagnoses = app(MedicalRecordDiagnosisService::class);

    return [
        'notes' => fn () => app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'baru']),
        'record' => fn () => $diagnoses->record($record->fresh(), [
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
        ], $actor),
        'makePrimary' => fn () => $diagnoses->makePrimary($record->fresh(), $secondary->fresh(), $actor),
        'remove' => fn () => $diagnoses->remove($record->fresh(), $primary->fresh(), $actor),
        'override' => fn () => app(DiagnosisRolloutService::class)
            ->grantOverride($record->fresh(), 'Pasien emergensi, koding menyusul nanti.', $actor),
    ];
}

$m33lPtmStates = [
    'PTM executed' => [RmeVisitConsentService::PTM_EXECUTED],
    'PTM awaiting the doctor' => [RmeVisitConsentService::PTM_AWAITING_DOCTOR],
    'PTM awaiting the patient' => [RmeVisitConsentService::PTM_AWAITING_PATIENT],
];

it('locks every write on a FINAL sheet whatever the PTM state, the lock answering before the consent (rule 240 §1.8)', function (string $ptm) {
    app(DiagnosisRolloutService::class)->setMode(
        $this->branch,
        DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'pilot enforcement cabang ini untuk urutan penolakan',
        userWith(['configure_diagnosis_rollout']),
    );
    [$visit, $record] = m33lSheetWithPtm($this->branch, $ptm, MedicalRecord::STATUS_FINAL);
    $this->actingAs($this->author);
    $writes = m33lSheetWrites($record, $this->author);
    $before = m33lSnapshot($record);

    foreach ($writes as $write => $call) {
        expect(m33lErrors($call))->toBe(['medical_record' => [MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED]], $write);
    }

    // HTTP: the clinician is denied by editSheet with the lock (403); a Super
    // Admin passes the policy and gets the service's lock, never the consent.
    $this->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'http'])->assertForbidden();
    $this->actingAs(superAdmin())
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'http'])
        ->assertSessionHasErrors(['medical_record' => MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED])
        ->assertSessionDoesntHaveErrors('consent');

    expect(m33lSnapshot($record))->toBe($before)
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(2)
        ->and(DiagnosisRequirementOverride::query()->where('medical_record_id', $record->id)->count())->toBe(0);
})->with($m33lPtmStates);

it('refuses every write on a DRAFT sheet of an unsigned encounter with the consent, and allows them on the executed one (rule 241 §2)', function (string $ptm) {
    app(DiagnosisRolloutService::class)->setMode(
        $this->branch,
        DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'pilot enforcement cabang ini untuk urutan penolakan',
        userWith(['configure_diagnosis_rollout']),
    );
    [, $record] = m33lSheetWithPtm($this->branch, $ptm, MedicalRecord::STATUS_DRAFT);
    $this->actingAs($this->author);
    $writes = m33lSheetWrites($record, $this->author);

    if ($ptm === RmeVisitConsentService::PTM_EXECUTED) {
        foreach ($writes as $write => $call) {
            expect(m33lErrors($call))->toBe([], $write);
        }

        expect($record->fresh()->notes)->toBe('baru')
            ->and($record->fresh()->status)->toBe(MedicalRecord::STATUS_DRAFT)
            ->and(DiagnosisRequirementOverride::query()->where('medical_record_id', $record->id)->count())->toBe(1);

        return;
    }

    $consent = ['consent' => [$ptm === RmeVisitConsentService::PTM_AWAITING_DOCTOR
        ? RmeVisitConsentService::MESSAGE_RME_AWAITING_DOCTOR
        : RmeVisitConsentService::MESSAGE_RME_AWAITING_PATIENT]];

    foreach ($writes as $write => $call) {
        expect(m33lErrors($call))->toBe($consent, $write);
    }

    expect($record->fresh()->notes)->toBe('awal')
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(2)
        ->and(DiagnosisRequirementOverride::query()->where('medical_record_id', $record->id)->count())->toBe(0);
})->with($m33lPtmStates);

// ─── Authorization: the policy answers scope first, then the lock ────────────

it('denies editSheet on a FINAL sheet with the lock reason, and allows it on a draft', function () {
    [, $draft] = m33lSheet($this->branch);
    [, $final] = m33lSheet($this->branch);
    m33lFinalize($final, $this->author);

    $onDraft = Gate::forUser($this->author)->inspect('editSheet', $draft->fresh());
    $onFinal = Gate::forUser($this->author)->inspect('editSheet', $final->fresh());

    expect($onDraft->allowed())->toBeTrue()
        ->and($onFinal->denied())->toBeTrue()
        ->and($onFinal->message())->toBe(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED)
        // A final sheet stays readable (AIR-RMC-09).
        ->and(Gate::forUser($this->author)->allows('view', $final->fresh()))->toBeTrue();
});

it('answers an out-of-scope account with the scope refusal, never the lock (scope first)', function () {
    [, $final] = m33lSheet($this->branch);
    m33lFinalize($final, $this->author);

    // A doctor with no clinical relationship to this patient.
    $outsider = doctorWithOnlineContext($this->branch);
    $reader = rmeClinicalReader();

    $outsiderAnswer = Gate::forUser($outsider)->inspect('editSheet', $final->fresh());
    $readerAnswer = Gate::forUser($reader)->inspect('editSheet', $final->fresh());

    expect($outsiderAnswer->denied())->toBeTrue()
        ->and($outsiderAnswer->message())->not->toBe(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED)
        ->and($readerAnswer->denied())->toBeTrue()
        ->and($readerAnswer->message())->not->toBe(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED);
});

it('keeps the existing HTTP refusals: a reader is 403 and a sheet named on another visit is 404', function () {
    [$visit, $record] = m33lSheet($this->branch, ['notes' => 'awal']);
    [$otherVisit] = m33lSheet($this->branch);
    m33lFinalize($record, $this->author);

    $this->actingAs(rmeClinicalReader())
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'x'])
        ->assertForbidden();

    $this->actingAs($this->author)
        ->patch(route('rme.visits.medical-record.update', [$otherVisit, $record]), ['notes' => 'x'])
        ->assertNotFound();

    expect($record->fresh()->notes)->toBe('awal');
});

// ─── Branch isolation is unchanged ───────────────────────────────────────────

it('still refuses a sheet outside the RME branch set before the lock is consulted', function () {
    // The patient has a live encounter, EXECUTED, that this author may write on:
    // nothing but the RME branch set stands between each write and the lock of
    // this FINAL sheet, so the branch refusal must answer first — for the notes
    // and for the three structured-diagnosis writes alike (review LOW-2).
    [$encounter] = m33lSheet($this->branch);
    $nonRme = Branch::factory()->create(['is_rme_enabled' => false]);
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $nonRme->id, 'patient_id' => $encounter->patient_id]);
    $primary = m33lDiagnosis($record);
    $secondary = m33lDiagnosis($record, MedicalRecordDiagnosis::ROLE_SECONDARY);
    $diagnoses = app(MedicalRecordDiagnosisService::class);
    $this->actingAs($this->author);

    $writes = [
        'notes' => fn () => app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'x']),
        'record' => fn () => $diagnoses->record($record->fresh(), [
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
        ], $this->author),
        'makePrimary' => fn () => $diagnoses->makePrimary($record->fresh(), $secondary->fresh(), $this->author),
        'remove' => fn () => $diagnoses->remove($record->fresh(), $primary->fresh(), $this->author),
    ];

    foreach ($writes as $write => $call) {
        expect(array_keys(m33lErrors($call)))->toBe(['medical_record_id'], $write);
    }

    expect(Gate::forUser($this->author)->inspect('editSheet', $record)->message())
        ->not->toBe(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED)
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(2);
});

// ─── The page mirrors the server (rule 135) ──────────────────────────────────

it('renders no submittable diagnosis form on a FINAL sheet, for a clinician or a Super Admin', function () {
    [$visit, $record] = m33lSheet($this->branch);
    m33lDiagnosis($record);
    m33lFinalize($record, $this->author);
    $diagnosisAction = route('rme.visits.medical-record.diagnoses.store', [$visit, $record]);

    foreach ([$this->author, superAdmin()] as $user) {
        $this->actingAs($user)
            ->get(route('rme.visits.medical-record.show', $visit))
            ->assertOk()
            ->assertDontSee($diagnosisAction, false)
            // The sheet's PATCH target; its URL is also the prefix of the handwriting
            // route, so the attribute is asserted with its closing quote.
            ->assertDontSee('action="'.route('rme.visits.medical-record.update', [$visit, $record]).'"', false)
            ->assertSee(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED);
    }
});

it('still renders the diagnosis form on a DRAFT sheet (positive control)', function () {
    [$visit, $record] = m33lSheet($this->branch);

    $this->actingAs($this->author)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('action="'.route('rme.visits.medical-record.diagnoses.store', [$visit, $record]).'"', false)
        ->assertDontSee(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED);
});

// ─── Lock order (rule 223 §1 item 6) ────────────────────────────────────────────

it('locks the encounter visit row before the sheet row when deciding the lock (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    [, $record] = m33lSheet($this->branch);
    $this->actingAs($this->author);

    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = strtolower($query->sql);
        if (preg_match('/for (no key )?update/', $sql)) {
            $locks[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_medical_records') ? 'record' : 'other');
        }
    });

    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'urutan kunci']);

    $visitAt = array_search('visit', $locks, true);
    $recordAt = array_search('record', $locks, true);

    expect($visitAt)->not->toBeFalse()
        ->and($recordAt)->not->toBeFalse()
        ->and($visitAt)->toBeLessThan($recordAt);
});

it('locks the visit row before the sheet row on the PTM-only path, while the PTM is awaiting (PostgreSQL, rule 240 §1.8)', function (string $ptm, string $status) {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    app(DiagnosisRolloutService::class)->setMode(
        $this->branch,
        DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'pilot enforcement cabang ini untuk urutan kunci',
        userWith(['configure_diagnosis_rollout']),
    );
    [, $record] = m33lSheetWithPtm($this->branch, $ptm, $status);
    $this->actingAs($this->author);
    $writes = m33lSheetWrites($record, $this->author);
    $expected = $status === MedicalRecord::STATUS_FINAL
        ? ['medical_record' => [MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED]]
        : ['consent' => [$ptm === RmeVisitConsentService::PTM_AWAITING_DOCTOR
            ? RmeVisitConsentService::MESSAGE_RME_AWAITING_DOCTOR
            : RmeVisitConsentService::MESSAGE_RME_AWAITING_PATIENT]];

    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = strtolower($query->sql);
        if (preg_match('/for (no key )?update/', $sql)) {
            $locks[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_medical_records') ? 'record' : 'other');
        }
    });

    foreach ($writes as $write => $call) {
        $locks = [];

        // The authority refuses with the PTM before it locks anything; the
        // helper then locks the encounter's visit row, THEN the sheet row
        // (rule 223 §1 item 6), and answers with the lock or the consent.
        expect(m33lErrors($call))->toBe($expected, $write);

        $visitAt = array_search('visit', $locks, true);
        $recordAt = array_search('record', $locks, true);

        expect($visitAt)->not->toBeFalse($write)
            ->and($recordAt)->not->toBeFalse($write)
            ->and($visitAt)->toBeLessThan($recordAt, $write);
    }
})->with([
    'awaiting the doctor, FINAL' => [RmeVisitConsentService::PTM_AWAITING_DOCTOR, MedicalRecord::STATUS_FINAL],
    'awaiting the patient, FINAL' => [RmeVisitConsentService::PTM_AWAITING_PATIENT, MedicalRecord::STATUS_FINAL],
    'awaiting the doctor, DRAFT' => [RmeVisitConsentService::PTM_AWAITING_DOCTOR, MedicalRecord::STATUS_DRAFT],
    'awaiting the patient, DRAFT' => [RmeVisitConsentService::PTM_AWAITING_PATIENT, MedicalRecord::STATUS_DRAFT],
]);
