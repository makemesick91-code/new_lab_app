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
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

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

it('exposes exactly the staging surface plus review and publish, and nothing else', function () {
    // 1A asserted that NO endpoint existed at all. 1B replaced that with the
    // staging surface. 1C adds review and publish — and nothing more: `approve`
    // and `void` still have no endpoint (void is its own later capability), and
    // no route can mutate a document any other way.
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'settings.rme.legacy-imports.'))
        ->values();

    expect($names->all())->toEqualCanonicalizing([
        'settings.rme.legacy-imports.index',
        'settings.rme.legacy-imports.create',
        'settings.rme.legacy-imports.store',
        'settings.rme.legacy-imports.show',
        'settings.rme.legacy-imports.status',
        'settings.rme.legacy-imports.source',
        'settings.rme.legacy-imports.pages.show',
        'settings.rme.legacy-imports.retry',
        'settings.rme.legacy-imports.cancel',
        'settings.rme.legacy-imports.review',
        'settings.rme.legacy-imports.publish',
    ]);

    foreach (['approve', 'void'] as $forbidden) {
        expect($names->contains('settings.rme.legacy-imports.'.$forbidden))->toBeFalse();
    }
});

it('exposes a read-only published archive surface with no write route', function () {
    // LEGACY-RME-PDF-1C. A published legacy record is immutable clinical
    // evidence: the viewer may only ever be read.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'rme.legacy-records.'));

    expect($routes->pluck('action.as')->values()->all())->toEqualCanonicalizing([
        'rme.legacy-records.show',
        'rme.legacy-records.source',
        'rme.legacy-records.pages.show',
    ]);

    foreach ($routes as $route) {
        expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([]);
    }
});

it('creates no visit, invoice, payment, lab or SATUSEHAT row when a document is uploaded and rendered', function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();

    app()->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->withPages(2),
    );
    app()->instance(
        LegacyRmePdfRasterizerInterface::class,
        (new FakeLegacyRmePdfRasterizer)->withPages(2),
    );

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    $visit = legacyRmeNativeVisit($patient, '2022-03-10');

    $baseline = [
        'visits' => ClinicVisit::count(),
        'medical_records' => MedicalRecord::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_candidates' => LabCaseCandidate::count(),
        'lab_orders' => LabOrder::count(),
        'satusehat_candidates' => SatusehatCandidate::count(),
    ];
    $visitStatus = $visit->status;

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload('arsip.pdf', 2),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect(ClinicVisit::count())->toBe($baseline['visits'])
        ->and(MedicalRecord::count())->toBe($baseline['medical_records'])
        ->and(RmeInvoice::count())->toBe($baseline['invoices'])
        ->and(RmePayment::count())->toBe($baseline['payments'])
        ->and(LabCaseCandidate::count())->toBe($baseline['lab_candidates'])
        ->and(LabOrder::count())->toBe($baseline['lab_orders'])
        ->and(SatusehatCandidate::count())->toBe($baseline['satusehat_candidates'])
        ->and($visit->refresh()->status)->toBe($visitStatus)
        // The archive lands in its own staging tables and nowhere else.
        ->and(LegacyRmeImport::count())->toBe(1)
        ->and(LegacyRmeRecord::count())->toBe(0);
});
