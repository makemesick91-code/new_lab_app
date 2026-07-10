<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabExternalDispatch;
use App\Modules\LabOrder\Models\LabModelAnalysis;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabWorkflowStateMachine;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Branch::factory()->main()->create();
});

/** A V2 order at EXTERNAL_LAB_REQUIRED with its analysis pointing at a lab. */
function externalRequiredOrder(?ExternalLab $lab = null): LabOrder
{
    $lab = $lab ?? ExternalLab::factory()->create();

    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::EXTERNAL_LAB_REQUIRED,
    ]);

    LabModelAnalysis::create([
        'lab_order_id' => $order->id,
        'decision' => LabModelAnalysis::DECISION_EXTERNAL,
        'reason' => 'Material khusus',
        'external_lab_id' => $lab->id,
        'analyzed_by' => superAdmin()->id,
        'analyzed_at' => now(),
    ]);

    return $order;
}

function labAdmin(): User
{
    return userWith(['manage_lab_orders', 'view_lab_orders']);
}

it('prepares a dispatch defaulting to the analysis destination', function () {
    $lab = ExternalLab::factory()->create();
    $order = externalRequiredOrder($lab);

    $this->actingAs(labAdmin())
        ->post(route('lab-v2-orders.external-dispatch', $order), ['reason' => 'Sesuai analisa'])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::EXTERNAL_LAB_PREPARATION);

    $dispatch = LabExternalDispatch::where('lab_order_id', $order->id)->firstOrFail();
    expect($dispatch->external_lab_id)->toBe($lab->id);
    expect($dispatch->status)->toBe(LabExternalDispatch::STATUS_PREPARATION);
});

it('refuses a dispatch to an inactive external lab', function () {
    $order = externalRequiredOrder();
    $inactive = ExternalLab::factory()->inactive()->create();

    $this->actingAs(labAdmin())
        ->post(route('lab-v2-orders.external-dispatch', $order), ['external_lab_id' => $inactive->id])
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_REQUIRED);
});

it('walks sent -> in progress -> returned -> review accepted -> MODEL_DONE', function () {
    $order = externalRequiredOrder();
    $admin = labAdmin();

    $this->actingAs($admin)->post(route('lab-v2-orders.external-dispatch', $order));
    $this->actingAs($admin)->post(route('lab-v2-orders.external-sent', $order), [
        'shipping_method' => 'Kurir internal',
        'reference_number' => 'RESI-001',
    ])->assertRedirect();
    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_SENT);

    $this->actingAs($admin)->post(route('lab-v2-orders.external-in-progress', $order))->assertRedirect();
    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS);

    $this->actingAs($admin)->post(route('lab-v2-orders.external-returned', $order))->assertRedirect();
    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW);

    $this->actingAs($admin)->post(route('lab-v2-orders.external-review', $order), [
        'result' => 'ACCEPTED',
        'notes' => 'Hasil bagus',
    ])->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::MODEL_DONE);

    $dispatch = LabExternalDispatch::where('lab_order_id', $order->id)->firstOrFail();
    expect($dispatch->status)->toBe(LabExternalDispatch::STATUS_REVIEWED);
    expect($dispatch->review_result)->toBe(LabExternalDispatch::RESULT_ACCEPTED);
    expect($dispatch->reference_number)->toBe('RESI-001');
});

it('requires notes on a rejected review and opens a new resend round', function () {
    $order = externalRequiredOrder();
    $admin = labAdmin();

    $this->actingAs($admin)->post(route('lab-v2-orders.external-dispatch', $order));
    $this->actingAs($admin)->post(route('lab-v2-orders.external-sent', $order));
    $this->actingAs($admin)->post(route('lab-v2-orders.external-in-progress', $order));
    $this->actingAs($admin)->post(route('lab-v2-orders.external-returned', $order));

    // Rejected without notes -> refused.
    $this->actingAs($admin)
        ->post(route('lab-v2-orders.external-review', $order), ['result' => 'REJECTED'])
        ->assertSessionHasErrors();

    $this->actingAs($admin)
        ->post(route('lab-v2-orders.external-review', $order), [
            'result' => 'REJECTED',
            'notes' => 'Warna tidak sesuai',
        ])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::EXTERNAL_LAB_PREPARATION);

    // Append-only rounds: the reviewed row is closed, a fresh PREPARATION row exists.
    $dispatches = LabExternalDispatch::where('lab_order_id', $order->id)->orderBy('id')->get();
    expect($dispatches)->toHaveCount(2);
    expect($dispatches[0]->status)->toBe(LabExternalDispatch::STATUS_REVIEWED);
    expect($dispatches[0]->review_result)->toBe(LabExternalDispatch::RESULT_REJECTED);
    expect($dispatches[1]->status)->toBe(LabExternalDispatch::STATUS_PREPARATION);
});

it('cannot jump to MODEL_DONE while the model is still at the external lab', function () {
    $order = externalRequiredOrder();
    $admin = labAdmin();
    $this->actingAs($admin)->post(route('lab-v2-orders.external-dispatch', $order));
    $this->actingAs($admin)->post(route('lab-v2-orders.external-sent', $order));

    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($order->refresh(), LabWorkflowState::MODEL_DONE, superAdmin()))
        ->toThrow(ValidationException::class);

    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_SENT);
});

it('manages external lab master data behind manage_lab_orders', function () {
    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-external-labs.index'))
        ->assertForbidden();

    $admin = labAdmin();
    $this->actingAs($admin)
        ->post(route('lab-external-labs.store'), ['name' => 'Lab Rekanan Makassar'])
        ->assertRedirect();

    expect(ExternalLab::where('name', 'Lab Rekanan Makassar')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('lab-external-labs.index'))
        ->assertOk()
        ->assertSee('Lab Rekanan Makassar');
});
