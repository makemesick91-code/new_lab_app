<?php

/**
 * LEGACY-RME-PDF-1C — HTTP boundary of the review → publish → view flow.
 *
 * The sidebar is never the boundary: these tests hit the routes directly, with
 * forged ids, from the wrong branch, from the wrong role, and with the feature
 * flag off.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrme1cHttpReviewed(int $pages = 2): LegacyRmeImport
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

    return app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin())->refresh();
}

function lrme1cHttpPublished(int $pages = 2): LegacyRmeRecord
{
    return app(LegacyRmePublishService::class)->publish(lrme1cHttpReviewed($pages), [], superAdmin());
}

/*
|--------------------------------------------------------------------------
| Review + publish over HTTP
|--------------------------------------------------------------------------
*/

it('lets a super admin review then publish and lands on the published archive', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient, '2020-05-01', null, legacyRmePdfUpload('arsip.pdf', 2), superAdmin(),
    );
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('settings.rme.legacy-imports.review', $import->getKey()))
        ->assertRedirect(route('settings.rme.legacy-imports.show', $import->getKey()));

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);

    $this->actingAs($admin)
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()), ['title' => 'RM Lama'])
        ->assertRedirect(route('rme.legacy-records.show', LegacyRmeRecord::first()->getKey()));

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED)
        ->and(LegacyRmeRecord::count())->toBe(1);
});

it('refuses a publish over HTTP when the import was never reviewed', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient, '2020-05-01', null, legacyRmePdfUpload('arsip.pdf', 1), superAdmin(),
    );
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    // A permission holder is stopped by the policy's transition gate...
    $this->actingAs(userWith(['view_legacy_rme_imports', 'publish_legacy_rme_imports']))
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertForbidden();

    // ...and a Super Admin, who bypasses every policy through the single global
    // Gate::before, is still stopped by the SERVICE. The status gate is a real
    // second boundary, not just a policy formality.
    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertSessionHasErrors('status');

    expect(LegacyRmeRecord::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('produces one record when publish is posted twice', function () {
    $import = lrme1cHttpReviewed(1);
    $admin = superAdmin();

    $this->actingAs($admin)->post(route('settings.rme.legacy-imports.publish', $import->getKey()));
    // The second POST hits a now-PUBLISHED import: the policy's transition gate
    // refuses it, and no second record can exist either way.
    $this->actingAs($admin)->post(route('settings.rme.legacy-imports.publish', $import->getKey()));

    expect(LegacyRmeRecord::count())->toBe(1);
});

it('rejects review and publish for an operator without the named permission', function () {
    $import = lrme1cHttpReviewed(1);

    $this->actingAs(userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']))
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertForbidden();

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('rejects review and publish while the feature flag is off', function () {
    $import = lrme1cHttpReviewed(1);

    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()))
        ->assertNotFound();

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.review', $import->getKey()))
        ->assertNotFound();

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The published viewer
|--------------------------------------------------------------------------
*/

it('serves the published archive page, its source pdf and its rendered pages', function () {
    $record = lrme1cHttpPublished(2);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk()
        ->assertSee('Arsip RME Lama');

    $source = $this->actingAs($admin)->get(route('rme.legacy-records.source', $record->getKey()));
    $source->assertOk();
    expect($source->headers->get('Content-Type'))->toContain('application/pdf');
    // The stored filename identifies a patient's archive; a generic name is
    // returned instead.
    expect($source->headers->get('Content-Disposition'))->toContain('arsip-rme-lama.pdf');

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]).'?variant=thumbnail')
        ->assertOk();
});

it('never renders a storage path or a full ktp on the viewer', function () {
    $record = lrme1cHttpPublished(1);
    $record->patient->forceFill(['ktp_number' => '7371010101900001'])->save();

    $response = $this->actingAs(superAdmin())->get(route('rme.legacy-records.show', $record->getKey()));

    $response->assertOk()
        ->assertDontSee('7371010101900001')
        ->assertDontSee($record->source_pdf_path)
        ->assertDontSee('rme-legacy/imports');
});

it('404s an unknown archive id rather than revealing whether it exists', function () {
    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.show', 999999))
        ->assertNotFound();
});

it('cannot reach a page belonging to another archive', function () {
    // Different page counts so the two fixtures are genuinely different
    // documents — an identical PDF would be refused by 1B's duplicate guard
    // before this test could set up its two archives.
    $first = lrme1cHttpPublished(2);
    $second = lrme1cHttpPublished(3);

    // A page number that exists — but under a DIFFERENT record. The nested
    // lookup resolves pages THROUGH their record, so this can only 404 or serve
    // this record's own page, never the other archive's bytes.
    $response = $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.pages.show', [$first->getKey(), 1]));

    $response->assertOk();

    $own = $first->pages()->where('page_number', 1)->first();
    $foreign = $second->pages()->where('page_number', 1)->first();

    expect($own->background_path)->not->toBe($foreign->background_path);
});

it('404s a page number that does not exist on the archive', function () {
    $record = lrme1cHttpPublished(1);

    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 99]))
        ->assertNotFound();
});

it('hides the whole published viewer while the feature flag is off', function () {
    $record = lrme1cHttpPublished(1);

    legacyRmeArchiveFlag(false);

    $admin = superAdmin();

    $this->actingAs($admin)->get(route('rme.legacy-records.show', $record->getKey()))->assertNotFound();
    $this->actingAs($admin)->get(route('rme.legacy-records.source', $record->getKey()))->assertNotFound();
    $this->actingAs($admin)->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]))->assertNotFound();
});

it('requires authentication for the published viewer', function () {
    $record = lrme1cHttpPublished(1);

    $this->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertRedirect(route('login'));
});

it('rejects a role that holds no legacy archive permission', function () {
    $record = lrme1cHttpPublished(1);

    $this->actingAs(userInRole('Kasir'))
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();

    $this->actingAs(userInRole('Doctor'))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertForbidden();
});

it('404s an archive whose origin branch is outside the caller scope', function () {
    $record = lrme1cHttpPublished(1);

    $ownBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $record->forceFill(['origin_branch_id' => $otherBranch->getKey()])->save();

    // Holds the read permission but no governance-tier permission, and is
    // explicitly pinned to a DIFFERENT branch — so the scope resolves to their
    // own branch only. Pinning matters: without it the BranchContext fallback
    // can land on whichever branch the fixtures happened to create first, which
    // differs between database engines.
    $operator = userWith(['view_legacy_rme_imports']);
    $operator->forceFill(['branch_id' => $ownBranch->getKey()])->save();

    $this->actingAs($operator->refresh())
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('lets a branch-scoped operator open their own branch archive', function () {
    $record = lrme1cHttpPublished(1);

    $ownBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $record->forceFill(['origin_branch_id' => $ownBranch->getKey()])->save();

    $operator = userWith(['view_legacy_rme_imports']);
    $operator->forceFill(['branch_id' => $ownBranch->getKey()])->save();

    $this->actingAs($operator->refresh())
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();
});

it('keeps the staging page viewer working after the import is published', function () {
    // Publishing moves the staging pages to PUBLISHED. That screen is the
    // operator's evidence of what was reviewed, so it must stay readable —
    // "viewable" is deliberately wider than "publishable".
    $import = lrme1cHttpReviewed(2);
    $admin = superAdmin();

    $this->actingAs($admin)->post(route('settings.rme.legacy-imports.publish', $import->getKey()));

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);

    $this->actingAs($admin)
        ->get(route('settings.rme.legacy-imports.pages.show', [$import->getKey(), 1]))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk();
});

it('stops streaming the bytes of a voided archive but keeps the row auditable', function () {
    // VOID exists for a mis-filed archive — the canonical case being a document
    // attached to the WRONG patient. Continuing to serve those bytes under that
    // patient would keep serving the exact leak the void retracted.
    $record = lrme1cHttpPublished(1);
    $record->forceFill([
        'status' => LegacyRmeRecord::STATUS_VOID,
        'voided_at' => now(),
        'void_reason' => 'Salah pasien.',
    ])->save();

    // Asserted for a SUPER ADMIN specifically: Gate::before hands them every
    // ability, so a policy-only rule would not bind the very actor who operates
    // this capability. The controller enforces it independently.
    $admin = superAdmin();

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]))
        ->assertNotFound();

    // The record itself stays readable: retracted, not erased.
    $this->actingAs($admin)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk()
        ->assertSee('Salah pasien.');
});

it('audits a real page view but not every gallery thumbnail', function () {
    $record = lrme1cHttpPublished(1);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]).'?variant=thumbnail')
        ->assertOk();

    expect(DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::RECORD_PAGE_VIEWED)
        ->count())->toBe(0);

    $this->actingAs($admin)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]))
        ->assertOk();

    expect(DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::RECORD_PAGE_VIEWED)
        ->count())->toBe(1);
});

it('404s the publish endpoint with an invalid payload while the flag is off', function () {
    // Validation must not answer before the flag check, or a 422 would weakly
    // confirm that the disabled endpoint exists.
    $import = lrme1cHttpReviewed(1);

    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.publish', $import->getKey()), [
            'title' => str_repeat('x', 500),
        ])
        ->assertNotFound();

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('exposes no write route for a published archive', function () {
    $record = lrme1cHttpPublished(1);
    $admin = superAdmin();

    // The viewer is GET-only by construction: there is no update, delete or
    // republish endpoint to call.
    $this->actingAs($admin)
        ->post(route('rme.legacy-records.show', $record->getKey()))
        ->assertMethodNotAllowed();

    $this->actingAs($admin)
        ->delete(route('rme.legacy-records.show', $record->getKey()))
        ->assertMethodNotAllowed();
});
