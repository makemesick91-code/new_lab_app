<?php

/**
 * Sprint 66.1 — Doctor master uses Cabang RME (mst_branches) instead of legacy mst_clinics.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update([
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);

    test()->rmeBranch = Branch::factory()->create([
        'code' => 'TKM1',
        'name' => 'Cabang Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    test()->inactiveRmeBranch = Branch::factory()->create([
        'code' => 'INA9',
        'name' => 'Cabang Nonaktif',
        'is_active' => false,
        'is_rme_enabled' => true,
    ]);

    test()->inventoryBranch = Branch::factory()->create([
        'code' => 'INV9',
        'name' => 'Cabang Gudang',
        'is_active' => true,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => true,
    ]);

    test()->admin = userWith(['manage doctors']);
});

it('does not show legacy Klinik dropdown on doctor create form', function () {
    Clinic::factory()->create(['name' => 'Legacy Klinik Lama']);

    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertDontSee('- Pilih klinik -')
        ->assertDontSee('Legacy Klinik Lama');
});

it('shows Cabang RME dropdown on doctor create form', function () {
    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertSee('Cabang RME')
        ->assertSee('TKM1 — Cabang Telkomas');
});

it('only lists active RME-enabled branches on doctor create form', function () {
    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertSee('TKM1 — Cabang Telkomas')
        ->assertDontSee('INA9 —')
        ->assertDontSee('INV9 —')
        ->assertDontSee('MAIN —');
});

it('rejects storing a doctor with a non-RME branch', function () {
    $this->actingAs(test()->admin)
        ->from(route('settings.doctors.create'))
        ->post(route('settings.doctors.store'), [
            'branch_id' => test()->inventoryBranch->id,
            'code' => 'DOC-BAD',
            'name' => 'Dr. Bad Branch',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('branch_id');

    expect(Doctor::where('code', 'DOC-BAD')->exists())->toBeFalse();
});

it('stores doctor with canonical branch_id and no new clinic_id', function () {
    $this->actingAs(test()->admin)
        ->post(route('settings.doctors.store'), [
            'branch_id' => test()->rmeBranch->id,
            'code' => 'DOC-RME',
            'name' => 'Dr. RME Branch',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    $doctor = Doctor::where('code', 'DOC-RME')->firstOrFail();
    expect($doctor->branch_id)->toBe(test()->rmeBranch->id);
    expect($doctor->clinic_id)->toBeNull();
});

it('updates doctor branch_id on edit', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'BD02',
        'name' => 'Cabang Kedua',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $doctor = Doctor::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'clinic_id' => null,
    ]);

    $this->actingAs(superAdmin())
        ->put(route('settings.doctors.update', $doctor), [
            'branch_id' => $otherBranch->id,
            'code' => $doctor->code,
            'name' => $doctor->name,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    expect($doctor->refresh()->branch_id)->toBe($otherBranch->id);
});

it('shows Cabang RME on doctor index', function () {
    Doctor::factory()->create([
        'name' => 'Dr. Visible Branch',
        'branch_id' => test()->rmeBranch->id,
        'clinic_id' => null,
    ]);

    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertSee('Cabang RME')
        ->assertSee('TKM1 — Cabang Telkomas')
        ->assertSee('Dr. Visible Branch');
});

it('warns when doctor has no Cabang RME on index', function () {
    Doctor::factory()->withLegacyClinic()->create(['name' => 'Dr. Tanpa Cabang']);

    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertSee('Dokter belum memiliki Cabang RME');
});

it('redirects legacy clinic master index to Cabang RME master', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.clinics.index'))
        ->assertRedirect(route('settings.branches.index'));
});

it('blocks creating new legacy clinic records', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->post(route('settings.clinics.store'), [
            'code' => 'LEG-NEW',
            'name' => 'Klinik Baru',
        ])
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect(Clinic::where('code', 'LEG-NEW')->exists())->toBeFalse();
});

it('does not expose legacy Klinik menu in sidebar html', function () {
    $html = $this->actingAs(superAdmin())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(route('settings.clinics.index'));
    expect($html)->toContain('Master Cabang RME');
});

it('keeps historical visits with legacy clinic_id readable', function () {
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'branch_id' => test()->rmeBranch->id,
    ]);

    $visit = ClinicVisit::factory()->create([
        'clinic_id' => $clinic->id,
        'branch_id' => test()->rmeBranch->id,
        'doctor_id' => $doctor->id,
    ]);

    $admin = userWith(['view_clinic_visits']);

    $this->actingAs($admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();
});

it('blocks doctor online session when branch_id is missing', function () {
    $doctor = Doctor::factory()->withLegacyClinic()->create();
    $user = userInRole('Doctor');
    $doctor->update(['user_id' => $user->id, 'branch_id' => null]);
    $room = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
    ]);

    expect(fn () => app(UserOnlineContextService::class)
        ->startDoctorSession($user, test()->rmeBranch->id, $room->id))
        ->toThrow(ValidationException::class);
});

it('allows doctor online when branch_id matches selected RME branch', function () {
    $doctor = Doctor::factory()->create(['branch_id' => test()->rmeBranch->id]);
    $user = rmeMakeDoctorOnline($doctor, test()->rmeBranch);

    expect(app(UserOnlineContextService::class)->isDoctorOnline($user))->toBeTrue();
});

it('filters branch master index to RME-enabled branches only', function () {
    $manager = userWith(['view_branch_master_data', 'manage_branch_master_data']);

    $this->actingAs($manager)
        ->get(route('settings.branches.index'))
        ->assertOk()
        ->assertSee('Master Cabang RME')
        ->assertSee('TKM1')
        ->assertDontSee('INV9 —');
});
