<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create([
        'code' => 'EXP1',
        'name' => 'Cabang Export RME',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->patientViewer = userWith(['view_rme_patient_reports']);
    $this->paymentViewer = userWith(['view_rme_payment_reports']);
});

function expPatient(array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'medical_record_number' => 'RM-EXP-'.fake()->unique()->numerify('####'),
        'ktp_number' => fake()->unique()->numerify('################'),
        'name' => 'Pasien Export RME',
    ], $overrides));
}

function expVisit(Patient $patient, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['is_active' => true, 'name' => 'Dr. Export RME'])->id,
        'visit_date' => clinicalToday()->toDateString(),
        'status' => ClinicVisit::STATUS_COMPLETED,
        'chief_complaint' => 'Sakit gigi',
    ], $overrides));
}

function expPayment(
    Patient $patient,
    PaymentMethod $paymentMethod,
    Treatment $treatment,
    Doctor $doctor,
    array $overrides = [],
    array $visitOverrides = [],
): RmePayment {
    $visit = expVisit($patient, array_merge(['doctor_id' => $doctor->id], $visitOverrides));

    $invoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => test()->branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'grand_total' => 250000,
    ]);

    RmeInvoiceItem::create([
        'rme_invoice_id' => $invoice->id,
        'treatment_id' => $treatment->id,
        'doctor_id' => $doctor->id,
        'description' => $treatment->name,
        'qty' => 1,
        'unit_price' => 250000,
        'discount' => 0,
        'subtotal' => 250000,
    ]);

    return RmePayment::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'payment_method_id' => $paymentMethod->id,
        'amount' => 250000,
        'paid_at' => now(),
    ], $overrides));
}

it('exports patient report as csv download', function () {
    $patient = expPatient(['name' => 'Export CSV Pasien']);
    expVisit($patient);

    $response = $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients.export'));

    $response->assertOk();
    $response->assertHeader('content-disposition');
    expect($response->headers->get('content-disposition'))->toContain('laporan-pasien-rme');
});

it('exports patient report respecting active filters', function () {
    $patient = expPatient(['name' => 'Filter Export Pasien', 'medical_record_number' => 'RM-EXP-FILTER']);
    $included = expVisit($patient, ['visit_date' => '2026-06-10', 'status' => ClinicVisit::STATUS_WAITING]);
    $excluded = expVisit($patient, ['visit_date' => '2026-06-01', 'status' => ClinicVisit::STATUS_COMPLETED]);

    $response = $this->actingAs($this->patientViewer)->get(route('rme.reports.patients.export', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
        'status' => ClinicVisit::STATUS_WAITING,
        'q' => 'Filter Export',
    ]));

    $csv = $response->streamedContent();

    expect($csv)->toContain('RM-EXP-FILTER')
        ->toContain('Filter Export Pasien')
        ->toContain('Menunggu')
        ->not->toContain($excluded->visit_number);
});

it('does not expose ktp on patient report export', function () {
    $patient = expPatient([
        'name' => 'KTP Export Pasien',
        'ktp_number' => '8888777766665555',
        'medical_record_number' => 'RM-EXP-KTP',
    ]);
    expVisit($patient);

    $csv = $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients.export', ['q' => 'KTP Export']))
        ->streamedContent();

    expect($csv)->toContain('RM-EXP-KTP')
        ->not->toContain('8888777766665555')
        ->not->toContain('ktp_number')
        ->not->toContain('Nomor KTP');
});

it('prints patient report with filter summary and totals', function () {
    $patientA = expPatient(['name' => 'Print Pasien A', 'medical_record_number' => 'RM-PRINT-A']);
    $patientB = expPatient(['name' => 'Print Pasien B', 'medical_record_number' => 'RM-PRINT-B']);
    expVisit($patientA, ['visit_date' => '2026-06-10']);
    expVisit($patientB, ['visit_date' => '2026-06-10']);

    $response = $this->actingAs($this->patientViewer)->get(route('rme.reports.patients.print', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
    ]));

    $response->assertOk()
        ->assertSee('Laporan Pasien RME')
        ->assertSee('Total Pasien Hasil Filter')
        ->assertSee('2')
        ->assertSee('RM-PRINT-A')
        ->assertSee('RM-PRINT-B')
        ->assertDontSee('8888777766665555')
        ->assertDontSee('Nomor KTP');
});

it('exports payment report as csv download', function () {
    $patient = expPatient(['name' => 'Export CSV Payment']);
    $paymentMethod = PaymentMethod::factory()->create(['name' => 'Tunai Export', 'is_active' => true]);
    $treatment = Treatment::factory()->create(['name' => 'Scaling Export', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Payment Export', 'is_active' => true]);
    expPayment($patient, $paymentMethod, $treatment, $doctor);

    $response = $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments.export'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('laporan-pembayaran-rme');
});

it('exports payment report respecting active filters', function () {
    $patient = expPatient(['name' => 'Filter Payment Export', 'medical_record_number' => 'RM-PAY-EXP']);
    $cash = PaymentMethod::factory()->create(['name' => 'Tunai Filter Export', 'is_active' => true]);
    $transfer = PaymentMethod::factory()->create(['name' => 'Transfer Filter Export', 'is_active' => true]);
    $treatment = Treatment::factory()->create(['name' => 'Bleaching Export', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Filter Payment', 'is_active' => true]);

    $included = expPayment($patient, $cash, $treatment, $doctor, [], ['visit_date' => '2026-06-10']);
    $excluded = expPayment($patient, $transfer, $treatment, $doctor, [], ['visit_date' => '2026-06-01']);

    $csv = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments.export', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
        'payment_method_id' => $cash->id,
        'treatment_id' => $treatment->id,
        'doctor_id' => $doctor->id,
        'q' => 'Filter Payment',
    ]))->streamedContent();

    expect($csv)->toContain('RM-PAY-EXP')
        ->toContain('Tunai Filter Export')
        ->toContain('Bleaching Export')
        ->toContain('Dr. Filter Payment')
        ->not->toContain($excluded->rmeInvoice->invoice_number);
});

it('does not expose ktp on payment report export', function () {
    $patient = expPatient([
        'name' => 'KTP Payment Export',
        'ktp_number' => '4444333322221111',
        'medical_record_number' => 'RM-PAY-KTP',
    ]);
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    expPayment($patient, $paymentMethod, $treatment, $doctor);

    $csv = $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments.export', ['q' => 'KTP Payment']))
        ->streamedContent();

    expect($csv)->toContain('RM-PAY-KTP')
        ->not->toContain('4444333322221111')
        ->not->toContain('ktp_number');
});

it('prints payment report with summary totals and active filters', function () {
    $paymentMethod = PaymentMethod::factory()->create(['name' => 'QRIS Print Export', 'is_active' => true]);
    $treatment = Treatment::factory()->create(['name' => 'Tambal Print Export', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Print Payment', 'is_active' => true]);

    $patientA = expPatient(['name' => 'Print Pay A', 'medical_record_number' => 'RM-PPRINT-A']);
    $patientB = expPatient(['name' => 'Print Pay B', 'medical_record_number' => 'RM-PPRINT-B']);
    $patientC = expPatient(['name' => 'Print Pay C Luar', 'medical_record_number' => 'RM-PPRINT-C']);

    expPayment($patientA, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-06-10']);
    expPayment($patientA, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-06-11']);
    expPayment($patientB, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-06-10']);
    $excluded = expPayment($patientC, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-05-01']);

    $response = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments.print', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
        'payment_method_id' => $paymentMethod->id,
    ]));

    $response->assertOk()
        ->assertSee('Laporan Pembayaran RME')
        ->assertSee('Total Pasien Hasil Filter')
        ->assertSee('2 pasien')
        ->assertSee('Total Baris Transaksi')
        ->assertSee('3 transaksi')
        ->assertSee('Total Pembayaran Hasil Filter')
        ->assertSee('QRIS Print Export')
        ->assertSee('Tambal Print Export')
        ->assertSee('Dr. Print Payment')
        ->assertSee('RM-PPRINT-A')
        ->assertSee('RM-PPRINT-B')
        ->assertDontSee('RM-PPRINT-C')
        ->assertDontSee($excluded->rmeInvoice->invoice_number)
        ->assertDontSee('4444333322221111')
        ->assertDontSee('Nomor KTP');
});

it('denies patient export without patient report permission', function () {
    $user = userWith(['view_rme_payment_reports']);

    $this->actingAs($user)
        ->get(route('rme.reports.patients.export'))
        ->assertForbidden();
});

it('lets Admin Klinik open export and print patient reports', function () {
    $admin = rmeAdminClinicUser($this->branch);

    $this->actingAs($admin)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('Laporan Pasien RME');

    $this->actingAs($admin)
        ->get(route('rme.reports.patients.export'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('rme.reports.patients.print'))
        ->assertOk()
        ->assertSee('Laporan Pasien RME');
});

it('denies payment export without payment report permission', function () {
    $user = userWith(['view_rme_patient_reports']);

    $this->actingAs($user)
        ->get(route('rme.reports.payments.export'))
        ->assertForbidden();
});
