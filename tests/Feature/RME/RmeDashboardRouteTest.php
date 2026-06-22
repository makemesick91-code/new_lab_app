<?php

/**
 * Sprint 58.3 — RME Dashboard Route Availability Hotfix.
 *
 * Confirmed VPS behaviour before the fix: GET /rme/dashboard returned HTTP 404
 * and the route was absent from `php artisan route:list`. This hotfix adds a
 * named route `rme.dashboard` that redirects safely to the existing
 * `rme.visits.index`. No new dashboard UI, no business-logic / schema /
 * permission changes.
 */

use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

it('registers the rme.dashboard named route', function () {
    expect(Route::has('rme.dashboard'))->toBeTrue();
});

it('redirects an authenticated allowed RME user from /rme/dashboard to /rme/visits', function () {
    $user = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs($user)
        ->get(route('rme.dashboard'))
        ->assertRedirect(route('rme.visits.index'));
});

it('does not return 404 for /rme/dashboard', function () {
    $user = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $response = $this->actingAs($user)->get('/rme/dashboard');

    expect($response->getStatusCode())->not->toBe(404)
        ->and($response->isRedirect())->toBeTrue();
});
