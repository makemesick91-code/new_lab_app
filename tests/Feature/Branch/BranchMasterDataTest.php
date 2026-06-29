<?php

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->manager = userWith(['view_branch_master_data', 'manage_branch_master_data']);
    $this->viewer = userWith(['view_branch_master_data']);
});

it('lets Super Admin view the branch master list', function () {
    $this->actingAs(userInRole('Super Admin'))
        ->get(route('settings.branches.index'))
        ->assertOk()
        ->assertViewIs('settings.branches.index')
        ->assertSee('Master Cabang RME');
});

it('lets a manager create a branch with manual code and name', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.branches.store'), [
            'code' => 'JKT-01',
            'name' => 'Cabang Jakarta',
            'is_active' => '1',
            'is_rme_enabled' => '1',
            'is_inventory_enabled' => '1',
        ])
        ->assertRedirect(route('settings.branches.index'));

    expect(Branch::where('code', 'JKT-01')->where('name', 'Cabang Jakarta')->exists())->toBeTrue();
});

it('normalizes the branch code to trimmed uppercase', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.branches.store'), [
            'code' => '  bdg-02  ',
            'name' => 'Cabang Bandung',
        ])
        ->assertRedirect(route('settings.branches.index'));

    expect(Branch::where('name', 'Cabang Bandung')->value('code'))->toBe('BDG-02');
});

it('rejects a duplicate branch code', function () {
    Branch::factory()->create(['code' => 'DUP-01', 'name' => 'Existing']);

    $this->actingAs($this->manager)
        ->post(route('settings.branches.store'), [
            'code' => 'DUP-01',
            'name' => 'Another',
        ])
        ->assertSessionHasErrors('code');

    expect(Branch::where('code', 'DUP-01')->count())->toBe(1);
});

it('persists the RME enabled flag', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.branches.store'), [
            'code' => 'RME-ON',
            'name' => 'Cabang RME',
            'is_rme_enabled' => '1',
            'is_inventory_enabled' => '0',
        ])
        ->assertRedirect(route('settings.branches.index'));

    $branch = Branch::where('code', 'RME-ON')->firstOrFail();
    expect($branch->is_rme_enabled)->toBeTrue();
    expect($branch->is_inventory_enabled)->toBeFalse();
});

it('persists the Inventory enabled flag', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.branches.store'), [
            'code' => 'INV-ON',
            'name' => 'Cabang Inventory',
            'is_rme_enabled' => '0',
            'is_inventory_enabled' => '1',
        ])
        ->assertRedirect(route('settings.branches.index'));

    $branch = Branch::where('code', 'INV-ON')->firstOrFail();
    expect($branch->is_inventory_enabled)->toBeTrue();
    expect($branch->is_rme_enabled)->toBeFalse();
});

it('does not expose a Lab enabled flag in the create form', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.branches.create'))
        ->assertOk()
        ->assertSee('Digunakan untuk RME')
        ->assertSee('Digunakan untuk Inventory')
        ->assertDontSee('is_lab_enabled')
        ->assertDontSee('Digunakan untuk Lab');
});

it('protects the default MAIN branch from deletion', function () {
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $this->actingAs($this->manager)
        ->delete(route('settings.branches.destroy', $main))
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect(Branch::where('code', Branch::MAIN_CODE)->exists())->toBeTrue();
});

it('forbids an unauthorized role from branch master data', function () {
    $this->actingAs(userInRole('Doctor'))
        ->get(route('settings.branches.index'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('forbids a Courier from branch master data', function () {
    $this->actingAs(userInRole('Courier'))
        ->get(route('settings.branches.index'))
        ->assertForbidden();

    $this->actingAs(userInRole('Courier'))
        ->post(route('settings.branches.store'), [
            'code' => 'X-01',
            'name' => 'Nope',
        ])
        ->assertForbidden();
});

it('forbids a view-only user from creating a branch', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.branches.create'))
        ->assertForbidden();
});
