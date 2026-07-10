<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->mainBranch = Branch::factory()->main()->create();
});

/** @param array<string,mixed> $overrides */
function branchRequestPayload(array $overrides = []): array
{
    $payload = array_merge(labOrderPayload(), [
        'spk_photo' => fakeEvidencePhoto('spk.png'),
        'model_photo' => fakeEvidencePhoto('model.png'),
    ], $overrides);

    // Klinik = Cabang RME: the store FormRequest now rejects doctors bound to
    // another branch, so align the fixture doctor with the active MAIN branch.
    if (! array_key_exists('doctor_id', $overrides)) {
        $main = Branch::where('code', Branch::MAIN_CODE)->first();
        $doctor = Doctor::find($payload['doctor_id']);

        if ($main && $doctor) {
            $doctor->update(['branch_id' => $main->id]);
            $doctor->branches()->syncWithoutDetaching([$main->id]);
        }
    }

    return $payload;
}

function v2DraftWithPhotos(): LabOrder
{
    $service = app(LabWorkflowRequestService::class);

    return $service->createDraft(
        labOrderPayload(),
        fakeEvidencePhoto('spk.png'),
        fakeEvidencePhoto('model.png'),
        superAdmin(),
    );
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('renders the Cabang request workspace for a permitted user', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.index'))
        ->assertOk()
        ->assertViewIs('lab-workflow.requests.index');
});

it('denies the workspace without the branch-request permission', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('lab-workflow-requests.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('lab-workflow-requests.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Draft creation with mandatory photos
// ---------------------------------------------------------------------------

it('creates a V2 draft with SPK + model photos, branch-stamped server-side', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), branchRequestPayload())
        ->assertRedirect();

    $order = LabOrder::latest('id')->first();
    expect($order->workflow_version)->toBe(LabOrder::WORKFLOW_V2);
    expect($order->status)->toBe(LabWorkflowState::DRAFT);
    expect($order->branch_id)->toBe($this->mainBranch->id);

    $evidence = LabWorkflowEvidence::where('lab_order_id', $order->id)->get();
    expect($evidence->pluck('type')->sort()->values()->all())
        ->toBe(['MODEL_PHOTO_BRANCH', 'SPK_PHOTO']);

    foreach ($evidence as $item) {
        expect($item->checksum)->not->toBeNull();
        expect($item->branch_id)->toBe($this->mainBranch->id);
        Storage::disk('local')->assertExists($item->file_path);
    }
});

it('requires the SPK photo', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), branchRequestPayload(['spk_photo' => null]))
        ->assertSessionHasErrors('spk_photo');

    expect(LabOrder::count())->toBe(0);
});

it('requires the model photo', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), branchRequestPayload(['model_photo' => null]))
        ->assertSessionHasErrors('model_photo');

    expect(LabOrder::count())->toBe(0);
});

it('rejects a non-image file as SPK photo', function () {
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), branchRequestPayload([
            'spk_photo' => UploadedFile::fake()->create('spk.pdf', 100, 'application/pdf'),
        ]))
        ->assertSessionHasErrors('spk_photo');
});

it('rejects a polyglot file that only pretends to be an image', function () {
    // Declared image mime, but the real bytes are not an image (service-level
    // getimagesizefromstring guard, beyond the FormRequest rule).
    $fake = UploadedFile::fake()->createWithContent('spk.jpg', '<?php echo "x"; ?>');

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.store'), branchRequestPayload(['spk_photo' => $fake]))
        ->assertSessionHasErrors();

    expect(LabWorkflowEvidence::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Submit for pickup
// ---------------------------------------------------------------------------

it('submits a complete draft to WAITING_PICKUP and creates the pickup task idempotently', function () {
    $order = v2DraftWithPhotos();
    $user = userWith(['create_lab_branch_requests']);

    $this->actingAs($user)
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(LabWorkflowState::WAITING_PICKUP);

    $task = LabPickupTask::where('lab_order_id', $order->id)->get();
    expect($task)->toHaveCount(1);
    expect($task->first()->status)->toBe(LabPickupTask::STATUS_PENDING);
    expect($task->first()->branch_id)->toBe($order->branch_id);

    // Re-submission does not duplicate the task and errors safely (already WAITING_PICKUP -> no legal DRAFT edge).
    $this->actingAs($user)->post(route('lab-workflow-requests.submit-pickup', $order));
    expect(LabPickupTask::where('lab_order_id', $order->id)->count())->toBe(1);
});

it('blocks pickup submission when photos are missing', function () {
    $order = app(LabWorkflowRequestService::class)
        ->createV2Draft(labOrderPayload(), $this->mainBranch->id, superAdmin());

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertSessionHasErrors('evidence');

    expect($order->refresh()->status)->toBe(LabWorkflowState::DRAFT);
    expect(LabPickupTask::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Branch isolation
// ---------------------------------------------------------------------------

it('hides another branch\'s V2 request (404) and blocks cross-branch submission', function () {
    $otherBranch = Branch::factory()->create();
    $order = app(LabWorkflowRequestService::class)
        ->createV2Draft(labOrderPayload(), $otherBranch->id, superAdmin());

    $user = userWith(['create_lab_branch_requests']);

    $this->actingAs($user)
        ->get(route('lab-workflow-requests.show', $order))
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::DRAFT);
});

it('lists only the active branch\'s V2 requests', function () {
    $own = app(LabWorkflowRequestService::class)
        ->createV2Draft(labOrderPayload(), $this->mainBranch->id, superAdmin());
    $foreign = app(LabWorkflowRequestService::class)
        ->createV2Draft(labOrderPayload(), Branch::factory()->create()->id, superAdmin());

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-requests.index'))
        ->assertOk()
        ->assertSee($own->order_number)
        ->assertDontSee($foreign->order_number);
});

// ---------------------------------------------------------------------------
// Private evidence access
// ---------------------------------------------------------------------------

it('streams evidence to authorized users and denies others', function () {
    $order = v2DraftWithPhotos();
    $evidence = LabWorkflowEvidence::where('lab_order_id', $order->id)->firstOrFail();

    // Lab staff can view.
    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-workflow-evidence.show', $evidence))
        ->assertOk();

    // Same-branch branch actor can view.
    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-evidence.show', $evidence))
        ->assertOk();

    // Unrelated role cannot.
    $this->actingAs(userWith(['manage users']))
        ->get(route('lab-workflow-evidence.show', $evidence))
        ->assertForbidden();

    // Guests are redirected.
    auth()->logout();
    $this->get(route('lab-workflow-evidence.show', $evidence))
        ->assertRedirect(route('login'));
});

it('denies a branch actor evidence from another branch', function () {
    $otherBranch = Branch::factory()->create();
    $order = app(LabWorkflowRequestService::class)
        ->createV2Draft(labOrderPayload(), $otherBranch->id, superAdmin());
    $evidence = LabWorkflowEvidence::factory()->create([
        'lab_order_id' => $order->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->get(route('lab-workflow-evidence.show', $evidence))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Legacy interplay + RME candidate conversion
// ---------------------------------------------------------------------------

it('keeps the legacy create path working (LEGACY-stamped) while the flag is off', function () {
    $this->actingAs(superAdmin())
        ->post(route('lab-orders.store'), labOrderPayload())
        ->assertRedirect();

    expect(LabOrder::first()->workflow_version)->toBe(LabOrder::WORKFLOW_LEGACY);
});

it('routes RME candidate conversion to a V2 draft once the flag is on', function () {
    $flags = config('feature_flags.flags');
    $flags['lab.workflow_v2']['default'] = true;
    config()->set('feature_flags.flags', $flags);

    $payload = labOrderPayload();
    $candidate = LabCaseCandidate::factory()->create([
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
        'branch_id' => $this->mainBranch->id,
    ]);

    $admin = superAdmin();
    $this->actingAs($admin);

    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder($candidate, [
        'lab_service_id' => $payload['items'][0]['lab_service_id'],
        'due_date' => now()->addDays(7)->toDateString(),
    ], $admin);

    expect($order->workflow_version)->toBe(LabOrder::WORKFLOW_V2);
    expect($order->status)->toBe(LabWorkflowState::DRAFT);
    expect($order->branch_id)->toBe($this->mainBranch->id);
    expect($candidate->refresh()->status)->toBe(LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER);
    expect($candidate->converted_lab_order_id)->toBe($order->id);
});
