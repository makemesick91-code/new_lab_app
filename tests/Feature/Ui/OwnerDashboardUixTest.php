<?php

use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Owner');

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
});

// ---------------------------------------------------------------------------
// Owner dashboard still loads / authorizes (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished Owner dashboard for the Owner', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard KPI Owner')
        ->assertSee('Low Stock');
});

it('redirects guests to login for the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy brand color gone
// ---------------------------------------------------------------------------

it('uses design-system tokens and no legacy teal brand class in the Owner KPI view', function () {
    $view = file_get_contents(resource_path('views/dashboards/owner-kpi.blade.php'));

    expect($view)->toContain('x-ui.kpi-card');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('text-brand-700');
    // Revenue KPI keeps the gold accent, but gold is never a CTA.
    expect($view)->toContain(':accent');
    expect($view)->not->toContain('variant="gold"');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('keeps the whole Owner dashboard surface free of legacy teal brand classes', function () {
    $files = [
        resource_path('views/dashboard.blade.php'),
        resource_path('views/dashboards/owner-kpi.blade.php'),
        ...glob(resource_path('views/components/owner-dashboard/*.blade.php')),
    ];

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-2 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-2 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
