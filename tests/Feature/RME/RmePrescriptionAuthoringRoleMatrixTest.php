<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-8B / D7 — who may write a prescription, per role
|--------------------------------------------------------------------------
|
| P8R-S1: PrescriptionAuthoringAuthority::permits() re-asserted the front-desk
| tier, the working branch and the doctor scope, but not the clinical write
| permission, so rule 226 §1.3 ("a caller that skips the policy is refused the
| same way") overstated it.
|
| P8R-S2: the front-desk refusal is a DENYLIST of two named roles. Any other role
| that is ever given `manage_clinic_visits` — in RoleSeeder, or at runtime
| through the role management screen — would inherit prescription authoring
| with nobody deciding it. Production already holds such a role (created by
| hand, permission granted, no holders today).
|
| The fix: the write permission is re-asserted in the service, and authoring is
| an ALLOWLIST of clinical roles (PrescriptionAuthoringAuthority::
| AUTHORING_ROLES). This suite pins the full matrix over every seeded role, a
| runtime-created role, role-less accounts, and direct service calls that never
| pass the route or the policy.
|
| Helper prefix `p8brm` is unique because the critical gate loads every suite
| into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\PrescriptionAuthoringAuthority;
use App\Modules\Prescription\Services\RmePrescriptionService;
use App\Support\AccessControl\FrontOfficeRole;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const P8BRM_CLINICAL = ['Doctor', 'Perawat', 'Supervisor RME', 'Super Admin'];

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Matriks']);
});

function p8brmVisit(object $test): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $test->branch->id,
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);

    return ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $test->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $test->doctor->id,
        'clinic_room_id' => ClinicRoom::factory()->create([
            'branch_id' => $test->branch->id,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])->id,
    ]);
}

/** A user holding exactly one role, active at the test branch when the role is context-bound. */
function p8brmUserInRole(object $test, string $role): User
{
    $user = User::factory()->create();

    match ($role) {
        FrontOfficeRole::LEGACY_ADMIN_CLINIC => rmeMakeAdminClinicActive($user, $test->branch),
        FrontOfficeRole::NAME => rmeMakeFrontOfficeActive($user, $test->branch),
        FrontOfficeRole::LEGACY_KASIR => rmeMakeKasirActive($user, $test->branch),
        'Perawat' => rmeMakePerawatActive($user, $test->branch),
        default => $user->assignRole($role),
    };

    $user = $user->fresh();
    expect($user->getRoleNames()->all())->toBe([$role]);

    return $user;
}

function p8brmForm(ClinicVisit $visit): array
{
    return [
        'prescription_date' => clinicalToday()->toDateString(),
        'patient_age_snapshot' => '30',
        'allergy_note' => 'Tidak ada',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
        'prescription_canvas_data' => validPodSignatureData(),
        // DMS-RME-REVISION-PROGRAM-8C (rule 228 §12) — content authoring: only the
        // prescription's own doctor saves the signature, so the authors here send none.
    ];
}

function p8brmObjects(): int
{
    return count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());
}

/*
|--------------------------------------------------------------------------
| The allowlist itself
|--------------------------------------------------------------------------
*/

it('pins the authoring allowlist to exactly the clinical tier', function () {
    expect(PrescriptionAuthoringAuthority::AUTHORING_ROLES)->toBe(P8BRM_CLINICAL)
        ->and(PrescriptionAuthoringAuthority::AUTHORING_ROLES)->toBe(FrontOfficeRole::CLINICAL_ROLES)
        ->and(PrescriptionAuthoringAuthority::WRITE_PERMISSION)->toBe('manage_clinic_visits')
        ->and(array_intersect(PrescriptionAuthoringAuthority::AUTHORING_ROLES, FrontOfficeRole::VISIT_FRONT_DESK_ONLY))->toBe([]);
});

it('classifies every seeded role that holds the clinical write permission', function () {
    // A new role in RoleSeeder holding `manage_clinic_visits` must be put on one
    // side of the line on purpose: clinical (may author) or front desk (may not).
    $unclassified = [];

    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $permissions) {
        $holds = $permissions === '*' || in_array('manage_clinic_visits', (array) $permissions, true);

        if ($holds
            && ! in_array($role, FrontOfficeRole::CLINICAL_ROLES, true)
            && ! in_array($role, FrontOfficeRole::VISIT_FRONT_DESK_ONLY, true)) {
            $unclassified[] = $role;
        }
    }

    expect($unclassified)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The matrix over every seeded role
|--------------------------------------------------------------------------
*/

it('lets exactly the clinical roles author a prescription in their branch', function () {
    $authority = app(PrescriptionAuthoringAuthority::class);
    $visit = p8brmVisit($this);
    $actual = [];

    foreach (array_keys(RoleSeeder::ROLE_PERMISSIONS) as $role) {
        $user = p8brmUserInRole($this, $role);
        $actual[$role] = $authority->permitsActorAtBranch($user, $this->branch->id);
    }

    $expected = [];
    foreach (array_keys(RoleSeeder::ROLE_PERMISSIONS) as $role) {
        $expected[$role] = in_array($role, P8BRM_CLINICAL, true);
    }

    expect($actual)->toBe($expected);
});

it('refuses every non-clinical seeded role at the service, with no row and no canvas written', function () {
    $service = app(RmePrescriptionService::class);
    $refused = [];

    foreach (array_keys(RoleSeeder::ROLE_PERMISSIONS) as $role) {
        if (in_array($role, P8BRM_CLINICAL, true)) {
            continue;
        }

        $visit = p8brmVisit($this);
        $user = p8brmUserInRole($this, $role);
        $objects = p8brmObjects();

        try {
            $service->create($visit, p8brmForm($visit), $user);
            $refused[$role] = false;
        } catch (AuthorizationException) {
            $refused[$role] = true;
        }

        expect(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
            ->and(p8brmObjects())->toBe($objects);
    }

    expect(array_keys(array_filter($refused, fn (bool $r) => ! $r)))->toBe([])
        ->and($refused)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| The cases a denylist could not catch
|--------------------------------------------------------------------------
*/

it('refuses a role created at runtime that is given the clinical write permission', function () {
    // The production shape: a role made by hand on the role screen, granted
    // `view_clinic_visits` + `manage_clinic_visits`, never classified.
    $role = Role::findOrCreate('Tester Klinik Baru', 'web');
    $role->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole($role);
    $user = $user->fresh();
    $visit = p8brmVisit($this);

    expect($user->can('manage_clinic_visits'))->toBeTrue()
        ->and(FrontOfficeRole::isVisitFrontDeskOnly($user))->toBeFalse('the denylist alone would have let this role through')
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($user, $visit))->toBeFalse()
        ->and($user->can('create', [RmePrescription::class, $visit]))->toBeFalse();

    $this->actingAs($user)
        ->post(route('rme.visits.prescription.store', $visit), p8brmForm($visit))
        ->assertForbidden();

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8brmForm($visit), $user))
        ->toThrow(AuthorizationException::class, PrescriptionAuthoringAuthority::REFUSAL_MESSAGE)
        ->and(RmePrescription::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('refuses a role-less account that holds the write permission directly', function () {
    $user = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = p8brmVisit($this);

    expect($user->getRoleNames()->all())->toBe([])
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($user, $visit))->toBeFalse();

    $this->actingAs($user)
        ->post(route('rme.visits.prescription.store', $visit), p8brmForm($visit))
        ->assertForbidden();
});

it('refuses a clinical role whose grant of the write permission was withdrawn (part 0a)', function () {
    // The allowlist is not a permission: a clinical role that no longer holds
    // `manage_clinic_visits` must not author either. Supervisor RME is used so
    // no online context or doctor link is involved.
    Role::findByName('Supervisor RME', 'web')->revokePermissionTo('manage_clinic_visits');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = p8brmUserInRole($this, 'Supervisor RME');
    $visit = p8brmVisit($this);

    expect($user->can('manage_clinic_visits'))->toBeFalse()
        ->and($user->hasAnyRole(PrescriptionAuthoringAuthority::AUTHORING_ROLES))->toBeTrue()
        ->and(app(PrescriptionAuthoringAuthority::class)->permitsActorAtBranch($user, $this->branch->id))->toBeFalse();

    expect(fn () => app(RmePrescriptionService::class)->create($visit, p8brmForm($visit), $user))
        ->toThrow(AuthorizationException::class);
});

it('still lets a clinical author write and attributes the row to that account', function () {
    $user = p8brmUserInRole($this, 'Supervisor RME');
    $visit = p8brmVisit($this);

    $this->actingAs($user)
        ->post(route('rme.visits.prescription.store', $visit), p8brmForm($visit))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::where('clinic_visit_id', $visit->id)->firstOrFail();

    expect((int) $prescription->created_by)->toBe($user->id);
});

/*
|--------------------------------------------------------------------------
| The parallel RME / odontogram predicate (permission re-assertion only)
|--------------------------------------------------------------------------
*/

it('refuses RME and odontogram authoring to an account without the write permission', function () {
    // Kasir is context-bound but not front-desk, so before D7 it passed the
    // service predicate and only the route stood in its way.
    $kasir = p8brmUserInRole($this, FrontOfficeRole::LEGACY_KASIR);
    $visit = rmeActiveConsentedEncounter(p8brmVisit($this));
    $consents = app(RmeVisitConsentService::class);

    expect($kasir->can('manage_clinic_visits'))->toBeFalse()
        ->and(FrontOfficeRole::isVisitFrontDeskOnly($kasir))->toBeFalse()
        ->and($consents->canAuthorRmeForPatient($visit->patient_id, $kasir))->toBeFalse()
        ->and($consents->canAuthorOdontogramFor($visit, $kasir))->toBeFalse();

    // Positive control on the same encounter: the clinical fixture may.
    $clinician = rmeClinicalAuthor();

    expect($consents->canAuthorRmeForPatient($visit->patient_id, $clinician))->toBeTrue()
        ->and($consents->canAuthorOdontogramFor($visit, $clinician))->toBeTrue();
});
