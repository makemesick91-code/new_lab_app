<?php

/**
 * LEGACY-RME-PDF-1B — who may reach the legacy archive workspace.
 *
 * The archive is a Master Data RME capability. It is behind a feature flag that
 * defaults to OFF, its own named permissions, and a per-row branch scope. The
 * sidebar is never the boundary: these tests hit the routes directly.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
});

function lrme1bImport(array $overrides = []): LegacyRmeImport
{
    return LegacyRmeImport::factory()->create(array_merge([
        'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
    ], $overrides));
}

it('lets a super admin open the archive workspace', function () {
    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertOk()
        ->assertSee('Impor Arsip RME Lama');
});

it('lets a super admin open the upload form', function () {
    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.create'))
        ->assertOk();
});

it('rejects a user holding no legacy archive permission', function () {
    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertForbidden();
});

it('rejects a doctor', function () {
    // The Sprint 66 EnsureRmeOnlineContext middleware would redirect a Doctor
    // before the request ever reaches this route. Bypass it so the assertion is
    // about THIS capability's own boundary rather than that redirect.
    $this->actingAs(userInRole('Doctor'))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertForbidden();
});

it('rejects a cashier', function () {
    $this->actingAs(userInRole('Kasir'))
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertForbidden();
});

it('does not grant the archive to Supervisor RME by default', function () {
    $user = userInRole('Supervisor RME');

    expect($user->can('view_legacy_rme_imports'))->toBeFalse()
        ->and($user->can('create_legacy_rme_imports'))->toBeFalse();
});

it('rejects an Admin Klinik', function () {
    $this->actingAs(userInRole('Admin Klinik'))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get(route('settings.rme.legacy-imports.index'))
        ->assertRedirect(route('login'));
});

it('hides the whole surface while the feature flag is off', function () {
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertNotFound();
});

it('hides the upload form while the feature flag is off', function () {
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.create'))
        ->assertNotFound();
});

it('refuses a view-only holder the create form', function () {
    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->get(route('settings.rme.legacy-imports.create'))
        ->assertForbidden();
});

it('lets a view-only holder open the index', function () {
    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertOk();
});

it('returns 404 for an import outside the caller branch scope', function () {
    $ownBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);

    $import = lrme1bImport(['origin_branch_id' => $otherBranch->id]);

    // A non-governance holder is pinned to their own BranchContext branch. Pin
    // it explicitly: BranchContext otherwise falls back to the first active
    // RME-enabled branch, which could coincidentally BE the import's branch and
    // make this assertion pass for the wrong reason.
    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertNotFound();
});

it('shows an import from the caller own branch to a non-governance holder', function () {
    $ownBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);
    $import = lrme1bImport(['origin_branch_id' => $ownBranch->id]);

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk();
});

it('hides an import with no origin branch from a non-governance holder', function () {
    $ownBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);
    $import = lrme1bImport(['origin_branch_id' => null]);

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertNotFound();
});

it('shows an import to a super admin regardless of origin branch', function () {
    $import = lrme1bImport();

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk();
});

it('offers no publish action on an import that has not been reviewed', function () {
    // 1B asserted the publish route did not exist at all. LEGACY-RME-PDF-1C
    // ships it, so the boundary that actually holds now is the STATE gate: a
    // document sitting at READY_FOR_REVIEW offers no publish form, because
    // publishing is only reachable once a human has reviewed it.
    $import = lrme1bImport();

    expect($import->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->assertDontSee('legacy-imports/'.$import->getKey().'/publish')
        ->assertDontSee('Publikasikan Arsip');

    expect(Route::has('settings.rme.legacy-imports.publish'))->toBeTrue();
});

it('never renders a patient KTP anywhere in the workspace', function () {
    $patient = legacyRmeArchivablePatient(['ktp_number' => '7371'.str_repeat('9', 12)]);
    $import = lrme1bImport(['patient_id' => $patient->id]);

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->assertDontSee('7371'.str_repeat('9', 12));

    $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertOk()
        ->assertDontSee('7371'.str_repeat('9', 12));
});

it('never exposes a storage path in the status payload', function () {
    $import = lrme1bImport();

    $payload = $this->actingAs(superAdmin())
        ->getJson(route('settings.rme.legacy-imports.status', $import->getKey()))
        ->assertOk()
        ->json();

    expect(array_keys($payload))->not->toContain('source_pdf_path', 'source_disk', 'source_pdf_sha256');
});

it('refuses a retry to a user without the create permission', function () {
    $import = lrme1bImport(['status' => LegacyRmeImportStatus::FAILED]);

    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->post(route('settings.rme.legacy-imports.retry', $import->getKey()))
        ->assertForbidden();
});

it('rejects a non-numeric import id at the route boundary', function () {
    $this->actingAs(superAdmin())
        ->get('/settings/rme/legacy-imports/not-an-id')
        ->assertNotFound();
});

it('never lets an unauthenticated visitor reach a legacy page image', function () {
    $import = lrme1bImport();

    $this->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 1]))
        ->assertRedirect(route('login'));
});

it('never lets an unauthenticated visitor reach the source pdf', function () {
    $import = lrme1bImport();

    $this->get(route('settings.rme.legacy-imports.source', $import->getKey()))
        ->assertRedirect(route('login'));
});

it('refuses an origin branch outside the uploader own scope', function () {
    // `origin_branch_id` is NOT merely descriptive: the repository filters rows
    // on it and the policy scopes on it, so a request-supplied value decides
    // which branch owns the row. A scoped operator must not be able to file a
    // document into a branch they have no authority over (and would then 404 on).
    $ownBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => true, 'is_active' => true]);

    $user = userWith(['create_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $otherBranch->id,
        legacyRmePdfUpload(),
        $user,
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::whereNotNull('source_pdf_sha256')->count())->toBe(0);
});

// ── LEGACY-RME-PDF-FIX-ROLL2-1: the branch is DERIVED, never chosen ─────────
// The three tests replaced here pinned the previous contract: a submitted id
// was trusted, a blank one was anchored to the uploader's own branch, and a
// governance-tier upload produced a branchless row. All three let a patient's
// history be filed under a branch that does not own that patient — and
// `origin_branch_id` is what decides who can read it afterwards.

it('derives the origin branch from the patient Nomor RM', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    // DG-TKM1-…  →  TKM1  →  Cabang Telkomas. Never null, never the actor's.
    expect($import->origin_branch_id)->toBe(legacyRmeBranch('TKM1')->id);
});

it('ignores nothing and refuses a submitted branch that contradicts the Nomor RM', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    legacyRmeNativeVisit($patient, '2022-03-10');

    $otherBranch = legacyRmeBranch('LDK2', 'Cabang Landak');

    // A conflicting value is REJECTED explicitly rather than silently dropped,
    // so an inconsistent operator sees the problem instead of a surprise owner.
    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $otherBranch->id,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::whereNotNull('source_pdf_sha256')->count())->toBe(0);
});

it('accepts a submitted branch that matches the RM-derived one', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        legacyRmeBranch('TKM1')->id,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    expect($import->origin_branch_id)->toBe(legacyRmeBranch('TKM1')->id);
});

it('fails closed when the patient Nomor RM names an unknown branch', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    $patient->forceFill(['medical_record_number' => 'DG-ZZZ9-2024-0001'])->save();
    legacyRmeNativeVisit($patient, '2022-03-10');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::whereNotNull('source_pdf_sha256')->count())->toBe(0);
});

it('fails closed when the patient Nomor RM is not in the canonical format', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    $patient->forceFill(['medical_record_number' => 'MRN-ABCDEFGH'])->save();
    legacyRmeNativeVisit($patient, '2022-03-10');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);
});

it('fails closed when the RM-derived branch is outside the uploader scope', function () {
    Storage::fake('legacy_rme_private');
    Bus::fake();

    // The patient belongs to TKM1; the operator is pinned to LDK2. Filing it
    // anyway would hand them a row they could never read back.
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    legacyRmeNativeVisit($patient, '2022-03-10');

    $user = userWith(['create_legacy_rme_imports']);
    $user->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload(),
        $user,
    ))->toThrow(ValidationException::class);
});

it('shows the RM-derived branch read-only on the upload form', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    legacyRmeNativeVisit($patient, '2022-03-10');

    $response = $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.create', ['patient_id' => $patient->id]))
        ->assertOk()
        ->assertSee('Cabang Telkomas')
        ->assertSee('TKM1');

    // There is no branch picker any more, and the misleading "just a note" copy
    // is gone: the field is a security property, not operator metadata.
    $html = $response->getContent();

    expect($html)->not->toContain('name="origin_branch_id"')
        ->and($html)->not->toContain('hanya catatan asal arsip');
});

it('keeps every legacy archive route behind a POST or GET verb only', function () {
    $names = [
        'settings.rme.legacy-imports.index',
        'settings.rme.legacy-imports.create',
        'settings.rme.legacy-imports.store',
        'settings.rme.legacy-imports.show',
        'settings.rme.legacy-imports.status',
        'settings.rme.legacy-imports.source',
        'settings.rme.legacy-imports.pages.show',
        'settings.rme.legacy-imports.retry',
        'settings.rme.legacy-imports.cancel',
    ];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("Route {$name} should exist")
            ->and(array_diff($route->methods(), ['GET', 'HEAD', 'POST']))->toBe([]);
    }
});
