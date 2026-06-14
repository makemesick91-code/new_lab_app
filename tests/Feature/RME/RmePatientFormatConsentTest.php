<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = Branch::factory()->create(['code' => 'FMT1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    $this->visitAdmin = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
    $this->cashier = userWith(['manage_rme_billing']);
});

function consentRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'registered_at' => '2026-06-14',
        'manual_rm_number' => '0101',
        'name' => 'Pasien Format RME',
        'gender' => 'Female',
        'is_active' => 1,
    ], $overrides);
}

function consentUnpaidInvoice(Branch $branch, $cashier): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => [[
            'description' => 'Konsultasi',
            'qty' => 1,
            'unit_price' => 400000,
            'discount' => 0,
        ]]],
    );

    return [$visit->fresh(), $invoice->fresh()];
}

function consentPaymentPayload(RmeInvoice $invoice, array $overrides = []): array
{
    return array_merge([
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ], $overrides);
}

it('rejects patient create when ktp belongs to another patient', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010001']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), consentRegistrationPayload([
            'ktp_number' => '3201010101010001',
            'manual_rm_number' => '0102',
        ]))
        ->assertSessionHasErrors('ktp_number');

    expect(Patient::where('name', 'Pasien Format RME')->exists())->toBeFalse();
});

it('rejects patient update when ktp belongs to another patient', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010002']);
    $patient = Patient::factory()->create(['ktp_number' => '3201010101010099']);

    $this->actingAs(userWith(['manage patients']))
        ->put(route('settings.patients.update', $patient), consentRegistrationPayload([
            'ktp_number' => '3201010101010002',
            'name' => $patient->name,
        ]))
        ->assertSessionHasErrors('ktp_number');
});

it('allows patient update keeping the same ktp number', function () {
    $patient = Patient::factory()->create([
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'ktp_number' => '3201010101010003',
        'name' => 'Pasien KTP Tetap',
    ]);

    $this->actingAs(userWith(['manage patients']))
        ->put(route('settings.patients.update', $patient), consentRegistrationPayload([
            'ktp_number' => '3201010101010003',
            'name' => 'Pasien KTP Tetap Diperbarui',
            'manual_rm_number' => null,
            'branch_id' => null,
        ]))
        ->assertRedirect(route('settings.patients.index'));

    expect($patient->refresh()->ktp_number)->toBe('3201010101010003')
        ->and($patient->name)->toBe('Pasien KTP Tetap Diperbarui');
});

it('shows nomor wa on rme visit detail', function () {
    $patient = Patient::factory()->create([
        'phone' => '081234567890',
        'whatsapp_number' => '081298765432',
    ]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Nomor WA')
        ->assertSee('081298765432')
        ->assertDontSee('3201010101010001');
});

it('does not show ktp on rme visit detail', function () {
    $patient = Patient::factory()->create(['ktp_number' => '3201010101010004']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee('3201010101010004')
        ->assertDontSee('Nomor KTP');
});

it('does not show ktp on rme visit print', function () {
    $patient = Patient::factory()->create(['ktp_number' => '3201010101010005']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Nomor WA')
        ->assertDontSee('3201010101010005')
        ->assertDontSee('Nomor KTP');
});

it('fails payment when patient consent is missing', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        consentPaymentPayload($invoice, ['consent_signed_by_patient' => false]),
    ))->toThrow(ValidationException::class, 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.');

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('fails payment when doctor consent is missing', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        consentPaymentPayload($invoice, ['consent_signed_by_doctor' => false]),
    ))->toThrow(ValidationException::class, 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.');

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('succeeds payment when both consents are true', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        consentPaymentPayload($invoice),
    );

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($visit->fresh()->consent_signed_by_patient)->toBeTrue()
        ->and($visit->fresh()->consent_signed_by_doctor)->toBeTrue()
        ->and($visit->fresh()->consent_verified_by)->toBe($this->cashier->id);
});

it('rejects http payment without consent checkboxes', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors(['consent_signed_by_patient', 'consent_signed_by_doctor']);

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('shows ktp duplicate validation message exactly', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010006']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), consentRegistrationPayload([
            'ktp_number' => '3201010101010006',
            'manual_rm_number' => '0103',
        ]))
        ->assertSessionHasErrors([
            'ktp_number' => 'Nomor KTP sudah terdaftar pada pasien lain.',
        ]);
});

it('shows new patient format fields on rme visit create form', function () {
    $this->actingAs($this->visitAdmin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Nomor KTP')
        ->assertSee('Nomor WA')
        ->assertSee('E-Mail')
        ->assertSee('Pekerjaan')
        ->assertSee('Alamat Lengkap')
        ->assertSee('Digunakan untuk validasi identitas pasien. Tidak ditampilkan di RME/cetak.');
});

it('persists new patient format fields when registering visit in new patient mode', function () {
    $this->actingAs($this->visitAdmin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'branch_id' => $this->branch->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Format Kunjungan',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-14',
                'manual_rm_number' => '0201',
                'ktp_number' => '3201010101010201',
                'whatsapp_number' => '081298760201',
                'email' => 'pasien.format@example.test',
                'occupation' => 'Guru',
                'address' => 'Jl. Contoh No. 20, Jakarta',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Format Kunjungan');

    expect($patient)->not->toBeNull()
        ->and($patient->ktp_number)->toBe('3201010101010201')
        ->and($patient->whatsapp_number)->toBe('081298760201')
        ->and($patient->email)->toBe('pasien.format@example.test')
        ->and($patient->occupation)->toBe('Guru')
        ->and($patient->address)->toBe('Jl. Contoh No. 20, Jakarta')
        ->and(ClinicVisit::where('patient_id', $patient->id)->exists())->toBeTrue();
});

it('rejects duplicate ktp through rme visit new patient flow with exact message', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010202']);

    $this->actingAs($this->visitAdmin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'branch_id' => $this->branch->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Duplikat KTP',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-14',
                'manual_rm_number' => '0202',
                'ktp_number' => '3201010101010202',
            ],
        ])
        ->assertSessionHasErrors([
            'new_patient.ktp_number' => 'Nomor KTP sudah terdaftar pada pasien lain.',
        ]);

    expect(Patient::where('name', 'Pasien Duplikat KTP')->exists())->toBeFalse();
});

it('computes patient age from date of birth on rme detail', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-14'));

    $patient = Patient::factory()->create([
        'date_of_birth' => '2000-06-14',
    ]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('26 tahun');

    Carbon::setTestNow();
});
