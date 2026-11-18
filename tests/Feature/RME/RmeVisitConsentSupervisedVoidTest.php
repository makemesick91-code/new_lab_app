<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 — consent integrity (OD-5-8, OD-5-9, R8A-2, rule 219 §12a)
|--------------------------------------------------------------------------
|
| Owner decisions of 2026-11-16:
|  - OD-5-8 "duplicate guard + supervised void": one valid consent per visit; a
|    consent is voided only through `rme.consents.void`, by an account holding the
|    dedicated `void_rme_consents` permission, with a written reason, never by the
|    account that recorded it (maker ≠ checker, binding Super Admin too), only
|    while the examination runs. The row is kept; signing again is possible only
|    after the void.
|  - OD-5-9: signatures are written to the clinical evidence disk
|    (ClinicalEvidenceStorage, rule 121); a late failure leaves no object behind.
|  - R8A-2: the consent POST is authorized before its payload is validated.
|  - Rule 219 §12a: with a void caller, every consent-gated RME write re-decides
|    the consent under the encounter's visit lock, taken first (rule 223 §6).
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcsv` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'CSV1', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->operator = userWith(['view_clinic_visits', 'manage_rme_billing', 'manage_rme_consents', 'view_rme_consents']);
    $this->supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
});

function rcsvVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $visit = ClinicVisit::factory()->create(['branch_id' => test()->branch->id, 'status' => $status]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

function rcsvPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

function rcsvSign(ClinicVisit $visit, ?User $by = null): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign($visit, $by ?? test()->operator, rcsvPayload());
}

function rcsvValidCount(ClinicVisit $visit): int
{
    return RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->whereNull('voided_at')->count();
}

/*
| OD-5-8 — one valid consent per visit
*/

it('CV-01 refuses a second consent while a valid one exists, in the service and over HTTP', function () {
    $visit = rcsvVisit();
    rcsvSign($visit);

    expect(fn () => rcsvSign($visit))->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_DUPLICATE);

    $this->actingAs($this->operator)
        ->from(route('rme.visits.show', $visit))
        ->post(route('rme.visits.consent.store', $visit), rcsvPayload())
        ->assertSessionHasErrors(['consent' => RmeVisitConsentService::MESSAGE_DUPLICATE]);

    expect(rcsvValidCount($visit))->toBe(1)
        ->and(RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1)
        // The refused attempt left no signature object behind.
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toHaveCount(1);
});

it('CV-02 lets a new consent be signed only after a supervised void, keeping the voided one', function () {
    $visit = rcsvVisit();
    $first = rcsvSign($visit);

    app(RmeVisitConsentService::class)->void($first, $this->supervisor, 'Salah pemberi persetujuan.');
    $second = rcsvSign($visit);

    expect($first->fresh()->isVoided())->toBeTrue()
        ->and($second->isValid())->toBeTrue()
        ->and(rcsvValidCount($visit))->toBe(1)
        ->and(RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->count())->toBe(2);
});

/*
| OD-5-8 — the supervised void
*/

it('CV-03 voids over HTTP for a supervisor with a reason, audits ids only and keeps the row', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);

    $this->actingAs($this->supervisor)
        ->post(route('rme.consents.void', $consent), ['void_reason' => 'Identitas pemberi persetujuan keliru.'])
        ->assertRedirect(route('rme.visits.show', $visit));

    $fresh = $consent->fresh();
    $audit = AuditLog::query()->where('action', RmeVisitConsentService::AUDIT_VOIDED)->sole();

    expect($fresh->isVoided())->toBeTrue()
        ->and((int) $fresh->voided_by)->toBe($this->supervisor->id)
        ->and($fresh->void_reason)->toBe('Identitas pemberi persetujuan keliru.')
        ->and($fresh->consenter_signature_path)->not->toBeNull()
        ->and((int) $audit->entity_id)->toBe((int) $consent->id)
        ->and((int) $audit->performed_by)->toBe($this->supervisor->id)
        ->and(json_encode($audit->new_values))->not->toContain('keliru');
});

it('CV-04 never lets the recording account void its own consent, Super Admin included', function () {
    $visit = rcsvVisit();
    $recorder = userWith(['view_clinic_visits', 'manage_rme_consents', 'view_rme_consents', 'void_rme_consents']);
    $consent = rcsvSign($visit, $recorder);

    expect(fn () => app(RmeVisitConsentService::class)->void($consent, $recorder, 'Batal oleh pencatat sendiri.'))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_VOID_SELF);

    $superAdmin = userInRole('Super Admin');
    $visit2 = rcsvVisit();
    $own = rcsvSign($visit2, $superAdmin);

    expect(fn () => app(RmeVisitConsentService::class)->void($own, $superAdmin, 'Batal oleh pencatat sendiri.'))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_VOID_SELF);

    expect($consent->fresh()->isVoided())->toBeFalse()
        ->and($own->fresh()->isVoided())->toBeFalse()
        ->and(AuditLog::query()->where('action', RmeVisitConsentService::AUDIT_VOIDED)->count())->toBe(0);
});

it('CV-05 refuses a void without the dedicated permission, the signing permission included', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    $signer = userWith(['view_clinic_visits', 'manage_rme_consents', 'view_rme_consents']);

    $this->actingAs($signer)
        ->post(route('rme.consents.void', $consent), ['void_reason' => 'Tidak berwenang membatalkan.'])
        ->assertForbidden();

    expect(fn () => app(RmeVisitConsentService::class)->void($consent, $signer, 'Tidak berwenang membatalkan.'))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_VOID_PERMISSION);

    expect($consent->fresh()->isVoided())->toBeFalse();
});

it('CV-06 refuses a void once the examination is no longer running', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    $visit->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();

    $this->actingAs($this->supervisor)
        ->post(route('rme.consents.void', $consent), ['void_reason' => 'Terlambat dibatalkan.'])
        ->assertForbidden();

    expect(fn () => app(RmeVisitConsentService::class)->void($consent->fresh(), $this->supervisor, 'Terlambat dibatalkan.'))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_VOID_WINDOW);

    expect($consent->fresh()->isVoided())->toBeFalse();
});

it('CV-07 requires a written reason of real length', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);

    $this->actingAs($this->supervisor)
        ->from(route('rme.consents.show', $consent))
        ->post(route('rme.consents.void', $consent), ['void_reason' => 'singkat'])
        ->assertSessionHasErrors('void_reason');

    expect(fn () => app(RmeVisitConsentService::class)->void($consent, $this->supervisor, '   '))
        ->toThrow(ValidationException::class);

    expect($consent->fresh()->isVoided())->toBeFalse();
});

it('CV-08 refuses a supervisor pinned to another working branch', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    $other = Branch::factory()->create(['code' => 'CSV2', 'is_active' => true, 'is_rme_enabled' => true]);
    $remote = userInRole('Admin Klinik');
    $remote->givePermissionTo('void_rme_consents');
    rmeMakeAdminClinicActive($remote, $other);

    $status = $this->actingAs($remote->fresh())
        ->post(route('rme.consents.void', $consent), ['void_reason' => 'Dari cabang lain.'])
        ->status();

    expect($status)->toBeIn([403, 404])
        ->and($consent->fresh()->isVoided())->toBeFalse();
});

it('CV-09 offers the void form to a supervisor, never to the recorder or a signer without the permission', function () {
    $visit = rcsvVisit();
    // The recorder ALSO holds the void permission, so only the signed_by
    // (maker ≠ checker) branch of the decision can hide the form from it.
    $recorder = userWith(['view_clinic_visits', 'manage_rme_consents', 'view_rme_consents', 'void_rme_consents']);
    $consent = rcsvSign($visit, $recorder);

    $this->actingAs($this->supervisor)->get(route('rme.consents.show', $consent))
        ->assertOk()->assertSee('data-testid="consent-void-form"', false);

    $this->actingAs($recorder)->get(route('rme.consents.show', $consent))
        ->assertOk()->assertDontSee('data-testid="consent-void-form"', false);

    $this->actingAs($this->operator)->get(route('rme.consents.show', $consent))
        ->assertOk()->assertDontSee('data-testid="consent-void-form"', false);
});

/*
| R8A-2 — authorization before validation
*/

it('CV-10 answers a refused account with 403 before validating its payload', function () {
    $visit = rcsvVisit();
    $outsider = userWith(['view_clinic_visits', 'view_rme_consents']);

    // Malformed payload: no signature, no action. Before: a field error. Now: 403.
    $this->actingAs($outsider)
        ->post(route('rme.visits.consent.store', $visit), ['template_code' => 'X'])
        ->assertForbidden();

    $this->actingAs($this->supervisor)
        ->post(route('rme.consents.void', rcsvSign($visit)->fresh()), [])
        ->assertSessionHasErrors('void_reason');

    $this->actingAs($this->operator)
        ->post(route('rme.visits.consent.store', 987654321), rcsvPayload())
        ->assertNotFound();
});

/*
| OD-5-9 — storage, and no orphan after a late failure
*/

it('CV-11 writes new signatures to the clinical evidence disk, never to the served local disk', function () {
    // Rule 241 §4 — the capture no longer takes a doctor drawing (the doctor
    // countersigns separately), so the patient's signature is the one object.
    $visit = rcsvVisit();
    $consent = app(RmeVisitConsentService::class)->sign($visit, $this->operator, rcsvPayload());

    expect(Storage::disk(ClinicalEvidenceStorage::diskName())->exists($consent->consenter_signature_path))->toBeTrue()
        ->and($consent->doctor_signature_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(app(RmeVisitConsentService::class)->signatureDataUri($consent->consenter_signature_path))->toStartWith('data:image/png;base64,');
});

it('CV-12 removes the signature objects it wrote when the consent insert fails', function () {
    $visit = rcsvVisit();
    $seenAtFailure = null;

    $this->mock(RmeVisitConsentRepositoryInterface::class, function ($mock) use (&$seenAtFailure) {
        $mock->shouldReceive('validForVisit')->andReturnNull();
        $mock->shouldReceive('lockNumberSequence')->andReturnNull();
        $mock->shouldReceive('latestConsentNumberForMonth')->andReturnNull();
        $mock->shouldReceive('create')->andReturnUsing(function () use (&$seenAtFailure) {
            // The signature object exists on the clinical evidence disk when the
            // insert fails…
            $seenAtFailure = count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());

            throw new RuntimeException('insert failed');
        });
    });

    expect(fn () => app(RmeVisitConsentService::class)->sign($visit, $this->operator, rcsvPayload()))
        ->toThrow(RuntimeException::class, 'insert failed');

    // …and none is left afterwards, on either disk. (Rule 241 §4: one object,
    // the patient's; the capture no longer stores a doctor drawing.)
    expect($seenAtFailure)->toBe(1)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

/*
| Rule 219 §12a / rule 223 §6 — writes re-decide the consent under the visit lock
*/

it('CV-13 locks the encounter visit row inside a write transaction before re-checking the consent', function () {
    $visit = rcsvVisit();
    // Rule 241 §2 — writes need the EXECUTED consent (fixture countersignature).
    rmeDoctorCountersignatureFor(rcsvSign($visit));
    $author = rmeClinicalAuthor(['manage_clinic_visits']);

    $locks = [];
    DB::listen(function (QueryExecuted $q) use (&$locks): void {
        $sql = strtolower($q->sql);
        if (str_contains($sql, 'trx_clinic_visits') && str_contains($sql, 'for no key update')) {
            $locks[] = array_map('intval', $q->bindings);
        }
    });

    $encounter = DB::transaction(fn () => app(RmeVisitConsentService::class)->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $author));

    expect((int) $encounter->id)->toBe((int) $visit->id)
        ->and($locks)->toContain([(int) $visit->id]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

it('CV-14 refuses an RM write after the consent was voided, even inside a transaction', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    rmeDoctorCountersignatureFor($consent); // Rule 241 §2 — writes need the EXECUTED consent.
    $author = rmeClinicalAuthor(['manage_clinic_visits']);
    $consents = app(RmeVisitConsentService::class);

    // Allowed while the consent is valid…
    expect((int) DB::transaction(fn () => $consents->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $author))->id)
        ->toBe((int) $visit->id);

    $consents->void($consent, $this->supervisor, 'Persetujuan dibatalkan pengawas.');

    // …refused once it is voided.
    expect(fn () => DB::transaction(fn () => $consents->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $author)))
        ->toThrow(ValidationException::class, 'Persetujuan Tindakan Medis belum ditandatangani');
});

/*
| Review round (2026-11-17)
*/

/** An Admin Klinik holding both consent permissions, pinned to another branch. */
function rcsvRemoteOperator(): User
{
    $other = Branch::factory()->create(['code' => 'CSV7', 'is_active' => true, 'is_rme_enabled' => true]);
    $remote = userInRole('Admin Klinik');
    $remote->givePermissionTo(['manage_rme_consents', 'void_rme_consents']);
    rmeMakeAdminClinicActive($remote, $other);

    return $remote->fresh();
}

/**
 * True when, inside the transaction that holds the first statement matching
 * $isAnchor, a visit row lock was taken BEFORE that statement. The BEGIN of
 * every (nested) transaction is recorded, so a lock taken before the write's
 * own transaction (e.g. a pre-check outside it) does not count.
 */
function rcsvVisitLockedBefore(callable $act, callable $isAnchor): bool
{
    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    $act();

    $anchor = null;
    foreach ($log as $i => $entry) {
        if ($entry !== 'BEGIN' && $isAnchor($entry)) {
            $anchor = $i;
            break;
        }
    }

    $begin = null;
    for ($i = ($anchor ?? 0) - 1; $i >= 0; $i--) {
        if ($log[$i] === 'BEGIN') {
            $begin = $i;
            break;
        }
    }

    if ($anchor === null || $begin === null) {
        return false;
    }

    for ($i = $begin + 1; $i < $anchor; $i++) {
        if (str_contains($log[$i], '"trx_clinic_visits"') && str_contains($log[$i], 'for no key update')) {
            return true;
        }
    }

    return false;
}

/** A distinct, non-blank 10x10 RGBA page drawing. */
function rcsvDrawing(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 37 + $y) % 256, 10, 10, 255), 10);
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', ''));
}

it('CV-15 answers an account inside the route middleware but outside the policy before validating (R8A-2)', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    $remote = rcsvRemoteOperator();

    // It passes both route middlewares…
    expect($remote->can('manage_rme_consents'))->toBeTrue()
        ->and($remote->can('void_rme_consents'))->toBeTrue();

    // …so only the FormRequest's policy check can refuse its malformed payloads.
    $store = $this->actingAs($remote)->post(route('rme.visits.consent.store', $visit), ['template_code' => 'X']);
    $void = $this->actingAs($remote)->post(route('rme.consents.void', $consent), []);

    expect($store->status())->toBeIn([403, 404])
        ->and($void->status())->toBeIn([403, 404]);
    $store->assertSessionHasNoErrors();
    $void->assertSessionHasNoErrors();
});

it('CV-16 re-decides the consent under the visit lock inside every consent-gated write transaction', function () {
    $visit = rcsvVisit();
    // Rule 241 §2 — writes need the EXECUTED consent (fixture countersignature).
    rmeDoctorCountersignatureFor(rcsvSign($visit));
    $record = MedicalRecord::query()->where('clinic_visit_id', $visit->id)->sole();
    // Rule 240 §1 (memo M3.3) — a FINAL sheet's diagnoses and override are locked,
    // so the writes whose lock order this pins run on a draft sheet.
    $record->forceFill(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null])->save();
    $author = rmeClinicalAuthor(['manage_clinic_visits']);
    // The override is only relevant on a pilot_enforced branch (never a default).
    DiagnosisRolloutSetting::query()->create([
        'branch_id' => $this->branch->id,
        'mode' => DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'reason' => 'Pilot cabang untuk uji kunci baris kunjungan.',
        'configured_by' => $author->id,
    ]);
    $diagnoses = app(MedicalRecordDiagnosisService::class);
    $first = ClinicalDiagnosis::factory()->create();
    $second = ClinicalDiagnosis::factory()->create();

    $recordLock = fn (string $sql): bool => str_contains($sql, 'from "trx_medical_records"') && str_contains($sql, 'for update');

    expect(rcsvVisitLockedBefore(
        fn () => $diagnoses->record($record, ['clinical_diagnosis_id' => $first->id, 'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY], $author),
        $recordLock,
    ))->toBeTrue('record()');

    $diagnosis = MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->sole();

    expect(rcsvVisitLockedBefore(fn () => $diagnoses->makePrimary($record, $diagnosis, $author), $recordLock))
        ->toBeTrue('makePrimary()');

    $diagnoses->record($record, ['clinical_diagnosis_id' => $second->id, 'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY], $author);
    $removable = MedicalRecordDiagnosis::query()->where('clinical_diagnosis_id', $second->id)->sole();

    expect(rcsvVisitLockedBefore(
        fn () => $diagnoses->remove($record, $removable, $author),
        // A soft delete is an UPDATE; either way the row write is the anchor.
        fn (string $sql): bool => str_starts_with(ltrim($sql), 'delete from "trx_medical_record_diagnoses"')
            || str_starts_with(ltrim($sql), 'update "trx_medical_record_diagnoses"'),
    ))->toBeTrue('remove()');

    expect(rcsvVisitLockedBefore(
        fn () => app(DiagnosisRolloutService::class)->grantOverride($record, 'Darurat: pasien tidak sadar.', $author),
        fn (string $sql): bool => str_starts_with(ltrim($sql), 'insert into "trx_diagnosis_requirement_overrides"'),
    ))->toBeTrue('grantOverride()');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

it('CV-16b re-decides the consent under the visit lock before the record lock when a page is edited in place', function () {
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS]);
    rmeActiveConsentedEncounter($visit);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    $author = rmeClinicalAuthor(['manage_clinic_visits']);
    $save = fn (int $seed) => $this->actingAs($author)->post(
        route('rme.visits.medical-record.handwriting.store', [$visit, $record]),
        ['handwriting_data' => rcsvDrawing($seed), 'page_number' => 1],
    );

    $save(1)->assertSessionHasNoErrors();

    expect(rcsvVisitLockedBefore(
        fn () => $save(2)->assertSessionHasNoErrors(),
        fn (string $sql): bool => str_contains($sql, 'from "trx_medical_records"') && str_contains($sql, 'for no key update'),
    ))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

it('CV-17 refuses a direct service void or sign by an account outside the branch scope (CG-06)', function () {
    $visit = rcsvVisit();
    $consent = rcsvSign($visit);
    $remote = rcsvRemoteOperator();

    expect(fn () => app(RmeVisitConsentService::class)->void($consent, $remote, 'Dari cabang lain, langsung ke service.'))
        ->toThrow(AuthorizationException::class);
    expect($consent->fresh()->isVoided())->toBeFalse();

    $other = rcsvVisit();

    expect(fn () => rcsvSign($other, $remote))->toThrow(AuthorizationException::class);
    expect(rcsvValidCount($other))->toBe(0)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toHaveCount(1);
});

it('CV-18 never offers Super Admin the void form on a voided consent or a visit no longer running', function () {
    $superAdmin = userInRole('Super Admin');

    $voidedVisit = rcsvVisit();
    $voided = rcsvSign($voidedVisit);
    app(RmeVisitConsentService::class)->void($voided, $this->supervisor, 'Dibatalkan sebelum diperiksa.');

    $finishedVisit = rcsvVisit();
    $finished = rcsvSign($finishedVisit);
    $finishedVisit->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();

    $this->actingAs($superAdmin)->get(route('rme.consents.show', $voided))
        ->assertOk()->assertDontSee('data-testid="consent-void-form"', false);
    $this->actingAs($superAdmin)->get(route('rme.consents.show', $finished))
        ->assertOk()->assertDontSee('data-testid="consent-void-form"', false);

    // Control: on a running visit Super Admin (not the recorder) is offered it.
    $live = rcsvSign(rcsvVisit());
    $this->actingAs($superAdmin)->get(route('rme.consents.show', $live))
        ->assertOk()->assertSee('data-testid="consent-void-form"', false);
});
