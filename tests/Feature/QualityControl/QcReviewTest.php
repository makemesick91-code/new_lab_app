<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;

beforeEach(function () {
    seedAccessControl();
});

it('lets QC start a review on a QC_PENDING order', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['start_qc']))
        ->post(route('quality-control.start', $order), ['notes' => 'starting'])
        ->assertRedirect(route('quality-control.show', $order));

    $review = QualityControl::where('lab_order_id', $order->id)->first();
    expect($review)->not->toBeNull();
    expect($review->inspected_by)->not->toBeNull();
    expect($review->started_at)->not->toBeNull();
});

it('creates the default checklist items on start', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.start', $order), []);

    $review = QualityControl::where('lab_order_id', $order->id)->first();
    expect(QualityControlChecklist::where('quality_control_id', $review->id)->count())
        ->toBe(count(QualityControlChecklist::ITEMS));
});

it('creates an audit log on start', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.start', $order), []);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'START_QC')->exists())->toBeTrue();
});

it('forbids starting review on a non QC_PENDING order', function () {
    $order = LabOrder::factory()->create(['status' => 'RECEIVED']);

    $this->actingAs(userWith(['start_qc']))
        ->post(route('quality-control.start', $order), [])
        ->assertForbidden();
});

it('denies start review without permission', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->post(route('quality-control.start', $order), [])
        ->assertForbidden();
});

it('shows the QC detail page to an authorized user', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->get(route('quality-control.show', $order))
        ->assertOk()
        ->assertViewIs('quality-control.show');
});
