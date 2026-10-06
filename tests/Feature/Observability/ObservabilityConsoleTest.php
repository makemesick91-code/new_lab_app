<?php

use App\Modules\Observability\Models\ObservabilityRequestEvent;
use App\Modules\Observability\Services\CacheObservabilityService;
use App\Modules\Observability\Services\ObservabilityConsoleService;
use App\Modules\Observability\Services\ObservabilityTelemetryRetention;
use App\Modules\Observability\Support\TelemetryRedactor;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — console pages, authorization,
| presence, redaction, retention and cache scope.
*/

uses()->group('Observability');

beforeEach(function () {
    seedAccessControl();
});

const OBS_CONSOLE_ROUTES = [
    'developer-console.index',
    'developer-console.live-users',
    'developer-console.errors',
    'developer-console.slow-requests',
    'developer-console.slow-queries',
    'developer-console.cache',
    'developer-console.diagnostics',
];

function obsSeedEvent(array $overrides = []): ObservabilityRequestEvent
{
    $id = DB::table('sys_obs_request_events')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'occurred_at' => now(),
        'request_id' => (string) Str::uuid(),
        'user_id' => null,
        'user_role' => null,
        'branch_id' => null,
        'method' => 'GET',
        'route_name' => 'rme.visits.index',
        'path_template' => '/rme/visits',
        'activity' => 'Lihat rme.visits.index',
        'status_code' => 200,
        'response_status' => 200,
        'duration_ms' => 120,
        'db_time_ms' => 10,
        'query_count' => 5,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'latency_category' => 'NORMAL',
        'is_error' => false,
        'is_slow' => false,
        'is_timeout' => false,
    ], $overrides));

    return ObservabilityRequestEvent::query()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// Authorization — server-side, every page
// ---------------------------------------------------------------------------

it('opens every console page for the Super Admin', function (string $route) {
    $this->actingAs(superAdmin())->get(route($route))->assertOk()->assertSee('Live Users');
})->with(OBS_CONSOLE_ROUTES);

it('forbids every console page to every operational role, including Owner', function (string $role) {
    $user = userInRole($role);
    foreach (OBS_CONSOLE_ROUTES as $route) {
        $this->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->actingAs($user)->get(route($route))->assertForbidden();
    }
})->with(['Owner', 'Doctor', 'Admin Klinik', 'Kasir', 'Supervisor RME', 'Perawat', 'Admin Lab']);

it('redirects guests away from every console page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(OBS_CONSOLE_ROUTES);

it('forbids the error detail page by direct URL to an unauthorized user', function () {
    $event = obsSeedEvent(['is_error' => true, 'status_code' => 500, 'response_status' => 500]);

    $this->actingAs(userWith([]))->get(route('developer-console.errors.show', $event))->assertForbidden();
});

it('shows the console menu group only to permission holders', function () {
    $this->actingAs(superAdmin())->get(route('developer-console.index'))
        ->assertSee(route('developer-console.slow-queries'), false);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Owner'))->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee(route('developer-console.slow-queries'), false);
});

it('keeps every console route GET-only (ENT7-DC001)', function () {
    $mutating = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'developer-console.'))
        ->filter(fn ($r) => array_diff($r->methods(), ['GET', 'HEAD']) !== []);

    expect($mutating)->toBeEmpty();
});

it('audits every console page view through the existing ENT-7 trail', function () {
    $admin = superAdmin();
    $this->actingAs($admin)->get(route('developer-console.slow-queries'))->assertOk();

    $this->assertDatabaseHas('sys_audit_logs', ['action' => 'VIEW_DEVELOPER_CONSOLE', 'performed_by' => $admin->id]);
});

// ---------------------------------------------------------------------------
// Presence
// ---------------------------------------------------------------------------

it('derives ONLINE, IDLE and OFFLINE from the latest request, and logout is OFFLINE', function () {
    config(['observability_console.presence.online_seconds' => 120, 'observability_console.presence.idle_seconds' => 300]);
    $online = userInRole('Kasir');
    $idle = userInRole('Doctor');
    $offline = userInRole('Perawat');
    $loggedOut = userInRole('Admin Klinik');

    obsSeedEvent(['user_id' => $online->id, 'occurred_at' => now()->subSeconds(30)]);
    obsSeedEvent(['user_id' => $idle->id, 'occurred_at' => now()->subSeconds(200)]);
    obsSeedEvent(['user_id' => $offline->id, 'occurred_at' => now()->subMinutes(20)]);
    obsSeedEvent(['user_id' => $loggedOut->id, 'occurred_at' => now()->subSeconds(5), 'route_name' => 'logout', 'method' => 'POST']);

    $presence = app(ObservabilityConsoleService::class)->presence();
    $byUser = collect($presence['rows'])->keyBy('user_id');

    expect($byUser[$online->id]['status'])->toBe('ONLINE')
        ->and($byUser[$idle->id]['status'])->toBe('IDLE')
        ->and($byUser[$offline->id]['status'])->toBe('OFFLINE')
        ->and($byUser[$loggedOut->id]['status'])->toBe('OFFLINE')
        ->and($presence['counts'])->toBe(['ONLINE' => 1, 'IDLE' => 1, 'OFFLINE' => 2]);
});

it('takes the presence boundary from config at exactly the online threshold', function () {
    config(['observability_console.presence.online_seconds' => 120]);
    $user = userInRole('Kasir');
    $this->travelTo(now());
    obsSeedEvent(['user_id' => $user->id, 'occurred_at' => now()->subSeconds(120)]);

    expect(app(ObservabilityConsoleService::class)->presence()['rows'][0]['status'])->toBe('ONLINE');
});

it('lists one row per user and the selected user recent activity, newest first', function () {
    $user = userInRole('Kasir');
    obsSeedEvent(['user_id' => $user->id, 'occurred_at' => now()->subMinutes(3), 'route_name' => 'rme.cashier.index']);
    obsSeedEvent(['user_id' => $user->id, 'occurred_at' => now()->subMinute(), 'route_name' => 'rme.visits.index']);

    $response = $this->actingAs(superAdmin())->get(route('developer-console.live-users', ['user' => $user->id]))->assertOk();

    expect(app(ObservabilityConsoleService::class)->presence()['rows'])->toHaveCount(1);
    $response->assertSeeInOrder(['Aktivitas terbaru', 'rme.visits.index', 'rme.cashier.index']);
});

// ---------------------------------------------------------------------------
// Errors / slow pages
// ---------------------------------------------------------------------------

it('lists errors with who/what/where and filters by status and route prefix', function () {
    $user = userInRole('Kasir');
    obsSeedEvent(['user_id' => $user->id, 'is_error' => true, 'status_code' => 500, 'response_status' => 500, 'route_name' => 'rme.cashier.store']);
    obsSeedEvent(['is_error' => true, 'status_code' => 404, 'response_status' => 404, 'route_name' => null, 'path_template' => '/missing']);

    $this->actingAs(superAdmin())->get(route('developer-console.errors', ['status' => 500, 'route' => 'rme.cashier']))
        ->assertOk()->assertSee('rme.cashier.store')->assertDontSee('/missing');
});

it('treats a route filter as a literal prefix, not a LIKE wildcard', function () {
    obsSeedEvent(['is_error' => true, 'status_code' => 500, 'response_status' => 500, 'route_name' => 'rme.cashier.store']);

    $this->actingAs(superAdmin())->get(route('developer-console.errors', ['route' => 'rme_']))
        ->assertOk()->assertDontSee('rme.cashier.store');
});

it('shows a sanitized error detail and 404s for a non-error event', function () {
    $error = obsSeedEvent(['is_error' => true, 'status_code' => 500, 'response_status' => 500,
        'exception_class' => 'RuntimeException', 'exception_message' => 'Boom [REDACTED]', 'trace_summary' => json_encode(['app/X.php:1  X->y()'])]);
    $plain = obsSeedEvent();

    $this->actingAs(superAdmin())->get(route('developer-console.errors.show', $error))
        ->assertOk()->assertSee('RuntimeException')->assertSee('Ringkasan trace');
    $this->actingAs(superAdmin())->get(route('developer-console.errors.show', $plain))->assertNotFound();
});

it('lists slow requests with DB time and query count and states the timeout limits honestly', function () {
    obsSeedEvent(['is_slow' => true, 'latency_category' => 'SLOW', 'duration_ms' => 1500, 'db_time_ms' => 900, 'query_count' => 140, 'route_name' => 'rme.reports.patients']);

    $this->actingAs(superAdmin())->get(route('developer-console.slow-requests'))
        ->assertOk()
        ->assertSee('rme.reports.patients')
        ->assertSee('kemungkinan N+1')
        ->assertSee('tidak terlihat');
});

it('aggregates slow queries per fingerprint with count, p95 and max', function () {
    foreach ([600, 700, 800, 2500] as $ms) {
        DB::table('sys_obs_slow_queries')->insert([
            'occurred_at' => now(), 'request_id' => 'r-'.$ms, 'route_name' => 'rme.visits.index',
            'fingerprint' => str_repeat('a', 40), 'sql_normalized' => 'select * from x where id = ?',
            'duration_ms' => $ms, 'severity' => $ms >= 2000 ? 'CRITICAL' : 'SLOW',
        ]);
    }

    $aggregate = app(ObservabilityConsoleService::class)->slowQueryAggregates([])[0];
    expect($aggregate['count'])->toBe(4)
        ->and($aggregate['max_ms'])->toBe(2500)
        ->and($aggregate['p95_ms'])->toBe(2500)
        ->and($aggregate['avg_ms'])->toBe(1150)
        ->and($aggregate['requests'])->toBe(4);

    $this->actingAs(superAdmin())->get(route('developer-console.slow-queries'))->assertOk()->assertSee('aaaaaaaaaaaa');
});

it('rejects an unbounded or malformed filter rather than querying with it', function () {
    $this->actingAs(superAdmin())
        ->get(route('developer-console.slow-queries', ['fingerprint' => "x' OR 1=1"]))
        ->assertSessionHasErrors('fingerprint');
});

// ---------------------------------------------------------------------------
// Redaction
// ---------------------------------------------------------------------------

it('redacts NIK, phone, passwords, tokens, emails and quoted literals from free text', function () {
    $out = app(TelemetryRedactor::class)->text(
        "Patient 7371012345678901 phone 081234567890 password=hunter2 token: abc123 Authorization: Bearer eyJabc.def email budi@example.com name='Siti Aminah' Key (ktp_number)=(7371012345678901) already exists"
    );

    foreach (['7371012345678901', '081234567890', 'hunter2', 'abc123', 'eyJabc', 'budi@', 'Siti Aminah'] as $leak) {
        expect($out)->not->toContain($leak);
    }
    expect($out)->toContain('[REDACTED]');
});

it('normalizes SQL so no literal survives and fingerprints stay stable', function () {
    $redactor = app(TelemetryRedactor::class);
    $a = $redactor->normalizeSql("select * from mst_patients where ktp_number = '7371012345678901' and id in (1, 2, 3) and age > 40");
    $b = $redactor->normalizeSql("select * from mst_patients where ktp_number = '1111' and id in (9, 8) and age > 7");

    expect($a)->not->toContain('7371')->not->toContain('40')
        ->and($a)->toBe($b)
        ->and($redactor->fingerprint($a))->toBe($redactor->fingerprint($b));
});

it('drops the interpolated SQL tail of a QueryException message', function () {
    $e = new QueryException('pgsql', 'select * from x where ktp = ?', ['7371012345678901'], new Exception('SQLSTATE[23505]: Unique violation'));

    $message = app(TelemetryRedactor::class)->exceptionMessage($e);
    expect($message)->toContain('SQLSTATE[23505]')
        ->and($message)->not->toContain('7371012345678901')
        ->and($message)->not->toContain('Connection:');
});

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------

it('prunes expired telemetry per class, keeps recent rows, and is idempotent', function () {
    config(['observability_console.retention' => array_merge(config('observability_console.retention'), [
        'access_hours' => 72, 'error_hours' => 720, 'slow_request_hours' => 336, 'slow_query_hours' => 336,
    ])]);

    $oldAccess = obsSeedEvent(['occurred_at' => now()->subHours(80)]);
    $recentAccess = obsSeedEvent(['occurred_at' => now()->subHours(10)]);
    $agingError = obsSeedEvent(['occurred_at' => now()->subDays(20), 'is_error' => true, 'status_code' => 500, 'response_status' => 500]);
    $oldError = obsSeedEvent(['occurred_at' => now()->subDays(31), 'is_error' => true, 'status_code' => 500, 'response_status' => 500]);
    $oldSlow = obsSeedEvent(['occurred_at' => now()->subDays(15), 'is_slow' => true, 'latency_category' => 'SLOW']);
    $slowError = obsSeedEvent(['occurred_at' => now()->subDays(20), 'is_slow' => true, 'is_error' => true, 'status_code' => 500, 'response_status' => 500]);
    DB::table('sys_obs_slow_queries')->insert([
        ['occurred_at' => now()->subDays(15), 'fingerprint' => str_repeat('b', 40), 'sql_normalized' => 'x', 'duration_ms' => 600, 'severity' => 'SLOW'],
        ['occurred_at' => now()->subDays(1), 'fingerprint' => str_repeat('c', 40), 'sql_normalized' => 'y', 'duration_ms' => 600, 'severity' => 'SLOW'],
    ]);

    $this->artisan('observability:prune')->assertSuccessful();

    $remaining = ObservabilityRequestEvent::query()->pluck('id')->all();
    expect($remaining)->toContain($recentAccess->id, $agingError->id, $slowError->id)
        ->and($remaining)->not->toContain($oldAccess->id)
        ->and($remaining)->not->toContain($oldError->id)
        ->and($remaining)->not->toContain($oldSlow->id)
        ->and(DB::table('sys_obs_slow_queries')->count())->toBe(1);

    expect(app(ObservabilityTelemetryRetention::class)->prune())->toBe(['access' => 0, 'slow_request' => 0, 'error' => 0, 'slow_query' => 0]);
});

it('bounds one prune pass by the batch size', function () {
    foreach (range(1, 5) as $i) {
        obsSeedEvent(['occurred_at' => now()->subHours(100)]);
    }

    expect(app(ObservabilityTelemetryRetention::class)->prune(2)['access'])->toBe(2)
        ->and(ObservabilityRequestEvent::query()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Cache / Redis scope
// ---------------------------------------------------------------------------

it('reports a null hit rate, never 0%, when there were no lookups', function () {
    expect(CacheObservabilityService::hitRate(0, 0))->toBeNull()
        ->and(CacheObservabilityService::hitRate(3, 1))->toBe(75.0);
});

it('reports Redis as NOT_IN_USE when no backend uses it, without probing', function () {
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
    Redis::shouldReceive('connection')->never();

    expect(app(CacheObservabilityService::class)->redisInfo()['status'])->toBe('NOT_IN_USE');
    $this->actingAs(superAdmin())->get(route('developer-console.cache'))->assertOk()->assertSee('NOT_IN_USE');
});

it('reports Redis as UNAVAILABLE without leaking the connection message', function () {
    config(['observability_console.redis.probe_when_unused' => true]);
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused to secret-host:6379 password=hunter2'));

    $info = app(CacheObservabilityService::class)->redisInfo();
    expect($info['status'])->toBe('UNAVAILABLE')
        ->and($info['reason'])->not->toContain('secret-host')
        ->and($info['reason'])->not->toContain('hunter2');
});

it('labels Redis INFO as a SERVER hit rate and surfaces only allow-listed counters', function () {
    config(['observability_console.redis.probe_when_unused' => true]);
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('info')->andReturn([
        'Stats' => ['keyspace_hits' => 90, 'keyspace_misses' => 10, 'evicted_keys' => 0, 'expired_keys' => 4],
        'Memory' => ['used_memory_human' => '1.2M'],
        'Clients' => ['connected_clients' => 3],
        'Server' => ['redis_version' => '7.2.4', 'config_file' => '/etc/redis/secret.conf'],
    ]);
    Redis::shouldReceive('connection')->andReturn($connection);

    $info = app(CacheObservabilityService::class)->redisInfo();
    expect($info['status'])->toBe('CONNECTED')
        ->and($info['hit_rate'])->toBe(90.0)
        ->and($info['connected_clients'])->toBe(3)
        ->and($info)->not->toHaveKey('config_file');

    $this->actingAs(superAdmin())->get(route('developer-console.cache'))
        ->assertOk()->assertSee('Redis Server Hit Rate')->assertDontSee('secret.conf');
});

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------

it('renders real health rows and 24h KPIs from telemetry', function () {
    obsSeedEvent(['is_error' => true, 'status_code' => 500, 'response_status' => 500]);
    obsSeedEvent(['is_slow' => true, 'latency_category' => 'WATCH', 'duration_ms' => 700]);

    $data = app(ObservabilityConsoleService::class)->overview();
    expect($data['kpis']['errors'])->toBe(1)
        ->and($data['kpis']['server_errors'])->toBe(1)
        ->and($data['kpis']['slow_requests'])->toBe(1)
        ->and(collect($data['health'])->pluck('name')->all())->toContain('Application', 'Queue', 'Storage', 'Redis')
        ->and($data['trends']['errors'])->toHaveCount(25);

    $this->actingAs(superAdmin())->get(route('developer-console.index'))
        ->assertOk()->assertSee('System Health')->assertSee('Errors per jam')->assertSee('Lihat sebagai tabel');
});

// ---------------------------------------------------------------------------
// Adversarial-review regressions
// ---------------------------------------------------------------------------

it('never stores the row data a PostgreSQL "Failing row contains" DETAIL carries', function () {
    $pdo = new Exception("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" violates not-null constraint\nDETAIL:  Failing row contains (45, DG-TLK1-2024-9985, Budi Santoso, 1990-01-01, F, Jl. Sudirman 12).");
    $e = new QueryException('pgsql', 'insert into mst_patients ...', [], $pdo);

    $message = app(TelemetryRedactor::class)->exceptionMessage($e);
    foreach (['Budi Santoso', 'DG-TLK1', '1990-01-01', 'Sudirman', 'Failing row'] as $leak) {
        expect($message)->not->toContain($leak);
    }
    expect($message)->toContain('SQLSTATE[23502]');
});

it('rebuilds an unmatched-route 404 message from the redacted template, never the raw path', function () {
    $e = new NotFoundHttpException('The route patients/budi-santoso-1990/edit could not be found.');

    $message = app(TelemetryRedactor::class)->exceptionMessage($e, '/patients/{x}/edit');
    expect($message)->toBe('Route tidak ditemukan: /patients/{x}/edit')->not->toContain('budi');
});

it('stores only the field keys of a validation failure, never the message that quotes values', function () {
    $e = ValidationException::withMessages([
        'selected_rme_date' => 'Tanggal tidak boleh mendahului tanggal lahir pasien (09-03-2020).',
        'source_rm' => 'Nomor RM DG-LDK2-2024-22681 tidak cocok.',
    ]);

    $message = app(TelemetryRedactor::class)->exceptionMessage($e);
    expect($message)->toBe('Validasi gagal (2 field): selected_rme_date, source_rm');
});

it('redacts separator-formatted phones and NIKs, Nomor RM and calendar dates', function () {
    $out = app(TelemetryRedactor::class)->text('call +62 812-3456-7890 nik 3201 0101 0101 0001 rm DG-SPN4-2026-564 born 09-03-2020 or 2020/03/09');

    foreach (['812-3456', '3201 0101', 'DG-SPN4', '09-03-2020', '2020/03/09'] as $leak) {
        expect($out)->not->toContain($leak);
    }
});

it('marks a user OFFLINE on their real logout request', function () {
    config(['observability_console.enabled' => true, 'observability_console.retention.lottery' => [0, 1]]);
    $user = superAdmin();

    $this->actingAs($user)->post(route('logout'));

    $event = ObservabilityRequestEvent::query()->where('route_name', 'logout')->sole();
    expect($event->user_id)->toBe($user->id)
        ->and(app(ObservabilityConsoleService::class)->presenceStatus($event))->toBe('OFFLINE');
});

it('excludes "up" exactly, so a scanner cannot hide behind an "up…" prefix', function () {
    config(['observability_console.enabled' => true, 'observability_console.retention.lottery' => [0, 1]]);

    $this->get('/uploads/../../etc/passwd');

    expect(ObservabilityRequestEvent::query()->count())->toBe(1);
});

it('keeps error and slow rows at least as long as plain access rows, whatever the config', function () {
    config(['observability_console.retention' => array_merge(config('observability_console.retention'), [
        'access_hours' => 24 * 60, 'error_hours' => 24, 'slow_request_hours' => 24,
    ])]);

    $error = obsSeedEvent(['occurred_at' => now()->subDays(10), 'is_error' => true, 'status_code' => 500, 'response_status' => 500]);
    $slow = obsSeedEvent(['occurred_at' => now()->subDays(10), 'is_slow' => true, 'latency_category' => 'SLOW']);

    app(ObservabilityTelemetryRetention::class)->prune();

    expect(ObservabilityRequestEvent::query()->pluck('id')->all())->toContain($error->id, $slow->id);
});

it('aggregates hourly trends exactly in SQL, including the p95 per hour', function () {
    $this->travelTo(now()->startOfHour()->addMinutes(30));
    foreach ([100, 200, 300, 400, 5000] as $ms) {
        obsSeedEvent(['duration_ms' => $ms, 'occurred_at' => now()]);
    }
    obsSeedEvent(['is_error' => true, 'status_code' => 500, 'response_status' => 500, 'occurred_at' => now()]);

    $trends = app(ObservabilityConsoleService::class)->overview()['trends'];
    $last = fn (string $series) => collect($trends[$series])->last()['value'];

    expect($last('errors'))->toBe(1)
        ->and($last('p95_ms'))->toBe(5000)
        ->and($trends['p95_sampled'])->toBeFalse();
});

it('computes nearest-rank percentiles exactly at rank boundaries', function () {
    expect(ObservabilityConsoleService::percentile(range(1, 100), 95))->toBe(95)
        ->and(ObservabilityConsoleService::percentile(range(1, 20), 95))->toBe(19)
        ->and(ObservabilityConsoleService::percentile([7], 95))->toBe(7)
        ->and(ObservabilityConsoleService::percentile([], 95))->toBe(0);
});

it('cuts a single-line DETAIL tail from any exception, not only QueryException (M1)', function () {
    $e = new RuntimeException('Insert failed: null value in column name DETAIL: Failing row contains (45, Budi Santoso, Jl. Sudirman 12)');

    $message = app(TelemetryRedactor::class)->exceptionMessage($e);
    expect($message)->toBe('Insert failed: null value in column name')
        ->not->toContain('Budi Santoso');
});

it('refuses a console page from the FormRequest layer even if the route middleware is bypassed (M6)', function () {
    $this->withoutMiddleware(PermissionMiddleware::class)
        ->actingAs(userWith([]))
        ->get(route('developer-console.errors'))
        ->assertForbidden();
});

it('replaces quoted string literals in SQL, not only numbers (M9)', function () {
    $sql = app(TelemetryRedactor::class)->normalizeSql("select * from mst_patients where name = 'Siti Aminah' and address = 'Jl. Merdeka'");

    expect($sql)->toBe('select * from mst_patients where name = ? and address = ?');
});
