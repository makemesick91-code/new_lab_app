<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 — who may request, cancel, reject and read an amendment
|--------------------------------------------------------------------------
|
| Rule 240 §6, three layers (CG-06): route permission (`request_rme_amendments`
| for Doctor, `approve_rme_amendments` for Supervisor RME; Super Admin via '*'),
| RmeAmendmentPolicy, and the service inside the transaction (binding Super
| Admin through Gate::before). The supervisor queue and the detail page are
| branch-scoped through RmeWorkingBranchScope (CG-05): an amendment outside the
| viewer's scope, or one a requester did not file, answers 404. The pages are
| `private, no-store`. The record page offers the request only where the server
| would accept it (rule 135).
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m33z`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'AMZ1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'M33Z Pasien Amandemen']);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    // RME pages need a doctor's online context (EnsureRmeOnlineContext).
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

function m33zSheet(?Branch $branch = null, ?User $finalizer = null): MedicalRecord
{
    $branch ??= test()->branch;
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE])->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'assessment' => 'm33z pulpitis',
    ]);
    $sheet->forceFill([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
        'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
    ])->save();

    return $sheet->fresh();
}

/** @return array<string, mixed> */
function m33zForm(): array
{
    return [
        'reason' => 'm33z diagnosis kerja salah ketik',
        'proposed' => ['fields' => ['assessment' => 'm33z pulpitis ireversibel']],
    ];
}

function m33zPending(?MedicalRecord $sheet = null, ?User $by = null): RmeAmendment
{
    return app(RmeAmendmentService::class)->request(
        $sheet ?? m33zSheet(),
        $by ?? test()->doctorUser,
        'm33z diagnosis kerja salah ketik',
        ['fields' => ['assessment' => 'm33z pulpitis ireversibel']],
    );
}

/** @return array<string, User> */
function m33zFrontDesk(Branch $branch): array
{
    $frontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive($frontOffice, $branch);
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $branch);
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $branch);
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $branch);

    return ['Front Office' => $frontOffice, 'Kasir' => $kasir, 'Admin Klinik' => $admin, 'Perawat' => $perawat];
}

// ─── Request ─────────────────────────────────────────────────────────────────

it('lets only the doctor of record (or finalizer) request over HTTP', function () {
    $sheet = m33zSheet();

    foreach (m33zFrontDesk($this->branch) as $role => $user) {
        $this->actingAs($user)->get(route('rme.amendments.create-sheet', $sheet))->assertForbidden();
        $this->actingAs($user)->post(route('rme.amendments.store-sheet', $sheet), m33zForm())->assertForbidden();
    }

    // A doctor who also treated this patient reads the record but is not its doctor
    // of record nor its finalizer: 403 with the reason. One who never treated the
    // patient may not even see it: 404 (review LOW-2).
    $treating = Doctor::factory()->create(['branch_id' => $this->branch->id]);
    $otherDoctor = rmeMakeDoctorOnline($treating, $this->branch);
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $treating->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $this->actingAs($otherDoctor)->post(route('rme.amendments.store-sheet', $sheet), m33zForm())->assertForbidden();
    $stranger = rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => $this->branch->id]), $this->branch);
    $this->actingAs($stranger)->post(route('rme.amendments.store-sheet', $sheet), m33zForm())->assertNotFound();
    $this->actingAs($this->supervisor)->post(route('rme.amendments.store-sheet', $sheet), m33zForm())->assertForbidden();

    $superAdmin = userInRole('Super Admin');
    $this->actingAs($superAdmin)->get(route('rme.amendments.create-sheet', $sheet))->assertForbidden();
    $this->actingAs($superAdmin)->post(route('rme.amendments.store-sheet', $sheet), m33zForm())->assertForbidden();
    expect(RmeAmendment::query()->count())->toBe(0);

    $this->actingAs($this->doctorUser)->get(route('rme.amendments.create-sheet', $sheet))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $response = $this->actingAs($this->doctorUser)->post(route('rme.amendments.store-sheet', $sheet), m33zForm());

    $amendment = RmeAmendment::query()->sole();
    $response->assertRedirect(route('rme.amendments.show', $amendment));
    expect($amendment->proposed_content)->toBe(['fields' => ['assessment' => 'm33z pulpitis ireversibel']]);
});

it('answers a bounded reason error on the form', function () {
    $sheet = m33zSheet();

    $this->actingAs($this->doctorUser)
        ->from(route('rme.amendments.create-sheet', $sheet))
        ->post(route('rme.amendments.store-sheet', $sheet), ['reason' => 'pendek'] + m33zForm())
        ->assertRedirect(route('rme.amendments.create-sheet', $sheet))
        ->assertSessionHasErrors('reason');

    expect(RmeAmendment::query()->count())->toBe(0);
});

// ─── Cancel / reject ─────────────────────────────────────────────────────────

it('lets only the requester cancel over HTTP', function () {
    $amendment = m33zPending();

    $this->actingAs($this->supervisor)->post(route('rme.amendments.cancel', $amendment))->assertForbidden();
    $this->actingAs(userInRole('Super Admin'))->post(route('rme.amendments.cancel', $amendment))->assertSessionHasErrors('amendment');
    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    $this->actingAs($this->doctorUser)->post(route('rme.amendments.cancel', $amendment))
        ->assertRedirect(route('rme.amendments.show', $amendment));
    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_CANCELLED);
});

it('lets a Supervisor RME reject over HTTP and refuses the requester, the doctor of record and the front desk', function () {
    $amendment = m33zPending();
    $decision = ['decision_reason' => 'm33z usulan tidak sesuai catatan klinis'];

    foreach (m33zFrontDesk($this->branch) as $user) {
        $this->actingAs($user)->post(route('rme.amendments.reject', $amendment), $decision)->assertForbidden();
    }

    // The requester (here also the doctor of record) holding the approve permission: still refused.
    $this->doctorUser->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    $this->actingAs($this->doctorUser->fresh())->post(route('rme.amendments.reject', $amendment), $decision)->assertForbidden();

    $superAdmin = userInRole('Super Admin');
    $own = m33zPending(m33zSheet(finalizer: $superAdmin), $superAdmin);
    $this->actingAs($superAdmin)->post(route('rme.amendments.reject', $own), $decision)->assertSessionHasErrors('amendment');
    expect($own->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    $this->actingAs($this->supervisor)->post(route('rme.amendments.reject', $amendment), ['decision_reason' => 'pendek'])
        ->assertSessionHasErrors('decision_reason');
    $this->actingAs($this->supervisor)->post(route('rme.amendments.reject', $amendment), $decision)
        ->assertRedirect(route('rme.amendments.show', $amendment));
    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_REJECTED)
        ->and((int) $amendment->fresh()->decided_by)->toBe((int) $this->supervisor->id);

    $this->actingAs($superAdmin)->post(route('rme.amendments.reject', $own->fresh()), $decision);
    expect($own->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

// ─── Queue and detail: branch scope, 404, no-store ───────────────────────────

it('shows the supervisor queue of the approver\'s branch scope only; out of scope answers 404', function () {
    $other = Branch::factory()->create(['code' => 'AMZ2', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor->branches()->syncWithoutDetaching([(int) $other->id]);
    $here = m33zPending(m33zSheet());
    $there = m33zPending(m33zSheet($other));

    $this->actingAs($this->supervisor)->get(route('rme.amendments.index'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('data-amendment-row="'.$here->id.'"', false)
        ->assertSee('data-amendment-row="'.$there->id.'"', false);

    // An approver pinned to one working branch (a context-bound clinical role).
    $pinned = userInRole('Perawat');
    $pinned->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    rmeMakePerawatActive($pinned, $this->branch);

    $this->actingAs($pinned)->get(route('rme.amendments.index'))
        ->assertOk()
        ->assertSee('data-amendment-row="'.$here->id.'"', false)
        ->assertDontSee('data-amendment-row="'.$there->id.'"', false);
    $this->actingAs($pinned)->get(route('rme.amendments.show', $there))->assertNotFound();
    $this->actingAs($pinned)->post(route('rme.amendments.reject', $there), ['decision_reason' => 'm33z di luar cabang kerja'])->assertNotFound();
    expect($there->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    $this->actingAs($pinned)->get(route('rme.amendments.show', $here))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('m33z pulpitis ireversibel');
});

it('shows a requester their own amendment and answers 404 for anyone else\'s; the queue is for approvers', function () {
    $mine = m33zPending();

    $this->actingAs($this->doctorUser)->get(route('rme.amendments.show', $mine))
        ->assertOk()
        ->assertSee('data-amendment-cancel', false)
        ->assertDontSee('data-amendment-reject', false);
    $this->actingAs($this->doctorUser)->get(route('rme.amendments.index'))->assertForbidden();

    $otherDoctor = rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => $this->branch->id]), $this->branch);
    $this->actingAs($otherDoctor)->get(route('rme.amendments.show', $mine))->assertNotFound();
    $this->actingAs($otherDoctor)->get(route('rme.amendments.show', 999999))->assertNotFound();

    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $mine))
        ->assertOk()
        ->assertSee('data-amendment-reject', false)
        ->assertDontSee('data-amendment-cancel', false)
        ->assertDontSee('data-amendment-approve', false);
});

// ─── Record page mirror (rule 135) ───────────────────────────────────────────

it('offers the request on the final sheet page only to the doctor of record, and shows the pending status', function () {
    $sheet = m33zSheet();
    $visit = $sheet->clinicVisit;

    $this->actingAs($this->doctorUser)->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-amendment-request', false)
        ->assertSee(route('rme.amendments.create-sheet', $sheet), false);

    foreach ([$this->supervisor, userInRole('Super Admin')] as $viewer) {
        $this->actingAs($viewer)->get(route('rme.visits.medical-record.show', $visit))
            ->assertOk()
            ->assertDontSee('data-amendment-request', false);
    }

    $amendment = m33zPending($sheet);

    $this->actingAs($this->supervisor)->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-amendment-status="pending"', false)
        ->assertSee(route('rme.amendments.show', $amendment), false);
});

// ─── Review fixes (M3.3 PR4 review) ──────────────────────────────────────────

it('never lets the finalizer decide over HTTP and offers them no decision (HIGH-1)', function () {
    $amendment = m33zPending(m33zSheet(finalizer: $this->supervisor));
    $decision = ['decision_reason' => 'm33z pemfinal menolak koreksi'];

    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertDontSee('data-amendment-reject', false);
    $this->actingAs($this->supervisor)->post(route('rme.amendments.reject', $amendment), $decision)->assertForbidden();

    $superAdmin = userInRole('Super Admin');
    $theirs = m33zPending(m33zSheet(finalizer: $superAdmin));
    $this->actingAs($superAdmin)->get(route('rme.amendments.show', $theirs))
        ->assertOk()
        ->assertDontSee('data-amendment-reject', false);
    $this->actingAs($superAdmin)->post(route('rme.amendments.reject', $theirs), $decision)->assertSessionHasErrors('amendment');

    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($theirs->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('answers 404 before anything else to an account that may not see the record (LOW-2)', function () {
    $final = m33zSheet();
    $draft = m33zSheet();
    $draft->forceFill(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null, 'finalized_by' => null])->save();

    // A doctor of another practice: no treating relationship with this patient.
    $stranger = rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => $this->branch->id]), $this->branch);

    foreach ([$final, $draft] as $sheet) {
        $this->actingAs($stranger)->get(route('rme.amendments.create-sheet', $sheet))->assertNotFound();
        $this->actingAs($stranger)->post(route('rme.amendments.store-sheet', $sheet), ['reason' => 'x'])->assertNotFound();
    }

    // An approver outside the amendment's branch scope: 404, never a validation error.
    $other = Branch::factory()->create(['code' => 'AMZ3', 'is_active' => true, 'is_rme_enabled' => true]);
    $amendment = m33zPending($final);
    $pinned = userInRole('Perawat');
    $pinned->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    rmeMakePerawatActive($pinned, $other);

    $this->actingAs($pinned)->post(route('rme.amendments.reject', $amendment), ['decision_reason' => 'x'])
        ->assertNotFound();
    expect(RmeAmendment::query()->count())->toBe(1);
});

it('shows patient identity in the queue only for a row the approver may open (LOW-4)', function () {
    $amendment = m33zPending();

    $this->actingAs($this->supervisor)->get(route('rme.amendments.index'))
        ->assertOk()
        ->assertSee('M33Z Pasien Amandemen');

    // An approver narrowed by the doctor-patient scope: the row is counted, its identity is not shown.
    $narrowed = rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => $this->branch->id]), $this->branch);
    $narrowed->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);

    $this->actingAs($narrowed->fresh())->get(route('rme.amendments.index'))
        ->assertOk()
        ->assertSee('data-amendment-row="'.$amendment->id.'"', false)
        ->assertDontSee('M33Z Pasien Amandemen')
        ->assertDontSee(route('rme.amendments.show', $amendment), false);
});
