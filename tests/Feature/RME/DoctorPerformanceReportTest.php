<?php

/**
 * FIX-PRE-68-45 Scope C — Doctor Performance / Income report.
 *
 * Covers: executive summary (all doctors), executive detail (one doctor),
 * doctor own-only visibility, cross-doctor denial + IDOR guard, unauthorized
 * 403, and totals matching the RME payment/invoice source of truth.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    // Disable MAIN RME; use a dedicated RME-enabled branch (report scope excludes MAIN).
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create([
        'code' => 'DPR1',
        'name' => 'Cabang Kinerja',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

function dprVisit(Branch $branch, Doctor $doctor): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    return ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => now()->toDateString(),
    ]);
}

function dprPaidVisit(Branch $branch, Doctor $doctor, Treatment $treatment, float $amount): ClinicVisit
{
    $visit = dprVisit($branch, $doctor);

    $invoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'subtotal' => $amount,
        'grand_total' => $amount,
    ]);

    RmeInvoiceItem::create([
        'rme_invoice_id' => $invoice->id,
        'treatment_id' => $treatment->id,
        'doctor_id' => $doctor->id,
        'description' => $treatment->name,
        'qty' => 1,
        'unit_price' => $amount,
        'discount' => 0,
        'subtotal' => $amount,
    ]);

    RmePayment::factory()->create([
        'branch_id' => $branch->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'amount' => $amount,
        'paid_at' => now(),
    ]);

    return $visit;
}

// ─── Access + visibility ──────────────────────────────────────────────────────

it('denies users without any doctor-report permission (403)', function () {
    $user = userWith(['view_clinic_visits']);

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('denies a user who has the own-doctor permission but is not a linked doctor (403)', function () {
    $user = userWith(['view_own_doctor_performance_report']);

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('lets an executive see the per-doctor summary and drill into one doctor', function () {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctorA = Doctor::factory()->create(['name' => 'drg. Alpha']);
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);
    dprPaidVisit($this->branch, $doctorA, $treatment, 100000);
    dprPaidVisit($this->branch, $doctorB, $treatment, 250000);

    $exec = userWith(['view_doctor_performance_report']);

    // Summary lists both doctors.
    $this->actingAs($exec)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk()
        ->assertSee('drg. Alpha')
        ->assertSee('drg. Beta')
        ->assertSee('Ringkasan per Dokter');

    // Drill into doctor A → detail view.
    $this->actingAs($exec)
        ->get(route('rme.reports.doctor-performance', ['doctor_id' => $doctorA->id]))
        ->assertOk()
        ->assertSee('Rincian per Tindakan / Perawatan');
});

it('scopes a linked doctor to their own data and ignores a forged doctor_id (IDOR guard)', function () {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctorUser = userWith(['view_own_doctor_performance_report']);
    $doctorA = Doctor::factory()->create(['name' => 'drg. Alpha', 'user_id' => $doctorUser->id]);
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);

    dprPaidVisit($this->branch, $doctorA, $treatment, 100000);
    dprPaidVisit($this->branch, $doctorB, $treatment, 250000);

    // Even forging ?doctor_id=B, the linked doctor only ever sees their own scope.
    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($doctorUser->fresh());
    expect($access['mode'])->toBe('own');
    expect($access['forced_doctor_id'])->toBe($doctorA->id);

    $report = $service->report($access, ['doctor_id' => $doctorB->id]);
    expect($report['view_mode'])->toBe('detail');
    expect($report['selected_doctor_id'])->toBe($doctorA->id);
    // Totals reflect only doctor A (100000), never doctor B (250000).
    expect($report['kpis']['paid'])->toBe(100000.0);
    expect($report['kpis']['patients'])->toBe(1);
});

// ─── Totals match source ──────────────────────────────────────────────────────

it('reports paid totals that match the RME payment source of truth', function () {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'drg. Gamma']);
    dprPaidVisit($this->branch, $doctor, $treatment, 100000);
    dprPaidVisit($this->branch, $doctor, $treatment, 75000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess(userWith(['view_doctor_performance_report']));
    $report = $service->report($access, ['doctor_id' => $doctor->id]);

    $expectedPaid = (float) RmePayment::query()
        ->whereIn('clinic_visit_id', ClinicVisit::query()->where('doctor_id', $doctor->id)->pluck('id'))
        ->sum('amount');

    expect($report['kpis']['paid'])->toBe($expectedPaid);
    expect($report['kpis']['billed'])->toBe(175000.0);
    expect($report['kpis']['patients'])->toBe(2);
});

it('excludes another RME branch and MAIN from a cross-branch executive summary', function () {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $otherBranch = Branch::factory()->create(['code' => 'DPR2', 'name' => 'Cabang Lain', 'is_active' => true, 'is_rme_enabled' => true]);
    $doctorA = Doctor::factory()->create(['name' => 'drg. Alpha']);
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);
    dprPaidVisit($this->branch, $doctorA, $treatment, 100000);
    dprPaidVisit($otherBranch, $doctorB, $treatment, 250000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess(userWith(['view_doctor_performance_report']));

    // Narrow to $this->branch only → doctor B (other branch) must be absent.
    $report = $service->report($access, ['branch_id' => $this->branch->id]);
    $doctorIds = collect($report['summary_rows'])->pluck('doctor_id')->all();
    expect($doctorIds)->toContain($doctorA->id);
    expect($doctorIds)->not->toContain($doctorB->id);
});
