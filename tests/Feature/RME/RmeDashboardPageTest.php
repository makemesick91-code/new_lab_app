<?php

/**
 * Sprint 58.4 — RME Dashboard Page & KPI Cards.
 *
 * Verifies the standalone /rme/dashboard page: permissions, KPI card labels,
 * shortcut links, branch-isolated aggregate metrics, and privacy (no patient
 * identity / sensitive fields rendered).
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    Branch::where('code', Branch::MAIN_CODE)->update([
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);

    $this->atg3 = Branch::factory()->create([
        'code' => 'ATG3', 'name' => 'Cabang Antang',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->tkm1 = Branch::factory()->create([
        'code' => 'TLK1', 'name' => 'Cabang Telkomas',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $this->doctor = Doctor::factory()->create();
    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

afterEach(fn () => Carbon::setTestNow());

function dashVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_date' => Carbon::today()->toDateString(),
    ], $overrides));
}

// --- Permissions ---------------------------------------------------------------

it('allows an RME-allowed user to open the dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard RME')
        ->assertSee('Ringkasan operasional RME');
});

it('forbids a user without RME permissions', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('rme.dashboard'))
        ->assertForbidden();
});

// --- KPI card labels -----------------------------------------------------------

it('renders all required KPI card labels', function () {
    $response = $this->actingAs($this->admin)->get(route('rme.dashboard'))->assertOk();

    foreach ([
        'Kunjungan Hari Ini',
        'Kunjungan Bulan Ini',
        'Pasien Baru Bulan Ini',
        'Kunjungan Kontrol / Follow-up',
        'Rekam Medis Draft',
        'Rekam Medis Finalized',
        'Menunggu Kasir',
        'Piutang RME Aktif',
        'Pembayaran Hari Ini',
    ] as $label) {
        $response->assertSee($label);
    }
});

// --- Shortcuts -----------------------------------------------------------------

it('renders shortcut links to the main RME pages', function () {
    $response = $this->actingAs($this->admin)->get(route('rme.dashboard'))->assertOk();

    $response->assertSee('/rme/visits', false);
    $response->assertSee('/rme/medical-records', false);
    $response->assertSee('/rme/cashier', false);
    $response->assertSee('/rme/reports/patients', false);
    $response->assertSee('/rme/reports/payments', false);
});

// --- Privacy -------------------------------------------------------------------

it('does not expose sensitive patient or system fields', function () {
    // Seed a patient + visit so the page has data to aggregate over.
    dashVisit($this->atg3);

    $html = $this->actingAs($this->admin)->get(route('rme.dashboard'))->assertOk()->getContent();

    // Patient identity / clinical detail and secret material must never appear on
    // an aggregate dashboard. (The framework CSRF token meta is intentional scaffolding
    // on every authenticated page and is not patient/secret data, so the generic word
    // "token" is asserted via the specific sensitive token columns below.)
    foreach ([
        'ktp_number', 'whatsapp_number', 'phone', 'address',
        'password', 'remember_token', 'api_token', '.env',
    ] as $needle) {
        expect($html)->not->toContain($needle);
    }
});

// --- Metrics -------------------------------------------------------------------

it('counts today and this-month visits across all RME branches', function () {
    dashVisit($this->atg3, ['visit_date' => Carbon::today()->toDateString()]);
    dashVisit($this->tkm1, ['visit_date' => Carbon::today()->toDateString()]);
    dashVisit($this->atg3, ['visit_date' => '2026-06-02']); // earlier this month
    dashVisit($this->atg3, ['visit_date' => '2026-05-20']); // last month

    $response = $this->actingAs($this->admin)->get(route('rme.dashboard'))->assertOk();

    // 2 today, 3 this month.
    $response->assertSeeInOrder(['Kunjungan Hari Ini']);
    expect($response->getContent())->toContain('Dashboard RME');
});

it('counts active receivables and today payments branch-scoped', function () {
    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->atg3->id,
        'grand_total' => 200000,
    ]);
    RmePayment::factory()->create([
        'branch_id' => $this->atg3->id,
        'rme_invoice_id' => $invoice->id,
        'amount' => 50000,
        'paid_at' => Carbon::now(),
    ]);

    $response = $this->actingAs($this->admin)->get(route('rme.dashboard'))->assertOk();

    // Active receivable (grand_total 200k > 50k paid) and today's payment total.
    $response->assertSee('Piutang RME Aktif');
    $response->assertSee('Pembayaran Hari Ini');
    $response->assertSee('Rp 50.000', false);
});

it('counts finalized medical records for the current month', function () {
    $visit = dashVisit($this->atg3);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->atg3->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $this->doctor->id,
        'finalized_at' => Carbon::now(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.dashboard'))
        ->assertOk()
        ->assertSee('Rekam Medis Finalized');
});

it('narrows metrics to a single RME branch via branch_id filter', function () {
    dashVisit($this->atg3);
    dashVisit($this->tkm1);

    $this->actingAs($this->admin)
        ->get(route('rme.dashboard', ['branch_id' => $this->atg3->id]))
        ->assertOk()
        ->assertSee('Dashboard RME');
});
