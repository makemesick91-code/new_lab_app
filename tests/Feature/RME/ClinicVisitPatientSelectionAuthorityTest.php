<?php

/*
|--------------------------------------------------------------------------
| HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — patient selection authority
| and owner decision OD-1 (cross-branch / cross-doctor Kontrol)
|--------------------------------------------------------------------------
|
| Rule 199 §27 recorded the residual: ClinicVisitService::create() did not
| re-assert patient selectability; the FormRequest was the only boundary.
| Reproduced on the unmodified base (b1e6d3c6) by calling the service directly:
|
|   - a nonexistent, soft-deleted or out-of-registry patient id reached the
|     database (or created a visit);
|   - follow_up_of_visit_id was never checked at all — another patient's visit,
|     a deleted visit, a non-RME-branch visit or a "new" visit carrying a prior
|     visit were all written;
|   - doctor_id was written unchecked (any doctor, online or not), which widens
|     that doctor's patient scope — the F9 class of rule 199 at creation time;
|   - clinic_room_id was accepted at REGISTRATION with an existence check only,
|     over HTTP as well, so a crafted POST placed a new visit straight into a
|     room of another branch, bypassing every rule assignRoom() enforces.
|
| OD-1 (owner, 2026-10-22): "pasien bisa kontrol lintas cabang bahkan dengan
| dokter berbeda". The prior-visit link therefore checks PATIENT identity only:
| it never compares the prior visit's branch or doctor with the new visit's.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\ClinicVisit\Services\ClinicVisitRegistrationGuard;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->a = Branch::factory()->create(['code' => 'ATG3', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->b = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->nonRme = Branch::factory()->create(['code' => 'NRM9', 'is_active' => true, 'is_rme_enabled' => false]);

    $this->doctorA = Doctor::factory()->withAllowedBranches([$this->a])->create(['is_active' => true]);
    $this->doctorB = Doctor::factory()->withAllowedBranches([$this->b])->create(['is_active' => true]);

    $this->treatment = Treatment::factory()->create(['is_active' => true]);

    // The patient's history lives at branch A, treated by doctor A.
    $this->patient = Patient::factory()->create(['branch_id' => $this->a->id]);
    $this->parent = ClinicVisit::factory()->create([
        'branch_id' => $this->a->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctorA->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => clinicalToday()->subDays(14)->toDateString(),
    ]);

    $this->adminB = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($this->adminB, $this->b);

    $this->governance = userInRole('Supervisor RME');

    $this->svc = app(ClinicVisitService::class);
});

/** @param array<string, mixed> $overrides */
function cpsaPayload(Branch $branch, ?Patient $patient, array $overrides = []): array
{
    return array_merge([
        'patient_mode' => 'existing',
        'patient_id' => $patient?->id,
        'branch_id' => $branch->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'initial_treatment_id' => test()->treatment->id,
        'chief_complaint' => 'kontrol pasca tindakan',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function cpsaControl(Branch $branch, ClinicVisit $parent, array $overrides = []): array
{
    return cpsaPayload($branch, Patient::find($parent->patient_id), array_merge([
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
    ], $overrides));
}

function cpsaRefused(callable $attempt, string $field): void
{
    $before = ClinicVisit::query()->withTrashed()->count();

    try {
        $attempt();
        test()->fail('Expected the registration to be refused on '.$field);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain($field);
    }

    expect(ClinicVisit::query()->withTrashed()->count())->toBe($before);
}

/** Everything about a row that a later visit must never change. */
function cpsaSnapshot(string $table, int $id): array
{
    return (array) DB::table($table)->where('id', $id)->first();
}

/*
|--------------------------------------------------------------------------
| A. The selected patient
|--------------------------------------------------------------------------
*/

it('registers a visit for a patient from another branch at the registrar\'s own branch (global identity)', function () {
    $visit = $this->svc->create(cpsaPayload($this->b, $this->patient), $this->adminB);

    expect((int) $visit->branch_id)->toBe((int) $this->b->id)
        ->and((int) $visit->patient_id)->toBe((int) $this->patient->id)
        ->and(Patient::query()->count())->toBe(1);
});

it('refuses an existing-patient registration that names no patient', function () {
    cpsaRefused(fn () => $this->svc->create(cpsaPayload($this->b, null), $this->adminB), 'patient_id');
});

it('refuses a patient id that does not exist', function (int $id) {
    cpsaRefused(fn () => $this->svc->create(cpsaPayload($this->b, null, ['patient_id' => $id]), $this->adminB), 'patient_id');
})->with([999999, 0, -5]);

it('refuses a soft-deleted patient', function () {
    $this->patient->delete();

    cpsaRefused(fn () => $this->svc->create(cpsaPayload($this->b, $this->patient), $this->adminB), 'patient_id');
});

it('refuses a patient outside the RME registry', function () {
    $outside = Patient::factory()->create(['branch_id' => $this->nonRme->id]);

    cpsaRefused(fn () => $this->svc->create(cpsaPayload($this->b, $outside), $this->adminB), 'patient_id');
});

it('refuses to let a context-bound registrar with no working branch select anybody', function () {
    $offShift = userInRole('Admin Klinik');

    expect(fn () => $this->svc->create(cpsaPayload($this->b, $this->patient), $offShift))
        ->toThrow(AuthorizationException::class);

    expect(ClinicVisit::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| B. The prior visit (OD-1: patient identity only)
|--------------------------------------------------------------------------
*/

it('accepts a Kontrol at branch B that follows a visit at branch A (OD-1 cross-branch)', function () {
    $before = cpsaSnapshot('trx_clinic_visits', (int) $this->parent->id);

    $visit = $this->svc->create(cpsaControl($this->b, $this->parent), $this->adminB);

    expect((int) $visit->branch_id)->toBe((int) $this->b->id)
        ->and((int) $visit->follow_up_of_visit_id)->toBe((int) $this->parent->id)
        ->and($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL)
        // The historical visit is not touched in any column.
        ->and(cpsaSnapshot('trx_clinic_visits', (int) $this->parent->id))->toBe($before);
});

it('refuses a prior visit that belongs to another patient', function () {
    $stranger = ClinicVisit::factory()->create([
        'branch_id' => $this->b->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $this->b->id])->id,
        'doctor_id' => $this->doctorB->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent, ['follow_up_of_visit_id' => $stranger->id]), $this->adminB), 'follow_up_of_visit_id');
});

it('refuses a prior visit id that does not exist', function () {
    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent, ['follow_up_of_visit_id' => 987654]), $this->adminB), 'follow_up_of_visit_id');
});

it('refuses a soft-deleted prior visit', function () {
    $this->parent->delete();

    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent), $this->adminB), 'follow_up_of_visit_id');
});

it('refuses a prior visit held at a non-RME branch', function () {
    $this->parent->forceFill(['branch_id' => $this->nonRme->id])->save();

    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent), $this->adminB), 'follow_up_of_visit_id');
});

it('refuses a follow-up visit type that names no prior visit', function (string $type) {
    cpsaRefused(fn () => $this->svc->create(cpsaPayload($this->b, $this->patient, ['visit_type' => $type]), $this->adminB), 'follow_up_of_visit_id');
})->with(ClinicVisit::FOLLOW_UP_VISIT_TYPES);

it('refuses a prior visit attached to a visit type that is not a follow-up', function (string $type) {
    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent, ['visit_type' => $type]), $this->adminB), 'visit_type');
})->with([ClinicVisit::VISIT_TYPE_NEW, ClinicVisit::VISIT_TYPE_EMERGENCY]);

it('refuses a follow-up for a brand-new patient', function () {
    cpsaRefused(fn () => $this->svc->create([
        'patient_mode' => 'new',
        'branch_id' => $this->b->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $this->parent->id,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => ['name' => 'Pasien Baru', 'branch_id' => $this->b->id, 'manual_rm_number' => '991'],
    ], $this->adminB), 'visit_type');

    expect(Patient::query()->count())->toBe(1);
});

it('refuses a new-patient registration whose new-patient data is not an array, instead of treating it as an existing patient', function () {
    // Security review (LOW): selectability is skipped for patient_mode=new, so
    // the service must not fall through to an existing patient_id when the
    // new-patient payload cannot create one (e.g. an ArrayAccess object).
    $stranger = Patient::factory()->create(['branch_id' => $this->a->id]);

    cpsaRefused(fn () => $this->svc->create([
        'patient_mode' => 'new',
        'patient_id' => $stranger->id,
        'branch_id' => $this->b->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => collect(['name' => 'Pasien Baru', 'branch_id' => $this->b->id, 'manual_rm_number' => '992']),
    ], $this->adminB), 'new_patient');

    expect(ClinicVisit::query()->where('patient_id', $stranger->id)->exists())->toBeFalse();
});

it('registers a new patient\'s visit on the patient it creates, never on a patient_id smuggled alongside', function () {
    $stranger = Patient::factory()->create(['branch_id' => $this->a->id]);

    $visit = $this->svc->create([
        'patient_mode' => 'new',
        'patient_id' => $stranger->id,
        'branch_id' => $this->b->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => ['name' => 'Pasien Baru', 'branch_id' => $this->b->id, 'manual_rm_number' => '993'],
    ], $this->adminB);

    expect((int) $visit->patient_id)->not->toBe((int) $stranger->id)
        ->and(Patient::query()->whereKey($visit->patient_id)->value('name'))->toBe('Pasien Baru');
});

/*
|--------------------------------------------------------------------------
| C. The attending doctor (OD-1 cross-doctor)
|--------------------------------------------------------------------------
*/

it('accepts a Kontrol with a different, authorized doctor and never rewrites the previous doctor (OD-1 cross-doctor)', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->b->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline($this->doctorB, $this->b, $room);

    $visit = $this->svc->create(cpsaControl($this->b, $this->parent, ['doctor_id' => $this->doctorB->id]), $this->governance);

    expect((int) $visit->doctor_id)->toBe((int) $this->doctorB->id)
        ->and((int) $this->parent->fresh()->doctor_id)->toBe((int) $this->doctorA->id)
        ->and((int) $this->parent->fresh()->branch_id)->toBe((int) $this->a->id);
});

it('refuses a doctor who is not online at the visit branch', function () {
    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent, ['doctor_id' => $this->doctorB->id]), $this->governance), 'doctor_id');
});

it('refuses the previous doctor when that doctor is working another branch', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->a->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline($this->doctorA, $this->a, $room);

    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent, ['doctor_id' => $this->doctorA->id]), $this->governance), 'doctor_id');
});

it('refuses a non-front-desk registration that names no doctor', function () {
    cpsaRefused(fn () => $this->svc->create(cpsaControl($this->b, $this->parent), $this->governance), 'doctor_id');
});

it('never carries a doctor through a front-desk registration, not even the previous one', function () {
    $visit = $this->svc->create(cpsaControl($this->b, $this->parent, ['doctor_id' => $this->doctorA->id]), $this->adminB);

    // The doctor comes from the room assignment, never from registration.
    expect($visit->doctor_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| D. Registration never writes a room (F9 at creation time)
|--------------------------------------------------------------------------
*/

it('never writes a room at registration through a direct service call', function () {
    $roomA = ClinicRoom::factory()->create(['branch_id' => $this->a->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $visit = $this->svc->create(cpsaPayload($this->b, $this->patient, ['clinic_room_id' => $roomA->id]), $this->adminB);

    expect($visit->clinic_room_id)->toBeNull();
});

it('never writes a room at registration through a crafted POST', function () {
    $roomA = ClinicRoom::factory()->create(['branch_id' => $this->a->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $this->actingAs($this->adminB)
        ->post(route('rme.visits.store'), cpsaPayload($this->b, $this->patient, ['clinic_room_id' => $roomA->id]))
        ->assertRedirect();

    $visit = ClinicVisit::query()->latest('id')->firstOrFail();

    expect((int) $visit->branch_id)->toBe((int) $this->b->id)
        ->and($visit->clinic_room_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| E. HTTP and service agree (one rule source)
|--------------------------------------------------------------------------
*/

it('accepts a cross-branch Kontrol through the real registration form', function () {
    $this->actingAs($this->adminB)
        ->post(route('rme.visits.store'), cpsaControl($this->b, $this->parent))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $visit = ClinicVisit::query()->latest('id')->firstOrFail();

    expect((int) $visit->branch_id)->toBe((int) $this->b->id)
        ->and((int) $visit->follow_up_of_visit_id)->toBe((int) $this->parent->id)
        ->and($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL);
});

it('refuses the same crafted prior visit over HTTP and at the service, on the same field', function (string $case) {
    $stranger = ClinicVisit::factory()->create([
        'branch_id' => $this->b->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $this->b->id])->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    $followUp = match ($case) {
        'another patient' => $stranger->id,
        'nonexistent' => 987654,
        'deleted' => tap(ClinicVisit::factory()->create([
            'branch_id' => $this->b->id,
            'patient_id' => $this->patient->id,
            'status' => ClinicVisit::STATUS_COMPLETED,
        ]), fn (ClinicVisit $v) => $v->delete())->id,
    };

    $payload = cpsaControl($this->b, $this->parent, ['follow_up_of_visit_id' => $followUp]);
    $before = ClinicVisit::query()->withTrashed()->count();

    $this->actingAs($this->adminB)
        ->post(route('rme.visits.store'), $payload)
        ->assertSessionHasErrors('follow_up_of_visit_id');

    cpsaRefused(fn () => $this->svc->create($payload, $this->adminB), 'follow_up_of_visit_id');

    expect(ClinicVisit::query()->withTrashed()->count())->toBe($before);
})->with(['another patient', 'nonexistent', 'deleted']);

it('keeps the prior-visit rule in one place for both the form and the service', function () {
    $request = file_get_contents((new ReflectionClass(StoreClinicVisitRequest::class))->getFileName());
    $service = file_get_contents((new ReflectionClass(ClinicVisitService::class))->getFileName());

    expect($request)->toContain('ClinicVisitRegistrationGuard')
        ->and($service)->toContain('ClinicVisitRegistrationGuard')
        // The parent lookup lives in the guard, not restated in either caller.
        ->and($request)->not->toContain('->find((int) $followUpId)');

    // OD-1: the guard never compares the prior visit's branch or doctor.
    $guard = file_get_contents((new ReflectionClass(ClinicVisitRegistrationGuard::class))->getFileName());
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $guard);

    // The guard names the prior visit `$prior`; the scan covers both spellings
    // (an earlier draft only matched `$parent` and so could never fail).
    expect($code)->toContain('$prior->patient_id')
        ->and($code)->not->toMatch('/\$(prior|parent)\w*->(branch_id|doctor_id)/');
});

it('refuses a bad prior visit in the form itself, before the visit service is ever reached', function () {
    $stranger = ClinicVisit::factory()->create([
        'branch_id' => $this->b->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $this->b->id])->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    // The service re-asserts the same rule, so the HTTP response alone cannot
    // tell the two barriers apart: the form must stop it first, and the
    // service must not be called at all.
    $this->mock(ClinicVisitService::class, fn ($mock) => $mock->shouldNotReceive('create'));

    $this->actingAs($this->adminB)
        ->post(route('rme.visits.store'), cpsaControl($this->b, $this->parent, ['follow_up_of_visit_id' => $stranger->id]))
        ->assertSessionHasErrors('follow_up_of_visit_id');
});

it('does not treat a room as a registration field at all, so a malformed room is ignored rather than validated', function () {
    $this->actingAs($this->adminB)
        ->post(route('rme.visits.store'), cpsaPayload($this->b, $this->patient, ['clinic_room_id' => 'not-a-room']))
        ->assertSessionDoesntHaveErrors('clinic_room_id')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ClinicVisit::query()->latest('id')->firstOrFail()->clinic_room_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| F. The prior-visit picker and historical clinical records
|--------------------------------------------------------------------------
*/

it('shows a registrar the patient\'s history at every RME branch with only the fields a choice needs', function () {
    $options = $this->svc->patientVisitOptions($this->adminB, (int) $this->patient->id);

    expect($options)->toHaveCount(1)
        ->and(array_keys($options[0]))->toEqualCanonicalizing([
            'id', 'visit_number', 'visit_date', 'visit_type', 'visit_type_label', 'doctor_name', 'initial_treatment', 'status',
        ]);
});

it('leaves the previous visit\'s medical record, consent and odontogram untouched by a Kontrol elsewhere', function () {
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $this->parent->id,
        'branch_id' => $this->a->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctorA->id,
    ]);
    rmeSignedConsentFor($this->parent);

    $recordBefore = cpsaSnapshot('trx_medical_records', (int) $record->id);
    $consentBefore = DB::table('trx_rme_visit_consents')->where('clinic_visit_id', $this->parent->id)->get()->map(fn ($r) => (array) $r)->all();
    $odontogramBefore = DB::table('trx_odontograms')->where('clinic_visit_id', $this->parent->id)->count();

    $this->svc->create(cpsaControl($this->b, $this->parent), $this->adminB);

    expect(cpsaSnapshot('trx_medical_records', (int) $record->id))->toBe($recordBefore)
        ->and(DB::table('trx_rme_visit_consents')->where('clinic_visit_id', $this->parent->id)->get()->map(fn ($r) => (array) $r)->all())->toBe($consentBefore)
        ->and(DB::table('trx_odontograms')->where('clinic_visit_id', $this->parent->id)->count())->toBe($odontogramBefore)
        // The new visit carries no medical record of its own until it is examined.
        ->and(MedicalRecord::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| G. The edit path cannot re-do the selection
|--------------------------------------------------------------------------
*/

it('never lets the visit edit re-point the patient, the prior visit, the branch or the type', function () {
    $visit = $this->svc->create(cpsaPayload($this->b, $this->patient), $this->adminB);
    $other = Patient::factory()->create(['branch_id' => $this->b->id]);
    $otherVisit = ClinicVisit::factory()->create(['branch_id' => $this->b->id, 'patient_id' => $other->id]);

    $this->svc->update($visit, [
        'patient_id' => $other->id,
        'follow_up_of_visit_id' => $otherVisit->id,
        'branch_id' => $this->a->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'visit_number' => 'VIS-FORGED-1',
        'created_by' => $this->governance->id,
        'chief_complaint' => 'diperbarui',
    ], $this->adminB);

    $fresh = $visit->fresh();

    expect((int) $fresh->patient_id)->toBe((int) $this->patient->id)
        ->and($fresh->follow_up_of_visit_id)->toBeNull()
        ->and((int) $fresh->branch_id)->toBe((int) $this->b->id)
        ->and($fresh->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and($fresh->visit_number)->toBe($visit->visit_number)
        ->and((int) $fresh->created_by)->toBe((int) $this->adminB->id)
        ->and($fresh->chief_complaint)->toBe('diperbarui');
});
