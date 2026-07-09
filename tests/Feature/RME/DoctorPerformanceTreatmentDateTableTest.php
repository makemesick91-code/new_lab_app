<?php

/**
 * HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-TREATMENT-DATE.
 *
 * The Doctor Performance / Income report auto-displays a daily table grouped by
 * "Tanggal Perawatan" (canonical trx_clinic_visits.visit_date) — no date filter
 * required first — and each date expands into its per-treatment breakdown. This
 * suite proves the auto table, the grouping, per-date/per-treatment counts, paid
 * totals from RME payment truth, the empty state, and that every access rule from
 * the -403 hotfix is preserved (own-data-only, IDOR, unlinked/denied/Kepala
 * Cabang 403, executive access) with no KTP/NIK leakage.
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
    // Sprint 66.0 online-context middleware redirects Doctor-role users without a
    // selected context — orthogonal to this report's own access boundary.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create([
        'code' => 'DPTD',
        'name' => 'Cabang Tanggal',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

/**
 * Create a paid RME visit on a specific date, with N invoice items of the given
 * treatment (one item = one tindakan) and a single payment for the visit.
 */
function dptVisit(Branch $branch, Doctor $doctor, Treatment $treatment, string $date, float $amount, int $items = 1): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => $date,
    ]);

    $total = $amount * $items;

    $invoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'subtotal' => $total,
        'grand_total' => $total,
    ]);

    for ($i = 0; $i < $items; $i++) {
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
    }

    RmePayment::factory()->create([
        'branch_id' => $branch->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'amount' => $total,
        'paid_at' => $date,
    ]);

    return $visit;
}

function dptLinkedDoctor(): array
{
    $user = userInRole('Doctor');
    $doctor = Doctor::factory()->create(['name' => 'drg. Harian', 'user_id' => $user->id]);

    return [$user, $doctor];
}

// ─── Auto Tanggal Perawatan table ─────────────────────────────────────────────

it('auto-shows the Tanggal Perawatan table for a linked doctor without any date filter', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $treatment = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);
    dptVisit($this->branch, $doctor, $treatment, now()->toDateString(), 100000);

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance')) // no date filter at all
        ->assertOk()
        ->assertSee('Rincian Harian per Tanggal Perawatan')
        ->assertSee('Tanggal Perawatan')
        ->assertSee(format_date_id(now()->toDateString()))
        ->assertSee('Tambal Gigi');
});

it('groups treatments under the correct treatment date', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $tambal = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);
    $cabut = Treatment::factory()->create(['name' => 'Cabut Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    dptVisit($this->branch, $doctor, $tambal, $today, 100000);
    dptVisit($this->branch, $doctor, $cabut, $yesterday, 80000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    $report = $service->report($access, []);

    $byDate = collect($report['daily_rows'])->keyBy('date');
    expect($byDate->keys())->toContain($today, $yesterday);

    $todayTreatments = collect($byDate[$today]['treatments'])->pluck('treatment_name');
    expect($todayTreatments)->toContain('Tambal Gigi');
    expect($todayTreatments)->not->toContain('Cabut Gigi');
});

it('never mixes another doctor treatments into a linked doctor daily table on the same date', function () {
    [$user, $doctorA] = dptLinkedDoctor();
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);
    $tambal = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);
    $cabut = Treatment::factory()->create(['name' => 'Cabut Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    dptVisit($this->branch, $doctorA, $tambal, $today, 100000);
    dptVisit($this->branch, $doctorB, $cabut, $today, 250000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    $report = $service->report($access, []);

    $treatments = collect($report['daily_rows'])->firstWhere('date', $today)['treatments'];
    $names = collect($treatments)->pluck('treatment_name');
    expect($names)->toContain('Tambal Gigi');
    expect($names)->not->toContain('Cabut Gigi');
});

it('scopes a linked doctor daily table to their own data even with a forged doctor_id', function () {
    [$user, $doctorA] = dptLinkedDoctor();
    $doctorB = Doctor::factory()->create(['name' => 'drg. Beta']);
    $treatment = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    dptVisit($this->branch, $doctorA, $treatment, $today, 100000);
    dptVisit($this->branch, $doctorB, $treatment, $today, 250000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    // Forge ?doctor_id=B — still scoped to doctor A.
    $report = $service->report($access, ['doctor_id' => $doctorB->id]);

    expect($report['selected_doctor_id'])->toBe($doctorA->id);
    $paidTotal = collect($report['daily_rows'])->sum('paid');
    expect($paidTotal)->toBe(100000.0); // A only, never B's 250000
});

it('limits displayed dates when an optional date range filter is applied', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $treatment = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    $threeDaysAgo = now()->subDays(3)->toDateString();
    dptVisit($this->branch, $doctor, $treatment, $today, 100000);
    dptVisit($this->branch, $doctor, $treatment, $threeDaysAgo, 80000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    // Only today's window.
    $report = $service->report($access, ['date_from' => $today, 'date_to' => $today]);

    $dates = collect($report['daily_rows'])->pluck('date');
    expect($dates)->toContain($today);
    expect($dates)->not->toContain($threeDaysAgo);
});

it('counts treatment items and distinct patients per date correctly', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $tambal = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);
    $cabut = Treatment::factory()->create(['name' => 'Cabut Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    // Patient 1: 4 tambal items. Patient 2: 2 cabut items. Patient 3: 1 tambal item.
    dptVisit($this->branch, $doctor, $tambal, $today, 50000, items: 4);
    dptVisit($this->branch, $doctor, $cabut, $today, 60000, items: 2);
    dptVisit($this->branch, $doctor, $tambal, $today, 50000, items: 1);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    $report = $service->report($access, []);

    $day = collect($report['daily_rows'])->firstWhere('date', $today);
    expect($day['patients'])->toBe(3);
    expect($day['treatment_types'])->toBe(2);
    expect($day['total_items'])->toBe(7); // 4 + 2 + 1

    $tambalRow = collect($day['treatments'])->firstWhere('treatment_name', 'Tambal Gigi');
    expect($tambalRow['item_count'])->toBe(5);   // 4 + 1
    expect($tambalRow['patient_count'])->toBe(2); // patient 1 + patient 3
});

it('reports per-date paid totals from the RME payment source of truth', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $treatment = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);

    $today = now()->toDateString();
    dptVisit($this->branch, $doctor, $treatment, $today, 100000);
    dptVisit($this->branch, $doctor, $treatment, $today, 75000);

    $service = app(DoctorPerformanceReportService::class);
    $access = $service->resolveAccess($user->fresh());
    $report = $service->report($access, []);

    $expectedPaid = (float) RmePayment::query()
        ->whereIn('clinic_visit_id', ClinicVisit::query()->where('doctor_id', $doctor->id)->pluck('id'))
        ->sum('amount');

    $day = collect($report['daily_rows'])->firstWhere('date', $today);
    expect($day['paid'])->toBe($expectedPaid);
    expect($day['paid'])->toBe(175000.0);
});

it('renders an empty state (not a 500) when the doctor has no treatments', function () {
    [$user] = dptLinkedDoctor();

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk()
        ->assertSee('Belum ada tanggal perawatan');
});

// ─── Access matrix preserved from the -403 hotfix ─────────────────────────────

it('lets an owner (executive) still open the report', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();
});

it('lets a super admin still open the report via the gate bypass', function () {
    $this->actingAs(userInRole('Super Admin'))
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();
});

it('still denies kepala cabang (403)', function () {
    $this->actingAs(userInRole('Kepala Cabang'))
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('still gives an unlinked doctor a clear 403 message', function () {
    $doctorUser = userInRole('Doctor');
    expect(Doctor::query()->where('user_id', $doctorUser->id)->exists())->toBeFalse();

    $response = $this->actingAs($doctorUser)->get(route('rme.reports.doctor-performance'));
    $response->assertForbidden();
    expect($response->exception?->getMessage())->toContain('Akun dokter belum terhubung ke data dokter');
});

it('still denies a user without any doctor-report permission (403)', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('never leaks a patient KTP/NIK value onto the daily treatment table', function () {
    [$user, $doctor] = dptLinkedDoctor();
    $treatment = Treatment::factory()->create(['name' => 'Tambal Gigi', 'is_active' => true]);
    dptVisit($this->branch, $doctor, $treatment, now()->toDateString(), 100000);

    $html = $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk()
        ->getContent();

    // No created patient's KTP/NIK value may appear anywhere in the report HTML,
    // and the report must not render a KTP/NIK label column.
    Patient::query()->get(['ktp_number'])->each(function (Patient $patient) use ($html) {
        if (! empty($patient->ktp_number)) {
            expect($html)->not->toContain((string) $patient->ktp_number);
        }
    });
    expect($html)->not->toContain('No. KTP');
});
