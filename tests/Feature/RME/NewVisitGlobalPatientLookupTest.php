<?php

/**
 * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1
 *
 * The one distinction this suite exists to hold in place:
 *
 *     PATIENT IDENTITY  !=  VISIT BRANCH AUTHORITY
 *
 * A patient may come from ANY branch. The visit happens at the OPERATOR's
 * current authorized branch. Both halves are asserted here, because either one
 * alone is a defect: a lookup that stays branch-scoped fails the operator at
 * the counter, and a lookup that drags the patient's origin branch into the
 * visit silently registers people at the wrong clinic.
 *
 * Sections:
 *   1. Global identity lookup — by name, by Nomor RM, across every RME branch.
 *   2. The registry's edges — non-RME out, legacy null-branch in, bounds kept.
 *   3. Least disclosure — four identity fields, never a phone or a KTP.
 *   4. Authorization — global is not unrestricted, and it still fails closed.
 *   5. Visit branch authority — the operator's context wins, always.
 *   6. Patient integrity — same row, same RM, no duplicate, no context move.
 *   7. The boundary this sprint refuses to cross — identity is not history.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);

    // The four canonical RME branches of the estate, plus one non-RME branch
    // that must stay outside the registry even though "global" now means
    // "every branch".
    $this->ldk2 = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->tkm1 = Branch::factory()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->atg3 = Branch::factory()->create(['code' => 'ATG3', 'name' => 'Cabang Antang', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->sun4 = Branch::factory()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->nonRme = Branch::factory()->create(['code' => 'NRME', 'name' => 'Cabang Non RME', 'is_rme_enabled' => false, 'is_active' => true]);

    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    rmeMakeDoctorOnline($this->doctor, $this->ldk2);

    // One shared given name across four different branches. Before this sprint
    // an operator could only ever see the one from their own branch.
    $this->jefriLdk2 = Patient::factory()->create([
        'name' => 'Jefri Ansar',
        'medical_record_number' => 'DG-LDK2-2024-1101',
        'branch_id' => $this->ldk2->id,
    ]);

    $this->jefriTkm1 = Patient::factory()->create([
        'name' => 'Jefri Bahtiar',
        'medical_record_number' => 'DG-TKM1-2024-1234',
        'branch_id' => $this->tkm1->id,
        'phone' => '081234500001',
        'whatsapp_number' => '081234500001',
        'ktp_number' => '7371010101010009',
        'address' => 'Jalan Telkomas 9',
        'email' => 'jefri.b@example.test',
    ]);

    $this->jefriAtg3 = Patient::factory()->create([
        'name' => 'Jefri Chandra',
        'medical_record_number' => 'DG-ATG3-2025-0077',
        'branch_id' => $this->atg3->id,
    ]);

    $this->jefriSun4 = Patient::factory()->create([
        'name' => 'Jefri Darmawan',
        'medical_record_number' => 'DG-SUN4-2026-0450',
        'branch_id' => $this->sun4->id,
    ]);
});

/** The canonical registration operator: Admin Klinik working at Landak. */
function globalLookupOperator(Branch $branch): User
{
    $admin = rmeAdminClinicUser($branch);
    $admin->givePermissionTo('manage_clinic_visits');

    return $admin->fresh();
}

/** Search as a given operator and return the result rows. */
function globalLookupSearch(User $user, string $term, array $extra = []): array
{
    return test()->actingAs($user)
        ->getJson(route('rme.visits.patient-search', array_merge(['q' => $term], $extra)))
        ->assertOk()
        ->json('results');
}

function globalLookupIds(User $user, string $term, array $extra = []): Collection
{
    return collect(globalLookupSearch($user, $term, $extra))->pluck('id');
}

// ---------------------------------------------------------------------------
// 1. Global identity lookup
// ---------------------------------------------------------------------------

it('finds patients of every RME branch by name while the operator works at one branch', function () {
    $admin = globalLookupOperator($this->ldk2);

    $ids = globalLookupIds($admin, 'Jefri');

    expect($ids)->toContain($this->jefriLdk2->id)
        ->and($ids)->toContain($this->jefriTkm1->id)
        ->and($ids)->toContain($this->jefriAtg3->id)
        ->and($ids)->toContain($this->jefriSun4->id);
});

it('finds a patient of another branch by exact Nomor RM', function () {
    $admin = globalLookupOperator($this->ldk2);

    expect(globalLookupIds($admin, 'DG-TKM1-2024-1234'))->toContain($this->jefriTkm1->id);
});

it('finds a patient of another branch by partial Nomor RM', function () {
    $admin = globalLookupOperator($this->ldk2);

    expect(globalLookupIds($admin, 'TKM1-2024'))->toContain($this->jefriTkm1->id)
        ->and(globalLookupIds($admin, 'SUN4'))->toContain($this->jefriSun4->id);
});

it('finds the same cross-branch patient for a Perawat operator', function () {
    $perawat = userInRole('Perawat');
    $perawat->givePermissionTo('manage_clinic_visits');
    rmeMakePerawatActive($perawat, $this->ldk2);

    expect(globalLookupIds($perawat->fresh(), 'Jefri'))->toContain($this->jefriTkm1->id);
});

it('labels each result with its origin branch so identical names stay distinguishable', function () {
    $admin = globalLookupOperator($this->ldk2);

    $row = collect(globalLookupSearch($admin, 'DG-TKM1-2024-1234'))->firstWhere('id', $this->jefriTkm1->id);

    expect($row)->not->toBeNull()
        ->and($row['branch_label'])->toContain('Telkomas');
});

// ---------------------------------------------------------------------------
// 2. The registry's edges — global is a wider scope, not a missing one
// ---------------------------------------------------------------------------

it('still keeps a patient of a non-RME branch out of the selector', function () {
    $admin = globalLookupOperator($this->ldk2);

    $outsider = Patient::factory()->create([
        'name' => 'Jefri Non RME',
        'medical_record_number' => 'DG-NRME-2025-0001',
        'branch_id' => $this->nonRme->id,
    ]);

    expect(globalLookupIds($admin, 'Jefri'))->not->toContain($outsider->id);
});

it('still keeps legacy patients without a Cabang RME selectable', function () {
    $admin = globalLookupOperator($this->ldk2);

    $legacy = Patient::factory()->create([
        'name' => 'Jefri Legacy',
        'medical_record_number' => 'MRN-LEGACY99',
        'branch_id' => null,
    ]);

    expect(globalLookupIds($admin, 'Jefri Legacy'))->toContain($legacy->id);
});

it('never dumps the global registry for an empty or too-short query', function () {
    $admin = globalLookupOperator($this->ldk2);

    $empty = test()->actingAs($admin)->getJson(route('rme.visits.patient-search', ['q' => '']))->assertOk()->json();
    $single = test()->actingAs($admin)->getJson(route('rme.visits.patient-search', ['q' => 'J']))->assertOk()->json();
    $none = test()->actingAs($admin)->getJson(route('rme.visits.patient-search'))->assertOk()->json();

    expect($empty['results'])->toBe([])
        ->and($empty['searched'])->toBeFalse()
        ->and($single['results'])->toBe([])
        ->and($single['searched'])->toBeFalse()
        ->and($none['results'])->toBe([])
        ->and($single['min_length'])->toBe(PatientSelectorSearchService::MIN_QUERY_LENGTH);
});

it('bounds the now-global result set server-side and ignores a client-supplied limit', function () {
    $admin = globalLookupOperator($this->ldk2);

    // Spread well past the ceiling across four different branches, so the bound
    // is proven against the widened scope rather than a single branch.
    foreach ([$this->ldk2, $this->tkm1, $this->atg3, $this->sun4] as $index => $branch) {
        Patient::factory()->count(8)->create([
            'name' => 'Bounded Global '.$index.'-'.Str::random(6),
            'branch_id' => $branch->id,
        ]);
    }

    $bounded = globalLookupSearch($admin, 'Bounded Global');
    $forced = globalLookupSearch($admin, 'Bounded Global', ['limit' => 500, 'per_page' => 500]);

    expect(count($bounded))->toBe(PatientSelectorSearchService::RESULT_LIMIT)
        ->and(count($forced))->toBe(PatientSelectorSearchService::RESULT_LIMIT);
});

it('still treats LIKE wildcards as literal characters across the global registry', function () {
    $admin = globalLookupOperator($this->ldk2);

    expect(globalLookupIds($admin, '%%'))->toBeEmpty()
        ->and(globalLookupIds($admin, '__'))->toBeEmpty()
        ->and(globalLookupIds($admin, '!!'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 3. Least disclosure — a wider scope must not mean a wider payload
// ---------------------------------------------------------------------------

it('returns only the four identity fields for a cross-branch match', function () {
    $admin = globalLookupOperator($this->ldk2);

    $row = collect(globalLookupSearch($admin, 'DG-TKM1-2024-1234'))->firstWhere('id', $this->jefriTkm1->id);

    expect($row)->not->toBeNull()
        ->and(array_keys($row))->toBe(['id', 'name', 'medical_record_number', 'branch_label']);
});

it('never exposes phone, WhatsApp, KTP, address, date of birth or email cross-branch', function () {
    $admin = globalLookupOperator($this->ldk2);

    $body = test()->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']))
        ->assertOk()
        ->getContent();

    foreach (['081234500001', '7371010101010009', 'Jalan Telkomas 9', 'jefri.b@example.test'] as $secret) {
        expect($body)->not->toContain($secret);
    }

    foreach (['phone', 'whatsapp', 'ktp', 'nik', 'address', 'date_of_birth', 'birth', 'email', 'occupation'] as $field) {
        expect($body)->not->toContain($field);
    }
});

// ---------------------------------------------------------------------------
// 4. Authorization — global is not unrestricted
// ---------------------------------------------------------------------------

it('requires authentication for the global lookup', function () {
    $this->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']))->assertUnauthorized();
});

it('denies a user who may not register a visit', function () {
    $viewer = userWith(['view_clinic_visits']);

    $this->actingAs($viewer)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']))
        ->assertForbidden();
});

it('still fails closed for a context-bound operator with no active working context', function () {
    $admin = userInRole('Admin Klinik'); // deliberately never given a context
    $admin->givePermissionTo('manage_clinic_visits');

    $response = $this->actingAs($admin)->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']));

    // Either the RME online-context gate intercepts, or the search returns
    // nothing. Global must never mean "an operator working nowhere may read the
    // whole registry".
    if ($response->status() === 200) {
        expect($response->json('results'))->toBe([]);
    } else {
        expect($response->status())->toBeIn([302, 403]);
    }
});

it('still narrows a doctor to their own clinical RM scope', function () {
    // The doctor clinical scope is not a branch scope. Widening branch reach for
    // registration must not widen which patients a doctor may see.
    $doctorUser = doctorWithOnlineContext($this->ldk2);
    $doctorUser->givePermissionTo('manage_clinic_visits');

    // The helper already links a doctor master row to this user; reuse it
    // rather than creating a second one (mst_doctors.user_id is UNIQUE).
    $linked = Doctor::query()->where('user_id', $doctorUser->id)->firstOrFail();

    ClinicVisit::factory()->create([
        'patient_id' => $this->jefriLdk2->id,
        'doctor_id' => $linked->id,
        'branch_id' => $this->ldk2->id,
    ]);

    $ids = globalLookupIds($doctorUser->fresh(), 'Jefri');

    expect($ids)->toContain($this->jefriLdk2->id)
        ->and($ids)->not->toContain($this->jefriTkm1->id);
});

// ---------------------------------------------------------------------------
// 5. Visit branch authority — the operator's context wins, always
// ---------------------------------------------------------------------------

it('creates the visit at the operator branch when the patient came from another branch', function () {
    $admin = globalLookupOperator($this->ldk2);

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriTkm1->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::firstWhere('patient_id', $this->jefriTkm1->id);

    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->ldk2->id)
        ->and($visit->branch_id)->not->toBe($this->tkm1->id)
        ->and($visit->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

it('registers a patient from any RME branch at the operator branch', function () {
    foreach ([
        [$this->jefriTkm1, $this->tkm1],
        [$this->jefriAtg3, $this->atg3],
        [$this->jefriSun4, $this->sun4],
    ] as [$patient, $originBranch]) {
        $admin = globalLookupOperator($this->ldk2);

        $this->actingAs($admin)
            ->post(route('rme.visits.store'), [
                'patient_id' => $patient->id,
                'initial_treatment_id' => $this->treatment->id,
            ])
            ->assertRedirect();

        $visit = ClinicVisit::where('patient_id', $patient->id)->latest('id')->first();

        expect($visit)->not->toBeNull()
            ->and($visit->branch_id)->toBe($this->ldk2->id)
            ->and($visit->branch_id)->not->toBe($originBranch->id);
    }
});

it('works in the reverse direction — a Landak patient registers at Telkomas', function () {
    $admin = globalLookupOperator($this->tkm1);

    expect(globalLookupIds($admin, 'DG-LDK2-2024-1101'))->toContain($this->jefriLdk2->id);

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriLdk2->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::firstWhere('patient_id', $this->jefriLdk2->id);

    expect($visit)->not->toBeNull()->and($visit->branch_id)->toBe($this->tkm1->id);
});

it('never lets a request branch_id decide the visit branch', function () {
    $admin = globalLookupOperator($this->ldk2);

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->atg3->id,       // crafted: a third branch entirely
            'patient_id' => $this->jefriTkm1->id, // origin: a second branch
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::firstWhere('patient_id', $this->jefriTkm1->id);

    expect($visit->branch_id)->toBe($this->ldk2->id)
        ->and($visit->branch_id)->not->toBe($this->atg3->id)
        ->and($visit->branch_id)->not->toBe($this->tkm1->id);
});

it('follows the approved mid-day branch change for the visit, while the lookup stays global throughout', function () {
    $admin = globalLookupOperator($this->ldk2);

    // Global from the first keystroke — the working branch never bounded it.
    expect(globalLookupIds($admin, 'Jefri'))->toContain($this->jefriTkm1->id);

    // The daily branch lock still owns the move: the operator cannot re-point
    // their own working branch mid-day.
    expect(fn () => rmeMakeAdminClinicActive($admin, $this->tkm1))->toThrow(ValidationException::class);

    $this->actingAs($admin->fresh())
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriTkm1->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(ClinicVisit::firstWhere('patient_id', $this->jefriTkm1->id)->branch_id)->toBe($this->ldk2->id);

    // Super Admin approves the single-use change; only NOW does the visit branch move.
    $approvals = app(BranchChangeApprovalService::class);
    $request = $approvals->request($admin, (int) $this->tkm1->id, 'Pindah cabang untuk shift sore.');
    $approvals->approve((int) $request->id, userInRole('Super Admin'));

    $this->actingAs($admin->fresh())
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriLdk2->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(ClinicVisit::firstWhere('patient_id', $this->jefriLdk2->id)->branch_id)->toBe($this->tkm1->id);
});

// ---------------------------------------------------------------------------
// 6. Patient integrity — selection is not a transfer
// ---------------------------------------------------------------------------

it('reuses the existing patient row and never rewrites its RM or branch', function () {
    $admin = globalLookupOperator($this->ldk2);

    $before = Patient::count();
    $originalRm = $this->jefriTkm1->medical_record_number;

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriTkm1->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $patient = $this->jefriTkm1->fresh();

    expect(Patient::count())->toBe($before)
        ->and($patient->id)->toBe($this->jefriTkm1->id)
        ->and($patient->medical_record_number)->toBe($originalRm)
        ->and($patient->medical_record_number)->toStartWith('DG-TKM1-')
        ->and($patient->branch_id)->toBe($this->tkm1->id)
        ->and(Patient::where('medical_record_number', $originalRm)->count())->toBe(1);
});

it('leaves the operator daily branch context exactly where it was', function () {
    $admin = globalLookupOperator($this->ldk2);
    $scope = app(RmeWorkingBranchScope::class);

    expect($scope->activeBranchId($admin))->toBe($this->ldk2->id);

    globalLookupIds($admin, 'Jefri');

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => $this->jefriTkm1->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect($scope->activeBranchId($admin->fresh()))->toBe($this->ldk2->id);
});

it('still rejects a crafted patient_id that is outside the registry entirely', function () {
    $admin = globalLookupOperator($this->ldk2);

    $outsider = Patient::factory()->create(['branch_id' => $this->nonRme->id]);

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => $outsider->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('patient_id');

    expect(ClinicVisit::where('patient_id', $outsider->id)->exists())->toBeFalse();
});

it('still rejects a patient id that does not exist at all', function () {
    $admin = globalLookupOperator($this->ldk2);

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'patient_id' => 999999,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('patient_id');
});

it('rejects a crafted patient_id for a context-bound operator with no working context', function () {
    $admin = userInRole('Admin Klinik');
    $admin->givePermissionTo('manage_clinic_visits');

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->ldk2->id,
            'patient_id' => $this->jefriTkm1->id,
            'initial_treatment_id' => $this->treatment->id,
        ]);

    expect(ClinicVisit::where('patient_id', $this->jefriTkm1->id)->exists())->toBeFalse();
});

it('keeps search selectability and submit selectability the same answer', function () {
    $admin = globalLookupOperator($this->ldk2);
    $service = app(PatientSelectorSearchService::class);

    // Everything the search offers must be submittable, and the reverse.
    foreach ([$this->jefriLdk2, $this->jefriTkm1, $this->jefriAtg3, $this->jefriSun4] as $patient) {
        $offered = globalLookupIds($admin, (string) $patient->medical_record_number)->contains($patient->id);

        expect($offered)->toBeTrue()
            ->and($service->isSelectable($admin, $patient->id))->toBeTrue();
    }

    $outsider = Patient::factory()->create(['branch_id' => $this->nonRme->id, 'name' => 'Jefri Outsider']);

    expect(globalLookupIds($admin, 'Jefri Outsider')->contains($outsider->id))->toBeFalse()
        ->and($service->isSelectable($admin, $outsider->id))->toBeFalse();
});

it('prefills a cross-branch selection on the create page', function () {
    $admin = globalLookupOperator($this->ldk2);

    $option = app(PatientSelectorSearchService::class)->selectedOption($admin, $this->jefriTkm1->id);

    expect($option)->not->toBeNull()
        ->and($option['id'])->toBe($this->jefriTkm1->id)
        ->and($option['medical_record_number'])->toBe('DG-TKM1-2024-1234');
});

// ---------------------------------------------------------------------------
// 7. The boundary this sprint refuses to cross
// ---------------------------------------------------------------------------

it('does not open cross-branch clinical history just because identity is global', function () {
    $admin = globalLookupOperator($this->ldk2);

    // A real visit for the remote patient, at the remote branch.
    $remoteVisit = ClinicVisit::factory()->create([
        'patient_id' => $this->jefriTkm1->id,
        'doctor_id' => $this->doctor->id,
        'branch_id' => $this->tkm1->id,
    ]);

    // Identity discovery: allowed.
    expect(globalLookupIds($admin, 'DG-TKM1-2024-1234'))->toContain($this->jefriTkm1->id);

    // Clinical detail for that other branch's visit: NOT granted by this sprint.
    $show = $this->actingAs($admin)->get(route('rme.visits.show', $remoteVisit));

    expect($show->status())->not->toBe(200);
});
