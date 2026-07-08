<?php

/**
 * UIX-11 — RME medical record, odontogram & print bundle polish.
 * Presentation-only; the doctor-facing clinical documentation pages (medical
 * record list/detail/empty, odontogram detail) and the print/PDF bundle move
 * onto the DaengtisiaMS design system. No controller/service/query/route/
 * permission/policy/BranchContext/schema change; no RME finalization,
 * handwriting-required, odontogram-finalization, room gate, or cashier logic
 * change; SOAP stays hidden; no KTP/NIK exposure; print stays table-based.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'RME');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->rmeBranch = Branch::factory()->create([
        'code' => 'UIX11', 'name' => 'Cabang UIX11', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->manager = userWith(['manage_clinic_visits', 'view_clinic_visits']);
});

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished medical record list for an authorized user', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Daftar Rekam Medis');
});

it('renders the polished odontogram detail for an authorized user', function () {
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

it('redirects guests to login for the medical record list', function () {
    $this->get(route('rme.medical-records.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Privacy — KTP/NIK never exposed on the clinical documentation surface
// ---------------------------------------------------------------------------

it('never exposes the patient KTP number on the medical record list', function () {
    $secretKtp = '7371091234567891';
    $patient = Patient::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'name' => 'Pasien RM Privasi',
        'ktp_number' => $secretKtp,
    ]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
    ]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'branch_id' => $this->rmeBranch->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Pasien RM Privasi')
        ->assertDontSee($secretKtp);
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy brand color gone (static view scan)
// ---------------------------------------------------------------------------

it('uses x-ui foundation components in the medical record detail view', function () {
    $view = file_get_contents(resource_path('views/rme/visits/medical-record/show.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    expect($view)->toContain('x-ui.alert');
    // No legacy teal chrome, no gold CTA, no KTP/NIK field.
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toContain('variant="gold"');
    expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
});

it('uses the list standard in the medical record list view', function () {
    $view = file_get_contents(resource_path('views/rme/visits/medical-record/index.blade.php'));

    foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
        expect($view)->toContain($component);
    }
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toContain('variant="gold"');
});

it('keeps the odontogram chrome on brand tokens while preserving clinical status colors', function () {
    $view = file_get_contents(resource_path('views/rme/visits/odontogram/show.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    expect($view)->not->toContain('variant="gold"');
    // Clinical status colors intentionally preserved (distinguishability + print parity).
    expect($view)->toContain('bg-red-200');
    expect($view)->toContain('bg-blue-200');
});

it('keeps the print bundle on brand hex, table-based, without KTP or teal', function () {
    foreach ([
        'views/rme/visits/print.blade.php',
        'views/rme/visits/print-pdf.blade.php',
        'views/rme/visits/odontogram/print.blade.php',
    ] as $path) {
        $view = file_get_contents(resource_path($path));
        // Legacy teal hex converted to brand hex.
        expect($view)->not->toMatch('/#0f766e|#0d9488/i');
        // Identity numbers never reach print/PDF.
        expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }

    // dompdf PDF template stays table-based (no flex layout engine dependency).
    $pdf = file_get_contents(resource_path('views/rme/visits/print-pdf.blade.php'));
    expect($pdf)->not->toContain('display: flex');
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-11 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-11 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
