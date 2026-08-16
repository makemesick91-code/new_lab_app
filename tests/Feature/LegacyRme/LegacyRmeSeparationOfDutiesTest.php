<?php

/**
 * LEGACY-RME-SOD-1 — the account that files a legacy document may not certify it.
 *
 * WHAT THESE TESTS ARE FOR. OPS-CLI-1 shipped the switch OFF, so the rule had
 * never been exercised as a production invariant. This suite is what makes the
 * activation defensible: it pins the rule's ONE home, proves the browser, the
 * command line and a direct service call all reach it, proves the one account
 * the role split cannot constrain (a Super Admin) is refused too, and proves a
 * refusal writes nothing anywhere.
 *
 * WHAT THEY DELIBERATELY DO NOT CLAIM. That two accounts are two humans. The
 * server can only see accounts; every assertion below is about ACCOUNT
 * separation, and the human maker/checker requirement remains an operational
 * governance control attested by a person, not a property software can prove.
 */

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
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
 * A distinct source PDF on every call — the archive refuses an identical
 * checksum staged against a different patient, so two independent documents
 * must genuinely differ, exactly as they would in production.
 */
function lrmeSodUpload(int $pages = 2): UploadedFile
{
    static $variant = 1000;
    $variant++;

    return UploadedFile::fake()->createWithContent(
        'arsip.pdf',
        legacyRmePdfBytes($pages, 595.276 + $variant, 841.89),
    );
}

/**
 * A rendered import attributed to a NAMED uploader.
 *
 * The shared `superAdmin()` helper mints a fresh account on every call, so a
 * test that wants the uploader and the actor to be the same person must hold
 * on to one user and pass it deliberately — which is the entire subject here.
 */
function lrmeSodReady(User $uploader, string $legacyDate = '2020-05-01'): LegacyRmeImport
{
    $pages = 2;

    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $legacyDate,
        null,
        lrmeSodUpload($pages),
        $uploader,
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

/** A REVIEWED import filed by `$uploader` and reviewed by a separate account. */
function lrmeSodReviewed(User $uploader): LegacyRmeImport
{
    return app(LegacyRmePublishService::class)
        ->review(lrmeSodReady($uploader), superAdmin())
        ->refresh();
}

/** Every table a legacy publish must never touch. */
function lrmeSodDownstreamCounts(): array
{
    return [
        'visits' => ClinicVisit::count(),
        'medical_records' => MedicalRecord::count(),
        'odontograms' => Odontogram::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_orders' => LabOrder::count(),
        'legacy_records' => LegacyRmeRecord::count(),
    ];
}

/*
|--------------------------------------------------------------------------
| Configuration — fail closed, on by default
|--------------------------------------------------------------------------
*/

it('enables separation of duties by default, with no environment line at all', function () {
    // The production invariant and the code default are the same thing after
    // SOD-1, so a deployment that forgets the environment line is SAFE rather
    // than silently unguarded.
    expect(config(SeparatePublisherGuard::CONFIG_KEY))->toBeTrue()
        ->and(app(SeparatePublisherGuard::class)->enabled())->toBeTrue();
});

it('resolves an unset, empty or misspelled environment value to ENABLED', function (mixed $raw, bool $expected) {
    // The failure mode that matters is a typo, not a deliberate `false`. Every
    // value that is not one of four unambiguous falsy words keeps the rule on.
    expect(SeparatePublisherGuard::resolveEnabledFromEnv($raw))->toBe($expected);
})->with([
    'unset key (typo in the name)' => [null, true],
    'empty value' => ['', true],
    'whitespace only' => ['   ', true],
    'unparseable word' => ['maybe', true],
    'truncated line' => ['tru', true],
    'array (malformed)' => [['true'], true],
    'decoded true' => [true, true],
    'decoded false' => [false, false],
    'string false' => ['false', false],
    'string FALSE' => ['FALSE', false],
    'string 0' => ['0', false],
    'string off' => ['off', false],
    'string no' => ['no', false],
    'string true' => ['true', true],
    'string 1' => ['1', true],
]);

/*
|--------------------------------------------------------------------------
| Backward compatibility — switched OFF, behaviour is exactly as before
|--------------------------------------------------------------------------
*/

it('still lets an uploader publish their own import while the switch is off', function () {
    config()->set(SeparatePublisherGuard::CONFIG_KEY, false);

    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('still lets an uploader review their own import while the switch is off', function () {
    config()->set(SeparatePublisherGuard::CONFIG_KEY, false);

    $uploader = superAdmin();
    $import = lrmeSodReady($uploader);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::REVIEW);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

it('lets a different publisher through while the switch is off, unchanged', function () {
    config()->set(SeparatePublisherGuard::CONFIG_KEY, false);

    $import = lrmeSodReviewed(superAdmin());

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| The rule itself — switched ON
|--------------------------------------------------------------------------
*/

it('refuses the uploader publishing their own document', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('refuses the uploader reviewing their own document', function () {
    // DECISION B, recorded deliberately. Reviewing is the CHECKER's duty in the
    // canonical role split — Wave-1 withheld BOTH `review` and `publish` from
    // the maker. Gating publish alone would leave "a human other than the filer
    // looked at the pages" bypassable by the one account the role split cannot
    // constrain.
    $uploader = superAdmin();
    $import = lrmeSodReady($uploader);

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::REVIEW))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($import->refresh()->reviewed_by)->toBeNull();
});

it('lets a different authorized account publish', function () {
    $import = lrmeSodReviewed(superAdmin());
    $checker = superAdmin();

    expect((int) $import->uploaded_by)->not->toBe($checker->getKey());

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($checker, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('does not restrict cancel or retry, which re-run the operator OWN intake', function () {
    // Cancel and retry carry the INTAKE permission by design: they are the
    // maker's own housekeeping, not a checker duty. Sweeping them into the rule
    // would strand an operator who needs to withdraw a document they filed.
    $uploader = superAdmin();
    $import = lrmeSodReady($uploader);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::CANCEL);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::CANCELLED);
});

it('exempts a pre-attribution row with no recorded uploader', function () {
    // Refusing it would strand a document nobody could ever publish, and
    // inventing an uploader to compare against would be a guess about who
    // filed it.
    $import = lrmeSodReviewed(superAdmin());
    DB::table($import->getTable())->where('id', $import->getKey())->update(['uploaded_by' => null]);

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('does not retroactively demand that the reviewer differ from the uploader', function () {
    // A row REVIEWED by its own uploader before activation must still have a
    // lawful way forward. Stranding it, or rewriting its attribution to satisfy
    // a rule that did not exist when it was filed, is the history-editing this
    // module forbids.
    $uploader = superAdmin();
    $import = lrmeSodReady($uploader);

    config()->set(SeparatePublisherGuard::CONFIG_KEY, false);
    app(LegacyRmePublishService::class)->review($import, $uploader);
    config()->set(SeparatePublisherGuard::CONFIG_KEY, true);

    expect((int) $import->refresh()->reviewed_by)->toBe($uploader->getKey());

    $outcome = app(LegacyRmeImportLifecycleService::class)
        ->perform(superAdmin(), $import->getKey(), LegacyRmeLifecycleAction::PUBLISH);

    expect($outcome->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| Super Admin is not a bypass — the whole reason this sprint exists
|--------------------------------------------------------------------------
*/

it('refuses a Super Admin publishing a document they filed themselves', function () {
    // The headline case. A Super Admin's global `Gate::before` bypass makes
    // every policy answer yes, so the role split — the load-bearing maker/checker
    // control — cannot constrain this one account. Separation of duties is a
    // BUSINESS invariant, not a permission check, so holding every permission
    // does not satisfy it.
    $superAdmin = superAdmin();
    $import = lrmeSodReviewed($superAdmin);

    expect($superAdmin->can('publish_legacy_rme_imports'))->toBeTrue()
        ->and($superAdmin->hasRole('Super Admin'))->toBeTrue();

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($superAdmin, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('lets a second Super Admin publish, but still applies every other rule', function () {
    // Passing the separation gate is not a licence: SOD is additive, never a
    // replacement for state, date, branch or duplicate validation.
    $import = lrmeSodReady(superAdmin());          // READY_FOR_REVIEW, never reviewed
    $checker = superAdmin();

    expect(app(SeparatePublisherGuard::class)->violates(LegacyRmeLifecycleAction::PUBLISH, $import, $checker))
        ->toBeFalse();

    // Refused anyway — the import was never reviewed, and publishing is only
    // ever reachable through REVIEWED so a human has looked at the pages first.
    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($checker, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| One gate — the browser, the command line and the service itself
|--------------------------------------------------------------------------
*/

it('refuses the uploader over HTTP and mutates nothing', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);
    $before = lrmeSodDownstreamCounts();

    test()->actingAs($uploader)
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertSessionHasErrors('actor');

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($import->refresh()->published_by)->toBeNull()
        ->and(lrmeSodDownstreamCounts())->toBe($before);
});

it('accepts a different authorized publisher over HTTP', function () {
    $import = lrmeSodReviewed(superAdmin());

    test()->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertSessionHasNoErrors();

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and(LegacyRmeRecord::count())->toBe(1);
});

it('refuses the uploader over the CLI with a non-zero exit', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    $exit = Artisan::call('legacy-rme:import-admin', [
        'action' => 'publish',
        '--import' => $import->getKey(),
        '--actor' => $uploader->getKey(),
        '--apply' => true,
        '--json' => true,
    ]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain(LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('reports separation of duties in a CLI dry run rather than at apply time', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    $exit = Artisan::call('legacy-rme:import-admin', [
        'action' => 'publish',
        '--import' => $import->getKey(),
        '--actor' => $uploader->getKey(),
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exit)->not->toBe(0)
        ->and($payload['eligible'])->toBeFalse()
        ->and($payload['blockers'])->toContain(LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

it('reports a different actor as eligible in a CLI dry run', function () {
    $import = lrmeSodReviewed(superAdmin());

    $exit = Artisan::call('legacy-rme:import-admin', [
        'action' => 'publish',
        '--import' => $import->getKey(),
        '--actor' => superAdmin()->getKey(),
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['eligible'])->toBeTrue()
        ->and($payload['blockers'])->not->toContain(LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

it('refuses the uploader calling the publish service DIRECTLY', function () {
    // THE LOWER LAYER MUST NOT BE THE WEAKER LAYER. If the rule lived only in
    // the lifecycle service, any future caller reaching this service — a job, a
    // seeder, a recovery command, a refactor — would silently escape it.
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], $uploader))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(LegacyRmeRecord::count())->toBe(0);
});

it('refuses the uploader calling the review service DIRECTLY', function () {
    $uploader = superAdmin();
    $import = lrmeSodReady($uploader);

    expect(fn () => app(LegacyRmePublishService::class)->review($import, $uploader))
        ->toThrow(ValidationException::class);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

/*
|--------------------------------------------------------------------------
| Time of check is time of use — the rule runs under the row lock
|--------------------------------------------------------------------------
*/

it('judges the LOCKED row, not the caller stale copy', function () {
    // The lifecycle service reads the import, runs five gates and only then
    // hands it to the publish service, which opens the transaction and takes
    // the lock. This test drives that exact window: the in-memory copy says the
    // row is exempt (no uploader), the row under the lock says the actor filed
    // it. Enforcement at the write itself is what makes the outcome correct.
    $actor = superAdmin();
    $import = lrmeSodReviewed(superAdmin());

    DB::table($import->getTable())->where('id', $import->getKey())->update(['uploaded_by' => null]);
    $stale = LegacyRmeImport::query()->findOrFail($import->getKey());   // exempt when read

    DB::table($import->getTable())->where('id', $import->getKey())->update(['uploaded_by' => $actor->getKey()]);

    expect(app(SeparatePublisherGuard::class)->violates(LegacyRmeLifecycleAction::PUBLISH, $stale, $actor))
        ->toBeFalse();   // the stale copy would have let this through

    expect(fn () => app(LegacyRmePublishService::class)->publish($stale, [], $actor))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A refusal writes nothing — and repeating it writes nothing either
|--------------------------------------------------------------------------
*/

it('creates no clinical, billing, lab or SATUSEHAT state when it refuses', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);
    $before = lrmeSodDownstreamCounts();

    expect(fn () => app(LegacyRmeImportLifecycleService::class)
        ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
        ->toThrow(ValidationException::class);

    expect(lrmeSodDownstreamCounts())->toBe($before);
});

it('stays idempotent across repeated refused attempts', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);
    $before = lrmeSodDownstreamCounts();

    foreach (range(1, 3) as $ignored) {
        expect(fn () => app(LegacyRmeImportLifecycleService::class)
            ->perform($uploader, $import->getKey(), LegacyRmeLifecycleAction::PUBLISH))
            ->toThrow(ValidationException::class);
    }

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and(lrmeSodDownstreamCounts())->toBe($before);
});

it('still publishes exactly once when a permitted checker submits twice', function () {
    // The pre-existing atomicity guarantee (one locked transaction plus
    // UNIQUE(source_import_id)) is untouched by adding the rule ahead of it.
    $import = lrmeSodReviewed(superAdmin());
    $checker = superAdmin();

    $first = app(LegacyRmePublishService::class)->publish($import->refresh(), [], $checker);
    $second = app(LegacyRmePublishService::class)->publish($import->refresh(), [], $checker);

    expect($second->getKey())->toBe($first->getKey())
        ->and(LegacyRmeRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The refusal trail
|--------------------------------------------------------------------------
*/

it('records a refusal reached at the write with its stable failure code', function () {
    // The refusal trail is the EXISTING one — no new audit event is invented.
    // An authorization gate refusing early stays unaudited exactly as
    // PERMISSION_DENIED and POLICY_DENIED already do; a refusal that reaches the
    // canonical service is recorded the way every other service refusal is.
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], $uploader))
        ->toThrow(ValidationException::class);

    $rejections = AuditLog::query()->where('action', LegacyRmeAuditEvent::PUBLISH_REJECTED)->get();

    expect($rejections)->toHaveCount(1)
        ->and(json_encode($rejections->first()->toArray()))
        ->toContain(LegacyRmePdfFailure::SEPARATE_PUBLISHER_REQUIRED);

    expect(AuditLog::query()->where('action', LegacyRmeAuditEvent::PUBLISHED)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The screen explains the refusal; it never IS the refusal
|--------------------------------------------------------------------------
*/

it('hides the checker actions from the uploader and says why', function () {
    $uploader = superAdmin();
    $import = lrmeSodReviewed($uploader);

    test()->actingAs($uploader)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->assertSee('Pemisahan tugas', false)
        ->assertDontSee('Publikasikan Arsip', false);
});

it('shows the publish action to a different authorized checker', function () {
    $import = lrmeSodReviewed(superAdmin());

    test()->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->assertSee('Publikasikan Arsip', false)
        ->assertDontSee('Pemisahan tugas', false);
});

/*
|--------------------------------------------------------------------------
| Structure — one home for the rule, reached by every surface
|--------------------------------------------------------------------------
*/

it('gives the enablement switch exactly one home in the application', function () {
    // The property an auditor actually wants is not "the CLI is as strict as
    // the browser today" but "there is only one rule to be as strict as". If a
    // second class ever reads the switch, it is deciding for itself whether the
    // rule applies — and that is how two surfaces drift apart.
    $readers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'require_separate_publisher')) {
            $readers[] = $file->getPathname();
        }
    }

    expect($readers)->toBe([app_path('Modules/LegacyRme/Support/SeparatePublisherGuard.php')]);
});

it('never restates the rule in a controller or a command', function () {
    // The surfaces ASK; they do not decide. Neither may reach for the uploader
    // column to make its own judgement about who is allowed to certify.
    $surfaces = [
        app_path('Modules/LegacyRme/Controllers/LegacyRmeImportController.php'),
        app_path('Console/Commands/LegacyRmeImportAdminCommand.php'),
    ];

    foreach ($surfaces as $path) {
        expect(file_get_contents($path))->not->toContain('uploaded_by');
    }
});

it('reaches the rule from the lifecycle service AND from the canonical write', function () {
    $lifecycle = file_get_contents(app_path('Modules/LegacyRme/Services/LegacyRmeImportLifecycleService.php'));
    $publishing = file_get_contents(app_path('Modules/LegacyRme/Services/LegacyRmePublishService.php'));

    expect($lifecycle)->toContain('SeparatePublisherGuard')
        ->and($lifecycle)->toContain('$this->separation->violates(')
        ->and($publishing)->toContain('SeparatePublisherGuard')
        ->and($publishing)->toContain('assertSeparationOfDuties($locked');

    // Both guarded duties, named once, so adding a third without wiring it is
    // visible rather than silent.
    expect(SeparatePublisherGuard::GUARDED_ACTIONS)->toBe([
        LegacyRmeLifecycleAction::REVIEW,
        LegacyRmeLifecycleAction::PUBLISH,
    ]);
});
