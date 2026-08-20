<?php

/**
 * UIX-4 — RME + Odontogram polish. Presentation-only; the RME detail and
 * Odontogram pages stay on the DaengtisiaMS design system and become the
 * reference implementation for all clinical pages. No controller/service/
 * query/permission/BranchContext/schema change; no KTP/NIK exposure.
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

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->rmeBranch = Branch::factory()->create([
        'code' => 'UIX4', 'name' => 'Cabang UIX4', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->manager = userWith(['manage_clinic_visits', 'view_clinic_visits']);
});

// ---------------------------------------------------------------------------
// Page still renders / authorizes (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished RME detail for an authorized user', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'name' => 'Pasien UIX Empat',
    ]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Detail Kunjungan')
        ->assertSee('Pasien UIX Empat')
        ->assertSee($visit->visit_number);
});

it('renders the polished Odontogram section for an authorized user', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Odontogram')
        ->assertSee('Peta Gigi');
});

it('no longer renders the RME print action route on the detail page', function () {
    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-04 — SUPERSEDED: "Cetak RME" moved
    // to the Rekam Medis page. Proven present there by MedicalRecordPageActionsTest.
    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee(route('rme.visits.print', $visit), false);
});

it('redirects guests to login for the RME detail page', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->get(route('rme.visits.show', $visit))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Privacy — KTP/NIK never exposed on the clinical detail surface
// ---------------------------------------------------------------------------

it('never exposes the patient KTP number on the RME detail page', function () {
    $secretKtp = '7371091234567890';
    $patient = Patient::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'name' => 'Pasien Privasi',
        'ktp_number' => $secretKtp,
        'phone' => '081100002222',
        'whatsapp_number' => '081100003333',
    ]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee($secretKtp);
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy brand color gone (static view scan)
// ---------------------------------------------------------------------------

it('uses x-ui foundation components and semantic tokens in the RME detail view', function () {
    $view = file_get_contents(resource_path('views/rme/visits/show.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    expect($view)->toContain('x-ui.alert');
    // Status badge resolves tone via the design-system :status map.
    expect($view)->toContain(':status');
    // No legacy teal brand class, no hardcoded hex, no gold CTA, no KTP/NIK field.
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
    expect($view)->not->toContain('variant="gold"');
    expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
});

it('keeps the Odontogram chrome on brand tokens while preserving clinical status colors', function () {
    $view = file_get_contents(resource_path('views/rme/visits/odontogram/show.blade.php'));

    // Chrome retokenized: no legacy teal, no gold CTA.
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toContain('variant="gold"');
    // Clinical status colors intentionally preserved (distinguishability + print parity).
    expect($view)->toContain('bg-red-200');
    expect($view)->toContain('bg-blue-200');
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-4 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-4 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
