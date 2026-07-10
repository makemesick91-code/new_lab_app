<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\LabOrder\Services\LabWorkflowResolver;
use App\Modules\LabOrder\Services\LabWorkflowStateMachine;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
});

/** Flip the Lab Workflow V2 feature flag on for this test (env not set, so default wins). */
function enableLabWorkflowV2(): void
{
    $flags = config('feature_flags.flags');
    $flags['lab.workflow_v2']['default'] = true;
    config()->set('feature_flags.flags', $flags);
}

/** @param array<string,mixed> $attrs */
function v2Order(array $attrs = []): LabOrder
{
    return LabOrder::factory()->create(array_merge([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::WAITING_PICKUP,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Workflow version discriminator + engine resolution
// ---------------------------------------------------------------------------

it('stamps new legacy orders with workflow_version LEGACY when V2 is off', function () {
    $order = app(LabOrderService::class)->create(labOrderPayload(), superAdmin());

    expect($order->workflow_version)->toBe(LabOrder::WORKFLOW_LEGACY);
    expect($order->isLegacyWorkflow())->toBeTrue();
    expect($order->isV2Workflow())->toBeFalse();
});

it('treats a null workflow_version as legacy (backfill / factory compatibility)', function () {
    $order = LabOrder::factory()->create(['workflow_version' => null]);

    expect($order->workflow_version)->toBeNull();
    expect($order->isLegacyWorkflow())->toBeTrue();
    expect($order->isV2Workflow())->toBeFalse();
});

it('resolves V2 inactive by default and active when the flag is enabled', function () {
    $resolver = app(LabWorkflowResolver::class);
    expect($resolver->isV2Active())->toBeFalse();
    expect($resolver->versionForNewOrder())->toBe(LabOrder::WORKFLOW_LEGACY);

    enableLabWorkflowV2();
    $resolver = app(LabWorkflowResolver::class);
    expect($resolver->isV2Active())->toBeTrue();
    expect($resolver->versionForNewOrder())->toBe(LabOrder::WORKFLOW_V2);
});

// ---------------------------------------------------------------------------
// Legacy read-only gate
// ---------------------------------------------------------------------------

it('blocks legacy order creation once V2 is active', function () {
    enableLabWorkflowV2();

    expect(fn () => app(LabOrderService::class)->create(labOrderPayload(), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses a legacy update on a V2 order', function () {
    $order = v2Order(['status' => LabWorkflowState::MODEL_REGISTERED]);

    expect(fn () => app(LabOrderService::class)->update($order, labOrderPayload(), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses a legacy cancel on a V2 order', function () {
    $order = v2Order();

    expect(fn () => app(LabOrderService::class)->cancel($order, 'x', superAdmin()))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// State machine — matrix integrity
// ---------------------------------------------------------------------------

it('has a self-consistent transition matrix (all targets valid, terminals closed)', function () {
    $matrix = LabWorkflowState::matrix();

    foreach ($matrix as $from => $targets) {
        expect(LabWorkflowState::isValid($from))->toBeTrue();
        foreach ($targets as $to) {
            expect(LabWorkflowState::isValid($to))->toBeTrue("target {$to} from {$from} must be a valid state");
        }
    }

    foreach (LabWorkflowState::TERMINAL as $terminal) {
        expect(LabWorkflowState::allowedFrom($terminal))->toBe([]);
    }

    expect(LabWorkflowState::INITIAL)->toBe(LabWorkflowState::DRAFT);
    expect(LabWorkflowState::DEFAULT_REWORK_TARGET)->toBe(LabWorkflowState::STEP_2_TEETH_SETUP);
});

// ---------------------------------------------------------------------------
// State machine — transitions & guards
// ---------------------------------------------------------------------------

it('applies a valid canonical transition with append-only status + audit logs', function () {
    $order = v2Order(['status' => LabWorkflowState::WAITING_PICKUP]);

    $result = app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::PICKUP_ACCEPTED, superAdmin(), ['reason' => 'kurir menerima']);

    expect($result->status)->toBe(LabWorkflowState::PICKUP_ACCEPTED);

    expect(LabOrderStatusLog::where('lab_order_id', $order->id)
        ->where('old_status', LabWorkflowState::WAITING_PICKUP)
        ->where('new_status', LabWorkflowState::PICKUP_ACCEPTED)
        ->exists())->toBeTrue();

    expect(AuditLog::where('entity_type', LabOrder::ENTITY_TYPE)
        ->where('entity_id', $order->id)
        ->where('action', AuditLog::ACTION_STATUS_CHANGE)
        ->exists())->toBeTrue();
});

it('rejects a transition that is not in the matrix', function () {
    $order = v2Order(['status' => LabWorkflowState::WAITING_PICKUP]);

    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::MODEL_DONE, superAdmin()))
        ->toThrow(ValidationException::class);

    expect($order->refresh()->status)->toBe(LabWorkflowState::WAITING_PICKUP);
});

it('rejects any transition out of a terminal state', function () {
    $order = v2Order(['status' => LabWorkflowState::DELIVERED]);

    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::DELIVERY_PENDING, superAdmin()))
        ->toThrow(ValidationException::class);
});

it('rejects using the V2 state machine on a legacy order', function () {
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_LEGACY,
        'status' => LabOrder::STATUS_RECEIVED,
    ]);

    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::MODEL_REGISTERED, superAdmin()))
        ->toThrow(ValidationException::class);
});

it('enforces the actor permission mapped to the target state', function () {
    $order = v2Order(['status' => LabWorkflowState::WAITING_PICKUP]);
    $machine = app(LabWorkflowStateMachine::class);

    // PICKUP_ACCEPTED is mapped to manage_delivery.
    $withoutPerm = userWith(['view_lab_orders']);
    expect(fn () => $machine->transition($order, LabWorkflowState::PICKUP_ACCEPTED, $withoutPerm))
        ->toThrow(ValidationException::class);
    expect($order->refresh()->status)->toBe(LabWorkflowState::WAITING_PICKUP);

    $withPerm = userWith(['manage_delivery']);
    $machine->transition($order, LabWorkflowState::PICKUP_ACCEPTED, $withPerm);
    expect($order->refresh()->status)->toBe(LabWorkflowState::PICKUP_ACCEPTED);
});

it('enforces branch ownership using the stored branch, never a request value', function () {
    $main = Branch::factory()->main()->create();
    $otherBranchId = Branch::factory()->create()->id;

    $this->actingAs(superAdmin());
    $contextBranchId = app(BranchContext::class)->id();
    expect($contextBranchId)->toBe($main->id);

    // Cross-branch order -> denied.
    $foreign = v2Order(['status' => LabWorkflowState::WAITING_PICKUP, 'branch_id' => $otherBranchId]);
    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($foreign, LabWorkflowState::PICKUP_ACCEPTED, superAdmin()))
        ->toThrow(ValidationException::class);

    // Same-branch order -> allowed.
    $own = v2Order(['status' => LabWorkflowState::WAITING_PICKUP, 'branch_id' => $contextBranchId]);
    app(LabWorkflowStateMachine::class)
        ->transition($own, LabWorkflowState::PICKUP_ACCEPTED, superAdmin());
    expect($own->refresh()->status)->toBe(LabWorkflowState::PICKUP_ACCEPTED);
});

it('is idempotent: transitioning to the current state is a safe no-op', function () {
    $order = v2Order(['status' => LabWorkflowState::PICKUP_ACCEPTED]);

    app(LabWorkflowStateMachine::class)
        ->transition($order, LabWorkflowState::PICKUP_ACCEPTED, superAdmin());

    expect($order->refresh()->status)->toBe(LabWorkflowState::PICKUP_ACCEPTED);
    // No status-change log written for a no-op.
    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->count())->toBe(0);
});

it('supports the QC-fail rework loop back to an explicit production step', function () {
    $machine = app(LabWorkflowStateMachine::class);
    $order = v2Order(['status' => LabWorkflowState::QC_PENDING]);

    $machine->transition($order, LabWorkflowState::QC_FAILED, superAdmin(), ['reason' => 'porositas']);
    $machine->transition($order->refresh(), LabWorkflowState::REWORK_REQUIRED, superAdmin(), ['reason' => 'perbaiki']);
    // Default rework target is STEP_2, but an explicit STEP_1 is also legal.
    $machine->transition($order->refresh(), LabWorkflowState::STEP_2_TEETH_SETUP, superAdmin());

    expect($order->refresh()->status)->toBe(LabWorkflowState::STEP_2_TEETH_SETUP);
    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Feature-flag governance safety
// ---------------------------------------------------------------------------

it('keeps the lab.workflow_v2 flag risky+default-off (governance not FAIL)', function () {
    $flag = app(FeatureFlagService::class)->get('lab.workflow_v2');
    expect($flag['default'])->toBeFalse();
    expect($flag['risk_level'])->toBe('high');

    $governance = app(FeatureFlagService::class)->validateGovernance();
    expect($governance['summary']['decision'])->not->toBe('FAIL');
});
