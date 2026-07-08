<?php

/**
 * UIX-10 — RME visit, queue & patient workspace polish. Presentation-only; the
 * RME daily operator surfaces (queue, room worklist, visit create/edit forms,
 * workspace nav, RM lookup) stay on the DaengtisiaMS design system. No
 * controller/service/query/permission/BranchContext/RME-workflow change.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'RME');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->rmeBranch = Branch::factory()->create([
        'code' => 'UIX10', 'name' => 'Cabang UIX10', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Sepuluh']);
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch);
    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished Antrian Pasien queue for an authorized user', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id, 'name' => 'Pasien Antrian']);
    ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertSee('Antrian Pasien')
        ->assertSee('Daftar Antrian Pasien')
        ->assertSee('Pasien Antrian');
});

it('renders the design-system empty state on the queue when there are no patients', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertSee('Belum ada pasien dalam antrian.');
});

it('keeps the existing queue room_status filter param working', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index', ['room_status' => 'unassigned']))
        ->assertOk();
});

it('renders the polished visit create form', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Daftar Kunjungan Baru');
});

it('redirects guests to login for the queue page', function () {
    $this->get(route('rme.patient-queue.index'))->assertRedirect(route('login'));
});

it('forbids users without the clinic-visit permission from the queue', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('rme.patient-queue.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Design-system markers present, legacy brand color gone
// ---------------------------------------------------------------------------

it('uses x-ui foundation components and semantic tokens in the queue view', function () {
    $view = file_get_contents(resource_path('views/rme/patient-queue/index.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.filter-bar');
    expect($view)->toContain('x-ui.table');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.empty-state');
    expect($view)->toContain(':status');
    // No legacy teal brand class, no gray legacy scale.
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring|divide)-gray-\d/');
});

it('keeps legacy brand color out of the RME operator surfaces', function () {
    $files = [
        'views/rme/visits/room-worklist.blade.php',
        'views/rme/visits/create.blade.php',
        'views/rme/visits/edit.blade.php',
        'views/rme/visits/_form.blade.php',
        'views/rme/partials/cross-branch-rm-lookup.blade.php',
        'views/rme/visits/partials/rm-sheet-nav.blade.php',
        'views/rme/visits/partials/visit-nav-arrows.blade.php',
        'views/rme/visits/partials/visit-workflow-nav.blade.php',
        'views/rme/dashboard/index.blade.php',
        'views/rme/online-context/select.blade.php',
    ];

    foreach ($files as $file) {
        $view = file_get_contents(resource_path($file));
        expect($view)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
        // Identity numbers must never be rendered on any RME operator surface.
        expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-10 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-10 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
