<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabModelAnalysis;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\Technician\Models\Technician;

beforeEach(function () {
    seedAccessControl();
    Branch::factory()->main()->create();
});

/** A V2 order at the given workflow state. */
function v2At(string $status): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => $status,
    ]);
}

/** A technician linked to a user holding the production work permissions. */
function technicianWithUser(): Technician
{
    $user = userWith(['start_production_work', 'complete_production_work', 'send_to_qc']);

    return Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);
}

function adminLab(): User
{
    return userWith(['manage_lab_orders', 'view_lab_orders', 'assign_technicians']);
}

/** Drive an order from TECHNICIAN_ASSIGNED through all four steps to QC_PENDING. */
function driveToQcPending(LabOrder $order, Technician $technician): void
{
    $techUser = $technician->user()->first();

    foreach (array_keys(LabWorkflowState::V2_PRODUCTION_STEPS) as $step) {
        test()->actingAs($techUser)->post(route('lab-v2-orders.steps.start', $order), ['step' => $step]);
        test()->actingAs($techUser)->post(route('lab-v2-orders.steps.complete', $order), ['step' => $step]);
    }

    test()->actingAs($techUser)->post(route('lab-v2-orders.send-to-qc', $order));
}

// ---------------------------------------------------------------------------
// Model registration + analysis
// ---------------------------------------------------------------------------

it('registers a received model straight into analysis', function () {
    $order = v2At(LabWorkflowState::RECEIVED_AT_LAB);

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.register', $order))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(LabWorkflowState::MODEL_ANALYSIS_PENDING);
});

it('records an internal analysis decision with mandatory reason', function () {
    $order = v2At(LabWorkflowState::MODEL_ANALYSIS_PENDING);
    $admin = adminLab();

    // Reason mandatory.
    $this->actingAs($admin)
        ->post(route('lab-v2-orders.analyze', $order), ['decision' => 'INTERNAL'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)
        ->post(route('lab-v2-orders.analyze', $order), [
            'decision' => 'INTERNAL',
            'reason' => 'Kapasitas internal tersedia',
        ])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::INTERNAL_APPROVED);
    $analysis = LabModelAnalysis::where('lab_order_id', $order->id)->firstOrFail();
    expect($analysis->decision)->toBe('INTERNAL');
    expect($analysis->analyzed_by)->toBe($admin->id);
});

it('requires an active external lab for an external decision', function () {
    $order = v2At(LabWorkflowState::MODEL_ANALYSIS_PENDING);
    $admin = adminLab();

    $this->actingAs($admin)
        ->post(route('lab-v2-orders.analyze', $order), [
            'decision' => 'EXTERNAL',
            'reason' => 'Butuh material khusus',
        ])
        ->assertSessionHasErrors('external_lab_id');

    $inactive = ExternalLab::factory()->inactive()->create();
    $this->actingAs($admin)
        ->post(route('lab-v2-orders.analyze', $order), [
            'decision' => 'EXTERNAL',
            'reason' => 'Butuh material khusus',
            'external_lab_id' => $inactive->id,
        ])
        ->assertSessionHasErrors();

    $lab = ExternalLab::factory()->create();
    $this->actingAs($admin)
        ->post(route('lab-v2-orders.analyze', $order), [
            'decision' => 'EXTERNAL',
            'reason' => 'Butuh material khusus',
            'external_lab_id' => $lab->id,
        ])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(LabWorkflowState::EXTERNAL_LAB_REQUIRED);
    expect(LabModelAnalysis::where('lab_order_id', $order->id)->value('external_lab_id'))->toBe($lab->id);
});

it('rejects analysis on an order that is not awaiting analysis', function () {
    $order = v2At(LabWorkflowState::DRAFT);

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.analyze', $order), [
            'decision' => 'INTERNAL',
            'reason' => 'x',
        ])
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::DRAFT);
});

it('rejects technician assignment before the analysis decision', function () {
    $order = v2At(LabWorkflowState::MODEL_ANALYSIS_PENDING);
    $technician = technicianWithUser();

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id])
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::MODEL_ANALYSIS_PENDING);
});

// ---------------------------------------------------------------------------
// Technician assignment + production steps
// ---------------------------------------------------------------------------

it('assigns a technician and seeds the four V2 steps', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::TECHNICIAN_ASSIGNED);
    expect(LabOrderAssignment::where('lab_order_id', $order->id)
        ->where('technician_id', $technician->id)
        ->where('status', LabOrderAssignment::STATUS_ASSIGNED)->exists())->toBeTrue();
    expect(ProductionStep::where('lab_order_id', $order->id)->pluck('step_name')->all())
        ->toBe(array_keys(LabWorkflowState::V2_PRODUCTION_STEPS));
});

it('walks the four steps in order and rejects skipping', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();
    $techUser = $technician->user()->first();

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);

    // Skipping straight to step 2 is rejected by the matrix.
    $this->actingAs($techUser)
        ->post(route('lab-v2-orders.steps.start', $order), ['step' => LabWorkflowState::STEP_2_TEETH_SETUP])
        ->assertSessionHasErrors();

    // Step 1 start + complete.
    $this->actingAs($techUser)
        ->post(route('lab-v2-orders.steps.start', $order), ['step' => LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE])
        ->assertRedirect();
    expect($order->refresh()->status)->toBe(LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE);
    expect(ProductionStep::where('lab_order_id', $order->id)
        ->where('step_name', LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE)->value('status'))
        ->toBe(ProductionStep::STATUS_IN_PROGRESS);

    $this->actingAs($techUser)
        ->post(route('lab-v2-orders.steps.complete', $order), ['step' => LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE])
        ->assertRedirect();
    expect($order->refresh()->status)->toBe(LabWorkflowState::STEP_1_COMPLETED);
});

it('rejects step work from a technician who is not assigned', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $assigned = technicianWithUser();
    $intruder = technicianWithUser();

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $assigned->id]);

    $this->actingAs($intruder->user()->first())
        ->post(route('lab-v2-orders.steps.start', $order), ['step' => LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE])
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::TECHNICIAN_ASSIGNED);
});

it('completes all steps, sends to QC, and closes the assignment', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);

    driveToQcPending($order->refresh(), $technician);

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::QC_PENDING);
    expect(LabOrderAssignment::where('lab_order_id', $order->id)->latest('id')->value('status'))
        ->toBe(LabOrderAssignment::STATUS_DONE);
});

// ---------------------------------------------------------------------------
// QC pass / fail / rework / segregation of duty
// ---------------------------------------------------------------------------

it('passes QC into MODEL_DONE with a QC row', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();
    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);
    driveToQcPending($order->refresh(), $technician);

    $qcUser = userWith(['pass_qc', 'reject_qc']);
    $this->actingAs($qcUser)
        ->post(route('lab-v2-orders.qc-pass', $order), ['notes' => 'rapi'])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::MODEL_DONE);
    expect(QualityControl::where('lab_order_id', $order->id)
        ->where('result', QualityControl::RESULT_PASSED)
        ->where('inspected_by', $qcUser->id)->exists())->toBeTrue();
});

it('requires reason and rework target on QC fail', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();
    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);
    driveToQcPending($order->refresh(), $technician);

    $this->actingAs(userWith(['reject_qc']))
        ->post(route('lab-v2-orders.qc-fail', $order), [])
        ->assertSessionHasErrors(['reason', 'target_step']);

    expect($order->refresh()->status)->toBe(LabWorkflowState::QC_PENDING);
});

it('runs the full rework loop back to the target step and re-passes QC', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();
    $techUser = $technician->user()->first();
    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);
    driveToQcPending($order->refresh(), $technician);

    $qcUser = userWith(['pass_qc', 'reject_qc']);
    $this->actingAs($qcUser)
        ->post(route('lab-v2-orders.qc-fail', $order), [
            'reason' => 'Porositas pada anasir',
            'target_step' => LabWorkflowState::STEP_2_TEETH_SETUP,
        ])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(LabWorkflowState::STEP_2_TEETH_SETUP);
    expect(QualityControl::where('lab_order_id', $order->id)
        ->where('result', QualityControl::RESULT_REJECTED)->count())->toBe(1);

    // Steps 2-4 reopened; step 1 untouched.
    $steps = ProductionStep::where('lab_order_id', $order->id)->get()->keyBy('step_name');
    expect($steps[LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE]->status)->toBe(ProductionStep::STATUS_COMPLETED);
    expect($steps[LabWorkflowState::STEP_2_TEETH_SETUP]->status)->toBe(ProductionStep::STATUS_IN_PROGRESS);
    expect($steps[LabWorkflowState::STEP_3_PROCESSING]->status)->toBe(ProductionStep::STATUS_PENDING);

    // Assignment reactivated for rework.
    expect(LabOrderAssignment::where('lab_order_id', $order->id)->latest('id')->value('status'))
        ->toBe(LabOrderAssignment::STATUS_IN_PROGRESS);

    // Finish the rework: complete steps 2-4, back to QC, pass.
    foreach ([LabWorkflowState::STEP_2_TEETH_SETUP, LabWorkflowState::STEP_3_PROCESSING, LabWorkflowState::STEP_4_FITTING_POLISH] as $step) {
        if ($order->refresh()->status !== $step) {
            $this->actingAs($techUser)->post(route('lab-v2-orders.steps.start', $order), ['step' => $step]);
        }
        $this->actingAs($techUser)->post(route('lab-v2-orders.steps.complete', $order), ['step' => $step]);
    }
    $this->actingAs($techUser)->post(route('lab-v2-orders.send-to-qc', $order));
    $this->actingAs($qcUser)->post(route('lab-v2-orders.qc-pass', $order));

    expect($order->refresh()->status)->toBe(LabWorkflowState::MODEL_DONE);

    // Append-only history: both QC decisions + the QC_FAILED log survive.
    expect(QualityControl::where('lab_order_id', $order->id)->count())->toBe(2);
    expect(LabOrderStatusLog::where('lab_order_id', $order->id)
        ->where('new_status', LabWorkflowState::QC_FAILED)->count())->toBe(1);
});

it('enforces segregation of duty: the producing technician cannot decide QC', function () {
    $order = v2At(LabWorkflowState::INTERNAL_APPROVED);
    $technician = technicianWithUser();
    $techUser = $technician->user()->first();
    $techUser->givePermissionTo(['pass_qc', 'reject_qc']);

    $this->actingAs(adminLab())
        ->post(route('lab-v2-orders.assign-technician', $order), ['technician_id' => $technician->id]);
    driveToQcPending($order->refresh(), $technician);

    $this->actingAs($techUser)
        ->post(route('lab-v2-orders.qc-pass', $order))
        ->assertSessionHasErrors();

    $this->actingAs($techUser)
        ->post(route('lab-v2-orders.qc-fail', $order), [
            'reason' => 'coba lulus sendiri',
            'target_step' => LabWorkflowState::STEP_2_TEETH_SETUP,
        ])
        ->assertSessionHasErrors();

    expect($order->refresh()->status)->toBe(LabWorkflowState::QC_PENDING);
});
