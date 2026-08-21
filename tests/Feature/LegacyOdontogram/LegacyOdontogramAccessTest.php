<?php

/**
 * FIX-04b — authorization, branch isolation, IDOR and private storage.
 *
 * Everything here is about the boundary rather than the workflow: who may see
 * WHICH archive, what a wrong id does, and whether the bytes can be reached any
 * other way than through a policy-gated route.
 */

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramVoidService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/**
 * A PUBLISHED record for a patient in the given branch, produced by driving the
 * real intake → process → review → publish pipeline.
 *
 * @return array{record: LegacyOdontogramRecord, patient: Patient}
 */
function lodoPublished(string $branchCode = 'TKM1'): array
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = lodoPatient([], $branchCode);
    lodoNativeOdontogram($patient, '2022-03-10');

    $actor = lodoOperator();
    $import = lodoStageImport($patient, '2019-06-01', $actor);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    $import->refresh();
    app(LegacyOdontogramPublishService::class)->review($import, $actor);

    return [
        'record' => app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor),
        'patient' => $patient,
    ];
}

it('does not widen legacy RME archive visibility', function () {
    // Rule 13: this module's permissions must NEVER appear in the legacy RME
    // governance tier, or holding an odontogram permission would silently open
    // every RME branch's RME archive.
    foreach (LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS as $permission) {
        expect(LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS)->not->toContain($permission);
    }

    expect(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS)
        ->not->toContain('view_legacy_odontogram_archive');
});

it('hides and denies a record from another branch (IDOR)', function () {
    $mine = lodoPublished('TKM1');
    $theirs = lodoPublished('LDK2');

    // Pinned to TKM1: holds only the read permission, which is NOT governance
    // tier, so the scope resolves to their own branch alone.
    $reader = userWith(['view_legacy_odontogram_archive']);
    $reader->forceFill(['branch_id' => lodoBranch('TKM1')->id])->save();
    $reader = $reader->refresh();

    // Hidden from the listing…
    $visible = app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($reader, (int) $theirs['patient']->id);

    expect($visible)->toHaveCount(0);

    // …and a direct id is a 404, never a 403 that would confirm it exists.
    $this->actingAs($reader)
        ->get(route('rme.legacy-odontograms.show', $theirs['record']->getKey()))
        ->assertNotFound();

    $this->actingAs($reader)
        ->get(route('rme.legacy-odontograms.show', $mine['record']->getKey()))
        ->assertOk();
});

it('never exposes a public URL for archive bytes', function () {
    $published = lodoPublished();
    $record = $published['record'];

    $disk = config('filesystems.disks.'.$record->source_disk);

    expect($disk['visibility'])->toBe('private')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk['serve'] ?? false)->toBeFalse();

    // The path is never rendered on the page either.
    $this->actingAs(lodoOperator())
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertOk()
        ->assertDontSee($record->source_pdf_path);
});

it('requires the policy to stream a page or the source PDF', function () {
    $published = lodoPublished();
    $record = $published['record'];

    $stranger = userWith(['view_clinic_visits']);

    $this->actingAs($stranger)
        ->get(route('rme.legacy-odontograms.source', $record->getKey()))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('rme.legacy-odontograms.pages.show', [$record->getKey(), 1]))
        ->assertForbidden();

    $this->actingAs(lodoOperator())
        ->get(route('rme.legacy-odontograms.source', $record->getKey()))
        ->assertOk();
});

it('stops streaming the bytes of a VOIDed record while keeping the row readable', function () {
    $published = lodoPublished();
    $record = $published['record'];
    $actor = lodoOperator();

    app(LegacyOdontogramVoidService::class)->void(
        $record,
        'Dokumen ternyata milik pasien lain, ditarik dan akan diunggah ulang.',
        $actor,
    );

    // A permission-holding operator is stopped by the POLICY (403): viewFile
    // requires the record to still be PUBLISHED.
    $this->actingAs($actor)
        ->get(route('rme.legacy-odontograms.source', $record->getKey()))
        ->assertForbidden();

    $this->actingAs($actor)
        ->get(route('rme.legacy-odontograms.pages.show', [$record->getKey(), 1]))
        ->assertForbidden();

    // Super Admin bypasses every policy via the single global Gate::before, so
    // the controller's own assertStreamable() is what actually stops them —
    // which is why that check exists in the controller and not only in the
    // policy. Super Admin is precisely who operates this capability.
    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-odontograms.source', $record->getKey()))
        ->assertNotFound();

    // Retracted, not erased: the row and its reason stay auditable to both.
    $this->actingAs($actor)
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertOk();

    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertOk();
});

it('never renders KTP or NIK on any archive surface', function () {
    $published = lodoPublished();

    // A REAL KTP on the patient, so the assertion has something to catch. The
    // factory leaves the column null, which would make this test vacuous.
    $published['patient']->forceFill(['ktp_number' => '7371010101900001'])->save();

    $operator = lodoOperator();

    $this->actingAs($operator)
        ->get(route('rme.legacy-odontograms.show', $published['record']->getKey()))
        ->assertOk()
        ->assertDontSee('7371010101900001');

    $this->actingAs($operator)
        ->get(route('settings.rme.legacy-odontograms.index'))
        ->assertOk()
        ->assertDontSee('7371010101900001');
});

it('does not let KTP or NIK be used as a search key on the intake listing', function () {
    $published = lodoPublished();
    $published['patient']->forceFill(['ktp_number' => '7371010101900001'])->save();

    // Searching by an identity number must find nothing: making KTP a lookup key
    // would turn the listing into an enumeration oracle for anyone who can open it.
    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.index', ['patient' => '7371010101900001']))
        ->assertOk()
        ->assertDontSee($published['patient']->name);
});

/*
|--------------------------------------------------------------------------
| The feature flag: it gates MIGRATION, never READING published evidence.
|--------------------------------------------------------------------------
*/

it('closes the whole intake surface while the migration capability is OFF', function () {
    $operator = lodoOperator();

    lodoFlag(false);

    $this->actingAs($operator)->get(route('settings.rme.legacy-odontograms.index'))->assertNotFound();
    $this->actingAs($operator)->get(route('settings.rme.legacy-odontograms.create'))->assertNotFound();
});

it('keeps an already-PUBLISHED archive readable while the migration capability is OFF', function () {
    $published = lodoPublished();
    $record = $published['record'];
    $reader = lodoOperator();

    lodoFlag(false);

    // Reading is governed by the record's state plus authorization, never by
    // the migration flag: a rollback must not remove a patient's history from
    // the doctor treating them.
    $this->actingAs($reader)->get(route('rme.legacy-odontograms.show', $record->getKey()))->assertOk();
    $this->actingAs($reader)->get(route('rme.legacy-odontograms.source', $record->getKey()))->assertOk();
    $this->actingAs($reader)->get(route('rme.legacy-odontograms.pages.show', [$record->getKey(), 1]))->assertOk();

    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($reader, (int) $published['patient']->id))
        ->toHaveCount(1);
});

it('freezes the archive while the migration capability is OFF — no void either', function () {
    $published = lodoPublished();
    $operator = lodoOperator();

    lodoFlag(false);

    $this->actingAs($operator)
        ->post(route('rme.legacy-odontograms.void', $published['record']->getKey()), [
            'void_reason' => 'Alasan pembatalan yang cukup panjang untuk lolos validasi.',
        ])
        ->assertNotFound();

    expect($published['record']->refresh()->isPublished())->toBeTrue();
});
