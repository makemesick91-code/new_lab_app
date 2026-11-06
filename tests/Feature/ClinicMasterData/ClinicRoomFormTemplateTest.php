<?php

// DMS-RME-REVISION-PROGRAM-8A / P8-N3 — the clinic-room form must compile to
// valid PHP on every supported PHP 8.3 patch release.
//
// The base template opened with an inline PHP directive followed by a block PHP
// directive. Blade extracts raw PHP blocks first, pairing the inline opener with
// the block's closer, so the compiled view began with an open tag immediately
// followed by "(" and carried a literal directive inside it. With
// short_open_tag On (CI: no php.ini) PHP parsed that as code and failed with a
// ParseError (HTTP 500); with it Off (production's php.ini) the glued tag is not
// an open tag and the template source was printed as text. These tests assert
// the ini-independent property
// — the compiled view has no open tag glued to the next character, and the
// rendered page shows no template source — so they fail on every PHP version.
//
// Helper prefix `p8acr` is unique because the critical gate loads every suite
// into one process.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Blade;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
});

/** Fragments that only appear when template source leaks into the response. */
function p8acrLeakMarkers(): array
{
    return ['@php', '@endphp', '<?php', '$typeLabels', '$statusLabels', '$room = $room'];
}

function p8acrAssertNoTemplateLeak($response): void
{
    foreach (p8acrLeakMarkers() as $marker) {
        $response->assertDontSee($marker, false);
    }
}

it('P8A-CR-01 compiles the clinic-room form with no open tag glued to the next character', function () {
    $compiled = Blade::compileString(file_get_contents(resource_path('views/settings/clinic-rooms/_form.blade.php')));

    expect(preg_match('/<\?php(?=\S)/', $compiled))->toBe(0)
        ->and($compiled)->not->toContain('@php')
        ->and($compiled)->not->toContain('@endphp');
});

it('P8A-CR-02 renders the create form with its labels and no template source', function () {
    $response = $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.create'))
        ->assertOk()
        ->assertViewIs('settings.clinic-rooms.create')
        ->assertSee('Ruang Perawatan')
        ->assertSee('Ruang Konsultasi')
        ->assertSee('Pemeliharaan')
        ->assertSee('name="code"', false)
        ->assertSee('name="name"', false)
        ->assertSee('name="description"', false)
        ->assertSee('name="_token"', false);

    p8acrAssertNoTemplateLeak($response);
});

it('P8A-CR-03 renders the edit form with the room values selected and the method spoofed', function () {
    $room = ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'KS-07',
        'name' => 'Ruang Konsultasi Tujuh',
        'type' => ClinicRoom::TYPE_CONSULTATION_ROOM,
        'status' => ClinicRoom::STATUS_MAINTENANCE,
    ]);

    $response = $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.edit', $room))
        ->assertOk()
        ->assertViewIs('settings.clinic-rooms.edit')
        ->assertSee('value="KS-07"', false)
        ->assertSee('value="Ruang Konsultasi Tujuh"', false)
        ->assertSee('value="'.ClinicRoom::TYPE_CONSULTATION_ROOM.'" selected', false)
        ->assertSee('value="'.ClinicRoom::STATUS_MAINTENANCE.'" selected', false)
        ->assertSee('name="_method" value="PUT"', false);

    p8acrAssertNoTemplateLeak($response);
});

it('P8A-CR-04 defaults a new room to the active status', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.create'))
        ->assertOk()
        ->assertSee('value="'.ClinicRoom::STATUS_ACTIVE.'" selected', false);
});

it('P8A-CR-05 re-renders validation errors with the old input kept', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.clinic-rooms.create'))
        ->post(route('settings.clinic-rooms.store'), [
            'code' => 'OLD-CODE',
            'name' => '',
            'type' => '',
            'status' => '',
        ])
        ->assertRedirect(route('settings.clinic-rooms.create'))
        ->assertSessionHasErrors(['name', 'type', 'status']);

    $response = $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.create'))
        ->assertOk()
        ->assertSee('value="OLD-CODE"', false);

    p8acrAssertNoTemplateLeak($response);
});

it('P8A-CR-06 keeps the form closed to a viewer', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)->get(route('settings.clinic-rooms.create'))->assertForbidden();
    $this->actingAs($this->viewer)->get(route('settings.clinic-rooms.edit', $room))->assertForbidden();
});

it('P8A-CR-07 keeps another branch room edit form closed (branch isolation)', function () {
    $otherBranch = Branch::factory()->create(['code' => 'RM8']);
    $room = ClinicRoom::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Ruang Cabang Lain']);

    $this->actingAs($this->manager)
        ->get(route('settings.clinic-rooms.edit', $room))
        ->assertForbidden();
});
