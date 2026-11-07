<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-8B / D6 (P8R-S3) — the printed dentist and patient
|--------------------------------------------------------------------------
|
| The printed prescription names a dentist and a patient. Both were free-text
| request fields, so the drawn doctor signature sat above whatever name the
| authoring account typed. They now come only from the clinical records: the
| doctor and patient the visit (or the prescription itself) points at. Request
| values are ignored, a prescription with no visit doctor is refused, and the
| form shows both names read-only.
|
| Helper prefix `p8bpid` is unique because the critical gate loads every suite
| into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\RmePrescriptionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

const P8BPID_FORGED_DOCTOR = 'drg. Palsu Bukan Dokter Kunjungan';
const P8BPID_FORGED_PATIENT = 'Nama Pasien Karangan';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Asli Kunjungan']);
    $this->author = rmeClinicalAuthor();
});

function p8bpidVisit(object $test, ?int $doctorId = -1): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $test->branch->id,
        'name' => 'Pasien Asli Kunjungan',
        'date_of_birth' => now()->subYears(40)->toDateString(),
    ]);

    return ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $test->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorId === -1 ? $test->doctor->id : $doctorId,
        'clinic_room_id' => ClinicRoom::factory()->create([
            'branch_id' => $test->branch->id,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])->id,
    ]);
}

function p8bpidForgedForm(): array
{
    return [
        'prescribed_by_name' => P8BPID_FORGED_DOCTOR,
        'patient_name_snapshot' => P8BPID_FORGED_PATIENT,
        'prescription_date' => clinicalToday()->toDateString(),
        'patient_age_snapshot' => '40',
        'allergy_note' => 'Tidak ada',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
        'prescription_canvas_data' => validPodSignatureData(),
        // DMS-RME-REVISION-PROGRAM-8C (rule 228 §12) — content authoring: only the
        // prescription's own doctor saves the signature, so the authors here send none.
    ];
}

it('stores the visit doctor and patient, never the submitted names', function () {
    $visit = p8bpidVisit($this);

    $this->actingAs($this->author)
        ->post(route('rme.visits.prescription.store', $visit), p8bpidForgedForm())
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();

    expect($prescription->prescribed_by_name)->toBe('drg. Asli Kunjungan')
        ->and($prescription->patient_name_snapshot)->toBe('Pasien Asli Kunjungan')
        ->and((int) $prescription->doctor_id)->toBe($this->doctor->id)
        ->and((int) $prescription->patient_id)->toBe($visit->patient_id);
});

it('prints the clinical identity and never the submitted text', function () {
    $visit = p8bpidVisit($this);

    $this->actingAs($this->author)->post(route('rme.visits.prescription.store', $visit), p8bpidForgedForm());
    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();

    $this->actingAs($this->author)
        ->get(route('rme.prescriptions.print', $prescription))
        ->assertOk()
        ->assertSee('drg. Asli Kunjungan')
        ->assertSee('Pasien Asli Kunjungan')
        ->assertDontSee(P8BPID_FORGED_DOCTOR)
        ->assertDontSee(P8BPID_FORGED_PATIENT);
});

it('re-derives both names on update and replaces an earlier free-text name', function () {
    $visit = p8bpidVisit($this);
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $this->doctor->id,
        // The shape a pre-8B row could carry: text that is not the doctor.
        'prescribed_by_name' => 'drg. Teks Lama',
        'patient_name_snapshot' => 'Pasien Teks Lama',
    ]);

    $this->actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), p8bpidForgedForm())
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $fresh = $prescription->fresh();

    expect($fresh->prescribed_by_name)->toBe('drg. Asli Kunjungan')
        ->and($fresh->patient_name_snapshot)->toBe('Pasien Asli Kunjungan');
});

it('keeps the prescription\'s own doctor on update even when the visit doctor changed', function () {
    $visit = p8bpidVisit($this);
    $this->actingAs($this->author)->post(route('rme.visits.prescription.store', $visit), p8bpidForgedForm());
    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();

    // A later room re-assignment can rewrite the visit's doctor (OD-5-3). The
    // printed name must stay the doctor the prescription was written under.
    $other = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Pengganti']);
    $visit->forceFill(['doctor_id' => $other->id])->save();

    $this->actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), p8bpidForgedForm())
        ->assertRedirect();

    $fresh = $prescription->fresh();

    expect($fresh->prescribed_by_name)->toBe('drg. Asli Kunjungan')
        ->and((int) $fresh->doctor_id)->toBe($this->doctor->id);
});

it('refuses a prescription on a visit with no doctor, writing nothing', function () {
    $visit = p8bpidVisit($this, null);
    $objects = count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8bpidForgedForm(), $this->author))
        ->toThrow(ValidationException::class)
        ->and(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles()))->toBe($objects);
});

it('shows both names read-only on the form, with no input to submit', function () {
    $visit = p8bpidVisit($this);

    $this->actingAs($this->author)
        ->get(route('rme.visits.prescription.show', [$visit, 'edit' => 1]))
        ->assertOk()
        ->assertSee('data-server-derived="doctor">drg. Asli Kunjungan<', false)
        ->assertSee('data-server-derived="patient">Pasien Asli Kunjungan<', false)
        ->assertDontSee('name="prescribed_by_name"', false)
        ->assertDontSee('name="patient_name_snapshot"', false)
        // The editor itself is present, so the absence above is not an artefact
        // of a page that rendered no form at all.
        ->assertSee('name="prescription_canvas_data"', false);
});

it('accepts a request that sends neither name', function () {
    $visit = p8bpidVisit($this);
    $form = p8bpidForgedForm();
    unset($form['prescribed_by_name'], $form['patient_name_snapshot']);

    $this->actingAs($this->author)
        ->post(route('rme.visits.prescription.store', $visit), $form)
        ->assertRedirect(route('rme.visits.prescription.show', $visit))
        ->assertSessionHasNoErrors();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->value('prescribed_by_name'))->toBe('drg. Asli Kunjungan');
});

it('still names a doctor and patient whose master records were soft-deleted', function () {
    // Independent review finding (8B): a doctor who left the clinic keeps their
    // soft-deleted master row, and their past visits still need prescriptions
    // printed and amended under their name. The identity must not disappear
    // just because the master record was archived.
    $visit = p8bpidVisit($this);
    $this->doctor->delete();
    $visit->patient->delete();

    expect(Doctor::query()->find($this->doctor->id))->toBeNull()
        ->and(Doctor::withTrashed()->find($this->doctor->id))->not->toBeNull();

    $prescription = app(RmePrescriptionService::class)->create($visit->fresh(), p8bpidForgedForm(), $this->author);

    expect($prescription->prescribed_by_name)->toBe('drg. Asli Kunjungan')
        ->and($prescription->patient_name_snapshot)->toBe('Pasien Asli Kunjungan')
        ->and((int) $prescription->doctor_id)->toBe($this->doctor->id);

    $updated = app(RmePrescriptionService::class)->update($prescription->fresh(), p8bpidForgedForm(), $this->author);

    expect($updated->prescribed_by_name)->toBe('drg. Asli Kunjungan')
        ->and($updated->patient_name_snapshot)->toBe('Pasien Asli Kunjungan');
});
