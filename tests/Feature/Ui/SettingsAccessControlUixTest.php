<?php

/**
 * UIX-14 — Settings, master data & access-control polish. Presentation-only; the
 * settings/master-data/access-control surfaces stay on the DaengtisiaMS design
 * system. No controller/service/route/permission/policy/Gate/Spatie/BranchContext/
 * master-data-semantics change; WA stays manual-only.
 */

use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Settings');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic / RBAC regression)
// ---------------------------------------------------------------------------

it('renders the polished clinic-rooms master list for an authorized user', function () {
    $this->actingAs(userWith(['view_clinic_master_data']))
        ->get(route('settings.clinic-rooms.index'))
        ->assertOk()
        ->assertSee('Daftar Ruangan')
        ->assertSee('Belum ada ruangan');
});

it('renders the polished user management list for an authorized user', function () {
    $admin = userWith(['manage users']);

    $this->actingAs($admin)
        ->get(route('settings.users.index'))
        ->assertOk()
        ->assertSee('Daftar Pengguna')
        ->assertSee($admin->name);
});

it('renders the polished role management list for an authorized user', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertSee('Daftar Role');
});

it('keeps the WA reminder template list manual-only safety notice', function () {
    $this->actingAs(userWith(['view_clinic_master_data']))
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertSee('belum mengirim WhatsApp otomatis');
});

it('redirects guests to login for the settings surfaces', function () {
    $this->get(route('settings.clinic-rooms.index'))->assertRedirect(route('login'));
    $this->get(route('settings.users.index'))->assertRedirect(route('login'));
});

it('forbids users without the required master-data / access permission', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('settings.clinic-rooms.index'))
        ->assertForbidden();

    $this->actingAs(userWith(['view dashboard']))
        ->get(route('settings.users.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy palette / hardcoded hex gone
// ---------------------------------------------------------------------------

it('uses x-ui foundation components and semantic tokens in all settings list views', function () {
    $views = [
        'settings/clinic-rooms/index',
        'settings/treatment-categories/index',
        'settings/treatments/index',
        'settings/tariffs/index',
        'settings/payment-methods/index',
        'settings/branches/index',
        'settings/wa-reminder-templates/index',
        'settings/users/index',
        'settings/roles/index',
        'settings/permissions/index',
    ];

    foreach ($views as $view) {
        $contents = file_get_contents(resource_path("views/{$view}.blade.php"));

        expect($contents)->toContain('x-ui.filter-bar');
        expect($contents)->toContain('x-ui.table');
        expect($contents)->toContain('x-ui.badge');
        expect($contents)->toContain('x-ui.button');
        expect($contents)->toContain('x-ui.empty-state');
        // No legacy palette, no legacy gray scale, no hardcoded hex.
        expect($contents)->not->toMatch('/\b(?:bg|text|border|ring|divide)-(?:teal|indigo|emerald|amber|rose|sky|purple)-\d/');
        expect($contents)->not->toMatch('/\b(?:bg|text|border|ring|divide)-gray-\d/');
        expect($contents)->not->toMatch('/#[0-9a-fA-F]{6}\b/');
        // Gold never a CTA, no KTP/NIK exposure.
        expect($contents)->not->toContain('variant="gold"');
        expect($contents)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }
});

it('adopts x-ui form controls in the clinic-rooms master-data form reference', function () {
    $form = file_get_contents(resource_path('views/settings/clinic-rooms/_form.blade.php'));

    expect($form)->toContain('x-ui.input');
    expect($form)->toContain('x-ui.select');
    expect($form)->toContain('x-ui.textarea');
});

it('keeps settings/access forms off the legacy teal/indigo palette', function () {
    $forms = [
        'settings/clinic-rooms/_form',
        'settings/roles/_form',
        'settings/users/_form',
        'settings/wa-reminder-templates/_form',
    ];

    foreach ($forms as $form) {
        $contents = file_get_contents(resource_path("views/{$form}.blade.php"));

        expect($contents)->not->toMatch('/\b(?:bg|text|border|ring|divide|focus:border|focus:ring)-(?:teal|indigo)-\d/');
        expect($contents)->not->toContain('variant="gold"');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-14 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-14 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
