<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Branch::factory()->main()->create();
});

/**
 * FIX-LAB-TECHNICIAN-ROLE-ASSIGNMENT — only ACTIVE user accounts holding the
 * canonical Technician role may receive a NEW assignment (V2 + legacy).
 */

/** A V2 order ready for technician assignment. */
function eligV2Order(string $status = LabWorkflowState::INTERNAL_APPROVED): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => $status,
    ]);
}

function eligAssigner(): User
{
    return userWith(['assign_technicians', 'manage_lab_orders', 'view_lab_orders']);
}

function eligTechnician(): Technician
{
    return Technician::factory()->assignable()->create();
}

// ---------------------------------------------------------------------------
// Eligibility source of truth
// ---------------------------------------------------------------------------

it('lists only active users with the Technician role as assignment targets', function () {
    $eligible = eligTechnician();

    $noAccount = Technician::factory()->create(['user_id' => null, 'name' => 'Tanpa Akun']);
    $wrongRole = Technician::factory()->create([
        'user_id' => userWith(['manage_production'])->id, 'name' => 'Bukan Teknisi',
    ]);
    $inactiveUser = Technician::factory()->assignable()->create(['name' => 'User Nonaktif']);
    $inactiveUser->user()->first()->update(['is_active' => false]);
    $inactiveMaster = Technician::factory()->assignable()->inactive()->create(['name' => 'Master Nonaktif']);
    $deletedUser = Technician::factory()->assignable()->create(['name' => 'User Terhapus']);
    $deletedUser->user()->first()->delete();

    $ids = app(TechnicianAssignmentEligibility::class)->listForAssignment()->pluck('id');

    expect($ids)->toContain($eligible->id)
        ->not->toContain($noAccount->id)
        ->not->toContain($wrongRole->id)
        ->not->toContain($inactiveUser->id)
        ->not->toContain($inactiveMaster->id)
        ->not->toContain($deletedUser->id);
});

it('does not treat manage_production permission as the Technician role', function () {
    $supervisor = userWith(['manage_production']);
    $technician = Technician::factory()->create(['user_id' => $supervisor->id]);

    expect(app(TechnicianAssignmentEligibility::class)->isEligible($technician))->toBeFalse();
});

it('shows only eligible technicians in the V2 assignment dropdown', function () {
    $eligible = Technician::factory()->assignable()->create(['name' => 'Teknisi Layak Zx']);
    $ineligible = Technician::factory()->create(['user_id' => null, 'name' => 'Teknisi Legacy Qy']);
    $order = eligV2Order();

    $this->actingAs(eligAssigner())
        ->get(route('lab-v2-orders.show', $order))
        ->assertOk()
        ->assertSee('Teknisi Layak Zx')
        ->assertDontSee('Teknisi Legacy Qy');
});

// ---------------------------------------------------------------------------
// V2 assignment enforcement (server-side, never trusts technician_id)
// ---------------------------------------------------------------------------

it('assigns an eligible technician to a V2 order and records the assignment + audit', function () {
    $order = eligV2Order();
    $technician = eligTechnician();

    $this->actingAs(eligAssigner())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(LabWorkflowState::TECHNICIAN_ASSIGNED);

    $assignment = LabOrderAssignment::query()->where('lab_order_id', $order->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->technician_id)->toBe($technician->id)
        ->and($assignment->status)->toBe(LabOrderAssignment::STATUS_ASSIGNED);

    $this->assertDatabaseHas('sys_audit_logs', [
        'entity_id' => $order->id,
        'action' => 'ASSIGN_TECHNICIAN',
    ]);
});

it('rejects a crafted technician_id pointing at a non-Technician user', function () {
    $order = eligV2Order();
    $crafted = Technician::factory()->create([
        'user_id' => userWith(['manage_production'])->id,
    ]);

    $this->actingAs(eligAssigner())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $crafted->id])
        ->assertSessionHasErrors('technician_id');

    expect($order->refresh()->status)->toBe(LabWorkflowState::INTERNAL_APPROVED)
        ->and(LabOrderAssignment::query()->where('lab_order_id', $order->id)->count())->toBe(0);
});

it('rejects a crafted technician_id for an inactive technician user account', function () {
    $order = eligV2Order();
    $technician = eligTechnician();
    $technician->user()->first()->update(['is_active' => false]);

    $this->actingAs(eligAssigner())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id])
        ->assertSessionHasErrors('technician_id');
});

it('rejects a technician without any linked user account', function () {
    $order = eligV2Order();
    $legacyOnly = Technician::factory()->create(['user_id' => null]);

    $this->actingAs(eligAssigner())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $legacyOnly->id])
        ->assertSessionHasErrors('technician_id');
});

it('rejects a second assignment while one is active (double-assign guard)', function () {
    $order = eligV2Order();
    $first = eligTechnician();
    $second = eligTechnician();
    $assigner = eligAssigner();

    $this->actingAs($assigner)
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $first->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($assigner)
        ->post(route('lab-v2-orders.assign-technician', $order->refresh()), ['technician_id' => $second->id])
        ->assertSessionHasErrors();

    expect(LabOrderAssignment::query()->where('lab_order_id', $order->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Role revocation semantics
// ---------------------------------------------------------------------------

it('keeps assignment history readable but blocks NEW assignments after the role is revoked', function () {
    $technician = eligTechnician();
    $firstOrder = eligV2Order();
    $assigner = eligAssigner();

    $this->actingAs($assigner)
        ->post(route('lab-v2-orders.assign-technician', $firstOrder), ['technician_id' => $technician->id])
        ->assertSessionHasNoErrors();

    // Revoke the canonical role AFTER the assignment exists.
    $technician->user()->first()->removeRole(TechnicianAssignmentEligibility::ROLE);

    // History stays: the old assignment row + relation are untouched.
    $assignment = LabOrderAssignment::query()->where('lab_order_id', $firstOrder->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->technician_id)->toBe($technician->id);

    // New assignments are blocked.
    $secondOrder = eligV2Order();
    $this->actingAs($assigner)
        ->post(route('lab-v2-orders.assign-technician', $secondOrder), ['technician_id' => $technician->id])
        ->assertSessionHasErrors('technician_id');
});

// ---------------------------------------------------------------------------
// Legacy path shares the exact same rule
// ---------------------------------------------------------------------------

it('rejects an ineligible technician on the legacy assignment service', function () {
    $order = receivedOrder();
    $ineligible = Technician::factory()->create(['user_id' => null]);

    expect(fn () => app(AssignmentService::class)->assign($order, $ineligible->id, 'x', superAdmin()))
        ->toThrow(ValidationException::class);
});

it('rejects an ineligible technician on the legacy reassignment path', function () {
    $order = receivedOrder();
    assignOrder($order); // eligible via helper

    $ineligible = Technician::factory()->create([
        'user_id' => userWith(['manage_production'])->id,
    ]);

    expect(fn () => app(AssignmentService::class)->reassign($order->refresh(), $ineligible->id, 'ganti', superAdmin()))
        ->toThrow(ValidationException::class);
});

it('still allows a Super Admin ACTOR to assign, without being an assignment target', function () {
    // superAdmin() holds every permission but not the Technician role — it can
    // PERFORM the assignment, yet may never BE the target.
    $order = receivedOrder();
    $assignment = assignOrder($order, null, superAdmin());

    expect($assignment->status)->toBe(LabOrderAssignment::STATUS_ASSIGNED);

    $superTechRow = Technician::factory()->create(['user_id' => superAdmin()->id]);
    expect(app(TechnicianAssignmentEligibility::class)->isEligible($superTechRow))->toBeFalse();
});
