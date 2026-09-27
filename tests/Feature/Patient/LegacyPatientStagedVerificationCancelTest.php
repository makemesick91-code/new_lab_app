<?php

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * The contract: uploading a legacy patient CSV is STAGING, not importing.
 *
 *   upload -> stage -> validate the whole batch -> operator reviews
 *     ERROR > 0  -> the WHOLE batch is refused; the batch may be cancelled
 *     ERROR = 0  -> operator explicitly confirms
 *                -> server revalidates against the database as it stands NOW
 *                -> all approved rows commit, or none do
 *
 * Every assertion here answers one question: can an incorrect legacy patient
 * reach the canonical patient estate because a CSV was uploaded? The answer must
 * be no at every step, and the checks must hold against a crafted request, not
 * only against the button.
 *
 * Sibling suite: LegacyPatientBatchImportTest (Sprint 62.3) covers parsing,
 * mapping, masking and rollback. This suite covers the staged-verification
 * lifecycle that revision added.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Exceptions\LegacyPatientImportBlockedException;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\LegacyPatientImportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'TLK1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->service = app(LegacyPatientImportService::class);
});

/** Build a legacy CSV from canonical-key rows (missing keys -> blank cell). */
function stagedCsv(array $rows): UploadedFile
{
    $keys = array_map(fn ($c) => $c['key'], LegacyPatientImportService::COLUMNS);
    $header = array_map(fn ($c) => $c['label'], LegacyPatientImportService::COLUMNS);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        $line = [];
        foreach ($keys as $k) {
            $line[] = $row[$k] ?? '';
        }
        fputcsv($handle, $line);
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    // FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — the fixture must outlive this function
    // (the request has not run yet), so the registry owns the path and the global
    // afterEach drains it.
    $path = tempArtifactFile('stg');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'legacy.csv', 'text/csv', null, true);
}

function stagedRow(array $overrides = []): array
{
    return array_merge([
        'branch' => 'TLK1',
        'manual_rm_number' => '0001',
        'timestamp' => '2024-01-15',
        'name' => 'Budi Santoso',
        'gender' => 'Laki-laki',
        'date_of_birth' => '1990-05-20',
    ], $overrides);
}

/** A batch of n valid rows with distinct manual RMs. */
function stagedBatchOf(int $valid, int $errors = 0): LegacyPatientImportBatch
{
    $rows = [];

    for ($i = 1; $i <= $valid; $i++) {
        $rows[] = stagedRow([
            'manual_rm_number' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => 'Pasien '.$i,
        ]);
    }

    for ($i = 1; $i <= $errors; $i++) {
        // A blank name is a blocking error; the RM stays unique so the ONLY
        // reason the batch is refused is the error row itself.
        $rows[] = stagedRow([
            'manual_rm_number' => str_pad((string) (9000 + $i), 4, '0', STR_PAD_LEFT),
            'name' => '',
        ]);
    }

    return test()->service->parseAndStage(stagedCsv($rows), null);
}

function confirmAs(LegacyPatientImportBatch $batch, array $payload = ['acknowledged' => '1'])
{
    return test()->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.import.commit', $batch), $payload);
}

// =============================================================================
// Staging is not importing
// =============================================================================

it('creates zero patients on upload, validation, preview, report and cancel', function () {
    $user = userWith(['manage patients']);

    // Upload (through HTTP, so the whole request path is covered).
    $this->actingAs($user)
        ->post(route('settings.patients.import.store'), ['csv_file' => stagedCsv([stagedRow()])])
        ->assertRedirect();

    $batch = LegacyPatientImportBatch::firstOrFail();
    expect(Patient::count())->toBe(0);

    // Validation happened at upload; it must not have created anyone.
    expect($batch->status)->toBe('validated')
        ->and($batch->valid_rows)->toBe(1)
        ->and(Patient::count())->toBe(0);

    // Preview.
    $this->actingAs($user)->get(route('settings.patients.import.show', $batch))->assertOk();
    expect(Patient::count())->toBe(0);

    // Verification report.
    $this->actingAs($user)->get(route('settings.patients.import.errors', $batch))->assertOk();
    expect(Patient::count())->toBe(0);

    // Cancel.
    $this->actingAs($user)->delete(route('settings.patients.import.destroy', $batch))->assertRedirect();
    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('cancelled');
});

it('reports a clean batch as ready to import and a batch with errors as review required', function () {
    $clean = stagedBatchOf(2);
    expect($clean->isReadyToImport())->toBeTrue()
        ->and($clean->isReviewRequired())->toBeFalse()
        ->and($clean->committable())->toBeTrue();

    $dirty = stagedBatchOf(2, 1);
    expect($dirty->isReviewRequired())->toBeTrue()
        ->and($dirty->isReadyToImport())->toBeFalse()
        ->and($dirty->committable())->toBeFalse();
});

it('refuses a batch of nothing importable rather than reporting a successful import of nobody', function () {
    $batch = stagedBatchOf(0, 1);

    expect($batch->isReadyToImport())->toBeFalse();

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::count())->toBe(0);
});

// =============================================================================
// ERROR blocks the ENTIRE batch — the headline rule
// =============================================================================

it('imports zero patients from a 500-row batch when 3 rows carry errors', function () {
    // The canonical worked example from the specification: 500 rows, 497 valid,
    // 3 error. Sprint 62.3 imported 497. The answer is now 0 — and the 497 are
    // still there in staging, so nothing is lost, only withheld.
    $batch = stagedBatchOf(497, 3);

    expect($batch->total_rows)->toBe(500)
        ->and($batch->valid_rows)->toBe(497)
        ->and($batch->error_rows)->toBe(3);

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->committed_rows)->toBe(0)
        ->and(LegacyPatientImportRow::where('status', 'committed')->count())->toBe(0);
})->group('slow');

it('refuses a crafted confirmation of a batch with errors, not merely a disabled button', function () {
    $batch = stagedBatchOf(2, 1);

    // No UI involved: a direct POST with the acknowledgement set.
    confirmAs($batch)->assertRedirect()->assertSessionHasErrors('commit');

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('validated');
});

it('names the error count and refuses with a stable reason code', function () {
    $batch = stagedBatchOf(1, 2);

    try {
        $this->service->commit($batch, null);
        $this->fail('the batch should have been refused');
    } catch (LegacyPatientImportBlockedException $e) {
        expect($e->reason)->toBe(LegacyPatientImportBlockedException::REASON_ERROR_ROWS)
            ->and($e->getMessage())->toContain('2 baris');
    }

    expect(Patient::count())->toBe(0);
});

it('lets the operator download the verification report for a refused batch', function () {
    $batch = stagedBatchOf(1, 1);

    $csv = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.errors', $batch))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Nama Pasien wajib diisi.')
        ->and(Patient::count())->toBe(0);
});

// =============================================================================
// WARNING is not ERROR
// =============================================================================

it('lets a warning-only batch import, and keeps the warnings visible', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'A', 'doctor' => 'Dokter Tidak Ada']),
    ]), null);

    expect($batch->warning_rows)->toBe(1)
        ->and($batch->error_rows)->toBe(0)
        ->and($batch->isReadyToImport())->toBeTrue();

    $batch = $this->service->commit($batch, null);

    expect($batch->committed_rows)->toBe(1)
        ->and(Patient::count())->toBe(1)
        // The warning survived the import: it is evidence, not a transient hint.
        ->and(LegacyPatientImportRow::first()->warnings)->not->toBeNull()
        // The unresolved doctor was not invented.
        ->and(Patient::first()->doctor_id)->toBeNull();
});

it('keeps a warning a warning even when it sits beside an error row', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'A', 'doctor' => 'Dokter Tidak Ada']),
        stagedRow(['manual_rm_number' => '0002', 'name' => '']),
    ]), null);

    expect($batch->warning_rows)->toBe(1)
        ->and($batch->error_rows)->toBe(1);

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    // The warning row was not silently promoted to an error to justify the refusal.
    expect(LegacyPatientImportRow::where('patient_name', 'A')->first()->status)->toBe('warning')
        ->and(Patient::count())->toBe(0);
});

// =============================================================================
// Explicit confirmation
// =============================================================================

it('imports nothing when the confirmation acknowledgement is absent', function () {
    $batch = stagedBatchOf(2);

    confirmAs($batch, [])->assertRedirect()->assertSessionHasErrors('acknowledged');

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('validated');
});

it('imports every approved row once the operator acknowledges', function () {
    $batch = stagedBatchOf(3);

    confirmAs($batch)->assertRedirect()->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(3)
        ->and($batch->refresh()->status)->toBe('committed')
        ->and($batch->committed_rows)->toBe(3);
});

it('creates no visit, medical record or clinical artefact when it imports', function () {
    $batch = stagedBatchOf(2);

    $this->service->commit($batch, null);

    expect(Patient::count())->toBe(2)
        ->and(ClinicVisit::count())->toBe(0)
        ->and(MedicalRecord::count())->toBe(0);
});

// =============================================================================
// Source identity
// =============================================================================

it('records the source sha256 at upload', function () {
    $batch = stagedBatchOf(1);

    expect($batch->file_hash)->not->toBeNull()
        ->and(strlen((string) $batch->file_hash))->toBe(64)
        ->and($batch->stored_path)->not->toBeNull();
});

it('refuses to import when the stored source file changed after review', function () {
    $batch = stagedBatchOf(1);

    // The reviewed bytes are replaced. Whatever the operator approved, it is not this.
    Storage::disk('local')->put($batch->stored_path, "tampered\n");

    try {
        $this->service->commit($batch->refresh(), null);
        $this->fail('a changed source must be refused');
    } catch (LegacyPatientImportBlockedException $e) {
        expect($e->reason)->toBe(LegacyPatientImportBlockedException::REASON_SOURCE_CHANGED);
    }

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('failed')
        ->and($batch->source_verified_at)->toBeNull();
});

it('refuses to import when the stored source file is gone', function () {
    $batch = stagedBatchOf(1);

    Storage::disk('local')->delete($batch->stored_path);

    try {
        $this->service->commit($batch->refresh(), null);
        $this->fail('a missing source must be refused');
    } catch (LegacyPatientImportBlockedException $e) {
        expect($e->reason)->toBe(LegacyPatientImportBlockedException::REASON_SOURCE_MISSING);
    }

    expect(Patient::count())->toBe(0);
});

it('stamps source_verified_at only when the hash actually matched', function () {
    $batch = stagedBatchOf(1);
    expect($batch->source_verified_at)->toBeNull();

    $this->service->commit($batch, null);

    expect($batch->refresh()->source_verified_at)->not->toBeNull();
});

// =============================================================================
// Confirm-time revalidation against current database state
// =============================================================================

it('catches at confirmation a conflict that did not exist at preview, and imports nothing', function () {
    $batch = stagedBatchOf(3);
    expect($batch->error_rows)->toBe(0);

    // Somebody else registers one of these RMs while the operator was reviewing.
    Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2024-0002']);

    try {
        $this->service->commit($batch, null);
        $this->fail('the stale batch should have been refused');
    } catch (LegacyPatientImportBlockedException $e) {
        expect($e->reason)->toBe(LegacyPatientImportBlockedException::REASON_REVALIDATION_FAILED);
    }

    // None of the three imported — not even the two that are still fine.
    expect(Patient::where('import_batch_id', $batch->id)->count())->toBe(0)
        ->and(Patient::count())->toBe(1);
});

it('persists the refreshed verdict so the operator can see why it was refused', function () {
    $batch = stagedBatchOf(2);

    Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2024-0001']);

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    $batch->refresh();

    // The verdict was written OUTSIDE the refusing transaction — had it been
    // inside, the throw would have unwound it and the preview would still show
    // a clean batch while the operator was told it was blocked.
    expect($batch->error_rows)->toBe(1)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->isReviewRequired())->toBeTrue()
        ->and($batch->revalidated_at)->not->toBeNull()
        ->and($batch->revalidation_attempts)->toBeGreaterThan(0)
        ->and($batch->error_summary)->not->toBeNull();
});

it('lets the batch import after the blocking conflict is removed', function () {
    $batch = stagedBatchOf(2);

    $blocker = Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2024-0001']);

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    $blocker->forceDelete();

    $batch = $this->service->commit($batch->refresh(), null);

    expect($batch->committed_rows)->toBe(2)
        ->and(Patient::count())->toBe(2);
});

it('re-asserts in-file duplicates at confirmation', function () {
    // Two rows claiming the same composed RM. The second is an error at upload;
    // revalidation must reach the same conclusion rather than losing the
    // in-file `seen` state it rebuilds from scratch.
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'A']),
        stagedRow(['manual_rm_number' => '0001', 'name' => 'B']),
    ]), null);

    expect($batch->error_rows)->toBe(1);

    $batch = $this->service->revalidate($batch, null);

    expect($batch->error_rows)->toBe(1)
        ->and(Patient::count())->toBe(0);
});

it('revalidates without scanning the patient estate row by row', function () {
    // The prefetch exists so a large batch does not become one query per row.
    // Twelve rows must not cost twelve identity lookups.
    $batch = stagedBatchOf(12);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->service->revalidate($batch, null);

    // Generous ceiling: what matters is that it is not O(rows) identity lookups.
    // Sprint 62.3's per-row form would add at least 24 (RM + KTP) on its own.
    expect($queries)->toBeLessThan(12 * 3);
});

// =============================================================================
// Atomicity
// =============================================================================

it('rolls back every insert when a later row fails mid-import', function () {
    $batch = stagedBatchOf(5);

    /*
     * The failure is injected where a real one would occur: at INSERT time,
     * inside the transaction, after earlier rows have already been written.
     * Corrupting the staged payload instead would prove nothing — confirm-time
     * revalidation rewrites `normalized_payload` from the frozen `raw_payload`,
     * so it would heal the corruption before the transaction even opened. That
     * is itself worth knowing, and it is why this hooks the model.
     */
    $created = 0;
    Patient::creating(function () use (&$created) {
        $created++;

        if ($created === 4) {
            throw new RuntimeException('simulated insert failure on row 4');
        }
    });

    expect(fn () => $this->service->commit($batch->refresh(), null))
        ->toThrow(RuntimeException::class);

    // Rows 1..3 were inserted and then discarded by the database itself.
    expect($created)->toBe(4)
        ->and(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(0)
        ->and($batch->refresh()->status)->not->toBe('committed')
        ->and($batch->committed_rows)->toBe(0);
});

it('imports exactly the number of rows it approved', function () {
    $batch = stagedBatchOf(7);
    $approved = $batch->valid_rows + $batch->warning_rows;

    $batch = $this->service->commit($batch, null);

    expect($approved)->toBe(7)
        ->and($batch->committed_rows)->toBe(7)
        ->and(Patient::count())->toBe(7)
        ->and(Patient::where('import_batch_id', $batch->id)->count())->toBe(7);
});

// =============================================================================
// Idempotency and concurrency
// =============================================================================

it('does not import twice when confirmation is submitted twice', function () {
    $batch = stagedBatchOf(3);

    confirmAs($batch)->assertRedirect();
    confirmAs($batch->refresh())->assertRedirect();

    expect(Patient::count())->toBe(3)
        ->and($batch->refresh()->committed_rows)->toBe(3);
});

it('refuses a stale confirmation with a lifecycle reason, not a stale-data one', function () {
    /*
     * The deterministic stand-in for the concurrent loser. Two operators confirm
     * the same batch: one wins, the other is still holding the model it loaded
     * before the win. The header row lock inside the transaction re-reads the
     * lifecycle and refuses — and the REASON matters as much as the refusal.
     *
     * A two-process run on PostgreSQL 16 showed that removing the lock does NOT
     * create a duplicate patient (the UNIQUE indexes on medical_record_number and
     * ktp_number already guarantee that) — it changes the loser's refusal from
     * `not_ready` to `revalidation_failed`. That is a lie with consequences: it
     * tells an operator their file went stale and sends them to fix a file that
     * was never wrong, when in fact a colleague simply got there first.
     */
    $batch = stagedBatchOf(3);
    $stale = LegacyPatientImportBatch::findOrFail($batch->id);   // loaded before the win

    $this->service->commit($batch, null);                        // the winner
    expect(Patient::count())->toBe(3);

    try {
        $this->service->commit($stale, null);                    // the loser, holding a stale model
        $this->fail('a stale confirmation must be refused');
    } catch (LegacyPatientImportBlockedException $e) {
        expect($e->reason)->toBe(LegacyPatientImportBlockedException::REASON_NOT_READY);
    }

    // The winner's bookkeeping is intact — the loser did not rewind it.
    $batch->refresh();
    expect(Patient::count())->toBe(3)
        ->and($batch->status)->toBe('committed')
        ->and($batch->committed_rows)->toBe(3)
        ->and(LegacyPatientImportRow::where('status', 'committed')->count())->toBe(3);
});

it('keeps a committed batch rollbackable after a losing concurrent confirmation', function () {
    // The consequence of the defect the concurrency harness found: a losing
    // confirm used to reset `status` to `validated` while `committed_rows` stayed
    // at 3, and rollback keys off isCommitted() — so three real patients became
    // impossible to withdraw through the workflow that created them.
    $batch = stagedBatchOf(3);
    $stale = LegacyPatientImportBatch::findOrFail($batch->id);

    $this->service->commit($batch, null);
    expect(fn () => $this->service->commit($stale, null))->toThrow(LegacyPatientImportBlockedException::class);

    $this->service->rollback($batch->refresh(), null);

    expect(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(3)
        ->and($batch->refresh()->status)->toBe('rolled_back');
});

it('refuses a confirmation of a cancelled batch', function () {
    $batch = stagedBatchOf(2);
    $this->service->cancel($batch, null, null);

    confirmAs($batch->refresh())->assertRedirect()->assertSessionHasErrors('commit');

    expect(Patient::count())->toBe(0);
});

it('refuses a second batch claiming an RM the first batch already imported', function () {
    $first = stagedBatchOf(1);
    $second = $this->service->parseAndStage(stagedCsv([stagedRow(['manual_rm_number' => '0001', 'name' => 'Duplikat'])]), null);

    $this->service->commit($first, null);
    expect(Patient::count())->toBe(1);

    // The second batch previewed clean; confirmation must catch the collision.
    expect(fn () => $this->service->commit($second->refresh(), null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::count())->toBe(1);
});

it('cancels idempotently', function () {
    $batch = stagedBatchOf(1);

    $this->service->cancel($batch, null, 'pertama');
    $this->service->cancel($batch->refresh(), null, 'kedua');

    expect($batch->refresh()->status)->toBe('cancelled')
        // The first cancellation is the one that happened; the second did not
        // overwrite its reason.
        ->and($batch->cancel_reason)->toBe('pertama');
});

// =============================================================================
// Cancellation boundary
// =============================================================================

it('records who cancelled, when, and why, without deleting the batch', function () {
    $user = userWith(['manage patients']);
    $batch = stagedBatchOf(2, 1);

    $this->actingAs($user)
        ->delete(route('settings.patients.import.destroy', $batch), ['cancel_reason' => 'berkas sumber diperbaiki'])
        ->assertRedirect();

    $batch->refresh();

    expect($batch->status)->toBe('cancelled')
        ->and($batch->cancelled_by)->toBe($user->id)
        ->and($batch->cancelled_at)->not->toBeNull()
        ->and($batch->cancel_reason)->toBe('berkas sumber diperbaiki')
        // The staging rows and their verdicts survive: they are the reason.
        ->and(LegacyPatientImportRow::where('batch_id', $batch->id)->count())->toBe(3)
        ->and(LegacyPatientImportRow::where('status', 'error')->count())->toBe(1)
        ->and(Patient::count())->toBe(0);
});

it('accepts a cancellation with no reason', function () {
    $batch = stagedBatchOf(1);

    $this->actingAs(userWith(['manage patients']))
        ->delete(route('settings.patients.import.destroy', $batch))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($batch->refresh()->status)->toBe('cancelled')
        ->and($batch->cancel_reason)->toBeNull();
});

it('refuses to cancel a batch that has already imported patients', function () {
    $batch = stagedBatchOf(2);
    $this->service->commit($batch, null);

    expect($batch->refresh()->isCancellable())->toBeFalse();

    expect(fn () => $this->service->cancel($batch, null, null))
        ->toThrow(RuntimeException::class);

    // Cancellation is never a way to delete patients.
    expect(Patient::count())->toBe(2)
        ->and($batch->refresh()->status)->toBe('committed');
});

it('leaves rollback as the only way to withdraw imported patients', function () {
    $batch = stagedBatchOf(1);
    $this->service->commit($batch, null);

    $this->service->rollback($batch->refresh(), null);

    expect(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(1)
        ->and($batch->refresh()->status)->toBe('rolled_back');
});

// =============================================================================
// Blank KTP / NIK
// =============================================================================

it('accepts a blank KTP and stores null, never a fabricated number', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'Andi Saputra', 'ktp_number' => '', 'date_of_birth' => '2014-08-12']),
    ]), null);

    expect($batch->error_rows)->toBe(0)
        ->and($batch->isReadyToImport())->toBeTrue();

    $this->service->commit($batch, null);

    $patient = Patient::firstOrFail();

    expect($patient->ktp_number)->toBeNull()
        ->and($patient->ktp_number)->not->toBe('0000000000000000')
        ->and($patient->ktp_number)->not->toBe('9999999999999999')
        ->and($patient->ktp_number)->not->toBe('')
        ->and(LegacyPatientImportRow::first()->ktp_masked)->toBeNull();
});

it('never derives a placeholder KTP for any blank-KTP row in a batch', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'A', 'ktp_number' => '']),
        stagedRow(['manual_rm_number' => '0002', 'name' => 'B', 'ktp_number' => '']),
        stagedRow(['manual_rm_number' => '0003', 'name' => 'C', 'ktp_number' => '']),
    ]), null);

    $this->service->commit($batch, null);

    // Three blank-KTP patients coexist. A sentinel would have collided on the
    // unique index and turned "no KTP" into a duplicate.
    expect(Patient::count())->toBe(3)
        ->and(Patient::whereNull('ktp_number')->count())->toBe(3);
});

it('still blocks a malformed supplied KTP', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'ktp_number' => '32010101010100019999']),
    ]), null);

    expect($batch->error_rows)->toBe(1)
        ->and($batch->isReadyToImport())->toBeFalse();

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::count())->toBe(0);
});

it('still blocks an exact KTP duplicate against an existing patient', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010001']);

    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'ktp_number' => '3201010101010001']),
    ]), null);

    expect($batch->error_rows)->toBe(1);

    expect(fn () => $this->service->commit($batch, null))
        ->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::count())->toBe(1);
});

it('surfaces a possible duplicate as a warning for a blank-KTP row rather than silently creating a second patient', function () {
    // No KTP to compare, so the duplicate signal is name + date of birth. The
    // canonical engine treats it as a warning: a human decides, and the decision
    // is visible before the import.
    Patient::factory()->create(['name' => 'Andi Saputra', 'date_of_birth' => '2014-08-12', 'ktp_number' => null]);

    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '0001', 'name' => 'Andi Saputra', 'date_of_birth' => '2014-08-12', 'ktp_number' => '']),
    ]), null);

    expect($batch->warning_rows)->toBe(1)
        ->and($batch->error_rows)->toBe(0)
        ->and(LegacyPatientImportRow::first()->warnings)->toBeArray();
});

// =============================================================================
// Branch authority
// =============================================================================

it('resolves the branch server-side from the CSV column and ignores a crafted request branch', function () {
    // There is no branch input in this workflow's request at all. The assertion
    // is that adding one changes nothing.
    $other = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.import.store'), [
            'csv_file' => stagedCsv([stagedRow(['branch' => 'TLK1'])]),
            'branch_id' => $other->id,
            'matched_branch_id' => $other->id,
        ])
        ->assertRedirect();

    expect(LegacyPatientImportRow::first()->matched_branch_id)->toBe($this->branch->id)
        ->and(LegacyPatientImportRow::first()->matched_branch_id)->not->toBe($other->id);
});

it('blocks a MAIN, inactive, non-RME or unknown branch and therefore the whole batch', function () {
    $main = Branch::where('code', Branch::MAIN_CODE)->first();
    $inactive = Branch::factory()->create(['code' => 'ATG3', 'is_active' => false, 'is_rme_enabled' => true]);
    $nonRme = Branch::factory()->create(['code' => 'NRM9', 'is_active' => true, 'is_rme_enabled' => false]);

    foreach ([$main?->code ?? 'MAIN', $inactive->code, $nonRme->code, 'TIDAKADA'] as $code) {
        $batch = $this->service->parseAndStage(stagedCsv([stagedRow(['branch' => $code])]), null);

        expect($batch->error_rows)->toBe(1, "branch {$code} should be refused")
            ->and($batch->isReadyToImport())->toBeFalse();

        expect(fn () => $this->service->commit($batch, null))
            ->toThrow(LegacyPatientImportBlockedException::class);
    }

    expect(Patient::count())->toBe(0);
});

it('charges the imported patient to the branch its own row resolved to', function () {
    Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);

    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['branch' => 'TLK1', 'manual_rm_number' => '0001', 'name' => 'A']),
        stagedRow(['branch' => 'LDK2', 'manual_rm_number' => '0002', 'name' => 'B']),
    ]), null);

    $this->service->commit($batch, null);

    expect(Patient::where('name', 'A')->first()->medical_record_number)->toBe('DG-TLK1-2024-0001')
        ->and(Patient::where('name', 'B')->first()->medical_record_number)->toBe('DG-LDK2-2024-0002');
});

// =============================================================================
// Authorization
// =============================================================================

it('denies every staged-verification action to a user without manage patients', function () {
    $user = userWith(['view_clinic_visits']);
    $batch = stagedBatchOf(1);

    $this->actingAs($user)->post(route('settings.patients.import.commit', $batch), ['acknowledged' => '1'])->assertForbidden();
    $this->actingAs($user)->delete(route('settings.patients.import.destroy', $batch))->assertForbidden();
    $this->actingAs($user)->get(route('settings.patients.import.errors', $batch))->assertForbidden();

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('validated');
});

it('exposes confirmation and cancellation only as state-changing verbs', function () {
    /*
     * CSRF itself is deliberately NOT asserted here. Laravel's VerifyCsrfToken
     * exempts itself while the application is running unit tests, so a test that
     * expected a 419 would pass or fail for reasons unrelated to this code and
     * would be evidence of nothing. The stock protection is unchanged by this
     * revision and the form carries @csrf.
     *
     * What IS provable, and is the adjacent guarantee worth pinning: neither
     * confirmation nor cancellation is reachable by a GET, so neither can be
     * triggered by a link, a prefetch or an image tag.
     */
    $batch = stagedBatchOf(1);
    $user = userWith(['manage patients']);

    // 405, not 404: the path exists, the verb does not.
    $this->actingAs($user)->get('/settings/patients/import/'.$batch->id.'/commit')->assertStatus(405);

    expect(Patient::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe('validated');
});

// =============================================================================
// Export safety
// =============================================================================

it('neutralises spreadsheet formulas in the verification report', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '', 'name' => '=cmd|\' /C calc\'!A0']),
    ]), null);

    $csv = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.errors', $batch))
        ->assertOk()
        ->streamedContent();

    // The cell is prefixed so a spreadsheet treats it as text, not a formula.
    expect($csv)->toContain("'=cmd")
        ->and($csv)->not->toContain(',=cmd');
});

it('never writes a full KTP into the verification report', function () {
    $batch = $this->service->parseAndStage(stagedCsv([
        stagedRow(['manual_rm_number' => '', 'ktp_number' => '3201010101015678']),
    ]), null);

    $csv = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.errors', $batch))
        ->assertOk()
        ->streamedContent();

    expect($csv)->not->toContain('3201010101015678')
        ->and($csv)->toContain('****5678');
});

// =============================================================================
// Audit trail
// =============================================================================

it('audits the upload, the refusal and the import without recording any PII', function () {
    $user = userWith(['manage patients']);
    $batch = stagedBatchOf(1, 1);

    confirmAs($batch);                           // refused
    $this->service->cancel($batch->refresh(), $user->id, 'diperbaiki');

    $actions = AuditLog::where('entity_type', LegacyPatientImportService::AUDIT_ENTITY)
        ->pluck('action')
        ->unique()
        ->values()
        ->all();

    expect($actions)->toContain(LegacyPatientImportService::AUDIT_UPLOADED)
        ->and($actions)->toContain(LegacyPatientImportService::AUDIT_CONFIRM_BLOCKED)
        ->and($actions)->toContain(LegacyPatientImportService::AUDIT_CANCELLED);

    // No patient name, no KTP, no filename anywhere in the payloads.
    $payloads = AuditLog::where('entity_type', LegacyPatientImportService::AUDIT_ENTITY)
        ->get()
        ->map(fn ($log) => json_encode($log->new_values))
        ->implode(' ');

    expect($payloads)->not->toContain('Pasien 1')
        ->and($payloads)->not->toContain('legacy.csv')
        ->and($payloads)->toContain('error_rows');
});

it('audits a successful import with the committed count', function () {
    $batch = stagedBatchOf(2);
    $this->service->commit($batch, null);

    $log = AuditLog::where('entity_type', LegacyPatientImportService::AUDIT_ENTITY)
        ->where('action', LegacyPatientImportService::AUDIT_COMMITTED)
        ->firstOrFail();

    expect($log->new_values['committed_rows'])->toBe(2)
        ->and($log->new_values['status'])->toBe('committed');
});
