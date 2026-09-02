<?php

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 1.
 *
 * Two device-independent Doctor restrictions:
 *
 *  §16 Room-scoped ACTIVE patient isolation — a Doctor only ever sees, and can
 *      only ever open, the ACTIVE (pre-examination) patients of the treatment
 *      room they are currently online in. Other rooms are hidden from the list
 *      AND denied on direct access. The room comes from the server-side online
 *      context, never from the request.
 *
 *  §18/§19 Print denial — a Doctor may read and edit RME/Odontogram under the
 *      existing lifecycle rules but may never print or export them.
 *
 * §17 is the boundary that keeps this safe: the room restriction applies ONLY to
 * the ACTIVE operational set (registered/waiting/in_progress). Post-examination
 * (cashier_pending) and historical (completed/cancelled) records stay reachable
 * exactly as before, so clinical history is never hidden by this sprint.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;

use function Pest\Laravel\actingAs;

/**
 * One RME branch with two treatment rooms, a doctor online in room A, and an
 * ACTIVE visit sitting in each room. Both visits are attributed to this doctor
 * so the pre-existing Sprint 66.2 doctor-patient scope already passes — that
 * isolates ROOM scope as the only thing under test.
 *
 * @return array{doctor: User, branch: Branch, roomA: ClinicRoom, roomB: ClinicRoom, visitA: ClinicVisit, visitB: ClinicVisit, doctorRecord: Doctor}
 */
function doctorRoomScopeFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create([
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $roomA = ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    $roomB = ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $doctorRecord = Doctor::factory()->withAllowedBranches([$branch])->create();
    $doctorUser = rmeMakeDoctorOnline($doctorRecord, $branch, $roomA);

    $visitA = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'clinic_room_id' => $roomA->id,
        'doctor_id' => $doctorRecord->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $visitB = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'clinic_room_id' => $roomB->id,
        'doctor_id' => $doctorRecord->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    return compact('branch', 'roomA', 'roomB', 'visitA', 'visitB', 'doctorRecord')
        + ['doctor' => $doctorUser];
}

// ---------------------------------------------------------------------------
// §16 — room-scoped ACTIVE patient list
// ---------------------------------------------------------------------------

it('shows a doctor only the active patients of their current room on the treatment worklist', function () {
    $f = doctorRoomScopeFixture();

    $response = actingAs($f['doctor'])->get(route('rme.treatment-room-worklist.index'));

    $response->assertOk();
    $response->assertSee($f['visitA']->visit_number);
    $response->assertDontSee($f['visitB']->visit_number);
});

it('shows a doctor only the active patients of their current room on the patient queue', function () {
    $f = doctorRoomScopeFixture();

    $response = actingAs($f['doctor'])->get(route('rme.patient-queue.index'));

    $response->assertOk();
    $response->assertSee($f['visitA']->visit_number);
    $response->assertDontSee($f['visitB']->visit_number);
});

it('ignores a tampered clinic_room_id filter so a doctor cannot list another room', function () {
    $f = doctorRoomScopeFixture();

    $response = actingAs($f['doctor'])->get(
        route('rme.treatment-room-worklist.index', ['clinic_room_id' => $f['roomB']->id])
    );

    $response->assertOk();
    $response->assertDontSee($f['visitB']->visit_number);
    // The crafted filter is IGNORED, not honoured into an empty page — the
    // doctor still sees their own room.
    $response->assertSee($f['visitA']->visit_number);
});

it('ignores a tampered branch_id filter so a doctor cannot widen scope', function () {
    $f = doctorRoomScopeFixture();

    $other = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $otherRoom = ClinicRoom::factory()->create([
        'branch_id' => $other->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    $otherVisit = ClinicVisit::factory()->create([
        'branch_id' => $other->id,
        'clinic_room_id' => $otherRoom->id,
        'doctor_id' => $f['doctorRecord']->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $response = actingAs($f['doctor'])->get(
        route('rme.treatment-room-worklist.index', ['branch_id' => $other->id])
    );

    $response->assertOk();
    $response->assertDontSee($otherVisit->visit_number);
    $response->assertSee($f['visitA']->visit_number);
});

it('does not offer a doctor an all-rooms filter control', function () {
    $f = doctorRoomScopeFixture();

    $response = actingAs($f['doctor'])->get(route('rme.treatment-room-worklist.index'));

    $response->assertOk();
    $response->assertDontSee('name="clinic_room_id"', false);
});

// ---------------------------------------------------------------------------
// §16 — direct access / IDOR
// ---------------------------------------------------------------------------

it('denies a doctor direct access to an active visit in another room', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.show', $f['visitB']))
        ->assertForbidden();
});

it('denies a doctor the medical record of an active visit in another room', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.medical-record.show', $f['visitB']))
        ->assertForbidden();
});

it('denies a doctor the odontogram of an active visit in another room', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.odontogram.show', $f['visitB']))
        ->assertForbidden();
});

it('still allows a doctor their own current-room active visit', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.show', $f['visitA']))
        ->assertOk();
});

it('fails closed for a doctor with no online room context on active visits', function () {
    $f = doctorRoomScopeFixture();

    app(UserOnlineContextService::class)
        ->markOffline($f['doctor']);

    actingAs($f['doctor'])
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.show', $f['visitA']))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// §17 — historical / post-examination access must NOT regress
// ---------------------------------------------------------------------------

it('still allows a doctor a completed visit from another room', function () {
    $f = doctorRoomScopeFixture();

    $f['visitB']->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();

    actingAs($f['doctor'])
        ->get(route('rme.visits.show', $f['visitB']))
        ->assertOk();
});

it('still allows a doctor a cashier-pending visit from another room', function () {
    $f = doctorRoomScopeFixture();

    $f['visitB']->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();

    actingAs($f['doctor'])
        ->get(route('rme.visits.show', $f['visitB']))
        ->assertOk();
});

it('still lists a doctor historical visit from another room in daftar kunjungan', function () {
    $f = doctorRoomScopeFixture();

    // visitB sits in another room but is finished — history is never
    // room-restricted, and Daftar Kunjungan lists every status.
    $f['visitB']->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();

    $response = actingAs($f['doctor'])->get(route('rme.visits.index'));

    $response->assertOk();
    $response->assertSee($f['visitB']->visit_number);
});

it('still lists historical visits for a doctor with no online room context', function () {
    $f = doctorRoomScopeFixture();

    $f['visitB']->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();

    app(UserOnlineContextService::class)
        ->markOffline($f['doctor']);

    $response = actingAs($f['doctor'])
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.index'));

    // Failing closed must remove ACTIVE patients only — never the doctor's
    // own clinical history.
    $response->assertOk();
    $response->assertSee($f['visitB']->visit_number);
    $response->assertDontSee($f['visitA']->visit_number);
});

// ---------------------------------------------------------------------------
// §16 — other roles are untouched
// ---------------------------------------------------------------------------

/*
 * `view_treatment_worklist` is held by Doctor, Perawat and Supervisor RME —
 * Admin Klinik has its own page (Antrian Pasien). So the meaningful same-page
 * regression guard is Perawat: identical route, non-doctor role, must stay
 * unrestricted. Supervisor RME is explicitly exempted by the doctor scope.
 */
it('leaves perawat able to see every room on the worklist', function () {
    $f = doctorRoomScopeFixture();

    $perawat = User::factory()->create();
    $perawat->assignRole('Perawat');
    rmeMakePerawatActive($perawat, $f['branch']);

    $response = actingAs($perawat)->get(route('rme.treatment-room-worklist.index'));

    $response->assertOk();
    $response->assertSee($f['visitA']->visit_number);
    $response->assertSee($f['visitB']->visit_number);
});

it('leaves supervisor rme able to see every room on the worklist', function () {
    $f = doctorRoomScopeFixture();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('Supervisor RME');

    $response = actingAs($supervisor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.treatment-room-worklist.index'));

    $response->assertOk();
    $response->assertSee($f['visitA']->visit_number);
    $response->assertSee($f['visitB']->visit_number);
});

it('leaves admin klinik able to see every room on the patient queue', function () {
    $f = doctorRoomScopeFixture();

    $admin = User::factory()->create();
    $admin->assignRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $f['branch']);

    $response = actingAs($admin)->get(route('rme.patient-queue.index'));

    $response->assertOk();
    $response->assertSee($f['visitA']->visit_number);
    $response->assertSee($f['visitB']->visit_number);
});

// ---------------------------------------------------------------------------
// §18 / §19 — print denial
// ---------------------------------------------------------------------------

it('denies a doctor the rme visit print bundle', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.print', $f['visitA']))
        ->assertForbidden();
});

it('denies a doctor the rme visit pdf export', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])
        ->get(route('rme.visits.pdf', $f['visitA']))
        ->assertForbidden();
});

it('denies a doctor the odontogram print view', function () {
    $f = doctorRoomScopeFixture();

    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $f['visitA']->id,
        'branch_id' => $f['branch']->id,
    ]);

    actingAs($f['doctor'])
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertForbidden();
});

it('hides the print action from the doctor medical record page', function () {
    $f = doctorRoomScopeFixture();

    $patient = Patient::query()->find($f['visitA']->patient_id);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $f['visitA']->id,
        'patient_id' => $patient?->id,
        'branch_id' => $f['branch']->id,
    ]);

    $response = actingAs($f['doctor'])
        ->get(route('rme.visits.medical-record.show', $f['visitA']));

    $response->assertOk();
    $response->assertDontSee(route('rme.visits.print', $f['visitA']), false);
});

it('hides the print action from the doctor odontogram page', function () {
    $f = doctorRoomScopeFixture();

    // The odontogram must exist, otherwise the print button would be absent for
    // an unrelated reason and this assertion would pass vacuously.
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $f['visitA']->id,
        'branch_id' => $f['branch']->id,
    ]);

    $response = actingAs($f['doctor'])
        ->get(route('rme.visits.odontogram.show', $f['visitA']));

    $response->assertOk();
    $response->assertDontSee(route('rme.odontograms.print', $odontogram), false);
});

it('leaves admin klinik able to print the rme visit bundle', function () {
    $f = doctorRoomScopeFixture();

    $admin = User::factory()->create();
    $admin->assignRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $f['branch']);

    actingAs($admin)
        ->get(route('rme.visits.print', $f['visitA']))
        ->assertOk();
});

it('leaves admin klinik able to print the odontogram', function () {
    $f = doctorRoomScopeFixture();

    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $f['visitA']->id,
        'branch_id' => $f['branch']->id,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $f['branch']);

    actingAs($admin)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// §23 — audit
// ---------------------------------------------------------------------------

it('audits a rejected doctor rme print attempt', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])->get(route('rme.visits.print', $f['visitA']))->assertForbidden();

    expect(AuditLog::query()->where('action', 'DOCTOR_PRINT_RME_REJECTED')
        ->where('entity_id', $f['visitA']->id)->exists())->toBeTrue();
});

it('audits a rejected doctor odontogram print attempt', function () {
    $f = doctorRoomScopeFixture();

    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $f['visitA']->id,
        'branch_id' => $f['branch']->id,
    ]);

    actingAs($f['doctor'])->get(route('rme.odontograms.print', $odontogram))->assertForbidden();

    expect(AuditLog::query()->where('action', 'DOCTOR_PRINT_ODONTOGRAM_REJECTED')
        ->where('entity_id', $odontogram->id)->exists())->toBeTrue();
});

it('audits a rejected doctor out-of-room access attempt', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])->get(route('rme.visits.show', $f['visitB']))->assertForbidden();

    expect(AuditLog::query()->where('action', 'DOCTOR_ROOM_ACCESS_REJECTED')
        ->where('entity_id', $f['visitB']->id)->exists())->toBeTrue();
});

it('does not audit a rejection when the doctor legitimately opens their own room', function () {
    $f = doctorRoomScopeFixture();

    actingAs($f['doctor'])->get(route('rme.visits.show', $f['visitA']))->assertOk();

    expect(AuditLog::query()->where('action', 'DOCTOR_ROOM_ACCESS_REJECTED')->exists())->toBeFalse();
});
