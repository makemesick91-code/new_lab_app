<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'UiFoundation');

beforeEach(function () {
    seedAccessControl();
});

// ---------------------------------------------------------------------------
// Component catalog route (dev-only, permission-gated like ENT-7 console)
// ---------------------------------------------------------------------------

it('lets the Super Admin open the UI component catalog', function () {
    $this->actingAs(superAdmin())
        ->get(route('developer-console.ui-catalog'))
        ->assertOk()
        ->assertSee('UI Component Catalog');
});

it('forbids a user without the developer console permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('developer-console.ui-catalog'))
        ->assertForbidden();
});

it('redirects guests to login for the catalog', function () {
    $this->get(route('developer-console.ui-catalog'))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Component rendering
// ---------------------------------------------------------------------------

it('renders the primary button as blue brand, never gold', function () {
    $html = Blade::render('<x-ui.button variant="primary">Simpan</x-ui.button>');

    expect($html)->toContain('bg-brand-600');
    expect($html)->not->toContain('bg-gold');
});

it('maps domain statuses to badge tones', function () {
    expect(Blade::render('<x-ui.badge status="paid" />'))->toContain('text-success-700');
    expect(Blade::render('<x-ui.badge status="cashier_pending" />'))->toContain('text-gold-700');
    expect(Blade::render('<x-ui.badge status="cancelled" />'))->toContain('text-danger-700');
    expect(Blade::render('<x-ui.badge status="in_progress" />'))->toContain('text-info-700');
});

it('renders each core x-ui component without error', function () {
    expect(Blade::render('<x-ui.card title="T" accent="true">body</x-ui.card>'))->toContain('body');
    expect(Blade::render('<x-ui.input label="Nama" name="nama" />'))->toContain('name="nama"');
    expect(Blade::render('<x-ui.select label="S" name="s"><option>a</option></x-ui.select>'))->toContain('<select');
    expect(Blade::render('<x-ui.textarea label="C" name="c" />'))->toContain('<textarea');
    expect(Blade::render('<x-ui.alert variant="warning" title="W">msg</x-ui.alert>'))->toContain('msg');
    expect(Blade::render('<x-ui.empty-state title="Kosong" />'))->toContain('Kosong');
    expect(Blade::render('<x-ui.skeleton :lines="3" />'))->toContain('animate-pulse');
    expect(Blade::render('<x-ui.page-header title="Judul" />'))->toContain('Judul');
    expect(Blade::render('<x-ui.kpi-card label="L" value="10" accent="true" />'))->toContain('before:bg-gold');
});

// ---------------------------------------------------------------------------
// Governance command
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
