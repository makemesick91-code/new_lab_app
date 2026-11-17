<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M1.1–M1.3, PR2 of 5 (rule 241 §2) — the ACTIVE ODONTOGRAM waits for
| both PTM signatures.
|--------------------------------------------------------------------------
|
| Owner decision of 2026-11-17 (binding): BOTH signatures — the patient's
| (front office) and the treating doctor's countersignature — are required
| before odontogram WRITING. This supersedes rule 134's pre-consent charting for
| writing; READING the chart and its history stays open. The authority stays
| one method, RmeVisitConsentService::assertOdontogramAuthoringAllowed(), which
| inside a write transaction now locks the visit row FIRST and re-decides the
| executed consent on it before the chart is locked or written.
|
| Gate state is built only through the services (sign, countersign, void).
|
| Selected by the existing `Odontogram` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rotg` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'OTG1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Odontogram Dua']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
});

function rotgVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id]);

    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => $status,
    ])->refresh();
}

function rotgPatientSigns(ClinicVisit $visit): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign($visit, test()->frontOffice, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 48',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ]);
}

function rotgDoctorSigns(RmeVisitConsent $consent): void
{
    app(RmeVisitConsentService::class)->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);
}

function rotgPayload(string $tooth = '16', string $condition = 'caries'): array
{
    return ['tooth_map_payload' => ['teeth' => [$tooth => ['conditions' => [$condition]]]]];
}

/** True only when refused by the authoring authority (a ValidationException keyed `consent`) with $needle. */
function rotgRefused(callable $write, string $needle): bool
{
    try {
        $write();
    } catch (ValidationException $e) {
        return array_key_exists('consent', $e->errors())
            && str_contains(implode(' ', $e->errors()['consent']), $needle);
    }

    return false;
}

it('OTG-01 refuses the first chart before any signature and writes no row (pre-consent charting superseded)', function () {
    $visit = rotgVisit();

    expect(rotgRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, rotgPayload(), $this->doctor), 'Persetujuan Tindakan Medis belum ditandatangani'))->toBeTrue()
        ->and(Odontogram::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(app(RmeVisitConsentService::class)->canAuthorOdontogramFor($visit, $this->doctor))->toBeFalse();

    $this->actingAs($this->doctor)
        ->patch(route('rme.visits.odontogram.store', $visit), rotgPayload())
        ->assertSessionHasErrors('consent');

    expect(Odontogram::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('OTG-02 refuses save, revision and finalize with only the patient signature', function () {
    $visit = rotgVisit();
    rotgPatientSigns($visit);
    // A chart written before the gate (e.g. a visit open at deploy).
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = $chart->tooth_map_payload;
    $service = app(OdontogramService::class);

    expect(rotgRefused(fn () => $service->saveForVisit($visit, rotgPayload(), $this->doctor), 'belum ditandatangani dokter'))->toBeTrue('save')
        ->and(rotgRefused(fn () => $service->updatePlaceholder($chart, rotgPayload('17'), $this->doctor), 'belum ditandatangani dokter'))->toBeTrue('revision')
        ->and(rotgRefused(fn () => $service->finalize($chart, $this->doctor), 'belum ditandatangani dokter'))->toBeTrue('finalize')
        ->and($chart->fresh()->tooth_map_payload)->toBe($before)
        ->and($chart->fresh()->isFinalized())->toBeFalse();

    $this->actingAs($this->doctor)
        ->patch(route('rme.odontograms.update', $chart), rotgPayload('18'))
        ->assertSessionHasErrors('consent');
});

it('OTG-03 allows save, revision and finalize once both have signed', function () {
    $visit = rotgVisit();
    rotgDoctorSigns(rotgPatientSigns($visit));
    $service = app(OdontogramService::class);

    $chart = $service->saveForVisit($visit, rotgPayload(), $this->doctor);
    $service->updatePlaceholder($chart, rotgPayload('17', 'missing'), $this->doctor);
    $service->finalize($chart->fresh(), $this->doctor);

    expect($chart->fresh()->isFinalized())->toBeTrue()
        ->and($chart->fresh()->tooth_map_payload['teeth'])->toHaveKey('17')
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(app(RmeVisitConsentService::class)->canAuthorOdontogramFor($visit, $this->doctor))->toBeTrue();
});

it('OTG-04 keeps the odontogram page and its history readable, with an honest state and no save form', function () {
    $visit = rotgVisit();

    $html = $this->actingAs($this->doctor)->get(route('rme.visits.odontogram.show', $visit))->assertOk()->getContent();
    expect($html)->toContain('data-ptm-state="awaiting_patient"')
        ->and($html)->not->toContain('action="'.route('rme.visits.odontogram.store', $visit).'"');

    $consent = rotgPatientSigns($visit);

    $html = $this->actingAs($this->doctor)->get(route('rme.visits.odontogram.show', $visit))->assertOk()->getContent();
    expect($html)->toContain('data-ptm-state="awaiting_doctor"')
        ->and($html)->toContain('menunggu tanda tangan dokter')
        ->and($html)->toContain(route('rme.consents.countersign.create', $consent))
        ->and($html)->not->toContain('action="'.route('rme.visits.odontogram.store', $visit).'"');

    rotgDoctorSigns($consent);

    $html = $this->actingAs($this->doctor)->get(route('rme.visits.odontogram.show', $visit))->assertOk()->getContent();
    expect($html)->not->toContain('data-ptm-state=')
        ->and($html)->toContain('action="'.route('rme.visits.odontogram.store', $visit).'"');
});

it('OTG-05 locks the visit row before the chart row and re-decides the consent under it (PostgreSQL)', function () {
    $visit = rotgVisit();
    rotgDoctorSigns(rotgPatientSigns($visit));
    Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    app(OdontogramService::class)->saveForVisit($visit, rotgPayload(), $this->doctor);

    $visitLock = $chartLock = $countersignRead = null;
    foreach ($log as $i => $sql) {
        if ($visitLock === null && str_contains($sql, '"trx_clinic_visits"') && str_contains($sql, 'for no key update')) {
            $visitLock = $i;
        }
        if ($chartLock === null && str_contains($sql, 'from "trx_odontograms"') && str_contains($sql, 'for update')) {
            $chartLock = $i;
        }
        if ($visitLock !== null && $countersignRead === null && $i > $visitLock && str_contains($sql, 'from "trx_rme_visit_consent_doctor_signatures"')) {
            $countersignRead = $i;
        }
    }

    expect($visitLock)->not->toBeNull()
        ->and($chartLock)->not->toBeNull()
        ->and($visitLock)->toBeLessThan($chartLock)
        ->and($countersignRead)->not->toBeNull()
        ->and($countersignRead)->toBeLessThan($chartLock);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

it('OTG-06 reopens the odontogram gate after a supervised void', function () {
    $visit = rotgVisit();
    $consent = rotgPatientSigns($visit);
    rotgDoctorSigns($consent);
    $chart = app(OdontogramService::class)->saveForVisit($visit, rotgPayload(), $this->doctor);

    app(RmeVisitConsentService::class)->void($consent->fresh(), $this->supervisor, 'Tindakan yang tertulis keliru.');

    expect(rotgRefused(fn () => app(OdontogramService::class)->updatePlaceholder($chart->fresh(), rotgPayload('21'), $this->doctor), 'Persetujuan Tindakan Medis belum ditandatangani'))->toBeTrue();
});

it('OTG-07 binds Super Admin to the same gate', function () {
    $visit = rotgVisit();
    rotgPatientSigns($visit);
    $admin = userInRole('Super Admin')->fresh();

    expect(rotgRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, rotgPayload(), $admin), 'belum ditandatangani dokter'))->toBeTrue()
        ->and(Odontogram::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('OTG-08 keeps a previous visit chart read-only whatever the live encounter\'s consent state', function () {
    $old = rotgVisit(ClinicVisit::STATUS_COMPLETED);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $old->id, 'branch_id' => $old->branch_id]);
    $live = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $old->patient_id,
        'doctor_id' => $this->doctorRecord->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    rotgDoctorSigns(rotgPatientSigns($live));

    expect(rotgRefused(fn () => app(OdontogramService::class)->updatePlaceholder($chart, rotgPayload(), $this->doctor), 'riwayat'))->toBeTrue();
});
