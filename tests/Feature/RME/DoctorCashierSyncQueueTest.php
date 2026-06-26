<?php

/**
 * Hotfix Sprint 60.7 — Doctor → Cashier Sync Queue & Pending Input Visibility.
 *
 * The cashier sync queue (rme.cashier.handoff) gives the front office/cashier
 * read-only visibility into where each active RME visit sits in the doctor →
 * cashier handoff: waiting for doctor input, waiting RME/treatment finalization,
 * consent incomplete, ready to pay, or in payment. It changes no payment, consent,
 * RME, or branch-isolation rules — it only classifies existing data.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\CashierHandoffStatusService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG7', 'name' => 'Cabang Antang', 'is_rme_enabled' => true]);
    $this->other = Branch::factory()->create(['code' => 'TKM7', 'name' => 'Cabang Telkomas', 'is_rme_enabled' => true]);

    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
    $this->cashier = userWith(['manage_rme_billing']);
    $this->unauthorized = userWith(['view_clinic_visits']);
});

/** Create a visit with a named patient at a branch. */
function handoffVisit(Branch $branch, string $patientName, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'name' => $patientName]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ], $overrides));
}

// ─── Access control ─────────────────────────────────────────────────────────

it('lets a cashier with manage_rme_billing open the sync queue', function () {
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertViewIs('rme.cashier.handoff')
        ->assertSee('Sinkronisasi Dokter–Kasir');
});

it('forbids users without manage_rme_billing', function () {
    $this->actingAs($this->unauthorized)
        ->get(route('rme.cashier.handoff'))
        ->assertForbidden();
});

// ─── Status classification (service) ────────────────────────────────────────

it('classifies a registered visit as waiting for doctor input', function () {
    $visit = handoffVisit($this->branch, 'Pasien Daftar', ['status' => ClinicVisit::STATUS_REGISTERED]);

    expect(app(CashierHandoffStatusService::class)->determineKey($visit))
        ->toBe(CashierHandoffStatusService::WAITING_DOCTOR);
});

it('classifies an in-progress visit as pending RME finalization', function () {
    $visit = handoffVisit($this->branch, 'Pasien Periksa', ['status' => ClinicVisit::STATUS_IN_PROGRESS]);

    expect(app(CashierHandoffStatusService::class)->determineKey($visit))
        ->toBe(CashierHandoffStatusService::PENDING_RME);
});

it('classifies a cashier_pending visit without verified consent as consent pending', function () {
    $visit = handoffVisit($this->branch, 'Pasien Consent', [
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
        'consent_signed_by_patient' => false,
        'consent_signed_by_doctor' => false,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    expect(app(CashierHandoffStatusService::class)->determineKey($visit->fresh()))
        ->toBe(CashierHandoffStatusService::CONSENT_PENDING);
});

it('classifies a cashier_pending visit with final RME and verified consent as ready to pay', function () {
    $visit = handoffVisit($this->branch, 'Pasien Siap', [
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    expect(app(CashierHandoffStatusService::class)->determineKey($visit->fresh()))
        ->toBe(CashierHandoffStatusService::READY_TO_PAY);
});

it('classifies a partially-paid invoice as in payment', function () {
    $visit = handoffVisit($this->branch, 'Pasien Partial', [
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);
    RmeInvoice::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'status' => RmeInvoice::STATUS_PARTIAL,
    ]);

    expect(app(CashierHandoffStatusService::class)->determineKey($visit->fresh()))
        ->toBe(CashierHandoffStatusService::IN_PAYMENT);
});

// ─── Visibility on the page ─────────────────────────────────────────────────

it('shows visits waiting for doctor input in the queue', function () {
    handoffVisit($this->branch, 'Pasien Tunggu Dokter', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Pasien Tunggu Dokter')
        ->assertSee('Menunggu Input Dokter');
});

it('shows a consent-pending visit as not ready to pay', function () {
    $visit = handoffVisit($this->branch, 'Pasien Belum Consent', [
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
        'consent_signed_by_patient' => false,
        'consent_signed_by_doctor' => false,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Pasien Belum Consent')
        ->assertSee('Consent Belum Lengkap');
});

it('shows a ready-to-pay visit with a billing action', function () {
    $visit = handoffVisit($this->branch, 'Pasien Siap Bayar', [
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Pasien Siap Bayar')
        ->assertSee('Siap Bayar')
        ->assertSee(route('rme.cashier.create', $visit), escape: false);
});

it('excludes completed and cancelled visits from the queue', function () {
    handoffVisit($this->branch, 'Pasien Selesai', ['status' => ClinicVisit::STATUS_COMPLETED]);
    handoffVisit($this->branch, 'Pasien Batal', ['status' => ClinicVisit::STATUS_CANCELLED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertDontSee('Pasien Selesai')
        ->assertDontSee('Pasien Batal');
});

// ─── Branch isolation ───────────────────────────────────────────────────────

it('respects RME branch scope and excludes non-RME (MAIN) branch visits', function () {
    handoffVisit($this->branch, 'Pasien RME Antang', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    handoffVisit($main, 'Pasien Non RME', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Pasien RME Antang')
        ->assertDontSee('Pasien Non RME');
});

it('narrows the queue to a single RME branch via the branch filter', function () {
    handoffVisit($this->branch, 'Pasien Antang', ['status' => ClinicVisit::STATUS_REGISTERED]);
    handoffVisit($this->other, 'Pasien Telkomas', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff', ['branch_id' => $this->branch->id]))
        ->assertOk()
        ->assertSee('Pasien Antang')
        ->assertDontSee('Pasien Telkomas');
});

// ─── KTP must never be exposed ──────────────────────────────────────────────

it('does not display the patient KTP number on the queue', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Pasien Rahasia',
        'ktp_number' => '7371090101990001',
    ]);
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Pasien Rahasia')
        ->assertDontSee('7371090101990001');
});

// ─── Group filter ───────────────────────────────────────────────────────────

it('filters the listing to a single handoff group', function () {
    handoffVisit($this->branch, 'Pasien Daftar Saja', ['status' => ClinicVisit::STATUS_REGISTERED]);
    handoffVisit($this->branch, 'Pasien Sedang Periksa', ['status' => ClinicVisit::STATUS_IN_PROGRESS]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff', ['group' => CashierHandoffStatusService::WAITING_DOCTOR]))
        ->assertOk()
        ->assertSee('Pasien Daftar Saja')
        ->assertDontSee('Pasien Sedang Periksa');
});
