<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use App\Modules\LabOrder\Services\LabWorkflowStateMachine;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->mainBranch = Branch::factory()->main()->create();
});

/** Flip the V2 flag on for this test (config default, env untouched). */
function p5FlagOn(): void
{
    $flags = config('feature_flags.flags');
    $flags['lab.workflow_v2']['default'] = true;
    config()->set('feature_flags.flags', $flags);
}

/** A V2 draft with complete photos, created through the service. */
function p5DraftWithPhotos(): LabOrder
{
    return app(LabWorkflowRequestService::class)->createDraft(
        labOrderPayload(),
        fakeEvidencePhoto('spk.png'),
        fakeEvidencePhoto('model.png'),
        superAdmin(),
    );
}

// ---------------------------------------------------------------------------
// In-app notifications
// ---------------------------------------------------------------------------

it('notifies pickup permission holders when a pickup request is submitted', function () {
    $kurir = userWith(['manage_lab_pickups']);
    $order = p5DraftWithPhotos();

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertRedirect();

    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $kurir->id)
        ->where('notifiable_type', $kurir->getMorphClass())
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['title'])->toBe('Tugas pickup baru');
    expect($notification->data['lab_order_id'])->toBe($order->id);
    expect($notification->read_at)->toBeNull();
});

it('notifies QC holders when work is sent to QC', function () {
    $qcUser = userWith(['pass_qc']);
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::STEP_4_COMPLETED,
    ]);
    // Drive via state machine directly needs an assignment; use production service path.
    $technician = Technician::factory()->create([
        'user_id' => userWith(['start_production_work', 'complete_production_work', 'send_to_qc'])->id,
        'is_active' => true,
    ]);
    LabOrderAssignment::query()->create([
        'lab_order_id' => $order->id,
        'technician_id' => $technician->id,
        'assigned_by' => superAdmin()->id,
        'assigned_at' => now(),
        'status' => LabOrderAssignment::STATUS_IN_PROGRESS,
    ]);

    $this->actingAs($technician->user()->first())
        ->post(route('lab-v2-orders.send-to-qc', $order))
        ->assertRedirect();

    expect(DatabaseNotification::query()
        ->where('notifiable_id', $qcUser->id)
        ->count())->toBe(1);
});

it('never blocks the workflow when notification dispatch fails', function () {
    // CICD-FIX-5 — the failure used to be injected by dropping the notifications
    // table. That is database-destructive failure injection, and it made the test
    // unrunnable on PostgreSQL: the resulting real SQL error (42P01) happens
    // inside the outer transaction RefreshDatabase opens, PostgreSQL then marks
    // that transaction aborted, and every later statement — including this
    // test's own $order->refresh() — dies with 25P02. Catching the exception
    // cannot undo that; only ROLLBACK TO SAVEPOINT can. SQLite simply tolerates
    // a failed statement, which is why the technique appeared to work.
    //
    // It also modelled something production cannot do. submitForPickup()
    // dispatches notifications AFTER its DB::transaction has committed — all
    // nine Lab notification call sites are post-commit — so in production the
    // insert runs with no open transaction and a failure there poisons nothing.
    //
    // Inject the failure at the real notification-dispatch boundary instead.
    // Notifiable::notify() resolves this contract from the container, so the
    // throw happens exactly where LabWorkflowNotificationService guards it with
    // try/catch(\Throwable) — the actual business contract under test — and it
    // behaves identically on both drivers.
    Log::spy();

    $this->instance(NotificationDispatcher::class, Mockery::mock(NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('notification store unavailable'));
        $mock->shouldReceive('sendNow')->andThrow(new RuntimeException('notification store unavailable'));
    }));

    $order = p5DraftWithPhotos();
    userWith(['manage_lab_pickups']);

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.submit-pickup', $order))
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    // The workflow transition still committed despite the notification failure.
    expect($order->refresh()->status)->toBe(LabWorkflowState::WAITING_PICKUP);

    // ...and no notification was persisted, so the failure was real, not a no-op.
    expect(DatabaseNotification::query()->count())->toBe(0);

    // The failure is recorded rather than silently swallowed.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains(
            (string) $message,
            'gagal mengirim notifikasi in-app'
        ));
});

// ---------------------------------------------------------------------------
// Notification inbox
// ---------------------------------------------------------------------------

it('shows only the user\'s own notifications and marks them read (self-scoped)', function () {
    $kurir = userWith(['manage_lab_pickups']);
    $other = userWith(['manage_lab_pickups']);
    $order = p5DraftWithPhotos();

    $this->actingAs(userWith(['create_lab_branch_requests']))
        ->post(route('lab-workflow-requests.submit-pickup', $order));

    $own = DatabaseNotification::query()->where('notifiable_id', $kurir->id)->firstOrFail();
    $foreign = DatabaseNotification::query()->where('notifiable_id', $other->id)->firstOrFail();

    $this->actingAs($kurir)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Tugas pickup baru');

    // Marking own notification works (redirects to its url).
    $this->actingAs($kurir)
        ->post(route('notifications.read', $own->id))
        ->assertRedirect();
    expect($own->fresh()->read_at)->not->toBeNull();

    // Someone else's notification id -> 404, untouched.
    $this->actingAs($kurir)
        ->post(route('notifications.read', $foreign->id))
        ->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();

    // Mark all read.
    $this->actingAs($other)->post(route('notifications.read-all'))->assertRedirect();
    expect($other->unreadNotifications()->count())->toBe(0);
});

it('redirects guests away from the notification inbox', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Legacy inactivation UI
// ---------------------------------------------------------------------------

it('shows the legacy read-only badge and hides the create button when V2 is active', function () {
    p5FlagOn();

    $this->actingAs(userWith(['view_lab_orders', 'create_lab_orders']))
        ->get(route('lab-orders.index'))
        ->assertOk()
        ->assertSee('Legacy Workflow — Inactive for New Orders')
        ->assertDontSee('+ Tambah Order Lab');
});

it('keeps the legacy create button while V2 is off', function () {
    $this->actingAs(userWith(['view_lab_orders', 'create_lab_orders']))
        ->get(route('lab-orders.index'))
        ->assertOk()
        ->assertSee('+ Tambah Order Lab')
        ->assertDontSee('Legacy Workflow — Inactive for New Orders');
});

it('labels a V2 order on the legacy detail page', function () {
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::WAITING_PICKUP,
    ]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertSee('Lab Workflow V2');
});

it('labels a legacy order as Legacy Workflow when V2 is active', function () {
    p5FlagOn();
    $order = LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_LEGACY]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertSee('Legacy Workflow');
});

// ---------------------------------------------------------------------------
// Pipeline KPIs
// ---------------------------------------------------------------------------

it('renders the V2 pipeline KPI cards with real counts', function () {
    LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::WAITING_PICKUP,
    ]);
    LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::QC_PENDING,
    ]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-v2-orders.index'))
        ->assertOk()
        ->assertSee('data-v2-pipeline-kpis', false)
        ->assertSee('Menunggu Pickup')
        ->assertSee('QC Pending')
        ->assertSee('Terkirim');
});

// ---------------------------------------------------------------------------
// Terminal DELIVERED stays terminal even for privileged users
// ---------------------------------------------------------------------------

it('keeps DELIVERED terminal for every actor', function () {
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::DELIVERED,
    ]);

    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::DELIVERY_PENDING, superAdmin()))
        ->toThrow(ValidationException::class);
});
