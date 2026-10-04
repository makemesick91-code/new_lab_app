<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — the HTTP boundary.
 *
 * §13 and §19. These pin that the batch publish surfaces add no authorization
 * of their own and relax none: the route permission IS the canonical publish
 * permission, a run is reachable only by the publisher who opened it and only
 * through its own archive's route group, an id the actor cannot act on is
 * refused rather than acted on, the selection is bounded, and the migration
 * capability switch still closes the endpoint.
 */

use App\Modules\LegacyImport\BatchPublish\Controllers\LegacyBatchPublishController;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/../LegacyBatchReview/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| Reachability and refusal — §13
|--------------------------------------------------------------------------
*/

it('lets a publisher holding the canonical publish permission open the workspace', function () {
    $this->actingAs(lbpPublisher())
        ->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertOk()
        ->assertSee('Batch Publish Legacy RME');
});

it('refuses an actor who may review but not publish', function () {
    // No batch-publish super-permission exists, so "can review" must not imply
    // "can batch publish" — the route carries the publish permission verbatim.
    $this->actingAs(userWith(['view_legacy_rme_imports', 'review_legacy_rme_imports']))
        ->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertForbidden();
});

it('refuses an actor who may only view the archive', function () {
    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertForbidden();
});

it('refuses a guest', function () {
    $this->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertRedirect(route('login'));
});

it('closes the endpoint entirely when the migration capability is off', function () {
    // 404 rather than 403, matching the canonical controllers: a 403 would
    // confirm the endpoint exists.
    legacyRmeArchiveFlag(false);

    $this->actingAs(lbpPublisher())
        ->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertNotFound();
});

it('keeps the two archives on separate publish permissions', function () {
    $this->actingAs(lbpOdontogramPublisher())
        ->get(route('settings.rme.legacy-publish-imports.index'))
        ->assertForbidden();

    $this->actingAs(lbpPublisher())
        ->get(route('settings.rme.legacy-publish-odontograms.index'))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Run addressing
|--------------------------------------------------------------------------
*/

it('opens a run and lands the publisher on it', function () {
    $publisher = lbpPublisher();

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.store'))
        ->assertRedirect();

    $run = LegacyBatchPublishRun::sole();
    expect($run->started_by)->toBe($publisher->getKey());

    $this->actingAs($publisher)
        ->get(route('settings.rme.legacy-publish-imports.show', $run->uuid))
        ->assertOk();
});

it('hides another publisher\'s run behind a 404', function () {
    $owner = lbpPublisher();
    $other = lbpPublisher();

    $this->actingAs($owner)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $this->actingAs($other)
        ->get(route('settings.rme.legacy-publish-imports.show', $run->uuid))
        ->assertNotFound();

    // And cannot drive it either.
    $this->actingAs($other)
        ->post(route('settings.rme.legacy-publish-imports.publish', $run->uuid))
        ->assertNotFound();
});

it('refuses to open an odontogram run through the RME route group', function () {
    // Cross-archive IDOR: the uuid is real, but not for this route group.
    $publisher = userWith([
        'view_legacy_rme_imports', 'publish_legacy_rme_imports',
        'view_legacy_odontogram_imports', 'publish_legacy_odontogram_imports',
    ]);

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-odontograms.store'));
    $odontogramRun = LegacyBatchPublishRun::sole();

    $this->actingAs($publisher)
        ->get(route('settings.rme.legacy-publish-imports.show', $odontogramRun->uuid))
        ->assertNotFound();
});

it('rejects a run handle that is not a uuid at the route level', function () {
    $this->actingAs(lbpPublisher())
        ->get('/settings/rme/legacy-publish-imports/1')
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Selection and publish over HTTP
|--------------------------------------------------------------------------
*/

it('selects and publishes through the HTTP boundary', function () {
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.select', $run->uuid), [
            'import_ids' => [$import->getKey()],
        ])
        ->assertRedirect();

    // Selection alone publishes NOTHING.
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect(LegacyRmeRecord::count())->toBe(0);

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.publish', $run->uuid), [
            'title' => 'Arsip RME Lama',
        ])
        ->assertRedirect();

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect(LegacyRmeRecord::sole()->title)->toBe('Arsip RME Lama');
});

it('refuses a selection naming a document that does not exist', function () {
    $publisher = superAdmin();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $response = $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.select', $run->uuid), [
            'import_ids' => [987654],
        ])
        ->assertRedirect();

    // NO attempt row is persisted, and that is the correct behaviour rather
    // than a gap: the item table's foreign keys refuse a row pointing at a
    // non-existent clinical import, and inventing one would trade real
    // referential integrity for bookkeeping. The operator is still told, in the
    // selection summary.
    expect(LegacyBatchPublishItem::count())->toBe(0);
    $response->assertSessionHas('status', fn (string $flash): bool => str_contains($flash, 'tidak tersedia'));

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses an odontogram document id submitted to the RME selection endpoint', function () {
    // Cross-archive IDOR on the item rather than the run. The id is real in the
    // other archive, so it must resolve to nothing here.
    $publisher = userWith([
        'view_legacy_rme_imports', 'publish_legacy_rme_imports',
        'view_legacy_odontogram_imports', 'publish_legacy_odontogram_imports',
    ]);

    $odontogram = lbpOdontogramReviewed();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.select', $run->uuid), [
            'import_ids' => [$odontogram->getKey()],
        ])
        ->assertRedirect();

    // Nothing selected, nothing published, and BOTH archives untouched.
    expect(LegacyBatchPublishItem::count())->toBe(0);
    expect(LegacyOdontogramRecord::count())->toBe(0);
    expect(LegacyRmeRecord::count())->toBe(0);
    expect($odontogram->refresh()->status)
        ->toBe(LegacyOdontogramImportStatus::REVIEWED);
});

it('rejects an empty or oversized selection at the request boundary', function () {
    $publisher = superAdmin();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.select', $run->uuid), ['import_ids' => []])
        ->assertSessionHasErrors('import_ids');

    $this->actingAs($publisher)
        ->post(route('settings.rme.legacy-publish-imports.select', $run->uuid), [
            'import_ids' => range(1, LegacyBatchPublishRunService::MAX_SELECTION + 1),
        ])
        ->assertSessionHasErrors('import_ids');

    expect(LegacyBatchPublishItem::count())->toBe(0);
});

it('publishes exactly once when confirm is double-posted', function () {
    // §8(E) replay: the same confirm request sent twice.
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $this->actingAs($publisher)->post(
        route('settings.rme.legacy-publish-imports.select', $run->uuid),
        ['import_ids' => [$import->getKey()]]
    );

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.publish', $run->uuid));
    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.publish', $run->uuid));

    expect(LegacyRmeRecord::where('source_import_id', $import->getKey())->count())->toBe(1);
    expect(LegacyRmeRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Denial of service and privacy — §19
|--------------------------------------------------------------------------
*/

it('clamps a request-supplied page size instead of honouring it', function () {
    $publisher = superAdmin();

    foreach (range(1, 3) as $ignored) {
        lbpRmeReviewed();
    }

    $paginator = $this->actingAs($publisher)
        ->get(route('settings.rme.legacy-publish-imports.index', ['per_page' => 500000]))
        ->assertOk()
        ->viewData('paginator');

    expect($paginator->perPage())->toBe(LegacyBatchPublishController::MAX_PER_PAGE);

    $small = $this->actingAs($publisher)
        ->get(route('settings.rme.legacy-publish-imports.index', ['per_page' => 2]))
        ->viewData('paginator');

    expect($small->perPage())->toBe(2);
});

it('never renders a patient identity number in the publish workspace', function () {
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $import->patient->forceFill(['ktp_number' => '7371010101010001'])->save();

    $this->actingAs($publisher)->post(route('settings.rme.legacy-publish-imports.store'));
    $run = LegacyBatchPublishRun::sole();

    $response = $this->actingAs($publisher)
        ->get(route('settings.rme.legacy-publish-imports.show', $run->uuid))
        ->assertOk();

    $response->assertDontSee('7371010101010001');
    // The medical record number is the identifier a legacy workspace shows.
    $response->assertSee($import->patient->medical_record_number);
});
