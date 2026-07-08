<?php

use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Owner');

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
});

// ---------------------------------------------------------------------------
// Owner + branch-admin dashboards still load / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished Owner dashboard landing for the Owner', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Owner')
        ->assertSee('Kartu KPI Eksekutif')
        ->assertSee('Performa Cabang');
});

it('renders the polished Branch Admin dashboard for a branch operational user', function () {
    $this->actingAs(userWith(['view dashboard', 'view_lab_orders', 'view_production']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Admin Cabang')
        ->assertSee('Ringkasan Harian')
        ->assertSee('Papan Antrean Kerja');
});

it('redirects guests to login for the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Design-system adoption on the owner landing + branch-admin views
// ---------------------------------------------------------------------------

it('uses foundation table, empty-state and select components on the owner landing view', function () {
    $view = file_get_contents(resource_path('views/dashboard.blade.php'));

    expect($view)->toContain('x-ui.table');
    expect($view)->toContain('x-ui.empty-state');
    expect($view)->toContain('x-ui.select');
});

it('keeps the branch-admin dashboard on x-ui.card and semantic tokens', function () {
    $view = file_get_contents(resource_path('views/dashboards/branch-admin.blade.php'));

    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('text-brand-700');
});

it('keeps the owner + branch dashboards free of legacy palette, gray and hardcoded hex', function () {
    $files = [
        resource_path('views/dashboard.blade.php'),
        resource_path('views/dashboards/branch-admin.blade.php'),
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toMatch('/\b(?:bg|text|border|ring)-(?:teal|emerald|amber|rose|sky)-\d/');
        expect($contents)->not->toMatch('/\b(?:bg|text|border|ring|divide)-gray-\d/');
        expect($contents)->not->toMatch('/#[0-9a-fA-F]{6}\b/');
    }
});

// ---------------------------------------------------------------------------
// Read-only + privacy guarantees (dashboard stays read-only, no PII)
// ---------------------------------------------------------------------------

it('keeps the owner + branch dashboards read-only with no mutating forms and no KTP/NIK', function () {
    $files = [
        resource_path('views/dashboard.blade.php'),
        resource_path('views/dashboards/branch-admin.blade.php'),
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toMatch('/method=["\'](?:POST|PUT|PATCH|DELETE)["\']/i');
        expect($contents)->not->toMatch('/@method\(["\'](?:PUT|PATCH|DELETE)["\']\)/i');
        expect($contents)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-13 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-13 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
