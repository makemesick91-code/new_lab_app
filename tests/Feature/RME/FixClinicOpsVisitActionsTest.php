<?php

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — FIX-05 / FIX-07 / FIX-08.
 *
 * FIX-05: only a clinically responsible role may finish an examination.
 * FIX-07: Admin Klinik's visit detail is read-only plus "Cetak RME".
 * FIX-08: SATUSEHAT is Super Admin only, in the menu AND on the route.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'TKMV', 'name' => 'Cabang Uji', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
});

function fcoActionsVisit(array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Aksi']);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ], $overrides));
}

/* ------------------------------------------------ FIX-05 examination completion */

it('lets a clinically authorised user finish an examination', function () {
    $visit = fcoActionsVisit();
    $doctorUser = userWith(['view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination']);

    $this->actingAs($doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('denies Admin Klinik the examination-completion action by direct POST', function () {
    $visit = fcoActionsVisit();

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->branch);

    // Admin Klinik still manages visits, but may not close an examination.
    expect($admin->can('manage_clinic_visits'))->toBeTrue()
        ->and($admin->can('complete_rme_examination'))->toBeFalse();

    $this->actingAs($admin)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('still lets Admin Klinik run the front-office transitions it owns', function () {
    $visit = fcoActionsVisit(['status' => ClinicVisit::STATUS_REGISTERED]);

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->branch);

    $this->actingAs($admin)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_WAITING])
        ->assertRedirect();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('keeps the cashier-owned completed transition unreachable from the visit surface', function () {
    $visit = fcoActionsVisit(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $doctorUser = userWith(['view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination']);

    $this->actingAs($doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

/* ------------------------------------------------ FIX-07 read-only visit detail */

it('shows Admin Klinik only the Cetak RME action on the visit detail', function () {
    $visit = fcoActionsVisit();

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->branch);

    $html = $this->actingAs($admin)->get(route('rme.visits.show', $visit))->assertOk()->getContent();

    expect($html)->toContain(route('rme.visits.print', $visit));

    foreach ([
        route('rme.visits.transition', $visit),
        route('rme.visits.edit', $visit),
        route('rme.visits.assign-room', $visit),
        route('rme.visits.medical-record.show', $visit),
        route('rme.visits.odontogram.show', $visit),
        route('rme.visits.prescription.show', $visit),
    ] as $forbiddenAction) {
        expect($html)->not->toContain($forbiddenAction);
    }
});

it('keeps the clinical surfaces on the visit detail for a clinician', function () {
    $visit = fcoActionsVisit();
    $clinician = userWith(['view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination']);

    $html = $this->actingAs($clinician)->get(route('rme.visits.show', $visit))->assertOk()->getContent();

    expect($html)->toContain(route('rme.visits.medical-record.show', $visit))
        ->and($html)->toContain(route('rme.visits.print', $visit));
});

/* ------------------------------------------------ FIX-08 SATUSEHAT Super Admin only */

it('lets only a Super Admin reach SATUSEHAT', function () {
    $this->actingAs(superAdmin())
        ->get(route('satusehat.submissions.index'))
        ->assertOk();
});

it('denies every non-Super-Admin role every SATUSEHAT entry point', function () {
    $roles = ['Supervisor RME', 'Owner', 'Admin Klinik', 'Kasir', 'Admin Lab', 'Doctor', 'Perawat'];

    foreach ($roles as $role) {
        $user = userInRole($role);

        $this->actingAs($user)
            ->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('satusehat.submissions.index'))
            ->assertForbidden();

        expect($user->can('satusehat.access'))->toBeFalse();
    }
});

it('hides the SATUSEHAT menu from a non-Super-Admin who still holds its permissions', function () {
    $supervisor = userInRole('Supervisor RME');

    // The permissions are deliberately left in place — the gate is the boundary.
    expect($supervisor->can('view_satusehat_submissions'))->toBeTrue()
        ->and($supervisor->can('satusehat.access'))->toBeFalse();

    $html = $this->actingAs($supervisor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('dashboard'))
        ->getContent();

    expect($html)->not->toContain('/rme/satusehat');
});
