<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Support\Routing\DatabaseRouteKey;
use Database\Seeders\BranchSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-1 / R-01 — a visit segment that is not a database
| key never reaches the database.
|--------------------------------------------------------------------------
|
| Before this revision none of the `{clinicVisit}` routes constrained the
| segment, so implicit model binding ran `where "id" = '<segment>'` against
| the bigint primary key. On PostgreSQL that raises SQLSTATE 22P02 for
| `abc` / `{VISIT_ID}` and 22003 for a number beyond bigint: the request
| answered HTTP 500 and wrote an ERROR line. Production logged three of them
| (one was a literal `{VISIT_ID}` template placeholder pasted into a URL), and
| every such line holds the monitoring log signal at WATCH for a day.
|
| The fix is a route-level constraint, so a segment that cannot be a key
| answers 404 before any middleware or query runs.
|
| Which assertions are load-bearing where:
|   - the structural and compiled-route cases prove the constraint on every
|     driver;
|   - the guest cases are driver-independent too: an unconstrained route
|     MATCHES and redirects a guest to login, a constrained one answers 404;
|   - the authenticated 404/no-ERROR cases fail only on PostgreSQL without the
|     fix (SQLite compares loosely and simply finds no row). CI's critical
|     gate runs on PostgreSQL 16.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'RKC1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin = User::factory()->create()->assignRole('Super Admin');
});

dataset('rkc_non_key_segments', [
    'letters' => ['abc'],
    'template placeholder' => ['{VISIT_ID}'],
    'digits then letters' => ['12abc'],
    'negative number' => ['-1'],
    'decimal' => ['1.5'],
    'exponent' => ['1e3'],
    'zero' => ['0'],
    'leading zero' => ['007'],
    'beyond bigint' => ['99999999999999999999'],
]);

/**
 * Collect every log record at ERROR or above written during the test, so a
 * 500 that the test client happens to render is still caught by its log line.
 *
 * @return ArrayObject<int, string>
 */
function rkcCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

it('constrains every {clinicVisit} route to a database key', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('clinicVisit', $route->parameterNames(), true));

    // 44 routes carried the parameter when this revision was written; a drop
    // means routes were removed or renamed, which this suite must notice.
    expect($routes->count())->toBeGreaterThanOrEqual(44);

    $unconstrained = $routes
        ->reject(fn ($route) => ($route->wheres['clinicVisit'] ?? null) === DatabaseRouteKey::PATTERN)
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unconstrained)->toBe([]);
});

it('keeps the constraint in the compiled route collection that route:cache writes', function () {
    // Production serves cached routes. A pattern registered after the routes
    // were defined would be missing from the compiled attributes even though
    // it looks registered at runtime.
    $compiled = app('router')->getRoutes()->compile();

    $withParameter = collect($compiled['attributes'])
        ->filter(fn (array $attributes) => str_contains((string) $attributes['uri'], '{clinicVisit}'));

    expect($withParameter)->not->toBeEmpty();

    $withParameter->each(function (array $attributes) {
        expect($attributes['wheres']['clinicVisit'] ?? null)->toBe(DatabaseRouteKey::PATTERN, (string) $attributes['uri']);
    });
});

it('accepts exactly the keys a PostgreSQL bigint identity column can hold', function () {
    $matches = fn (string $segment): bool => preg_match('#^'.DatabaseRouteKey::PATTERN.'$#', $segment) === 1;

    expect($matches('1'))->toBeTrue()
        ->and($matches('42'))->toBeTrue()
        ->and($matches('999999999999999999'))->toBeTrue()
        ->and($matches('1000000000000000000'))->toBeFalse()
        ->and($matches('0'))->toBeFalse()
        ->and($matches('007'))->toBeFalse()
        ->and($matches('-1'))->toBeFalse()
        ->and($matches('1.5'))->toBeFalse()
        ->and($matches(' 1'))->toBeFalse()
        ->and($matches('abc'))->toBeFalse()
        ->and($matches('１'))->toBeFalse()
        ->and($matches('+1'))->toBeFalse();

    // The longest accepted key (18 nines) is below the bigint ceiling of
    // 9,223,372,036,854,775,807, so PostgreSQL can always cast it; the
    // behavioural case below proves that on the real engine.
});

it('answers 404 to a guest for a segment that is not a database key', function (string $segment) {
    $this->get('/rme/visits/'.rawurlencode($segment))->assertNotFound();
    $this->get('/rme/cashier/'.rawurlencode($segment).'/billing/create')->assertNotFound();
})->with('rkc_non_key_segments');

it('answers 404, never 500, to a signed-in user and logs no ERROR', function (string $segment) {
    $errors = rkcCaptureErrorLogs();

    $this->actingAs($this->admin)->get('/rme/visits/'.rawurlencode($segment))->assertNotFound();
    $this->actingAs($this->admin)->get('/rme/cashier/'.rawurlencode($segment).'/billing/create')->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('rkc_non_key_segments');

it('leaves no route a looser per-route constraint that overrides the global one', function (string $suffix) {
    // The visit-bound legacy-archive routes used to declare
    // `->whereNumber('clinicVisit')` (`[0-9]+`). A route-level constraint
    // overrides the global pattern, and `[0-9]+` still admits a number beyond
    // bigint — a 500 on PostgreSQL. The guest case is driver-independent: an
    // overridden route still matches and redirects to login.
    $errors = rkcCaptureErrorLogs();

    $this->get('/rme/visits/99999999999999999999/'.$suffix)->assertNotFound();
    $this->actingAs($this->admin)->get('/rme/visits/99999999999999999999/'.$suffix)->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with([
    'legacy RME archive' => ['legacy-archive/rme'],
    'legacy odontogram archive' => ['legacy-archive/odontogram'],
]);

it('answers 404 without an ERROR for the longest key that matches but names no visit', function () {
    $errors = rkcCaptureErrorLogs();

    $this->actingAs($this->admin)->get('/rme/visits/999999999999999999')->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
});

it('still serves a real visit by its numeric key', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk();
});

it('still lets a guest reach the login redirect for a real numeric key', function () {
    // The constraint must not turn the normal authentication flow into a 404.
    $this->get('/rme/visits/'.$this->visit->id)->assertRedirect();
});
