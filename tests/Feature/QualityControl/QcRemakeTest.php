<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\RemakeRequest;
use App\Modules\QualityControl\Services\QualityWorkflowService;

beforeEach(function () {
    seedAccessControl();
});

/** Reject an order through the workflow so it ends up in REMAKE with a QC review. */
function rejectedOrder(): LabOrder
{
    $order = qcPendingOrder();
    app(QualityWorkflowService::class)->reject($order->refresh(), 'REJECTED', 'FIT_ISSUE', 'off', superAdmin());

    return $order->refresh();
}

it('stores a remake request', function () {
    $order = rejectedOrder();
    $before = RemakeRequest::where('lab_order_id', $order->id)->count();

    $this->actingAs(userWith(['request_remake']))
        ->post(route('quality-control.remake', $order), ['reason' => 'MARGIN_ISSUE', 'notes' => 'please redo margins'])
        ->assertRedirect(route('quality-control.show', $order));

    expect(RemakeRequest::where('lab_order_id', $order->id)->count())->toBe($before + 1);
});

it('requires a reason to request remake', function () {
    $order = rejectedOrder();

    $this->actingAs(superAdmin())
        ->post(route('quality-control.remake', $order), ['notes' => 'no reason given'])
        ->assertSessionHasErrors('reason');
});

it('creates an audit log on remake request', function () {
    $order = rejectedOrder();

    $this->actingAs(superAdmin())
        ->post(route('quality-control.remake', $order), ['reason' => 'OTHER', 'notes' => 'extra remake']);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'REQUEST_REMAKE')->exists())->toBeTrue();
});

it('preserves QC and remake history across cycles', function () {
    $order = rejectedOrder();
    $firstReviewId = QualityControl::where('lab_order_id', $order->id)->value('id');

    $this->actingAs(superAdmin())
        ->post(route('quality-control.remake', $order), ['reason' => 'OTHER', 'notes' => 'second remake']);

    // Original QC review and the auto-created remake request are still present.
    expect(QualityControl::find($firstReviewId))->not->toBeNull();
    expect(RemakeRequest::where('lab_order_id', $order->id)->count())->toBeGreaterThanOrEqual(2);
});

it('forbids remake request on a QC_PENDING order', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['request_remake']))
        ->post(route('quality-control.remake', $order), ['reason' => 'OTHER', 'notes' => 'too early'])
        ->assertForbidden();
});

it('denies remake request without permission', function () {
    $order = rejectedOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->post(route('quality-control.remake', $order), ['reason' => 'OTHER', 'notes' => 'blocked'])
        ->assertForbidden();
});
