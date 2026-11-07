<?php

// DMS-RME-REVISION-PROGRAM-8A / P8-N1 — the prescription is a clinical document,
// so it follows the same authoring authority as the rest of the clinical record
// (rule 223 §3, extended by rule 226):
//
//   - the front-desk-only tier (FrontOfficeRole::isVisitFrontDeskOnly(): Admin
//     Klinik / Front Office without a clinical role) never creates or rewrites a
//     prescription, its R/ canvas or its doctor-signature canvas;
//   - a write is scoped to the actor's WORKING branch (RmeWorkingBranchScope::
//     allows()), exactly like the visit's own write authority, never to "any
//     RME-enabled branch";
//   - the doctor-patient scope still applies to a Doctor;
//   - all three are re-asserted in RmePrescriptionService, so a caller that
//     never passes through the policy (a job, a command, a future controller)
//     is refused the same way.
//
// Deliberately NOT decided here (owner decisions, see the sprint record):
// whether Perawat / Supervisor RME may author prescriptions (rule 223 §16),
// room isolation of prescriptions (OD-7-2), and whether a prescription may be
// amended after its visit completed. The tests below pin today's answer for
// those cases so that this sprint is provably neutral on them.
//
// Helper prefix `p8arx` is unique because the critical gate loads every suite
// into one process.

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\RmePrescriptionService;
use App\Support\AccessControl\FrontOfficeRole;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branchA = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctorA = Doctor::factory()->withAllowedBranches([$this->branchA])->create(['name' => 'drg. Andi Wijaya']);
    $this->doctorB = Doctor::factory()->withAllowedBranches([$this->branchB])->create(['name' => 'drg. Budi Santoso']);

    // The permission fixture the prescription suites have always used. Since
    // DMS-RME-REVISION-PROGRAM-8B / D7 it also carries a clinical role: the
    // role-less form of it is now refused, and that refusal is pinned in
    // RmePrescriptionAuthoringRoleMatrixTest.
    $this->clinician = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
});

function p8arxVisit(Branch $branch, Doctor $doctor, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'date_of_birth' => now()->subYears(32)->toDateString(),
    ]);

    return ClinicVisit::factory()->inProgress()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => ClinicRoom::factory()->create([
            'branch_id' => $branch->id,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])->id,
    ], $overrides));
}

function p8arxPrescription(ClinicVisit $visit, array $overrides = []): RmePrescription
{
    return RmePrescription::factory()->withStoredCanvases()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'prescribed_by_name' => 'drg. Asli',
        'allergy_note' => 'CATATAN ASLI',
    ], $overrides));
}

function p8arxForm(ClinicVisit $visit, array $overrides = []): array
{
    return array_merge([
        'prescribed_by_name' => $visit->doctor?->name ?? 'drg. Test',
        'prescription_date' => clinicalToday()->toDateString(),
        'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien Test',
        'patient_age_snapshot' => '32',
        'allergy_note' => 'Tidak ada',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
        'prescription_canvas_data' => validPodSignatureData(),
        'doctor_signature_canvas_data' => validPodSignatureData(),
    ], $overrides);
}

function p8arxObjectCount(): int
{
    return count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());
}

/**
 * A PURE front-desk account: exactly one role. rmeMakeAdminClinicActive() assigns
 * Admin Klinik, so using it for a Front Office account would test an Admin Klinik
 * hybrid and hide a regression that dropped Front Office from the tier.
 */
function p8arxFrontDesk(string $role, Branch $branch): User
{
    $user = User::factory()->create();

    $role === FrontOfficeRole::NAME
        ? rmeMakeFrontOfficeActive($user, $branch)
        : rmeMakeAdminClinicActive($user, $branch);

    expect($user->fresh()->getRoleNames()->all())->toBe([$role]);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Positive controls — today's authorised authors keep their authority
|--------------------------------------------------------------------------
*/

it('P8A-RX-01 lets the clinician fixture create a prescription with both canvases', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($this->clinician)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();
    expect((int) $prescription->created_by)->toBe($this->clinician->id)
        ->and(p8arxObjectCount())->toBe(2);
});

it('P8A-RX-02 lets the visit doctor create and then update their own prescription', function () {
    $doctorUser = rmeMakeDoctorOnline($this->doctorA, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($doctorUser)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();

    $this->actingAs($doctorUser)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'Diperbarui dokter']))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect($prescription->fresh()->allergy_note)->toBe('Diperbarui dokter');
});

it('P8A-RX-03 keeps Super Admin able to create a prescription', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs(userInRole('Super Admin'))
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('P8A-RX-04 keeps a clinician who also holds the front-office role able to author', function () {
    $hybrid = userInRole('Admin Klinik');
    $hybrid->assignRole('Perawat');
    rmeMakeAdminClinicActive($hybrid, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($hybrid)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('P8A-RX-06 keeps Supervisor RME able to author with no online context (unchanged; owner decision §16)', function () {
    $supervisor = userInRole('Supervisor RME');
    $visit = p8arxVisit($this->branchB, $this->doctorB);

    $this->actingAs($supervisor)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('P8A-RX-05 keeps a Perawat able to author in the branch they are working in (unchanged; owner decision §16)', function () {
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($perawat)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The front-desk-only tier never authors a prescription
|--------------------------------------------------------------------------
*/

it('P8A-RX-10 refuses an Admin Klinik account creating a prescription in its own working branch', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($admin)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

it('P8A-RX-11 refuses a Front Office account creating a prescription in its own working branch', function () {
    $frontOffice = p8arxFrontDesk('Front Office', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($frontOffice)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

it('P8A-RX-12 refuses a Front Office account creating a prescription on another branch visit', function () {
    $frontOffice = p8arxFrontDesk('Front Office', $this->branchA);
    $visit = p8arxVisit($this->branchB, $this->doctorB);

    $this->actingAs($frontOffice)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

it('P8A-RX-13 refuses an Admin Klinik account rewriting an existing prescription and leaves it untouched', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $prescription = p8arxPrescription($visit);
    $before = $prescription->fresh()->only(['allergy_note', 'prescription_canvas_path', 'doctor_signature_canvas_path', 'updated_by']);
    $objects = p8arxObjectCount();

    $this->actingAs($admin)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'DITIMPA']))
        ->assertForbidden();

    expect($prescription->fresh()->only(array_keys($before)))->toBe($before)
        ->and(p8arxObjectCount())->toBe($objects);
});

it('P8A-RX-14 refuses a Front Office account replacing only the doctor-signature canvas', function () {
    $frontOffice = p8arxFrontDesk('Front Office', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $prescription = p8arxPrescription($visit);
    $signature = $prescription->doctor_signature_canvas_path;
    $objects = p8arxObjectCount();

    $this->actingAs($frontOffice)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, [
            'prescription_canvas_data' => null,
        ]))
        ->assertForbidden();

    expect($prescription->fresh()->doctor_signature_canvas_path)->toBe($signature)
        ->and(p8arxObjectCount())->toBe($objects);
});

it('P8A-RX-15 refuses a Front Office account rewriting another branch prescription', function () {
    $frontOffice = p8arxFrontDesk('Front Office', $this->branchA);
    $visit = p8arxVisit($this->branchB, $this->doctorB);
    $prescription = p8arxPrescription($visit);

    $this->actingAs($frontOffice)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'DITIMPA']))
        ->assertForbidden();

    expect($prescription->fresh()->allergy_note)->toBe('CATATAN ASLI');
});

it('P8A-RX-16 refuses the front desk a prescription on a completed visit (historical record)', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $prescription = p8arxPrescription($visit);

    $this->actingAs($admin)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'DITIMPA']))
        ->assertForbidden();

    expect($prescription->fresh()->allergy_note)->toBe('CATATAN ASLI');
});

it('P8A-RX-17 does not offer the front desk the prescription editor (UI mirrors the capability)', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    // The store route shares its URL with this page's own GET route, so the URL
    // is not a signal; the two canvas inputs exist only inside the editor form.
    $this->actingAs($admin)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()
        ->assertDontSee('name="prescription_canvas_data"', false)
        ->assertDontSee('name="doctor_signature_canvas_data"', false);

    // Positive control: the same page offers the editor to an authorised author,
    // so the absence above cannot pass because the markers were renamed.
    $this->actingAs($this->clinician)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()
        ->assertSee('name="prescription_canvas_data"', false)
        ->assertSee('name="doctor_signature_canvas_data"', false);
});

it('P8A-RX-18 still lets the front desk read a prescription in its working branch (reading is unchanged)', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    p8arxPrescription($visit);

    $this->actingAs($admin)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Writes are scoped to the actor's working branch
|--------------------------------------------------------------------------
*/

it('P8A-RX-20 refuses a Perawat creating a prescription on a visit outside their working branch', function () {
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branchA);
    $visit = p8arxVisit($this->branchB, $this->doctorB);

    $this->actingAs($perawat)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

it('P8A-RX-21 refuses a Perawat rewriting a prescription outside their working branch', function () {
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branchA);
    $visit = p8arxVisit($this->branchB, $this->doctorB);
    $prescription = p8arxPrescription($visit);

    $this->actingAs($perawat)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'DITIMPA']))
        ->assertForbidden();

    expect($prescription->fresh()->allergy_note)->toBe('CATATAN ASLI');
});

it('P8A-RX-22 refuses a doctor outside the patient scope (wrong patient / wrong visit)', function () {
    $doctorBUser = rmeMakeDoctorOnline($this->doctorB, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($doctorBUser)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('P8A-RX-23 refuses a view-only account by route middleware', function () {
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($kasir)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertForbidden();

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('P8A-RX-24 ends an inactive account before it can write a prescription', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $this->actingAs($this->clinician)->get(route('profile.edit'))->assertOk();
    User::query()->whereKey($this->clinician->id)->update(['is_active' => false]);

    $this->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('login'));

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The policy layer on its own (the service re-assertion would otherwise turn a
| policy regression into the same 403 and hide it)
|--------------------------------------------------------------------------
*/

it('P8A-RX-25 denies the front-desk tier both write abilities in the policy', function (string $role) {
    $actor = p8arxFrontDesk($role, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $prescription = p8arxPrescription(p8arxVisit($this->branchA, $this->doctorA));

    expect(Gate::forUser($actor)->denies('create', [RmePrescription::class, $visit]))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('update', $prescription))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('view', $prescription))->toBeTrue();
})->with(['Admin Klinik', 'Front Office']);

it('P8A-RX-26 scopes both write abilities to the working branch in the policy', function () {
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branchA);
    $own = p8arxVisit($this->branchA, $this->doctorA);
    $other = p8arxVisit($this->branchB, $this->doctorB);

    expect(Gate::forUser($perawat)->allows('create', [RmePrescription::class, $own]))->toBeTrue()
        ->and(Gate::forUser($perawat)->allows('update', p8arxPrescription($own)))->toBeTrue()
        ->and(Gate::forUser($perawat)->denies('create', [RmePrescription::class, $other]))->toBeTrue()
        ->and(Gate::forUser($perawat)->denies('update', p8arxPrescription($other)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Identity of the record comes from the server, never from the request
|--------------------------------------------------------------------------
*/

it('P8A-RX-30 ignores request-supplied actor, doctor, patient and branch fields', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $other = User::factory()->create();

    $this->actingAs($this->clinician)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit, [
            'created_by' => $other->id,
            'updated_by' => $other->id,
            'doctor_id' => $this->doctorB->id,
            'branch_id' => $this->branchB->id,
            'patient_id' => Patient::factory()->create(['branch_id' => $this->branchB->id])->id,
            'status' => 'printed',
        ]))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();
    expect((int) $prescription->created_by)->toBe($this->clinician->id)
        ->and((int) $prescription->updated_by)->toBe($this->clinician->id)
        ->and((int) $prescription->doctor_id)->toBe($this->doctorA->id)
        ->and((int) $prescription->branch_id)->toBe($this->branchA->id)
        ->and((int) $prescription->patient_id)->toBe((int) $visit->patient_id)
        ->and($prescription->status)->toBe(RmePrescription::STATUS_DRAFT);
});

it('P8A-RX-31 records the acting account as the updater, never a request value', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $prescription = p8arxPrescription($visit);

    $this->actingAs($this->clinician)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, [
            'updated_by' => User::factory()->create()->id,
            'clinic_visit_id' => p8arxVisit($this->branchA, $this->doctorA)->id,
        ]))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $fresh = $prescription->fresh();
    expect((int) $fresh->updated_by)->toBe($this->clinician->id)
        ->and((int) $fresh->clinic_visit_id)->toBe($visit->id);
});

/*
|--------------------------------------------------------------------------
| The service re-asserts the authority (CG-06: three layers)
|--------------------------------------------------------------------------
*/

it('P8A-RX-40 refuses a front-desk actor in the service even without the policy', function () {
    $frontOffice = p8arxFrontDesk('Front Office', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8arxForm($visit), $frontOffice))
        ->toThrow(AuthorizationException::class);

    expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(p8arxObjectCount())->toBe(0);
});

it('P8A-RX-41 refuses a front-desk actor updating through the service even without the policy', function () {
    $admin = p8arxFrontDesk('Admin Klinik', $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    $prescription = p8arxPrescription($visit);
    $objects = p8arxObjectCount();

    expect(fn () => app(RmePrescriptionService::class)->update($prescription, p8arxForm($visit, ['allergy_note' => 'DITIMPA']), $admin))
        ->toThrow(AuthorizationException::class);

    expect($prescription->fresh()->allergy_note)->toBe('CATATAN ASLI')
        ->and(p8arxObjectCount())->toBe($objects);
});

it('P8A-RX-42 refuses a context-bound actor whose working branch is not the visit branch, in the service', function () {
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branchA);
    $visit = p8arxVisit($this->branchB, $this->doctorB);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8arxForm($visit), $perawat))
        ->toThrow(AuthorizationException::class);
});

it('P8A-RX-43 refuses a context-bound actor with no working context at all (fail closed), in the service', function () {
    $perawat = userInRole('Perawat');
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8arxForm($visit), $perawat))
        ->toThrow(AuthorizationException::class);
});

it('P8A-RX-44 refuses a deactivated actor in the service', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA);
    User::query()->whereKey($this->clinician->id)->update(['is_active' => false]);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8arxForm($visit), $this->clinician->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('P8A-RX-45 refuses a doctor outside the patient scope in the service', function () {
    $doctorBUser = rmeMakeDoctorOnline($this->doctorB, $this->branchA);
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8arxForm($visit), $doctorBUser))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| Neutral on undecided policy — pinned so this sprint provably changes nothing
|--------------------------------------------------------------------------
*/

it('P8A-RX-50 leaves a clinician able to amend the prescription of a completed visit (undecided, owner decision)', function () {
    $visit = p8arxVisit($this->branchA, $this->doctorA, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $prescription = p8arxPrescription($visit);

    $this->actingAs($this->clinician)
        ->patch(route('rme.prescriptions.update', $prescription), p8arxForm($visit, ['allergy_note' => 'Revisi klinisi']))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect($prescription->fresh()->allergy_note)->toBe('Revisi klinisi');
});

it('P8A-RX-51 leaves a doctor writing the prescription of an active visit in another room unchanged (OD-7-2)', function () {
    $doctorUser = rmeMakeDoctorOnline($this->doctorA, $this->branchA);
    // The doctor's online room differs from the visit's room; prescription room
    // isolation is owner decision OD-7-2 and is deliberately not added here.
    $visit = p8arxVisit($this->branchA, $this->doctorA);

    $this->actingAs($doctorUser)
        ->post(route('rme.visits.prescription.store', $visit), p8arxForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));
});
