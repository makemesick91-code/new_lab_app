<?php

/**
 * Sprint 66.1.1 — Doctor master uses multi-branch Cabang Praktik (mst_doctor_branches pivot).
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update([
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);

    test()->rmeBranch = Branch::factory()->create([
        'code' => 'TLK1',
        'name' => 'Cabang Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    test()->secondRmeBranch = Branch::factory()->create([
        'code' => 'BD02',
        'name' => 'Cabang Kedua',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    test()->thirdRmeBranch = Branch::factory()->create([
        'code' => 'BD03',
        'name' => 'Cabang Ketiga',
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

it('shows multi-select Cabang Praktik on doctor create form', function () {
    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertSee('Cabang Praktik yang Diizinkan')
        ->assertSee('TLK1 — Cabang Telkomas');
});

it('does not show legacy single Cabang RME select on doctor create form', function () {
    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertDontSee('name="branch_id"')
        ->assertDontSee('- Pilih cabang RME -');
});

it('only lists active RME-enabled branches on doctor create form', function () {
    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.create'))
        ->assertOk()
        ->assertSee('TLK1 — Cabang Telkomas')
        ->assertDontSee('INA9 —')
        ->assertDontSee('INV9 —')
        ->assertDontSee('MAIN —');
});

it('rejects storing a doctor without practice branches', function () {
    $this->actingAs(test()->admin)
        ->from(route('settings.doctors.create'))
        ->post(route('settings.doctors.store'), [
            'code' => 'DOC-NO-BR',
            'name' => 'Dr. Tanpa Cabang',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('branch_ids');

    expect(Doctor::where('code', 'DOC-NO-BR')->exists())->toBeFalse();
});

it('rejects storing a doctor with a non-RME branch', function () {
    $this->actingAs(test()->admin)
        ->from(route('settings.doctors.create'))
        ->post(route('settings.doctors.store'), [
            'branch_ids' => [test()->inventoryBranch->id],
            'code' => 'DOC-BAD',
            'name' => 'Dr. Bad Branch',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('branch_ids.0');

    expect(Doctor::where('code', 'DOC-BAD')->exists())->toBeFalse();
});

it('stores doctor with one allowed practice branch and no new clinic_id', function () {
    $this->actingAs(test()->admin)
        ->post(route('settings.doctors.store'), [
            'branch_ids' => [test()->rmeBranch->id],
            'code' => 'DOC-RME',
            'name' => 'Dr. RME Branch',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    $doctor = Doctor::where('code', 'DOC-RME')->firstOrFail();
    expect($doctor->clinic_id)->toBeNull();
    expect($doctor->branches->pluck('id')->all())->toBe([test()->rmeBranch->id]);
});

it('stores doctor with multiple allowed practice branches', function () {
    $this->actingAs(test()->admin)
        ->post(route('settings.doctors.store'), [
            'branch_ids' => [test()->rmeBranch->id, test()->secondRmeBranch->id],
            'code' => 'DOC-MULTI',
            'name' => 'Dr. Multi Branch',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    $doctor = Doctor::where('code', 'DOC-MULTI')->firstOrFail();
    expect($doctor->branches->pluck('id')->sort()->values()->all())
        ->toBe(collect([test()->rmeBranch->id, test()->secondRmeBranch->id])->sort()->values()->all());
});

it('syncs practice branches on doctor update', function () {
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch])->create([
        'clinic_id' => null,
    ]);

    $this->actingAs(superAdmin())
        ->put(route('settings.doctors.update', $doctor), [
            'branch_ids' => [test()->secondRmeBranch->id, test()->thirdRmeBranch->id],
            'code' => $doctor->code,
            'name' => $doctor->name,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    expect($doctor->refresh()->branches->pluck('id')->sort()->values()->all())
        ->toBe(collect([test()->secondRmeBranch->id, test()->thirdRmeBranch->id])->sort()->values()->all());
});

it('shows practice branches on doctor index', function () {
    Doctor::factory()->withAllowedBranches([test()->rmeBranch, test()->secondRmeBranch])->create([
        'name' => 'Dr. Visible Branch',
        'clinic_id' => null,
    ]);

    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertSee('Cabang Praktik')
        ->assertSee('TLK1 — Cabang Telkomas')
        ->assertSee('BD02 — Cabang Kedua')
        ->assertSee('Dr. Visible Branch');
});

it('warns when doctor has no practice branches on index', function () {
    Doctor::factory()->withLegacyClinic()->create(['name' => 'Dr. Tanpa Cabang']);

    $this->actingAs(test()->admin)
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertSee('Dokter belum memiliki Cabang Praktik')
        ->assertDontSee('Dokter belum memiliki Cabang RME');
});

it('filters doctor index by practice branch pivot', function () {
    Doctor::factory()->withAllowedBranches([test()->rmeBranch])->create(['name' => 'Dr. Alpha']);
    Doctor::factory()->withAllowedBranches([test()->secondRmeBranch])->create(['name' => 'Dr. Beta']);

    $this->actingAs(superAdmin())
        ->get(route('settings.doctors.index', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('Dr. Alpha')
        ->assertDontSee('Dr. Beta');
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
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch])->create([
        'clinic_id' => $clinic->id,
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

it('blocks doctor online session when no practice branches exist', function () {
    $doctor = Doctor::factory()->withLegacyClinic()->create();
    $user = userInRole('Doctor');
    $doctor->update(['user_id' => $user->id]);
    $room = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
    ]);

    expect(fn () => app(UserOnlineContextService::class)
        ->startDoctorSession($user, test()->rmeBranch->id, $room->id))
        ->toThrow(ValidationException::class);
});

it('allows doctor with branches A and B to go online in branch A', function () {
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch, test()->secondRmeBranch])->create();
    $user = rmeMakeDoctorOnline($doctor, test()->rmeBranch);

    expect(app(UserOnlineContextService::class)->isDoctorOnline($user))->toBeTrue();
});

it('allows doctor with branches A and B to go online in branch B', function () {
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch, test()->secondRmeBranch])->create();
    $room = ClinicRoom::factory()->create(['branch_id' => test()->secondRmeBranch->id]);
    $user = rmeMakeDoctorOnline($doctor, test()->secondRmeBranch, $room);

    expect(app(UserOnlineContextService::class)->isDoctorOnline($user))->toBeTrue();
});

it('blocks doctor with only branch A from going online in branch C', function () {
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch])->create();
    $user = userInRole('Doctor');
    $doctor->update(['user_id' => $user->id]);
    $room = ClinicRoom::factory()->create(['branch_id' => test()->thirdRmeBranch->id]);

    expect(fn () => app(UserOnlineContextService::class)
        ->startDoctorSession($user, test()->thirdRmeBranch->id, $room->id))
        ->toThrow(ValidationException::class);
});

it('shows only allowed practice branches on doctor online context select', function () {
    $doctor = Doctor::factory()->withAllowedBranches([test()->rmeBranch])->create();
    $user = userInRole('Doctor');
    $doctor->update(['user_id' => $user->id, 'email' => $user->email]);

    $this->actingAs($user)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('TLK1 — Cabang Telkomas')
        ->assertDontSee('BD02 — Cabang Kedua')
        ->assertDontSee('BD03 — Cabang Ketiga');
});

it('shows empty state when doctor has no practice branches on online context select', function () {
    $doctor = Doctor::factory()->withLegacyClinic()->create();
    $user = userInRole('Doctor');
    $doctor->update(['user_id' => $user->id, 'email' => $user->email]);

    $this->actingAs($user)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('Dokter belum memiliki Cabang Praktik')
        ->assertSee('Hubungi Admin Klinik');
});

it('backfills legacy branch_id into doctor practice pivot when valid', function () {
    $doctor = Doctor::create([
        'branch_id' => test()->rmeBranch->id,
        'code' => 'BFILL-'.uniqid(),
        'name' => 'Dr. Backfill',
        'is_active' => true,
        'clinic_id' => null,
    ]);

    DB::table('mst_doctor_branches')->where('doctor_id', $doctor->id)->delete();
    expect(DB::table('mst_doctor_branches')->where('doctor_id', $doctor->id)->count())->toBe(0);

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_06_29_150001_create_mst_doctor_branches_table.php',
        '--force' => true,
    ]);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_06_29_150001_create_mst_doctor_branches_table.php',
        '--force' => true,
    ]);

    expect(DB::table('mst_doctor_branches')
        ->where('doctor_id', $doctor->id)
        ->where('branch_id', test()->rmeBranch->id)
        ->exists())->toBeTrue();
});

it('filters branch master index to RME-enabled branches only', function () {
    $manager = userWith(['view_branch_master_data', 'manage_branch_master_data']);

    $this->actingAs($manager)
        ->get(route('settings.branches.index'))
        ->assertOk()
        ->assertSee('Master Cabang RME')
        ->assertSee('TLK1')
        ->assertDontSee('INV9 —');
});
