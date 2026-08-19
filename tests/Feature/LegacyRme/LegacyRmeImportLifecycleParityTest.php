<?php

/**
 * LEGACY-RME-OPS-CLI-1 — the CLI is an adapter, not a second set of rules.
 *
 * The whole safety argument for adding an SSH entry point to a clinical
 * migration is that it converges on the SAME business path as the browser.
 * These tests are what make that a fact rather than a comment: the same shared
 * service, the same gates, the same quota semantics, the same audit trail, and
 * no downstream clinical, billing, lab or SATUSEHAT side effect from either
 * surface.
 */

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Controllers\LegacyRmeImportController;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationQuotaService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeWaveBindingService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeImportNotInScope;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleDenied;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Http\UploadedFile;
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

/**
 * A distinct source PDF on every call.
 *
 * The shared fixture emits byte-identical PDFs for a given page count, and the
 * archive refuses an identical checksum already staged against a DIFFERENT
 * patient — correctly, since that is a real operator mistake. Tests that need
 * two independent documents must therefore produce two genuinely different
 * files, exactly as production would.
 */
function lrmeParityUpload(int $pages): UploadedFile
{
    static $variant = 0;
    $variant++;

    return UploadedFile::fake()->createWithContent(
        'arsip.pdf',
        legacyRmePdfBytes($pages, 595.276 + $variant, 841.89),
    );
}

function lrmeParityReady(int $pages = 2, string $legacyDate = '2020-05-01'): LegacyRmeImport
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
        lrmeParityUpload($pages),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

function lrmeParityReviewed(int $pages = 2): LegacyRmeImport
{
    return app(LegacyRmePublishService::class)
        ->review(lrmeParityReady($pages), superAdmin())
        ->refresh();
}

/*
|--------------------------------------------------------------------------
| ONE business path — asserted structurally, not by comment
|--------------------------------------------------------------------------
*/

it('routes every HTTP lifecycle action through the shared lifecycle service', function () {
    $source = file_get_contents((new ReflectionClass(LegacyRmeImportController::class))->getFileName());

    foreach (['retry', 'cancel', 'review', 'publish'] as $action) {
        expect($source)->toContain('LegacyRmeLifecycleAction::'.strtoupper($action));
    }

    // The controller must no longer reach the canonical mutation services
    // directly: if it did, the CLI and the browser could drift apart again.
    expect($source)->not->toContain('$this->processing->')
        ->and($source)->not->toContain('$this->publishing->')
        ->and($source)->toContain('$this->lifecycle->perform(');
});

it('declares the same named permission the HTTP route requires, for every action', function () {
    // The route middleware and the CLI's gate 3 read from ONE table, so a
    // future action cannot be wired to a weaker permission on one surface.
    expect(LegacyRmeLifecycleAction::requiredPermission('cancel'))->toBe('create_legacy_rme_imports')
        ->and(LegacyRmeLifecycleAction::requiredPermission('retry'))->toBe('create_legacy_rme_imports')
        ->and(LegacyRmeLifecycleAction::requiredPermission('review'))->toBe('review_legacy_rme_imports')
        ->and(LegacyRmeLifecycleAction::requiredPermission('publish'))->toBe('publish_legacy_rme_imports');

    $routes = collect(app('router')->getRoutes())->keyBy(fn ($r) => $r->getName());

    foreach (['retry', 'cancel', 'review', 'publish'] as $action) {
        $middleware = implode(' ', $routes["settings.rme.legacy-imports.$action"]->gatherMiddleware());

        expect($middleware)->toContain(LegacyRmeLifecycleAction::requiredPermission($action));
    }
});

it('reaches the same state from HTTP and from the CLI', function (string $action, string $expected) {
    $viaHttp = $action === 'publish' ? lrmeParityReviewed() : lrmeParityReady();
    $viaCli = $action === 'publish' ? lrmeParityReviewed() : lrmeParityReady();

    if ($action === 'review') {
        // both fixtures are already READY_FOR_REVIEW
    }

    $actor = superAdmin();

    test()->actingAs($actor)->post(route("settings.rme.legacy-imports.$action", $viaHttp->getKey()));

    app(LegacyRmeImportLifecycleService::class)->perform($actor, $viaCli->getKey(), $action);

    expect($viaHttp->refresh()->status)->toBe($expected)
        ->and($viaCli->refresh()->status)->toBe($expected);
})->with([
    ['cancel', LegacyRmeImportStatus::CANCELLED],
    ['review', LegacyRmeImportStatus::REVIEWED],
    ['publish', LegacyRmeImportStatus::PUBLISHED],
]);

it('refuses an account with no legacy permission on both surfaces', function () {
    // A cashier holds no legacy permission at all. Over HTTP the route
    // middleware stops them; on the CLI the SCOPE gate fires first — with no
    // governance permission their branch set is whatever BranchContext resolves,
    // which does not contain this import, so the row is invisible before
    // authorization is even asked. Fail-closed twice over, and neither surface
    // writes anything.
    $import = lrmeParityReady();
    $cashier = userInRole('Kasir');

    // Pinned explicitly: BranchContext otherwise falls back to the first active
    // RME-enabled branch, which can coincidentally BE the import's branch and
    // make this pass for the wrong reason (it would then be the permission gate
    // refusing, which the next test covers deliberately).
    $cashier->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();
    // FIX-03 — a Kasir now works from a selected branch; pin the same LDK2 so
    // BranchContext still resolves exactly where this test intends.
    rmeMakeKasirActive($cashier, legacyRmeBranch('LDK2', 'Cabang Landak'));

    test()->actingAs($cashier)
        ->post(route('settings.rme.legacy-imports.cancel', $import->getKey()))
        ->assertForbidden();

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($cashier, $import->getKey(), LegacyRmeLifecycleAction::CANCEL))
        ->toThrow(LegacyRmeImportNotInScope::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('refuses a visible-but-unauthorized actor at the permission gate on both surfaces', function () {
    // In scope (pinned to the import's own branch) and able to READ it, but
    // holding no intake permission — so cancelling is refused on the named
    // permission the HTTP route also declares, not on visibility.
    $import = lrmeParityReady();

    $viewer = userWith(['view_legacy_rme_imports']);
    $viewer->forceFill(['branch_id' => $import->origin_branch_id])->save();

    test()->actingAs($viewer)
        ->post(route('settings.rme.legacy-imports.cancel', $import->getKey()))
        ->assertForbidden();

    try {
        app(LegacyRmeImportLifecycleService::class)
            ->perform($viewer, $import->getKey(), LegacyRmeLifecycleAction::CANCEL);
        $this->fail('The lifecycle service should have refused an unauthorized actor.');
    } catch (LegacyRmeLifecycleDenied $denied) {
        expect($denied->refusalCode)->toBe(LegacyRmeLifecycleRefusal::PERMISSION_DENIED);
    }

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('answers 404 over HTTP and IMPORT_NOT_IN_SCOPE on the CLI for an out-of-scope import', function () {
    $import = lrmeParityReady();

    $elsewhere = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $elsewhere->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    // The SAME exception type produces both: extending NotFoundHttpException is
    // what keeps the browser's long-standing 404 contract byte-for-byte intact
    // while the CLI reports a branchable code.
    test()->actingAs($elsewhere)
        ->post(route('settings.rme.legacy-imports.cancel', $import->getKey()))
        ->assertNotFound();

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($elsewhere, $import->getKey(), LegacyRmeLifecycleAction::CANCEL))
        ->toThrow(LegacyRmeImportNotInScope::class);
});

/*
|--------------------------------------------------------------------------
| Quota — cancelling does NOT hand a slot back
|--------------------------------------------------------------------------
*/

it('does not refund a consumed quota slot when an import is cancelled', function () {
    // Wave-2's rule, pinned. The quota bucket is a RESERVATION taken at intake:
    // it records that a slot was spent shaping the day's migration, and a
    // withdrawn document does not un-spend the reviewer time it already cost.
    // A CLI that quietly refunded it would let an operator loop
    // upload → cancel → upload past the ceiling the wave was approved for.
    $import = lrmeParityReady();
    $wave = app(LegacyRmeWaveBindingService::class)->resolveWave();

    $before = app(LegacyRmeMigrationQuotaService::class)->totalConsumed($wave);
    expect($before)->toBeGreaterThan(0);

    app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED)
        ->and(app(LegacyRmeMigrationQuotaService::class)->totalConsumed($wave))->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Separation of duties — one switch, both surfaces
|--------------------------------------------------------------------------
*/

it('lets an uploader publish their own import while the switch is off, exactly as today', function () {
    // Recorded honestly: this is the CURRENT behaviour on both surfaces, and
    // OPS-CLI-1 does not change it. What actually enforces maker/checker in
    // production is the ROLE split (a maker holds no publish permission at all).
    config()->set('legacy_rme_operations.require_separate_publisher', false);

    $import = lrmeParityReviewed();
    $uploader = User::find($import->uploaded_by);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('refuses the uploader on BOTH surfaces once separation of duties is switched on', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', true);

    $viaCli = lrmeParityReviewed();
    $viaHttp = lrmeParityReviewed();

    $cliUploader = User::find($viaCli->uploaded_by);
    $httpUploader = User::find($viaHttp->uploaded_by);

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($cliUploader, $viaCli->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    test()->actingAs($httpUploader)
        ->post(route('settings.rme.legacy-imports.publish', $viaHttp->getKey()))
        ->assertSessionHasErrors('actor');

    // There is deliberately no way to have this rule in the browser but not
    // over SSH: both refused, and neither produced a record.
    expect($viaCli->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($viaHttp->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('lets a different authorized account publish once separation of duties is on', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', true);

    $import = lrmeParityReviewed();
    $checker = superAdmin();

    expect((int) $import->uploaded_by)->not->toBe($checker->getKey());

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($checker, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('reports separation of duties as a blocker in a dry run rather than at apply time', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', true);

    $import = lrmeParityReviewed();
    $uploader = User::find($import->uploaded_by);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->preview($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->eligible)->toBeFalse()
        ->and($outcome->blockers)->toContain(LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

/*
|--------------------------------------------------------------------------
| No downstream clinical, billing, lab or SATUSEHAT side effect
|--------------------------------------------------------------------------
*/

it('creates no native clinical, billing or lab state from any lifecycle action', function (string $action) {
    $import = $action === 'publish' ? lrmeParityReviewed() : lrmeParityReady();

    $before = [
        'visits' => ClinicVisit::count(),
        'records' => MedicalRecord::count(),
        'odontograms' => Odontogram::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_orders' => LabOrder::count(),
    ];

    try {
        app(LegacyRmeImportLifecycleService::class)
            ->perform(superAdmin(), $import->getKey(), $action);
    } catch (ValidationException) {
        // A refusal is an acceptable outcome for this assertion — what matters
        // is that neither success nor refusal produced a downstream row.
    }

    expect(ClinicVisit::count())->toBe($before['visits'])
        ->and(MedicalRecord::count())->toBe($before['records'])
        ->and(Odontogram::count())->toBe($before['odontograms'])
        ->and(RmeInvoice::count())->toBe($before['invoices'])
        ->and(RmePayment::count())->toBe($before['payments'])
        ->and(LabOrder::count())->toBe($before['lab_orders']);
})->with(['cancel', 'review', 'publish', 'retry']);

it('creates no SATUSEHAT candidate when a legacy archive is published', function () {
    $import = lrmeParityReviewed();

    $before = DB::table('trx_satusehat_candidates')->count();

    app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    // A legacy archive is a historical PAPER document, not an encounter. It must
    // never enter the national interoperability pipeline as if it were one.
    expect(DB::table('trx_satusehat_candidates')->count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Concurrency — two operators, one document
|--------------------------------------------------------------------------
*/

it('produces exactly one record when the same import is published twice in a row', function () {
    $import = lrmeParityReviewed();
    $service = app(LegacyRmeImportLifecycleService::class);
    $actor = superAdmin();

    $first = $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);
    $second = $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    // The canonical service opens with a row lock and re-reads the status under
    // it; UNIQUE(source_import_id) is the independent database-level backstop.
    expect(LegacyRmeRecord::count())->toBe(1)
        ->and($second->recordId)->toBe($first->recordId)
        ->and($second->changed)->toBeFalse();
});

it('cannot cancel an import that has already been published', function () {
    $import = lrmeParityReviewed();
    $service = app(LegacyRmeImportLifecycleService::class);
    $actor = superAdmin();

    $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    // The publish/cancel race, resolved: PUBLISHED is terminal, so the loser of
    // the race is refused rather than quietly voiding published evidence.
    expect(fn () => $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::CANCEL))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and(LegacyRmeRecord::count())->toBe(1);
});

it('cannot publish an import that has already been cancelled', function () {
    $import = lrmeParityReviewed();
    $service = app(LegacyRmeImportLifecycleService::class);
    $actor = superAdmin();

    $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    expect(fn () => $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('cannot retry an import that has already been cancelled', function () {
    $import = lrmeParityReady();
    $service = app(LegacyRmeImportLifecycleService::class);
    $actor = superAdmin();

    $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    expect(fn () => $service->perform($actor, $import->getKey(), LegacyRmeLifecycleAction::RETRY))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED);
});

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

it('tags an HTTP action as HTTP and a CLI action as CLI', function () {
    $viaHttp = lrmeParityReady();
    $viaCli = lrmeParityReady();
    $actor = superAdmin();

    test()->actingAs($actor)->post(route('settings.rme.legacy-imports.cancel', $viaHttp->getKey()));

    app(LegacyRmeImportLifecycleService::class)
        ->perform($actor, $viaCli->getKey(), LegacyRmeLifecycleAction::CANCEL);

    $channelFor = fn (int $importId) => AuditLog::query()
        ->where('action', 'LEGACY_RME_IMPORT_CANCELLED')
        ->where('entity_id', $importId)
        ->latest('id')
        ->first()?->new_values['channel'] ?? null;

    expect($channelFor($viaHttp->getKey()))->toBe('HTTP')
        ->and($channelFor($viaCli->getKey()))->toBe('CLI');
});

it('never leaves a stale channel behind after an operation', function () {
    $import = lrmeParityReady();
    $audit = app(LegacyRmeAuditService::class);

    app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    // The channel is scoped to one operation and restored in a `finally`. A
    // long-lived queue worker must not inherit the label of whatever ran before.
    $audit->logImportEvent('LEGACY_RME_IMPORT_CREATED', $import, [], superAdmin());

    $latest = AuditLog::query()->where('action', 'LEGACY_RME_IMPORT_CREATED')->latest('id')->first();

    expect($latest->new_values)->not->toHaveKey('channel');
});

it('keeps the audit payload free of patient identifiers', function () {
    $patient = legacyRmeArchivablePatient([
        'name' => 'Budi Santoso Contoh',
        'ktp_number' => '7371015001900002',
    ]);

    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
    ]);

    app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    $payload = json_encode(
        AuditLog::query()->where('action', 'LEGACY_RME_IMPORT_CANCELLED')->latest('id')->first()->new_values
    );

    expect($payload)->not->toContain('Budi Santoso')
        ->and($payload)->not->toContain('7371015001900002')
        ->and($payload)->not->toContain((string) $patient->medical_record_number);
});
