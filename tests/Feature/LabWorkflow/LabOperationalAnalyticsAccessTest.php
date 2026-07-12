<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabOperationalAnalyticsService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;

beforeEach(fn () => seedAccessControl());

$indexUrl = '/lab/analytics/operational-kpi';
$exportUrl = '/lab/analytics/operational-kpi/export';

it('denies a user with no lab analytics permission', function () use ($indexUrl) {
    $this->actingAs(userWith([]))->get($indexUrl)->assertForbidden();
});

it('denies clinical/cashier roles (doctor, admin klinik, kasir permissions)', function () use ($indexUrl) {
    // Representative non-lab permissions must not open lab analytics.
    $this->actingAs(userWith(['manage_clinic_visits']))->get($indexUrl)->assertForbidden();
    $this->actingAs(userWith(['manage_rme_billing']))->get($indexUrl)->assertForbidden();
});

it('allows the full management tier (view_lab_operational_analytics)', function () use ($indexUrl) {
    $this->actingAs(userWith(['view_lab_operational_analytics']))
        ->get($indexUrl)
        ->assertOk()
        ->assertSee('KPI Operasional Lab')
        ->assertSee('Cakupan Kualitas Data');
});

it('allows manage_lab_orders as a management tier', function () use ($indexUrl) {
    $this->actingAs(userWith(['manage_lab_orders']))->get($indexUrl)->assertOk();
});

it('own tier: a linked active technician sees only their own scope', function () use ($indexUrl) {
    $user = userWith(['view_own_lab_operational_analytics']);
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true, 'name' => 'Teknisi Uji']);

    $this->actingAs($user->fresh())
        ->get($indexUrl)
        ->assertOk()
        ->assertSee('data Anda sendiri sebagai teknisi');
});

it('own tier without a linked technician record is denied (clear boundary)', function () use ($indexUrl) {
    // Has the own-permission but no mst_technicians link → denied.
    $this->actingAs(userWith(['view_own_lab_operational_analytics']))->get($indexUrl)->assertForbidden();
});

it('own tier is forced to its own technician_id (crafted technician_id ignored)', function () {
    $user = userWith(['view_own_lab_operational_analytics']);
    $mine = Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);
    $other = Technician::factory()->create(['is_active' => true]);

    $scope = app(LabOperationalAnalyticsService::class)->resolveScope($user->fresh(), 999, $other->id);

    expect($scope['tier'])->toBe('own')
        ->and($scope['technician_id'])->toBe($mine->id) // forged id ignored
        ->and($scope['branch_id'])->toBeNull();
});

it('full tier drops a crafted branch_id that is not an active RME branch (IDOR-safe)', function () {
    $user = userWith(['view_lab_operational_analytics']);

    $scope = app(LabOperationalAnalyticsService::class)->resolveScope($user, 999999, null);

    expect($scope['tier'])->toBe('full')->and($scope['branch_id'])->toBeNull(); // crafted → all branches
});

it('branch operator cannot leak another branch via a forced branch filter', function () {
    $a = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $b = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_V2, 'branch_id' => $a->id, 'status' => LabWorkflowState::QC_PENDING, 'order_date' => now()->toDateString()]);
    LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_V2, 'branch_id' => $b->id, 'status' => LabWorkflowState::QC_PENDING, 'order_date' => now()->toDateString()]);

    $user = userWith(['view_lab_operational_analytics']);
    $scope = app(LabOperationalAnalyticsService::class)->resolveScope($user, $a->id, null);
    $data = app(LabOperationalAnalyticsService::class)->analytics($scope, ['period' => 'month']);

    expect($data['kpi']['open_wip'])->toBe(1); // only branch A
});

it('exports a PII-free CSV with the same authorization and no KTP', function () use ($exportUrl) {
    $tech = Technician::factory()->create(['is_active' => true, 'name' => 'Teknisi CSV']);
    $order = LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_V2, 'branch_id' => 1, 'status' => LabWorkflowState::STEP_2_TEETH_SETUP, 'order_date' => now()->toDateString()]);
    LabOrderAssignment::factory()->create(['lab_order_id' => $order->id, 'technician_id' => $tech->id, 'assigned_at' => now()]);

    $response = $this->actingAs(userWith(['view_lab_operational_analytics']))->get($exportUrl);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    $csv = $response->streamedContent();
    expect($csv)->toContain('KPI Operasional Lab')
        ->toContain('Teknisi CSV')
        ->not->toContain('KTP')
        ->not->toContain('NIK');
    // No KTP/NIK-style 13+ digit identity numbers leak into the export.
    expect(preg_match('/\d{13,}/', $csv))->toBe(0);
});

it('denies export without a lab analytics permission', function () use ($exportUrl) {
    $this->actingAs(userWith([]))->get($exportUrl)->assertForbidden();
});

it('Super Admin (Gate::before) can access analytics', function () use ($indexUrl) {
    $this->actingAs(superAdmin())->get($indexUrl)->assertOk();
});

it('validates the custom period range shape', function () use ($indexUrl) {
    $this->actingAs(userWith(['view_lab_operational_analytics']))
        ->get($indexUrl.'?period=custom&from=2026-07-10&to=2026-07-01')
        ->assertSessionHasErrors('to');
});
