<?php

/*
|--------------------------------------------------------------------------
| AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1
|--------------------------------------------------------------------------
|
| ClinicVisitService decides branch scope, visit-history reach and clinical
| authority from the EXPLICIT actor the caller passes — re-read from the
| database (persisted, active, holding the same ability the HTTP controller
| checks) — never from Auth::user(). Rule 199; extends rule 198 (§31 closed).
|
| Every defect below was reproduced on the unmodified base (a98af8fc):
|   - no session -> list, queue, counts and the branch selector answered
|     EVERY active RME branch (fail-open);
|   - the ambient session, not the intended actor, decided the scope;
|   - a Kasir pinned to branch A read branch B's patient visit history over
|     HTTP (rme.visits.patient-options -> 200);
|   - no session -> "Selesai Pemeriksaan" skipped the clinical actor check;
|   - no session -> create() failed only at the database (NOT NULL);
|   - the RME dashboard summed today's payments and receivables across every
|     branch for a context-bound operator, and ?branch_id= reached any branch.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->a = Branch::factory()->create(['code' => 'ATG3', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->b = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctor = Doctor::factory()->create();

    $this->va = cvbaVisit($this->a, ClinicVisit::STATUS_WAITING);
    $this->vb = cvbaVisit($this->b, ClinicVisit::STATUS_WAITING);

    $this->governance = userInRole('Supervisor RME');

    $this->kasirA = userInRole('Kasir');
    rmeMakeKasirActive($this->kasirA, $this->a);

    $this->adminA = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($this->adminA, $this->a);

    $this->svc = app(ClinicVisitService::class);
});

function cvbaVisit(Branch $branch, string $status, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => $status,
        'visit_date' => clinicalToday()->toDateString(),
    ], $overrides));
}

/** @return array<int, int> */
function cvbaBranches(iterable $rows): array
{
    return collect($rows)->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
}

/** A doctor who is online in the visit's own room, i.e. a legitimate examiner. */
function cvbaExaminingDoctor(ClinicVisit $visit): User
{
    $room = ClinicRoom::factory()->create(['branch_id' => $visit->branch_id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $visit->forceFill(['clinic_room_id' => $room->id])->save();

    return rmeMakeDoctorOnline(test()->doctor, Branch::find($visit->branch_id), $room);
}

/*
|--------------------------------------------------------------------------
| A. Read scope comes from the explicit actor
|--------------------------------------------------------------------------
*/

it('scopes the visit list, queue, counts and selector to the explicit actor, not the session', function () {
    // The ambient session is a governance account that may read every branch.
    $this->actingAs($this->governance);

    expect(cvbaBranches($this->svc->paginate($this->kasirA)->items()))->toBe([$this->a->id])
        ->and(cvbaBranches($this->svc->registeredQueue($this->kasirA)->items()))->toBe([$this->a->id])
        ->and($this->svc->visitsTodayCount($this->kasirA))->toBe(1)
        ->and($this->svc->waitingCount($this->kasirA))->toBe(1)
        ->and($this->svc->selectableRmeBranches($this->kasirA)->pluck('id')->all())->toBe([$this->a->id]);
});

it('gives a governance actor every RME branch with no session at all', function () {
    Auth::logout();

    expect(cvbaBranches($this->svc->paginate($this->governance)->items()))->toBe([$this->a->id, $this->b->id])
        ->and($this->svc->waitingCount($this->governance))->toBe(2);
});

it('refuses a null actor at the boundary instead of answering every branch', function (string $method) {
    Auth::logout();

    expect(fn () => $this->svc->{$method}(null))->toThrow(TypeError::class);
})->with(['paginate', 'roomWorklist', 'registeredQueue', 'visitsTodayCount', 'waitingCount', 'inProgressCount', 'selectableRmeBranches', 'scopedBranchIdsFor']);

it('fails closed for an actor whose account can no longer be used', function (string $case) {
    $actor = $this->governance;

    match ($case) {
        'inactive' => $actor->forceFill(['is_active' => false])->save(),
        'soft-deleted' => $actor->delete(),
        'no visit permission' => $actor->syncRoles([]),
        default => null,
    };

    if ($case === 'unsaved object carrying a governance id') {
        $actor = (new User)->forceFill(['id' => $this->governance->id, 'name' => 'forged']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->svc->paginate($actor)->total())->toBe(0)
        ->and($this->svc->registeredQueue($actor)->total())->toBe(0)
        ->and($this->svc->waitingCount($actor))->toBe(0)
        ->and($this->svc->scopedBranchIdsFor($actor))->toBe([])
        ->and($this->svc->selectableRmeBranches($actor)->all())->toBe([]);
})->with(['inactive', 'soft-deleted', 'no visit permission', 'unsaved object carrying a governance id']);

it('judges the persisted row, not stale roles carried on the object it was given', function () {
    $stale = User::with('roles')->find($this->governance->id);
    expect($stale->hasRole('Supervisor RME'))->toBeTrue();

    User::find($this->governance->id)->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->svc->paginate($stale)->total())->toBe(0);
});

it('fails closed for a context-bound actor whose working context has ended', function () {
    app(UserOnlineContextService::class)->markOffline($this->kasirA);

    expect($this->svc->paginate($this->kasirA)->total())->toBe(0)
        ->and($this->svc->scopedBranchIdsFor($this->kasirA))->toBe([]);
});

it('lets a requested branch filter only narrow, never widen', function () {
    expect(cvbaBranches($this->svc->paginate($this->kasirA, ['branch_id' => $this->b->id])->items()))->toBe([$this->a->id])
        ->and($this->svc->scopedBranchIdsFor($this->kasirA, $this->b->id))->toBe([$this->a->id])
        ->and(cvbaBranches($this->svc->paginate($this->governance, ['branch_id' => $this->b->id])->items()))->toBe([$this->b->id]);
});

/*
|--------------------------------------------------------------------------
| B. Prior-visit picker (rme.visits.patient-options)
|--------------------------------------------------------------------------
*/

it('refuses the prior-visit picker to a role with no registration authority', function () {
    $this->actingAs($this->kasirA)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $this->vb->patient_id]))
        ->assertForbidden();

    $this->actingAs($this->kasirA)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $this->va->patient_id]))
        ->assertForbidden();
});

it('keeps the cross-branch prior-visit picker for a registrar (cross-branch Kontrol is a supported workflow)', function () {
    $this->actingAs($this->adminA)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $this->vb->patient_id]))
        ->assertOk()
        ->assertJsonPath('visits.0.id', $this->vb->id);
});

it('refuses the prior-visit history at the service for a non-registrar or an unusable registrar', function (string $case) {
    $actor = match ($case) {
        'view-only actor' => $this->kasirA,
        'registrar without a working context' => tap($this->adminA, fn (User $u) => app(UserOnlineContextService::class)->markOffline($u)),
        'inactive registrar' => tap($this->governance, fn (User $u) => $u->forceFill(['is_active' => false])->save()),
    };

    expect(fn () => $this->svc->patientVisitOptions($actor, (int) $this->vb->patient_id))
        ->toThrow(AuthorizationException::class);
})->with(['view-only actor', 'registrar without a working context', 'inactive registrar']);

/*
|--------------------------------------------------------------------------
| C. Status transitions
|--------------------------------------------------------------------------
*/

it('refuses "Selesai Pemeriksaan" without an explicit actor even when consent is signed', function () {
    Auth::logout();
    $visit = rmeActiveConsentedEncounter($this->va);

    expect(fn () => $this->svc->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('does not let an authorized session rescue an unauthorized explicit actor', function () {
    $visit = rmeActiveConsentedEncounter($this->va);
    $doctorUser = cvbaExaminingDoctor($visit);

    // The session could finish the examination; the actor the caller names cannot.
    $this->actingAs($doctorUser);

    expect(fn () => $this->svc->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $this->adminA))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('lets the examining doctor finish the examination', function () {
    Auth::logout();
    $visit = rmeActiveConsentedEncounter($this->va);
    $doctorUser = cvbaExaminingDoctor($visit);

    $this->svc->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $doctorUser);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('refuses an examining doctor whose account has been deactivated', function () {
    $visit = rmeActiveConsentedEncounter($this->va);
    $doctorUser = cvbaExaminingDoctor($visit);
    $doctorUser->forceFill(['is_active' => false])->save();

    expect(fn () => $this->svc->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $doctorUser))
        ->toThrow(ValidationException::class);
});

it('refuses every non-cashier transition without an explicit actor', function (string $to) {
    Auth::logout();

    expect(fn () => $this->svc->transitionStatus($this->va->fresh(), $to))->toThrow(ValidationException::class);
    expect($this->va->fresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
})->with([ClinicVisit::STATUS_IN_PROGRESS, ClinicVisit::STATUS_CANCELLED]);

it('refuses a transition by an actor working another branch', function () {
    expect(fn () => $this->svc->transitionStatus($this->vb->fresh(), ClinicVisit::STATUS_CANCELLED, $this->adminA))
        ->toThrow(ValidationException::class);

    expect($this->vb->fresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('keeps the cashier-owned completion actorless (RmePaymentService path)', function () {
    Auth::logout();
    $this->va->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();

    $this->svc->transitionStatus($this->va->fresh(), ClinicVisit::STATUS_COMPLETED);

    expect($this->va->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| D. Visit creation
|--------------------------------------------------------------------------
*/

function cvbaCreatePayload(Branch $branch, ClinicVisit $existing): array
{
    return [
        'patient_mode' => 'existing',
        'patient_id' => $existing->patient_id,
        'branch_id' => $branch->id,
        'doctor_id' => test()->doctor->id,
        'chief_complaint' => 'kontrol',
    ];
}

it('refuses visit creation without an explicit actor at the boundary', function () {
    Auth::logout();

    expect(fn () => $this->svc->create(cvbaCreatePayload($this->a, $this->va), null))->toThrow(TypeError::class);
});

it('attributes a new visit to the explicit actor, not the session', function () {
    $this->actingAs($this->governance);

    $visit = $this->svc->create(cvbaCreatePayload($this->a, $this->va), $this->adminA);

    expect((int) $visit->created_by)->toBe((int) $this->adminA->id)
        ->and((int) $visit->branch_id)->toBe((int) $this->a->id);
});

it('refuses visit creation for an actor without registration authority, writing nothing', function (string $case) {
    $actor = match ($case) {
        'view-only actor' => $this->kasirA,
        'inactive registrar' => tap($this->adminA, fn (User $u) => $u->forceFill(['is_active' => false])->save()),
    };
    $before = ClinicVisit::count();

    expect(fn () => $this->svc->create(cvbaCreatePayload($this->a, $this->va), $actor))
        ->toThrow(AuthorizationException::class);

    expect(ClinicVisit::count())->toBe($before);
})->with(['view-only actor', 'inactive registrar']);

it('refuses a registrar creating a visit at a branch it is not working in', function () {
    $before = ClinicVisit::count();

    expect(fn () => $this->svc->create(cvbaCreatePayload($this->b, $this->vb), $this->adminA))
        ->toThrow(AuthorizationException::class);

    expect(ClinicVisit::count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| E. Room assignment and visit update
|--------------------------------------------------------------------------
*/

it('attributes the auto doctor assignment to the explicit actor, not the session', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->a->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline($this->doctor, $this->a, $room);

    $this->actingAs($this->governance);
    $this->svc->assignRoom($this->va->fresh(), (int) $room->id, $this->adminA);

    $assignment = PatientDoctorAssignment::query()->where('patient_id', $this->va->patient_id)->firstOrFail();

    expect((int) $assignment->assigned_by)->toBe((int) $this->adminA->id);
});

it('refuses room assignment and visit edits by an actor working another branch', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->b->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    // ClinicVisitFactory gives every visit a room by default (Sprint 60.8), so
    // the property is "unchanged", not "null".
    $roomBefore = $this->vb->fresh()->clinic_room_id;

    expect(fn () => $this->svc->assignRoom($this->vb->fresh(), (int) $room->id, $this->adminA))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->svc->update($this->vb->fresh(), ['chief_complaint' => 'diubah'], $this->adminA))
        ->toThrow(AuthorizationException::class);

    expect($this->vb->fresh()->clinic_room_id)->toBe($roomBefore)
        ->and($this->vb->fresh()->clinic_room_id)->not->toBe($room->id)
        ->and($this->vb->fresh()->chief_complaint)->not->toBe('diubah');
});

it('ignores a crafted room or doctor on the visit edit, which only assignRoom may write', function () {
    // Found by the independent security review. UpdateClinicVisitRequest
    // accepted clinic_room_id and doctor_id checked only for EXISTENCE, while the
    // edit form sends neither: a crafted PATCH by an operator allowed to edit a
    // branch-A visit could set a branch-B room (bypassing assignRoom's
    // same-branch, active-room and terminal-visit rules and the "doctor comes
    // from the room" rule) or any doctor (which DoctorPatientScopeService then
    // treats as a treating relationship, widening that doctor's patient access).
    $roomB = ClinicRoom::factory()->create(['branch_id' => $this->b->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $otherDoctor = Doctor::factory()->create();
    $before = $this->va->fresh();

    $this->actingAs($this->adminA)
        ->put(route('rme.visits.update', $this->va), [
            'chief_complaint' => 'gigi ngilu',
            'clinic_room_id' => $roomB->id,
            'doctor_id' => $otherDoctor->id,
        ])
        ->assertRedirect(route('rme.visits.show', $this->va));

    $after = $this->va->fresh();
    expect($after->chief_complaint)->toBe('gigi ngilu')
        ->and($after->clinic_room_id)->toBe($before->clinic_room_id)
        ->and($after->doctor_id)->toBe($before->doctor_id);

    // A non-HTTP caller cannot use the service to do it either.
    $this->svc->update($after, ['clinic_room_id' => $roomB->id, 'doctor_id' => $otherDoctor->id], $this->adminA);

    expect($this->va->fresh()->clinic_room_id)->toBe($before->clinic_room_id)
        ->and($this->va->fresh()->doctor_id)->toBe($before->doctor_id);
});

/*
|--------------------------------------------------------------------------
| F. Callers that aggregate visit-domain data
|--------------------------------------------------------------------------
*/

it('keeps the RME dashboard inside a context-bound operator\'s branch, even with a crafted branch filter', function () {
    foreach ([[$this->va, 100000], [$this->vb, 900000]] as [$visit, $amount]) {
        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $visit->branch_id, 'clinic_visit_id' => $visit->id, 'patient_id' => $visit->patient_id,
        ]);
        RmePayment::factory()->create([
            'branch_id' => $visit->branch_id, 'rme_invoice_id' => $invoice->id,
            'clinic_visit_id' => $visit->id, 'patient_id' => $visit->patient_id,
            'amount' => $amount, 'paid_at' => now(),
        ]);
    }
    cvbaVisit($this->b, ClinicVisit::STATUS_CASHIER_PENDING);

    foreach ([[], ['branch_id' => $this->b->id]] as $query) {
        $metrics = $this->actingAs($this->kasirA)
            ->get(route('rme.dashboard', $query))
            ->assertOk()
            ->viewData('metrics');

        expect((float) $metrics['payments_today_total'])->toBe(100000.0)
            ->and($metrics['cashier_pending'])->toBe(0)
            ->and($metrics['visits_today'])->toBe(1);
    }
});

it('keeps the RME dashboard estate-wide for a governance actor', function () {
    $metrics = $this->actingAs($this->governance)->get(route('rme.dashboard'))->assertOk()->viewData('metrics');

    expect($metrics['visits_today'])->toBe(2);
});

it('counts draft medical records only inside the actor\'s branch on the visit list and dashboard', function () {
    MedicalRecord::factory()->create(['clinic_visit_id' => $this->va->id, 'branch_id' => $this->a->id, 'patient_id' => $this->va->patient_id, 'doctor_id' => $this->doctor->id]);
    MedicalRecord::factory()->create(['clinic_visit_id' => $this->vb->id, 'branch_id' => $this->b->id, 'patient_id' => $this->vb->patient_id, 'doctor_id' => $this->doctor->id]);

    $widgets = $this->actingAs($this->kasirA)->get(route('rme.visits.index'))->assertOk()->viewData('rmeWidgets');
    $metrics = $this->actingAs($this->kasirA)->get(route('rme.dashboard'))->assertOk()->viewData('metrics');

    expect($widgets['draft_medical_records'])->toBe(1)
        ->and($metrics['medical_records_draft'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| G. Governance — no ambient authority in the service
|--------------------------------------------------------------------------
*/

it('contains no ambient authentication or branch context in ClinicVisitService', function () {
    $source = file_get_contents(app_path('Modules/ClinicVisit/Services/ClinicVisitService.php'));
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    foreach (['Auth::', 'auth()', "app('auth')", 'request()', 'Request::', 'session()', 'BranchContext'] as $ambient) {
        expect(str_contains($code, $ambient))->toBeFalse("ClinicVisitService must not read [{$ambient}]");
    }
});
