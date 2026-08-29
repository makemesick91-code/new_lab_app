<?php

/**
 * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1
 *
 * "Kunjungan Baru" chooses a registered patient through ONE searchable control
 * backed by an authorized, branch-scoped, bounded server search.
 *
 * What these tests pin, in order of how badly it would hurt to lose it:
 *  1. The response never carries phone / WhatsApp / KTP / address / DOB / email.
 *     The control this replaced rendered every patient's phone into the page.
 *  2. SUPERSEDED by REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1. Branch scope
 *     used to come from RmeWorkingBranchScope, so a context-bound role saw only
 *     its own working branch. Registration identity lookup is now GLOBAL across
 *     the RME patient registry. What survives unchanged: it still fails closed
 *     without a working context, and a request `branch_id` is still never read
 *     — it can neither widen the lookup nor decide the visit's branch.
 *  3. The page preloads NO patient list.
 *  4. Exactly one patient control exists on the form.
 *  5. `patient_id` is re-authorized at submit time, so being invisible in the
 *     dropdown is not the only thing stopping a crafted id.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-08-29 09:00:00'));

    $this->clinic = Clinic::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);

    $this->ldk2 = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->tkm1 = Branch::factory()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->nonRme = Branch::factory()->create(['code' => 'NRME', 'name' => 'Cabang Non RME', 'is_rme_enabled' => false, 'is_active' => true]);

    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    rmeMakeDoctorOnline($this->doctor, $this->ldk2);

    // Not context-bound: scope is the full active RME-enabled set.
    $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);

    $this->nurbaya = Patient::factory()->create([
        'name' => 'Nurbaya',
        'medical_record_number' => 'DG-LDK2-2025-8445',
        'branch_id' => $this->ldk2->id,
        'phone' => '081234567890',
        'whatsapp_number' => '081234567890',
        'ktp_number' => '7371010101010001',
        'address' => 'Jalan Rahasia 1',
        'email' => 'nurbaya@example.test',
    ]);

    $this->nurAisyah = Patient::factory()->create([
        'name' => 'Nur Aisyah',
        'medical_record_number' => 'DG-LDK2-2026-0128',
        'branch_id' => $this->ldk2->id,
    ]);

    $this->budi = Patient::factory()->create([
        'name' => 'Budi Santoso',
        'medical_record_number' => 'DG-TKM1-2025-0002',
        'branch_id' => $this->tkm1->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** Search as the default (non-context-bound) actor. */
function comboboxSearch(string $term, array $extra = []): array
{
    return test()->actingAs(test()->actor)
        ->getJson(route('rme.visits.patient-search', array_merge(['q' => $term], $extra)))
        ->assertOk()
        ->json();
}

// ---------------------------------------------------------------------------
// 1. One control — the old pair is gone
// ---------------------------------------------------------------------------

it('renders exactly one patient control on Kunjungan Baru', function () {
    $html = $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    // One searchable combobox, one canonical hidden value, no native select.
    expect(substr_count($html, 'data-patient-combobox'))->toBe(1)
        ->and(substr_count($html, 'name="patient_id"'))->toBe(1)
        ->and($html)->not->toContain('<select name="patient_id"')
        ->and($html)->not->toContain('- Pilih pasien terdaftar -');
});

it('keeps the form free of the retired dual-control component', function () {
    $form = file_get_contents(base_path('resources/views/rme/visits/_form.blade.php'));

    expect($form)->toContain('x-patient-combobox')
        ->and($form)->not->toContain('x-patient-search-select');
});

it('exposes the searchable combobox with its accessible roles', function () {
    $html = $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('role="combobox"')
        ->and($html)->toContain('role="listbox"')
        ->and($html)->toContain('Cari nama atau nomor RM')
        ->and($html)->toContain('onKeydown($event)');
});

// ---------------------------------------------------------------------------
// 2. No preload — the page must not ship the patient set
// ---------------------------------------------------------------------------

it('does not preload any patient into the create page', function () {
    $html = $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Nurbaya')
        ->and($html)->not->toContain('DG-LDK2-2025-8445')
        ->and($html)->not->toContain('Budi Santoso')
        ->and($html)->not->toContain('081234567890');
});

// ---------------------------------------------------------------------------
// 3. Search behaviour
// ---------------------------------------------------------------------------

it('finds a patient by exact and partial name, case-insensitively', function () {
    expect(collect(comboboxSearch('Nurbaya')['results'])->pluck('id'))->toContain($this->nurbaya->id);

    $partial = collect(comboboxSearch('nur')['results'])->pluck('id');
    expect($partial)->toContain($this->nurbaya->id)
        ->and($partial)->toContain($this->nurAisyah->id);

    expect(collect(comboboxSearch('NUR')['results'])->pluck('id'))->toContain($this->nurbaya->id);
});

it('finds a patient by exact and partial Nomor RM', function () {
    expect(collect(comboboxSearch('DG-LDK2-2025-8445')['results'])->pluck('id'))->toContain($this->nurbaya->id);
    expect(collect(comboboxSearch('8445')['results'])->pluck('id'))->toContain($this->nurbaya->id);

    $prefix = collect(comboboxSearch('dg-ldk2')['results'])->pluck('id');
    expect($prefix)->toContain($this->nurbaya->id)
        ->and($prefix)->toContain($this->nurAisyah->id);
});

it('returns nothing for a query that matches no patient', function () {
    $payload = comboboxSearch('zzzzz');

    expect($payload['results'])->toBe([])
        ->and($payload['searched'])->toBeTrue();
});

it('never dumps patients for an empty or too-short query', function () {
    $empty = comboboxSearch('');
    $single = comboboxSearch('n');

    expect($empty['results'])->toBe([])
        ->and($empty['searched'])->toBeFalse()
        ->and($single['results'])->toBe([])
        ->and($single['too_short'])->toBeTrue()
        ->and($single['min_length'])->toBe(PatientSelectorSearchService::MIN_QUERY_LENGTH);
});

it('treats LIKE wildcards in the query as literal characters', function () {
    // '%' must not become "match everything".
    expect(comboboxSearch('%')['results'])->toBe([])
        ->and(comboboxSearch('%%')['results'])->toBe([])
        ->and(comboboxSearch('_a')['results'])->toBe([]);
});

it('bounds the result set server-side and ignores a client-supplied limit', function () {
    Patient::factory()->count(40)->create([
        'branch_id' => $this->ldk2->id,
        'name' => 'Massal Pasien',
    ]);

    $bounded = comboboxSearch('Massal');
    $forced = comboboxSearch('Massal', ['limit' => 10000, 'per_page' => 10000]);

    expect(count($bounded['results']))->toBe(PatientSelectorSearchService::RESULT_LIMIT)
        ->and(count($forced['results']))->toBe(PatientSelectorSearchService::RESULT_LIMIT);
});

// ---------------------------------------------------------------------------
// 4. Privacy — least disclosure
// ---------------------------------------------------------------------------

it('returns only the four identity fields the dropdown draws', function () {
    $results = comboboxSearch('Nurbaya')['results'];

    expect($results)->toHaveCount(1)
        ->and(array_keys($results[0]))
        ->toBe(['id', 'name', 'medical_record_number', 'branch_label']);
});

it('never exposes KTP, phone, WhatsApp, address, date of birth or email', function () {
    $raw = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Nurbaya']))
        ->assertOk()
        ->getContent();

    expect($raw)->not->toContain('7371010101010001')
        ->and($raw)->not->toContain('081234567890')
        ->and($raw)->not->toContain('Jalan Rahasia')
        ->and($raw)->not->toContain('nurbaya@example.test')
        ->and($raw)->not->toContain('ktp')
        ->and($raw)->not->toContain('phone')
        ->and($raw)->not->toContain('whatsapp')
        ->and($raw)->not->toContain('address')
        ->and($raw)->not->toContain('date_of_birth');
});

// ---------------------------------------------------------------------------
// 5. Authorization and branch scope
// ---------------------------------------------------------------------------

it('requires authentication', function () {
    $this->getJson(route('rme.visits.patient-search', ['q' => 'nur']))->assertUnauthorized();
});

it('denies a user who may not register a visit', function () {
    $viewer = userWith(['view_clinic_visits']);

    $this->actingAs($viewer)
        ->getJson(route('rme.visits.patient-search', ['q' => 'nur']))
        ->assertForbidden();
});

it('lets a context-bound Admin Klinik reach every RME branch (global identity lookup)', function () {
    // SUPERSEDED RULE: this used to assert the operator saw ONLY its own working
    // branch. REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 deliberately reversed
    // that: a patient registered at Telkomas must be findable by the Landak
    // operator. The visit branch is a separate authority and is asserted below.
    $admin = rmeAdminClinicUser($this->ldk2);
    $admin->givePermissionTo('manage_clinic_visits');

    $ids = collect($this->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']))
        ->assertOk()
        ->json('results'))->pluck('id');

    expect($ids)->toContain($this->nurbaya->id)
        ->and($ids)->toContain($this->budi->id);
});

it('fails closed for a context-bound role with no active working context', function () {
    $admin = userInRole('Admin Klinik'); // deliberately no online context
    $admin->givePermissionTo('manage_clinic_visits');

    $response = $this->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']));

    // Either the RME online-context gate intercepts, or the search itself returns
    // nothing. What must never happen is a populated cross-branch result set.
    if ($response->status() === 200) {
        expect($response->json('results'))->toBe([]);
    } else {
        expect($response->status())->toBeIn([302, 403]);
    }
});

it('still ignores a request branch_id — it can neither widen, narrow nor redirect the scope', function () {
    // The endpoint reads no branch at all. Now that the scope is global, the
    // point is the mirror image of the original one: a crafted branch_id cannot
    // NARROW the result set to one branch either, and it certainly cannot become
    // the branch a visit is later created at.
    $admin = rmeAdminClinicUser($this->ldk2);
    $admin->givePermissionTo('manage_clinic_visits');

    $withCraftedBranch = collect($this->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-', 'branch_id' => $this->tkm1->id]))
        ->assertOk()
        ->json('results'))->pluck('id');

    $withoutBranch = collect($this->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']))
        ->assertOk()
        ->json('results'))->pluck('id');

    expect($withCraftedBranch->sort()->values()->all())
        ->toBe($withoutBranch->sort()->values()->all())
        ->and($withCraftedBranch)->toContain($this->nurbaya->id)
        ->and($withCraftedBranch)->toContain($this->budi->id);
});

it('keeps the daily branch lock intact while the lookup stays global on both sides of a mid-day change', function () {
    // SUPERSEDED RULE: the search used to follow the working branch, so this
    // test proved the daily lock by watching the RESULT SET move. It cannot do
    // that any more — the lookup is global before and after. The lock itself is
    // untouched and is still asserted directly, which is the stronger claim.
    $admin = rmeAdminClinicUser($this->ldk2);
    $admin->givePermissionTo('manage_clinic_visits');

    $before = collect($this->actingAs($admin)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']))->json('results'))->pluck('id');

    expect($before)->toContain($this->nurbaya->id)
        ->and($before)->toContain($this->budi->id);

    // The daily branch lock still owns the move: an operator cannot re-point
    // their own working branch mid-day just because they can now SEE that
    // branch's patients.
    expect(fn () => rmeMakeAdminClinicActive($admin, $this->tkm1))
        ->toThrow(ValidationException::class);

    expect(app(RmeWorkingBranchScope::class)->activeBranchId($admin->fresh()))->toBe($this->ldk2->id);

    // Super Admin approves the single-use change; the WORKING BRANCH moves...
    $approvals = app(BranchChangeApprovalService::class);
    $request = $approvals->request($admin, (int) $this->tkm1->id, 'Pindah cabang untuk shift sore.');
    $approvals->approve((int) $request->id, userInRole('Super Admin'));

    expect(app(RmeWorkingBranchScope::class)->activeBranchId($admin->fresh()))->toBe($this->tkm1->id);

    // ...and the lookup is still global, exactly as it was before the move.
    $after = collect($this->actingAs($admin->fresh())
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']))->json('results'))->pluck('id');

    expect($after)->toContain($this->budi->id)
        ->and($after)->toContain($this->nurbaya->id);
});

it('keeps a patient of a non-RME branch out of the selector', function () {
    $outsider = Patient::factory()->create([
        'name' => 'Nurul Non RME',
        'medical_record_number' => 'DG-NRME-2025-0001',
        'branch_id' => $this->nonRme->id,
    ]);

    expect(collect(comboboxSearch('Nurul')['results'])->pluck('id'))->not->toContain($outsider->id);
});

it('keeps legacy patients without a Cabang RME selectable', function () {
    $legacy = Patient::factory()->create([
        'name' => 'Nurlela Legacy',
        'medical_record_number' => 'MRN-LEGACY01',
        'branch_id' => null,
    ]);

    expect(collect(comboboxSearch('Nurlela')['results'])->pluck('id'))->toContain($legacy->id);
});

it('narrows a doctor to the patients already in their RM scope', function () {
    $doctorUser = doctorWithOnlineContext($this->ldk2);
    $doctorUser->givePermissionTo('manage_clinic_visits');
    $doctor = app(DoctorUserResolver::class)->resolveForUser($doctorUser);

    ClinicVisit::factory()->create([
        'patient_id' => $this->nurbaya->id,
        'doctor_id' => $doctor->id,
        'branch_id' => $this->ldk2->id,
    ]);

    $ids = collect($this->actingAs($doctorUser)
        ->getJson(route('rme.visits.patient-search', ['q' => 'DG-']))
        ->assertOk()
        ->json('results'))->pluck('id');

    expect($ids)->toContain($this->nurbaya->id)
        ->and($ids)->not->toContain($this->nurAisyah->id);
});

// ---------------------------------------------------------------------------
// 6. Submit-time authorization — visibility is not authorization
// ---------------------------------------------------------------------------

it('creates the visit for an explicitly selected in-scope patient', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->ldk2->id,
            'patient_id' => $this->nurbaya->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::firstWhere('patient_id', $this->nurbaya->id);

    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->ldk2->id)
        ->and($visit->status)->toBe(ClinicVisit::STATUS_REGISTERED)
        ->and($visit->queue_number)->not->toBeNull();
});

it('accepts a patient from another branch and creates the visit at the operator branch', function () {
    // SUPERSEDED RULE: this used to reject a cross-branch patient_id. Under
    // REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 that selection is legitimate —
    // and the assertion that matters moves to WHERE the visit lands. The IDOR
    // guard is unchanged and still proven by the non-RME, nonexistent-id and
    // no-working-context cases around this one.
    $admin = rmeAdminClinicUser($this->ldk2);
    $admin->givePermissionTo('manage_clinic_visits');

    $this->actingAs($admin)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->tkm1->id, // crafted: the patient's origin branch
            'patient_id' => $this->budi->id, // TKM1 patient, served at Landak today
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasNoErrors();

    $visit = ClinicVisit::firstWhere('patient_id', $this->budi->id);

    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->ldk2->id)
        ->and($visit->branch_id)->not->toBe($this->tkm1->id)
        ->and($this->budi->fresh()->medical_record_number)->toBe('DG-TKM1-2025-0002')
        ->and($this->budi->fresh()->branch_id)->toBe($this->tkm1->id);
});

it('rejects a patient of a non-RME branch even when the id is real', function () {
    $outsider = Patient::factory()->create(['branch_id' => $this->nonRme->id]);

    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->ldk2->id,
            'patient_id' => $outsider->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('patient_id');

    expect(ClinicVisit::where('patient_id', $outsider->id)->exists())->toBeFalse();
});

it('rejects a patient id that does not exist at all', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->ldk2->id,
            'patient_id' => 999999,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('patient_id');
});

// ---------------------------------------------------------------------------
// 7. Pasien Baru is untouched, and never inherits a stale selection
// ---------------------------------------------------------------------------

it('drops a stale existing-patient selection when registering a new patient', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'patient_id' => $this->nurbaya->id, // left behind by the combobox
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Combobox',
                'branch_id' => $this->ldk2->id,
                'registered_at' => '2026-08-29',
                'manual_rm_number' => '0099',
            ],
        ])
        ->assertRedirect();

    $created = Patient::firstWhere('name', 'Pasien Baru Combobox');

    expect($created)->not->toBeNull()
        ->and($created->medical_record_number)->toBe('DG-LDK2-2026-0099')
        ->and(ClinicVisit::where('patient_id', $created->id)->exists())->toBeTrue()
        ->and(ClinicVisit::where('patient_id', $this->nurbaya->id)->exists())->toBeFalse();
});

it('nulls a stale patient_id in the validated payload of a new-patient registration', function () {
    // Defence in depth, asserted at the layer that owns it. ClinicVisitService
    // also overwrites patient_id when it creates the patient, but the REQUEST
    // contract must not depend on that: the browser's cleared selection is
    // re-asserted server-side, so the value never reaches the service at all.
    $request = StoreClinicVisitRequest::create(route('rme.visits.store'), 'POST', [
        'patient_mode' => 'new',
        'patient_id' => $this->nurbaya->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => [
            'name' => 'Pasien Baru Kontrak',
            'branch_id' => $this->ldk2->id,
            'registered_at' => '2026-08-29',
            'manual_rm_number' => '0123',
        ],
    ]);

    $request->setUserResolver(fn () => $this->actor);
    $request->setContainer(app());
    $request->setRedirector(app(Redirector::class));
    $request->validateResolved();

    expect($request->validated()['patient_id'])->toBeNull();
});

it('clears the selection in the browser when switching to Pasien Baru', function () {
    $form = file_get_contents(base_path('resources/views/rme/visits/_form.blade.php'));
    $component = file_get_contents(base_path('resources/views/components/patient-combobox.blade.php'));

    expect($form)->toContain("new CustomEvent('patient-selector-reset')")
        ->and($component)->toContain('x-on:patient-selector-reset.window="resetSelection()"');
});

// ---------------------------------------------------------------------------
// 8. Prefill is authorized too
// ---------------------------------------------------------------------------

it('prefills a deep-linked patient only when the operator may select it', function () {
    $admin = rmeAdminClinicUser($this->ldk2);
    $admin->givePermissionTo('manage_clinic_visits');

    $allowed = $this->actingAs($admin)
        ->get(route('rme.visits.create', ['patient_id' => $this->nurbaya->id]))
        ->assertOk()
        ->getContent();

    expect($allowed)->toContain('DG-LDK2-2025-8445');

    // SUPERSEDED HALF: a patient of ANOTHER RME branch is now a legitimate
    // selection, so a deep link to one legitimately prefills.
    $crossBranch = $this->actingAs($admin)
        ->get(route('rme.visits.create', ['patient_id' => $this->budi->id]))
        ->assertOk()
        ->getContent();

    expect($crossBranch)->toContain('DG-TKM1-2025-0002');

    // SURVIVING HALF: a patient outside the registry still prefills nothing, so
    // a crafted link cannot leak a name the operator may not select.
    $outsider = Patient::factory()->create([
        'name' => 'Outsider NonRme',
        'medical_record_number' => 'DG-NRME-2026-0009',
        'branch_id' => $this->nonRme->id,
    ]);

    $denied = $this->actingAs($admin)
        ->get(route('rme.visits.create', ['patient_id' => $outsider->id]))
        ->assertOk()
        ->getContent();

    expect($denied)->not->toContain('Outsider NonRme')
        ->and($denied)->not->toContain('DG-NRME-2026-0009');
});

// ---------------------------------------------------------------------------
// 9. Service and repository contract
// ---------------------------------------------------------------------------

it('fails closed in the repository when the branch scope is empty', function () {
    $patients = app(PatientRepositoryInterface::class);

    expect($patients->searchSelectable([], 'nur', 15))->toBeEmpty()
        ->and($patients->findSelectable([], $this->nurbaya->id))->toBeNull();
});

it('fails closed in the service for an unauthenticated caller', function () {
    // RmeWorkingBranchScope answers "every active RME branch" for a null user
    // because its own callers are always behind `auth`. This selector is also
    // rendered from a Blade component, so it must refuse rather than inherit
    // that estate-wide default.
    $service = app(PatientSelectorSearchService::class);

    expect($service->search(null, 'Nurbaya')['results'])->toBe([])
        ->and($service->isSelectable(null, $this->nurbaya->id))->toBeFalse()
        ->and($service->selectedOption(null, $this->nurbaya->id))->toBeNull();
});

it('agrees between what is searchable and what is submittable', function () {
    $service = app(PatientSelectorSearchService::class);

    expect($service->isSelectable($this->actor, $this->nurbaya->id))->toBeTrue()
        ->and($service->isSelectable($this->actor, null))->toBeFalse()
        ->and($service->isSelectable($this->actor, 0))->toBeFalse();
});
