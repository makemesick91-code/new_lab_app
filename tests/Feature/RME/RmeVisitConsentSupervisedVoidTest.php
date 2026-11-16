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
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
    $consent = rcsvSign($visit);

    $this->actingAs($this->supervisor)->get(route('rme.consents.show', $consent))
        ->assertOk()->assertSee('data-testid="consent-void-form"', false);

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
    $visit = rcsvVisit();
    $consent = app(RmeVisitConsentService::class)->sign($visit, $this->operator, rcsvPayload(['doctor_signature' => validPodSignatureData()]));

    expect(Storage::disk(ClinicalEvidenceStorage::diskName())->exists($consent->consenter_signature_path))->toBeTrue()
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->exists($consent->doctor_signature_path))->toBeTrue()
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
            // Both signature objects exist on the clinical evidence disk when the
            // insert fails…
            $seenAtFailure = count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());

            throw new RuntimeException('insert failed');
        });
    });

    expect(fn () => app(RmeVisitConsentService::class)->sign($visit, $this->operator, rcsvPayload(['doctor_signature' => validPodSignatureData()])))
        ->toThrow(RuntimeException::class, 'insert failed');

    // …and none is left afterwards, on either disk.
    expect($seenAtFailure)->toBe(2)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

/*
| Rule 219 §12a / rule 223 §6 — writes re-decide the consent under the visit lock
*/

it('CV-13 locks the encounter visit row inside a write transaction before re-checking the consent', function () {
    $visit = rcsvVisit();
    rcsvSign($visit);
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
