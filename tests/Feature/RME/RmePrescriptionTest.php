<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function prescriptionBranchId(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

function prescriptionVisit(array $overrides = []): ClinicVisit
{
    $branchId = prescriptionBranchId();
    Branch::query()->whereKey($branchId)->update(['is_rme_enabled' => true]);
    $patient = Patient::factory()->create([
        'branch_id' => $branchId,
        'date_of_birth' => now()->subYears(32)->toDateString(),
    ]);
    $doctor = Doctor::factory()->create(['name' => 'drg. Andi Wijaya']);
    rmeMakeDoctorOnline($doctor, Branch::query()->findOrFail($branchId));

    return ClinicVisit::factory()->inProgress()->create(array_merge([
        'branch_id' => $branchId,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ], $overrides));
}

function prescriptionCanvasPayload(): array
{
    return [
        'prescription_canvas_data' => validPodSignatureData(),
        'doctor_signature_canvas_data' => validPodSignatureData(),
    ];
}

function prescriptionFormPayload(ClinicVisit $visit, array $overrides = []): array
{
    return array_merge([
        'prescribed_by_name' => $visit->doctor?->name ?? 'drg. Test',
        'prescription_date' => now()->toDateString(),
        'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien Test',
        'patient_age_snapshot' => (string) ($visit->patient?->age() ?? '30'),
        'allergy_note' => 'Tidak ada',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
    ], prescriptionCanvasPayload(), $overrides);
}

it('visit detail shows Resep Dokter menu card', function () {
    $manager = userWith(['view_clinic_visits']);
    $visit = prescriptionVisit();

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Resep Dokter')
        ->assertSee(route('rme.visits.prescription.show', $visit), false);
});

it('prescription page shows workflow tab Resep Dokter', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = prescriptionVisit();

    $this->actingAs($manager)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()
        ->assertSee('Resep Dokter')
        ->assertSee('Klinik Gigi Daengtisia')
        ->assertSee($visit->patient->name)
        ->assertSee($visit->doctor->name);
});

it('authorized user can open prescription page with auto-filled defaults', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = prescriptionVisit();

    /*
     * CICD-BASELINE-REVERIFY-1 — escape the dynamic half of the attribute.
     *
     * Escaping is switched off deliberately here because the assertion targets
     * the raw `value="…"` attribute, not the text. The names inside it are
     * faker-generated and the view prints them through `{{ }}`, so the expected
     * string has to be escaped exactly as Blade escapes the rendered one —
     * `e()` is the same htmlspecialchars call Blade compiles to. Without it a
     * name like `Dr. Oswaldo O'Kon` renders as `Dr. Oswaldo O&#039;Kon` and the
     * comparison fails on nothing but the random seed. Same defect class as the
     * one that reddened run 31928614428; caught here before it ever fired.
     */
    $this->actingAs($manager)
        ->get(route('rme.visits.prescription.show', [$visit, 'edit' => 1]))
        ->assertOk()
        ->assertSee('value="'.e($visit->doctor->name).'"', false)
        ->assertSee('value="'.e($visit->patient->name).'"', false)
        ->assertSee('32', false);
});

it('can store prescription with prescription and signature canvases', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = prescriptionVisit();

    $this->actingAs($manager)
        ->post(route('rme.visits.prescription.store', $visit), prescriptionFormPayload($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->first();
    expect($prescription)->not->toBeNull()
        ->and($prescription->prescription_canvas_path)->not->toBeNull()
        ->and($prescription->doctor_signature_canvas_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($prescription->prescription_canvas_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($prescription->doctor_signature_canvas_path))->toBeTrue();
});

it('can update an existing prescription', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = prescriptionVisit();
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'prescribed_by_name' => 'drg. Lama',
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.prescriptions.update', $prescription), array_merge(
            prescriptionFormPayload($visit, ['prescribed_by_name' => 'drg. Baru']),
            prescriptionCanvasPayload(),
        ))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect($prescription->fresh()->prescribed_by_name)->toBe('drg. Baru');
});

it('can print prescription with fields and canvas images', function () {
    $viewer = userWith(['view_clinic_visits']);
    $visit = prescriptionVisit();
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'prescribed_by_name' => 'drg. Cetak',
        'patient_name_snapshot' => $visit->patient->name,
        'allergy_note' => 'Paracetamol',
    ]);

    $rxUrl = $prescription->fresh()->prescriptionCanvasUrl();
    $sigUrl = $prescription->fresh()->signatureCanvasUrl();

    $this->actingAs($viewer)
        ->get(route('rme.prescriptions.print', $prescription))
        ->assertOk()
        ->assertSee('Klinik Gigi Daengtisia')
        ->assertSee('drg. Cetak')
        ->assertSee($visit->patient->name)
        ->assertSee('Paracetamol')
        ->assertSee($rxUrl, false)
        ->assertSee($sigUrl, false)
        ->assertDontSee('KTP')
        ->assertDontSee('ktp_number', false);
});

it('denies prescription access for visit outside active RME branch set', function () {
    $nonRmeBranch = Branch::factory()->create(['is_rme_enabled' => false, 'is_active' => true]);
    $manager = userWith(['manage_clinic_visits']);
    $visit = prescriptionVisit(['branch_id' => $nonRmeBranch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertForbidden();
});

it('denies print for prescription outside active RME branch set', function () {
    $nonRmeBranch = Branch::factory()->create(['is_rme_enabled' => false, 'is_active' => true]);
    $viewer = userWith(['view_clinic_visits']);
    $visit = prescriptionVisit(['branch_id' => $nonRmeBranch->id]);
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $nonRmeBranch->id,
        'patient_id' => $visit->patient_id,
    ]);

    $this->actingAs($viewer)
        ->get(route('rme.prescriptions.print', $prescription))
        ->assertForbidden();
});

it('shows patient prescription history from other visits', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visitA = prescriptionVisit();
    $visitB = prescriptionVisit(['patient_id' => $visitA->patient_id]);

    RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visitA->id,
        'branch_id' => $visitA->branch_id,
        'patient_id' => $visitA->patient_id,
        'prescribed_by_name' => 'drg. Riwayat',
        'prescription_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.prescription.show', $visitB))
        ->assertOk()
        ->assertSee('Riwayat Resep Pasien')
        ->assertSee('drg. Riwayat')
        ->assertSee($visitA->visit_number);
});

it('view-only user can open prescription page but cannot store', function () {
    $viewer = userWith(['view_clinic_visits']);
    $visit = prescriptionVisit();

    $this->actingAs($viewer)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk();

    $this->actingAs($viewer)
        ->post(route('rme.visits.prescription.store', $visit), prescriptionFormPayload($visit))
        ->assertForbidden();
});
