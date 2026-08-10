<?php

/**
 * LEGACY-RME-PDF-1B — legacy archive bytes are private, opaque, and only ever
 * reachable through the policy-gated streaming route.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Repositories\LegacyRmeImportRepository;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('public');
    Bus::fake();
});

function lrmeStoredImport(int $pages = 2): LegacyRmeImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload('arsip.pdf', $pages),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

it('stores the source pdf on the private legacy disk', function () {
    $import = lrmeStoredImport(1);

    expect($import->source_disk)->toBe('legacy_rme_private')
        ->and(Storage::disk('legacy_rme_private')->exists($import->source_pdf_path))->toBeTrue();
});

it('never writes anything to the public disk', function () {
    lrmeStoredImport(2);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('roots the legacy disk outside the framework-served local disk root', function () {
    // The `local` disk registers a `storage.local` route; anything under its
    // root could in principle be served by a signed framework URL. The legacy
    // archive must therefore live outside it entirely.
    $legacyRoot = realpath(config('filesystems.disks.legacy_rme_private.root'))
        ?: config('filesystems.disks.legacy_rme_private.root');
    $localRoot = realpath(config('filesystems.disks.local.root'))
        ?: config('filesystems.disks.local.root');

    expect(str_starts_with((string) $legacyRoot, (string) $localRoot))->toBeFalse()
        ->and(config('filesystems.disks.legacy_rme_private.serve'))->toBeFalse()
        ->and(config('filesystems.disks.legacy_rme_private.visibility'))->toBe('private')
        ->and(config('filesystems.disks.legacy_rme_private'))->not->toHaveKey('url');
});

it('refuses to use a public disk for the archive', function () {
    config()->set('legacy_rme.storage.disk', 'public');

    expect(fn () => app(LegacyRmeStorageService::class)->diskName())
        ->toThrow(LegacyRmePdfException::class);
});

it('builds opaque storage paths with no patient identity in them', function () {
    $patient = Patient::factory()->create([
        'name' => 'Budi Santoso',
        'ktp_number' => '7371'.str_repeat('9', 12),
    ]);

    $path = app(LegacyRmeStorageService::class)->sourcePath((int) $patient->id, 'abc-uuid');

    expect($path)->not->toContain('Budi')
        ->and($path)->not->toContain('Santoso')
        ->and($path)->not->toContain($patient->ktp_number)
        ->and($path)->toContain('abc-uuid');
});

it('streams the source pdf to an authorized operator', function () {
    $import = lrmeStoredImport(1);

    $response = $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('streams a rendered page image to an authorized operator', function () {
    $import = lrmeStoredImport(2);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('streams the thumbnail variant', function () {
    $import = lrmeStoredImport(2);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 1]).'?variant=thumbnail')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('never serves a page number that belongs to another import', function () {
    $first = lrmeStoredImport(2);
    $second = lrmeStoredImport(5);

    // Page 5 exists — but only on the OTHER import.
    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.pages.show', [$first->getKey(), 5]))
        ->assertNotFound();

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.pages.show', [$second->getKey(), 5]))
        ->assertOk();
});

it('returns 404 for a page that does not exist', function () {
    $import = lrmeStoredImport(1);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 99]))
        ->assertNotFound();
});

it('refuses file access to an operator outside the branch scope', function () {
    $import = lrmeStoredImport(1);

    $ownBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);

    $import->forceFill(['origin_branch_id' => $otherBranch->id])->save();

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 1]))
        ->assertNotFound();
});

it('returns 404 when the stored source file has disappeared', function () {
    $import = lrmeStoredImport(1);

    Storage::disk('legacy_rme_private')->delete($import->source_pdf_path);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()))
        ->assertNotFound();
});

it('never puts the storage path or original filename in a response header', function () {
    $import = lrmeStoredImport(1);

    $response = $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()));

    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($disposition)->not->toContain($import->source_pdf_path)
        ->and($disposition)->not->toContain('arsip.pdf')
        ->and($disposition)->toContain('arsip-rme-lama.pdf');
});

it('cleans up the stored source when the staging row cannot be created', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    // Make the staging INSERT fail after the bytes are already on disk.
    //
    // The failure is injected at the repository seam the service already
    // depends on, overriding exactly one method. Dropping the staging table is
    // NOT a portable way to do this: on PostgreSQL the DROP itself aborts with
    // SQLSTATE[2BP01], because `stg_rme_legacy_import_pages` and
    // `trx_rme_legacy_records` still reference it — so the test failed on the
    // setup instead of exercising the cleanup it exists to prove. Every other
    // repository method keeps its real behaviour, so the duplicate precheck
    // that runs before this point is untouched.
    $repository = new class extends LegacyRmeImportRepository
    {
        /** @var array<int, string> */
        public array $storedAtInsert = [];

        public function create(array $attributes): LegacyRmeImport
        {
            // Captured BEFORE the failure: without this the cleanup assertion
            // could pass simply because the bytes were never written at all.
            $this->storedAtInsert = Storage::disk('legacy_rme_private')->allFiles();

            throw new RuntimeException('staging insert failed');
        }
    };

    app()->instance(LegacyRmeImportRepositoryInterface::class, $repository);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(RuntimeException::class, 'staging insert failed');

    // The source really was stored first, and nothing survived the failure.
    expect($repository->storedAtInsert)->not->toBe([])
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe([]);
});
