<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Production\Models\ProductionStep;

beforeEach(function () {
    seedAccessControl();
});

it('creates default production steps when an order is assigned', function () {
    $order = receivedOrder();
    assignOrder($order);

    expect(ProductionStep::where('lab_order_id', $order->id)->pluck('step_name')->all())
        ->toBe(ProductionStep::DEFAULT_STEPS);
});

it('lets an authorized user update a step status', function () {
    [$order] = orderInProduction();
    $step = ProductionStep::where('lab_order_id', $order->id)->first();

    $this->actingAs(userWith(['start_production_work']))
        ->patch(route('production.steps.update', [$order, $step]), ['status' => 'IN_PROGRESS'])
        ->assertRedirect(route('production.show', $order));

    expect($step->refresh()->status)->toBe('IN_PROGRESS');
    expect($step->refresh()->started_at)->not->toBeNull();
});

it('sets completed_at when a step is completed', function () {
    [$order] = orderInProduction();
    $step = ProductionStep::where('lab_order_id', $order->id)->first();

    $this->actingAs(superAdmin())
        ->patch(route('production.steps.update', [$order, $step]), ['status' => 'COMPLETED']);

    expect($step->refresh()->completed_at)->not->toBeNull();
});

it('rejects an invalid step status', function () {
    [$order] = orderInProduction();
    $step = ProductionStep::where('lab_order_id', $order->id)->first();

    $this->actingAs(superAdmin())
        ->patch(route('production.steps.update', [$order, $step]), ['status' => 'BOGUS'])
        ->assertSessionHasErrors('status');
});

it('requires notes when a step is skipped', function () {
    [$order] = orderInProduction();
    $step = ProductionStep::where('lab_order_id', $order->id)->first();

    $this->actingAs(superAdmin())
        ->patch(route('production.steps.update', [$order, $step]), ['status' => 'SKIPPED'])
        ->assertSessionHasErrors('notes');
});

it('creates an audit log on step update', function () {
    [$order] = orderInProduction();
    $step = ProductionStep::where('lab_order_id', $order->id)->first();

    $this->actingAs(superAdmin())
        ->patch(route('production.steps.update', [$order, $step]), ['status' => 'IN_PROGRESS']);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'UPDATE_PRODUCTION_STEP')->exists())->toBeTrue();
});
