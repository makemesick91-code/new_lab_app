<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 — the supervised amendment stays OUTSIDE the PTM gate
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17, "AMENDMENT — APPROVE: No PTM gate, record it" (rule 240
| §6.11, rule 241 §2.3, AIR-RMC-55). An amendment corrects a record that already
| exists, so neither its request nor its approve-and-apply consults the PTM. What
| stays: append-only versions, the immutable original, the doctor as requester, a
| separate Supervisor RME as approver, the written reason and the audit trail. The
| amended visit's PTM, as its persisted consent evidence records it — executed,
| patient signature only, none, or voided — is recorded as a code in the request
| and the apply audit (`visit_ptm_state`) and shown on the detail page for
| INFORMATION only: the current state, plus the state at request when it differs
| — decided in RmeAmendmentService::detailFor(), never in Blade. No
| consent row is ever created or changed: a historical consent is never made up.
| A new medical act goes through the examination workflow and the PTM instead.
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m33pt`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

/**
 * A finished visit whose PTM is executed, patient_only, none or voided — built as
 * fixtures, before any amendment. The four values are the page's contract
 * (`data-amendment-original-ptm`), so they are written out here.
 */
function m33ptVisit(string $ptm): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $consent = [
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien',
    ];

    match ($ptm) {
        'executed' => rmeSignedConsentFor($visit),
        'patient_only' => RmeVisitConsent::factory()->create($consent),
        'voided' => RmeVisitConsent::factory()->voided()->create($consent),
        'none' => null,
    };

    return $visit->fresh();
}

function m33ptSheet(ClinicVisit $visit): MedicalRecord
{
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => test()->doctor->id,
        'assessment' => 'm33pt pulpitis',
    ]);
    $sheet->forceFill([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
        'finalized_by' => test()->doctorUser->id,
    ])->save();

    return $sheet->fresh();
}

function m33ptChart(ClinicVisit $visit): Odontogram
{
    $chart = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_DRAFT,
        'summary_notes' => 'm33pt ringkasan',
        'tooth_map_payload' => ['teeth' => ['36' => ['status' => 'caries']]],
    ]);
    $chart->forceFill([
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now()->subDay(),
        'finalized_by' => test()->doctorUser->id,
    ])->save();

    return $chart->fresh();
}

/** Every consent row of the visit, as stored: the amendment must never add or touch one. */
function m33ptConsents(ClinicVisit $visit): array
{
    return RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->orderBy('id')->get()
        ->map(fn (RmeVisitConsent $consent): array => $consent->getAttributes())->all();
}

/** The `visit_ptm_state` an amendment audit row recorded (one row per action). */
function m33ptAuditedPtm(RmeAmendment $amendment, string $action): mixed
{
    return AuditLog::query()
        ->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)
        ->where('entity_id', $amendment->id)
        ->where('action', $action)
        ->sole()
        ->new_values['visit_ptm_state'] ?? null;
}

$m33ptStates = [
    'executed' => ['executed', 'Lengkap'],
    'patient signature only' => ['patient_only', 'Hanya tanda tangan pasien'],
    'none' => ['none', 'Tidak ada PTM tercatat'],
    'voided' => ['voided', 'PTM tercatat telah dibatalkan'],
];

it('requests and applies a sheet amendment whatever the visit PTM, shows that PTM for information only, and creates or changes no consent', function (string $ptm, string $label) {
    $visit = m33ptVisit($ptm);
    $sheet = m33ptSheet($visit);
    $consents = m33ptConsents($visit);
    $service = app(RmeAmendmentService::class);

    // No PTM gate on the request (the doctor of record) …
    $amendment = $service->request($sheet, $this->doctorUser, 'm33pt koreksi diagnosis kerja', ['fields' => ['assessment' => 'm33pt pulpitis ireversibel']]);

    // … the state is recorded, as a code, in the request audit …
    expect(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_REQUESTED))->toBe($ptm);

    // … the Supervisor RME sees the recorded PTM, decided by the service …
    expect($service->detailFor($amendment, $this->supervisor)['original_ptm'])
        ->toMatchArray(['state' => $ptm, 'at_request' => null])
        ->and($service->detailFor($amendment, $this->supervisor)['actions']['can_approve'])->toBeTrue();

    $this->actingAs($this->supervisor)
        ->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-amendment-original-ptm="'.$ptm.'"', false)
        ->assertSee($label)
        ->assertSee('informasi')
        ->assertDontSee('data-amendment-original-ptm-at-request', false)
        ->assertSee('data-amendment-approve', false);

    // … and no PTM gate on approve-and-apply either.
    $applied = $service->approve($amendment, $this->supervisor, 'm33pt sesuai catatan klinis');

    expect($applied->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and($sheet->fresh()->assessment)->toBe('m33pt pulpitis ireversibel')
        ->and($sheet->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_APPLIED))->toBe($ptm)
        ->and(m33ptConsents($visit))->toBe($consents);

    // The applied amendment still shows the same recorded PTM, read-only.
    $this->actingAs($this->doctorUser)
        ->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-amendment-original-ptm="'.$ptm.'"', false);

    expect(m33ptConsents($visit))->toBe($consents);
})->with($m33ptStates);

it('shows the recorded PTM of the amended chart visit and applies the chart amendment without a PTM gate', function (string $ptm, string $label) {
    $visit = m33ptVisit($ptm);
    $chart = m33ptChart($visit);
    $consents = m33ptConsents($visit);
    $service = app(RmeAmendmentService::class);

    $amendment = $service->request($chart, $this->doctorUser, 'm33pt diagnosis gigi 36 salah', [
        'tooth_map_payload' => ['teeth' => ['36' => ['status' => 'filling', 'conditions' => ['filling']]]],
    ]);

    $this->actingAs($this->supervisor)
        ->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-amendment-original-ptm="'.$ptm.'"', false)
        ->assertSee($label);

    expect($service->approve($amendment, $this->supervisor)->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_REQUESTED))->toBe($ptm)
        ->and(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_APPLIED))->toBe($ptm)
        ->and(m33ptConsents($visit))->toBe($consents);
})->with($m33ptStates);

it('names the live consent, not an earlier voided one, once the visit was re-signed', function () {
    // A void followed by a new capture: the live consent decides (rule 241 §2.3);
    // the voided one stays history and never turns the state into "voided".
    $visit = m33ptVisit('voided');
    RmeVisitConsent::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien',
    ]);
    $amendment = app(RmeAmendmentService::class)->request(m33ptSheet($visit), $this->doctorUser, 'm33pt koreksi diagnosis kerja', ['fields' => ['assessment' => 'm33pt koreksi']]);

    expect(app(RmeAmendmentService::class)->detailFor($amendment, $this->supervisor)['original_ptm']['state'])
        ->toBe('patient_only');
});

it('shows the state recorded at request beside the current one after a supervised void, records both in the audits, and writes no consent', function () {
    // The examination is still running, so its executed PTM can be voided by a
    // supervisor between the request and the decision (rule 237 §14.2).
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    $consent = rmeSignedConsentFor($visit);
    $sheet = m33ptSheet($visit);
    $service = app(RmeAmendmentService::class);

    $amendment = $service->request($sheet, $this->doctorUser, 'm33pt koreksi diagnosis kerja', ['fields' => ['assessment' => 'm33pt koreksi setelah void']]);

    expect(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_REQUESTED))->toBe('executed')
        ->and($service->detailFor($amendment, $this->supervisor)['original_ptm'])
        ->toMatchArray(['state' => 'executed', 'at_request' => null]);

    app(RmeVisitConsentService::class)->void(
        $consent->fresh(),
        userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']),
        'm33pt identitas pemberi persetujuan keliru',
    );
    $consents = m33ptConsents($visit);

    expect($service->detailFor($amendment, $this->supervisor)['original_ptm'])
        ->toMatchArray(['state' => 'voided'])
        ->and($service->detailFor($amendment, $this->supervisor)['original_ptm']['at_request'])
        ->toMatchArray(['state' => 'executed']);

    $this->actingAs($this->supervisor)
        ->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-amendment-original-ptm="voided"', false)
        ->assertSee('data-amendment-original-ptm-at-request="executed"', false)
        ->assertSeeInOrder(['PTM tercatat telah dibatalkan', 'Lengkap'])
        ->assertSee('data-amendment-approve', false);

    // The decision reads neither: it applies, and its audit records the state now.
    expect($service->approve($amendment, $this->supervisor, 'm33pt sesuai catatan klinis')->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_APPLIED))->toBe('voided')
        ->and(m33ptAuditedPtm($amendment, RmeAmendmentService::AUDIT_REQUESTED))->toBe('executed')
        ->and(m33ptConsents($visit))->toBe($consents)
        ->and(RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1);
});
