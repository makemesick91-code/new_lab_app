<?php

declare(strict_types=1);

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-3 — multi-branch wave behaviour.
 *
 * Covers the three things a controlled wave has to prove beyond ROLL-2's single
 * branch: that several admitted branches can migrate side by side without
 * bleeding into one another, that DRAIN and EMERGENCY STOP are genuinely
 * different controls, and that none of it creates native clinical records.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

/**
 * A fully rendered import sitting at READY_FOR_REVIEW for a given branch —
 * exactly the state a wave rollback has to leave publishable.
 */
function roll3ReadyImport(
    string $branchCode,
    string $rm,
    string $legacyDate = '2019-05-01',
    ?array $admittedOverride = null,
): LegacyRmeImport {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = legacyRmeArchivablePatient(
        ['medical_record_number' => $rm, 'date_of_birth' => '1990-01-01'],
        $branchCode,
    );

    // ORDERING MATTERS. legacyRmeBranch() admits the branch it creates, so a
    // fixture built after the allowlist was narrowed would silently re-admit
    // itself and the denial under test would never happen. Callers that need a
    // specific wave state re-assert it here, once the fixture exists.
    if ($admittedOverride !== null) {
        legacyRmeAdmittedBranches($admittedOverride);
    }

    // Each patient gets structurally distinct bytes. Identical content across
    // different patients is refused by the 1B cross-patient duplicate guard —
    // correctly so — and that guard is not what this suite is testing.
    $document = UploadedFile::fake()->createWithContent(
        'arsip.pdf',
        legacyRmePdfBytes(1, 595.276 + (float) crc32($rm) % 40, 841.89),
    );

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $legacyDate,
        $patient->medical_record_number,
        null,
        $document,
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

// ---------------------------------------------------------------------------
// Several admitted branches migrate side by side
// ---------------------------------------------------------------------------

it('lets two admitted branches migrate independently and files each under its own branch', function () {
    legacyRmeBranch('TLK1', 'Cabang Telkomas');
    legacyRmeBranch('LDK2', 'Cabang Landak');
    legacyRmeAdmittedBranches(['TLK1', 'LDK2']);

    $first = roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');
    $second = roll3ReadyImport('LDK2', 'DG-LDK2-2024-9002');

    expect($first->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($second->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        // Each archive is owned by the branch in its own patient's Nomor RM,
        // never by the uploading operator's branch.
        ->and($first->origin_branch_id)->not->toBe($second->origin_branch_id);
});

it('admits one branch of a pair without admitting the other', function () {
    legacyRmeBranch('TLK1', 'Cabang Telkomas');
    legacyRmeBranch('LDK2', 'Cabang Landak');
    legacyRmeAdmittedBranches(['TLK1']);

    roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');

    expect(fn () => roll3ReadyImport('LDK2', 'DG-LDK2-2024-9002', '2019-05-01', ['TLK1']))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeImport::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// NORMAL DRAIN — a removed branch stops new intake but finishes existing work
// ---------------------------------------------------------------------------

it('still publishes an already-reviewed import after its branch is drained from the wave', function () {
    legacyRmeAdmittedBranches(['TLK1']);

    $import = roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');

    app(LegacyRmePublishService::class)->review($import, superAdmin());

    // NORMAL DRAIN: the wave rolls back while this document is mid-lifecycle.
    legacyRmeAdmittedBranches([]);

    $record = app(LegacyRmePublishService::class)->publish(
        $import->refresh(),
        [],
        superAdmin(),
    );

    expect($record)->toBeInstanceOf(LegacyRmeRecord::class)
        ->and($record->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

it('preserves every staged artefact when a branch is drained', function () {
    legacyRmeAdmittedBranches(['TLK1']);

    $import = roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');
    $filesBefore = Storage::disk('legacy_rme_private')->allFiles();
    $pagesBefore = $import->pages()->count();

    legacyRmeAdmittedBranches([]);

    expect(LegacyRmeImport::query()->whereKey($import->getKey())->exists())->toBeTrue()
        ->and($import->refresh()->pages()->count())->toBe($pagesBefore)
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe($filesBefore);
});

// ---------------------------------------------------------------------------
// EMERGENCY STOP — the capability switch withdraws mutations, including publish
// ---------------------------------------------------------------------------

it('refuses publish over HTTP when the capability is switched off, unlike a drain', function () {
    legacyRmeAdmittedBranches(['TLK1']);

    $import = roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');
    app(LegacyRmePublishService::class)->review($import, superAdmin());

    // EMERGENCY STOP.
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()), [
            'confirm_reviewed' => '1',
        ])
        ->assertNotFound();

    expect(LegacyRmeRecord::query()->count())->toBe(0);
});

it('refuses the operator upload surface entirely when the capability is off', function () {
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.create'))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// A migration never becomes a native clinical record
// ---------------------------------------------------------------------------

it('creates no native clinical or billing artefact across a multi-branch wave', function () {
    legacyRmeBranch('TLK1', 'Cabang Telkomas');
    legacyRmeBranch('LDK2', 'Cabang Landak');
    legacyRmeAdmittedBranches(['TLK1', 'LDK2']);

    $first = roll3ReadyImport('TLK1', 'DG-TLK1-2024-9001');
    $second = roll3ReadyImport('LDK2', 'DG-LDK2-2024-9002');

    foreach ([$first, $second] as $import) {
        app(LegacyRmePublishService::class)->review($import, superAdmin());
        app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin());
    }

    expect(LegacyRmeRecord::query()->count())->toBe(2)
        // The archive is history, not an encounter: it must never manufacture
        // a visit, a native medical record or anything billable.
        ->and(ClinicVisit::query()->count())->toBe(0)
        ->and(MedicalRecord::query()->count())->toBe(0)
        ->and(RmeInvoice::query()->count())->toBe(0);
});
