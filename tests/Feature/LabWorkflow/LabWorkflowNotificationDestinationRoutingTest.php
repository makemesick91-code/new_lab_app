<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Notifications\LabWorkflowEvent;
use App\Modules\LabOrder\Services\LabWorkflowNotificationService;
use App\Modules\LabOrder\Support\LabWorkflowNotificationDestinationResolver;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->mainBranch = Branch::factory()->main()->create();
    // A distinct, non-MAIN RME branch so Admin Lab's default (MAIN) context
    // never matches the order's branch.
    $this->branch = Branch::factory()->create([
        'code' => 'BR2',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

/** A V2 order at the non-MAIN branch. */
function routingOrder(string $status = LabWorkflowState::DELIVERED): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => $status,
        'branch_id' => Branch::query()->where('code', 'BR2')->value('id'),
    ]);
}

function adminLabUser(): User
{
    return User::factory()->create()->assignRole('Admin Lab');
}

function branchPerawat(int $branchId): User
{
    return User::factory()->create(['branch_id' => $branchId])->assignRole('Perawat');
}

function resolver(): LabWorkflowNotificationDestinationResolver
{
    return app(LabWorkflowNotificationDestinationResolver::class);
}

// ---------------------------------------------------------------------------
// A. Destination resolver
// ---------------------------------------------------------------------------

it('routes Admin Lab to the V2 order detail for a delivered event (never the branch page)', function () {
    $order = routingOrder();
    $url = resolver()->resolve(adminLabUser(), $order, LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH);

    expect($url)->toContain('/lab/v2-orders/'.$order->id);
    expect($url)->not->toContain('/lab/workflow-requests/');
});

it('routes Admin Lab to the V2 order detail for a QC event', function () {
    $order = routingOrder(LabWorkflowState::QC_PENDING);
    $url = resolver()->resolve(adminLabUser(), $order, LabWorkflowNotificationDestinationResolver::EVENT_QC_PENDING);

    expect($url)->toContain('/lab/v2-orders/'.$order->id);
});

it('routes a courier pickup event to the pickup task detail', function () {
    $order = routingOrder(LabWorkflowState::WAITING_PICKUP);
    $courier = userWith(['manage_lab_pickups']);

    $url = resolver()->resolve($courier, $order, LabWorkflowNotificationDestinationResolver::EVENT_PICKUP_TASK, ['pickup_task_id' => 77]);

    expect($url)->toContain('/lab/pickup-tasks/77');
});

it('routes a courier delivery event to the delivery task detail', function () {
    $order = routingOrder();
    $courier = userWith(['view_delivery', 'start_delivery']);

    $url = resolver()->resolve($courier, $order, LabWorkflowNotificationDestinationResolver::EVENT_DELIVERY_TASK, ['delivery_task_id' => 55]);

    expect($url)->toContain('/lab/delivery-tasks/55');
});

it('routes a branch operator of the order branch to the branch request page for a delivered event', function () {
    $order = routingOrder();
    $perawat = branchPerawat((int) $order->branch_id);

    $url = resolver()->resolve($perawat, $order, LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH);

    expect($url)->toContain('/lab/workflow-requests/'.$order->id);
});

it('never routes a V2 order event to a legacy branch route for Admin Lab', function () {
    $order = routingOrder();

    foreach ([
        LabWorkflowNotificationDestinationResolver::EVENT_MODEL_DONE,
        LabWorkflowNotificationDestinationResolver::EVENT_QC_PENDING,
        LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH,
    ] as $event) {
        $url = resolver()->resolve(adminLabUser(), $order, $event);
        expect($url)->not->toContain('/lab/workflow-requests/');
    }
});

it('falls back to the branch index for a foreign-branch operator (never a 404 detail link)', function () {
    $order = routingOrder();
    // A Perawat pinned to MAIN receiving a BR2 delivered event: the branch detail
    // page would 404, so the resolver returns the operator's own queue index.
    $foreign = branchPerawat((int) $this->mainBranch->id);

    $url = resolver()->resolve($foreign, $order, LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH);

    expect($url)->toContain('/lab/workflow-requests');
    expect($url)->not->toContain('/lab/workflow-requests/'.$order->id);
});

it('returns null (link-less) when the recipient holds no lab-domain permission', function () {
    $order = routingOrder();
    $stranger = userWith(['view_clinic_visits']);

    $url = resolver()->resolve($stranger, $order, LabWorkflowNotificationDestinationResolver::EVENT_MODEL_DONE);

    expect($url)->toBeNull();
});

it('falls back to the V2 index when a delivered event lacks a delivery task and branch does not match', function () {
    $order = routingOrder();
    // Admin Lab with no delivery task id still gets an openable v2 detail.
    $url = resolver()->resolve(adminLabUser(), $order, LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH, []);

    expect($url)->toContain('/lab/v2-orders/'.$order->id);
});

// ---------------------------------------------------------------------------
// B. Recipient-aware fan-out (service + resolver integration)
// ---------------------------------------------------------------------------

it('fans out one delivered notification to two audiences with two different, openable URLs', function () {
    $order = routingOrder();
    $adminLab = adminLabUser();
    $perawat = branchPerawat((int) $order->branch_id);

    app(LabWorkflowNotificationService::class)->notifyPermissionHoldersRouted(
        ['create_lab_branch_requests'],
        'Model tiba di cabang',
        'Order diterima.',
        $order,
        LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH,
        ['delivery_task_id' => null],
    );

    $adminNotif = DatabaseNotification::query()->where('notifiable_id', $adminLab->id)->firstOrFail();
    $perawatNotif = DatabaseNotification::query()->where('notifiable_id', $perawat->id)->firstOrFail();

    expect($adminNotif->data['url'])->toContain('/lab/v2-orders/'.$order->id);
    expect($perawatNotif->data['url'])->toContain('/lab/workflow-requests/'.$order->id);
    expect($adminNotif->data['lab_order_id'])->toBe($order->id);
});

// ---------------------------------------------------------------------------
// C. Admin Lab regression — destination is openable, Lab-only preserved
// ---------------------------------------------------------------------------

it('lets Admin Lab open the resolved V2 destination but 404s the legacy branch link', function () {
    $order = routingOrder();
    $adminLab = adminLabUser();

    // The OLD (broken) destination still 404s for Admin Lab (branch isolation).
    $this->actingAs($adminLab)
        ->get(route('lab-workflow-requests.show', $order->id))
        ->assertNotFound();

    // The NEW resolved destination is openable.
    $this->actingAs($adminLab)
        ->get(route('lab-v2-orders.show', $order->id))
        ->assertOk();
});

it('keeps Admin Lab Lab-only after the fix (no widened permission)', function () {
    $adminLab = adminLabUser();

    // Still 403 on RME and Inventory — the fix touched no permission.
    $this->actingAs($adminLab)->get(route('rme.patient-queue.index'))->assertForbidden();
    $this->actingAs($adminLab)->get(route('inventory.products.index'))->assertForbidden();

    expect($adminLab->can('create_lab_branch_requests'))->toBeTrue(); // held by design (Phase 2)
    expect($adminLab->can('view dashboard'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// D. Branch recipient can open its resolved destination
// ---------------------------------------------------------------------------

it('lets a matched-branch operator open the resolved branch request page', function () {
    $order = routingOrder();
    $perawat = branchPerawat((int) $order->branch_id);

    $this->actingAs($perawat)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('lab-workflow-requests.show', $order->id))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// E. Open-redirect protection (notification read flow)
// ---------------------------------------------------------------------------

it('refuses to follow an external URL stored in a notification', function () {
    $user = userWith(['manage_lab_pickups']);
    $user->notify(new LabWorkflowEvent('X', 'Y', 'https://evil.example/phish', null));
    $row = DatabaseNotification::query()->where('notifiable_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)->post(route('notifications.read', $row->id));

    expect($response->headers->get('Location'))->not->toContain('evil.example');
});

it('refuses a javascript: and protocol-relative URL', function () {
    $user = userWith(['manage_lab_pickups']);
    foreach (['javascript:alert(1)', '//evil.example/x'] as $bad) {
        $user->notify(new LabWorkflowEvent('X', 'Y', $bad, null));
    }

    foreach (DatabaseNotification::query()->where('notifiable_id', $user->id)->get() as $row) {
        $response = $this->actingAs($user)->post(route('notifications.read', $row->id));
        expect($response->headers->get('Location'))->not->toContain('evil.example');
        expect($response->headers->get('Location'))->not->toStartWith('javascript:');
    }
});

it('follows a same-origin internal URL', function () {
    $user = userWith(['view_lab_orders']);
    $order = routingOrder();
    $internal = route('lab-v2-orders.show', $order->id);
    $user->notify(new LabWorkflowEvent('X', 'Y', $internal, $order->id));
    $row = DatabaseNotification::query()->where('notifiable_id', $user->id)->firstOrFail();

    $this->actingAs($user)
        ->post(route('notifications.read', $row->id))
        ->assertRedirect($internal);
});

it('keeps notification read ownership-scoped', function () {
    $owner = userWith(['view_lab_orders']);
    $other = userWith(['view_lab_orders']);
    $owner->notify(new LabWorkflowEvent('X', 'Y', null, null));
    $row = DatabaseNotification::query()->where('notifiable_id', $owner->id)->firstOrFail();

    $this->actingAs($other)->post(route('notifications.read', $row->id))->assertNotFound();
    expect($row->fresh()->read_at)->toBeNull();
});
