<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->rmeBranch = Branch::factory()->create([
        'code' => 'DOC1',
        'name' => 'Cabang Dokter Test',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

it('lists doctors for an authorized user', function () {
    Doctor::factory()->count(3)->create();

    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertViewIs('settings.doctors.index');
});

it('filters doctors by Cabang RME', function () {
    $branchA = Branch::factory()->create(['code' => 'BRA', 'is_active' => true, 'is_rme_enabled' => true]);
    $branchB = Branch::factory()->create(['code' => 'BRB', 'is_active' => true, 'is_rme_enabled' => true]);
    Doctor::factory()->create(['branch_id' => $branchA->id, 'name' => 'Dr. Alpha']);
    Doctor::factory()->create(['branch_id' => $branchB->id, 'name' => 'Dr. Beta']);

    $this->actingAs(superAdmin())
        ->get(route('settings.doctors.index', ['branch_id' => $branchA->id]))
        ->assertOk()
        ->assertSee('Dr. Alpha')
        ->assertDontSee('Dr. Beta');
});

it('creates a doctor (happy path)', function () {
    $this->actingAs(userWith(['manage doctors']))
        ->post(route('settings.doctors.store'), [
            'branch_id' => test()->rmeBranch->id,
            'code' => 'DOC-001',
            'name' => 'Dr. New',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    $doctor = Doctor::where('code', 'DOC-001')->firstOrFail();
    expect($doctor->branch_id)->toBe(test()->rmeBranch->id);
    expect($doctor->clinic_id)->toBeNull();
});

it('requires branch_id and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.doctors.store'), ['code' => 'X', 'name' => ''])
        ->assertSessionHasErrors(['branch_id', 'name']);
});

it('rejects a duplicate doctor code', function () {
    Doctor::factory()->create(['code' => 'DDUP']);

    $this->actingAs(superAdmin())
        ->post(route('settings.doctors.store'), [
            'branch_id' => test()->rmeBranch->id,
            'code' => 'DDUP',
            'name' => 'X',
        ])
        ->assertSessionHasErrors('code');
});

it('updates a doctor', function () {
    $doctor = Doctor::factory()->create(['name' => 'Old', 'branch_id' => test()->rmeBranch->id]);

    $this->actingAs(superAdmin())
        ->put(route('settings.doctors.update', $doctor), [
            'branch_id' => test()->rmeBranch->id,
            'code' => $doctor->code,
            'name' => 'Updated Doctor',
        ])
        ->assertRedirect(route('settings.doctors.index'));

    expect($doctor->refresh()->name)->toBe('Updated Doctor');
});

it('soft deletes a doctor', function () {
    $doctor = Doctor::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.doctors.destroy', $doctor))
        ->assertRedirect();

    expect(Doctor::find($doctor->id))->toBeNull();
    expect(Doctor::withTrashed()->find($doctor->id))->not->toBeNull();
});

it('denies doctor access without permission', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.doctors.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('settings.doctors.index'))->assertRedirect(route('login'));
});
