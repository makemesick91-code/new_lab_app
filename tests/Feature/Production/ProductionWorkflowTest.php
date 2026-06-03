<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\WorkLog;
use App\Modules\Production\Services\ProductionWorkflowService;

beforeEach(function () {
    seedAccessControl();
});

// ---- Start work ----------------------------------------------------------

it('starts work and moves the order to IN_PRODUCTION', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(userWith(['start_production_work']))
        ->post(route('production.start', $order->refresh()), ['notes' => 'begin'])
        ->assertRedirect(route('production.show', $order));

    expect($order->refresh()->status)->toBe('IN_PRODUCTION');
});

it('creates a WORK_STARTED log and audit on start', function () {
    $order = receivedOrder();
    $assignment = assignOrder($order);

    $this->actingAs(superAdmin())->post(route('production.start', $order->refresh()), []);

    expect(WorkLog::where('assignment_id', $assignment->id)->where('event_type', 'WORK_STARTED')->exists())->toBeTrue();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'START_WORK')->exists())->toBeTrue();
    expect(LabOrderAssignment::find($assignment->id)->status)->toBe('IN_PROGRESS');
});

it('forbids starting an order that is not ASSIGNED', function () {
    $order = receivedOrder();

    $this->actingAs(userWith(['start_production_work']))
        ->post(route('production.start', $order), [])
        ->assertForbidden();
});

// ---- Pause work ----------------------------------------------------------

it('pauses work and moves the order to ON_HOLD', function () {
    [$order] = orderInProduction();

    $this->actingAs(userWith(['pause_production_work']))
        ->post(route('production.pause', $order), ['reason' => 'waiting for material', 'hold_reason' => 'WAITING_MATERIAL'])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe('ON_HOLD');
});

it('requires a reason to pause', function () {
    [$order] = orderInProduction();

    $this->actingAs(superAdmin())
        ->post(route('production.pause', $order), [])
        ->assertSessionHasErrors('reason');
});

it('creates a WORK_PAUSED log and audit on pause', function () {
    [$order, $assignment] = orderInProduction();

    $this->actingAs(superAdmin())->post(route('production.pause', $order), ['reason' => 'waiting material']);

    expect(WorkLog::where('assignment_id', $assignment->id)->where('event_type', 'WORK_PAUSED')->exists())->toBeTrue();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'PAUSE_WORK')->exists())->toBeTrue();
});

// ---- Resume work ---------------------------------------------------------

it('resumes work and moves the order back to IN_PRODUCTION', function () {
    [$order] = orderInProduction();
    app(ProductionWorkflowService::class)->pauseWork($order->refresh(), 'on hold reason', 'OTHER', superAdmin());

    $this->actingAs(userWith(['resume_production_work']))
        ->post(route('production.resume', $order->refresh()), ['notes' => 'resolved'])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe('IN_PRODUCTION');
});

it('creates a WORK_RESUMED log and audit on resume', function () {
    [$order, $assignment] = orderInProduction();
    app(ProductionWorkflowService::class)->pauseWork($order->refresh(), 'on hold reason', 'OTHER', superAdmin());

    $this->actingAs(superAdmin())->post(route('production.resume', $order->refresh()), []);

    expect(WorkLog::where('assignment_id', $assignment->id)->where('event_type', 'WORK_RESUMED')->exists())->toBeTrue();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'RESUME_WORK')->exists())->toBeTrue();
});

it('forbids resuming an order that is not ON_HOLD', function () {
    [$order] = orderInProduction();

    $this->actingAs(userWith(['resume_production_work']))
        ->post(route('production.resume', $order), [])
        ->assertForbidden();
});

// ---- Complete work -------------------------------------------------------

it('completes work, marks the assignment DONE and logs it', function () {
    [$order, $assignment] = orderInProduction();

    $this->actingAs(userWith(['complete_production_work']))
        ->post(route('production.complete', $order), ['notes' => 'finished'])
        ->assertRedirect();

    expect(LabOrderAssignment::find($assignment->id)->status)->toBe('DONE');
    expect(WorkLog::where('assignment_id', $assignment->id)->where('event_type', 'WORK_COMPLETED')->exists())->toBeTrue();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'COMPLETE_WORK')->exists())->toBeTrue();
});

// ---- Send to QC ----------------------------------------------------------

it('sends a completed order to QC (QC_PENDING) with status + audit logs', function () {
    [$order] = orderInProduction();
    app(ProductionWorkflowService::class)->completeWork($order->refresh(), 'done', superAdmin());

    $this->actingAs(userWith(['send_to_qc']))
        ->post(route('production.send-to-qc', $order->refresh()), ['notes' => 'handing off'])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe('QC_PENDING');
    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', 'QC_PENDING')->exists())->toBeTrue();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'SEND_TO_QC')->exists())->toBeTrue();
});

it('rejects send to QC before work is completed', function () {
    [$order] = orderInProduction();

    $this->actingAs(superAdmin())
        ->post(route('production.send-to-qc', $order), ['notes' => 'too early'])
        ->assertSessionHasErrors('status');
});

it('denies start work without permission', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(userWith(['view_production']))
        ->post(route('production.start', $order->refresh()), [])
        ->assertForbidden();
});
