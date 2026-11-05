<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
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
| DMS-RME-REVISION-PROGRAM-3 / R-09, R-10, R-12, R-13 — a child-record
| segment that is not a database key never reaches the database.
|--------------------------------------------------------------------------
|
| Programs 1 and 2 constrained the RME record parameters and recorded these
| as owner-approval follow-ups (rule 222 §1.10). The owner approved them in
| the Program 3 mission brief of 2026-11-05. Each name is domain-specific,
| and every route carrying it binds a bigint `id()` primary key — implicitly
| (no model overrides the route key, no explicit Route::bind) or, for the
| receipt snapshot, through an `int` controller argument looked up by key:
|
|   R-09 {diagnosis}        7 routes   trx_medical_record_diagnoses.id   (2)
|                                      mst_clinical_diagnoses.id         (5)
|   R-10 {receiptSnapshot}  1 route    trx_rme_receipt_snapshots.id      int arg
|   R-12 {handwriting}      2 routes   trx_medical_record_handwritings.id
|   R-13 {handwritingPage}  2 routes   trx_medical_record_handwriting_pages.id
|
| `{handwritingPage}` is the page ROW's primary key, not a page index: the
| page's position lives in its own `page_number` column and never in the URL.
|
| Which assertions are load-bearing where:
|   - structural, compiled-collection and cached-matcher cases: every driver;
|   - guest and no-permission cases: driver-independent — an unconstrained
|     route MATCHES (login redirect / 403), a constrained one answers 404;
|   - the signed-in 404/no-ERROR cases fail only on PostgreSQL without the
|     fix (SQLite compares loosely and finds no row). CI's critical gate runs
|     on PostgreSQL 16.
|
| The receipt snapshot's behavioural cases need a real paid invoice, so they
| live with the post-payment fixtures in
| tests/Feature/RmeInvoiceCorrection/RmeReceiptSnapshotRouteKeyConstraintTest.php.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'RCK1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);
    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->visit->patient_id,
    ]);
    $this->admin = User::factory()->create()->assignRole('Super Admin');
});

/** Approved parameter => route count measured when this revision was written. */
function rckGovernedParameters(): array
{
    return [
        'diagnosis' => 7,
        'receiptSnapshot' => 1,
        'handwriting' => 2,
        'handwritingPage' => 2,
    ];
}

/**
 * Path segments, already URL-encoded as a browser would send them. None can
 * be a key an identity column issues.
 *
 * @return array<string, string>
 */
function rckNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BDIAGNOSIS_ID%7D',
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

dataset('rck_segments', fn () => array_map(fn (string $segment) => [$segment], rckNonKeySegments()));

/**
 * One representative request per HTTP method per controller. `{V}` is a real
 * visit and `{M}` its real medical record (so the parents bind), `{S}` the
 * segment under test.
 */
dataset('rck_requests', [
    'R-09 diagnosis destroy' => ['delete', '/rme/visits/{V}/medical-record/{M}/diagnoses/{S}'],
    'R-09 diagnosis make primary' => ['post', '/rme/visits/{V}/medical-record/{M}/diagnoses/{S}/make-primary'],
    'R-09 terminology submit review' => ['post', '/rme/satusehat/diagnoses/{S}/submit-review'],
    'R-09 terminology approve' => ['post', '/rme/satusehat/diagnoses/{S}/approve'],
    'R-12 handwriting image' => ['get', '/rme/handwritings/{S}/image'],
    'R-13 handwriting page image' => ['get', '/rme/handwriting-pages/{S}/image'],
]);

/** @return ArrayObject<int, string> */
function rckCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

function rckUrl(string $template, ClinicVisit $visit, MedicalRecord $record, string $segment): string
{
    return strtr($template, ['{V}' => (string) $visit->id, '{M}' => (string) $record->id, '{S}' => $segment]);
}

it('governs the four approved child-record parameters globally and never a generic name', function () {
    // `diagnosis`, `receiptSnapshot`, `handwriting` and `handwritingPage` are
    // domain names bound to a bigint key everywhere they appear. The generic
    // names this programme also hardened (`patient`, `record`, `page`) and the
    // Program 2 `visit` are constrained per route instead (rule 222 §1.6).
    expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)
        ->toContain(...array_keys(rckGovernedParameters()))
        ->not->toContain('visit')
        ->not->toContain('patient')
        ->not->toContain('record')
        ->not->toContain('page');
});

it('constrains every route carrying an approved child-record parameter to a database key', function (string $parameter, int $minimum) {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($parameter, $route->parameterNames(), true));

    // A drop below the recorded count means routes were removed or renamed,
    // which this suite must notice rather than pass vacuously.
    expect($routes->count())->toBeGreaterThanOrEqual($minimum);

    $unconstrained = $routes
        // A route-level whereNumber() would override the global pattern with
        // `[0-9]+`, which admits overflow and a leading-zero alias.
        ->reject(fn ($route) => ($route->wheres[$parameter] ?? null) === DatabaseRouteKey::PATTERN)
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unconstrained)->toBe([]);
})->with(fn () => collect(rckGovernedParameters())->map(fn ($min, $param) => [$param, $min])->all());

it('keeps every approved child-record constraint in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();

    foreach (array_keys(rckGovernedParameters()) as $parameter) {
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
    // Symfony's CompiledUrlMatcher, not the runtime route objects.
    $compiled = app('router')->getRoutes()->compile();
    $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
        ->setRouter(app('router'))
        ->setContainer(app());

    $matched = $cached->match(Request::create(rckUrl($template, $this->visit, $this->record, '42'), strtoupper($method)));
    expect($matched->uri())->not->toBe('');

    $leaked = [];
    foreach (rckNonKeySegments() as $label => $segment) {
        try {
            $cached->match(Request::create(rckUrl($template, $this->visit, $this->record, $segment), strtoupper($method)));
            $leaked[] = $label;
        } catch (NotFoundHttpException) {
            // expected: the cached route does not match a non-key segment
        }
    }

    expect($leaked)->toBe([]);
})->with('rck_requests');

it('answers 404 to a guest for a segment that is not a database key', function (string $method, string $template) {
    $mismatched = [];

    foreach (rckNonKeySegments() as $label => $segment) {
        $status = $this->call(strtoupper($method), rckUrl($template, $this->visit, $this->record, $segment))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('rck_requests');

it('answers 404, never 500, to a signed-in user and logs no ERROR', function (string $method, string $template, string $segment) {
    // One segment per test: a failed statement aborts the whole PostgreSQL
    // transaction (25P02), so a loop would contaminate every later segment.
    $errors = rckCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), rckUrl($template, $this->visit, $this->record, $segment))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('rck_requests')->with('rck_segments');

it('answers 404 without an ERROR for the longest key that matches but names no record', function (string $method, string $template) {
    $errors = rckCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), rckUrl($template, $this->visit, $this->record, '999999999999999999'))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('rck_requests');

it('still lets a guest reach the login redirect for a well-formed key', function (string $method, string $template) {
    // The constraint must not turn the normal authentication flow into a 404.
    $this->call(strtoupper($method), rckUrl($template, $this->visit, $this->record, '42'))->assertRedirect();
})->with('rck_requests');

dataset('rck_real_record_requests', [
    'R-09 diagnosis destroy' => ['delete', '/rme/visits/{V}/medical-record/{M}/diagnoses/{S}', 'diagnosis'],
    'R-09 diagnosis make primary' => ['post', '/rme/visits/{V}/medical-record/{M}/diagnoses/{S}/make-primary', 'diagnosis'],
    'R-09 terminology submit review' => ['post', '/rme/satusehat/diagnoses/{S}/submit-review', 'terminology'],
    'R-09 terminology approve' => ['post', '/rme/satusehat/diagnoses/{S}/approve', 'terminology'],
    'R-12 handwriting image' => ['get', '/rme/handwritings/{S}/image', 'handwriting'],
    'R-13 handwriting page image' => ['get', '/rme/handwriting-pages/{S}/image', 'handwritingPage'],
]);

it('keeps the permission gate for a real record and answers 404 when no record can match', function (string $method, string $template, string $kind) {
    // Implicit binding runs BEFORE the route's permission middleware (rule 222
    // §1.8). For a user without the permission: a real record meets the gate
    // (403, unchanged); a well-formed key naming no record is a 404 from
    // binding (unchanged); a non-key segment is now a 404 from the router —
    // before this revision it reached the query and answered 500.
    $author = User::factory()->create();
    $record = match ($kind) {
        'diagnosis' => MedicalRecordDiagnosis::query()->create([
            'medical_record_id' => $this->record->id,
            'clinic_visit_id' => $this->visit->id,
            'branch_id' => $this->branch->id,
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => 'primary',
            'diagnosed_by' => $author->id,
            'diagnosed_at' => now(),
            'created_by' => $author->id,
        ]),
        'terminology' => ClinicalDiagnosis::factory()->create(),
        'handwriting' => MedicalRecordHandwriting::factory()->create(['medical_record_id' => $this->record->id]),
        'handwritingPage' => MedicalRecordHandwritingPage::factory()->create(['medical_record_id' => $this->record->id]),
    };
    $outsider = User::factory()->create();
    $status = fn (string $segment): int => $this->actingAs($outsider)
        ->call(strtoupper($method), rckUrl($template, $this->visit, $this->record, $segment))
        ->getStatusCode();

    expect($status((string) $record->getKey()))->toBe(403)
        ->and($status('999999999999999999'))->toBe(404)
        ->and($status('abc'))->toBe(404)
        // The leading-zero alias of the REAL key no longer reaches binding.
        ->and($status('0'.$record->getKey()))->toBe(404);
})->with('rck_real_record_requests');

it('still refuses a diagnosis that belongs to another medical record', function () {
    // Parent-child ownership is the controller's check, after binding; the
    // constraint must leave it exactly where it was.
    $author = User::factory()->create();
    $otherVisit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);
    $otherRecord = MedicalRecord::factory()->create([
        'clinic_visit_id' => $otherVisit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $otherVisit->patient_id,
    ]);
    $foreign = MedicalRecordDiagnosis::query()->create([
        'medical_record_id' => $otherRecord->id,
        'clinic_visit_id' => $otherVisit->id,
        'branch_id' => $this->branch->id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => 'primary',
        'diagnosed_by' => $author->id,
        'diagnosed_at' => now(),
        'created_by' => $author->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(rckUrl('/rme/visits/{V}/medical-record/{M}/diagnoses/{S}', $this->visit, $this->record, (string) $foreign->id))
        ->assertNotFound();

    expect(MedicalRecordDiagnosis::query()->whereKey($foreign->id)->exists())->toBeTrue();
});
