<?php

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Support\Routing\DatabaseRouteKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-3 / R-11..R-15 — an oversight segment that is not
| an identifier never reaches the controller.
|--------------------------------------------------------------------------
|
| The read-only clinical oversight surface carried `->whereNumber()`
| (`[0-9]+`) on every identifier:
|
|   R-11 {patient}          1 route   `int $patient`  -> mst_patients.id
|   R-12 {handwriting}      1 route   model-bound     -> trx_medical_record_handwritings.id
|   R-13 {handwritingPage}  1 route   model-bound     -> trx_medical_record_handwriting_pages.id
|   R-14 {record}           9 routes  `int $record`   -> trx_rme_legacy_records.id /
|                                                        trx_odontogram_legacy_records.id
|   R-15 {page}             2 routes  `int $page`     -> page_number (PostgreSQL `integer`)
|
| `[0-9]+` admits a number beyond PHP's integer range (TypeError on the `int`
| argument: HTTP 500 plus an ERROR line), a leading-zero alias that PHP or
| PostgreSQL casts to the real id (a non-canonical URL opened — and AUDITED —
| a real patient or archive), and, for `{page}`, a number that fits PHP's
| integer but not the int4 `page_number` column (PostgreSQL 22003, HTTP 500).
|
| `patient`, `record` and `page` are generic names, so they are constrained
| per route (rule 222 §1.6). `{record}` reuses DatabaseRouteKey::PATTERN;
| `{page}` is a 1-based page number, not a key, and uses the narrower
| DatabaseRouteKey::PAGE_NUMBER_PATTERN. `handwriting` and `handwritingPage`
| are domain names and are constrained globally.
*/

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');

    $this->branch = covBranch('ORK', 'Cabang Oversight Rekam');
    $this->main = covBranch('MAIN', 'Kantor Pusat');

    $this->patient = covPatient($this->branch, ['name' => 'Pasien Kunci Arsip']);
    $this->visit = covVisit($this->branch, $this->patient, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);
    $this->handwritingPage = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('PNGDATA'),
    ]);
    $this->handwriting = MedicalRecordHandwriting::factory()->create(['medical_record_id' => $this->record->id]);

    Storage::disk('legacy_rme_private')->put('rme-legacy/ork/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_rme_private')->put('rme-legacy/ork/source.pdf', '%PDF-1.4 fake');
    $this->legacyRme = LegacyRmeRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'origin_branch_id' => $this->branch->id,
        'title' => 'Arsip Kunci 2019',
        'source_disk' => 'legacy_rme_private',
        'source_pdf_path' => 'rme-legacy/ork/source.pdf',
    ]);
    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $this->legacyRme->id,
        'page_number' => 1,
        'background_disk' => 'legacy_rme_private',
        'background_path' => 'rme-legacy/ork/page-1.png',
    ]);

    Storage::disk('legacy_odontogram_private')->put('odo/ork/page-1.png', 'PNG-BYTES');
    Storage::disk('legacy_odontogram_private')->put('odo/ork/source.pdf', '%PDF-1.4 fake');
    $this->legacyOdontogram = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'branch_id' => $this->branch->id,
        'source_pdf_path' => 'odo/ork/source.pdf',
        'page_count' => 1,
        'title' => 'Odontogram Kunci 2018',
    ]);
    LegacyOdontogramRecordPage::query()->forceCreate([
        'odontogram_legacy_record_id' => $this->legacyOdontogram->id,
        'page_number' => 1,
        'width' => 100,
        'height' => 100,
        'dpi' => 150,
        'rotation' => 0,
        'image_disk' => 'legacy_odontogram_private',
        'image_path' => 'odo/ork/page-1.png',
        'image_sha256' => str_repeat('b', 64),
    ]);

    $this->reader = covUser('Data Analyst');
});

/** Oversight parameter => [route names carrying it, the pattern each must carry]. */
function orkPerRouteContract(): array
{
    return [
        'patient' => [['oversight.rme.patients.show'], DatabaseRouteKey::PATTERN],
        'record' => [[
            'oversight.legacy.rme.show', 'oversight.legacy.rme.pages.show', 'oversight.legacy.rme.source',
            'oversight.legacy.rme.print', 'oversight.legacy.rme.pdf',
            'oversight.legacy.odontogram.show', 'oversight.legacy.odontogram.pages.show',
            'oversight.legacy.odontogram.source', 'oversight.legacy.odontogram.print',
        ], DatabaseRouteKey::PATTERN],
        'page' => [['oversight.legacy.rme.pages.show', 'oversight.legacy.odontogram.pages.show'], DatabaseRouteKey::PAGE_NUMBER_PATTERN],
        'handwriting' => [['oversight.rme.handwritings.image'], DatabaseRouteKey::PATTERN],
        'handwritingPage' => [['oversight.rme.handwriting-pages.image'], DatabaseRouteKey::PATTERN],
    ];
}

/** @return array<string, string> already URL-encoded segments that are not a key */
function orkNonKeySegments(): array
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

/**
 * A page number is a 1-based ordinal compared against an int4 column. Ten
 * digits already passes `2147483647` territory; nothing a rasterizer can
 * produce comes close.
 *
 * @return array<string, string>
 */
function orkNonPageSegments(): array
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

dataset('ork_page_segments', fn () => array_map(fn (string $segment) => [$segment], orkNonPageSegments()));

/** @return ArrayObject<int, string> */
function orkCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

it('constrains every oversight identifier route with the right pattern for its identifier', function (string $parameter) {
    [$names, $pattern] = orkPerRouteContract()[$parameter];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);

        // `whereNumber` would leave `[0-9]+`, which admits integer overflow
        // and a leading-zero alias.
        expect($route)->not->toBeNull($name)
            ->and($route->wheres[$parameter] ?? null)->toBe($pattern, $name);
    }
})->with(array_keys(orkPerRouteContract()));

it('keeps every oversight constraint in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();
    $byName = collect($compiled['attributes'])->keyBy(fn (array $attributes) => $attributes['action']['as'] ?? '');

    foreach (orkPerRouteContract() as $parameter => [$names, $pattern]) {
        foreach ($names as $name) {
            expect($byName[$name]['wheres'][$parameter] ?? null)->toBe($pattern, $parameter.' in '.$name);
        }
    }
});

dataset('ork_int_routes', [
    'R-11 patient history' => ['/oversight/rme/patients/{S}'],
    'R-14 legacy RME show' => ['/oversight/legacy-rme/{S}'],
    'R-14 legacy RME source' => ['/oversight/legacy-rme/{S}/source'],
    'R-14 legacy RME print' => ['/oversight/legacy-rme/{S}/print'],
    'R-14 legacy RME pdf' => ['/oversight/legacy-rme/{S}/pdf'],
    'R-14 legacy RME page' => ['/oversight/legacy-rme/{S}/pages/1'],
    'R-14 legacy odontogram show' => ['/oversight/legacy-odontogram/{S}'],
    'R-14 legacy odontogram source' => ['/oversight/legacy-odontogram/{S}/source'],
    'R-14 legacy odontogram print' => ['/oversight/legacy-odontogram/{S}/print'],
    'R-14 legacy odontogram page' => ['/oversight/legacy-odontogram/{S}/pages/1'],
    'R-12 handwriting image' => ['/oversight/rme/handwritings/{S}/image'],
    'R-13 handwriting page image' => ['/oversight/rme/handwriting-pages/{S}/image'],
]);

it('answers 404 to a guest for every segment that is not an identifier', function (string $template) {
    $mismatched = [];

    foreach (orkNonKeySegments() as $label => $segment) {
        $status = $this->get(strtr($template, ['{S}' => $segment]))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('ork_int_routes');

it('answers 404, never 500, to an oversight reader and logs no ERROR', function (string $template, string $segment) {
    // One segment per test: a failed statement aborts the whole PostgreSQL
    // transaction (25P02), so a loop would contaminate every later segment.
    $errors = orkCaptureErrorLogs();
    covActingAs($this->reader);

    $this->get(strtr($template, ['{S}' => $segment]))->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('ork_int_routes')->with(fn () => array_map(fn (string $s) => [$s], orkNonKeySegments()));

it('opens a real patient, archive and image only at the canonical identifier, never a leading-zero alias', function () {
    covActingAs($this->reader);

    $canonical = [
        'R-11 patient history' => '/oversight/rme/patients/'.$this->patient->id,
        'R-14 legacy RME show' => '/oversight/legacy-rme/'.$this->legacyRme->id,
        'R-14 legacy odontogram show' => '/oversight/legacy-odontogram/'.$this->legacyOdontogram->id,
        'R-13 handwriting page image' => '/oversight/rme/handwriting-pages/'.$this->handwritingPage->id.'/image',
        'R-15 legacy RME page' => '/oversight/legacy-rme/'.$this->legacyRme->id.'/pages/1',
        'R-15 legacy odontogram page' => '/oversight/legacy-odontogram/'.$this->legacyOdontogram->id.'/pages/1',
    ];

    foreach ($canonical as $label => $url) {
        expect($this->get($url)->getStatusCode())->toBe(200, $label.' canonical');
    }

    $aliases = [
        'R-11 patient history' => '/oversight/rme/patients/0'.$this->patient->id,
        'R-14 legacy RME show' => '/oversight/legacy-rme/0'.$this->legacyRme->id,
        'R-14 legacy odontogram show' => '/oversight/legacy-odontogram/0'.$this->legacyOdontogram->id,
        'R-13 handwriting page image' => '/oversight/rme/handwriting-pages/0'.$this->handwritingPage->id.'/image',
        'R-15 legacy RME page' => '/oversight/legacy-rme/'.$this->legacyRme->id.'/pages/01',
        'R-15 legacy odontogram page' => '/oversight/legacy-odontogram/'.$this->legacyOdontogram->id.'/pages/01',
    ];

    // Before this revision each alias cast to the real identifier: it opened
    // the same clinical record and wrote an oversight audit row for it.
    foreach ($aliases as $label => $url) {
        expect($this->get($url)->getStatusCode())->toBe(404, $label.' alias');
    }
});

it('answers 404, never 500, for a page number that is not a valid page', function (string $kind, string $segment) {
    // `{page}` is compared against the int4 `page_number` column. A number
    // that fits PHP's integer but not int4 reached PostgreSQL and raised
    // 22003 (HTTP 500 plus an ERROR line) under `whereNumber`.
    $errors = orkCaptureErrorLogs();
    covActingAs($this->reader);

    $record = $kind === 'rme' ? $this->legacyRme : $this->legacyOdontogram;
    $prefix = $kind === 'rme' ? '/oversight/legacy-rme/' : '/oversight/legacy-odontogram/';

    $this->get($prefix.$record->id.'/pages/'.$segment)->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with(['rme', 'odontogram'])->with('ork_page_segments');

it('answers 404 for a well-formed page number beyond the last page, and opens the first page', function () {
    covActingAs($this->reader);

    $this->get('/oversight/legacy-rme/'.$this->legacyRme->id.'/pages/1')->assertOk();
    $this->get('/oversight/legacy-rme/'.$this->legacyRme->id.'/pages/2')->assertNotFound();
    // The longest page number the pattern admits still fits int4: 404, no 500.
    $this->get('/oversight/legacy-rme/'.$this->legacyRme->id.'/pages/999999999')->assertNotFound();
    $this->get('/oversight/legacy-odontogram/'.$this->legacyOdontogram->id.'/pages/999999999')->assertNotFound();
});

it('keeps the oversight permission gate and the RME branch scope unchanged', function () {
    // No reader permission: the gate answers before any lookup (unchanged).
    covActingAs(User::factory()->create());
    $this->get('/oversight/rme/patients/'.$this->patient->id)->assertForbidden();
    $this->get('/oversight/legacy-rme/'.$this->legacyRme->id)->assertForbidden();
    // The handwriting image IS model-bound, and binding runs before the
    // permission middleware (rule 222 §1.8): the real key meets the gate, its
    // leading-zero alias no longer reaches binding at all.
    $this->get('/oversight/rme/handwritings/'.$this->handwriting->id.'/image')->assertForbidden();
    $this->get('/oversight/rme/handwritings/0'.$this->handwriting->id.'/image')->assertNotFound();

    // A MAIN-branch patient and archive stay out of the reader's scope: 404.
    $mainPatient = covPatient($this->main, ['name' => 'Pasien Pusat Kunci']);
    $mainVisit = covVisit($this->main, $mainPatient);
    MedicalRecord::factory()->create(['clinic_visit_id' => $mainVisit->id, 'branch_id' => $this->main->id, 'patient_id' => $mainPatient->id]);
    $mainArchive = LegacyRmeRecord::factory()->create(['patient_id' => $mainPatient->id, 'origin_branch_id' => $this->main->id]);

    covActingAs($this->reader);
    $this->get('/oversight/rme/patients/'.$mainPatient->id)->assertNotFound();
    $this->get('/oversight/legacy-rme/'.$mainArchive->id)->assertNotFound();
    // The longest key the pattern admits names nothing: 404, no 500.
    $this->get('/oversight/rme/patients/999999999999999999')->assertNotFound();
    $this->get('/oversight/legacy-rme/999999999999999999')->assertNotFound();
});
