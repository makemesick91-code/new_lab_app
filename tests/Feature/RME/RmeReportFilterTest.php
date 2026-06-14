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
        'code' => 'RPT1',
        'name' => 'Cabang Laporan RME',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->patientViewer = userWith(['view_rme_patient_reports']);
    $this->paymentViewer = userWith(['view_rme_payment_reports']);
});

function rptPatient(array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'medical_record_number' => 'RM-RPT-'.fake()->unique()->numerify('####'),
        'ktp_number' => '3374019900123456',
        'name' => 'Pasien Laporan RME',
    ], $overrides));
}

function rptVisit(Patient $patient, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['is_active' => true])->id,
        'visit_date' => now()->toDateString(),
        'status' => ClinicVisit::STATUS_COMPLETED,
    ], $overrides));
}

function rptPayment(
    Patient $patient,
    PaymentMethod $paymentMethod,
    Treatment $treatment,
    Doctor $doctor,
    array $overrides = [],
    array $visitOverrides = [],
): RmePayment {
    $visit = rptVisit($patient, array_merge(['doctor_id' => $doctor->id], $visitOverrides));

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

it('filters patient report by visit date range', function () {
    $patient = rptPatient();
    $included = rptVisit($patient, ['visit_date' => '2026-06-10']);
    rptVisit($patient, ['visit_date' => '2026-06-01']);

    $response = $this->actingAs($this->patientViewer)->get(route('rme.reports.patients', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
    ]));

    $response->assertOk()
        ->assertSee($included->visit_number)
        ->assertDontSee(
            ClinicVisit::query()
                ->whereDate('visit_date', '2026-06-01')
                ->where('patient_id', $patient->id)
                ->value('visit_number')
        );
});

it('filters patient report by status', function () {
    $patient = rptPatient();
    $waiting = rptVisit($patient, ['status' => ClinicVisit::STATUS_WAITING]);
    rptVisit($patient, ['status' => ClinicVisit::STATUS_COMPLETED]);

    $response = $this->actingAs($this->patientViewer)->get(route('rme.reports.patients', [
        'status' => ClinicVisit::STATUS_WAITING,
    ]));

    $response->assertOk()
        ->assertSee($waiting->visit_number)
        ->assertSee('Menunggu');
});

it('searches patient report by patient id rm and name', function () {
    $patient = rptPatient([
        'name' => 'Budi Santoso RME',
        'medical_record_number' => 'RM-SEARCH-001',
    ]);
    $visit = rptVisit($patient);

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', ['q' => 'Budi Santoso']))
        ->assertOk()
        ->assertSee($visit->visit_number)
        ->assertSee('RM-SEARCH-001');

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', ['q' => 'RM-SEARCH-001']))
        ->assertOk()
        ->assertSee($visit->visit_number);

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', ['q' => (string) $patient->id]))
        ->assertOk()
        ->assertSee($visit->visit_number);
});

it('does not expose ktp on patient report', function () {
    $patient = rptPatient(['ktp_number' => '9999888877776666']);
    rptVisit($patient);

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertDontSee('9999888877776666')
        ->assertDontSee('ktp_number')
        ->assertDontSee('Nomor KTP');
});

it('filters payment report by visit date range', function () {
    $patient = rptPatient();
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);

    $included = rptPayment($patient, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-06-10']);
    $excluded = rptPayment($patient, $paymentMethod, $treatment, $doctor, [], ['visit_date' => '2026-06-01']);

    $response = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments', [
        'date_from' => '2026-06-09',
        'date_to' => '2026-06-11',
    ]));

    $response->assertOk()
        ->assertSee($included->rmeInvoice->invoice_number)
        ->assertDontSee($excluded->rmeInvoice->invoice_number)
        ->assertDontSee('3374019900123456')
        ->assertDontSee('ktp_number')
        ->assertDontSee('Nomor KTP');
});

it('filters payment report by payment method', function () {
    $patient = rptPatient();
    $cash = PaymentMethod::factory()->create(['name' => 'Tunai RME Report', 'is_active' => true]);
    $transfer = PaymentMethod::factory()->create(['name' => 'Transfer RME Report', 'is_active' => true]);
    $treatment = Treatment::factory()->create(['name' => 'Scaling RME', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. RME Report', 'is_active' => true]);

    $included = rptPayment($patient, $cash, $treatment, $doctor);
    rptPayment($patient, $transfer, $treatment, $doctor);

    $response = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments', [
        'payment_method_id' => $cash->id,
    ]));

    $response->assertOk()
        ->assertSee($included->rmeInvoice->invoice_number)
        ->assertSee('Tunai RME Report');
});

it('filters payment report by treatment', function () {
    $patient = rptPatient();
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $scaling = Treatment::factory()->create(['name' => 'Scaling Filter RME', 'is_active' => true]);
    $filling = Treatment::factory()->create(['name' => 'Tambal Filter RME', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);

    $included = rptPayment($patient, $paymentMethod, $scaling, $doctor);
    $excluded = rptPayment($patient, $paymentMethod, $filling, $doctor);

    $response = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments', [
        'treatment_id' => $scaling->id,
    ]));

    $response->assertOk()
        ->assertSee($included->rmeInvoice->invoice_number)
        ->assertSee('Scaling Filter RME')
        ->assertDontSee($excluded->rmeInvoice->invoice_number);
});

it('filters payment report by doctor', function () {
    $patient = rptPatient();
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $targetDoctor = Doctor::factory()->create(['name' => 'Dr. Filter RME', 'is_active' => true]);
    $otherDoctor = Doctor::factory()->create(['name' => 'Dr. Lain RME', 'is_active' => true]);

    $included = rptPayment($patient, $paymentMethod, $treatment, $targetDoctor);
    $excluded = rptPayment($patient, $paymentMethod, $treatment, $otherDoctor);

    $response = $this->actingAs($this->paymentViewer)->get(route('rme.reports.payments', [
        'doctor_id' => $targetDoctor->id,
    ]));

    $response->assertOk()
        ->assertSee($included->rmeInvoice->invoice_number)
        ->assertSee('Dr. Filter RME')
        ->assertDontSee($excluded->rmeInvoice->invoice_number);
});

it('searches payment report by patient id rm and name', function () {
    $patient = rptPatient([
        'name' => 'Siti Payment Report',
        'medical_record_number' => 'RM-PAY-SEARCH',
    ]);
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    $payment = rptPayment($patient, $paymentMethod, $treatment, $doctor);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments', ['q' => 'Siti Payment']))
        ->assertOk()
        ->assertSee($payment->rmeInvoice->invoice_number)
        ->assertSee('RM-PAY-SEARCH');

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments', ['q' => (string) $patient->id]))
        ->assertOk()
        ->assertSee($payment->rmeInvoice->invoice_number);
});

it('shows payment method treatment and doctor on payment report', function () {
    $patient = rptPatient();
    $paymentMethod = PaymentMethod::factory()->create(['name' => 'QRIS RME Report', 'is_active' => true]);
    $treatment = Treatment::factory()->create(['name' => 'Bleaching RME', 'is_active' => true]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Visible RME', 'is_active' => true]);
    rptPayment($patient, $paymentMethod, $treatment, $doctor);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments'))
        ->assertOk()
        ->assertSee('Tanggal Kunjungan Dari')
        ->assertSee('Tanggal Kunjungan Sampai')
        ->assertSee('QRIS RME Report')
        ->assertSee('Bleaching RME')
        ->assertSee('Dr. Visible RME');
});

it('does not expose ktp on payment report', function () {
    $patient = rptPatient(['ktp_number' => '1111222233334444']);
    $paymentMethod = PaymentMethod::factory()->create(['is_active' => true]);
    $treatment = Treatment::factory()->create(['is_active' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    rptPayment($patient, $paymentMethod, $treatment, $doctor);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments'))
        ->assertOk()
        ->assertDontSee('1111222233334444')
        ->assertDontSee('ktp_number')
        ->assertDontSee('Nomor KTP');
});
