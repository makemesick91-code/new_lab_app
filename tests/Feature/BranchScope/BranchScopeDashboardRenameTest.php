<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\ModuleBranchScope;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Reporting\Services\LabDashboardKpiService;
use App\Modules\Reporting\Services\RmeDashboardKpiService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Task B — Module branch scope rule
|--------------------------------------------------------------------------
*/
it('declares RME and Inventory multi-branch and Lab single-branch', function () {
    expect(ModuleBranchScope::scope('rme'))->toBe(ModuleBranchScope::MULTI_BRANCH);
    expect(ModuleBranchScope::scope('inventory'))->toBe(ModuleBranchScope::MULTI_BRANCH);
    expect(ModuleBranchScope::scope('lab'))->toBe(ModuleBranchScope::SINGLE_BRANCH);

    expect(ModuleBranchScope::isMultiBranch('rme'))->toBeTrue();
    expect(ModuleBranchScope::isMultiBranch('inventory'))->toBeTrue();
    expect(ModuleBranchScope::isMultiBranch('lab'))->toBeFalse();

    expect(ModuleBranchScope::usesBranchFilter('lab'))->toBeFalse();
    expect(ModuleBranchScope::usesBranchFilter('rme'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Task C — Master Data Cabang flags (additive, default enabled)
|--------------------------------------------------------------------------
*/
it('defaults new branches to RME and Inventory enabled', function () {
    $branch = Branch::factory()->create();

    expect($branch->fresh()->is_rme_enabled)->toBeTrue();
    expect($branch->fresh()->is_inventory_enabled)->toBeTrue();
});

it('scopes branches by RME and Inventory enablement flags', function () {
    $rmeOnly = Branch::factory()->create(['is_rme_enabled' => true, 'is_inventory_enabled' => false]);
    $inventoryOnly = Branch::factory()->create(['is_rme_enabled' => false, 'is_inventory_enabled' => true]);

    expect(Branch::rmeEnabled()->pluck('id'))->toContain($rmeOnly->id)
        ->not->toContain($inventoryOnly->id);
    expect(Branch::inventoryEnabled()->pluck('id'))->toContain($inventoryOnly->id)
        ->not->toContain($rmeOnly->id);
});

/*
|--------------------------------------------------------------------------
| Task E — RME KPI branch-aware, Lab KPI global
|--------------------------------------------------------------------------
*/
it('filters RME KPI by branch', function () {
    $other = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $today = now()->toDateString();

    ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'visit_date' => $today, 'status' => ClinicVisit::STATUS_WAITING]);
    ClinicVisit::factory()->create(['branch_id' => $other->id, 'visit_date' => $today, 'status' => ClinicVisit::STATUS_WAITING]);

    $service = app(RmeDashboardKpiService::class);

    expect($service->metrics($this->branch->id)['visits_today'])->toBe(1);
    expect($service->metrics($other->id)['visits_today'])->toBe(1);
    expect($service->metrics(null)['visits_today'])->toBeGreaterThanOrEqual(2);
});

it('computes Lab KPI globally regardless of branch context', function () {
    $other = Branch::factory()->create(['is_active' => true]);

    LabOrder::factory()->create(['branch_id' => $this->branch->id]);
    LabOrder::factory()->create(['branch_id' => $other->id]);

    $metrics = app(LabDashboardKpiService::class)->metrics();

    // Global total includes both branches; the service exposes no branch filter.
    expect($metrics['lab_orders_total'])->toBeGreaterThanOrEqual(2);
    expect($metrics['scope_label'])->toBe('Laboratorium global');
});

/*
|--------------------------------------------------------------------------
| Task F — Dashboard renaming in the sidebar
|--------------------------------------------------------------------------
*/
it('shows Dashboard Owner in the sidebar for the Owner role', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Owner')
        ->assertSee('Dashboard RME');
});

it('shows Dashboard Inventory for inventory users', function () {
    $this->actingAs(userWith(['view dashboard', 'view_inventory', 'view_inventory_executive_dashboard']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory');
});

it('shows Dashboard Lab in the reporting group', function () {
    $this->actingAs(userWith(['view dashboard', 'view_dashboard', 'manage_report']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Lab');
});

/*
|--------------------------------------------------------------------------
| Task G — Separated RME report access matrix
|--------------------------------------------------------------------------
*/
it('lets a patient-report viewer access patient report only', function () {
    $user = userWith(['view_rme_patient_reports']);

    $this->actingAs($user)->get(route('rme.reports.patients'))->assertOk();
    $this->actingAs($user)->get(route('rme.reports.payments'))->assertForbidden();
});

it('lets a payment-report viewer access payment report only', function () {
    $user = userWith(['view_rme_payment_reports']);

    $this->actingAs($user)->get(route('rme.reports.payments'))->assertOk();
    $this->actingAs($user)->get(route('rme.reports.patients'))->assertForbidden();
});

it('lets Super Admin access both RME reports', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->get(route('rme.reports.patients'))->assertOk();
    $this->actingAs($admin)->get(route('rme.reports.payments'))->assertOk();
});

it('forbids RME reports for a clinical user without report permissions', function () {
    $user = userWith(['view_clinic_visits']);

    $this->actingAs($user)->get(route('rme.reports.patients'))->assertForbidden();
    $this->actingAs($user)->get(route('rme.reports.payments'))->assertForbidden();
});

it('grants the dedicated RME report roles their single report each', function () {
    $patientViewer = userInRole('Laporan Pasien RME');
    $paymentViewer = userInRole('Laporan Pembayaran RME');

    expect($patientViewer->can('view_rme_patient_reports'))->toBeTrue();
    expect($patientViewer->can('view_rme_payment_reports'))->toBeFalse();
    expect($paymentViewer->can('view_rme_payment_reports'))->toBeTrue();
    expect($paymentViewer->can('view_rme_patient_reports'))->toBeFalse();
});

it('does not grant Doctor any RME payment report access', function () {
    expect(userInRole('Doctor')->can('view_rme_payment_reports'))->toBeFalse();
});
