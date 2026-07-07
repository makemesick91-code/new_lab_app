<?php

/**
 * UIX-3 — Kunjungan list polish. Presentation-only; the page stays on the
 * DaengtisiaMS design system and remains the reference implementation for all
 * list pages. No controller/service/query/permission/BranchContext change.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'RME');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->rmeBranch = Branch::factory()->create([
        'code' => 'UIX3', 'name' => 'Cabang UIX3', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

// ---------------------------------------------------------------------------
// Page still renders / authorizes (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished Kunjungan list for an authorized user', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'name' => 'Pasien UIX Tiga',
    ]);
    ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Kunjungan Pasien')
        ->assertSee('Daftar Kunjungan')
        ->assertSee('Pasien UIX Tiga');
});

it('renders the design-system empty state when there are no visits', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Belum ada kunjungan.');
});

it('keeps the existing status filter param working', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['status' => 'waiting']))
        ->assertOk();
});

it('redirects guests to login for the Kunjungan list', function () {
    $this->get(route('rme.visits.index'))->assertRedirect(route('login'));
});

it('forbids users without the clinic-visit permission', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('rme.visits.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy brand color / hardcoded hex gone
// ---------------------------------------------------------------------------

it('uses x-ui foundation components and semantic tokens in the Kunjungan view', function () {
    $view = file_get_contents(resource_path('views/rme/visits/index.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.filter-bar');
    expect($view)->toContain('x-ui.table');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    expect($view)->toContain('x-ui.empty-state');
    // Status badge resolves tone via the design-system :status map.
    expect($view)->toContain(':status');
    // No legacy teal brand class, no gray legacy scale, no hardcoded hex.
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring|divide)-gray-\d/');
    expect($view)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-3 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-3 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
