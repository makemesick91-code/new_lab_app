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

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = Branch::factory()->create(['code' => 'FMT1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch);
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

/*
 * AMENDED by FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01.
 *
 * These four tests described the OLD gate: two booleans carried by the payment
 * request, which the payment service wrote onto the visit and then asserted
 * against. Sending `false` proved only that a request could decline consent on
 * its own behalf — the same mechanism that let a request GRANT itself consent.
 *
 * The gate now depends on a signed PERSETUJUAN TINDAKAN MEDIS, so the tests are
 * rewritten around the evidence rather than around the booleans.
 */

it('settles payment even when no consent document has been signed', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    // SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03 — consent is
    // taken at the start of the examination and is not a payment condition.
    app(RmePaymentService::class)->pay($invoice, $this->cashier, consentPaymentPayload($invoice));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('settles payment and ignores any consent claim the request carries', function () {
    // The exact payload that settled an invoice before this sprint.
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        consentPaymentPayload($invoice, [
            'consent_signed_by_patient' => true,
            'consent_signed_by_doctor' => true,
        ]),
    );

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        // PRESERVED, and still the point: the request must never be able to write
        // the attestation columns. It could not gate a payment before, and it
        // cannot author consent evidence now.
        ->and($visit->fresh()->consent_signed_by_patient)->toBeFalse();
});

it('succeeds payment once a consent document exists for the visit', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);
    rmeSignedConsentFor($visit);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        consentPaymentPayload($invoice),
    );

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($visit->fresh()->hasSignedConsentDocument())->toBeTrue();
});

it('accepts an http payment without the consent checkboxes when a consent is signed', function () {
    // The checkboxes are now an acknowledgement, not the decision, so their
    // absence must not block a payment that is genuinely consented.
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);
    rmeSignedConsentFor($visit);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('accepts an http payment when no consent has been signed', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = consentUnpaidInvoice($this->branch, $this->cashier);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => 1,
        'consent_signed_by_doctor' => 1,
    ])->assertSessionHasNoErrors();

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
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
