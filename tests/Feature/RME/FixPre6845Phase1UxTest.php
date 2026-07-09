<?php

/**
 * FIX-PRE-68-45 — Phase 1 (Scopes A, D, B) UX polish.
 *
 * Scope A — RME Rekam Medis page: "Kembali ke Kunjungan" button at the top
 *           (Odontogram-style), "Buku RM Pasien" + "Riwayat Pencatatan" relocated
 *           below the handwriting canvas.
 * Scope D — Resep page: back button at the top + handling-doctor name under the
 *           signature canvas.
 * Scope B — Owner dashboard: permission-gated module/report shortcuts + activated
 *           visit/payment trend charts.
 *
 * These tests assert presentation + permission-safety only; no RME workflow,
 * finalization, room-gate, payment, or branch-isolation rule is changed.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function fix6845Branch(): Branch
{
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $branch->update(['is_rme_enabled' => true]);

    return $branch;
}

function fix6845Visit(array $overrides = []): ClinicVisit
{
    $branch = fix6845Branch();
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);
    $doctor = Doctor::factory()->create(['name' => 'drg. Sinta Melati']);
    rmeMakeDoctorOnline($doctor, $branch);

    // ClinicVisitFactory defaults an assigned clinic_room_id (Sprint 60.8), so the
    // room gate passes and the pre-exam RM/Resep pages render.
    return ClinicVisit::factory()->inProgress()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ], $overrides));
}

// ─── Scope A — Rekam Medis ────────────────────────────────────────────────────

it('Scope A: Rekam Medis shows a Kembali ke Kunjungan button at the top and relocates the nav/history below the handwriting canvas', function () {
    $visit = fix6845Visit();
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $manager = userWith(['view_clinic_visits']);

    $response = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    // Real back button, and the old plain text link is gone.
    $response->assertSee('Kembali ke Kunjungan');
    $response->assertDontSee('Kembali ke detail kunjungan');

    // Back button renders before the handwriting card (top of page).
    $response->assertSeeInOrder(['Kembali ke Kunjungan', 'RME Tulisan Tangan']);

    // Buku RM + Riwayat Pencatatan now render AFTER the handwriting card.
    $response->assertSee('Buku RM Pasien');
    $response->assertSee('Riwayat Pencatatan');
    $response->assertSeeInOrder(['RME Tulisan Tangan', 'Buku RM Pasien']);
    $response->assertSeeInOrder(['RME Tulisan Tangan', 'Riwayat Pencatatan']);
});

// ─── Scope D — Resep ──────────────────────────────────────────────────────────

it('Scope D: Resep shows a Kembali ke Kunjungan button at the top and the handling doctor name under the signature canvas', function () {
    $visit = fix6845Visit();
    $manager = userWith(['manage_clinic_visits']);

    $response = $this->actingAs($manager)
        ->get(route('rme.visits.prescription.show', [$visit, 'edit' => 1]))
        ->assertOk();

    $response->assertSee('Kembali ke Kunjungan');
    $response->assertDontSee('Kembali ke detail kunjungan');

    // Handling doctor (ClinicVisit->doctor) rendered under the signature canvas.
    $response->assertSee('drg. Sinta Melati');
    $response->assertSee('Dokter yang menangani kunjungan ini');
    $response->assertSeeInOrder(['Tanda Tangan Dokter', 'Dokter yang menangani kunjungan ini']);
});

it('Scope D: Resep view mode shows the handling doctor even without an edit session', function () {
    $visit = fix6845Visit();
    $viewer = userWith(['view_clinic_visits']);

    $this->actingAs($viewer)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()
        ->assertSee('Kembali ke Kunjungan');
});

// ─── Scope B — Owner dashboard module shortcuts (permission-safe) ─────────────

it('Scope B: module shortcuts are permission-gated per user', function () {
    $service = app(OwnerDashboardKpiService::class);

    $full = userWith(['view_rme_patient_reports', 'view_rme_payment_reports', 'manage_rme_billing', 'view_inventory']);
    $fullKeys = collect($service->moduleShortcuts($full))->pluck('key')->all();
    expect($fullKeys)->toContain('owner_summary', 'rme_patients', 'rme_payments', 'receivables', 'inventory');

    // A user with no report permissions only gets the always-available owner summary.
    $limited = userWith(['view_clinic_visits']);
    $limitedKeys = collect($service->moduleShortcuts($limited))->pluck('key')->all();
    expect($limitedKeys)->toContain('owner_summary');
    expect($limitedKeys)->not->toContain('rme_patients');
    expect($limitedKeys)->not->toContain('rme_payments');
    expect($limitedKeys)->not->toContain('receivables');
    expect($limitedKeys)->not->toContain('inventory');
});

it('Scope B: owner dashboard renders module shortcuts and trend charts without error', function () {
    $owner = userWith(['view dashboard', 'view_owner_dashboard', 'view_rme_patient_reports', 'view_rme_payment_reports']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Pintasan Laporan Modul')
        ->assertSee('Laporan Pasien RME')
        ->assertSee('Laporan Pembayaran RME')
        ->assertSee('Tren Kunjungan')
        ->assertSee('Tren Pembayaran');
});
