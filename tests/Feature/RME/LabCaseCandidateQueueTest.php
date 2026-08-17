<?php

// Sprint 21 Phase 21.3 — Admin Lab read-only queue UI for LabCaseCandidate records.
// TDD-first: these tests drive implementation of the controller, policy, routes, views, and sidebar.

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    // A user with lab-view permission (Admin Lab role has manage_lab_orders which implies view)
    $this->viewer = userWith(['view_lab_orders']);
});

// ─── Test 1: Authorized user can view index ───────────────────────────────────

it('authorized user can view lab case candidate index', function () {
    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('Kandidat Pekerjaan Lab');
});

// ─── Test 2: Unauthorized user cannot view index ──────────────────────────────

it('unauthorized user cannot view lab case candidate index', function () {
    $unauth = User::factory()->create();

    $this->actingAs($unauth)
        ->get(route('lab-case-candidates.index'))
        ->assertForbidden();
});

// ─── Test 3: Index only shows candidates from active branch ───────────────────

it('index only shows candidates from active branch', function () {
    $mainCandidate = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Pekerjaan Cabang Utama',
    ]);

    $otherBranch = Branch::factory()->create(['is_active' => true]);
    LabCaseCandidate::factory()->create([
        'branch_id' => $otherBranch->id,
        'source_description' => 'Pekerjaan Cabang Lain',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('Pekerjaan Cabang Utama')
        ->assertDontSee('Pekerjaan Cabang Lain');
});

// ─── Test 4: Index filters by status ─────────────────────────────────────────

it('index filters candidates by status', function () {
    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
        'source_description' => 'Status Menunggu Review',
    ]);
    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER,
        'source_description' => 'Status Sudah Dikonversi',
    ]);

    $response = $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index', ['status' => LabCaseCandidate::STATUS_PENDING_REVIEW]))
        ->assertOk()
        ->assertSee('Status Menunggu Review')
        ->assertDontSee('Status Sudah Dikonversi');
});

// ─── Test 5: Index searches by patient name ───────────────────────────────────

it('index searches by patient name', function () {
    // Create two candidates in the same branch; distinguish by patient name via source_description
    $candidateA = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Veneer Pasien Alpha',
    ]);
    $candidateB = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Veneer Pasien Beta',
    ]);

    /*
     * CICD-BASELINE-REVERIFY-1 — deterministic, escaping-aware names.
     *
     * This assertion used to read the faker-generated name straight off the
     * model and look for it in the raw response body. Blade renders the name
     * through `{{ }}`, so any name carrying an HTML-special character came back
     * escaped and the raw comparison failed — `Oswaldo O'Kon` rendered as
     * `Oswaldo O&#039;Kon`. That made the whole Full Suite non-deterministic:
     * it reddened run 31928614428 and passed on either side of it without a
     * single line of the module changing.
     *
     * The names are pinned here so the escaping path is exercised on every run
     * rather than whenever faker happens to pick an apostrophe, and the search
     * is asserted through `assertSee()`, which escapes the expected value the
     * same way the view escapes the rendered one.
     */
    $searchedName = "Alpha O'Kon";
    $otherName = 'Beta Sanjaya';

    $candidateA->patient->update(['name' => $searchedName]);
    $candidateB->patient->update(['name' => $otherName]);

    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index', ['search' => $searchedName]))
        ->assertOk()
        // Response should contain candidateA but NOT candidateB (different patient)
        ->assertSee($searchedName)
        ->assertDontSee($otherName);
});

// ─── Test 6: Index searches by source description ─────────────────────────────

it('index searches by source description', function () {
    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Crown Zirkonia Molar',
    ]);
    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Veneer Anterior',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index', ['search' => 'Zirkonia']))
        ->assertOk()
        ->assertSee('Crown Zirkonia Molar')
        ->assertDontSee('Veneer Anterior');
});

// ─── Test 7: Authorized user can view candidate show page ─────────────────────

it('authorized user can view candidate show page', function () {
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'Detail Crown Porselen',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertSee('Detail Kandidat Pekerjaan Lab')
        ->assertSee('Detail Crown Porselen');
});

// ─── Test 8: User cannot view candidate from another branch ───────────────────

it('user cannot view candidate from another branch', function () {
    $otherBranch = Branch::factory()->create(['is_active' => true]);
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $otherBranch->id,
    ]);

    // viewer has view_lab_orders but is scoped to MAIN branch (BranchContext fallback)
    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertForbidden();
});

// ─── Test 9: Index shows empty state ─────────────────────────────────────────

it('index shows empty state when no candidates exist', function () {
    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('Belum ada kandidat pekerjaan lab');
});

// ─── Test 10: Sidebar shows Kandidat Lab RME for permitted user ───────────────

it('sidebar shows Kandidat Lab RME for permitted user', function () {
    $this->actingAs($this->viewer)
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('Kandidat Lab RME');
});

// ─── Test 11: Sidebar hides Kandidat Lab RME for unauthorized user ─────────────

it('sidebar hides Kandidat Lab RME for unauthorized user', function () {
    $unauth = User::factory()->create();

    $this->actingAs($unauth)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Kandidat Lab RME');
});

// ─── Test 12: Viewer without create permission cannot convert ─────────────────

it('viewer without create permission cannot convert candidate', function () {
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
    ]);

    $beforeCount = LabOrder::count();

    $this->actingAs($this->viewer)
        ->post(route('lab-case-candidates.convert', $candidate), [
            'lab_service_id' => LabService::factory()->create()->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ])
        ->assertForbidden();

    expect(LabOrder::count())->toBe($beforeCount);
});
