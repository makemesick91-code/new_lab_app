<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §19, §22, §29 — the operator's
 * actual path, driven over HTTP.
 *
 * The service-level suites prove the domain. This one proves the WORKFLOW an
 * operator walks, because the guarantees that matter most (a confirmation
 * cannot promote a blocked row; a cancel cannot delete a clinical document)
 * are properties of the HTTP boundary, and a boundary is only proven by
 * crossing it.
 */
beforeEach(function (): void {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    legacyMassUploadFakeDisk();
    Bus::fake();

    // ROLL-3/ROLL-4: ingestion needs an admitted branch under an approved wave.
    // Supplying only the permission would test a denial by accident — mass
    // upload changes none of that layer, so the fixture must satisfy it exactly
    // as production does.
    legacyRmeMigrationWave(['TLK1']);
    legacyRmeAdmitBranch('TLK1');
});

function lmuWorkflowPdf(int $pages = 1): string
{
    static $variant = 0;
    $variant++;

    return legacyRmePdfBytes($pages, 595.276 + ($variant / 1000));
}

/**
 * A real legacy operator: permitted, branch-pinned AND wave-assigned.
 *
 * The ROLL-4 layer requires all three. A fixture that supplies only the
 * permission measures a denial rather than the workflow — which is exactly what
 * the first version of this suite did.
 */
function lmuWorkflowUploader(): User
{
    $user = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    $user->forceFill(['branch_id' => legacyRmeBranch('TLK1')->id])->save();

    return legacyRmeOperator($user);
}

function lmuWorkflowPatient(): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    legacyRmeNativeVisit($patient, '2022-03-10');

    return $patient;
}

/*
|--------------------------------------------------------------------------
| The full operator path
|--------------------------------------------------------------------------
*/

it('walks upload then review then confirm entirely over http', function (): void {
    $uploader = lmuWorkflowUploader();
    $one = lmuWorkflowPatient();
    $two = lmuWorkflowPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => lmuWorkflowPdf(), 'b.pdf' => lmuWorkflowPdf()],
        manifestRows: [
            legacyMassUploadRmeRow($one->medical_record_number, 'a.pdf', '2018-01-02', '2019-05-06'),
            legacyMassUploadRmeRow($two->medical_record_number, 'b.pdf', '2017-03-04'),
        ],
    );

    // 1. Upload. The response must be a redirect to review — NOT a dispatch.
    $store = $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip]);

    $batch = LegacyMassUploadBatch::query()->latest('id')->firstOrFail();

    $store->assertRedirect(route('settings.rme.legacy-mass-imports.show', $batch));

    // §19 — nothing is created merely because the archive was valid.
    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::PREFLIGHT_READY)
        ->and(LegacyRmeImport::query()->count())->toBe(0);

    // 2. Review. The page states the counts the operator decides on.
    $this->actingAs($uploader)
        ->get(route('settings.rme.legacy-mass-imports.show', $batch))
        ->assertOk()
        ->assertSee('Mulai Upload', false)
        ->assertSee($one->medical_record_number, false);

    // 3. Confirm.
    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.confirm', $batch))
        ->assertRedirect(route('settings.rme.legacy-mass-imports.show', $batch));

    $batch->refresh();

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::COMPLETED)
        ->and($batch->dispatched_items)->toBe(2)
        ->and(LegacyRmeImport::query()->count())->toBe(2);
});

it('does not offer the start button before the package has been reviewed', function (): void {
    // A package-rejected batch has nothing to confirm, and the page must not
    // present an action the server would refuse.
    $uploader = lmuWorkflowUploader();

    $batch = LegacyMassUploadBatch::factory()->create([
        'created_by' => $uploader->getKey(),
        'status' => LegacyMassUploadBatchStatus::PACKAGE_REJECTED,
        'failure_code' => LegacyMassUploadReason::MANIFEST_MISSING,
        'failure_message' => LegacyMassUploadReason::message(LegacyMassUploadReason::MANIFEST_MISSING),
    ]);

    $this->actingAs($uploader)
        ->get(route('settings.rme.legacy-mass-imports.show', $batch))
        ->assertOk()
        ->assertSee('Paket ditolak', false)
        ->assertDontSee('Mulai Upload', false);
});

it('refuses to confirm a batch that is not awaiting review', function (): void {
    // §27 — the status guard, at the HTTP boundary.
    $uploader = lmuWorkflowUploader();

    $batch = LegacyMassUploadBatch::factory()->completed()->create([
        'created_by' => $uploader->getKey(),
    ]);

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.confirm', $batch))
        ->assertForbidden();
});

it('cannot promote a blocked row by confirming the batch', function (): void {
    /*
     * §19, stated as an attack.
     *
     * The confirm form carries NO row selection, so there is nothing for an
     * operator to tamper with — but even a crafted POST cannot help, because
     * dispatch reads the rows the SERVER marked dispatchable and a blocked row
     * is not among them.
     */
    $uploader = lmuWorkflowUploader();
    $blocked = lmuWorkflowPatient();

    // Occupy the patient's slot so their row is refused at preflight.
    app(LegacyRmeImportService::class)->createFromUpload(
        $blocked,
        '2020-05-01',
        $blocked->medical_record_number,
        null,
        UploadedFile::fake()->createWithContent('arsip.pdf', lmuWorkflowPdf()),
        superAdmin(),
    );

    $existing = LegacyRmeImport::query()->count();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => lmuWorkflowPdf()],
        manifestRows: [legacyMassUploadRmeRow($blocked->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip]);

    $batch = LegacyMassUploadBatch::query()->latest('id')->firstOrFail();

    expect($batch->blocked_items)->toBe(1)->and($batch->eligible_items)->toBe(0);

    // A confirmation that tries to force the row through, including a forged
    // item id and an override flag that does not exist.
    $item = LegacyMassUploadItem::query()->where('mass_upload_batch_id', $batch->getKey())->firstOrFail();

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.confirm', $batch), [
        'items' => [$item->getKey()],
        'force' => true,
        'override_blocked' => '1',
    ]);

    $batch->refresh();
    $item->refresh();

    expect($item->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($item->rme_legacy_import_id)->toBeNull()
        ->and($batch->dispatched_items)->toBe(0)
        ->and(LegacyRmeImport::query()->count())->toBe($existing);
});

/*
|--------------------------------------------------------------------------
| §29 — cancellation is a staging operation only
|--------------------------------------------------------------------------
*/

it('cancels a reviewed batch without creating or deleting anything clinical', function (): void {
    $uploader = lmuWorkflowUploader();
    $patient = lmuWorkflowPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => lmuWorkflowPdf()],
        manifestRows: [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip]);

    $batch = LegacyMassUploadBatch::query()->latest('id')->firstOrFail();

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.cancel', $batch))
        ->assertRedirect(route('settings.rme.legacy-mass-imports.index'));

    $batch->refresh();

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::CANCELLED)
        ->and(LegacyRmeImport::query()->count())->toBe(0)
        // The workspace is cleaned on the cancel path too (§40).
        ->and($batch->workspace_cleaned_at)->not->toBeNull();
});

it('refuses to cancel a batch that has already created a lifecycle', function (): void {
    /*
     * THE RULE §29 EXISTS FOR.
     *
     * Once a real document exists, "cancel" must never become a way to make it
     * disappear. Withdrawing a document is VOID, on that document, under its
     * own authority — a completely different act with a completely different
     * permission.
     */
    $uploader = lmuWorkflowUploader();
    $patient = lmuWorkflowPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => lmuWorkflowPdf()],
        manifestRows: [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip]);

    $batch = LegacyMassUploadBatch::query()->latest('id')->firstOrFail();

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.confirm', $batch));

    $batch->refresh();

    expect($batch->dispatched_items)->toBe(1)
        ->and(LegacyRmeImport::query()->count())->toBe(1);

    // Cancel is now refused by the policy, and the document survives.
    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.cancel', $batch))
        ->assertForbidden();

    expect(LegacyRmeImport::query()->count())->toBe(1)
        ->and($batch->refresh()->status)->not->toBe(LegacyMassUploadBatchStatus::CANCELLED);
});

/*
|--------------------------------------------------------------------------
| §22 — the report
|--------------------------------------------------------------------------
*/

it('streams a report that carries reason codes but no patient identity', function (): void {
    $uploader = lmuWorkflowUploader();
    $patient = lmuWorkflowPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => lmuWorkflowPdf()],
        manifestRows: [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $this->actingAs($uploader)->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip]);

    $batch = LegacyMassUploadBatch::query()->latest('id')->firstOrFail();

    $response = $this->actingAs($uploader)->get(route('settings.rme.legacy-mass-imports.report', $batch));

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('row_number')
        ->toContain('reason_code')
        // The operator's own RM is the only patient identifier permitted.
        ->toContain((string) $patient->medical_record_number)
        // Never the patient's name, and never an identity number.
        ->not->toContain((string) $patient->name)
        ->not->toContain('ktp')
        ->not->toContain('nik');
});

it('neutralises a formula in a report cell', function (): void {
    /*
     * A manifest is operator-authored free text, and a report is a spreadsheet
     * that gets forwarded. An RM beginning with `=` would otherwise be executed
     * by Excel on open.
     */
    $uploader = lmuWorkflowUploader();

    $batch = LegacyMassUploadBatch::factory()->preflightReady()->create([
        'created_by' => $uploader->getKey(),
    ]);

    LegacyMassUploadItem::factory()->blocked()->create([
        'mass_upload_batch_id' => $batch->getKey(),
        'row_number' => 1,
        'manifest_medical_record_number' => '=cmd|calc',
        'manifest_file_name' => '=HYPERLINK("http://x")',
    ]);

    $csv = $this->actingAs($uploader)
        ->get(route('settings.rme.legacy-mass-imports.report', $batch))
        ->streamedContent();

    // Escaped to text, so the cell is inert.
    expect($csv)
        ->toContain("'=cmd|calc")
        ->toContain("'=HYPERLINK")
        // And the raw, unescaped form never appears at the start of a cell.
        ->not->toContain(',=cmd|calc');
});

/*
|--------------------------------------------------------------------------
| Branch isolation on the batch list
|--------------------------------------------------------------------------
*/

it('does not list another operators out of scope batch', function (): void {
    $mine = lmuWorkflowUploader();
    $theirs = lmuWorkflowUploader();

    $otherBranch = legacyRmeBranch('ATG3', 'Cabang Antang');

    $foreign = LegacyMassUploadBatch::factory()->preflightReady()->create([
        'created_by' => $theirs->getKey(),
        'origin_branch_id' => $otherBranch->getKey(),
        'package_original_name' => 'paket-cabang-lain.zip',
    ]);

    $response = $this->actingAs($mine)->get(route('settings.rme.legacy-mass-imports.index'));

    $response->assertOk()->assertDontSee('paket-cabang-lain.zip', false);

    // And the detail route is refused, not merely hidden.
    $this->actingAs($mine)
        ->get(route('settings.rme.legacy-mass-imports.show', $foreign))
        ->assertForbidden();
});
