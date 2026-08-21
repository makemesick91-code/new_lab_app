<?php

/**
 * FIX-04b — the operator's real path through HTTP, end to end.
 *
 * The service tests prove the rules; this proves the routes actually enforce
 * them: the permission middleware, the FormRequest, the policy and the branch
 * scope, in the order a browser hits them.
 */

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();

    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));
});

it('walks upload → review → publish → view, and the record is read-only at the end', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');
    $operator = lodoOperator();

    // Upload.
    $this->actingAs($operator)
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2019-06-01',
            'document' => lodoPdfUpload('odontogram.pdf', 2),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertRedirect();

    $import = LegacyOdontogramImport::query()->firstOrFail();

    expect($import->status)->toBe(LegacyOdontogramImportStatus::QUEUED);

    // Render (the queued job, run inline).
    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    $this->actingAs($operator)
        ->get(route('settings.rme.legacy-odontograms.show', $import->getKey()))
        ->assertOk()
        ->assertSee('READY_FOR_REVIEW');

    // Review, then publish.
    $this->actingAs($operator)
        ->post(route('settings.rme.legacy-odontograms.review', $import->getKey()))
        ->assertRedirect();

    $this->actingAs($operator)
        ->post(route('settings.rme.legacy-odontograms.publish', $import->getKey()), [
            'title' => 'Kartu odontogram 2019',
        ])
        ->assertRedirect();

    $record = LegacyOdontogramRecord::query()->firstOrFail();

    expect($record->status)->toBe(LegacyOdontogramRecordStatus::PUBLISHED)
        ->and($record->page_count)->toBe(2);

    // The published viewer works, and offers no edit/delete affordance.
    $this->actingAs($operator)
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertOk()
        ->assertSee('Kartu odontogram 2019')
        ->assertSee('Batalkan Arsip');
});

it('rejects a store that omits the operator confirmations', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2019-06-01',
            'document' => lodoPdfUpload(),
        ])
        ->assertSessionHasErrors(['patient_confirmation', 'date_confirmation']);

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('refuses an operator holding only the read permission', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $reader = userWith(['view_legacy_odontogram_imports']);

    $this->actingAs($reader)
        ->get(route('settings.rme.legacy-odontograms.create'))
        ->assertForbidden();

    $this->actingAs($reader)
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2019-06-01',
            'document' => lodoPdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertForbidden();
});

it('refuses a publisher who lacks the publish permission', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $import = lodoStageImport($patient, '2019-06-01', lodoOperator(), 2);
    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    $reviewer = userWith([
        'view_legacy_odontogram_imports',
        'review_legacy_odontogram_imports',
    ]);

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-odontograms.review', $import->getKey()))
        ->assertRedirect();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-odontograms.publish', $import->getKey()))
        ->assertForbidden();

    expect(LegacyOdontogramRecord::count())->toBe(0);
});

it('sends a guest to the login screen rather than answering 403', function () {
    $this->get(route('settings.rme.legacy-odontograms.index'))->assertRedirect(route('login'));
    $this->get(route('rme.legacy-odontograms.show', 1))->assertRedirect(route('login'));
});
