<?php

/**
 * UIX-20 — Permission-aware UI consistency polish. Presentation only: visible
 * actions/links/empty states consistently respect the existing server-side
 * authorization boundary. No controller/service/route/permission/policy/Gate/
 * Spatie/BranchContext/business-logic change. Blade @can/@canany is presentation
 * only; server-side route middleware + policies remain authoritative.
 */

use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'UiFoundation', 'Permission');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

// ---------------------------------------------------------------------------
// Canonical component — permission-aware, non-submitting, announced.
// ---------------------------------------------------------------------------

it('provides a non-submitting canonical restricted-notice component', function () {
    $html = Blade::render('<x-ui.restricted-notice />');

    expect($html)->toContain('role="note"');
    expect($html)->toContain('Akses terbatas');
    // It is copy/clarity, never an action: it must never submit or mutate.
    expect($html)->not->toContain('<button');
    expect($html)->not->toContain('type="submit"');
    expect($html)->not->toContain('<form');
});

it('lets the restricted-notice carry a surface-specific description', function () {
    $html = Blade::render('<x-ui.restricted-notice description="Anda tidak memiliki akses untuk menambah produk." />');

    expect($html)->toContain('Anda tidak memiliki akses untuk menambah produk.');
});

// ---------------------------------------------------------------------------
// Real permission boundary — authorized vs view-only operator.
// ---------------------------------------------------------------------------

it('shows the create action to an operator who is authorized to manage rooms', function () {
    $this->actingAs(userWith(['manage_clinic_master_data']))
        ->get(route('settings.clinic-rooms.index'))
        ->assertOk()
        ->assertSee('+ Tambah Ruangan')
        ->assertDontSee('Akses terbatas');
});

it('shows a clear restricted notice (not a silent empty action) to a view-only operator', function () {
    $this->actingAs(userWith(['view_clinic_master_data']))
        ->get(route('settings.clinic-rooms.index'))
        ->assertOk()
        ->assertSee('Belum ada ruangan')
        ->assertSee('Akses terbatas')
        ->assertDontSee('+ Tambah Ruangan');
});

it('still redirects guests to login for the guarded surface', function () {
    $this->get(route('settings.clinic-rooms.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Guard preservation — representative surfaces keep real @can guards + companion.
// ---------------------------------------------------------------------------

it('keeps real permission guards and the restricted companion on representative surfaces', function () {
    $surfaces = [
        'resources/views/inventory/products/index.blade.php',
        'resources/views/rme/visits/index.blade.php',
        'resources/views/lab-orders/index.blade.php',
        'resources/views/settings/clinic-rooms/index.blade.php',
    ];

    foreach ($surfaces as $file) {
        $contents = file_get_contents(base_path($file));
        expect($contents)->toContain('@can');
        expect($contents)->toContain('x-ui.restricted-notice');
    }
});

// ---------------------------------------------------------------------------
// Governance gate.
// ---------------------------------------------------------------------------

it('passes the UI governance check with the UIX-20 permission-aware rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--strict' => true]);

    expect($exit)->toBe(0);
});
