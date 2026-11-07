<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-8B / D1 (P8-N2) — clinical document read scope
|--------------------------------------------------------------------------
|
| The medical record (and its handwriting images), the prescription (page,
| canvases, print, history list) and the odontogram (page, print) were readable
| in ANY RME-enabled branch by every account holding `view_clinic_visits`, while
| the visit itself is read only in the working branch for the context-bound
| roles. So a Kasir or Front Office account pinned to one branch could fetch
| another branch's clinical documents by id.
|
| Owner decision D1 (a): a context-bound account that holds NO clinical role
| (Kasir, Admin Klinik, Front Office) reads clinical documents only in its
| working branch, and none without a working context. Clinicians (Doctor,
| Perawat, Supervisor RME, Super Admin) stay cross-branch — a patient's record
| book spans the branches they were treated in — and the non-context-bound
| roles (Owner, the reporting roles) are unchanged. Nothing is widened.
|
| Helper prefix `p8bdoc` is unique because the critical gate loads every suite
| into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\FrontOfficeRole;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branchA = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctorB = Doctor::factory()->withAllowedBranches([$this->branchB])->create(['name' => 'drg. Cabang B']);
    $this->docsB = p8bdocDocuments($this->branchB, $this->doctorB, 'Pasien Cabang Bravo');
});

/**
 * One visit with every clinical document D1 covers: a medical record with a
 * page 2+ handwriting image, a prescription with both canvases, an odontogram.
 *
 * @return array{visit: ClinicVisit, record: MedicalRecord, page: MedicalRecordHandwritingPage, prescription: RmePrescription, odontogram: Odontogram}
 */
function p8bdocDocuments(Branch $branch, Doctor $doctor, string $patientName): array
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'name' => $patientName]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'clinic_room_id' => ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE])->id,
    ]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $pagePath = 'handwritings/'.$branch->id.'/'.$visit->id.'/handwriting_p2_d1.png';
    ClinicalEvidenceStorage::disk()->put($pagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    $page = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'page_number' => 2,
        'handwriting_path' => $pagePath,
    ]);

    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'prescribed_by_name' => $doctor->name,
        'patient_name_snapshot' => $patientName,
        'prescription_date' => now()->subDays(3)->toDateString(),
    ]);

    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'medical_record_id' => $record->id,
    ]);

    return compact('visit', 'record', 'page', 'prescription', 'odontogram');
}

/** A user with exactly one role, active at the given branch when context-bound. */
function p8bdocUser(string $role, Branch $branch): User
{
    $user = User::factory()->create();

    match ($role) {
        FrontOfficeRole::LEGACY_ADMIN_CLINIC => rmeMakeAdminClinicActive($user, $branch),
        FrontOfficeRole::NAME => rmeMakeFrontOfficeActive($user, $branch),
        FrontOfficeRole::LEGACY_KASIR => rmeMakeKasirActive($user, $branch),
        'Perawat' => rmeMakePerawatActive($user, $branch),
        default => $user->assignRole($role),
    };

    return $user->fresh();
}

/** @return array<string, bool> every D1 read ability for a document set */
function p8bdocAbilities(User $user, array $docs): array
{
    return [
        'medical_record.view' => $user->can('view', $docs['record']),
        'prescription.view' => $user->can('view', $docs['prescription']),
        'prescription.print' => $user->can('print', $docs['prescription']),
        'prescription.viewForVisit' => $user->can('viewForVisit', [RmePrescription::class, $docs['visit']]),
        'odontogram.view' => $user->can('view', $docs['odontogram']),
        'odontogram.print' => $user->can('print', $docs['odontogram']),
        'odontogram.page' => $user->can('create', [Odontogram::class, $docs['visit']]),
    ];
}

function p8bdocAll(bool $value): array
{
    return array_fill_keys([
        'medical_record.view', 'prescription.view', 'prescription.print', 'prescription.viewForVisit',
        'odontogram.view', 'odontogram.print', 'odontogram.page',
    ], $value);
}

dataset('p8bdoc narrowed roles', [
    'Kasir' => [FrontOfficeRole::LEGACY_KASIR],
    'Admin Klinik' => [FrontOfficeRole::LEGACY_ADMIN_CLINIC],
    'Front Office' => [FrontOfficeRole::NAME],
]);

/*
|--------------------------------------------------------------------------
| The narrowed tier: front desk and cashier
|--------------------------------------------------------------------------
*/

it('refuses another branch\'s clinical documents to a front-desk or cashier account', function (string $role) {
    $user = p8bdocUser($role, $this->branchA);

    expect(p8bdocAbilities($user, $this->docsB))->toBe(p8bdocAll(false));
})->with('p8bdoc narrowed roles');

it('still lets a front-desk or cashier account read its own working branch\'s documents', function (string $role) {
    $user = p8bdocUser($role, $this->branchB);
    $abilities = p8bdocAbilities($user, $this->docsB);

    // Odontogram print is unaffected by D1 for these roles; the page and view
    // abilities are what D1 scopes. All document reads in the working branch
    // must stay open.
    expect($abilities['medical_record.view'])->toBeTrue()
        ->and($abilities['prescription.view'])->toBeTrue()
        ->and($abilities['prescription.print'])->toBeTrue()
        ->and($abilities['prescription.viewForVisit'])->toBeTrue()
        ->and($abilities['odontogram.view'])->toBeTrue()
        ->and($abilities['odontogram.print'])->toBeTrue()
        ->and($abilities['odontogram.page'])->toBeTrue();
})->with('p8bdoc narrowed roles');

it('reads no clinical document at all without a working context (fail closed)', function () {
    $kasir = User::factory()->create();
    $kasir->assignRole(FrontOfficeRole::LEGACY_KASIR);
    $kasir = $kasir->fresh();

    expect(app(RmeWorkingBranchScope::class)->clinicalDocumentBranchIdsFor($kasir))->toBe([])
        ->and(p8bdocAbilities($kasir, $this->docsB))->toBe(p8bdocAll(false));
});

it('refuses the stream and print routes of another branch to the cashier', function () {
    $kasir = p8bdocUser(FrontOfficeRole::LEGACY_KASIR, $this->branchA);
    $docs = $this->docsB;

    $this->actingAs($kasir)->get(route('rme.handwriting-pages.image', ['handwritingPage' => $docs['page']->id]))->assertForbidden();
    $this->actingAs($kasir)->get(route('rme.prescriptions.print', $docs['prescription']))->assertForbidden();
    $this->actingAs($kasir)->get(route('rme.prescriptions.canvas', [$docs['prescription'], 'prescription']))->assertForbidden();
    $this->actingAs($kasir)->get(route('rme.prescriptions.canvas', [$docs['prescription'], 'signature']))->assertForbidden();
    $this->actingAs($kasir)->get(route('rme.odontograms.print', $docs['odontogram']))->assertForbidden();
});

it('serves the same routes to the cashier in its own working branch', function () {
    $kasir = p8bdocUser(FrontOfficeRole::LEGACY_KASIR, $this->branchB);
    $docs = $this->docsB;

    $this->actingAs($kasir)->get(route('rme.handwriting-pages.image', ['handwritingPage' => $docs['page']->id]))->assertOk();
    $this->actingAs($kasir)->get(route('rme.prescriptions.print', $docs['prescription']))->assertOk();
    $this->actingAs($kasir)->get(route('rme.prescriptions.canvas', [$docs['prescription'], 'prescription']))->assertOk();
});

it('keeps another branch\'s prescriptions out of the front desk\'s history list', function () {
    $frontOffice = p8bdocUser(FrontOfficeRole::NAME, $this->branchA);
    $doctorA = Doctor::factory()->withAllowedBranches([$this->branchA])->create(['name' => 'drg. Cabang A']);

    // Same patient, now visiting branch A.
    $visitA = ClinicVisit::factory()->create([
        'branch_id' => $this->branchA->id,
        'patient_id' => $this->docsB['visit']->patient_id,
        'doctor_id' => $doctorA->id,
        'clinic_room_id' => ClinicRoom::factory()->create(['branch_id' => $this->branchA->id, 'status' => ClinicRoom::STATUS_ACTIVE])->id,
    ]);

    $this->actingAs($frontOffice)
        ->get(route('rme.visits.prescription.show', $visitA))
        ->assertOk()
        ->assertDontSee($this->docsB['visit']->visit_number);

    // Positive control: a clinician on the same page still sees the patient's
    // prescription from the other branch.
    $this->actingAs(rmeClinicalAuthor(['view_clinic_visits']))
        ->get(route('rme.visits.prescription.show', $visitA))
        ->assertOk()
        ->assertSee($this->docsB['visit']->visit_number);
});

it('keeps another branch\'s records out of the cashier\'s medical-record index', function () {
    $kasirAtA = p8bdocUser(FrontOfficeRole::LEGACY_KASIR, $this->branchA);
    $kasirAtB = p8bdocUser(FrontOfficeRole::LEGACY_KASIR, $this->branchB);

    $this->actingAs($kasirAtA)->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertDontSee('Pasien Cabang Bravo');

    $this->actingAs($kasirAtB)->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Pasien Cabang Bravo');
});

/*
|--------------------------------------------------------------------------
| Unchanged: clinicians and the non-context-bound roles stay cross-branch
|--------------------------------------------------------------------------
*/

it('keeps clinicians cross-branch', function (string $role) {
    $user = p8bdocUser($role, $this->branchA);
    $abilities = p8bdocAbilities($user, $this->docsB);

    expect($abilities['medical_record.view'])->toBeTrue()
        ->and($abilities['prescription.view'])->toBeTrue()
        ->and($abilities['prescription.viewForVisit'])->toBeTrue()
        ->and($abilities['odontogram.view'])->toBeTrue();
})->with([
    'Perawat' => ['Perawat'],
    'Supervisor RME' => ['Supervisor RME'],
]);

it('keeps the owner cross-branch', function () {
    $owner = p8bdocUser('Owner', $this->branchA);

    expect(app(RmeWorkingBranchScope::class)->isContextBound($owner))->toBeFalse()
        ->and($owner->can('view', $this->docsB['record']))->toBeTrue()
        ->and($owner->can('view', $this->docsB['prescription']))->toBeTrue();
});

it('lets a clinical role win on an account that is also front desk', function () {
    $hybrid = User::factory()->create();
    rmeMakeFrontOfficeActive($hybrid, $this->branchA);
    $hybrid->assignRole('Perawat');
    $hybrid = $hybrid->fresh();

    expect($hybrid->can('view', $this->docsB['record']))->toBeTrue()
        ->and($hybrid->can('view', $this->docsB['prescription']))->toBeTrue();
});

it('never widens: every document scope is a subset of the RME-enabled set', function () {
    $scope = app(RmeWorkingBranchScope::class);
    $rme = app(BranchService::class)->rmeEnabledIds();
    $outsider = Branch::factory()->create(['code' => 'XX9', 'is_active' => true, 'is_rme_enabled' => false]);

    foreach (['Kasir', 'Admin Klinik', 'Front Office', 'Perawat', 'Supervisor RME', 'Owner', 'Doctor'] as $role) {
        $ids = $scope->clinicalDocumentBranchIdsFor(p8bdocUser($role, $this->branchA));

        expect(array_diff($ids, $rme))->toBe([], $role.' was given a branch outside the RME set')
            ->and($ids)->not->toContain($outsider->id);
    }

    expect($scope->clinicalDocumentBranchIdsFor(null))->toBe([]);
});
