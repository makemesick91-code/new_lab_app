<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\Patient\Models\Patient;
use App\Support\Routing\DatabaseRouteKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-3 / R-14, R-15 — a legacy archive segment that is
| not an identifier never reaches the controller.
|--------------------------------------------------------------------------
|
| Every legacy archive route takes its identifiers as PHP `int` arguments
| and carried `->whereNumber()` (`[0-9]+`):
|
|   R-14 {record}  19 routes  `int $record` -> trx_rme_legacy_records.id or
|                             trx_odontogram_legacy_records.id (bigint)
|   R-15 {page}     6 routes  `int $page`   -> page_number (unsignedInteger,
|                             PostgreSQL `integer`, 1-based, never in a key)
|
| `[0-9]+` admits a number beyond PHP's integer range (TypeError: HTTP 500
| plus an ERROR line), a leading-zero alias that PHP casts to the real
| identifier (a non-canonical URL opened — and audited — a real archive or
| page), and, for `{page}`, a number that fits PHP's integer but not int4
| (PostgreSQL 22003: HTTP 500).
|
| Both are GENERIC names, so they are constrained per route, never globally
| (rule 222 §1.6). The contract is therefore pinned on an explicit list of
| route NAMES: a future route may still use `{record}` for another identifier
| type. `{record}` reuses DatabaseRouteKey::PATTERN; `{page}` is a page
| number and uses DatabaseRouteKey::PAGE_NUMBER_PATTERN (1–9 digits, no
| leading zero), which always fits the int4 column.
|
| The settings import page routes also carry `{import}`, which keeps its
| `whereNumber` — it is outside this programme's approved scope (residual).
*/

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');

    $this->branch = Branch::factory()->create(['code' => 'LAK1', 'name' => 'Cabang Arsip Kunci', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    Storage::disk('legacy_rme_private')->put('rme-legacy/lak/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_rme_private')->put('rme-legacy/lak/source.pdf', '%PDF-1.4 fake');
    $this->rme = LegacyRmeRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'origin_branch_id' => $this->branch->id,
        'source_disk' => 'legacy_rme_private',
        'source_pdf_path' => 'rme-legacy/lak/source.pdf',
    ]);
    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $this->rme->id,
        'page_number' => 1,
        'background_disk' => 'legacy_rme_private',
        'background_path' => 'rme-legacy/lak/page-1.png',
    ]);

    Storage::disk('legacy_odontogram_private')->put('odo/lak/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_odontogram_private')->put('odo/lak/source.pdf', '%PDF-1.4 fake');
    $this->odontogram = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'branch_id' => $this->branch->id,
        'source_pdf_path' => 'odo/lak/source.pdf',
        'page_count' => 1,
    ]);
    LegacyOdontogramRecordPage::query()->forceCreate([
        'odontogram_legacy_record_id' => $this->odontogram->id,
        'page_number' => 1,
        'width' => 100,
        'height' => 100,
        'dpi' => 150,
        'rotation' => 0,
        'image_disk' => 'legacy_odontogram_private',
        'image_path' => 'odo/lak/page-1.png',
        'image_sha256' => str_repeat('c', 64),
    ]);

    $this->rmeImport = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $this->patient->id,
        'origin_branch_id' => $this->branch->id,
    ]);
    $this->odontogramImport = LegacyOdontogramImport::factory()->create([
        'patient_id' => $this->patient->id,
        'origin_branch_id' => $this->branch->id,
    ]);

    $this->admin = superAdmin();
});

/** Every route this programme constrained, by name. Generic names are never global. */
function lakRecordRouteNames(): array
{
    return [
        'rme.legacy-records.show', 'rme.legacy-records.source', 'rme.legacy-records.pages.show',
        'rme.legacy-records.print', 'rme.legacy-records.export', 'rme.legacy-records.void',
        'rme.legacy-odontograms.show', 'rme.legacy-odontograms.source', 'rme.legacy-odontograms.pages.show',
        'rme.legacy-odontograms.void',
        'oversight.legacy.rme.show', 'oversight.legacy.rme.pages.show', 'oversight.legacy.rme.source',
        'oversight.legacy.rme.print', 'oversight.legacy.rme.pdf',
        'oversight.legacy.odontogram.show', 'oversight.legacy.odontogram.pages.show',
        'oversight.legacy.odontogram.source', 'oversight.legacy.odontogram.print',
    ];
}

function lakPageRouteNames(): array
{
    return [
        'settings.rme.legacy-imports.pages.show', 'settings.rme.legacy-odontograms.pages.show',
        'rme.legacy-records.pages.show', 'rme.legacy-odontograms.pages.show',
        'oversight.legacy.rme.pages.show', 'oversight.legacy.odontogram.pages.show',
    ];
}

/** @return array<string, string> already URL-encoded segments that are not a key */
function lakNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BRECORD_ID%7D',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '007',
        'decimal' => '1.5',
        'invalid percent encoding' => '%ZZ',
        'nineteen digits' => '1000000000000000000',
        'beyond PHP integer range' => '99999999999999999999',
    ];
}

/** @return array<string, string> */
function lakNonPageSegments(): array
{
    return [
        'letters' => 'abc',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '01',
        'decimal' => '1.5',
        'invalid percent encoding' => '%ZZ',
        'ten digits' => '2147483648',
        'eleven digits' => '99999999999',
        'eighteen digits' => '999999999999999999',
        'beyond PHP integer range' => '99999999999999999999',
    ];
}

/** @return ArrayObject<int, string> */
function lakCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

it('constrains every legacy archive record route with the canonical database key pattern', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('record', $route->parameterNames(), true));

    // Measured 2026-11-05: every `{record}` route is a legacy archive route.
    expect($routes->map->getName()->sort()->values()->all())
        ->toEqualCanonicalizing(lakRecordRouteNames());

    foreach (lakRecordRouteNames() as $name) {
        expect(Route::getRoutes()->getByName($name)->wheres['record'] ?? null)
            ->toBe(DatabaseRouteKey::PATTERN, $name);
    }

    expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)->not->toContain('record');
});

it('constrains every legacy archive page route with the page number pattern', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('page', $route->parameterNames(), true));

    expect($routes->map->getName()->sort()->values()->all())
        ->toEqualCanonicalizing(lakPageRouteNames());

    foreach (lakPageRouteNames() as $name) {
        expect(Route::getRoutes()->getByName($name)->wheres['page'] ?? null)
            ->toBe(DatabaseRouteKey::PAGE_NUMBER_PATTERN, $name);
    }

    expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)->not->toContain('page');
});

it('keeps the record and page constraints in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();
    $byName = collect($compiled['attributes'])->keyBy(fn (array $attributes) => $attributes['action']['as'] ?? '');

    foreach (lakRecordRouteNames() as $name) {
        expect($byName[$name]['wheres']['record'] ?? null)->toBe(DatabaseRouteKey::PATTERN, $name);
    }

    foreach (lakPageRouteNames() as $name) {
        expect($byName[$name]['wheres']['page'] ?? null)->toBe(DatabaseRouteKey::PAGE_NUMBER_PATTERN, $name);
    }
});

it('keeps the page number pattern inside both PHP integers and the int4 page_number column', function () {
    // The longest page number the pattern admits, and the first it refuses.
    expect(preg_match('#^'.DatabaseRouteKey::PAGE_NUMBER_PATTERN.'$#', '999999999'))->toBe(1)
        ->and(preg_match('#^'.DatabaseRouteKey::PAGE_NUMBER_PATTERN.'$#', '1000000000'))->toBe(0)
        ->and(preg_match('#^'.DatabaseRouteKey::PAGE_NUMBER_PATTERN.'$#', '01'))->toBe(0)
        ->and(preg_match('#^'.DatabaseRouteKey::PAGE_NUMBER_PATTERN.'$#', '0'))->toBe(0)
        ->and(999999999)->toBeLessThan(2147483647);
});

dataset('lak_record_routes', [
    'legacy RME show' => ['get', '/rme/legacy-records/{S}'],
    'legacy RME source' => ['get', '/rme/legacy-records/{S}/source'],
    'legacy RME print' => ['get', '/rme/legacy-records/{S}/print'],
    'legacy RME export' => ['get', '/rme/legacy-records/{S}/export'],
    'legacy RME page' => ['get', '/rme/legacy-records/{S}/pages/1'],
    'legacy RME void' => ['post', '/rme/legacy-records/{S}/void'],
    'legacy odontogram show' => ['get', '/rme/legacy-odontograms/{S}'],
    'legacy odontogram source' => ['get', '/rme/legacy-odontograms/{S}/source'],
    'legacy odontogram page' => ['get', '/rme/legacy-odontograms/{S}/pages/1'],
    'legacy odontogram void' => ['post', '/rme/legacy-odontograms/{S}/void'],
]);

it('answers 404 to a guest for every record segment that is not a key', function (string $method, string $template) {
    $mismatched = [];

    foreach (lakNonKeySegments() as $label => $segment) {
        $status = $this->call(strtoupper($method), strtr($template, ['{S}' => $segment]))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('lak_record_routes');

it('answers 404, never 500, to a signed-in reader for a record segment that is not a key', function (string $method, string $template, string $segment) {
    $errors = lakCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), strtr($template, ['{S}' => $segment]), ['reason' => 'Uji kunci rute: bukan arsip.'])
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('lak_record_routes')->with(fn () => array_map(fn (string $s) => [$s], lakNonKeySegments()));

dataset('lak_page_routes', [
    'legacy RME record page' => ['rme'],
    'legacy odontogram record page' => ['odontogram'],
    'legacy RME import page' => ['rme-import'],
    'legacy odontogram import page' => ['odontogram-import'],
]);

function lakPageUrl(string $kind, string $page): string
{
    $t = test();

    return match ($kind) {
        'rme' => '/rme/legacy-records/'.$t->rme->id.'/pages/'.$page,
        'odontogram' => '/rme/legacy-odontograms/'.$t->odontogram->id.'/pages/'.$page,
        'rme-import' => '/settings/rme/legacy-imports/'.$t->rmeImport->id.'/pages/'.$page,
        'odontogram-import' => '/settings/rme/legacy-odontograms/'.$t->odontogramImport->id.'/pages/'.$page,
    };
}

it('answers 404, never 500, for a page segment that is not a page number', function (string $kind, string $segment) {
    // One segment per test: under `whereNumber` an out-of-int4 page reached
    // PostgreSQL (22003) and aborted the whole test transaction (25P02).
    $errors = lakCaptureErrorLogs();

    $this->actingAs($this->admin)->get(lakPageUrl($kind, $segment))->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('lak_page_routes')->with(fn () => array_map(fn (string $s) => [$s], lakNonPageSegments()));

it('answers 404 for a well-formed page beyond the last page, never 500', function (string $kind) {
    $errors = lakCaptureErrorLogs();

    $this->actingAs($this->admin)->get(lakPageUrl($kind, '999999999'))->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('lak_page_routes');

it('opens a real archive and page only at the canonical identifier, never a leading-zero alias', function () {
    $this->actingAs($this->admin);

    $this->get('/rme/legacy-records/'.$this->rme->id)->assertOk();
    $this->get('/rme/legacy-records/'.$this->rme->id.'/pages/1')->assertOk();
    $this->get('/rme/legacy-odontograms/'.$this->odontogram->id)->assertOk();
    $this->get('/rme/legacy-odontograms/'.$this->odontogram->id.'/pages/1')->assertOk();

    // Before this revision each alias cast to the real identifier and opened
    // (and audited) the same archive or page under a non-canonical URL.
    $this->get('/rme/legacy-records/0'.$this->rme->id)->assertNotFound();
    $this->get('/rme/legacy-records/'.$this->rme->id.'/pages/01')->assertNotFound();
    $this->get('/rme/legacy-odontograms/0'.$this->odontogram->id)->assertNotFound();
    $this->get('/rme/legacy-odontograms/'.$this->odontogram->id.'/pages/01')->assertNotFound();
});

it('keeps the archive permission gate and the archive state rules unchanged', function () {
    // No archive permission: the gate answers, not a lookup (unchanged).
    $this->actingAs(User::factory()->create())->get('/rme/legacy-records/'.$this->rme->id)->assertForbidden();

    // A VOID archive stays unreadable at its canonical key (unchanged).
    $void = LegacyRmeRecord::factory()->voided()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->branch->id]);
    $this->actingAs($this->admin)->get('/rme/legacy-records/'.$void->id.'/source')->assertNotFound();

    // The longest key the pattern admits names nothing: 404, no 500.
    $this->get('/rme/legacy-records/999999999999999999')->assertNotFound();
    $this->get('/rme/legacy-odontograms/999999999999999999')->assertNotFound();

    expect($this->rme->fresh()->isPublished())->toBeTrue()
        ->and($this->odontogram->fresh()->status)->toBe(LegacyOdontogramRecord::STATUS_PUBLISHED);
});
