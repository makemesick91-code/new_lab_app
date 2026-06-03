<?php

use App\Modules\LabOrder\Models\AuditLog;

beforeEach(function () {
    seedAccessControl();
});

it('lets an authorized user update a checklist item', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $checklist = $review->checklists()->first();

    $this->actingAs(userWith(['update_qc_checklist']))
        ->patch(route('quality-control.checklists.update', $checklist), ['result' => 'PASS'])
        ->assertRedirect(route('quality-control.show', $order));

    expect($checklist->refresh()->result)->toBe('PASS');
});

it('rejects an invalid checklist result', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $checklist = $review->checklists()->first();

    $this->actingAs(superAdmin())
        ->patch(route('quality-control.checklists.update', $checklist), ['result' => 'BOGUS'])
        ->assertSessionHasErrors('result');
});

it('requires notes when a checklist item is FAIL', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $checklist = $review->checklists()->first();

    $this->actingAs(superAdmin())
        ->patch(route('quality-control.checklists.update', $checklist), ['result' => 'FAIL'])
        ->assertSessionHasErrors('notes');
});

it('creates an audit log on checklist update', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $checklist = $review->checklists()->first();

    $this->actingAs(superAdmin())
        ->patch(route('quality-control.checklists.update', $checklist), ['result' => 'PASS']);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'UPDATE_QC_CHECKLIST')->exists())->toBeTrue();
});

it('denies checklist update without permission', function () {
    $order = qcPendingOrder();
    $review = startQcReview($order);
    $checklist = $review->checklists()->first();

    $this->actingAs(userWith(['view_quality_control']))
        ->patch(route('quality-control.checklists.update', $checklist), ['result' => 'PASS'])
        ->assertForbidden();
});
