<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;

beforeEach(function () {
    seedAccessControl();
});

it('creates a QC record with result PASSED on pass', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['pass_qc']))
        ->post(route('quality-control.pass', $order), ['notes' => 'good'])
        ->assertRedirect(route('quality-control.show', $order));

    expect(QualityControl::where('lab_order_id', $order->id)->where('result', 'PASSED')->exists())->toBeTrue();
});

it('changes the order status to QC_PASSED on pass', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.pass', $order), []);

    expect($order->refresh()->status)->toBe('QC_PASSED');
});

it('creates an audit log on pass', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.pass', $order), []);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'PASS_QC')->exists())->toBeTrue();
});

it('creates a status log on pass', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.pass', $order), []);

    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', 'QC_PASSED')->exists())->toBeTrue();
});

it('blocks pass when a checklist item has FAIL', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $review->checklists()->first()->update(['result' => QualityControlChecklist::RESULT_FAIL, 'notes' => 'bad fit']);

    $this->actingAs(userWith(['pass_qc']))
        ->post(route('quality-control.pass', $order->refresh()), [])
        ->assertSessionHasErrors('checklist');

    expect($order->refresh()->status)->toBe('QC_PENDING');
});

it('denies pass without permission', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->post(route('quality-control.pass', $order), [])
        ->assertForbidden();
});
