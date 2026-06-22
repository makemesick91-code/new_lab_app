<?php

/**
 * Sprint 58.4 — RME Dashboard Page & KPI Cards.
 *
 * Supersedes the Sprint 58.3 availability hotfix. Previously GET /rme/dashboard
 * redirected to rme.visits.index. It now renders a real standalone "Dashboard
 * RME" page. These route-level assertions confirm the named route still exists,
 * is gated, and no longer redirects.
 */

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::factory()->create([
        'code' => 'ATG3', 'name' => 'Cabang Antang',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);
});

it('registers the rme.dashboard named route', function () {
    expect(Route::has('rme.dashboard'))->toBeTrue();
});

it('renders a standalone dashboard page and does not redirect', function () {
    $user = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $response = $this->actingAs($user)->get(route('rme.dashboard'));

    $response->assertOk();
    expect($response->isRedirect())->toBeFalse();
    $response->assertSee('Dashboard RME');
});

it('does not return 404 for /rme/dashboard', function () {
    $user = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $response = $this->actingAs($user)->get('/rme/dashboard');

    expect($response->getStatusCode())->not->toBe(404);
});
