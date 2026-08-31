<?php

/**
 * LEGACY-RME-OPS-CLI-1 — the operator recovery CLI.
 *
 * Wave-2 was opened and aborted, and the operator found they could not
 * canonically withdraw or progress a staged document over SSH. These tests pin
 * the properties that make the replacement safe: it names one import, it needs a
 * real active human actor, it is a dry run unless told otherwise, it refuses
 * with a non-zero exit and a stable code, it never leaks PHI, and it never
 * becomes a way around the gates the browser enforces.
 */

use App\Modules\LabOrder\Models\AuditLog;
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
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

/**
 * Run the command and return [exitCode, decodedJson].
 *
 * Uses Artisan::call rather than Pest's expectsOutput chain because the command
 * emits ONE multi-line JSON document: `expectsOutputToContain` consumes a single
 * writeln per expectation and would silently only ever match the first line.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: array<string, mixed>}
 */
function lrmeCli(string $action, array $options = []): array
{
    $exit = Artisan::call('legacy-rme:import-admin', ['action' => $action] + $options + ['--json' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(Artisan::output(), true) ?: [];

    return [$exit, $payload];
}

function lrmeCliImport(array $overrides = []): LegacyRmeImport
{
    $patient = legacyRmeArchivablePatient();

    return LegacyRmeImport::factory()->readyForReview()->create(array_merge([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ], $overrides));
}

/**
 * A REAL, fully rendered import sitting at READY_FOR_REVIEW with its source PDF
 * and page images actually present on the fake private disk.
 *
 * The factory alone is not enough for review/publish: both re-assert that every
 * page is READY and still on disk, so a fixture that only sets a status would
 * be refused for the wrong reason and prove nothing about the CLI.
 */
function lrmeCliReadyOnDisk(int $pages = 2, string $legacyDate = '2020-05-01'): LegacyRmeImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $legacyDate,
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip.pdf', $pages),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

function lrmeCliReviewedOnDisk(int $pages = 2, string $legacyDate = '2020-05-01'): LegacyRmeImport
{
    $import = lrmeCliReadyOnDisk($pages, $legacyDate);

    return app(LegacyRmePublishService::class)->review($import, superAdmin())->refresh();
}

/*
|--------------------------------------------------------------------------
| The command exists and is discoverable from the deployed tree
|--------------------------------------------------------------------------
*/

it('registers the lifecycle command with all four actions', function () {
    expect(array_keys(Artisan::all()))->toContain('legacy-rme:import-admin');

    $definition = Artisan::all()['legacy-rme:import-admin']->getDefinition();

    expect($definition->hasOption('import'))->toBeTrue()
        ->and($definition->hasOption('actor'))->toBeTrue()
        ->and($definition->hasOption('apply'))->toBeTrue()
        ->and($definition->hasOption('json'))->toBeTrue()
        // There is deliberately NO batch selector: no --all, no --branch,
        // no --wave. A recovery tool whose blast radius is the whole wave is
        // how one wrong command becomes an incident.
        ->and($definition->hasOption('all'))->toBeFalse()
        ->and($definition->hasOption('branch'))->toBeFalse()
        ->and($definition->hasOption('wave'))->toBeFalse()
        // ...and no escape hatch that widens anything.
        ->and($definition->hasOption('force'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| An explicit, real, active actor — the SSH identity is never an authority
|--------------------------------------------------------------------------
*/

it('refuses without an actor', function () {
    $import = lrmeCliImport();

    [$exit, $out] = lrmeCli('cancel', ['--import' => $import->getKey()]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::ACTOR_REQUIRED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('refuses an unknown actor', function () {
    [$exit, $out] = lrmeCli('cancel', [
        '--import' => lrmeCliImport()->getKey(),
        '--actor' => 'nobody@example.test',
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::ACTOR_NOT_FOUND);
});

it('refuses a deactivated actor even when their permissions would allow it', function () {
    $actor = superAdmin();
    $actor->forceFill(['is_active' => false])->save();

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => lrmeCliImport()->getKey(),
        '--actor' => $actor->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::ACTOR_INACTIVE);
});

it('refuses a soft-deleted actor', function () {
    $actor = superAdmin();
    $email = $actor->email;
    $actor->delete();

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => lrmeCliImport()->getKey(),
        '--actor' => $email,
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::ACTOR_NOT_FOUND);
});

it('accepts an actor by id or by email', function () {
    $actor = superAdmin();
    $import = lrmeCliImport();

    [$byId, $idOut] = lrmeCli('cancel', ['--import' => $import->getKey(), '--actor' => $actor->getKey()]);
    [$byEmail, $emailOut] = lrmeCli('cancel', ['--import' => $import->getKey(), '--actor' => $actor->email]);

    expect($byId)->toBe(0)
        ->and($byEmail)->toBe(0)
        ->and($idOut['actor_id'])->toBe($actor->getKey())
        ->and($emailOut['actor_id'])->toBe($actor->getKey());
});

/*
|--------------------------------------------------------------------------
| Input shape
|--------------------------------------------------------------------------
*/

it('refuses an unknown action', function () {
    [$exit, $out] = lrmeCli('approve', ['--import' => 1, '--actor' => superAdmin()->getKey()]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::UNKNOWN_ACTION);
});

it('refuses a missing or non-numeric import id', function (string $value) {
    [$exit, $out] = lrmeCli('cancel', ['--import' => $value, '--actor' => superAdmin()->getKey()]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::IMPORT_REQUIRED);
})->with(['', 'abc', '0', '-3', '1; DROP TABLE users']);

it('holds the archive labels to the same bounds the HTTP FormRequest enforces', function (string $option, int $limit) {
    // A FormRequest constrains one caller, not the capability. Without this the
    // command line would be a way to write a label the browser would reject.
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::REVIEWED]);

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        "--$option" => str_repeat('a', $limit + 1),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::INVALID_ARCHIVE_LABEL);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
})->with([
    ['title', 150],
    ['description', 2000],
]);

it('accepts archive labels at exactly the boundary', function () {
    $import = lrmeCliReviewedOnDisk();

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--title' => str_repeat('a', 150),
        '--description' => str_repeat('b', 2000),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0);

    $record = LegacyRmeRecord::find($out['legacy_record_id']);

    expect(mb_strlen((string) $record->title))->toBe(150)
        ->and(mb_strlen((string) $record->description))->toBe(2000);
});

/*
|--------------------------------------------------------------------------
| Dry run — genuinely read-only
|--------------------------------------------------------------------------
*/

it('changes nothing without --apply and says so', function () {
    $import = lrmeCliImport();
    $importsBefore = LegacyRmeImport::count();
    $auditBefore = AuditLog::count();

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
    ]);

    expect($exit)->toBe(0)
        ->and($out['applied'])->toBeFalse()
        ->and($out['eligible'])->toBeTrue()
        ->and($out['changed'])->toBeFalse()
        ->and($out['target_status'])->toBe(LegacyRmeImportStatus::CANCELLED);

    // DB_DELTA = 0, AUDIT_DELTA = 0.
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and(LegacyRmeImport::count())->toBe($importsBefore)
        ->and(AuditLog::count())->toBe($auditBefore);
});

it('predicts an illegal transition for a Super Admin, whose Gate::before bypasses every policy', function () {
    // THE CASE THE DRY RUN EXISTS FOR. PUBLISHED is terminal, so cancelling is
    // impossible — but Super Admin's global `Gate::before` makes the policy
    // answer yes, so the policy predicts nothing for the very account most
    // likely to be running a 2am recovery. The transition map is consulted
    // directly for exactly this reason.
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::PUBLISHED]);
    $auditBefore = AuditLog::count();

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
    ]);

    expect($exit)->toBe(1)
        ->and($out['applied'])->toBeFalse()
        ->and($out['eligible'])->toBeFalse()
        ->and($out['blockers'])->toContain(LegacyRmeLifecycleRefusal::TRANSITION_NOT_ALLOWED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and(AuditLog::count())->toBe($auditBefore);
});

it('reports every blocker it can see, not just the first', function () {
    // A maker (intake only) asked to publish a not-yet-reviewed import: they
    // lack the named permission, the policy refuses, AND the transition is
    // illegal. An operator fixing one should not have to re-run to discover the
    // next.
    $patient = legacyRmeArchivablePatient();
    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    // Pinned to the import's own branch, so the refusal is about authority
    // rather than about the row being invisible.
    $maker = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $maker->forceFill(['branch_id' => $patient->branch_id])->save();

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => $maker->getKey(),
    ]);

    expect($exit)->toBe(1)
        ->and($out['blockers'])->toContain(LegacyRmeLifecycleRefusal::PERMISSION_DENIED)
        ->and($out['blockers'])->toContain(LegacyRmeLifecycleRefusal::POLICY_DENIED)
        ->and($out['blockers'])->toContain(LegacyRmeLifecycleRefusal::TRANSITION_NOT_ALLOWED);
});

it('hides an import outside the actor branch scope behind the same answer as a missing one', function () {
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    $elsewhere = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $elsewhere->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    [$existing, $existingOut] = lrmeCli('cancel', ['--import' => $import->getKey(), '--actor' => $elsewhere->getKey()]);
    [$missing, $missingOut] = lrmeCli('cancel', ['--import' => 999999, '--actor' => $elsewhere->getKey()]);

    // Byte-identical outcomes: an operator cannot use id probing to learn what
    // another branch has staged.
    expect($existing)->toBe(1)
        ->and($missing)->toBe(1)
        ->and($existingOut['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE)
        ->and($missingOut['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE)
        ->and($existingOut['status'])->toBeNull()
        ->and($existingOut['patient_id'])->toBeNull();

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

/*
|--------------------------------------------------------------------------
| Applying — through the canonical service, with a real audit trail
|--------------------------------------------------------------------------
*/

it('cancels through the canonical service and records a CLI-tagged audit row', function () {
    $import = lrmeCliImport();
    $actor = superAdmin();

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => $import->getKey(),
        '--actor' => $actor->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($out['applied'])->toBeTrue()
        ->and($out['changed'])->toBeTrue()
        ->and($out['previous_status'])->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($out['status'])->toBe(LegacyRmeImportStatus::CANCELLED);

    $import->refresh();

    expect($import->status)->toBe(LegacyRmeImportStatus::CANCELLED)
        // The canonical service attributes the cancellation to the named human,
        // never to "the server".
        ->and($import->cancelled_by)->toBe($actor->getKey())
        ->and($import->cancelled_at)->not->toBeNull();

    $audit = AuditLog::query()
        ->where('action', 'LEGACY_RME_IMPORT_CANCELLED')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        // Attributed to the named human, never to "the server".
        ->and((int) $audit->performed_by)->toBe($actor->getKey())
        // ...and tagged with the surface that asked, so an auditor can tell an
        // SSH recovery from a browser action.
        ->and($audit->new_values['channel'] ?? null)->toBe('CLI')
        ->and((int) ($audit->new_values['import_id'] ?? 0))->toBe($import->getKey());
});

it('reviews a rendered import from the command line', function () {
    $import = lrmeCliReadyOnDisk();

    [$exit, $out] = lrmeCli('review', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($out['status'])->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($out['changed'])->toBeTrue();
});

it('reports a repeated review as applied but unchanged', function () {
    $import = lrmeCliReadyOnDisk();
    $actor = superAdmin();

    lrmeCli('review', ['--import' => $import->getKey(), '--actor' => $actor->getKey(), '--apply' => true]);
    [$exit, $out] = lrmeCli('review', ['--import' => $import->getKey(), '--actor' => $actor->getKey(), '--apply' => true]);

    // Idempotent by design in the canonical service — the operator pressed the
    // button twice. `changed` is what tells an automated caller the difference.
    expect($exit)->toBe(0)
        ->and($out['status'])->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($out['changed'])->toBeFalse();
});

it('publishes a reviewed import and reports the produced record id', function () {
    $import = lrmeCliReviewedOnDisk();

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--title' => 'RM Lama 2020',
        '--apply' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($out['status'])->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and($out['legacy_record_id'])->not->toBeNull();

    $record = LegacyRmeRecord::find($out['legacy_record_id']);

    expect($record)->not->toBeNull()
        ->and($record->title)->toBe('RM Lama 2020')
        ->and($record->source_import_id)->toBe($import->getKey());
});

it('refuses to publish an unreviewed import for a checker, at the policy', function () {
    // Review is a real gate, not a formality, and the CLI does not get to skip
    // it because it is a recovery tool. Supervisor RME is the production checker
    // role, so the policy — not a Super Admin bypass — is what answers here.
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::READY_FOR_REVIEW]);

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => userInRole('Supervisor RME')->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::POLICY_DENIED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('still refuses to publish an unreviewed import for a Super Admin, at the canonical service', function () {
    // The account whose `Gate::before` bypasses every policy is exactly the one
    // that must not be able to skip review. The canonical publish service is the
    // backstop, and it holds.
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::READY_FOR_REVIEW]);

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::SERVICE_REFUSED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('never creates a second record when publish is repeated', function () {
    $import = lrmeCliReviewedOnDisk();
    $actor = superAdmin();

    [, $first] = lrmeCli('publish', ['--import' => $import->getKey(), '--actor' => $actor->getKey(), '--apply' => true]);
    expect(LegacyRmeRecord::count())->toBe(1);

    [$exit, $second] = lrmeCli('publish', ['--import' => $import->getKey(), '--actor' => $actor->getKey(), '--apply' => true]);

    // The canonical service is idempotent by design: it returns the record this
    // import already produced rather than failing OR duplicating. `changed`
    // is what tells an automated caller the second call was a no-op, and
    // UNIQUE(source_import_id) is the database-level backstop underneath.
    expect($exit)->toBe(0)
        ->and($second['legacy_record_id'])->toBe($first['legacy_record_id'])
        ->and($second['changed'])->toBeFalse()
        ->and(LegacyRmeRecord::count())->toBe(1);
});

it('refuses to retry a published import', function () {
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::PUBLISHED]);

    [$exit] = lrmeCli('retry', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('refuses to retry a cancelled import', function () {
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::CANCELLED]);

    [$exit] = lrmeCli('retry', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED);
});

it('retries a failed import back onto the queue', function () {
    $import = lrmeCliReadyOnDisk();
    $import->forceFill(['status' => LegacyRmeImportStatus::FAILED, 'failure_code' => 'PDF_RENDER_FAILED'])->save();

    [$exit, $out] = lrmeCli('retry', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($out['status'])->toBe(LegacyRmeImportStatus::QUEUED);
});

it('refuses to retry a failed import whose source PDF is gone', function () {
    $import = lrmeCliImport([
        'status' => LegacyRmeImportStatus::FAILED,
        'source_pdf_path' => 'rme-legacy/missing/source.pdf',
    ]);

    [$exit, $out] = lrmeCli('retry', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    // A retry that could only fail again is refused with a reason rather than
    // burning a processing cycle.
    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::SERVICE_REFUSED)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED);
});

/*
|--------------------------------------------------------------------------
| The capability flag stops the command line too
|--------------------------------------------------------------------------
*/

it('refuses every action while the migration capability is switched off', function (string $action) {
    $import = lrmeCliImport(['status' => LegacyRmeImportStatus::REVIEWED]);
    legacyRmeArchiveFlag(false);

    [$exit, $out] = lrmeCli($action, [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::FEATURE_DISABLED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
})->with(['cancel', 'review', 'publish', 'retry']);

it('refuses a dry run while the capability is off, without disclosing the import', function () {
    $import = lrmeCliImport();
    legacyRmeArchiveFlag(false);

    [$exit, $out] = lrmeCli('cancel', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
    ]);

    expect($exit)->toBe(1)
        ->and($out['refusal_code'])->toBe(LegacyRmeLifecycleRefusal::FEATURE_DISABLED)
        // The emergency stop answers before anything is read, so the reply says
        // nothing about the document.
        ->and($out['status'])->toBeNull()
        ->and($out['patient_id'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Output carries no PHI
|--------------------------------------------------------------------------
*/

it('keeps a refusal message free of patient identifiers even when it quotes clinical dates', function () {
    // The date rules legitimately quote the DATES a refusal is about — one of
    // them is the patient's date of birth — because an operator holding a
    // refusal they cannot act on is worse. The boundary is that a NAME, Nomor RM
    // or KTP/NIK never appears, and that is what is pinned here.
    $patient = legacyRmeArchivablePatient([
        'name' => 'Dewi Lestari Contoh',
        'ktp_number' => '7371015001900003',
        'date_of_birth' => '1995-06-15',
    ]);

    // A legacy date that precedes the patient's birth — the one refusal whose
    // message names a clinical date belonging to the patient.
    $import = LegacyRmeImport::factory()->reviewed()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'selected_rme_date' => '1990-01-01',
        'latest_rme_date' => '1990-01-01',
    ]);

    [$exit, $out] = lrmeCli('publish', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    $printed = json_encode($out);

    expect($exit)->toBe(1)
        ->and($printed)->not->toContain('Dewi Lestari')
        ->and($printed)->not->toContain('7371015001900003')
        ->and($printed)->not->toContain((string) $patient->medical_record_number);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('never prints a patient name, a medical record number or an identity number', function () {
    $patient = legacyRmeArchivablePatient([
        'name' => 'Siti Rahmawati Contoh',
        'ktp_number' => '7371015001900001',
    ]);

    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'original_filename' => 'RM-SITI-RAHMAWATI.pdf',
    ]);

    [, $out] = lrmeCli('cancel', [
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--apply' => true,
    ]);

    $printed = json_encode($out);

    expect($printed)->not->toContain('Siti Rahmawati')
        ->and($printed)->not->toContain('7371015001900001')
        ->and($printed)->not->toContain((string) $patient->medical_record_number)
        ->and($printed)->not->toContain('RM-SITI-RAHMAWATI')
        // Structure only: the patient is an id, and the source is a truncated
        // checksum an operator can match against their own manifest.
        ->and($out['patient_id'])->toBe($patient->getKey())
        ->and(strlen((string) $out['source_sha256_prefix']))->toBe(12);
});
