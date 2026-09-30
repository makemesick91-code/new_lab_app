<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §36, §44 — discoverability and
 * authorization.
 *
 * The brief's central warning is "do not merely add a dead sidebar link", so
 * this suite refuses to accept a link as proven until the route behind it
 * actually answers. Every menu entry is checked three ways: it renders for
 * someone entitled, it is absent for someone who is not, and a direct request
 * to its target is refused for that second person.
 */
beforeEach(function (): void {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    legacyMassUploadFakeDisk();
});

/** Someone entitled to BOTH bulk surfaces. */
function lmuBothUploader(): User
{
    return userWith([
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ]);
}

/**
 * Someone with an unrelated clinical permission and nothing legacy.
 *
 * Not a permissionless user: a user with no permissions at all could pass these
 * assertions for the wrong reason (blocked somewhere earlier). This actor can
 * log in and reach pages, and is refused specifically on the legacy surfaces.
 */
function lmuOutsider(): User
{
    return userWith(['view_clinic_visits']);
}

/*
|--------------------------------------------------------------------------
| §44.1–4 — all four entries render for an entitled operator
|--------------------------------------------------------------------------
*/

it('shows both single upload and both mass upload entries to an entitled operator', function (): void {
    $response = $this->actingAs(lmuBothUploader())
        ->get(route('settings.rme.legacy-mass-imports.index'));

    $response->assertOk();

    // The two pre-existing entries must still be there — mass upload is
    // additive and must not replace the single surfaces (§2).
    $response->assertSee('Upload Legacy RME', false);
    $response->assertSee('Upload Legacy Odontogram', false);

    // And the two new ones.
    $response->assertSee('Mass Upload Legacy RME', false);
    $response->assertSee('Mass Upload Legacy Odontogram', false);

    // Both new links point at real named routes.
    $response->assertSee(route('settings.rme.legacy-mass-imports.index'), false);
    $response->assertSee(route('settings.rme.legacy-mass-odontograms.index'), false);
});

it('keeps the single upload links pointing at their original routes', function (): void {
    $response = $this->actingAs(lmuBothUploader())
        ->get(route('settings.rme.legacy-mass-imports.index'));

    $response->assertOk()
        ->assertSee(route('settings.rme.legacy-imports.index'), false)
        ->assertSee(route('settings.rme.legacy-odontograms.index'), false);
});

/*
|--------------------------------------------------------------------------
| §44.5–6 — invisible and unreachable without the permission
|--------------------------------------------------------------------------
*/

it('hides both mass entries from a user without the upload permissions', function (): void {
    $response = $this->actingAs(lmuOutsider())->get(route('dashboard'));

    $response->assertDontSee('Mass Upload Legacy RME', false);
    $response->assertDontSee('Mass Upload Legacy Odontogram', false);
});

it('refuses direct access to every mass route for an unauthorized user', function (): void {
    // The sidebar is never the boundary. Each route is gated server-side.
    $outsider = lmuOutsider();

    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-imports.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-imports.create'))->assertForbidden();
    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-imports.manifest-template'))->assertForbidden();
    $this->actingAs($outsider)->post(route('settings.rme.legacy-mass-imports.store'))->assertForbidden();

    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-odontograms.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-odontograms.create'))->assertForbidden();
    $this->actingAs($outsider)->get(route('settings.rme.legacy-mass-odontograms.manifest-template'))->assertForbidden();
    $this->actingAs($outsider)->post(route('settings.rme.legacy-mass-odontograms.store'))->assertForbidden();
});

it('refuses the rme mass surface to an odontogram only uploader and vice versa', function (): void {
    // The two permissions are independent. Holding one must not grant the other.
    $rmeOnly = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $odontoOnly = userWith(['view_legacy_odontogram_imports', 'create_legacy_odontogram_imports']);

    $this->actingAs($rmeOnly)->get(route('settings.rme.legacy-mass-imports.index'))->assertOk();
    $this->actingAs($rmeOnly)->get(route('settings.rme.legacy-mass-odontograms.index'))->assertForbidden();

    $this->actingAs($odontoOnly)->get(route('settings.rme.legacy-mass-odontograms.index'))->assertOk();
    $this->actingAs($odontoOnly)->get(route('settings.rme.legacy-mass-imports.index'))->assertForbidden();
});

it('requires authentication for the mass surfaces', function (): void {
    $this->get(route('settings.rme.legacy-mass-imports.index'))->assertRedirect();
    $this->get(route('settings.rme.legacy-mass-odontograms.index'))->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| §35, §44.7–8 — active state isolation
|--------------------------------------------------------------------------
*/

it('marks the rme mass entry active without lighting up the single upload entry', function (): void {
    /*
     * THE TRAP THIS TEST EXISTS FOR.
     *
     * The single-upload menu marks itself active with
     * routeIs('settings.rme.legacy-imports.*'). Had the mass routes been named
     * `settings.rme.legacy-imports.mass.*`, that wildcard would match and the
     * SINGLE entry would appear active while the operator was on a mass page.
     * The disjoint `legacy-mass-imports` prefix is what prevents it, and this
     * assertion is what would catch a future rename that reintroduced it.
     */
    $html = $this->actingAs(lmuBothUploader())
        ->get(route('settings.rme.legacy-mass-imports.index'))
        ->assertOk()
        ->getContent();

    expect(lmuAnchorFor($html, route('settings.rme.legacy-mass-imports.index')))
        ->toContain('menu-subitem-active');

    expect(lmuAnchorFor($html, route('settings.rme.legacy-imports.index')))
        ->toContain('menu-subitem-inactive')
        ->not->toContain('menu-subitem-active');

    // The sibling mass surface is also idle.
    expect(lmuAnchorFor($html, route('settings.rme.legacy-mass-odontograms.index')))
        ->toContain('menu-subitem-inactive');
});

it('marks the odontogram mass entry active without lighting up its single upload entry', function (): void {
    $html = $this->actingAs(lmuBothUploader())
        ->get(route('settings.rme.legacy-mass-odontograms.index'))
        ->assertOk()
        ->getContent();

    expect(lmuAnchorFor($html, route('settings.rme.legacy-mass-odontograms.index')))
        ->toContain('menu-subitem-active');

    expect(lmuAnchorFor($html, route('settings.rme.legacy-odontograms.index')))
        ->toContain('menu-subitem-inactive')
        ->not->toContain('menu-subitem-active');

    expect(lmuAnchorFor($html, route('settings.rme.legacy-mass-imports.index')))
        ->toContain('menu-subitem-inactive');
});

it('keeps the mass entries idle while the operator is on a single upload page', function (): void {
    $html = $this->actingAs(lmuBothUploader())
        ->get(route('settings.rme.legacy-imports.index'))
        ->assertOk()
        ->getContent();

    expect(lmuAnchorFor($html, route('settings.rme.legacy-imports.index')))
        ->toContain('menu-subitem-active');

    expect(lmuAnchorFor($html, route('settings.rme.legacy-mass-imports.index')))
        ->toContain('menu-subitem-inactive')
        ->not->toContain('menu-subitem-active');
});

/**
 * Extract the single anchor tag whose href is exactly $url.
 *
 * Asserting on the whole page would be useless here — "menu-subitem-active"
 * appears somewhere on every page. The assertion has to be scoped to one link.
 */
function lmuAnchorFor(string $html, string $url): string
{
    $pattern = '/<a[^>]*href="'.preg_quote($url, '/').'"[^>]*>/';

    if (preg_match($pattern, $html, $matches) !== 1) {
        throw new RuntimeException('No sidebar anchor found for ['.$url.'].');
    }

    return $matches[0];
}

/*
|--------------------------------------------------------------------------
| §36 — no dead menu: the pages behind the links actually answer
|--------------------------------------------------------------------------
*/

it('serves every mass upload page without a 404 or a 500', function (): void {
    $user = lmuBothUploader();

    foreach (['settings.rme.legacy-mass-imports', 'settings.rme.legacy-mass-odontograms'] as $prefix) {
        $this->actingAs($user)->get(route($prefix.'.index'))->assertOk();
        $this->actingAs($user)->get(route($prefix.'.create'))->assertOk();
        $this->actingAs($user)->get(route($prefix.'.manifest-template'))->assertOk();
    }
});

it('serves a downloadable manifest template with the right columns per surface', function (): void {
    $user = lmuBothUploader();

    $rme = $this->actingAs($user)->get(route('settings.rme.legacy-mass-imports.manifest-template'));
    $rme->assertOk();
    expect($rme->streamedContent())
        ->toContain('rme_date_earliest')
        ->toContain('rme_date_latest')
        ->not->toContain('document_date');

    $odonto = $this->actingAs($user)->get(route('settings.rme.legacy-mass-odontograms.manifest-template'));
    $odonto->assertOk();
    expect($odonto->streamedContent())
        ->toContain('document_date')
        ->not->toContain('rme_date_earliest');

    // Neither template may invite an identity column.
    expect($rme->streamedContent())->not->toContain('nik');
    expect($odonto->streamedContent())->not->toContain('nik');
});

it('serves a batch detail page and its report', function (): void {
    $user = lmuBothUploader();

    $batch = LegacyMassUploadBatch::factory()
        ->preflightReady(eligible: 2, blocked: 1)
        ->create(['created_by' => $user->getKey()]);

    $this->actingAs($user)->get(route('settings.rme.legacy-mass-imports.show', $batch))->assertOk();
    $this->actingAs($user)->get(route('settings.rme.legacy-mass-imports.report', $batch))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Cross-type IDOR
|--------------------------------------------------------------------------
*/

it('404s an odontogram batch requested through the rme routes', function (): void {
    /*
     * Without this the RME adapter would drive an odontogram batch and could
     * file a chart into the medical-record archive. The type is fixed by the
     * route, so a mismatched uuid must simply not exist here.
     */
    $user = lmuBothUploader();

    $odontoBatch = LegacyMassUploadBatch::factory()
        ->odontogram()
        ->preflightReady()
        ->create(['created_by' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-mass-imports.show', $odontoBatch))
        ->assertNotFound();

    // And it is reachable on its own surface.
    $this->actingAs($user)
        ->get(route('settings.rme.legacy-mass-odontograms.show', $odontoBatch))
        ->assertOk();
});

it('404s an rme batch requested through the odontogram routes', function (): void {
    $user = lmuBothUploader();

    $rmeBatch = LegacyMassUploadBatch::factory()
        ->preflightReady()
        ->create([
            'created_by' => $user->getKey(),
            'import_type' => LegacyImportType::LEGACY_RME,
        ]);

    $this->actingAs($user)
        ->get(route('settings.rme.legacy-mass-odontograms.show', $rmeBatch))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| §4, §32 — no permission widening
|--------------------------------------------------------------------------
*/

it('does not let a mass uploader publish or void anything', function (): void {
    /*
     * The whole safety story of §8 and §32: bulk INGESTION is not bulk
     * PUBLICATION. An operator who can submit 250 documents still cannot make a
     * single one of them live, and maker-checker is untouched.
     */
    $uploader = lmuBothUploader();

    expect($uploader->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($uploader->can('review_legacy_rme_imports'))->toBeFalse()
        ->and($uploader->can('void_legacy_rme_imports'))->toBeFalse()
        ->and($uploader->can('publish_legacy_odontogram_imports'))->toBeFalse()
        ->and($uploader->can('review_legacy_odontogram_imports'))->toBeFalse()
        ->and($uploader->can('void_legacy_odontogram_records'))->toBeFalse();
});

it('introduces no new permission for mass upload', function (): void {
    /*
     * Mass upload reuses create_legacy_*_imports. If a `mass_upload_*`
     * permission ever appears, someone has added a role grant to make a menu
     * visible — which is how privilege creeps — and this test is the tripwire.
     */
    $permissions = Permission::query()->pluck('name');

    foreach ($permissions as $name) {
        expect((string) $name)
            ->not->toContain('mass_upload')
            ->not->toContain('legacy_mass');
    }
});
