<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Support\Routing\DatabaseRouteKey;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-2 / R-03..R-07 — an RME record segment that is not
| a database key never reaches the database.
|--------------------------------------------------------------------------
|
| Program 1 (R-01) constrained `{clinicVisit}` and recorded the same weakness
| on the other integer-bound RME parameters as an owner-approval follow-up.
| The owner approved it for these five on 2026-11-05:
|
|   R-03 {rmeInvoice}      15 routes   trx_rme_invoices.id       bigint
|   R-04 {medicalRecord}    7 routes   trx_medical_records.id    bigint
|   R-05 {rmePrescription}  4 routes   trx_rme_prescriptions.id  bigint
|   R-06 {consent}          3 routes   trx_rme_visit_consents.id bigint
|   R-07 {odontogram}       3 routes   trx_odontograms.id        bigint
|
| Each is bound implicitly on its primary key (no model overrides the route
| key, no explicit Route::bind), so binding ran `where "id" = '<segment>'`.
| On PostgreSQL a non-integer segment raises SQLSTATE 22P02 and a number
| beyond bigint 22003: HTTP 500 plus an ERROR line instead of a 404.
|
| Which assertions are load-bearing where:
|   - structural, compiled-collection and cached-matcher cases: every driver;
|   - guest and no-permission cases: driver-independent — an unconstrained
|     route MATCHES (login redirect / 403), a constrained one answers 404;
|   - the signed-in 404/no-ERROR cases fail only on PostgreSQL without the
|     fix (SQLite compares loosely and finds no row). CI's critical gate runs
|     on PostgreSQL 16.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'RRK1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin = User::factory()->create()->assignRole('Super Admin');
});

/** Approved parameter => minimum route count when this revision was written. */
function rrkGovernedParameters(): array
{
    return [
        'rmeInvoice' => 15,
        'medicalRecord' => 7,
        'rmePrescription' => 4,
        'consent' => 3,
        'odontogram' => 3,
    ];
}

/**
 * Path segments, already URL-encoded as a browser would send them. None can
 * be a key an identity column issues.
 *
 * @return array<string, string>
 */
function rrkNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BINVOICE_ID%7D',
        'digits then letters' => '12abc',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '007',
        'decimal' => '1.5',
        'exponent' => '1e3',
        'leading space' => '%201',
        'fullwidth digit' => '%EF%BC%91',
        'invalid percent encoding' => '%ZZ',
        'nineteen digits' => '1000000000000000000',
        'beyond bigint' => '99999999999999999999',
    ];
}

dataset('rrk_segments', fn () => array_map(fn (string $segment) => [$segment], rrkNonKeySegments()));

/**
 * One representative request per HTTP method per parameter. `{V}` is a real
 * visit (so the parent `{clinicVisit}` binds), `{S}` the segment under test.
 */
dataset('rrk_requests', [
    'R-03 invoice show' => ['get', '/rme/cashier/{V}/billing/{S}'],
    'R-03 receivable follow-up' => ['get', '/rme/cashier/receivables/{S}/follow-ups/create'],
    'R-03 payment store' => ['post', '/rme/cashier/{V}/billing/{S}/payment'],
    'R-04 medical record update' => ['patch', '/rme/visits/{V}/medical-record/{S}'],
    'R-04 medical record finalize' => ['post', '/rme/visits/{V}/medical-record/{S}/finalize'],
    'R-05 prescription print' => ['get', '/rme/prescriptions/{S}/print'],
    'R-05 prescription update' => ['patch', '/rme/prescriptions/{S}'],
    'R-06 consent show' => ['get', '/rme/consents/{S}'],
    'R-06 consent signature' => ['get', '/rme/consents/{S}/signature/patient'],
    'R-07 odontogram print' => ['get', '/rme/odontograms/{S}/print'],
    'R-07 odontogram update' => ['patch', '/rme/odontograms/{S}'],
]);

/** @return ArrayObject<int, string> */
function rrkCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

function rrkUrl(string $template, ClinicVisit $visit, string $segment): string
{
    return strtr($template, ['{V}' => (string) $visit->id, '{S}' => $segment]);
}

it('governs exactly the approved RME record parameters globally', function () {
    // clinicVisit (Program 1 / R-01) plus the five approved here. The generic
    // `visit` name is deliberately NOT global (R-08 constrains it per route):
    // the oversight lookup already resolves visits by a string visit number,
    // so a global numeric `visit` pattern could silently 404 a future route.
    expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)
        ->toEqualCanonicalizing(['clinicVisit', ...array_keys(rrkGovernedParameters())])
        ->not->toContain('visit');
});

it('constrains every route carrying an approved parameter to a database key', function (string $parameter, int $minimum) {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($parameter, $route->parameterNames(), true));

    // A drop below the recorded count means routes were removed or renamed,
    // which this suite must notice rather than pass vacuously.
    expect($routes->count())->toBeGreaterThanOrEqual($minimum);

    $unconstrained = $routes
        ->reject(fn ($route) => ($route->wheres[$parameter] ?? null) === DatabaseRouteKey::PATTERN)
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unconstrained)->toBe([]);
})->with(fn () => collect(rrkGovernedParameters())->map(fn ($min, $param) => [$param, $min])->all());

it('keeps every approved constraint in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();

    foreach (array_keys(rrkGovernedParameters()) as $parameter) {
        $withParameter = collect($compiled['attributes'])
            ->filter(fn (array $attributes) => str_contains((string) $attributes['uri'], '{'.$parameter.'}'));

        expect($withParameter)->not->toBeEmpty();

        $withParameter->each(function (array $attributes) use ($parameter) {
            expect($attributes['wheres'][$parameter] ?? null)
                ->toBe(DatabaseRouteKey::PATTERN, $parameter.' in '.$attributes['uri']);
        });
    }
});

it('rejects a non-key segment and accepts a real key through the cached matcher', function (string $method, string $template) {
    // Production serves the compiled collection, which matches through
    // Symfony's CompiledUrlMatcher, not the runtime route objects. Matching
    // against it directly proves the cached path behaves like the live one.
    $compiled = app('router')->getRoutes()->compile();
    $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
        ->setRouter(app('router'))
        ->setContainer(app());

    $matched = $cached->match(Request::create(rrkUrl($template, $this->visit, '42'), strtoupper($method)));
    expect($matched->uri())->not->toBe('');

    $leaked = [];
    foreach (rrkNonKeySegments() as $label => $segment) {
        try {
            $cached->match(Request::create(rrkUrl($template, $this->visit, $segment), strtoupper($method)));
            $leaked[] = $label;
        } catch (NotFoundHttpException) {
            // expected: the cached route does not match a non-key segment
        }
    }

    expect($leaked)->toBe([]);
})->with('rrk_requests');

it('answers 404 to a guest for a segment that is not a database key', function (string $method, string $template) {
    $mismatched = [];

    foreach (rrkNonKeySegments() as $label => $segment) {
        $status = $this->call(strtoupper($method), rrkUrl($template, $this->visit, $segment))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('rrk_requests');

it('answers 404, never 500, to a signed-in user and logs no ERROR', function (string $method, string $template, string $segment) {
    // One segment per test: a failed statement aborts the whole PostgreSQL
    // transaction (25P02), so a loop would contaminate every later segment.
    $errors = rrkCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), rrkUrl($template, $this->visit, $segment))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('rrk_requests')->with('rrk_segments');

dataset('rrk_real_record_requests', [
    'R-03 invoice show' => ['get', '/rme/cashier/{V}/billing/{S}', 'invoice'],
    'R-03 receivable follow-up' => ['get', '/rme/cashier/receivables/{S}/follow-ups/create', 'invoice'],
    'R-03 payment store' => ['post', '/rme/cashier/{V}/billing/{S}/payment', 'invoice'],
    'R-04 medical record update' => ['patch', '/rme/visits/{V}/medical-record/{S}', 'medicalRecord'],
    'R-04 medical record finalize' => ['post', '/rme/visits/{V}/medical-record/{S}/finalize', 'medicalRecord'],
    'R-05 prescription print' => ['get', '/rme/prescriptions/{S}/print', 'prescription'],
    'R-05 prescription update' => ['patch', '/rme/prescriptions/{S}', 'prescription'],
    'R-06 consent show' => ['get', '/rme/consents/{S}', 'consent'],
    'R-06 consent signature' => ['get', '/rme/consents/{S}/signature/patient', 'consent'],
    'R-07 odontogram print' => ['get', '/rme/odontograms/{S}/print', 'odontogram'],
    'R-07 odontogram update' => ['patch', '/rme/odontograms/{S}', 'odontogram'],
]);

it('keeps the permission gate for a real record and answers 404 when no record can match', function (string $method, string $template, string $kind) {
    // Implicit binding runs BEFORE the route's permission middleware. So for
    // a user without the permission: a real record meets the permission gate
    // (403, unchanged); a well-formed key naming no record is a 404 from
    // binding (unchanged); and a non-key segment is now a 404 from the router
    // — before this revision it reached the query and answered 500.
    $owner = ['branch_id' => $this->branch->id, 'clinic_visit_id' => $this->visit->id, 'patient_id' => $this->visit->patient_id];
    $record = match ($kind) {
        'invoice' => RmeInvoice::factory()->create($owner),
        'medicalRecord' => MedicalRecord::factory()->create($owner),
        'prescription' => RmePrescription::factory()->create($owner),
        'consent' => RmeVisitConsent::factory()->create($owner),
        'odontogram' => Odontogram::factory()->create(['clinic_visit_id' => $this->visit->id, 'branch_id' => $this->branch->id]),
    };
    $outsider = User::factory()->create();
    $status = fn (string $segment): int => $this->actingAs($outsider)
        ->call(strtoupper($method), rrkUrl($template, $this->visit, $segment))
        ->getStatusCode();

    expect($status((string) $record->getKey()))->toBe(403)
        ->and($status('999999999999999999'))->toBe(404)
        ->and($status('abc'))->toBe(404);
})->with('rrk_real_record_requests');

it('answers 404 without an ERROR for the longest key that matches but names no record', function (string $method, string $template) {
    $errors = rrkCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), rrkUrl($template, $this->visit, '999999999999999999'))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('rrk_requests');

it('still lets a guest reach the login redirect for a well-formed key', function (string $method, string $template) {
    // The constraint must not turn the normal authentication flow into a 404.
    $this->call(strtoupper($method), rrkUrl($template, $this->visit, '42'))->assertRedirect();
})->with('rrk_requests');
