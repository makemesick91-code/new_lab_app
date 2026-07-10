<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Storage;

/**
 * FIX-LAB-REQUEST-RME-BRANCH-SEARCHABLE-FIELDS
 *
 * Klinik on the V2 branch lab request = Cabang RME (active, is_rme_enabled),
 * locked to the server-resolved BranchContext branch; Pasien / Dokter /
 * Layanan Lab are searchable-dropdown catalogs scoped server-side to that
 * branch, and every hidden id is re-validated on store.
 */
beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->mainBranch = Branch::factory()->main()->create(); // is_rme_enabled defaults true
    $this->otherBranch = Branch::factory()->create([
        'code' => 'TKM9',
        'name' => 'Cabang Lain Tidak Terkait',
    ]);
});

/** @param array<string,mixed> $overrides */
function rmeAlignedRequestPayload(Branch $branch, array $overrides = []): array
{
    $doctor = Doctor::factory()->create(['branch_id' => $branch->id]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'doctor_id' => $doctor->id]);
    $service = LabService::factory()->create();

    return array_merge([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'order_date' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'priority' => 'NORMAL',
        'notes' => 'Order via Cabang RME',
        'items' => [
            ['lab_service_id' => $service->id, 'tooth_number' => '21', 'quantity' => 1, 'unit_price' => 500000],
        ],
        'spk_photo' => fakeEvidencePhoto('spk.png'),
        'model_photo' => fakeEvidencePhoto('model.png'),
    ], $overrides);
}

// ---------------------------------------------------------------------------
// A. Klinik = Cabang RME (create page)
// ---------------------------------------------------------------------------

it('locks the Klinik field to the active RME branch and never lists other branches', function () {
    $response = $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertViewIs('lab-workflow.requests.create')
        ->assertSee('Klinik (Cabang RME)')
        ->assertSee($this->mainBranch->name)
        ->assertDontSee($this->otherBranch->name);

    expect($response->viewData('branch')->id)->toBe($this->mainBranch->id);
});

it('blocks the create form with a clear warning when the active branch is not RME-enabled', function () {
    $this->mainBranch->update(['is_rme_enabled' => false]);
    $this->otherBranch->update(['is_rme_enabled' => false]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('belum terhubung ke Cabang RME aktif')
        ->assertDontSee('Simpan Permintaan');
});

it('redirects guests away from the create page', function () {
    $this->get(route('lab-workflow-requests.create'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// B. Searchable Pasien — scoped, capped, no PII
// ---------------------------------------------------------------------------

it('only offers patients of the active RME branch or legacy unassigned patients', function () {
    $own = Patient::factory()->create(['branch_id' => $this->mainBranch->id, 'name' => 'Pasien Cabang Sendiri']);
    $legacy = Patient::factory()->create(['branch_id' => null, 'name' => 'Pasien Legacy Tanpa Cabang']);
    $foreign = Patient::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'Pasien Cabang Lain Rahasia']);
    $inactive = Patient::factory()->create(['branch_id' => $this->mainBranch->id, 'is_active' => false, 'name' => 'Pasien Nonaktif Tersembunyi']);

    $response = $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('Pasien Cabang Sendiri')
        ->assertSee('Pasien Legacy Tanpa Cabang')
        ->assertDontSee('Pasien Cabang Lain Rahasia')
        ->assertDontSee('Pasien Nonaktif Tersembunyi');

    $ids = collect($response->viewData('patients'))->pluck('id');
    expect($ids)->toContain($own->id)
        ->toContain($legacy->id)
        ->not->toContain($foreign->id)
        ->not->toContain($inactive->id);
});

it('never exposes KTP/NIK in the patient dropdown catalog', function () {
    Patient::factory()->create([
        'branch_id' => $this->mainBranch->id,
        'name' => 'Pasien Dengan KTP',
        'ktp_number' => '7371012345678901',
    ]);

    $response = $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('Pasien Dengan KTP')
        ->assertDontSee('7371012345678901');

    $catalog = collect($response->viewData('patients'))->map(fn ($p) => (array) $p);
    expect($catalog->first(fn ($p) => $p['name'] === 'Pasien Dengan KTP'))
        ->toHaveKeys(['id', 'code', 'name'])
        ->not->toHaveKey('ktp_number');
});

it('labels patients with their medical record number for RM-number search', function () {
    Patient::factory()->create([
        'branch_id' => $this->mainBranch->id,
        'name' => 'Pasien Ber-RM',
        'medical_record_number' => 'DG-TKM1-2026-0001',
    ]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('DG-TKM1-2026-0001');
});

it('caps the patient catalog to the bounded local-search limit', function () {
    expect(LabWorkflowRequestService::PATIENT_OPTION_LIMIT)->toBe(500);

    $service = app(LabWorkflowRequestService::class);
    Patient::factory()->count(3)->create(['branch_id' => $this->mainBranch->id]);

    $this->actingAs(userWith(['create_lab_branch_requests']));
    $options = $service->formOptionsForActiveBranch();

    expect($options['patients']->count())->toBeLessThanOrEqual(500);
});

// ---------------------------------------------------------------------------
// C. Searchable Dokter — active + branch-compatible only
// ---------------------------------------------------------------------------

it('only offers active doctors that are valid for the active RME branch', function () {
    $own = Doctor::factory()->create(['branch_id' => $this->mainBranch->id, 'name' => 'drg. Cabang Sendiri']);
    $pivotOnly = Doctor::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'drg. Pivot Utama']);
    $pivotOnly->branches()->syncWithoutDetaching([$this->mainBranch->id]);
    $unbound = Doctor::factory()->create(['branch_id' => null, 'name' => 'drg. Legacy Global']);
    $unbound->branches()->detach();
    $foreign = Doctor::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'drg. Cabang Lain Saja']);
    $inactive = Doctor::factory()->create(['branch_id' => $this->mainBranch->id, 'is_active' => false, 'name' => 'drg. Nonaktif']);

    $response = $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk();

    $ids = collect($response->viewData('doctors'))->pluck('id');
    expect($ids)->toContain($own->id)
        ->toContain($pivotOnly->id)
        ->toContain($unbound->id)
        ->not->toContain($foreign->id)
        ->not->toContain($inactive->id);
});

// ---------------------------------------------------------------------------
// D. Searchable Layanan Lab — active, non-deleted only
// ---------------------------------------------------------------------------

it('only offers active non-deleted lab services with their code', function () {
    $active = LabService::factory()->create(['name' => 'Crown Zirconia Aktif', 'code' => 'SVC-AKTIF1']);
    $inactive = LabService::factory()->create(['name' => 'Layanan Nonaktif Lama', 'is_active' => false]);
    $deleted = LabService::factory()->create(['name' => 'Layanan Terhapus']);
    $deleted->delete();

    $response = $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('SVC-AKTIF1')
        ->assertDontSee('Layanan Nonaktif Lama')
        ->assertDontSee('Layanan Terhapus');

    $ids = collect($response->viewData('labServices'))->pluck('id');
    expect($ids)->toContain($active->id)
        ->not->toContain($inactive->id)
        ->not->toContain($deleted->id);
});

// ---------------------------------------------------------------------------
// E. Store — server-side re-validation (IDOR / injection)
// ---------------------------------------------------------------------------

it('creates a V2 draft for the context branch with a null legacy clinic reference', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch))
        ->assertRedirect();

    $order = LabOrder::latest('id')->first();
    expect($order->workflow_version)->toBe(LabOrder::WORKFLOW_V2)
        ->and($order->status)->toBe(LabWorkflowState::DRAFT)
        ->and($order->branch_id)->toBe($this->mainBranch->id)
        ->and($order->clinic_id)->toBeNull();
});

it('ignores a crafted branch_id and always uses the server-resolved branch', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'branch_id' => $this->otherBranch->id,
        ]))
        ->assertRedirect();

    $order = LabOrder::latest('id')->first();
    expect($order->branch_id)->toBe($this->mainBranch->id);
});

it('rejects store entirely when the context branch is not an active RME branch', function () {
    $payload = rmeAlignedRequestPayload($this->mainBranch);
    $this->mainBranch->update(['is_rme_enabled' => false]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), $payload)
        ->assertSessionHasErrors('branch_id');

    expect(LabOrder::count())->toBe(0);
});

it('rejects a crafted patient from another branch', function () {
    $foreignPatient = Patient::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'patient_id' => $foreignPatient->id,
        ]))
        ->assertSessionHasErrors('patient_id');

    expect(LabOrder::count())->toBe(0);
});

it('rejects inactive and soft-deleted patients', function () {
    $inactive = Patient::factory()->create(['branch_id' => $this->mainBranch->id, 'is_active' => false]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'patient_id' => $inactive->id,
        ]))
        ->assertSessionHasErrors('patient_id');

    $deleted = Patient::factory()->create(['branch_id' => $this->mainBranch->id]);
    $deleted->delete();

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'patient_id' => $deleted->id,
        ]))
        ->assertSessionHasErrors('patient_id');

    expect(LabOrder::count())->toBe(0);
});

it('accepts a legacy unassigned patient (branch_id null) by existing design', function () {
    $legacy = Patient::factory()->create(['branch_id' => null]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'patient_id' => $legacy->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(LabOrder::latest('id')->first()->patient_id)->toBe($legacy->id);
});

it('rejects a crafted doctor bound to another branch', function () {
    $foreignDoctor = Doctor::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'doctor_id' => $foreignDoctor->id,
        ]))
        ->assertSessionHasErrors('doctor_id');

    expect(LabOrder::count())->toBe(0);
});

it('rejects an inactive doctor', function () {
    $inactive = Doctor::factory()->create(['branch_id' => $this->mainBranch->id, 'is_active' => false]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'doctor_id' => $inactive->id,
        ]))
        ->assertSessionHasErrors('doctor_id');

    expect(LabOrder::count())->toBe(0);
});

it('rejects a crafted inactive or soft-deleted lab service', function () {
    $inactive = LabService::factory()->create(['is_active' => false]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'items' => [['lab_service_id' => $inactive->id, 'quantity' => 1, 'unit_price' => 100]],
        ]))
        ->assertSessionHasErrors('items.0.lab_service_id');

    $deleted = LabService::factory()->create();
    $deleted->delete();

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch, [
            'items' => [['lab_service_id' => $deleted->id, 'quantity' => 1, 'unit_price' => 100]],
        ]))
        ->assertSessionHasErrors('items.0.lab_service_id');

    expect(LabOrder::count())->toBe(0);
});

it('keeps old input for the searchable fields when validation fails', function () {
    $payload = rmeAlignedRequestPayload($this->mainBranch, ['due_date' => null]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), $payload)
        ->assertSessionHasErrors('due_date')
        ->assertSessionHasInput('patient_id', (string) $payload['patient_id'])
        ->assertSessionHasInput('doctor_id', (string) $payload['doctor_id']);
});

// ---------------------------------------------------------------------------
// F. Lab Workflow V2 regression — pickup pipeline intact
// ---------------------------------------------------------------------------

it('still submits the created request into WAITING_PICKUP with an idempotent pickup task', function () {
    $user = userWith(['create_lab_branch_requests']);

    $this->actingAs($user)
        ->post(route('lab-workflow-requests.store'), rmeAlignedRequestPayload($this->mainBranch))
        ->assertRedirect();

    $order = LabOrder::latest('id')->first();

    $this->actingAs($user)
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(LabWorkflowState::WAITING_PICKUP)
        ->and(LabPickupTask::where('lab_order_id', $order->id)->count())->toBe(1);

    // Idempotent re-submit never duplicates the pickup task.
    $this->actingAs($user)->post(route('lab-workflow-requests.submit-pickup', $order));
    expect(LabPickupTask::where('lab_order_id', $order->id)->count())->toBe(1);
});

it('conversion-path drafts may still carry a legacy clinic reference', function () {
    // createV2Draft (used by the RME candidate conversion) accepts a nullable
    // legacy clinic_id — both null and a real mst_clinics id must persist.
    $service = app(LabWorkflowRequestService::class);
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create(['branch_id' => $this->mainBranch->id]);
    $patient = Patient::factory()->create(['branch_id' => $this->mainBranch->id, 'doctor_id' => $doctor->id]);
    $labService = LabService::factory()->create();

    $base = [
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'items' => [['lab_service_id' => $labService->id, 'quantity' => 1, 'unit_price' => 100]],
    ];

    $withClinic = $service->createV2Draft($base + ['clinic_id' => $clinic->id], $this->mainBranch->id, superAdmin());
    $withoutClinic = $service->createV2Draft($base, $this->mainBranch->id, superAdmin());

    expect($withClinic->clinic_id)->toBe($clinic->id)
        ->and($withoutClinic->clinic_id)->toBeNull()
        ->and($withoutClinic->workflow_version)->toBe(LabOrder::WORKFLOW_V2);
});
