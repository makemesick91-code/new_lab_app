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

it('shows admin klinik only online doctors from the active branch', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $otherDoctor = Doctor::factory()->withAllowedBranches([test()->otherRmeBranch])->create(['clinic_id' => test()->clinic->id]);
    rmeMakeDoctorOnline($otherDoctor, test()->otherRmeBranch, test()->otherRoom);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee(test()->doctor->name)
        ->assertDontSee($otherDoctor->name);
});

it('does not show offline doctors to admin klinik', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Belum ada dokter online');
});

it('does not show doctors online in another branch to admin klinik', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->otherRmeBranch, test()->otherRoom);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee(test()->doctor->name);
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
