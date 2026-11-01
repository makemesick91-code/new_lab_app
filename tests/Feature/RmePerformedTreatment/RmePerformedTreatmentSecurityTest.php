<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — who may author the
| doctor's structured record (DPT-063…076). Three layers: route middleware, the
| ClinicVisitPolicy ability, and the service re-assertion (treating linked doctor
| under the visit row lock) that binds Super Admin too.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\PerformedTreatment\Interfaces\RmePerformedTreatmentRepositoryInterface;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentAuditEvent;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

/** @return array<string, mixed> */
function dptPayload(Treatment $treatment, array $extra = []): array
{
    return $extra + [
        'treatment_id' => $treatment->id,
        'quantity' => 1,
        'submission_token' => (string) Str::uuid(),
    ];
}

it('DPT-063 refuses the cashier at every layer', function () {
    $visit = dptEncounter();
    rmeMakeKasirActive($this->cashier, $this->branch);

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.performed-treatments.store', $visit), dptPayload($this->scaling))
        ->assertForbidden();

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $this->cashier)))->toContain(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-064 refuses Admin Klinik — the front office never impersonates the doctor', function () {
    $visit = dptEncounter();
    $admin = User::factory()->create();
    $admin->assignRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->branch);

    $this->actingAs($admin)
        ->post(route('rme.visits.performed-treatments.declaration', $visit), ['declaration_type' => 'no_treatment', 'confirm_no_treatment' => '1'])
        ->assertForbidden();

    expect(RmeTreatmentDeclaration::query()->count())->toBe(0);
});

it('DPT-065 refuses a doctor working at another branch', function () {
    $visit = dptEncounter();
    $other = Branch::factory()->create(['code' => 'DP02', 'is_rme_enabled' => true, 'is_active' => true]);
    $doctor = Doctor::factory()->create();
    $user = rmeMakeDoctorOnline($doctor, $other);

    $this->actingAs($user)
        ->post(route('rme.visits.performed-treatments.store', $visit), dptPayload($this->scaling))
        ->assertStatus(403);

    expect(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-066 refuses the treating doctor when the active visit sits in another room', function () {
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $visit = dptEncounter(['clinic_room_id' => $otherRoom->id]);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.store', $visit), dptPayload($this->scaling))
        ->assertForbidden();

    expect(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-067 refuses to void a row of another visit through this visit\'s URL', function () {
    $first = dptEncounter();
    $row = dptRecord($first, $this->scaling);
    $second = dptEncounter();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.void', [$second, $row]), ['void_reason' => 'Bukan kunjungan ini.'])
        ->assertNotFound();

    expect($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('DPT-068 refuses an inactive doctor record and a deactivated account', function () {
    $visit = dptEncounter();
    $this->doctor->update(['is_active' => false]);

    // Whichever layer sees it first refuses: the clinical scope (an inactive doctor
    // has no patient access) or the treating-doctor identity rule.
    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling)))->toMatch('/DPT_(NOT_TREATING_DOCTOR|ACTOR_NOT_AUTHORIZED)/');

    $this->doctor->update(['is_active' => true]);
    $this->doctorUser->forceFill(['is_active' => false])->save();

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $this->doctorUser->fresh())))->toContain(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-069 binds Super Admin too — Gate::before never makes it the treating doctor', function () {
    $visit = dptEncounter();
    $super = User::factory()->create();
    $super->assignRole('Super Admin');

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $super)))->toContain(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR)
        ->and(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT, $super)))->toContain(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR)
        ->and(RmePerformedTreatment::query()->count() + RmeTreatmentDeclaration::query()->count())->toBe(0);
});

it('refuses a nurse who may complete the examination but is not the treating doctor', function () {
    $visit = dptEncounter();
    $nurse = User::factory()->create();
    $nurse->assignRole('Perawat');
    rmeMakePerawatActive($nurse, $this->branch);

    expect($nurse->can('complete_rme_examination'))->toBeTrue()
        ->and(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $nurse)))->not->toBeNull()
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-070 gives the doctor no cashier or payment permission', function () {
    expect($this->doctorUser->can('manage_rme_billing'))->toBeFalse()
        ->and(Permission::query()->where('name', 'like', '%performed%')->count())->toBe(0);
});

it('DPT-071 keeps free text and identity out of the audit payload', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->filling, ['tooth_numbers' => ['16']]);
    dptVoid($visit, $row, 'Pasien Rahasia Koreksi menolak tindakan.');

    $payloads = AuditLog::query()->whereIn('action', [
        RmePerformedTreatmentAuditEvent::RECORDED, RmePerformedTreatmentAuditEvent::VOIDED,
    ])->pluck('new_values')->map(fn ($v) => json_encode($v))->implode(' ');

    expect($payloads)->not->toContain('Pasien Rahasia Koreksi')
        ->and($payloads)->not->toContain('7371010101900001')
        ->and($payloads)->toContain('void_reason_length');
});

it('DPT-072 leaves the trusted-device boundary untouched', function () {
    $sources = collect(glob(app_path('Modules/PerformedTreatment/**/*.php')))
        ->merge(glob(app_path('Modules/PerformedTreatment/*/*.php')))
        ->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect($sources)->not->toContain('DoctorDevice')
        ->and($sources)->not->toContain('trusted_device_enforcement');
});

it('DPT-073 audits every clinical mutation exactly once', function () {
    $visit = dptEncounter();

    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);
    $row = dptRecord($visit, $this->scaling);
    dptVoid($visit, $row);

    expect(dptAudits(RmePerformedTreatmentAuditEvent::DECLARED))->toBe(1)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::RECORDED))->toBe(1)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::VOIDED))->toBe(1);
});

it('DPT-074 cannot rewrite clinical evidence — a voided row cannot be voided again, and no update path exists', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    dptVoid($visit, $row, 'Alasan pertama.');

    expect(dptRefusal(fn () => dptVoid($visit, $row, 'Alasan kedua.')))->toContain(RmePerformedTreatmentRefusal::ROW_NOT_ACTIVE)
        ->and($row->fresh()->void_reason)->toBe('Alasan pertama.');

    $interface = new ReflectionClass(RmePerformedTreatmentRepositoryInterface::class);
    $names = collect($interface->getMethods())->map->getName();
    expect($names->filter(fn ($n) => str_starts_with($n, 'update') || str_starts_with($n, 'delete'))->all())->toBe([]);
});

it('DPT-075 refuses a treatment the doctor may not record', function () {
    $visit = dptEncounter();
    $inactive = Treatment::factory()->create(['is_active' => false]);
    $deleted = Treatment::factory()->create();
    $deleted->delete();

    foreach ([$inactive, $deleted, $this->voucher, $this->consultation, $this->controlService] as $treatment) {
        expect(dptRefusal(fn () => dptRecord($visit, $treatment)))->toContain(RmePerformedTreatmentRefusal::TREATMENT_NOT_SELECTABLE);
    }

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, ['treatment_id' => 99999999])))->toContain(RmePerformedTreatmentRefusal::TREATMENT_NOT_SELECTABLE)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('records a lab treatment and snapshots requires_lab', function () {
    $visit = dptEncounter();

    $row = dptRecord($visit, $this->labTreatment, ['tooth_numbers' => ['11']]);

    expect((bool) $row->requires_lab_snapshot)->toBeTrue();
});

it('DPT-076 keeps branch isolation with the flag ON — another branch\'s doctor cannot open the workspace', function () {
    $visit = dptEncounter();
    $other = Branch::factory()->create(['code' => 'DP03', 'is_rme_enabled' => true, 'is_active' => true]);
    $user = rmeMakeDoctorOnline(Doctor::factory()->create(), $other);

    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertStatus(403);
});

it('answers 404 on every write route while the flag is OFF', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    dptFlag(false);

    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.store', $visit), dptPayload($this->filling))->assertNotFound();
    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.void', [$visit, $row]), ['void_reason' => 'Uji.'])->assertNotFound();
    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.declaration', $visit), ['declaration_type' => 'has_treatment'])->assertNotFound();
});

it('records over HTTP for the treating doctor and never trusts a submitted visit, patient or branch', function () {
    $visit = dptEncounter();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.store', $visit), dptPayload($this->filling, [
            'tooth_numbers' => ['16'], 'clinic_visit_id' => 1, 'patient_id' => 1, 'branch_id' => 1, 'status' => 'voided',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row = RmePerformedTreatment::query()->sole();
    expect((int) $row->clinic_visit_id)->toBe((int) $visit->id)->and($row->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('requires an explicit confirmation before declaring no treatment over HTTP', function () {
    $visit = dptEncounter();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.declaration', $visit), ['declaration_type' => 'no_treatment'])
        ->assertSessionHasErrors('confirm_no_treatment');

    expect(RmeTreatmentDeclaration::query()->count())->toBe(0);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.declaration', $visit), ['declaration_type' => 'no_treatment', 'confirm_no_treatment' => '1'])
        ->assertSessionHasNoErrors();

    expect(RmeTreatmentDeclaration::query()->sole()->operational_visit_category)->toBe('control');
});

it('requires a void reason over HTTP', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.void', [$visit, $row]), ['void_reason' => ''])
        ->assertSessionHasErrors('void_reason');

    expect($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

// ─── Independent security review, 2026-11-01 ─────────────────────────────────

it('DPT-091 refuses a reused submission token whose payload differs — a stale form never silently drops a treatment', function (string $field) {
    $visit = dptEncounter();
    $token = (string) Str::uuid();
    $first = dptRecord($visit, $this->scaling, ['submission_token' => $token]);

    $changed = match ($field) {
        'treatment' => fn () => dptRecord($visit, $this->filling, ['submission_token' => $token]),
        'quantity' => fn () => dptRecord($visit, $this->scaling, ['submission_token' => $token, 'quantity' => 2]),
        'teeth' => fn () => dptRecord($visit, $this->scaling, ['submission_token' => $token, 'tooth_numbers' => ['11']]),
    };

    expect(dptRefusal($changed))->toContain(RmePerformedTreatmentRefusal::SUBMISSION_REUSED)
        ->and(RmePerformedTreatment::query()->count())->toBe(1)
        ->and(RmePerformedTreatment::query()->value('id'))->toBe($first->id)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::RECORDED))->toBe(1);
})->with(['treatment', 'quantity', 'teeth']);

it('DPT-092 answers the same 403 to an unauthorized actor whether or not the row belongs to the visit', function () {
    $visit = dptEncounter();
    $own = dptRecord($visit, $this->scaling);
    $foreignVisit = dptEncounter();
    $foreign = dptRecord($foreignVisit, $this->filling);

    $other = Branch::factory()->create(['code' => 'DP03', 'is_rme_enabled' => true, 'is_active' => true]);
    $outsider = rmeMakeDoctorOnline(Doctor::factory()->create(), $other);

    foreach ([$own, $foreign] as $row) {
        $this->actingAs($outsider)
            ->post(route('rme.visits.performed-treatments.void', [$visit, $row]), ['void_reason' => 'Uji batas akses.'])
            ->assertForbidden();
    }

    expect($own->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED)
        ->and($foreign->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('DPT-093 refuses a write to a historical visit while the patient has another live encounter', function () {
    $historical = dptEncounter();
    dptEncounter(); // opens the patient's next encounter and completes the first

    expect($historical->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(dptRefusal(fn () => dptRecord($historical, $this->scaling)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptRefusal(fn () => dptDeclare($historical, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(RmePerformedTreatment::query()->count())->toBe(0)
        ->and(RmeTreatmentDeclaration::query()->count())->toBe(0);
});

it('DPT-097 requires the Doctor role — an account linked to the visit\'s doctor record without it is refused', function () {
    $nurse = User::factory()->create();
    $nurse->assignRole('Perawat');
    rmeMakePerawatActive($nurse, $this->branch);
    $linked = Doctor::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $nurse->id, 'is_active' => true]);
    $visit = dptEncounter(['doctor_id' => $linked->id]);

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $nurse)))->toContain(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('DPT-095 the policy itself denies an examiner of another working branch (defence in depth beside the service)', function () {
    $visit = dptEncounter();
    $other = Branch::factory()->create(['code' => 'DP04', 'is_rme_enabled' => true, 'is_active' => true]);
    $nurse = User::factory()->create();
    $nurse->assignRole('Perawat');
    rmeMakePerawatActive($nurse, $other);

    $sameBranchNurse = User::factory()->create();
    $sameBranchNurse->assignRole('Perawat');
    rmeMakePerawatActive($sameBranchNurse, $this->branch);

    expect($nurse->can('complete_rme_examination'))->toBeTrue()
        ->and(Gate::forUser($nurse)->allows('recordPerformedTreatments', $visit))->toBeFalse()
        // control: the same role at the visit's branch passes the POLICY (and is
        // then refused by the service as not the treating doctor — DPT-097)
        ->and(Gate::forUser($sameBranchNurse)->allows('recordPerformedTreatments', $visit))->toBeTrue();
});

it('DPT-098 refuses an inactive doctor record even for an account exempt from the clinical doctor scope', function () {
    // Super Admin + Doctor is exempt from DoctorPatientScopeService (governance
    // account), so the treating-doctor identity rule is the ONLY barrier left for
    // an inactive doctor record (mutation M04).
    $account = User::factory()->create();
    $account->assignRole(['Super Admin', 'Doctor']);
    $inactive = Doctor::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $account->id, 'is_active' => false]);
    $visit = dptEncounter(['doctor_id' => $inactive->id]);

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $account)))->toContain(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR)
        ->and(RmePerformedTreatment::query()->count())->toBe(0);

    // control: the same account is accepted once the record is active again
    $inactive->update(['is_active' => true]);
    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $account)))->toBeNull()
        ->and(RmePerformedTreatment::query()->count())->toBe(1);
});
