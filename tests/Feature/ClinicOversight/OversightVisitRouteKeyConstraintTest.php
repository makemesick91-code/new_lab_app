<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Routing\DatabaseRouteKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-2 / R-08 — an oversight visit segment that is not
| a database key never reaches the controller.
|--------------------------------------------------------------------------
|
| The five `oversight/rme/visits/{visit}` routes are not model-bound: the
| controller takes `int $visit` and looks the visit up inside the reader's
| RME branches. They carried `->whereNumber('visit')` (`[0-9]+`), which
| admits:
|   - a number beyond PHP's integer range, which cannot be passed to the
|     `int` argument (TypeError: HTTP 500 plus an ERROR line); and
|   - a leading-zero alias (`0` . id), which PHP casts to the real id, so a
|     non-canonical URL opened — and audited — a real clinical record.
|
| The fix swaps that constraint for DatabaseRouteKey::PATTERN on each route.
| `visit` is a generic parameter name and the oversight lookup already
| resolves visits by a string visit number, so it is constrained per route
| (rule 222), never globally.
|
| Every behavioural case here is driver-independent: the defect is in PHP's
| integer conversion, not in a database cast.
*/

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branch = covBranch('OVK', 'Cabang Oversight Kunci');
    $this->main = covBranch('MAIN', 'Kantor Pusat');

    $this->patient = covPatient($this->branch, ['name' => 'Pasien Kunci Rute']);
    $this->visit = covVisit($this->branch, $this->patient, ['status' => ClinicVisit::STATUS_COMPLETED]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);
    // Every print page has something to render, so a 404 below can only come
    // from the route constraint, never from missing clinical data.
    Odontogram::factory()->create(['clinic_visit_id' => $this->visit->id, 'branch_id' => $this->branch->id]);
    RmePrescription::factory()->create(['clinic_visit_id' => $this->visit->id]);

    $this->reader = covUser('Data Analyst');
});

dataset('ovk_routes', [
    'visit detail' => ['oversight.rme.visits.show', '/oversight/rme/visits/{S}'],
    'print' => ['oversight.rme.visits.print', '/oversight/rme/visits/{S}/print'],
    'pdf' => ['oversight.rme.visits.pdf', '/oversight/rme/visits/{S}/pdf'],
    'odontogram print' => ['oversight.rme.visits.odontogram.print', '/oversight/rme/visits/{S}/odontogram/print'],
    'prescription print' => ['oversight.rme.visits.prescription.print', '/oversight/rme/visits/{S}/prescription/print'],
]);

/** @return array<string, string> already URL-encoded segments that are not a key */
function ovkNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BVISIT_ID%7D',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '007',
        'decimal' => '1.5',
        'invalid percent encoding' => '%ZZ',
        'nineteen digits' => '1000000000000000000',
        'beyond PHP integer range' => '99999999999999999999',
    ];
}

dataset('ovk_segments', fn () => array_map(fn (string $segment) => [$segment], ovkNonKeySegments()));

/** @return ArrayObject<int, string> */
function ovkCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

it('constrains every oversight visit route with the canonical database key pattern', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'oversight.rme.visits.'));

    expect($routes->count())->toBe(5);

    $routes->each(function ($route) {
        // `whereNumber` would leave `[0-9]+`, which admits integer overflow.
        expect($route->wheres['visit'] ?? null)->toBe(DatabaseRouteKey::PATTERN, $route->uri());
    });
});

it('keeps the oversight visit constraint in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();

    $withParameter = collect($compiled['attributes'])
        ->filter(fn (array $attributes) => str_starts_with((string) $attributes['uri'], 'oversight/rme/visits/{visit}'));

    expect($withParameter)->toHaveCount(5);

    $withParameter->each(function (array $attributes) {
        expect($attributes['wheres']['visit'] ?? null)->toBe(DatabaseRouteKey::PATTERN, (string) $attributes['uri']);
    });
});

it('answers 404 to a guest for a segment that is not a database key', function (string $name, string $template) {
    $mismatched = [];

    foreach (ovkNonKeySegments() as $label => $segment) {
        $status = $this->get(strtr($template, ['{S}' => $segment]))->getStatusCode();
        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('ovk_routes');

it('answers 404, never 500, to a signed-in reader and logs no ERROR', function (string $name, string $template, string $segment) {
    covActingAs($this->reader);
    $errors = ovkCaptureErrorLogs();

    $this->get(strtr($template, ['{S}' => $segment]))->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('ovk_routes')->with('ovk_segments');

it('no longer opens a real visit through a leading-zero alias of its key', function (string $name, string $template) {
    covActingAs($this->reader);

    // Control: the canonical key opens the page, so the alias 404 below is
    // the constraint at work and not missing data.
    $this->get(route($name, $this->visit->id))->assertOk();
    $this->get(strtr($template, ['{S}' => '0'.$this->visit->id]))->assertNotFound();
})->with('ovk_routes');

it('still opens a real visit in the reader branches by its numeric key', function () {
    covActingAs($this->reader);

    $this->get(route('oversight.rme.visits.show', $this->visit->id))
        ->assertOk()
        ->assertSee($this->visit->visit_number);
});

it('still answers 404 for a well-formed key outside the reader branches or naming no visit', function () {
    // MAIN is not an RME branch: an oversight reader cannot see it, and an
    // out-of-scope visit is a 404 exactly like a missing one, never a 403
    // that would confirm the visit exists.
    $mainVisit = covVisit($this->main, covPatient($this->main));
    covActingAs($this->reader);
    $errors = ovkCaptureErrorLogs();

    $this->get(route('oversight.rme.visits.show', $mainVisit->id))->assertNotFound();
    $this->get('/oversight/rme/visits/999999999999999999')->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
});

it('keeps the reader permission gate in front of a well-formed key', function () {
    // A user without view_clinical_records_readonly meets the permission
    // middleware for a real key, and gets 404 for a non-key segment.
    covActingAs(covUser('Kasir'));

    expect($this->get(route('oversight.rme.visits.show', $this->visit->id))->getStatusCode())->toBe(403)
        ->and($this->get('/oversight/rme/visits/abc')->getStatusCode())->toBe(404);
});

it('still lets a guest reach the login redirect for a real numeric key', function () {
    $this->get('/oversight/rme/visits/'.$this->visit->id)->assertRedirect();
});
