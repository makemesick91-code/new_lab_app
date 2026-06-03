<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\RemakeRequest;

beforeEach(function () {
    seedAccessControl();
});

function rejectPayload(array $overrides = []): array
{
    return array_merge(['result' => 'REJECTED', 'reason' => 'FIT_ISSUE', 'notes' => 'margins are off'], $overrides);
}

it('creates a QC record with result REJECTED on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['reject_qc']))
        ->post(route('quality-control.reject', $order), rejectPayload())
        ->assertRedirect(route('quality-control.show', $order));

    expect(QualityControl::where('lab_order_id', $order->id)->where('result', 'REJECTED')->exists())->toBeTrue();
});

it('changes the order status to REMAKE on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.reject', $order), rejectPayload());

    expect($order->refresh()->status)->toBe('REMAKE');
});

it('creates a remake request on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.reject', $order), rejectPayload());

    expect(RemakeRequest::where('lab_order_id', $order->id)->exists())->toBeTrue();
});

it('creates an audit log on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.reject', $order), rejectPayload());

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'REJECT_QC')->exists())->toBeTrue();
});

it('creates a status log on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())->post(route('quality-control.reject', $order), rejectPayload());

    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', 'REMAKE')->exists())->toBeTrue();
});

it('requires notes and reason on reject', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())
        ->post(route('quality-control.reject', $order), ['result' => 'REJECTED'])
        ->assertSessionHasErrors(['notes', 'reason']);
});

it('denies reject without permission', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->post(route('quality-control.reject', $order), rejectPayload())
        ->assertForbidden();
});
