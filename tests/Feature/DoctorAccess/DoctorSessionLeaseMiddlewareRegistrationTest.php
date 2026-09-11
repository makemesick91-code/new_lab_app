<?php

declare(strict_types=1);

/*
| DOCTOR-ACCESS PR-B, FIRST OBLIGATION — close the PR-A proof gap.
|
| PR-A shipped EnsureDoctorSessionLease as a globally appended `web` middleware
| and verified its registration by READING bootstrap/app.php. That is not a
| regression guard: nothing would fail if a future edit appended it twice, or
| moved it after the middleware whose ordering it depends on.
|
| WHY THE GAP EXISTED, so nobody closes it the wrong way later:
|
|   `php artisan route:list` CANNOT see this middleware. Route::gatherMiddleware()
|   returns the RAW action entries — 'web', 'auth', 'permission:…' — and a group's
|   members are never expanded into them. A group-appended class therefore appears
|   on zero routes no matter how many times it is registered, so counting
|   route:list output proves nothing in either direction.
|
|   A production REPL is forbidden, so the registration cannot be inspected on the
|   deployed host either.
|
| WHAT ACTUALLY WORKS is asking the router for the resolved group, which is what
| this file does. The HTTP kernel MUST be resolved first: in a console context
| (and `php artisan test` is one) the router's middleware groups are not populated
| until the kernel is built, so a bare app('router') call reports an empty `web`
| group and every assertion below would pass vacuously. The first test asserts the
| group is non-empty precisely so that failure mode cannot hide the others.
|
| ORDERING IS A CORRECTNESS PROPERTY, NOT A STYLE CHOICE. TouchOnlineContextLastSeen
| writes `last_seen_at` on every authenticated request, and clinic-room occupancy is
| derived from that row. If the lease middleware ran AFTER it, the very request that
| evicts a doctor would first refresh their presence, leaving a ghost holding a
| consulting room. Prepending is not an option either: StartSession lives inside the
| group, so a prepended middleware has no session and no authenticated user.
*/

use App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease;
use App\Modules\RmeOnlineContext\Middleware\TouchOnlineContextLastSeen;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;

/**
 * The resolved `web` group, as the framework will actually run it.
 *
 * TRAP: resolving the kernel is what populates the router's middleware groups in
 * a console context. Without it this returns [] and every assertion passes for
 * the wrong reason.
 */
function dsmWebGroup(): array
{
    app(Kernel::class);

    return array_values(array_map(
        static fn ($entry) => is_string($entry) ? $entry : $entry::class,
        app('router')->getMiddlewareGroups()['web'] ?? [],
    ));
}

it('populates the web middleware group at all, so the assertions below cannot pass vacuously', function () {
    expect(dsmWebGroup())->not->toBeEmpty();
});

it('registers the session-lease middleware on the web group exactly once', function () {
    $occurrences = array_keys(dsmWebGroup(), EnsureDoctorSessionLease::class, true);

    // Exactly one. A second append would make this 2 and is the regression this
    // whole file exists to catch: the middleware would then run twice per request,
    // and its eviction branch would tear a session down and then act on a session
    // that no longer exists.
    expect($occurrences)->toHaveCount(1);
});

it('orders the session-lease middleware ahead of the presence-touch middleware', function () {
    $group = dsmWebGroup();

    $lease = array_search(EnsureDoctorSessionLease::class, $group, true);
    $touch = array_search(TouchOnlineContextLastSeen::class, $group, true);

    expect($lease)->not->toBeFalse('the session-lease middleware is absent from the web group');
    expect($touch)->not->toBeFalse('the presence-touch middleware is absent from the web group');

    // Strictly before. If this inverts, an evicted doctor's clinic room stays
    // marked occupied and blocks the doctor taking over from them.
    expect($lease)->toBeLessThan($touch);
});

it('runs the session-lease middleware after the session starts and first among the appended tail', function () {
    $group = dsmWebGroup();

    $lease = array_search(EnsureDoctorSessionLease::class, $group, true);
    $startSession = array_search(StartSession::class, $group, true);

    expect($startSession)->not->toBeFalse('StartSession is absent from the web group');

    // AFTER StartSession, necessarily. The middleware reads the session to find a
    // lease token and reads the authenticated user; prepending it ahead of
    // StartSession would give it neither. This is why the registration is an
    // append and not a prepend.
    expect($lease)->toBeGreaterThan($startSession);

    // FIRST among the entries this project appends. Note that one project
    // middleware legitimately sits BEFORE the framework's stack —
    // App\Http\Middleware\AttachRequestCorrelationContext is PREPENDED by the
    // observability foundation so that a request id exists for everything that
    // follows — so "first App\ entry in the group" would be the wrong test and
    // would fail for a correct arrangement. The claim is narrower and real: of the
    // project middleware that run after the session exists, the lease check is
    // first, because its decision can end the request before any other appended
    // middleware writes anything.
    $appendedTail = array_values(array_filter(
        array_slice($group, (int) $startSession + 1),
        static fn (string $class): bool => str_starts_with($class, 'App\\'),
    ));

    expect($appendedTail)->not->toBeEmpty()
        ->and($appendedTail[0])->toBe(EnsureDoctorSessionLease::class);
});

it('registers the middleware exactly once in the bootstrap source, independently of the resolved group', function () {
    // A second, deliberately different witness — and MEASUREMENT PROVED IT IS THE
    // LOAD-BEARING ONE. A double registration was injected into bootstrap/app.php
    // and the resolved group STILL reported exactly one entry: Laravel
    // de-duplicates a middleware group, so the append-twice edit cannot cause
    // double execution. Only this source-level check failed on it.
    //
    // Two consequences worth keeping. The runtime risk of an accidental second
    // append is smaller than it looks, because the framework collapses it. And the
    // resolved-group assertion above, on its own, could NEVER have caught the edit
    // — so a single witness here would have been another unproven guard, which is
    // the exact PR-A mistake this file was written to close.
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

    // One import plus one registration. Anything else means a second append, a
    // removed registration, or a stray reference.
    expect(substr_count($bootstrap, 'EnsureDoctorSessionLease'))->toBe(2);
    expect(substr_count($bootstrap, 'EnsureDoctorSessionLease::class'))->toBe(1);
});

it('does not reach the route-middleware list, which is why route:list cannot see it', function () {
    // Pinning the CONSTRAINT, not just the current state. If someone later
    // route-attaches this middleware to make it "visible", it acquires a device
    // token on a route and trips the contract in
    // DoctorDeviceApiAndNoEnforcementTest, which requires the only device
    // middleware on any non device-api route to be EnsureDoctorDeviceSession.
    // Keeping it group-only is what keeps that contract satisfied.
    app(Kernel::class);

    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $entry) {
            if (is_string($entry)) {
                expect($entry)->not->toBe(EnsureDoctorSessionLease::class);
            }
        }
    }
});
