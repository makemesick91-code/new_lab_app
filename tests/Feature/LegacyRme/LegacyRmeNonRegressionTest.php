<?php

/**
 * LEGACY-RME-PDF-1A — non-regression contract.
 *
 * A legacy RME record is an ARCHIVE. Creating one must never touch the live
 * RME workflow: no clinic visit, no invoice, no payment, no consent, no
 * odontogram, no lab candidate/order, no SATUSEHAT candidate, and no effect on
 * visit or revenue KPI.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;

it('creates no visit, invoice, payment, lab or SATUSEHAT row when a legacy record is archived', function () {
    $patient = Patient::factory()->create();

    $baseline = [
        'visits' => ClinicVisit::count(),
        'medical_records' => MedicalRecord::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_candidates' => LabCaseCandidate::count(),
        'lab_orders' => LabOrder::count(),
        'satusehat_candidates' => SatusehatCandidate::count(),
    ];

    $import = LegacyRmeImport::factory()->published()->create(['patient_id' => $patient->id]);

    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'source_import_id' => $import->id,
    ]);

    LegacyRmeRecordPage::factory()->create(['rme_legacy_record_id' => $record->id]);

    expect(ClinicVisit::count())->toBe($baseline['visits'])
        ->and(MedicalRecord::count())->toBe($baseline['medical_records'])
        ->and(RmeInvoice::count())->toBe($baseline['invoices'])
        ->and(RmePayment::count())->toBe($baseline['payments'])
        ->and(LabCaseCandidate::count())->toBe($baseline['lab_candidates'])
        ->and(LabOrder::count())->toBe($baseline['lab_orders'])
        ->and(SatusehatCandidate::count())->toBe($baseline['satusehat_candidates']);
});

it('does not change an existing visit status when a legacy record is archived', function () {
    $patient = Patient::factory()->create();

    $visit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id]);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the legacy archive out of the native RME sheet history', function () {
    $patient = Patient::factory()->create();

    $visit = ClinicVisit::factory()->create(['patient_id' => $patient->id, 'visit_date' => '2023-01-01']);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    LegacyRmeRecord::factory()->count(3)->create(['patient_id' => $patient->id]);

    // The patient still has exactly one NATIVE medical record; legacy documents
    // live in their own table and never inflate the native sheet count.
    expect(MedicalRecord::where('patient_id', $patient->id)->count())->toBe(1);
});

it('registers no HTTP route for the legacy RME archive in this sprint', function () {
    // Sprint 1A is a foundation: schema, permissions, policies and the date
    // rules only. Upload, review, publish and the viewer ship separately, so no
    // endpoint may be exposed here.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => (string) $route->getName().'|'.$route->uri())
        ->filter(fn (string $descriptor) => str_contains($descriptor, 'legacy-rme')
            || str_contains($descriptor, 'legacy_rme')
            || str_contains($descriptor, 'rme-legacy'));

    expect($routes->all())->toBe([]);
});
