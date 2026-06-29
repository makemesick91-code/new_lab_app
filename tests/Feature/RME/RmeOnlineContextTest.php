<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->rmeBranch = Branch::factory()->create(['code' => 'RME66', 'is_rme_enabled' => true]);
    test()->otherRmeBranch = Branch::factory()->create(['code' => 'RME67', 'is_rme_enabled' => true]);
    test()->nonRmeBranch = Branch::factory()->create(['code' => 'NORM', 'is_rme_enabled' => false]);
    test()->room = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    test()->otherRoom = ClinicRoom::factory()->create([
        'branch_id' => test()->otherRmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    test()->clinic = Clinic::factory()->create();
    test()->patient = Patient::factory()->create();
    test()->treatment = Treatment::factory()->create(['is_active' => true]);
    test()->doctor = Doctor::factory()->create([
        'clinic_id' => test()->clinic->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    test()->doctorUser = User::factory()->create()->assignRole('Doctor');
    test()->doctor->update(['user_id' => test()->doctorUser->id]);
    test()->adminUser = User::factory()->create()->assignRole('Admin Klinik');
    test()->adminUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    test()->ownerUser = User::factory()->create()->assignRole('Owner');
    test()->ownerUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    test()->onlineContext = app(UserOnlineContextService::class);
});

it('treats a doctor without branch or room as offline', function () {
    expect(test()->onlineContext->isDoctorOnline(test()->doctorUser))->toBeFalse();
});

it('lets a doctor choose branch and room to become online', function () {
    test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->rmeBranch->id,
        test()->room->id,
    );

    expect(test()->onlineContext->isDoctorOnline(test()->doctorUser))->toBeTrue();
});

it('rejects a doctor choosing a room from another branch', function () {
    expect(fn () => test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->rmeBranch->id,
        test()->otherRoom->id,
    ))->toThrow(ValidationException::class);
});

it('rejects a doctor choosing a non-RME branch', function () {
    expect(fn () => test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->nonRmeBranch->id,
        test()->room->id,
    ))->toThrow(ValidationException::class);
});

it('rejects a doctor going online in a branch not in allowed practice list', function () {
    expect(fn () => test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->otherRmeBranch->id,
        test()->otherRoom->id,
    ))->toThrow(ValidationException::class);
});

it('hides a doctor from dropdown after logout offline', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    test()->onlineContext->markOffline(test()->doctorUser);

    expect(test()->onlineContext->activeDoctorsForBranch(test()->rmeBranch->id))->toBeEmpty();
});

it('redirects admin klinik without branch to context selection', function () {
    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.index'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('activates admin klinik after choosing an RME branch', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    expect(test()->onlineContext->isAdminClinicActive(test()->adminUser))->toBeTrue()
        ->and(test()->onlineContext->resolveActiveBranchForAdmin(test()->adminUser))->toBe(test()->rmeBranch->id);
});

it('shows admin klinik auto doctor info instead of doctor dropdown on visit create', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $otherDoctor = Doctor::factory()->withAllowedBranches([test()->otherRmeBranch])->create(['clinic_id' => test()->clinic->id]);
    rmeMakeDoctorOnline($otherDoctor, test()->otherRmeBranch, test()->otherRoom);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Cabang Kunjungan')
        ->assertSee(test()->rmeBranch->code)
        ->assertSee('Dokter akan otomatis dipilih berdasarkan ruangan yang diassign pada halaman antrian.')
        ->assertDontSee('- Pilih cabang RME -')
        ->assertDontSee('- Pilih dokter -')
        ->assertDontSee($otherDoctor->name);
});

it('stores admin klinik visit using active context branch without manual branch input', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    $this->actingAs(test()->adminUser)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'patient_id' => test()->patient->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::query()->latest('id')->first();

    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe(test()->rmeBranch->id)
        ->and($visit->doctor_id)->toBeNull();
});

it('ignores manipulated branch_id for admin klinik visit store', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    $this->actingAs(test()->adminUser)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->otherRmeBranch->id,
            'patient_id' => test()->patient->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::query()->latest('id')->first();

    expect($visit->branch_id)->toBe(test()->rmeBranch->id)
        ->and($visit->branch_id)->not->toBe(test()->otherRmeBranch->id);
});

it('redirects admin klinik without context from visit create to context selection', function () {
    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('lets owner keep manual branch selection on visit create', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $this->actingAs(test()->ownerUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('- Pilih cabang RME -')
        ->assertDontSee('Cabang Kunjungan');
});

it('returns only context-branch online doctors for admin klinik online-doctors endpoint', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $otherDoctor = Doctor::factory()->withAllowedBranches([test()->otherRmeBranch])->create(['clinic_id' => test()->clinic->id]);
    rmeMakeDoctorOnline($otherDoctor, test()->otherRmeBranch, test()->otherRoom);

    $response = $this->actingAs(test()->adminUser)
        ->getJson(route('rme.visits.online-doctors', ['branch_id' => test()->otherRmeBranch->id]))
        ->assertOk();

    $doctorIds = collect($response->json('doctors'))->pluck('id');

    expect($doctorIds)->toContain(test()->doctor->id)
        ->and($doctorIds)->not->toContain($otherDoctor->id);
});

it('shows auto doctor info for admin klinik when no doctors are online', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Dokter akan otomatis dipilih berdasarkan ruangan yang diassign pada halaman antrian.');
});

it('does not show doctor dropdown to admin klinik when doctors are online in another branch', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->otherRmeBranch, test()->otherRoom);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee(test()->doctor->name)
        ->assertDontSee('- Pilih dokter -');
});

it('rejects visit store with an offline doctor', function () {
    $manager = userWith(['manage_clinic_visits']);

    $this->actingAs($manager)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->rmeBranch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertSessionHasErrors('doctor_id');
});

it('rejects visit store with a doctor online in another branch', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->otherRmeBranch, test()->otherRoom);
    $manager = userWith(['manage_clinic_visits']);

    $this->actingAs($manager)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->rmeBranch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertSessionHasErrors('doctor_id');
});

it('allows owner to open visit create without admin context', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $this->actingAs(test()->ownerUser)
        ->get(route('rme.visits.create'))
        ->assertOk();
});

it('keeps historical visit doctor visible even when doctor is offline', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);
    $manager = userWith(['manage_clinic_visits', 'view_clinic_visits']);

    $this->actingAs($manager)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->rmeBranch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    test()->onlineContext->markOffline(test()->doctorUser);

    $visit = ClinicVisit::query()->latest('id')->first();

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee(test()->doctor->name);
});

it('redirects doctor without context to selection page', function () {
    $this->actingAs(test()->doctorUser)
        ->get(route('dashboard'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('renders doctor online context select page with allowed branches', function () {
    test()->doctor->branches()->sync([test()->rmeBranch->id]);

    $this->actingAs(test()->doctorUser)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('Mulai Online')
        ->assertSee(test()->rmeBranch->name)
        ->assertSee(test()->room->name, false);
});

it('renders empty state for doctor without allowed branches on select page', function () {
    test()->doctor->branches()->sync([]);

    $this->actingAs(test()->doctorUser)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('Dokter belum memiliki Cabang Praktik')
        ->assertDontSee('Mulai Online');
});

it('renders admin klinik online context select page', function () {
    $this->actingAs(test()->adminUser)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('Mulai Bertugas')
        ->assertSee(test()->rmeBranch->name);
});

it('stores doctor online context with expected status fields', function () {
    test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->rmeBranch->id,
        test()->room->id,
    );

    $context = UserOnlineContext::query()->where('user_id', test()->doctorUser->id)->first();

    expect($context)->not->toBeNull()
        ->and($context->status)->toBe(UserOnlineContext::STATUS_ONLINE)
        ->and($context->role_context)->toBe(UserOnlineContext::ROLE_DOCTOR)
        ->and($context->branch_id)->toBe(test()->rmeBranch->id)
        ->and($context->clinic_room_id)->toBe(test()->room->id);
});
