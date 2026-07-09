<?php

/**
 * HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-403 — Doctor Performance report access.
 *
 * Official access decision for this hotfix:
 *   - Linked Doctor (mst_doctors.user_id + own permission) → own data only.
 *   - Unlinked Doctor (own permission, no doctor link)     → clear 403 message.
 *   - Doctor A cannot see Doctor B data (IDOR-forced).
 *   - Owner / Super Admin (executive permission / bypass)  → access.
 *   - Kepala Cabang                                        → 403, no permission.
 *   - User without permission                              → 403.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    // The Sprint 66.0 online-context middleware redirects any Doctor-role user
    // without a selected branch/room — orthogonal to this hotfix, which is about
    // the report's OWN access control. Bypass it so we test that boundary directly.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create([
        'code' => 'DPRA',
        'name' => 'Cabang Akses',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

function dpraPaidVisit(Branch $branch, Doctor $doctor, Treatment $treatment, float $amount): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => now()->toDateString(),
    ]);

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

// ─── Access matrix ────────────────────────────────────────────────────────────

it('lets a linked doctor open the doctor performance report', function () {
    $doctorUser = userInRole('Doctor');
    Doctor::factory()->create(['name' => 'drg. Terhubung', 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();
});

it('gives an unlinked doctor a clear 403 message (not a bare 403)', function () {
    $doctorUser = userInRole('Doctor'); // Doctor role grants view_own_doctor_performance_report

    // No mst_doctors.user_id link exists for this user.
    expect(Doctor::query()->where('user_id', $doctorUser->id)->exists())->toBeFalse();

    $response = $this->actingAs($doctorUser)
        ->get(route('rme.reports.doctor-performance'));

    $response->assertForbidden();
    expect($response->exception?->getMessage())
        ->toContain('Akun dokter belum terhubung ke data dokter');
});

it('never exposes another doctor data to a linked doctor even with a forged doctor_id', function () {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctorUser = userInRole('Doctor');
    $doctorA = Doctor::factory()->create(['name' => 'drg. Alpha', 'user_id' => $doctorUser->id]);
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);

    dpraPaidVisit($this->branch, $doctorA, $treatment, 100000);
    dpraPaidVisit($this->branch, $doctorB, $treatment, 250000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($doctorUser->fresh());

    expect($access['mode'])->toBe('own');
    expect($access['forced_doctor_id'])->toBe($doctorA->id);

    // Forge ?doctor_id=B — still scoped to doctor A only.
    $report = $service->report($access, ['doctor_id' => $doctorB->id]);
    expect($report['selected_doctor_id'])->toBe($doctorA->id);
    expect($report['kpis']['paid'])->toBe(100000.0);
});

it('denies kepala cabang and does not grant it the doctor performance permission', function () {
    $kepala = userInRole('Kepala Cabang');

    expect($kepala->can('view_doctor_performance_report'))->toBeFalse();
    expect($kepala->can('view_own_doctor_performance_report'))->toBeFalse();

    $this->actingAs($kepala)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('denies a user without any doctor-report permission (403)', function () {
    $user = userWith(['view_clinic_visits']);

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('lets an owner (executive permission) access the report', function () {
    $owner = userInRole('Owner');

    expect($owner->can('view_doctor_performance_report'))->toBeTrue();

    $this->actingAs($owner)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();
});

it('lets a super admin access the report via the gate bypass', function () {
    $superAdmin = userInRole('Super Admin');

    $this->actingAs($superAdmin)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();
});
