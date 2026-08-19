<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\BranchSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RmeSmokeTestSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    test()->seed([
        BranchSeeder::class,
        PermissionSeeder::class,
        RoleSeeder::class,
        RmeSmokeTestSeeder::class,
    ]);

    $this->branch = Branch::where('code', RmeSmokeTestSeeder::BRANCH_CODE)->firstOrFail();
    $this->clinicalVisit = ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER)->firstOrFail();
    $this->cashierVisit = ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER_CASHIER)->firstOrFail();
});

function smokeUser(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

it('allows smoke-test Doctor to access doctor-side RME routes', function () {
    $doctor = smokeUser(RmeSmokeTestSeeder::DOCTOR_USER_EMAIL);

    $this->actingAs($doctor)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($doctor)->get(route('rme.visits.show', $this->clinicalVisit))->assertOk();
    $this->actingAs($doctor)->get(route('rme.visits.medical-record.show', $this->clinicalVisit))->assertOk();
    $this->actingAs($doctor)->get(route('rme.visits.odontogram.show', $this->clinicalVisit))->assertOk();
    $this->actingAs($doctor)->get(route('rme.medical-records.index'))->assertOk();
});

it('allows smoke-test Perawat to access visit support routes but not cashier', function () {
    $perawat = smokeUser(RmeSmokeTestSeeder::PERAWAT_USER_EMAIL);

    $this->actingAs($perawat)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($perawat)->get(route('rme.visits.create'))->assertOk();
    $this->actingAs($perawat)->get(route('rme.visits.show', $this->clinicalVisit))->assertOk();
    $this->actingAs($perawat)->get(route('rme.cashier.index'))->assertForbidden();
});

it('still redirects the smoke-test Perawat once the online context is released', function () {
    $perawat = smokeUser(RmeSmokeTestSeeder::PERAWAT_USER_EMAIL);

    // Release the seeded context through the same service the application uses.
    app(UserOnlineContextService::class)->markOffline($perawat);

    $this->actingAs($perawat)
        ->get(route('rme.visits.index'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('allows smoke-test Kasir to access cashier routes but not visit creation', function () {
    $kasir = smokeUser(RmeSmokeTestSeeder::KASIR_USER_EMAIL);
    rmeMakeKasirActive($kasir, $this->cashierVisit->branch);

    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk();
    $this->actingAs($kasir)->get(route('rme.cashier.create', $this->cashierVisit))->assertOk();
    $this->actingAs($kasir)->get(route('rme.visits.create'))->assertForbidden();
});

it('denies Kasir from doctor-only medical record edit routes', function () {
    $kasir = smokeUser(RmeSmokeTestSeeder::KASIR_USER_EMAIL);
    rmeMakeKasirActive($kasir, $this->cashierVisit->branch);
    $record = $this->clinicalVisit->medicalRecord;

    $this->actingAs($kasir)->patch(route('rme.visits.medical-record.update', [
        'clinicVisit' => $this->clinicalVisit,
        'medicalRecord' => $record,
    ]), [
        'notes' => 'Percobaan edit kasir',
    ])->assertForbidden();
});

it('denies unauthorized users from RME routes', function () {
    $user = userWith([]);

    $this->actingAs($user)->get(route('rme.visits.index'))->assertForbidden();
    $this->actingAs($user)->get(route('rme.cashier.index'))->assertForbidden();
});

it('allows smoke-test Owner dashboard access per Phase 22.1 permissions', function () {
    $owner = smokeUser(RmeSmokeTestSeeder::OWNER_USER_EMAIL);

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($owner)->get(route('rme.cashier.index'))->assertForbidden();
});
