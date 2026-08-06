<?php

/**
 * LEGACY-RME-PDF-1A — module foundation: models, status domain, repositories,
 * container bindings, audit payload policy and the default-off feature flag.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Repositories\LegacyRmeImportRepository;
use App\Modules\LegacyRme\Repositories\LegacyRmeRecordRepository;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\Patient\Models\Patient;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

it('casts dates and relations on the staging import model', function () {
    $patient = Patient::factory()->create();
    $branch = Branch::factory()->create();

    $import = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $branch->id,
        'selected_rme_date' => '2015-04-01',
        'earliest_native_rme_date_snapshot' => '2020-04-01',
    ]);

    LegacyRmeImportPage::factory()->count(2)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
    )->create(['legacy_import_id' => $import->id]);

    $import->refresh();

    expect($import->selected_rme_date->toDateString())->toBe('2015-04-01')
        ->and($import->earliest_native_rme_date_snapshot->toDateString())->toBe('2020-04-01')
        ->and($import->patient->is($patient))->toBeTrue()
        ->and($import->originBranch->is($branch))->toBeTrue()
        ->and($import->pages)->toHaveCount(2)
        ->and($import->uuid)->not->toBeEmpty();
});

it('generates a uuid for staging imports and published records automatically', function () {
    $import = new LegacyRmeImport([
        'patient_id' => Patient::factory()->create()->id,
        'selected_rme_date' => '2015-01-01',
        'status' => LegacyRmeImport::STATUS_DRAFT,
    ]);
    $import->save();

    $record = new LegacyRmeRecord([
        'patient_id' => Patient::factory()->create()->id,
        'rme_date' => '2015-01-01',
        'source_disk' => 'local',
        'source_pdf_path' => 'rme-legacy/x/source.pdf',
        'source_pdf_sha256' => hash('sha256', 'x'),
        'page_count' => 1,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
    ]);
    $record->save();

    expect($import->refresh()->uuid)->not->toBeEmpty()
        ->and($record->refresh()->uuid)->not->toBeEmpty();
});

it('soft-deletes a staging import but keeps published records undeletable', function () {
    $import = LegacyRmeImport::factory()->create();
    $import->delete();

    expect(LegacyRmeImport::withTrashed()->find($import->id))->not->toBeNull()
        ->and(LegacyRmeImport::find($import->id))->toBeNull();

    // The published record model deliberately has no SoftDeletes trait.
    expect(in_array(
        SoftDeletes::class,
        class_uses_recursive(LegacyRmeRecord::class),
        true
    ))->toBeFalse()
        ->and(in_array(
            SoftDeletes::class,
            class_uses_recursive(LegacyRmeRecordPage::class),
            true
        ))->toBeFalse();
});

it('exposes a closed staging status vocabulary with explicit transitions', function () {
    expect(LegacyRmeImportStatus::ALL)->toBe([
        'DRAFT', 'UPLOADED', 'QUEUED', 'PROCESSING',
        'READY_FOR_REVIEW', 'REVIEWED', 'PUBLISHED', 'FAILED', 'CANCELLED',
    ])
        ->and(LegacyRmeImportStatus::isValid('DRAFT'))->toBeTrue()
        ->and(LegacyRmeImportStatus::isValid('draft'))->toBeFalse()
        ->and(LegacyRmeImportStatus::isValid('WHATEVER'))->toBeFalse();

    expect(LegacyRmeImportStatus::canTransition('REVIEWED', 'PUBLISHED'))->toBeTrue()
        ->and(LegacyRmeImportStatus::canTransition('DRAFT', 'PUBLISHED'))->toBeFalse()
        ->and(LegacyRmeImportStatus::canTransition('PUBLISHED', 'REVIEWED'))->toBeFalse()
        ->and(LegacyRmeImportStatus::isTerminal('PUBLISHED'))->toBeTrue()
        ->and(LegacyRmeImportStatus::isTerminal('CANCELLED'))->toBeTrue()
        ->and(LegacyRmeImportStatus::isTerminal('FAILED'))->toBeFalse();
});

it('only allows a published legacy record to move to VOID', function () {
    expect(LegacyRmeRecordStatus::ALL)->toBe(['PUBLISHED', 'VOID'])
        ->and(LegacyRmeRecordStatus::canTransition('PUBLISHED', 'VOID'))->toBeTrue()
        ->and(LegacyRmeRecordStatus::canTransition('VOID', 'PUBLISHED'))->toBeFalse();

    $record = LegacyRmeRecord::factory()->create();

    expect($record->isPublished())->toBeTrue()
        ->and($record->canTransitionTo(LegacyRmeRecord::STATUS_VOID))->toBeTrue();
});

it('treats an unrendered staging page as not publishable', function () {
    $pending = LegacyRmeImportPage::factory()->create();
    $ready = LegacyRmeImportPage::factory()->ready()->create(['page_number' => 2]);

    expect($pending->isPublishable())->toBeFalse()
        ->and($ready->isPublishable())->toBeTrue()
        ->and(LegacyRmeImportPageStatus::isPublishable('PROCESSING'))->toBeFalse()
        ->and(LegacyRmeImportPageStatus::isPublishable('FAILED'))->toBeFalse();
});

it('links a published record back to its staging import and its pages', function () {
    $import = LegacyRmeImport::factory()->published()->create();

    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $import->patient_id,
        'source_import_id' => $import->id,
    ]);

    LegacyRmeRecordPage::factory()->count(2)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
    )->create(['rme_legacy_record_id' => $record->id]);

    expect($record->refresh()->sourceImport->is($import))->toBeTrue()
        ->and($import->refresh()->record->is($record))->toBeTrue()
        ->and($record->pages)->toHaveCount(2);
});

it('binds both legacy RME repositories to their interfaces', function () {
    expect(app(LegacyRmeImportRepositoryInterface::class))->toBeInstanceOf(LegacyRmeImportRepository::class)
        ->and(app(LegacyRmeRecordRepositoryInterface::class))->toBeInstanceOf(LegacyRmeRecordRepository::class);
});

it('fails closed when a repository is queried with an empty branch scope', function () {
    $patient = Patient::factory()->create();
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    LegacyRmeImport::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);

    $imports = app(LegacyRmeImportRepositoryInterface::class);
    $records = app(LegacyRmeRecordRepositoryInterface::class);

    expect($imports->listForPatientInBranches([], $patient->id))->toHaveCount(0)
        ->and($records->listPublishedForPatientInBranches([], $patient->id))->toHaveCount(0)
        ->and($imports->listForPatientInBranches([$branch->id], $patient->id))->toHaveCount(1)
        ->and($records->listPublishedForPatientInBranches([$branch->id], $patient->id))->toHaveCount(1);
});

it('excludes voided records from the published archive listing', function () {
    $patient = Patient::factory()->create();
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);
    LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);

    expect(app(LegacyRmeRecordRepositoryInterface::class)
        ->listPublishedForPatientInBranches([$branch->id], $patient->id))
        ->toHaveCount(1);
});

it('orders the patient archive chronologically', function () {
    $patient = Patient::factory()->create();
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    foreach (['2018-01-01', '2012-06-06', '2015-03-03'] as $date) {
        LegacyRmeRecord::factory()->create([
            'patient_id' => $patient->id,
            'origin_branch_id' => $branch->id,
            'rme_date' => $date,
        ]);
    }

    $dates = app(LegacyRmeRecordRepositoryInterface::class)
        ->listPublishedForPatientInBranches([$branch->id], $patient->id)
        ->map(fn ($record) => $record->rme_date->toDateString())
        ->all();

    expect($dates)->toBe(['2012-06-06', '2015-03-03', '2018-01-01']);
});

it('keeps the legacy RME feature flag off by default', function () {
    $guard = app(LegacyRmeFeatureGuard::class);
    $flag = app(FeatureFlagService::class)->metadata('rme.legacy_pdf_archive');

    expect($guard->flagKey())->toBe('rme.legacy_pdf_archive')
        ->and($guard->enabled())->toBeFalse()
        ->and($flag['default'])->toBeFalse()
        ->and($flag['risk_level'])->toBe('high');

    expect(fn () => $guard->assertEnabled())
        ->toThrow(ValidationException::class);
});

it('strips anything outside the audit allow-list from an audit payload', function () {
    $audit = app(LegacyRmeAuditService::class);

    $safe = $audit->safePayload([
        'patient_id' => 12,
        'selected_rme_date' => '2015-01-01',
        'ktp_number' => '7371'.str_repeat('9', 12),
        'patient_name' => 'Nama Pasien',
        'notes' => 'isi klinis',
        'source_pdf_path' => '/var/www/storage/app/private/rme-legacy/x.pdf',
        'pages' => ['a', 'b'],
    ]);

    expect($safe)->toHaveKeys(['patient_id', 'selected_rme_date'])
        ->and($safe)->not->toHaveKeys(['ktp_number', 'patient_name', 'notes', 'source_pdf_path', 'pages']);
});

it('writes a PII-free audit entry into the shared audit trail', function () {
    $import = LegacyRmeImport::factory()->create([
        'selected_rme_date' => '2015-04-01',
        'earliest_native_rme_date_snapshot' => '2020-04-01',
    ]);

    app(LegacyRmeAuditService::class)->logImportEvent(
        LegacyRmeAuditEvent::IMPORT_CREATED,
        $import,
        ['rule_code' => 'OK', 'patient_name' => 'Nama Pasien'],
    );

    $log = AuditLog::query()
        ->where('entity_type', LegacyRmeAuditEvent::ENTITY_IMPORT)
        ->where('entity_id', $import->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(LegacyRmeAuditEvent::IMPORT_CREATED)
        ->and($log->new_values)->toHaveKey('patient_id')
        ->and($log->new_values)->toHaveKey('selected_rme_date')
        ->and($log->new_values)->not->toHaveKey('patient_name')
        ->and(json_encode($log->new_values))->not->toContain('Nama Pasien');
});
