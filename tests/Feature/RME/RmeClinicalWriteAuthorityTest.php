<?php

/**
 * DMS-RME-REVISION-PROGRAM-8C PR1 (rule 228) — clinical WRITE authority.
 *
 * Owner decisions 2026-11-07 (D3 Group 1, "FIX NOW"):
 *
 *  C1 OD-7-2 / R8A-1 — a doctor may not open or write the prescription of an
 *     ACTIVE visit of another treatment room (rule 138's room scope, which already
 *     bound the visit, the RME page and the odontogram, now binds prescriptions).
 *  C2 OD-5-7 — the same room scope binds the current-RME / odontogram / performed
 *     treatment write authority, which resolves the patient's live encounter
 *     server-side (a canonical-URL write never reached the visit's room guard).
 *  C3 OD-5-2 — only a clinical examination authority may START an examination;
 *     the front desk keeps CANCEL (no-show).
 *  C4 OD-5-3 — once the examination is finished (cashier_pending), moving the
 *     visit to another room no longer rewrites its doctor.
 *  C5 D3-NEW-1 — RME / odontogram authoring is a clinical-role ALLOWLIST: a role
 *     that merely holds manage_clinic_visits (e.g. a runtime "Tester RME") does
 *     not inherit it.
 *
 * Every room rule applies only to the ACTIVE set (registered / waiting /
 * in_progress); history stays readable (rule 138 §17).
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\RmePrescriptionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->roomA = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->roomB = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Ruang A']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->roomA);

    // Both visits name THIS doctor, so the Sprint 66.2 doctor-patient scope
    // passes for both and the ROOM is the only thing under test.
    $this->visitA = cwaVisit($this, $this->roomA);
    $this->visitB = cwaVisit($this, $this->roomB);
});

function cwaVisit(object $t, ClinicRoom $room, string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $t->branch->id,
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);

    return ClinicVisit::factory()->create([
        'branch_id' => $t->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $t->doctorRecord->id,
        'clinic_room_id' => $room->id,
        'status' => $status,
    ]);
}

function cwaForm(array $overrides = []): array
{
    return array_merge([
        'prescription_date' => clinicalToday()->toDateString(),
        'patient_age_snapshot' => '30',
        'allergy_note' => 'Tidak ada',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
        'prescription_canvas_data' => validPodSignatureData(),
        'doctor_signature_canvas_data' => validPodSignatureData(),
    ], $overrides);
}

function cwaRuntimeRole(array $permissions): User
{
    $role = Role::findOrCreate('Tester RME', 'web');
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/*
|--------------------------------------------------------------------------
| C1 — prescription room scope (OD-7-2 / R8A-1)
|--------------------------------------------------------------------------
*/

it('C1-01 lets the doctor write the prescription of the active visit in their own room', function () {
    actingAs($this->doctor)
        ->post(route('rme.visits.prescription.store', $this->visitA), cwaForm())
        ->assertRedirect(route('rme.visits.prescription.show', $this->visitA));

    expect(RmePrescription::query()->where('clinic_visit_id', $this->visitA->id)->exists())->toBeTrue();
});

it('C1-02 refuses the doctor creating the prescription of an active visit in another room', function () {
    actingAs($this->doctor)
        ->post(route('rme.visits.prescription.store', $this->visitB), cwaForm())
        ->assertForbidden();

    expect(RmePrescription::query()->where('clinic_visit_id', $this->visitB->id)->exists())->toBeFalse();
});

it('C1-03 refuses the doctor opening the prescription page of an active visit in another room', function () {
    actingAs($this->doctor)
        ->get(route('rme.visits.prescription.show', $this->visitB))
        ->assertForbidden();
});

it('C1-04 refuses the doctor updating the prescription of an active visit in another room', function () {
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $this->visitB->id,
        'branch_id' => $this->visitB->branch_id,
        'patient_id' => $this->visitB->patient_id,
        'doctor_id' => $this->doctorRecord->id,
        'allergy_note' => 'ASLI',
    ]);

    actingAs($this->doctor)
        ->patch(route('rme.prescriptions.update', $prescription), cwaForm(['allergy_note' => 'DITIMPA']))
        ->assertForbidden();

    expect($prescription->fresh()->allergy_note)->toBe('ASLI');
});

it('C1-05 refuses the same write at the service, for a caller that skips the route and the policy', function () {
    expect(fn () => app(RmePrescriptionService::class)->create($this->visitB, cwaForm(), $this->doctor))
        ->toThrow(AuthorizationException::class);

    expect(RmePrescription::query()->where('clinic_visit_id', $this->visitB->id)->exists())->toBeFalse();
});

it('C1-06 keeps a post-examination visit of another room readable (history is never room-hidden)', function () {
    $history = cwaVisit($this, $this->roomB, ClinicVisit::STATUS_CASHIER_PENDING);

    actingAs($this->doctor)
        ->get(route('rme.visits.prescription.show', $history))
        ->assertOk();
});

it('C1-07 does not room-scope a clinician who is not doctor-scoped (Supervisor RME)', function () {
    $supervisor = rmeClinicalAuthor();

    actingAs($supervisor)
        ->post(route('rme.visits.prescription.store', $this->visitB), cwaForm())
        ->assertRedirect(route('rme.visits.prescription.show', $this->visitB));
});

/*
|--------------------------------------------------------------------------
| C2 — the live-encounter write authority is room scoped (OD-5-7)
|--------------------------------------------------------------------------
*/

it('C2-01 lets the doctor author the RME of the consented encounter in their own room', function () {
    rmeActiveConsentedEncounter($this->visitA);

    $service = app(RmeVisitConsentService::class);

    expect($service->canAuthorRmeForPatient($this->visitA->patient_id, $this->doctor))->toBeTrue()
        ->and($service->assertRmeAuthoringAllowedForPatient($this->visitA->patient_id, $this->doctor)->id)->toBe($this->visitA->id);
});

it('C2-02 refuses the doctor authoring the RME of a consented encounter in another room', function () {
    rmeActiveConsentedEncounter($this->visitB);

    $service = app(RmeVisitConsentService::class);

    expect($service->canAuthorRmeForPatient($this->visitB->patient_id, $this->doctor))->toBeFalse();
    expect(fn () => $service->assertRmeAuthoringAllowedForPatient($this->visitB->patient_id, $this->doctor))
        ->toThrow(ValidationException::class);
});

it('C2-03 refuses the doctor charting the odontogram of a consented encounter in another room', function () {
    rmeActiveConsentedEncounter($this->visitB);

    $service = app(RmeVisitConsentService::class);

    expect($service->canAuthorOdontogramFor($this->visitB, $this->doctor))->toBeFalse()
        ->and($service->canAuthorOdontogramFor($this->visitA->refresh(), $this->doctor))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| C3 — START an examination is a clinical act (OD-5-2)
|--------------------------------------------------------------------------
*/

it('C3-01 refuses the front desk starting an examination over HTTP and leaves the visit waiting', function () {
    $waiting = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_WAITING);
    $frontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);

    actingAs($frontOffice)
        ->post(route('rme.visits.transition', $waiting), ['status' => ClinicVisit::STATUS_IN_PROGRESS])
        ->assertForbidden();

    expect($waiting->fresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('C3-02 refuses the front desk starting an examination at the service', function () {
    $waiting = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_WAITING);
    $adminKlinik = User::factory()->create();
    rmeMakeAdminClinicActive($adminKlinik, $this->branch);

    expect(fn () => app(ClinicVisitService::class)->transitionStatus($waiting, ClinicVisit::STATUS_IN_PROGRESS, $adminKlinik))
        ->toThrow(ValidationException::class);

    expect($waiting->fresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('C3-03 lets the doctor in the room start the examination', function () {
    $waiting = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_WAITING);

    actingAs($this->doctor)
        ->post(route('rme.visits.transition', $waiting), ['status' => ClinicVisit::STATUS_IN_PROGRESS])
        ->assertRedirect(route('rme.visits.show', $waiting));

    expect($waiting->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('C3-04 keeps the front desk able to cancel a no-show', function () {
    $waiting = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_WAITING);
    $frontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);

    app(ClinicVisitService::class)->transitionStatus($waiting, ClinicVisit::STATUS_CANCELLED, $frontOffice);

    expect($waiting->fresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED);
});

/*
|--------------------------------------------------------------------------
| C4 — the doctor is frozen once the examination is finished (OD-5-3)
|--------------------------------------------------------------------------
*/

it('C4-01 keeps the examining doctor when a cashier_pending visit is moved to another room', function () {
    $finished = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_CASHIER_PENDING);

    $otherDoctor = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Ruang B']);
    rmeMakeDoctorOnline($otherDoctor, $this->branch, $this->roomB);

    app(ClinicVisitService::class)->assignRoom($finished, $this->roomB->id, rmeClinicalAuthor());

    $fresh = $finished->fresh();
    expect($fresh->clinic_room_id)->toBe($this->roomB->id)
        ->and($fresh->doctor_id)->toBe($this->doctorRecord->id);
});

it('C4-02 still assigns the online doctor when a waiting visit is placed in a room', function () {
    $waiting = cwaVisit($this, $this->roomA, ClinicVisit::STATUS_WAITING);

    $otherDoctor = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Ruang B']);
    rmeMakeDoctorOnline($otherDoctor, $this->branch, $this->roomB);

    app(ClinicVisitService::class)->assignRoom($waiting, $this->roomB->id, rmeClinicalAuthor());

    expect($waiting->fresh()->doctor_id)->toBe($otherDoctor->id);
});

/*
|--------------------------------------------------------------------------
| C5 — RME / odontogram authoring is a clinical-role allowlist (D3-NEW-1)
|--------------------------------------------------------------------------
*/

it('C5-01 refuses a runtime role holding manage_clinic_visits the RME and odontogram of a consented encounter', function () {
    rmeActiveConsentedEncounter($this->visitB);
    $tester = cwaRuntimeRole(['view_clinic_visits', 'manage_clinic_visits']);

    $service = app(RmeVisitConsentService::class);

    expect($service->canAuthorRmeForPatient($this->visitB->patient_id, $tester))->toBeFalse()
        ->and($service->canAuthorOdontogramFor($this->visitB, $tester))->toBeFalse();
});

it('C5-02 keeps a clinical role (Supervisor RME) authoring the same encounter', function () {
    rmeActiveConsentedEncounter($this->visitB);

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->visitB->patient_id, rmeClinicalAuthor()))
        ->toBeTrue();
});
