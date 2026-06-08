<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
});

it('lists clinic rooms for a viewer', function () {
    ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'RM-UI',
        'name' => 'Ruang Perawatan UI',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.index'))
        ->assertOk()
        ->assertViewIs('settings.clinic-rooms.index')
        ->assertSee('RM-UI')
        ->assertSee('Ruang Perawatan UI');
});

it('only lists rooms from the active branch', function () {
    ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Cabang Aktif',
    ]);
    $otherBranch = Branch::factory()->create(['code' => 'RM2']);
    ClinicRoom::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Ruang Cabang Lain',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.index'))
        ->assertOk()
        ->assertSee('Ruang Cabang Aktif')
        ->assertDontSee('Ruang Cabang Lain');
});

it('filters clinic rooms by type and status and search', function () {
    ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Rontgen Alpha',
        'code' => 'XR-1',
        'type' => ClinicRoom::TYPE_XRAY_ROOM,
        'status' => ClinicRoom::STATUS_MAINTENANCE,
    ]);
    ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Perawatan Beta',
        'code' => 'TR-1',
        'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.index', ['type' => ClinicRoom::TYPE_XRAY_ROOM]))
        ->assertOk()
        ->assertSee('Ruang Rontgen Alpha')
        ->assertDontSee('Ruang Perawatan Beta');

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.index', ['status' => ClinicRoom::STATUS_ACTIVE]))
        ->assertOk()
        ->assertSee('Ruang Perawatan Beta')
        ->assertDontSee('Ruang Rontgen Alpha');

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.index', ['search' => 'Rontgen']))
        ->assertOk()
        ->assertSee('Ruang Rontgen Alpha')
        ->assertDontSee('Ruang Perawatan Beta');
});

it('lets a manager open the create page', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.create'))
        ->assertOk()
        ->assertViewIs('settings.clinic-rooms.create');
});

it('stores a valid clinic room in the active branch', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'TR-01',
            'name' => 'Ruang Perawatan 1',
            'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
            'status' => ClinicRoom::STATUS_ACTIVE,
            'description' => 'Ruang utama',
        ])
        ->assertRedirect(route('settings.clinic-rooms.index'));

    $this->assertDatabaseHas('mst_clinic_rooms', [
        'branch_id' => $this->branch->id,
        'code' => 'TR-01',
        'name' => 'Ruang Perawatan 1',
        'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
});

it('requires code, name, type and status', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.clinic-rooms.create'))
        ->post(route('settings.clinic-rooms.store'), [
            'code' => '',
            'name' => '',
            'type' => '',
            'status' => '',
        ])
        ->assertSessionHasErrors(['code', 'name', 'type', 'status']);
});

it('rejects an invalid type or status', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.clinic-rooms.create'))
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'BAD-1',
            'name' => 'Ruang Tidak Valid',
            'type' => 'not_a_type',
            'status' => 'not_a_status',
        ])
        ->assertSessionHasErrors(['type', 'status']);
});

it('rejects a duplicate code within the same branch', function () {
    ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'DUP-1',
    ]);

    $this->actingAs($this->manager)
        ->from(route('settings.clinic-rooms.create'))
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'DUP-1',
            'name' => 'Ruang Duplikat',
            'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])
        ->assertSessionHasErrors('code');
});

it('allows the same code in a different branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'RM3']);
    ClinicRoom::factory()->create([
        'branch_id' => $otherBranch->id,
        'code' => 'SHARED',
    ]);

    $this->actingAs($this->manager)
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'SHARED',
            'name' => 'Ruang Kode Sama',
            'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])
        ->assertRedirect(route('settings.clinic-rooms.index'));

    $this->assertDatabaseHas('mst_clinic_rooms', [
        'branch_id' => $this->branch->id,
        'code' => 'SHARED',
    ]);
});

it('updates a clinic room', function () {
    $room = ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Lama',
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.clinic-rooms.update', $room), [
            'code' => $room->code,
            'name' => 'Ruang Baru',
            'type' => ClinicRoom::TYPE_CONSULTATION_ROOM,
            'status' => ClinicRoom::STATUS_MAINTENANCE,
        ])
        ->assertRedirect(route('settings.clinic-rooms.index'));

    expect($room->refresh()->name)->toBe('Ruang Baru')
        ->and($room->type)->toBe(ClinicRoom::TYPE_CONSULTATION_ROOM)
        ->and($room->status)->toBe(ClinicRoom::STATUS_MAINTENANCE);
});

it('soft deletes a clinic room', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->delete(route('settings.clinic-rooms.destroy', $room))
        ->assertRedirect(route('settings.clinic-rooms.index'));

    expect(ClinicRoom::find($room->id))->toBeNull();
    expect(ClinicRoom::withTrashed()->find($room->id))->not->toBeNull();
});

it('denies a viewer from creating, updating or deleting', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)
        ->get(route('settings.clinic-rooms.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'NO-1',
            'name' => 'Ruang Terlarang',
            'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->delete(route('settings.clinic-rooms.destroy', $room))
        ->assertForbidden();
});

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.clinic-rooms.index'))
        ->assertForbidden();
});

it('denies updating or deleting a room from another branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'RM4']);
    $room = ClinicRoom::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Ruang Cabang Lain',
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.clinic-rooms.update', $room), [
            'code' => $room->code,
            'name' => 'Tidak Boleh',
            'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->delete(route('settings.clinic-rooms.destroy', $room))
        ->assertForbidden();

    expect($room->refresh()->name)->toBe('Ruang Cabang Lain');
});
