<?php

use App\Modules\Observability\Models\ObservabilityRequestEvent;
use App\Modules\Observability\Models\ObservabilitySlowQuery;
use App\Modules\Observability\Support\LatencyCategory;
use App\Modules\Observability\Support\TelemetryCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — request telemetry capture.
|
| phpunit.xml turns telemetry OFF for the whole suite (so query-budget tests
| measure the application). Every test here turns it ON explicitly.
*/

uses()->group('Observability');

beforeEach(function () {
    seedAccessControl();
    config(['observability_console.enabled' => true]);
    // Never let a lottery prune run in the middle of an assertion.
    config(['observability_console.retention.lottery' => [0, 1]]);

    Route::middleware('web')->group(function () {
        Route::get('/__obs/ok', fn () => 'ok')->name('obs-test.ok');
        Route::get('/__obs/boom', function () {
            throw new RuntimeException('Gagal untuk pasien NIK 7371012345678901 hp 081234567890 password=hunter2 email budi@example.com');
        })->name('obs-test.boom');
        Route::get('/__obs/query', function () {
            DB::table('users')->where('name', 'Budi 7371012345678901')->count();

            return 'q';
        })->name('obs-test.query');
        Route::get('/__obs/cache', function () {
            Cache::get('obs-test-missing-key');
            Cache::put('obs-test-key', 'v', 60);
            Cache::get('obs-test-key');

            return 'c';
        })->name('obs-test.cache');
    });
});

function obsEvents()
{
    return ObservabilityRequestEvent::query()->orderBy('id')->get();
}

it('records an authenticated request with a route template, method, status and the OBS-1 request id', function () {
    $user = superAdmin();

    $response = $this->actingAs($user)->get('/__obs/ok')->assertOk();

    $event = obsEvents()->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->user_role)->toBe('Super Admin')
        ->and($event->method)->toBe('GET')
        ->and($event->route_name)->toBe('obs-test.ok')
        ->and($event->path_template)->toBe('/__obs/ok')
        ->and($event->status_code)->toBe(200)
        ->and($event->is_error)->toBeFalse()
        ->and($event->activity)->toBe('Lihat obs-test.ok')
        ->and($event->request_id)->toBe($response->headers->get('X-Request-ID'));
});

it('stores a route TEMPLATE, never the concrete URL or query string', function () {
    $this->actingAs(superAdmin())->get(route('developer-console.errors.show', ['event' => (string) Str::uuid()]).'?nik=7371012345678901');

    $event = obsEvents()->first();
    expect($event->path_template)->toBe('/dev-console/errors/{event}')
        ->and($event->path_template)->not->toContain('7371');
});

it('does not record a guest request that is neither an error nor slow', function () {
    $this->get('/__obs/ok')->assertOk();

    expect(obsEvents())->toHaveCount(0);
});

it('records a guest 404 for an unmatched path with id-shaped segments collapsed', function () {
    $this->get('/no-such-page/12345/abc')->assertNotFound();

    $event = obsEvents()->sole();
    expect($event->user_id)->toBeNull()
        ->and($event->status_code)->toBe(404)
        ->and($event->is_error)->toBeTrue()
        ->and($event->path_template)->toBe('/no-such-page/{x}/abc');
});

it('caps guest events per minute so a scanner cannot flood the table', function () {
    config(['observability_console.guest_events_per_minute' => 2]);

    foreach (range(1, 5) as $i) {
        $this->get('/scan-'.$i);
    }

    expect(obsEvents())->toHaveCount(2);
});

it('records a 403 with who and what, and the original authorization exception class', function () {
    $user = userWith([]);

    $this->actingAs($user)->get(route('developer-console.errors'))->assertForbidden();

    $event = obsEvents()->sole();
    expect($event->status_code)->toBe(403)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->route_name)->toBe('developer-console.errors')
        ->and($event->exception_class)->not->toBeNull();
});

it('records a web validation failure as 422 while keeping the real 302 response status', function () {
    $this->actingAs(superAdmin())
        ->get(route('developer-console.errors', ['status' => 999]))
        ->assertRedirect();

    $event = obsEvents()->sole();
    expect($event->status_code)->toBe(422)
        ->and($event->response_status)->toBe(302)
        ->and($event->is_error)->toBeTrue()
        ->and($event->exception_class)->toBe(ValidationException::class);
});

it('records an unauthenticated hit as 401 rather than the redirect that delivered it', function () {
    $this->get(route('developer-console.index'))->assertRedirect(route('login'));

    $event = obsEvents()->sole();
    expect($event->status_code)->toBe(401)
        ->and($event->response_status)->toBe(302);
});

it('records a 500 with a REDACTED message, a relative file and an argument-free trace', function () {
    $user = superAdmin();

    $this->actingAs($user)->get('/__obs/boom')->assertStatus(500);

    $event = obsEvents()->sole();
    expect($event->status_code)->toBe(500)
        ->and($event->exception_class)->toBe(RuntimeException::class)
        ->and($event->exception_file)->toBe('tests/Feature/Observability/ObservabilityTelemetryTest.php')
        ->and($event->exception_message)->not->toContain('7371012345678901')
        ->and($event->exception_message)->not->toContain('081234567890')
        ->and($event->exception_message)->not->toContain('hunter2')
        ->and($event->exception_message)->not->toContain('budi@')
        ->and($event->trace_summary)->toBeArray()->not->toBeEmpty();

    foreach ($event->trace_summary as $frame) {
        expect($frame)->not->toContain(base_path());
    }
});

it('marks a slow request and categorises it from configured thresholds only', function () {
    config(['observability_console.latency.watch_ms' => 0]);

    $this->actingAs(superAdmin())->get('/__obs/ok');

    $event = obsEvents()->sole();
    expect($event->is_slow)->toBeTrue()
        ->and($event->latency_category)->not->toBe(LatencyCategory::NORMAL);
});

it('categorises latency at each boundary', function (int $ms, string $expected) {
    expect(LatencyCategory::forDuration($ms))->toBe($expected);
})->with([
    [0, LatencyCategory::NORMAL],
    [499, LatencyCategory::NORMAL],
    [500, LatencyCategory::WATCH],
    [999, LatencyCategory::WATCH],
    [1000, LatencyCategory::SLOW],
    [2999, LatencyCategory::SLOW],
    [3000, LatencyCategory::VERY_SLOW],
]);

it('captures a slow query normalized, never with its bindings, and correlated to the request', function () {
    config(['observability_console.slow_query.threshold_ms' => 0]);

    $this->actingAs(superAdmin())->get('/__obs/query')->assertOk();

    $event = obsEvents()->sole();
    $queries = ObservabilitySlowQuery::query()->where('route_name', 'obs-test.query')->get();
    expect($queries)->not->toBeEmpty();

    $target = $queries->first(fn ($q) => str_contains($q->sql_normalized, '"name"') || str_contains($q->sql_normalized, 'name'));
    expect($target)->not->toBeNull()
        ->and($target->sql_normalized)->not->toContain('7371012345678901')
        ->and($target->sql_normalized)->not->toContain('Budi')
        ->and($target->fingerprint)->toMatch('/^[a-f0-9]{40}$/')
        ->and($target->request_id)->toBe($event->request_id)
        ->and($target->request_event_id)->toBe($event->id);

    expect($event->query_count)->toBeGreaterThan(0);
});

it('never records its own telemetry writes as slow queries (self-observability guard)', function () {
    config(['observability_console.slow_query.threshold_ms' => 0]);

    $this->actingAs(superAdmin())->get('/__obs/ok');

    expect(ObservabilitySlowQuery::query()->where('sql_normalized', 'like', '%sys_obs_%')->count())->toBe(0);
});

it('classifies slow-query severity at the critical boundary', function () {
    config(['observability_console.slow_query.critical_ms' => 2000]);

    expect(LatencyCategory::slowQuerySeverity(1999))->toBe('SLOW')
        ->and(LatencyCategory::slowQuerySeverity(2000))->toBe('CRITICAL');
});

it('counts application cache hits and misses on the request event', function () {
    $this->actingAs(superAdmin())->get('/__obs/cache');

    $event = obsEvents()->sole();
    expect($event->cache_hits)->toBeGreaterThanOrEqual(1)
        ->and($event->cache_misses)->toBeGreaterThanOrEqual(1);
});

it('never records health probes or the up endpoint', function () {
    $this->get('/up');
    $this->get(route('health.live'));

    expect(obsEvents())->toHaveCount(0);
});

it('records nothing at all when telemetry is disabled', function () {
    config(['observability_console.enabled' => false]);

    $this->actingAs(superAdmin())->get('/__obs/ok');
    $this->get('/missing-page');

    expect(obsEvents())->toHaveCount(0);
});

it('never breaks a request when the telemetry table is unavailable', function () {
    Schema::drop('sys_obs_slow_queries');
    Schema::drop('sys_obs_request_events');

    $this->actingAs(superAdmin())->get('/__obs/ok')->assertOk()->assertSee('ok');
});

it('leaves the collector inert outside an HTTP request (queue workers, Artisan)', function () {
    $collector = app(TelemetryCollector::class);
    DB::table('users')->count();

    expect($collector->isActive())->toBeFalse()
        ->and($collector->queryCount())->toBe(0);
});
