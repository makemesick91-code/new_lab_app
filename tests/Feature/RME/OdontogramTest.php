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
    $visit = ClinicVisit::factory()->create();
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    expect($visit->odontogram)->toBeInstanceOf(Odontogram::class)
        ->and($visit->odontogram->id)->toBe($odontogram->id);
});

it('odontogram belongs to clinic visit', function () {
    $visit = ClinicVisit::factory()->create();
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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(OdontogramService::class);
    $first = $service->getOrCreateForVisit($visit, $user);
    $second = $service->getOrCreateForVisit($visit, $user);

    expect($second->id)->toBe($first->id);
    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('getOrCreateForVisit rejects visit from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create();
    $visit = ClinicVisit::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(OdontogramService::class)->getOrCreateForVisit($visit, $user))
        ->toThrow(ValidationException::class);
});

it('updateNotes updates summary_notes correctly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $updated = app(OdontogramService::class)->updateNotes(
        $odontogram,
        ['summary_notes' => 'Gigi 11 karies'],
        $user
    );

    expect($updated->summary_notes)->toBe('Gigi 11 karies')
        ->and($updated->updated_by)->toBe($user->id);
});

it('updateNotes rejects odontogram from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create();
    $odontogram = Odontogram::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(OdontogramService::class)->updateNotes(
        $odontogram,
        ['summary_notes' => 'test'],
        $user
    ))->toThrow(ValidationException::class);
});

// --- HTTP show (GET) ---

it('authorized user can open odontogram placeholder from clinic visit', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Odontogram');
});

it('viewer can open odontogram placeholder from clinic visit', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($viewer)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();
});

it('opening odontogram show creates odontogram if not exists', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('opening odontogram show again does not duplicate record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)->get(route('rme.visits.odontogram.show', $visit));
    $this->actingAs($manager)->get(route('rme.visits.odontogram.show', $visit));

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('unauthenticated user cannot open odontogram placeholder', function () {
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->get(route('rme.visits.odontogram.show', $visit))
        ->assertRedirect(route('login'));
});

it('user from another branch cannot open odontogram placeholder', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create();
    $visit = ClinicVisit::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertForbidden();
});

// --- HTTP update (PATCH) ---

it('authorized manager can update summary_notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
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
    $otherBranch = Branch::factory()->create();
    $visit = ClinicVisit::factory()->create(['branch_id' => $otherBranch->id]);
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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
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
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Buka Odontogram');
});
