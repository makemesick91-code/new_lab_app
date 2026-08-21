<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

// --- Model / factory / relation ---

it('odontogram factory can create record', function () {
    $odontogram = Odontogram::factory()->create();

    expect($odontogram)->toBeInstanceOf(Odontogram::class)
        ->and($odontogram->id)->toBeInt()
        ->and($odontogram->clinic_visit_id)->toBeInt()
        ->and($odontogram->branch_id)->toBeInt()
        ->and($odontogram->status)->toBe(Odontogram::STATUS_DRAFT);
});

it('odontogram default status is draft', function () {
    $odontogram = Odontogram::factory()->create();

    expect($odontogram->status)->toBe('draft');
});

it('odontogram tooth_map_payload casts to array when set', function () {
    $odontogram = Odontogram::factory()->create([
        'tooth_map_payload' => ['teeth' => []],
    ]);

    expect($odontogram->tooth_map_payload)->toBeArray();
});

it('odontogram tooth_map_payload is null when not set', function () {
    $odontogram = Odontogram::factory()->create(['tooth_map_payload' => null]);

    expect($odontogram->tooth_map_payload)->toBeNull();
});

it('clinic visit has one odontogram relation works', function () {
    $visit = rmeConsentedOdontogramVisit();
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    expect($visit->odontogram)->toBeInstanceOf(Odontogram::class)
        ->and($visit->odontogram->id)->toBe($odontogram->id);
});

it('odontogram belongs to clinic visit', function () {
    $visit = rmeConsentedOdontogramVisit();
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    expect($odontogram->clinicVisit)->toBeInstanceOf(ClinicVisit::class)
        ->and($odontogram->clinicVisit->id)->toBe($visit->id);
});

it('odontogram belongs to branch', function () {
    $odontogram = Odontogram::factory()->create();

    expect($odontogram->branch)->toBeInstanceOf(Branch::class);
});

// --- Service layer ---

it('getOrCreateForVisit creates new odontogram when none exists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $odontogram = app(OdontogramService::class)->getOrCreateForVisit($visit, $user);

    expect($odontogram)->toBeInstanceOf(Odontogram::class)
        ->and($odontogram->clinic_visit_id)->toBe($visit->id)
        ->and($odontogram->branch_id)->toBe($branch->id)
        ->and($odontogram->status)->toBe(Odontogram::STATUS_DRAFT);
});

it('getOrCreateForVisit returns existing odontogram idempotently', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $service = app(OdontogramService::class);
    $first = $service->getOrCreateForVisit($visit, $user);
    $second = $service->getOrCreateForVisit($visit, $user);

    expect($second->id)->toBe($first->id);
    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('getOrCreateForVisit rejects visit from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);

    expect(fn () => app(OdontogramService::class)->getOrCreateForVisit($visit, $user))
        ->toThrow(ValidationException::class);
});

it('updatePlaceholder updates summary_notes correctly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $updated = app(OdontogramService::class)->updatePlaceholder(
        $odontogram,
        ['summary_notes' => 'Gigi 11 karies'],
        $user
    );

    expect($updated->summary_notes)->toBe('Gigi 11 karies')
        ->and($updated->updated_by)->toBe($user->id);
});

it('updatePlaceholder rejects odontogram from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $odontogram = Odontogram::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(OdontogramService::class)->updatePlaceholder(
        $odontogram,
        ['summary_notes' => 'test'],
        $user
    ))->toThrow(ValidationException::class);
});

// --- HTTP show (GET) ---

it('authorized user can open odontogram placeholder from clinic visit', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Odontogram');
});

it('viewer can open odontogram placeholder from clinic visit', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($viewer)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();
});

/*
 * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — CONTRACT REVERSED.
 *
 * These two cases used to assert that opening the page CREATES a row
 * ('opening odontogram show creates odontogram if not exists' / '...again does
 * not duplicate record'). That was the defect, not the requirement: viewing a
 * chart is a read and must leave no clinical record behind. They now pin the
 * opposite, which is what the fix guarantees.
 */
it('opening odontogram show does not create an odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('opening odontogram show repeatedly still creates no record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($manager)->get(route('rme.visits.odontogram.show', $visit))->assertOk();
    $this->actingAs($manager)->get(route('rme.visits.odontogram.show', $visit))->assertOk();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(0);
});

it('unauthenticated user cannot open odontogram placeholder', function () {
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->get(route('rme.visits.odontogram.show', $visit))
        ->assertRedirect(route('login'));
});

it('user from another branch cannot open odontogram placeholder', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertForbidden();
});

// --- HTTP update (PATCH) ---

it('authorized manager can update summary_notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'Catatan gigi placeholder',
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    expect($odontogram->fresh()->summary_notes)->toBe('Catatan gigi placeholder');
});

it('viewer cannot update summary_notes', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'Catatan tidak boleh',
        ])
        ->assertForbidden();
});

it('unauthorized user cannot update odontogram', function () {
    $user = User::factory()->create();
    $odontogram = Odontogram::factory()->create();

    $this->actingAs($user)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'test',
        ])
        ->assertForbidden();
});

it('cross-branch user cannot update odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'cross-branch attempt',
        ])
        ->assertForbidden();
});

it('update validates summary_notes max length', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => str_repeat('x', 5001),
        ])
        ->assertSessionHasErrors('summary_notes');
});

it('update allows null summary_notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'summary_notes' => 'ada catatan',
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => null,
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->summary_notes)->toBeNull();
});

// --- UI visibility ---

it('visit show page contains odontogram link for authorized user', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Buka Odontogram');
});

// ============================================================
// Phase 1.3.2 — Tooth Map Draft UI
// ============================================================

// --- UI: tooth number presence ---

it('odontogram page displays all 32 FDI tooth numbers', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $response = $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    $allTeeth = array_merge(
        range(11, 18), range(21, 28), range(31, 38), range(41, 48)
    );
    foreach ($allTeeth as $tooth) {
        $response->assertSee((string) $tooth);
    }
});

// --- HTTP update: valid tooth_map_payload (JSON string format, simulates form) ---

it('manager can update tooth_map_payload with valid FDI teeth via JSON string', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries'], '21' => ['status' => 'crown']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($fresh->tooth_map_payload['teeth']['21']['status'])->toBe('crown');
});

// --- HTTP update: invalid status ---

it('update rejects invalid tooth status', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'rotten']]],
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth.11.status');
});

// --- HTTP update: invalid FDI tooth number ---

it('update rejects invalid FDI tooth number', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['99' => ['status' => 'caries']]],
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth');
});

it('update rejects tooth number out of FDI range', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    // 10 is not valid (valid range per quadrant starts at 1 → tooth 11, 21, 31, 41)
    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['10' => ['status' => 'caries']]],
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth');
});

it('update rejects alphabetic tooth number', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['abc' => ['status' => 'caries']]],
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth');
});

// --- Permission: viewer cannot update ---

it('viewer cannot update tooth_map_payload', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
        ])
        ->assertForbidden();
});

// --- Permission: cross-branch cannot update ---

it('cross-branch user cannot update tooth_map_payload', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
        ])
        ->assertForbidden();
});

// --- Idempotency: update does not duplicate odontogram ---

it('updating tooth map does not create duplicate odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
        ])
        ->assertRedirect();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

// --- summary_notes can be updated alongside tooth_map_payload ---

it('can update summary_notes and tooth_map_payload together', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'Gigi 11 karies, gigi 18 PSA',
            'tooth_map_payload' => [
                'teeth' => [
                    '11' => ['status' => 'caries'],
                    '18' => ['status' => 'root_treated'],
                ],
            ],
        ])
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->summary_notes)->toBe('Gigi 11 karies, gigi 18 PSA')
        ->and($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($fresh->tooth_map_payload['teeth']['18']['status'])->toBe('root_treated');
});

// ============================================================
// Phase 1.3.3 — Odontogram Finalize
// ============================================================

// --- Service: finalize ---

it('finalize sets status to finalized and records timestamp and user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $result = app(OdontogramService::class)->finalize($odontogram, $user);

    expect($result->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($result->finalized_at)->not->toBeNull()
        ->and($result->finalized_by)->toBe($user->id);
});

it('finalize is idempotent for already-finalized odontogram', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $user->id,
    ]);

    $result = app(OdontogramService::class)->finalize($odontogram, $user);

    expect($result->id)->toBe($odontogram->id)
        ->and($result->status)->toBe(Odontogram::STATUS_FINALIZED);
    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('finalize service rejects odontogram from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $odontogram = Odontogram::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(OdontogramService::class)->finalize($odontogram, $user))
        ->toThrow(ValidationException::class);
});

it('updatePlaceholder allows editing a finalized odontogram (Sprint 59)', function () {
    $user = userWith(['manage_clinic_visits']);
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $user->id,
    ]);

    $updated = app(OdontogramService::class)->updatePlaceholder(
        $odontogram,
        ['summary_notes' => 'revisi setelah final'],
        $user
    );

    expect($updated->summary_notes)->toBe('revisi setelah final')
        // Finalized status/columns are preserved for backward compatibility.
        ->and($updated->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($updated->finalized_at)->not->toBeNull();
});

// --- HTTP: finalize ---

it('manager can finalize draft odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($fresh->finalized_at)->not->toBeNull()
        ->and($fresh->finalized_by)->toBe($manager->id);
});

it('viewer cannot finalize odontogram', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertForbidden();
});

it('user without permission cannot finalize odontogram', function () {
    $user = User::factory()->create();
    $odontogram = Odontogram::factory()->create();

    $this->actingAs($user)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertForbidden();
});

it('cross-branch user cannot finalize odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertForbidden();
});

it('finalize does not duplicate odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

// --- HTTP: editable after finalize (Sprint 59) ---

it('finalized odontogram can update summary_notes via HTTP (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'revisi catatan',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($odontogram->fresh()->summary_notes)->toBe('revisi catatan');
});

it('finalized odontogram can update tooth_map_payload via HTTP (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries']]]),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['status'])->toBe('caries');
});

// --- UI: badge visibility ---

it('finalized odontogram page shows final badge', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Final');
});

it('draft odontogram page shows draft badge', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Draft');
});

// ============================================================
// Sprint 20 Phase 1.4 — Per-Tooth Notes Foundation
// ============================================================

// --- Happy path: note saved alongside status ---

it('manager can save per-tooth note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => 'Karies oklusal ringan']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['note'])->toBe('Karies oklusal ringan');
});

it('per-tooth note is persisted in tooth_map_payload alongside status', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = [
        'teeth' => [
            '11' => ['status' => 'caries', 'note' => 'Karies oklusal'],
            '21' => ['status' => 'crown', 'note' => ''],
        ],
    ];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($fresh->tooth_map_payload['teeth']['11']['note'])->toBe('Karies oklusal')
        ->and($fresh->tooth_map_payload['teeth']['21']['status'])->toBe('crown')
        ->and($fresh->tooth_map_payload['teeth']['21']['note'])->toBe('');
});

// --- Note length validation ---

it('per-tooth note of exactly 1000 chars is accepted', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => str_repeat('a', 1000)]]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();
});

it('per-tooth note exceeding 1000 chars is rejected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => str_repeat('a', 1001)]]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth.11.note');
});

it('per-tooth note can be null', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => null]]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();
});

// --- Permission: viewer cannot update note ---

it('viewer cannot update per-tooth note', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries', 'note' => 'test']]]),
        ])
        ->assertForbidden();
});

// --- Permission: cross-branch cannot update note ---

it('cross-branch user cannot update per-tooth note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries', 'note' => 'test']]]),
        ])
        ->assertForbidden();
});

// --- Editable: finalized odontogram can receive note update (Sprint 59) ---

it('finalized odontogram can update per-tooth note via HTTP (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode(['teeth' => ['11' => ['status' => 'caries', 'note' => 'test']]]),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['note'])->toBe('test');
});

// ============================================================
// Sprint 20 Phase 1.5 — Odontogram Multi-Condition Per Tooth
// ============================================================

// --- Happy path: multiple conditions saved ---

it('manager can save multiple conditions per tooth', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = [
        'teeth' => [
            '11' => [
                'status' => 'caries',
                'conditions' => ['caries', 'crown'],
                'note' => 'Karies oklusal ringan',
            ],
        ],
    ];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($fresh->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries', 'crown'])
        ->and($fresh->tooth_map_payload['teeth']['11']['note'])->toBe('Karies oklusal ringan');
});

it('conditions persist alongside existing status and note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = [
        'teeth' => [
            '11' => ['status' => 'missing', 'conditions' => ['missing', 'impaction'], 'note' => 'Impaksi bawah'],
            '21' => ['status' => 'crown', 'conditions' => ['crown', 'filling'], 'note' => ''],
        ],
    ];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['conditions'])->toBe(['missing', 'impaction'])
        ->and($fresh->tooth_map_payload['teeth']['21']['conditions'])->toBe(['crown', 'filling']);
});

it('conditions can be empty array', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'conditions' => [], 'note' => '']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['conditions'])->toBe([]);
});

it('conditions can be null or omitted', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => 'test']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();
});

// --- Validation: duplicate conditions rejected ---

it('duplicate conditions per tooth are rejected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries', 'caries']]]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth.11.conditions');
});

// --- Validation: invalid condition string rejected ---

it('invalid condition value is rejected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'conditions' => ['invalid_condition']]]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth.11.conditions.0');
});

// --- Permission: viewer cannot update conditions ---

it('viewer cannot update conditions', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries']]],
            ]),
        ])
        ->assertForbidden();
});

// --- Permission: cross-branch cannot update conditions ---

it('cross-branch cannot update conditions', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries']]],
            ]),
        ])
        ->assertForbidden();
});

// --- Editable: finalized can update conditions (Sprint 59) ---

it('finalized odontogram can update conditions via HTTP (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries']]],
            ]),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries']);
});

// --- Idempotency: no duplicate odontogram after conditions update ---

it('updating conditions does not create duplicate odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries', 'crown']]],
            ]),
        ])
        ->assertRedirect();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

// --- Regression: status-only update still works after Phase 1.5 ---

it('existing tooth status update still works after phase 1.5', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'missing'], '31' => ['status' => 'root_treated']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('missing')
        ->and($fresh->tooth_map_payload['teeth']['31']['status'])->toBe('root_treated');
});

it('mobility is not a valid status value', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    // Hotfix Sprint 60.3 — `filling` is now a valid status (F component of DMF-T);
    // `mobility` remains a clinical condition only, not a valid DIAGNOSA status.
    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'mobility']],
            ]),
        ])
        ->assertSessionHasErrors('tooth_map_payload.teeth.11.status');
});

it('existing note update still works after phase 1.5', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'caries', 'note' => 'Catatan 1.5']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['note'])->toBe('Catatan 1.5');
});

// ============================================================
// Sprint 20 Phase 1.6 — Odontogram Print View Foundation
// ============================================================

// --- Authorization ---

it('authorized manager can open print view', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk();
});

it('authorized viewer can open print view', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk();
});

it('user without permission cannot open print view', function () {
    $user = User::factory()->create();
    $odontogram = Odontogram::factory()->create();

    $this->actingAs($user)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertForbidden();
});

it('cross-branch user cannot open print view', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $otherBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertForbidden();
});

// --- Content: patient / visit / RM info ---

it('print view displays patient name', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee($visit->patient->name);
});

it('print view displays visit number', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee($visit->visit_number);
});

it('print view displays odontogram status', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Final');
});

// --- Content: tooth status ---

it('print view displays tooth status', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Karies');
});

// --- Content: conditions ---

it('print view displays tooth conditions', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'tooth_map_payload' => [
            'teeth' => ['21' => ['status' => 'crown', 'conditions' => ['crown', 'filling']]],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Tambalan');
});

// --- Content: per-tooth note ---

it('print view displays per-tooth note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'note' => 'Karies oklusal print test']],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Karies oklusal print test');
});

// --- Print button on show page ---

/*
 * FIX-01 — the print route is model-bound (`odontograms/{odontogram}/print`),
 * so there is nothing to print until a chart has actually been saved. These
 * cases therefore now chart the visit first; a button that 404s is not a
 * feature. The companion case below pins the absence before the first save.
 */
it('print button appears on odontogram show page for manager', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Cetak Odontogram');
});

it('print button appears on odontogram show page for viewer', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $branch->id]);

    $this->actingAs($viewer)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Cetak Odontogram');
});

it('print button is absent until the visit has a saved chart', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('Cetak Odontogram');
});

// --- Regression: status-only update still works after Phase 1.4 ---

it('existing tooth status update still works after phase 1.4', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $payload = ['teeth' => ['11' => ['status' => 'missing'], '31' => ['status' => 'root_treated']]];

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode($payload),
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('missing')
        ->and($fresh->tooth_map_payload['teeth']['31']['status'])->toBe('root_treated');
});
