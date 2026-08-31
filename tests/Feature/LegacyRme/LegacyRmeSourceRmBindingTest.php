<?php

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — the Nomor RM printed on a legacy document
 * must name the patient it is filed under, or nothing happens.
 *
 * THE FAILURE THIS SUITE EXISTS TO MAKE IMPOSSIBLE. Wave-2 produced a real
 * wrong-patient binding in production: an operator selected one patient and
 * uploaded a document belonging to another, and the write path could not tell,
 * because the RM printed on the paper was never captured. It was caught
 * afterwards from a frozen source hash and manual evidence. Every assertion
 * below is ultimately about that one scenario.
 *
 * WHAT IS PINNED HERE:
 *
 *   - Identity is EXACT. `27541` never resolves to `22541`, at any distance.
 *   - A mismatch, a miss, an ambiguity and a malformed value all FAIL CLOSED,
 *     and leave no staging row, no file, no queued job and no consumed quota.
 *   - The rule lives in the SERVICE, so HTTP, a direct call and the CLI are all
 *     the same door.
 *   - Normalization repairs transcription and never moves a digit.
 *   - The binding is REVALIDATED before review and before publish, because the
 *     master data can move after acceptance.
 *   - A pre-enforcement row (staged before capture existed) is refused rather
 *     than waved through — and CANCEL still works on it, because cancel is the
 *     safety valve that refusal depends on.
 *   - A refusal never discloses WHO the number actually belongs to.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
use App\Modules\LegacyRme\Services\LegacyRmeSourceRmNormalizer;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmFailure;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function srbBinding(): LegacyRmeSourcePatientBindingService
{
    return app(LegacyRmeSourcePatientBindingService::class);
}

/**
 * Attempt an upload through the canonical service.
 *
 * Deliberately the SERVICE and not the HTTP endpoint: the whole safety claim is
 * that the rule lives below the boundary, so the default probe has to be the
 * one that skips the form request entirely.
 */
function srbUpload(Patient $patient, ?string $sourceRm, string $date = '2015-01-01'): LegacyRmeImport
{
    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $date,
        $sourceRm,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );
}

/** @return array{imports: int, records: int, files: int} */
function srbFootprint(): array
{
    return [
        'imports' => LegacyRmeImport::withTrashed()->count(),
        'records' => LegacyRmeRecord::count(),
        'files' => count(Storage::disk('legacy_rme_private')->allFiles()),
    ];
}

/*
|--------------------------------------------------------------------------
| Normalization — repairs transcription, never moves a digit
|--------------------------------------------------------------------------
*/

it('folds transcription noise without changing a single significant character', function (string $input, ?string $expected) {
    expect(app(LegacyRmeSourceRmNormalizer::class)->normalize($input))->toBe($expected);
})->with([
    'already canonical' => ['DG-TLK1-2024-9985', 'DG-TLK1-2024-9985'],
    'outer whitespace' => ['  DG-TLK1-2024-9985  ', 'DG-TLK1-2024-9985'],
    'spaces around separators' => ['DG - TLK1 - 2024 - 9985', 'DG-TLK1-2024-9985'],
    'lowercase transcription' => ['dg-tlk1-2024-9985', 'DG-TLK1-2024-9985'],
    // A deprecated branch code is what the DOCUMENT says. Normalization
    // recases it; it deliberately does not rewrite it (see the test below).
    'deprecated branch code is transcribed faithfully' => ['dg-tkm1-2024-9985', 'DG-TKM1-2024-9985'],
    'en dash from a word processor' => ['DG–TLK1–2024–9985', 'DG-TLK1-2024-9985'],
    'non-breaking space' => ["DG-TLK1-2024-9985\u{00A0}", 'DG-TLK1-2024-9985'],
    'bare manual number is left alone' => ['9985', '9985'],
    'leading zeros are significant and preserved' => ['0099', '0099'],
    'empty becomes null, not an empty string' => ['   ', null],
]);

it('transcribes a DEPRECATED branch code faithfully instead of canonicalizing it', function () {
    // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — the source Nomor RM is what
    // the paper document ASSERTS, and it is frozen into immutable clinical
    // evidence at publish. Rewriting `TKM1` to `TLK1` here would make the
    // archive claim the document says something it does not.
    //
    // Reachability is preserved elsewhere, and deliberately so: identity
    // resolution and branch derivation canonicalize when they MATCH, so this
    // faithful value still binds to the right patient and the right branch.
    expect(app(LegacyRmeSourceRmNormalizer::class)->normalize('DG-TKM1-2024-9985'))
        ->toBe('DG-TKM1-2024-9985');
});

it('never turns one manual number into a different one', function () {
    $normalizer = app(LegacyRmeSourceRmNormalizer::class);

    // The exact pair from the RM 27541 investigation. Normalization is the only
    // place a digit could plausibly be "helpfully" repaired, so it is asserted
    // directly rather than only through the resolver.
    expect($normalizer->normalize('27541'))->toBe('27541')
        ->and($normalizer->normalize('27541'))->not->toBe('22541')
        ->and($normalizer->normalize('DG-LDK2-2024-27541'))->toBe('DG-LDK2-2024-27541');

    // Length is identity too: a truncation would be just as wrong as a swap.
    expect($normalizer->normalize('9985'))->not->toBe('998')
        ->and($normalizer->normalize('0099'))->not->toBe('99');
});

/*
|--------------------------------------------------------------------------
| The binding decision
|--------------------------------------------------------------------------
*/

it('binds a document whose printed Nomor RM names the selected patient', function () {
    $patient = legacyRmeArchivablePatient();

    $binding = srbBinding()->bind($patient->medical_record_number, $patient);

    expect($binding->bound)->toBeTrue()
        ->and($binding->patientId)->toBe($patient->getKey())
        ->and($binding->resolutionCode)->toBe('EXACT_UNIQUE')
        ->and($binding->code)->toBeNull();
});

it('binds the bare manual number an old document actually prints', function () {
    $patient = legacyRmeArchivablePatient();
    $manual = explode('-', $patient->medical_record_number, 4)[3];

    $binding = srbBinding()->bind($manual, $patient);

    expect($binding->bound)->toBeTrue()
        ->and($binding->resolutionCode)->toBe('SEGMENT_UNIQUE');
});

it('refuses a document whose printed Nomor RM belongs to a different patient', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    $binding = srbBinding()->bind($other->medical_record_number, $selected);

    expect($binding->bound)->toBeFalse()
        ->and($binding->code)->toBe(LegacyRmeSourceRmFailure::SOURCE_RM_PATIENT_MISMATCH);
});

it('refuses a Nomor RM no patient carries', function () {
    $patient = legacyRmeArchivablePatient();

    expect(srbBinding()->bind('DG-TLK1-2024-999999', $patient)->code)
        ->toBe(LegacyRmeSourceRmFailure::SOURCE_RM_NOT_FOUND);
});

it('refuses an ambiguous Nomor RM instead of picking one of the patients', function () {
    $selected = legacyRmeArchivablePatient();
    $duplicate = legacyRmeArchivablePatient();

    // A duplicated MANUAL SEGMENT across two branches: the bare number the
    // document prints now names two people, and choosing between them is a
    // master-data decision no query result may settle.
    $manual = explode('-', $selected->medical_record_number, 4)[3];
    $duplicate->forceFill(['medical_record_number' => 'DG-LDK2-2024-'.$manual])->save();
    legacyRmeBranch('LDK2', 'Cabang Landak');

    $binding = srbBinding()->bind($manual, $selected);

    expect($binding->bound)->toBeFalse()
        ->and($binding->code)->toBe(LegacyRmeSourceRmFailure::SOURCE_RM_AMBIGUOUS);
});

it('refuses a missing or malformed source RM before it ever reaches a query', function (?string $value, string $code) {
    $patient = legacyRmeArchivablePatient();

    expect(srbBinding()->bind($value, $patient)->code)->toBe($code);
})->with([
    'null' => [null, LegacyRmeSourceRmFailure::SOURCE_RM_REQUIRED],
    'blank' => ['   ', LegacyRmeSourceRmFailure::SOURCE_RM_REQUIRED],
    'a sentence' => ['ini dokumen lama pasien', LegacyRmeSourceRmFailure::SOURCE_RM_INVALID],
    'a wildcard probe' => ['%', LegacyRmeSourceRmFailure::SOURCE_RM_INVALID],
    'an over-long paste' => [str_repeat('9', 200), LegacyRmeSourceRmFailure::SOURCE_RM_INVALID],
    'too short to resolve safely' => ['99', LegacyRmeSourceRmFailure::SOURCE_RM_INVALID],
]);

it('never binds a near-miss Nomor RM — the RM 27541 regression', function () {
    // The production case, reproduced exactly: 27541 was printed on a document,
    // 22541 exists, and they are one digit apart. MASTERDATA-1 closed it as
    // DOCUMENT_NOT_ELIGIBLE. It must stay unbindable to ANY patient, forever.
    $neighbour = legacyRmeArchivablePatient();
    $neighbour->forceFill(['medical_record_number' => 'DG-TLK1-2024-22541'])->save();

    $someoneElse = legacyRmeArchivablePatient();

    foreach ([$neighbour->refresh(), $someoneElse] as $candidate) {
        $binding = srbBinding()->bind('27541', $candidate);

        expect($binding->bound)->toBeFalse()
            ->and($binding->code)->toBe(LegacyRmeSourceRmFailure::SOURCE_RM_NOT_FOUND)
            ->and($binding->patientId)->toBeNull();
    }

    // And the full canonical form is no more bindable than the bare one.
    expect(srbBinding()->bind('DG-TLK1-2024-27541', $neighbour->refresh())->bound)->toBeFalse();
});

it('refuses when the branch the document asserts contradicts the patient Nomor RM', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    legacyRmeBranch('LDK2', 'Cabang Landak');

    $manual = explode('-', $patient->medical_record_number, 4)[3];

    // Same manual number, a branch the patient does not belong to. It resolves
    // to nobody, so the refusal is a miss rather than a branch conflict — the
    // point is only that it can never bind.
    expect(srbBinding()->bind('DG-LDK2-2024-'.$manual, $patient)->bound)->toBeFalse();
});

it('never discloses which patient a refused Nomor RM belongs to', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient(['name' => 'Pasien Rahasia']);

    $binding = srbBinding()->bind($other->medical_record_number, $selected);
    $context = $binding->auditContext();
    $payload = json_encode($context).($binding->message ?? '');

    // The result carries no identity for the patient the number really names —
    // not the id, not the name, and no key that could later be read as one.
    expect($binding->patientId)->toBeNull()
        ->and($context)->not->toHaveKey('patient_id')
        ->and($context)->not->toHaveKey('resolved_patient_id')
        ->and($payload)->not->toContain('Pasien Rahasia');

    // The operator-facing message names no patient at all: it says what to do,
    // not who the number belongs to.
    expect($binding->message)->toBe(
        LegacyRmeSourceRmFailure::explain(LegacyRmeSourceRmFailure::SOURCE_RM_PATIENT_MISMATCH)
    );
});

/*
|--------------------------------------------------------------------------
| The write path — a refused binding costs nothing
|--------------------------------------------------------------------------
*/

it('creates nothing at all when the document names a different patient', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    $before = srbFootprint();

    expect(fn () => srbUpload($selected, $other->medical_record_number))
        ->toThrow(ValidationException::class);

    expect(srbFootprint())->toBe($before);

    // Nothing queued either: a wrong-patient document never becomes work.
    Bus::assertNothingDispatched();
});

it('consumes no migration quota when the binding is refused', function () {
    // WITH A REAL RUNNING WAVE, so the assertion is not vacuous: without one the
    // operations layer is not enforced and no quota bucket is touched by ANY
    // upload, refused or not.
    $selected = legacyRmeArchivablePatient([], 'TLK1');
    $other = legacyRmeArchivablePatient([], 'TLK1');

    $actor = superAdmin();
    legacyRmeMigrationWave(['TLK1']);
    legacyRmeAssignOperator($actor, 'TLK1');

    $consumed = fn (): int => (int) LegacyRmeMigrationQuota::query()->sum('consumed');

    expect($consumed())->toBe(0);

    // The gate runs BEFORE the transaction that reserves quota, so a refused
    // wrong-patient binding cannot cost a branch a migration slot. This is the
    // ordering claim, asserted rather than argued.
    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $selected,
        '2015-01-01',
        $other->medical_record_number,
        null,
        legacyRmePdfUpload('refused.pdf', 2),
        $actor,
    ))->toThrow(ValidationException::class);

    expect($consumed())->toBe(0)
        ->and(LegacyRmeImport::count())->toBe(0);

    // ...and a CORRECT binding still charges exactly one, so the test cannot
    // pass because quota was silently disabled.
    app(LegacyRmeImportService::class)->createFromUpload(
        $selected,
        '2015-01-01',
        $selected->medical_record_number,
        null,
        legacyRmePdfUpload('accepted.pdf', 3),
        $actor,
    );

    expect($consumed())->toBe(1);
});

it('refuses a wrong patient even when the request never touched a form', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    // The form request validates SHAPE only. Calling the service directly is
    // what proves the rule is not an HTTP-boundary decoration — and it is the
    // same call a future job, command or refactor would make.
    try {
        srbUpload($selected, $other->medical_record_number);
        expect(false)->toBeTrue('the service accepted a wrong-patient binding');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain(LegacyRmeSourceRmFailure::FIELD);
    }
});

it('refuses a wrong patient submitted straight to the HTTP endpoint', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.store'), [
            'patient_id' => $selected->getKey(),
            'selected_rme_date' => '2015-01-01',
            'source_rm_raw' => $other->medical_record_number,
            'document' => legacyRmePdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
            'source_rm_confirmation' => '1',
        ])
        ->assertSessionHasErrors(LegacyRmeSourceRmFailure::FIELD);

    expect(LegacyRmeImport::count())->toBe(0);
});

it('requires the source RM and its attestation at the HTTP boundary', function (array $omit) {
    $patient = legacyRmeArchivablePatient();

    $payload = [
        'patient_id' => $patient->getKey(),
        'selected_rme_date' => '2015-01-01',
        'source_rm_raw' => $patient->medical_record_number,
        'document' => legacyRmePdfUpload(),
        'patient_confirmation' => '1',
        'date_confirmation' => '1',
        'source_rm_confirmation' => '1',
    ];

    foreach ($omit as $key) {
        unset($payload[$key]);
    }

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.store'), $payload)
        ->assertSessionHasErrors($omit);

    expect(LegacyRmeImport::count())->toBe(0);
})->with([
    'no source RM' => [['source_rm_raw']],
    'no attestation' => [['source_rm_confirmation']],
]);

it('records an accepted binding on the staging row and in the trail', function () {
    $patient = legacyRmeArchivablePatient();

    $import = srbUpload($patient, '  '.strtolower($patient->medical_record_number).'  ');

    expect($import->source_rm_normalized)->toBe($patient->medical_record_number)
        ->and($import->source_rm_raw)->toBe(strtolower($patient->medical_record_number))
        ->and($import->source_rm_resolution)->toBe('EXACT_UNIQUE');

    $log = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::IMPORT_CREATED)
        ->latest('id')
        ->first();

    expect(json_encode($log))->toContain('source_rm_normalized');
});

it('audits a refused binding as its own action, PII-free', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient(['name' => 'Pasien Rahasia']);

    expect(fn () => srbUpload($selected, $other->medical_record_number))
        ->toThrow(ValidationException::class);

    $log = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::SOURCE_RM_BINDING_REJECTED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_encode($log);

    expect($payload)->toContain(LegacyRmeSourceRmFailure::SOURCE_RM_PATIENT_MISMATCH)
        ->and($payload)->not->toContain('Pasien Rahasia')
        // The refused number resolved to somebody. The trail must not say who.
        ->and($payload)->not->toContain('"patient_id":'.$other->getKey());
});

/*
|--------------------------------------------------------------------------
| Revalidation — the master data can move after acceptance
|--------------------------------------------------------------------------
*/

it('refuses to review or publish once the binding no longer holds', function (string $action) {
    $patient = legacyRmeArchivablePatient();
    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    if ($action === 'publish') {
        $import->update(['status' => LegacyRmeImportStatus::REVIEWED]);
    }

    // The patient's Nomor RM is corrected AFTER staging. The stored source RM is
    // immutable, so the document now asserts an identity the master data no
    // longer agrees with.
    $patient->forceFill(['medical_record_number' => 'DG-TLK1-2024-70001'])->save();

    $service = app(LegacyRmePublishService::class);

    expect(fn () => $action === 'review'
        ? $service->review($import->refresh(), superAdmin())
        : $service->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->not->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and(LegacyRmeRecord::count())->toBe(0);
})->with(['review', 'publish']);

it('refuses to publish a pre-enforcement import instead of freezing an unverifiable binding', function () {
    $patient = legacyRmeArchivablePatient();

    $import = LegacyRmeImport::factory()
        ->reviewed()
        ->withoutSourceRm()
        ->create([
            'patient_id' => $patient->getKey(),
            'origin_branch_id' => $patient->branch_id,
        ]);

    expect($import->source_rm_normalized)->toBeNull();

    try {
        app(LegacyRmePublishService::class)->publish($import, [], superAdmin());
        expect(false)->toBeTrue('a pre-enforcement import was published');
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))
            ->toContain(LegacyRmeSourceRmFailure::explain(LegacyRmeSourceRmFailure::SOURCE_RM_CAPTURE_MISSING));
    }

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses a RETRY once the binding no longer holds', function () {
    $patient = legacyRmeArchivablePatient();

    // A FAILED import is the real retry case: the render died and an operator
    // wants the worker to try again.
    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'status' => LegacyRmeImportStatus::FAILED,
        'source_disk' => app(LegacyRmeStorageService::class)->diskName(),
    ]);

    Storage::disk('legacy_rme_private')->put((string) $import->source_pdf_path, 'pdf');

    // The patient's Nomor RM is corrected after staging, so the immutable source
    // RM now asserts an identity the master data contradicts.
    $patient->forceFill(['medical_record_number' => 'DG-TLK1-2024-70004'])->save();

    // A retry restarts render WORK on a document. Doing that for a document
    // whose subject is now in doubt is the wrong direction — the operator
    // cancels and re-imports instead.
    expect(fn () => app(LegacyRmeImportProcessingService::class)
        ->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin()))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED);
    Bus::assertNothingDispatched();
});

it('still allows CANCEL on an import whose binding has gone stale', function () {
    $patient = legacyRmeArchivablePatient();

    $stale = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);
    $preEnforcement = LegacyRmeImport::factory()->readyForReview()->withoutSourceRm()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    $patient->forceFill(['medical_record_number' => 'DG-TLK1-2024-70002'])->save();

    // Cancel is the SAFETY VALVE the refusals depend on: every "cancel and
    // re-import" instruction this domain gives is a lie if cancel is gated by
    // the same rule that produced the refusal.
    foreach ([$stale, $preEnforcement] as $import) {
        app(LegacyRmeImportProcessingService::class)
            ->cancel($import->refresh(), superAdmin());

        expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED);
    }
});

it('carries the asserted source RM onto the published record', function () {
    $patient = legacyRmeArchivablePatient();
    $import = LegacyRmeImport::factory()->reviewed()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        // The factory's generic 'local' disk is not the governed private disk,
        // and publishing (rightly) refuses to promote from an unsafe one.
        'source_disk' => app(LegacyRmeStorageService::class)->diskName(),
    ]);

    app(LegacyRmeImportRepositoryInterface::class)
        ->upsertPage($import, 1, [
            'status' => LegacyRmeImportPageStatus::READY,
            'width' => 1200,
            'height' => 1600,
            'dpi' => 200,
            'background_disk' => 'legacy_rme_private',
            'background_path' => 'rme-legacy/example/page-1.png',
            'background_sha256' => hash('sha256', 'page-1'),
        ]);

    Storage::disk('legacy_rme_private')->put((string) $import->source_pdf_path, 'pdf');
    Storage::disk('legacy_rme_private')->put('rme-legacy/example/page-1.png', 'png');
    $import->update(['page_count' => 1]);

    $record = app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin());

    expect($record->source_rm_normalized)->toBe($patient->medical_record_number)
        ->and($record->source_rm_raw)->toBe($patient->medical_record_number);
});

/*
|--------------------------------------------------------------------------
| The command line is the same door — LEGACY-RME-OPS-CLI-1 convergence
|--------------------------------------------------------------------------
*/

it('refuses a stale binding on the CLI exactly as the browser does', function () {
    $patient = legacyRmeArchivablePatient();
    $import = LegacyRmeImport::factory()->reviewed()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    $patient->forceFill(['medical_record_number' => 'DG-TLK1-2024-70003'])->save();

    // OPS-CLI-1's whole safety argument is that the command line is an ADAPTER
    // over the same canonical services, never a second set of rules. A new gate
    // is only genuinely enforced if it reaches the CLI without being wired
    // there — which is exactly what this asserts.
    $this->artisan('legacy-rme:import-admin', [
        'action' => 'publish',
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ])->assertFailed();

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('answers the binding question read-only from the command line', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    $before = srbFootprint();

    // A correct binding is accepted...
    $this->artisan('legacy-rme:source-rm-binding-check', [
        '--source-rm' => $selected->medical_record_number,
        '--patient' => $selected->getKey(),
    ])->assertSuccessful();

    // ...a wrong one is refused, and the non-zero exit is what makes a
    // post-deploy probe scriptable: an exit 0 HERE would be the alarm.
    $this->artisan('legacy-rme:source-rm-binding-check', [
        '--source-rm' => $other->medical_record_number,
        '--patient' => $selected->getKey(),
    ])->assertFailed();

    // The RM 27541 probe, runnable against production without creating a thing.
    $this->artisan('legacy-rme:source-rm-binding-check', [
        '--source-rm' => '27541',
        '--patient' => $selected->getKey(),
    ])->assertFailed();

    expect(srbFootprint())->toBe($before);
});

it('normalizes on the command line without consulting patient data', function () {
    $this->artisan('legacy-rme:source-rm-binding-check', [
        '--source-rm' => ' dg-tlk1-2024-9985 ',
        '--normalize-only' => true,
    ])->expectsOutputToContain('DG-TLK1-2024-9985')->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Bounded cost — identity resolution must not read the patient master
|--------------------------------------------------------------------------
*/

it('resolves identity with a bounded, constant number of queries', function () {
    $patient = legacyRmeArchivablePatient();

    // A realistic neighbourhood, so a full-table strategy would show up as a
    // growing query count rather than a growing row count.
    Patient::factory()->count(25)->create(['branch_id' => $patient->branch_id]);

    DB::enableQueryLog();
    srbBinding()->bind($patient->medical_record_number, $patient);
    $exact = count(DB::getQueryLog());

    DB::flushQueryLog();
    srbBinding()->bind('DG-TLK1-2024-999999', $patient);
    $miss = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The MASTERDATA-1 diagnostic runs a bounded near-miss scan on top of
    // identity. The write path must not: a handful of queries either way, and
    // an exact hit must never cost more than a miss.
    expect($exact)->toBeLessThanOrEqual(4)
        ->and($miss)->toBeLessThanOrEqual(6);
});

/*
|--------------------------------------------------------------------------
| The archive boundary is unchanged
|--------------------------------------------------------------------------
*/

it('creates no clinical, billing, lab or SATUSEHAT state on either outcome', function () {
    $selected = legacyRmeArchivablePatient();
    $other = legacyRmeArchivablePatient();

    expect(fn () => srbUpload($selected, $other->medical_record_number))
        ->toThrow(ValidationException::class);

    srbUpload($selected, $selected->medical_record_number);

    expect(ClinicVisit::count())->toBe(0)
        ->and(MedicalRecord::count())->toBe(0)
        ->and(RmeInvoice::count())->toBe(0)
        ->and(RmePayment::count())->toBe(0)
        ->and(LabOrder::count())->toBe(0)
        ->and(LabCaseCandidate::count())->toBe(0)
        ->and(SatusehatCandidate::count())->toBe(0);
});
