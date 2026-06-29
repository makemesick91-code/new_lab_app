<?php

/**
 * Hotfix Sprint 66.1.4 — Auto Doctor From Assigned Room Queue.
 */

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

    test()->rmeBranch = Branch::factory()->create(['code' => 'RME71', 'is_rme_enabled' => true]);
    test()->otherRmeBranch = Branch::factory()->create(['code' => 'RME72', 'is_rme_enabled' => true]);
    test()->clinic = Clinic::factory()->create();
    test()->room = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    test()->otherRoom = ClinicRoom::factory()->create([
        'branch_id' => test()->otherRmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    test()->patient = Patient::factory()->create(['branch_id' => test()->rmeBranch->id]);
    test()->treatment = Treatment::factory()->create(['is_active' => true]);
    test()->doctor = Doctor::factory()->create([
        'clinic_id' => test()->clinic->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    test()->doctorUser = User::factory()->create()->assignRole('Doctor');
    test()->doctor->update(['user_id' => test()->doctorUser->id]);
    test()->doctor->branches()->sync([(int) test()->rmeBranch->id]);
    test()->adminUser = User::factory()->create()->assignRole('Admin Klinik');
    test()->adminUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    test()->ownerUser = User::factory()->create()->assignRole('Owner');
    test()->ownerUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    test()->onlineContext = app(UserOnlineContextService::class);
});

function autoDoctorQueueVisit(array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->rmeBranch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => null,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ], $overrides));
}

it('hides doctor dropdown for admin klinik on visit create', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee('name="doctor_id"', false)
        ->assertDontSee('- Pilih dokter -')
        ->assertSee('Dokter akan otomatis dipilih berdasarkan ruangan yang diassign pada halaman antrian.');
});

it('lets admin klinik create visit without doctor_id', function () {
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
        ->and($visit->doctor_id)->toBeNull()
        ->and($visit->branch_id)->toBe(test()->rmeBranch->id);
});

it('ignores manipulated doctor_id for admin klinik visit store', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $this->actingAs(test()->adminUser)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    expect(ClinicVisit::query()->latest('id')->first()->doctor_id)->toBeNull();
});

it('auto assigns doctor when admin klinik assigns room with one online doctor', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);
    $visit = autoDoctorQueueVisit();

    $this->actingAs(test()->adminUser)
        ->from(route('rme.patient-queue.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->room->id])
        ->assertRedirect();

    $visit->refresh();

    expect($visit->clinic_room_id)->toBe(test()->room->id)
        ->and($visit->doctor_id)->toBe(test()->doctor->id);
});

it('rejects room assignment when no doctor is online in the room', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    $visit = autoDoctorQueueVisit();

    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->room->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull()
        ->and($visit->doctor_id)->toBeNull();
});

it('rejects room assignment when more than one doctor is online in the room', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $secondDoctor = Doctor::factory()->create(['clinic_id' => test()->clinic->id]);
    $secondUser = User::factory()->create()->assignRole('Doctor');
    $secondDoctor->update(['user_id' => $secondUser->id]);
    $secondDoctor->branches()->sync([(int) test()->rmeBranch->id]);

    // Simulate a stale duplicate context (defense-in-depth at assign time).
    UserOnlineContext::query()->updateOrCreate(
        ['user_id' => $secondUser->id],
        [
            'branch_id' => test()->rmeBranch->id,
            'clinic_room_id' => test()->room->id,
            'role_context' => UserOnlineContext::ROLE_DOCTOR,
            'status' => UserOnlineContext::STATUS_ONLINE,
            'online_since' => now(),
            'last_seen_at' => now(),
            'offline_at' => null,
        ],
    );

    $visit = autoDoctorQueueVisit();

    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->room->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

it('rejects doctor going online in a room occupied by another active doctor', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $otherDoctor = Doctor::factory()->create(['clinic_id' => test()->clinic->id]);
    $otherUser = User::factory()->create()->assignRole('Doctor');
    $otherDoctor->update(['user_id' => $otherUser->id]);
    $otherDoctor->branches()->sync([(int) test()->rmeBranch->id]);

    expect(fn () => test()->onlineContext->startDoctorSession(
        $otherUser,
        test()->rmeBranch->id,
        test()->room->id,
    ))->toThrow(ValidationException::class);
});

it('lets the same doctor refresh online context in their own room', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    test()->onlineContext->startDoctorSession(
        test()->doctorUser,
        test()->rmeBranch->id,
        test()->room->id,
    );

    $context = UserOnlineContext::query()->where('user_id', test()->doctorUser->id)->first();

    expect($context)->not->toBeNull()
        ->and($context->status)->toBe(UserOnlineContext::STATUS_ONLINE)
        ->and((int) $context->clinic_room_id)->toBe(test()->room->id);
});

it('rejects admin klinik assigning a room from another branch', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctor, test()->otherRmeBranch, test()->otherRoom);
    $visit = autoDoctorQueueVisit();

    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->otherRoom->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

it('keeps owner manual doctor selection on visit create', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $this->actingAs(test()->ownerUser)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('name="doctor_id"', false)
        ->assertSee('- Pilih dokter -')
        ->assertDontSee('Dokter akan otomatis dipilih berdasarkan ruangan yang diassign pada halaman antrian.');
});

it('lets owner store visit with manual doctor selection', function () {
    rmeMakeDoctorOnline(test()->doctor, test()->rmeBranch, test()->room);

    $this->actingAs(test()->ownerUser)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->rmeBranch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    expect(ClinicVisit::query()->latest('id')->first()->doctor_id)->toBe(test()->doctor->id);
});
