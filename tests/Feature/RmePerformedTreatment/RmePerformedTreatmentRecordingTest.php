<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the doctor's structured
| record of what was ACTUALLY performed ("Tindakan Dilakukan"): core recording
| (DPT-001…018) and the examination / consent workflow (DPT-037…046).
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentAuditEvent;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentState;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

// ─── Core recording ──────────────────────────────────────────────────────────

it('DPT-001 records one performed treatment for the authorized treating doctor', function () {
    $visit = dptEncounter();

    $row = dptRecord($visit, $this->scaling);

    expect($row->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED)
        ->and((int) $row->clinic_visit_id)->toBe((int) $visit->id)
        ->and((int) $row->treatment_id)->toBe((int) $this->scaling->id)
        ->and((int) $row->quantity)->toBe(1)
        ->and((int) $row->recorded_by)->toBe((int) $this->doctorUser->id)
        ->and((int) $row->recorded_by_doctor_id)->toBe((int) $this->doctor->id)
        ->and(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::HAS_PERFORMED_TREATMENTS);
});

it('DPT-002 records several distinct performed treatments on one visit', function () {
    $visit = dptEncounter();

    dptRecord($visit, $this->scaling);
    dptRecord($visit, $this->filling, ['tooth_numbers' => ['16']]);
    dptRecord($visit, $this->filling, ['tooth_numbers' => ['26']]);

    expect(RmePerformedTreatment::query()->where('clinic_visit_id', $visit->id)->active()->count())->toBe(3);
});

it('DPT-003 binds every row to the visit it was recorded on, never another', function () {
    $visit = dptEncounter();
    $other = ClinicVisit::factory()->create(['patient_id' => $this->patient->id, 'branch_id' => $this->branch->id, 'status' => ClinicVisit::STATUS_COMPLETED]);

    dptRecord($visit, $this->scaling);

    expect(RmePerformedTreatment::query()->where('clinic_visit_id', $other->id)->count())->toBe(0)
        ->and(RmePerformedTreatment::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('DPT-004/005 derives patient and branch from the visit — the tables carry neither column', function () {
    expect(Schema::hasColumn('trx_rme_performed_treatments', 'patient_id'))->toBeFalse()
        ->and(Schema::hasColumn('trx_rme_performed_treatments', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('trx_rme_treatment_declarations', 'patient_id'))->toBeFalse()
        ->and(Schema::hasColumn('trx_rme_treatment_declarations', 'branch_id'))->toBeFalse();

    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling, ['patient_id' => 999999, 'branch_id' => 999999, 'clinic_visit_id' => 999999]);

    expect((int) $row->clinic_visit_id)->toBe((int) $visit->id)
        ->and((int) $row->clinicVisit->patient_id)->toBe((int) $this->patient->id)
        ->and((int) $row->clinicVisit->branch_id)->toBe((int) $this->branch->id);
});

it('DPT-006 validates doctor authority — only the visit\'s own linked doctor may record', function () {
    // The fixture doctor is online in the room and has a treating relationship
    // with the patient (an earlier visit), so every PERMISSION layer passes — but
    // this visit belongs to another doctor. The identity rule refuses.
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED, 'visit_date' => clinicalToday()->subDays(30)->toDateString(),
    ]);
    $otherDoctor = Doctor::factory()->create();
    $visit = dptEncounter(['doctor_id' => $otherDoctor->id]);

    $refusal = dptRefusal(fn () => dptRecord($visit, $this->scaling));

    expect($refusal)->toContain(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-007 keeps the stable treatment master identity and snapshots its code, name and category', function () {
    $visit = dptEncounter();

    $row = dptRecord($visit, $this->scaling);

    expect((int) $row->treatment_id)->toBe((int) $this->scaling->id)
        ->and($row->treatment_code_snapshot)->toBe($this->scaling->code)
        ->and($row->treatment_name_snapshot)->toBe($this->scaling->name)
        ->and($row->treatment_category_code_snapshot)->toBe('restorative')
        ->and((bool) $row->requires_lab_snapshot)->toBeFalse();
});

it('DPT-008 replays a duplicate submission instead of writing a second row, and refuses an exact active duplicate', function () {
    $visit = dptEncounter();
    $token = (string) Str::uuid();

    $first = dptRecord($visit, $this->scaling, ['submission_token' => $token]);
    $replay = dptRecord($visit, $this->scaling, ['submission_token' => $token]);

    expect((int) $replay->id)->toBe((int) $first->id)
        ->and(RmePerformedTreatment::query()->count())->toBe(1)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::RECORDED))->toBe(1);

    $refusal = dptRefusal(fn () => dptRecord($visit, $this->scaling));
    expect($refusal)->toContain(RmePerformedTreatmentRefusal::DUPLICATE_ACTIVE)
        ->and(RmePerformedTreatment::query()->count())->toBe(1);
});

it('DPT-009 validates quantity between 1 and the maximum', function (mixed $quantity) {
    $visit = dptEncounter();

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, ['quantity' => $quantity])))
        ->toContain(RmePerformedTreatmentRefusal::QUANTITY_INVALID)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
})->with([0, -1, 33, 'dua', 1.5, null]);

it('DPT-010 validates tooth references against the canonical FDI chart', function () {
    $visit = dptEncounter();

    foreach ([['19'], ['99'], ['00'], ['1a'], [' 16 '], ['56']] as $teeth) {
        expect(dptRefusal(fn () => dptRecord($visit, $this->filling, ['tooth_numbers' => $teeth])))
            ->toContain(RmePerformedTreatmentRefusal::TOOTH_INVALID);
    }

    $row = dptRecord($visit, $this->filling, ['tooth_numbers' => ['26', '16', '16', '55']]);

    expect($row->tooth_numbers)->toBe(['16', '26', '55'])
        ->and(RmePerformedTreatment::query()->count())->toBe(1);
});

it('DPT-011 does not require tooth references for a non-tooth-specific treatment', function () {
    $visit = dptEncounter();

    $row = dptRecord($visit, $this->scaling, ['tooth_numbers' => []]);

    expect($row->tooth_numbers)->toBeNull()->and($row->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('DPT-012 never treats a planned / registered treatment as performed', function () {
    $visit = dptEncounter(['initial_treatment_id' => $this->scaling->id]);

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(dptService()->evidenceFor($visit)?->performedCount())->toBe(0);
});

it('DPT-013 never treats a structured diagnosis as a performed treatment', function () {
    $visit = dptEncounter();
    $record = MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'patient_id' => $visit->patient_id]);
    $dx = ClinicalDiagnosis::factory()->create();
    MedicalRecordDiagnosis::query()->create([
        'medical_record_id' => $record->id, 'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
        'clinical_diagnosis_id' => $dx->id, 'diagnosis_role' => 'primary', 'diagnosed_by' => $this->doctorUser->id,
        'diagnosed_at' => now(), 'created_by' => $this->doctorUser->id,
    ]);

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED);
});

it('DPT-014 never derives performed evidence from Tindakan Awal', function () {
    $visit = dptEncounter(['initial_treatment_id' => $this->filling->id]);

    expect(RmePerformedTreatment::query()->count())->toBe(0)
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull();
});

it('DPT-015 never parses the handwritten RME into structured treatment', function () {
    $visit = dptEncounter();
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'patient_id' => $visit->patient_id,
        'notes' => 'Scaling dan tambal gigi 16 dilakukan',
    ]);

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-016 never generates a performed treatment from an odontogram change', function () {
    $visit = dptEncounter();
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['16' => ['status' => 'filling']]],
    ]);

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-017 keeps the full history reconstructable — a removal is a void, never a delete', function () {
    $visit = dptEncounter();

    $first = dptRecord($visit, $this->filling, ['tooth_numbers' => ['16']]);
    dptVoid($visit, $first, 'Gigi salah — seharusnya 26.');
    dptRecord($visit, $this->filling, ['tooth_numbers' => ['26']]);

    $history = dptService()->historyFor($visit);

    expect($history)->toHaveCount(2)
        ->and($history->firstWhere('id', $first->id)->status)->toBe(RmePerformedTreatment::STATUS_VOIDED)
        ->and($history->firstWhere('id', $first->id)->void_reason)->toBe('Gigi salah — seharusnya 26.')
        ->and((int) $history->firstWhere('id', $first->id)->voided_by)->toBe((int) $this->doctorUser->id)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::RECORDED))->toBe(2)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::VOIDED))->toBe(1);
});

it('DPT-018 keeps the historical clinical meaning when the treatment master changes', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    $originalName = $this->scaling->name;
    $other = TreatmentCategory::factory()->create(['code' => 'preventive-2']);

    $this->scaling->update(['name' => 'Scaling Baru', 'code' => 'TRT-NEWCODE', 'treatment_category_id' => $other->id]);
    $this->scaling->delete();

    $row->refresh();
    expect($row->treatment_name_snapshot)->toBe($originalName)
        ->and($row->treatment_category_code_snapshot)->toBe('restorative')
        ->and($row->treatment_code_snapshot)->not->toBe('TRT-NEWCODE');
});

// ─── Consent and examination workflow ───────────────────────────────────────

it('DPT-037 refuses authoring before the examination has started', function (string $status) {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id, 'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id, 'clinic_room_id' => $this->room->id, 'status' => $status,
        'visit_number' => 'VIS-DPT-'.uniqid(), 'visit_date' => clinicalToday()->toDateString(),
    ]);
    rmeSignedConsentFor($visit);

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(RmePerformedTreatment::query()->count() + RmeTreatmentDeclaration::query()->count())->toBe(0);
})->with([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING]);

it('DPT-038 refuses authoring while the Persetujuan Tindakan Medis is unsigned', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id, 'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id, 'clinic_room_id' => $this->room->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS,
        'visit_number' => 'VIS-DPT-'.uniqid(), 'visit_date' => clinicalToday()->toDateString(),
    ]);

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling)))->toContain(RmePerformedTreatmentRefusal::CONSENT_REQUIRED)
        ->and(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::CONSENT_REQUIRED)
        ->and(RmePerformedTreatment::query()->count() + RmeTreatmentDeclaration::query()->count())->toBe(0);
});

it('DPT-039 permits authoring on a consented, in-progress encounter', function () {
    $visit = dptEncounter();

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling)))->toBeNull()
        ->and(dptService()->canAuthor($visit, $this->doctorUser))->toBeTrue();
});

it('DPT-040 leaves the visit in_progress after recording a treatment', function () {
    $visit = dptEncounter();

    dptRecord($visit, $this->scaling);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('DPT-041 leaves the visit in_progress after declaring no treatment', function () {
    $visit = dptEncounter();

    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('DPT-042 keeps the clinical services independent of the visit status machine', function () {
    $source = file_get_contents(app_path('Modules/PerformedTreatment/Services/RmePerformedTreatmentService.php'));

    expect($source)->not->toContain('use App\\Modules\\ClinicVisit\\Services\\ClinicVisitService;')
        ->and($source)->not->toContain('transitionStatus(');
});

it('DPT-043 hands the visit to the cashier only through the explicit examination-completion path', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasNoErrors();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(dptRefusal(fn () => dptRecord($visit, $this->filling)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE);
});

it('DPT-044 keeps historical performed treatments readable after the examination', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    $workspace = dptService()->workspaceFor($visit->fresh(), $this->doctorUser);

    expect($workspace['can_author'])->toBeFalse()
        ->and($workspace['active_rows'])->toHaveCount(1)
        ->and($workspace['state'])->toBe(RmePerformedTreatmentState::HAS_PERFORMED_TREATMENTS);
});

it('DPT-045 writes nothing to the legacy archives', function () {
    $before = [DB::table('stg_rme_legacy_imports')->count(), DB::table('trx_rme_legacy_records')->count()];
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);

    expect([DB::table('stg_rme_legacy_imports')->count(), DB::table('trx_rme_legacy_records')->count()])->toBe($before);
});

it('DPT-046 refuses every change once the examination is complete (amendment workflow is an owner decision)', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    expect(dptRefusal(fn () => dptRecord($visit, $this->filling)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptRefusal(fn () => dptVoid($visit, $row)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});
