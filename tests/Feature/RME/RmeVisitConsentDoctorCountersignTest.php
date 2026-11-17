<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M1.1–M1.3, PR1 of 5 (rule 241 §1) — the treating doctor's
| countersignature on the PERSETUJUAN TINDAKAN MEDIS, captured and stored only.
|--------------------------------------------------------------------------
|
| Owner decisions of 2026-11-17: the PTM carries two signatures — the patient's,
| captured by the front office, and the treating doctor's, captured from the
| doctor's own treatment room. PR1 added the doctor's step and nothing else;
| PR2 (rule 241 §2) then made both signatures the gate for every clinical write
| (CDS-29 was flipped accordingly).
|
|  - The countersignature is a separate, insert-only row
|    (`trx_rme_visit_consent_doctor_signatures`, UNIQUE per consent); the consent
|    row is never updated (AIR-RMC-12).
|  - Only the account linked to the visit's treating doctor, who is also the
|    consent's doctor, may countersign, never the account that captured the
|    patient signature; identity binds Super Admin (Gate::before) in the service.
|  - The visit row is locked first; the visit must be in_progress, the consent
|    valid and not yet countersigned. The canvas is bounded and validated before
|    any write; the object lives on ClinicalEvidenceStorage and a failure removes
|    only the object this call wrote (rule 224).
|  - Audit `RME_CONSENT_DOCTOR_SIGNED`, ids only.
|  - Out-of-branch answers 403, like the existing consent endpoints (rule 237
|    §14.6 records that drift as kept for consistency).
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcdc` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Models\RmeVisitConsentDoctorSignature;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'CDS1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Penanda Tangan PTM']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();

    // The account that captures the patient's signature (front-office shape:
    // the consent permissions without any clinical authority).
    $this->operator = userWith(['view_clinic_visits', 'manage_rme_consents', 'view_rme_consents']);
    $this->supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
});

function rcdcVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS, ?Doctor $doctor = null): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Rahasia Kontrasign']);

    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => ($doctor ?? test()->doctorRecord)->id,
        'clinic_room_id' => test()->room->id,
        'status' => $status,
    ])->refresh();
}

/** The patient's consent, captured through the real service (never a factory row). */
function rcdcPatientSigned(ClinicVisit $visit, ?User $by = null, array $overrides = []): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign($visit, $by ?? test()->operator, array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides));
}

/**
 * A consent that already carries the LEGACY optional doctor drawing — the shape
 * of a consent captured before rule 241 §4 refused that field. Written as a
 * fixture (object on the evidence disk + the column), since no current path
 * stores it any more.
 */
function rcdcWithLegacyDrawing(RmeVisitConsent $consent): RmeVisitConsent
{
    $path = 'rme-consents/'.$consent->consent_number.'/doctor-legacy-fixture.png';
    $png = base64_decode(substr(validPodSignatureData(), strlen('data:image/png;base64,')));
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($path, $png);
    DB::table('trx_rme_visit_consents')->where('id', $consent->id)->update(['doctor_signature_path' => $path]);

    return $consent->fresh();
}

function rcdcCountersign(RmeVisitConsent $consent, ?User $by = null, ?string $canvas = null): RmeVisitConsentDoctorSignature
{
    return app(RmeVisitConsentService::class)->countersign($consent, $by ?? test()->doctor, [
        'doctor_signature' => $canvas ?? validPodSignatureData(),
    ]);
}

/** Every object on the clinical evidence disk that a countersignature wrote. */
function rcdcCountersignObjects(): array
{
    return array_values(array_filter(
        Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles(),
        fn (string $key): bool => str_contains($key, '/doctor-countersign-'),
    ));
}

/** An RGBA PNG; blank = fully transparent, otherwise one opaque pixel. */
function rcdcPng(int $width, int $height, bool $blank = false): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $line = str_repeat("\xff\xff\xff\x00", $width);
        if (! $blank && $y === intdiv($height, 2)) {
            $line = substr_replace($line, "\x00\x00\x00\xff", intdiv($width, 2) * 4, 4);
        }
        $raw .= "\0".$line;
    }

    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    };

    return 'data:image/png;base64,'.base64_encode(
        "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', '')
    );
}

/** A PNG whose IHDR declares the given size (refused on the declaration alone). */
function rcdcDeclaredPng(int $width, int $height): string
{
    $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
    $chunk = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    $idat = gzcompress(str_repeat("\0", 64));
    $idatChunk = pack('N', strlen($idat)).'IDAT'.$idat.pack('N', crc32('IDAT'.$idat));
    $iend = pack('N', 0).'IEND'.pack('N', crc32('IEND'));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".$chunk.$idatChunk.$iend);
}

/** A second doctor of the same branch, linked and online in its own room (one doctor per room). */
function rcdcOtherDoctor(?ClinicRoom $room = null): User
{
    $record = Doctor::factory()->withAllowedBranches([test()->branch])->create(['name' => 'drg. Dokter Lain']);
    $room ??= ClinicRoom::factory()->create(['branch_id' => test()->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    return rmeMakeDoctorOnline($record, test()->branch, $room)->fresh();
}

function rcdcRoleUser(string $role): User
{
    return match ($role) {
        'Front Office' => tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, test()->branch))->fresh(),
        'Admin Klinik' => tap(User::factory()->create(), fn (User $u) => rmeMakeAdminClinicActive($u, test()->branch))->fresh(),
        'Kasir' => tap(User::factory()->create(), fn (User $u) => rmeMakeKasirActive($u, test()->branch))->fresh(),
        'Perawat' => tap(User::factory()->create(), fn (User $u) => rmeMakePerawatActive($u, test()->branch))->fresh(),
        default => userInRole($role)->fresh(),
    };
}

/**
 * True when, inside the transaction that holds the first statement matching
 * $isAnchor, a visit row lock was taken BEFORE that statement.
 */
function rcdcVisitLockedBefore(callable $act, callable $isAnchor): bool
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

    if ($anchor === null) {
        return false;
    }

    $begin = null;
    for ($i = $anchor - 1; $i >= 0; $i--) {
        if ($log[$i] === 'BEGIN') {
            $begin = $i;
            break;
        }
    }

    if ($begin === null) {
        return false;
    }

    for ($i = $begin + 1; $i < $anchor; $i++) {
        if (str_contains($log[$i], '"trx_clinic_visits"') && str_contains($log[$i], 'for no key update')) {
            return true;
        }
    }

    return false;
}

/*
| Schema and seeding
*/

it('CDS-01 stores the countersignature in its own additive table, unique per consent', function () {
    expect(Schema::hasTable('trx_rme_visit_consent_doctor_signatures'))->toBeTrue()
        ->and(Schema::hasColumns('trx_rme_visit_consent_doctor_signatures', [
            'id', 'rme_visit_consent_id', 'doctor_id', 'signed_by', 'signature_path', 'signature_sha256', 'signed_at', 'created_at',
        ]))->toBeTrue()
        // The consent table itself gains nothing (AIR-RMC-12: the row is never updated).
        ->and(Schema::hasColumn('trx_rme_visit_consents', 'doctor_countersigned_at'))->toBeFalse();

    $consent = rcdcPatientSigned(rcdcVisit());
    rcdcCountersign($consent);

    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_visit_consent_doctor_signatures')->insert([
        'rme_visit_consent_id' => $consent->id,
        'doctor_id' => test()->doctorRecord->id,
        'signed_by' => test()->doctor->id,
        'signature_path' => 'rme-consents/x/doctor-countersign-duplicate.png',
        'signature_sha256' => str_repeat('a', 64),
        'signed_at' => now(),
        'created_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('CDS-02 grants countersign_rme_consents to the Doctor role only (Super Admin holds every permission)', function () {
    expect(Permission::query()->where('name', RmeVisitConsentService::COUNTERSIGN_PERMISSION)->exists())->toBeTrue()
        ->and(RmeVisitConsentService::COUNTERSIGN_PERMISSION)->toBe('countersign_rme_consents');

    $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'countersign_rme_consents'))
        ->orderBy('name')->pluck('name')->all();

    // Super Admin's '*' grant is irrelevant here: Gate::before already answers
    // every ability for it, and the service's identity condition binds it.
    expect($holders)->toBe(['Doctor', 'Super Admin']);
});

/*
| Happy path
*/

it('CDS-03 lets the treating doctor countersign: one row, one private object, the consent row untouched', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    $before = DB::table('trx_rme_visit_consents')->where('id', $consent->id)->first();

    $signature = rcdcCountersign($consent);

    $objects = rcdcCountersignObjects();
    $disk = Storage::disk(ClinicalEvidenceStorage::diskName());

    expect($signature->exists)->toBeTrue()
        ->and((int) $signature->rme_visit_consent_id)->toBe((int) $consent->id)
        ->and((int) $signature->doctor_id)->toBe((int) $this->doctorRecord->id)
        ->and((int) $signature->signed_by)->toBe((int) $this->doctor->id)
        ->and($signature->signed_at)->not->toBeNull()
        ->and($objects)->toBe([$signature->signature_path])
        ->and(str_starts_with($signature->signature_path, 'rme-consents/'.$consent->consent_number.'/doctor-countersign-'))->toBeTrue()
        ->and($signature->signature_sha256)->toBe(hash('sha256', $disk->get($signature->signature_path)))
        // The stored path is never serialised.
        ->and(array_key_exists('signature_path', $signature->toArray()))->toBeFalse()
        ->and($consent->fresh()->doctorCountersignature?->is($signature))->toBeTrue()
        ->and($consent->fresh()->isDoctorCountersigned())->toBeTrue()
        // AIR-RMC-12 — the consent row is exactly as it was.
        ->and((array) DB::table('trx_rme_visit_consents')->where('id', $consent->id)->first())->toBe((array) $before);
});

it('CDS-04 audits RME_CONSENT_DOCTOR_SIGNED with ids only', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $signature = rcdcCountersign($consent);

    $audit = AuditLog::query()->where('action', RmeVisitConsentService::AUDIT_DOCTOR_SIGNED)->sole();

    expect(RmeVisitConsentService::AUDIT_DOCTOR_SIGNED)->toBe('RME_CONSENT_DOCTOR_SIGNED')
        ->and($audit->entity_type)->toBe('trx_rme_visit_consents')
        ->and((int) $audit->entity_id)->toBe((int) $consent->id)
        ->and((int) $audit->performed_by)->toBe((int) $this->doctor->id)
        // PostgreSQL json(b) may reorder keys: compare the set, then the types.
        ->and($audit->new_values)->toEqual([
            'clinic_visit_id' => (int) $consent->clinic_visit_id,
            'doctor_id' => (int) $this->doctorRecord->id,
            'doctor_signature_id' => (int) $signature->id,
        ])
        ->and(array_keys($audit->new_values))->toEqualCanonicalizing(['clinic_visit_id', 'doctor_id', 'doctor_signature_id'])
        ->and(json_encode($audit->new_values))->not->toContain('Pasien Rahasia')
        ->and(json_encode($audit->new_values))->not->toContain('rme-consents/');
});

it('CDS-05 countersigns over HTTP from the form and ignores crafted identity fields', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $stranger = User::factory()->create();

    $form = $this->actingAs($this->doctor)
        ->get(route('rme.consents.countersign.create', $consent))
        ->assertOk()
        ->assertSee('Tanda Tangani PTM')
        ->assertSee('name="doctor_signature"', false);

    // The form renders the consent document (identity number included).
    expect((string) $form->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $this->actingAs($this->doctor)
        ->post(route('rme.consents.countersign', $consent), [
            'doctor_signature' => validPodSignatureData(),
            'doctor_id' => 999999,
            'signed_by' => $stranger->id,
        ])
        ->assertRedirect(route('rme.consents.show', $consent))
        ->assertSessionHasNoErrors();

    $row = RmeVisitConsentDoctorSignature::query()->sole();

    expect((int) $row->doctor_id)->toBe((int) $this->doctorRecord->id)
        ->and((int) $row->signed_by)->toBe((int) $this->doctor->id);
});

/*
| Validation — bounded and validated before any write
*/

it('CDS-06 refuses a missing, blank, oversized, over-declared or non-PNG canvas before any write', function (string $case) {
    $consent = rcdcPatientSigned(rcdcVisit());

    $canvas = match ($case) {
        'missing' => '',
        'blank' => rcdcPng(40, 20, blank: true),
        'oversized' => 'data:image/png;base64,'.str_repeat('A', 1_000_001),
        'declared' => rcdcDeclaredPng(4096, 10),
        'jpeg' => 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0".str_repeat("\0", 64)),
        'garbage' => 'data:image/png;base64,@@not-base64@@',
    };

    expect(fn () => app(RmeVisitConsentService::class)->countersign($consent, $this->doctor, ['doctor_signature' => $canvas]))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->doctor)
        ->from(route('rme.consents.countersign.create', $consent))
        ->post(route('rme.consents.countersign', $consent), ['doctor_signature' => $canvas])
        ->assertSessionHasErrors('doctor_signature')
        // Rule 237 §3 — a refused canvas is never flashed into the session store.
        ->assertSessionMissing('_old_input.doctor_signature');

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
})->with(['missing', 'blank', 'oversized', 'declared', 'jpeg', 'garbage']);

it('CDS-07 accepts a real 700x220 consent canvas (the bound sits above the drawn size)', function () {
    $consent = rcdcPatientSigned(rcdcVisit());

    $signature = rcdcCountersign($consent, canvas: rcdcPng(700, 220));

    expect($signature->exists)->toBeTrue();
});

/*
| Authorization matrix — service (CG-06 third layer) and HTTP
*/

it('CDS-08 refuses every account other than the treating doctor in the service, writing nothing', function (string $who, string $guard) {
    $consent = rcdcPatientSigned(rcdcVisit());

    $actor = match ($who) {
        'other doctor' => rcdcOtherDoctor(),
        'Super Admin' => userInRole('Super Admin')->fresh(),
        'Super Admin linked to another doctor' => tap(userInRole('Super Admin'), function (User $u): void {
            Doctor::factory()->withAllowedBranches([test()->branch])->create(['user_id' => $u->id]);
        })->fresh(),
        default => rcdcRoleUser($who),
    };

    // The message names the guard that refused, so each case proves which one fired.
    expect(fn () => rcdcCountersign($consent, $actor))
        ->toThrow(AuthorizationException::class, constant(RmeVisitConsentService::class.'::'.$guard));

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([])
        ->and(AuditLog::query()->where('action', 'RME_CONSENT_DOCTOR_SIGNED')->count())->toBe(0);
})->with([
    'other doctor' => ['other doctor', 'MESSAGE_OUT_OF_SCOPE'],
    'Front Office' => ['Front Office', 'MESSAGE_COUNTERSIGN_PERMISSION'],
    'Kasir' => ['Kasir', 'MESSAGE_COUNTERSIGN_PERMISSION'],
    'Admin Klinik' => ['Admin Klinik', 'MESSAGE_COUNTERSIGN_PERMISSION'],
    'Perawat' => ['Perawat', 'MESSAGE_COUNTERSIGN_PERMISSION'],
    'Supervisor RME' => ['Supervisor RME', 'MESSAGE_COUNTERSIGN_PERMISSION'],
    // Gate::before lets Super Admin through every permission and policy; the
    // identity guard is what refuses it.
    'Super Admin' => ['Super Admin', 'MESSAGE_COUNTERSIGN_NOT_LINKED'],
    'Super Admin linked to another doctor' => ['Super Admin linked to another doctor', 'MESSAGE_COUNTERSIGN_NOT_VISIT_DOCTOR'],
]);

it('CDS-09 answers 403 over HTTP to every account other than the treating doctor', function (string $who) {
    $consent = rcdcPatientSigned(rcdcVisit());

    $actor = match ($who) {
        'other doctor' => rcdcOtherDoctor(),
        'Super Admin' => userInRole('Super Admin')->fresh(),
        default => rcdcRoleUser($who),
    };

    $this->actingAs($actor)
        ->get(route('rme.consents.countersign.create', $consent))
        ->assertForbidden();

    $this->actingAs($actor)
        ->post(route('rme.consents.countersign', $consent), ['doctor_signature' => validPodSignatureData()])
        ->assertForbidden();

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
})->with(['other doctor', 'Front Office', 'Kasir', 'Admin Klinik', 'Perawat', 'Supervisor RME', 'Super Admin']);

it('CDS-10 refuses an account outside the branch even with a runtime grant (403, like consent view)', function () {
    $consent = rcdcPatientSigned(rcdcVisit());

    $other = Branch::factory()->create(['code' => 'CDS7', 'is_active' => true, 'is_rme_enabled' => true]);
    $remote = userInRole('Admin Klinik');
    $remote->givePermissionTo(['countersign_rme_consents', 'view_rme_consents']);
    rmeMakeAdminClinicActive($remote, $other);
    $remote = $remote->fresh();

    $this->actingAs($remote)
        ->post(route('rme.consents.countersign', $consent), ['doctor_signature' => validPodSignatureData()])
        ->assertForbidden();

    expect(fn () => rcdcCountersign($consent, $remote))->toThrow(AuthorizationException::class);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
});

it('CDS-11 answers 404 for a crafted or unknown consent id', function () {
    $this->actingAs($this->doctor)->get('/rme/consents/999999/countersign')->assertNotFound();
    $this->actingAs($this->doctor)->get('/rme/consents/abc/countersign')->assertNotFound();
    $this->actingAs($this->doctor)->post('/rme/consents/0999/countersign', ['doctor_signature' => validPodSignatureData()])->assertNotFound();
});

it('CDS-12 binds the identity to the locked visit AND the consent: a reassigned visit cannot be countersigned', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);

    // The visit is re-placed after the patient signed: the consent names the
    // first doctor, the visit now names the second (in the second's room, so the
    // second doctor may otherwise work on the encounter).
    $room2 = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $second = rcdcOtherDoctor($room2);
    $secondRecord = Doctor::query()->where('user_id', $second->id)->sole();
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['doctor_id' => $secondRecord->id, 'clinic_room_id' => $room2->id]);

    // The new treating doctor is the visit's doctor but not the consent's.
    expect(fn () => rcdcCountersign($consent->fresh(), $second))
        ->toThrow(AuthorizationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_NOT_CONSENT_DOCTOR)
        // The consent's doctor no longer works this room (room scope).
        ->and(fn () => rcdcCountersign($consent->fresh(), $this->doctor))->toThrow(AuthorizationException::class);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0);
});

it('CDS-12b refuses the consent\'s doctor once ONLY the visit\'s doctor changed (the visit-doctor identity guard)', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);

    // The consent's doctor keeps working this room and keeps a treating
    // relationship with the patient (an earlier visit), so every other guard
    // passes; only the visit now names another doctor.
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $this->doctorRecord->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $other = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Pengganti']);
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['doctor_id' => $other->id]);

    expect(fn () => rcdcCountersign($consent->fresh(), $this->doctor))
        ->toThrow(AuthorizationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_NOT_VISIT_DOCTOR);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
});

it('CDS-13 refuses an inactive linked doctor (the is_active identity guard)', function () {
    // A Doctor-role account whose doctor record is deactivated is already
    // refused by the branch/patient scope guard, before identity is asked.
    $consent = rcdcPatientSigned(rcdcVisit());
    $this->doctorRecord->forceFill(['is_active' => false])->save();

    expect(fn () => rcdcCountersign($consent, $this->doctor->fresh()))
        ->toThrow(AuthorizationException::class, RmeVisitConsentService::MESSAGE_OUT_OF_SCOPE);

    // Where every earlier guard passes — a Super Admin linked to the visit's own,
    // now inactive, doctor — the is_active guard is the one that refuses.
    $inactive = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Nonaktif']);
    $admin = userInRole('Super Admin');
    $inactive->forceFill(['user_id' => $admin->id])->save();
    $visit = rcdcVisit(doctor: $inactive);
    $second = rcdcPatientSigned($visit);
    $inactive->forceFill(['is_active' => false])->save();

    expect(fn () => rcdcCountersign($second, $admin->fresh()))
        ->toThrow(AuthorizationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_DOCTOR_INACTIVE);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0);
});

it('CDS-13b refuses a non-treating holder in the POLICY even where a state refusal would come later (403, never the form)', function () {
    $visit = rcdcVisit();
    $stale = rcdcPatientSigned($visit);
    // A second, later non-voided consent makes the first one no longer the
    // visit's live consent (a pre-OD-5-8 data shape): a state refusal.
    RmeVisitConsent::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        // Later than the first by the same clock (validForVisit orders by signed_at).
        'signed_at' => $stale->signed_at->addMinute(),
    ]);

    expect(app(RmeVisitConsentService::class)->validConsentFor($visit)?->is($stale))->toBeFalse();

    // A clinical account outside doctor scope, given the permission at runtime.
    $holder = userInRole('Supervisor RME');
    $holder->givePermissionTo('countersign_rme_consents');
    $holder = $holder->fresh();

    $this->actingAs($holder)->get(route('rme.consents.countersign.create', $stale))->assertForbidden();
    $this->actingAs($holder)
        ->post(route('rme.consents.countersign', $stale), ['doctor_signature' => validPodSignatureData()])
        ->assertForbidden();

    expect(fn () => rcdcCountersign($stale->fresh(), $holder))
        ->toThrow(AuthorizationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_NOT_LINKED);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0);
});

it('CDS-14 refuses the account that captured the patient signature (maker ≠ checker)', function () {
    $visit = rcdcVisit();
    // The treating doctor operated the tablet for the patient's signature.
    $consent = rcdcPatientSigned($visit, $this->doctor);

    expect(fn () => rcdcCountersign($consent, $this->doctor))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_SELF);

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
});

/*
| State — window, voided, once only
*/

it('CDS-15 refuses outside the examination window', function (string $status) {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => $status]);

    expect(fn () => rcdcCountersign($consent->fresh()))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_WINDOW);

    expect(rcdcCountersignObjects())->toBe([]);
})->with([ClinicVisit::STATUS_CASHIER_PENDING, ClinicVisit::STATUS_COMPLETED, ClinicVisit::STATUS_CANCELLED]);

it('CDS-16 refuses a voided consent', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    app(RmeVisitConsentService::class)->void($consent, $this->supervisor, 'Salah pemberi persetujuan.');

    expect(fn () => rcdcCountersign($consent->fresh()))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_VOIDED);

    // A stale copy of the consent is re-read under the lock, too.
    expect(fn () => rcdcCountersign($consent))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_VOIDED);

    $this->actingAs($this->doctor)
        ->post(route('rme.consents.countersign', $consent), ['doctor_signature' => validPodSignatureData()])
        ->assertForbidden();

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([]);
});

it('CDS-17 refuses a second countersignature, in the service and over HTTP', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $first = rcdcCountersign($consent);

    expect(fn () => rcdcCountersign($consent))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_DUPLICATE);

    $this->actingAs($this->doctor)
        ->from(route('rme.consents.show', $consent))
        ->post(route('rme.consents.countersign', $consent), ['doctor_signature' => validPodSignatureData()])
        ->assertSessionHasErrors(['consent' => RmeVisitConsentService::MESSAGE_COUNTERSIGN_DUPLICATE]);

    $this->actingAs($this->doctor)
        ->get(route('rme.consents.countersign.create', $consent))
        ->assertRedirect(route('rme.consents.show', $consent));

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(1)
        ->and(rcdcCountersignObjects())->toBe([$first->signature_path]);
});

it('CDS-18 turns a racing second insert (the UNIQUE backstop) into the same refusal and removes its object', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $raced = false;

    // The competing countersignature lands between the locked check and this
    // call's insert — what the unique index exists for.
    RmeVisitConsentDoctorSignature::creating(function () use ($consent, &$raced): void {
        if ($raced) {
            return;
        }
        $raced = true;
        DB::table('trx_rme_visit_consent_doctor_signatures')->insert([
            'rme_visit_consent_id' => $consent->id,
            'doctor_id' => test()->doctorRecord->id,
            'signed_by' => test()->doctor->id,
            'signature_path' => 'rme-consents/race/doctor-countersign-winner.png',
            'signature_sha256' => str_repeat('b', 64),
            'signed_at' => now(),
            'created_at' => now(),
        ]);
    });

    expect(fn () => rcdcCountersign($consent))
        ->toThrow(ValidationException::class, RmeVisitConsentService::MESSAGE_COUNTERSIGN_DUPLICATE);

    expect($raced)->toBeTrue()
        ->and(rcdcCountersignObjects())->toBe([])
        ->and(AuditLog::query()->where('action', 'RME_CONSENT_DOCTOR_SIGNED')->count())->toBe(0);
});

it('CDS-19 removes only the object this call wrote when the write fails inside the transaction', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    $existing = Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles();

    RmeVisitConsentDoctorSignature::created(function (): void {
        throw new RuntimeException('simulated failure after the insert');
    });

    expect(fn () => rcdcCountersign($consent))->toThrow(RuntimeException::class, 'simulated failure after the insert');

    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(0)
        ->and(rcdcCountersignObjects())->toBe([])
        // The patient's committed signature object is never touched.
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe($existing);
});

it('CDS-20 keeps the countersignature row insert-only', function () {
    $signature = rcdcCountersign(rcdcPatientSigned(rcdcVisit()));
    $hash = $signature->signature_sha256;

    expect(fn () => $signature->forceFill(['signature_sha256' => str_repeat('c', 64)])->save())->toThrow(LogicException::class)
        ->and(fn () => $signature->fresh()->delete())->toThrow(LogicException::class)
        ->and(RmeVisitConsentDoctorSignature::query()->sole()->signature_sha256)->toBe($hash);
});

it('CDS-21 locks the visit row FIRST, before reading the consent, the countersignature or inserting', function () {
    $consent = rcdcPatientSigned(rcdcVisit());

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    rcdcCountersign($consent);

    $begin = array_search('BEGIN', $log, true);
    expect($begin)->not->toBeFalse();

    // The first statement of countersign()'s own transaction is the visit lock.
    $first = $log[$begin + 1] ?? '';
    expect($first)->toContain('"trx_clinic_visits"')->toContain('for no key update');

    $indexOf = function (string $needle) use ($log, $begin): ?int {
        foreach ($log as $i => $sql) {
            if ($i > $begin && $sql !== 'BEGIN' && str_contains($sql, $needle)) {
                return $i;
            }
        }

        return null;
    };

    expect($indexOf('from "trx_rme_visit_consents"'))->toBeGreaterThan($begin + 1)
        ->and($indexOf('from "trx_rme_visit_consent_doctor_signatures"'))->toBeGreaterThan($begin + 1)
        ->and($indexOf('insert into "trx_rme_visit_consent_doctor_signatures"'))->toBeGreaterThan($begin + 1);

    // The same helper the void suite uses, anchored on the consent re-read.
    $second = rcdcPatientSigned(rcdcVisit());

    expect(rcdcVisitLockedBefore(
        fn () => rcdcCountersign($second),
        fn (string $sql): bool => str_contains($sql, 'from "trx_rme_visit_consents"'),
    ))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

/*
| The supervised void is unchanged
*/

it('CDS-22 still voids a countersigned consent exactly as before, keeping the countersignature row', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    $signature = rcdcCountersign($consent);

    app(RmeVisitConsentService::class)->void($consent->fresh(), $this->supervisor, 'Tindakan yang tertulis keliru.');

    expect($consent->fresh()->isVoided())->toBeTrue()
        ->and(RmeVisitConsentDoctorSignature::query()->whereKey($signature->id)->exists())->toBeTrue()
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->exists($signature->signature_path))->toBeTrue();

    // A new consent needs its own countersignature.
    $fresh = rcdcPatientSigned($visit->fresh());

    expect($fresh->fresh()->isDoctorCountersigned())->toBeFalse();

    $second = rcdcCountersign($fresh);

    expect((int) $second->rme_visit_consent_id)->toBe((int) $fresh->id);
});

/*
| Rendering — show, print, old consents
*/

it('CDS-23 prints the doctor countersignature with name and time', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $signature = rcdcCountersign($consent);

    // Rule 243 §1 — a Doctor reads the PTM on screen but never prints it, so the
    // print is rendered for the operator who may print it.
    $html = $this->actingAs($this->operator)
        ->get(route('rme.consents.print', $consent))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-testid="consent-doctor-countersignature"')
        ->and($html)->toContain('data:image/png;base64,')
        ->and($html)->toContain('drg. Penanda Tangan PTM')
        ->and($html)->toContain($signature->signed_at->format('d/m/Y H:i').' WITA')
        ->and($html)->not->toContain('Belum ditandatangani dokter')
        ->and($html)->not->toContain('src=""');
});

it('CDS-24 says "Belum ditandatangani dokter" only while a countersignature is still possible', function () {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);

    // In progress, valid, not countersigned: the doctor may still sign.
    foreach (['rme.consents.print', 'rme.consents.show'] as $route) {
        $html = $this->actingAs($this->operator)->get(route($route, $consent))->assertOk()->getContent();

        expect($html)->toContain('Belum ditandatangani dokter')
            ->and($html)->not->toContain('data-testid="consent-doctor-countersignature"')
            ->and($html)->not->toContain('src=""');
    }

    // Once the examination is over the consent is history: no placeholder, ever.
    foreach ([ClinicVisit::STATUS_CASHIER_PENDING, ClinicVisit::STATUS_COMPLETED, ClinicVisit::STATUS_CANCELLED] as $status) {
        DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => $status]);

        foreach (['rme.consents.print', 'rme.consents.show'] as $route) {
            $html = $this->actingAs($this->operator)->get(route($route, $consent))->assertOk()->getContent();

            expect($html)->not->toContain('Belum ditandatangani dokter', "{$status} {$route}")
                ->and($html)->not->toContain('data-testid="consent-doctor-countersign-state"', "{$status} {$route}")
                ->and($html)->not->toContain('src=""');
        }
    }

    // A voided consent is history too, even while the visit runs.
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => ClinicVisit::STATUS_IN_PROGRESS]);
    app(RmeVisitConsentService::class)->void($consent->fresh(), $this->supervisor, 'Salah pemberi persetujuan.');

    $html = $this->actingAs($this->operator)->get(route('rme.consents.print', $consent))->assertOk()->getContent();
    expect($html)->not->toContain('Belum ditandatangani dokter');
});

it('CDS-24b renders a historical consent byte for byte as the document did before rule 241', function (bool $legacyDrawing) {
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    if ($legacyDrawing) {
        $consent = rcdcWithLegacyDrawing($consent);
    }
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    $consent = $consent->fresh();
    $consent->loadMissing(['clinicVisit.branch', 'patient', 'doctor', 'signedBy', 'voidedBy']);

    $service = app(RmeVisitConsentService::class);
    $before = [
        'consent' => $consent,
        'branch' => $consent->clinicVisit?->branch,
        'relationships' => (array) config('rme_consent.relationships', []),
        'genderLabels' => (array) config('rme_consent.gender_labels', []),
        'consenterSignature' => $service->signatureDataUri($consent->consenter_signature_path),
        'doctorSignature' => $service->signatureDataUri($consent->doctor_signature_path),
    ];

    // The partial exactly as it was at 82cb6e13, before rule 241.
    $old = Blade::render((string) file_get_contents(base_path('tests/Fixtures/rme-consent-document-pre-rule-241.blade.php.txt')), $before);

    // What the controller hands the partial now, for the same consent.
    $html = $this->actingAs($this->operator)->get(route('rme.consents.print', $consent))->assertOk()->getContent();
    $start = strpos($html, '<div class="consent-document">');
    $end = strrpos($html, '</div>');
    $current = substr($html, $start, $end - $start + strlen('</div>'));
    $oldStart = strpos($old, '<div class="consent-document">');
    $oldEnd = strrpos($old, '</div>');

    expect($start)->not->toBeFalse()
        ->and($current)->toBe(substr($old, $oldStart, $oldEnd - $oldStart + strlen('</div>')));
})->with(['without the legacy drawing' => [false], 'with the legacy drawing' => [true]]);

it('CDS-25 keeps the legacy optional doctor drawing as display history that never counts as the countersignature', function () {
    $consent = rcdcWithLegacyDrawing(rcdcPatientSigned(rcdcVisit()));

    $html = $this->actingAs($this->operator)->get(route('rme.consents.print', $consent))->assertOk()->getContent();

    expect($html)->toContain('alt="Tanda tangan dokter"')
        ->and($consent->fresh()->isDoctorCountersigned())->toBeFalse()
        ->and(app(RmeVisitConsentService::class)->canCountersign($consent->fresh(), $this->doctor))->toBeTrue();

    // After the countersignature both stay visible, labelled apart.
    rcdcCountersign($consent);

    $after = $this->actingAs($this->operator)->get(route('rme.consents.print', $consent))->assertOk()->getContent();

    expect($after)->toContain('alt="Tanda tangan dokter"')
        ->and($after)->toContain('data-testid="consent-doctor-countersignature"')
        ->and($after)->toContain('Tanda tangan dokter (PTM)')
        ->and($after)->not->toContain('Belum ditandatangani dokter');
});

it('CDS-25b adds no per-route constraint to the governed {consent} key (AIR-RMC-37)', function () {
    $routes = (string) file_get_contents(base_path('routes/web.php'));
    preg_match_all("/Route::(?:get|post)\\('consents\\/\\{consent\\}\\/countersign'.*?->name\\('consents\\.countersign(?:\\.create)?'\\);/s", $routes, $blocks);

    expect($blocks[0])->toHaveCount(2);

    foreach ($blocks[0] as $block) {
        expect($block)->not->toContain("->where('consent'");
    }
});

it('CDS-26 says so honestly when a countersignature object is missing', function () {
    $consent = rcdcPatientSigned(rcdcVisit());
    $signature = rcdcCountersign($consent);
    Storage::disk(ClinicalEvidenceStorage::diskName())->delete($signature->signature_path);

    $html = $this->actingAs($this->operator)->get(route('rme.consents.print', $consent))->assertOk()->getContent(); // rule 243 §1

    expect($html)->toContain('Gambar tanda tangan dokter tidak tersedia.')
        ->and($html)->toContain($signature->signed_at->format('d/m/Y H:i').' WITA')
        ->and($html)->not->toContain('src=""');
});

it('CDS-27 offers "Tanda Tangani PTM" on the consent page only to the treating doctor', function () {
    $consent = rcdcPatientSigned(rcdcVisit());

    $this->actingAs($this->doctor)->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertSee(route('rme.consents.countersign.create', $consent), false);

    $this->actingAs(userInRole('Super Admin')->fresh())->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertDontSee(route('rme.consents.countersign.create', $consent), false);

    $this->actingAs($this->operator)->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertDontSee(route('rme.consents.countersign.create', $consent), false);

    rcdcCountersign($consent);

    $this->actingAs($this->doctor)->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertDontSee(route('rme.consents.countersign.create', $consent), false)
        ->assertSee('Ditandatangani dokter');
});

/*
| Ruang Perawatan — the doctor's entry point
*/

it('CDS-28 lists "Tanda Tangani PTM" on Ruang Perawatan for the doctor\'s own patient-signed, uncountersigned visits only', function () {
    $mine = rcdcVisit();
    $consent = rcdcPatientSigned($mine);
    $unsigned = rcdcVisit();

    $html = $this->actingAs($this->doctor)
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'data-testid="worklist-countersign-ptm"'))->toBe(1)
        ->and($html)->toContain(route('rme.consents.countersign.create', $consent));

    // A Perawat working the same room is not offered the doctor's step.
    $nurse = rcdcRoleUser('Perawat');
    $nurse->givePermissionTo('view_treatment_worklist');
    $nurseHtml = $this->actingAs($nurse->fresh())->get(route('rme.treatment-room-worklist.index'))->getContent();
    expect($nurseHtml)->not->toContain('data-testid="worklist-countersign-ptm"');

    rcdcCountersign($consent);

    $after = $this->actingAs($this->doctor)->get(route('rme.treatment-room-worklist.index'))->assertOk()->getContent();

    expect($after)->not->toContain('data-testid="worklist-countersign-ptm"')
        ->and($unsigned->exists)->toBeTrue();
});

/*
| No authoring gate changes in this PR
*/

it('CDS-29 makes the countersignature the step that opens the authoring gates (rule 241 §2, PR2)', function () {
    // FLIPPED by PR2: was 'CDS-29 leaves every authoring gate on the patient
    // signature alone' (PR1 changed no gate). Owner decision 2026-11-17: both
    // signatures gate writing. The full matrix is in
    // RmeVisitConsentTwoSignatureGateTest and RmeOdontogramTwoSignatureGateTest.
    $visit = rcdcVisit();
    $consent = rcdcPatientSigned($visit);
    $service = app(RmeVisitConsentService::class);

    // Patient-signed, not countersigned: a valid consent, but no write.
    expect($service->hasValidConsent($visit))->toBeTrue()
        ->and($service->hasExecutedConsent($visit))->toBeFalse()
        ->and($service->canAuthorRmeForPatient((int) $visit->patient_id, $this->doctor))->toBeFalse()
        ->and($service->canAuthorOdontogramFor($visit, $this->doctor))->toBeFalse();

    rcdcCountersign($consent);

    $encounter = DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor));

    expect((int) $encounter->id)->toBe((int) $visit->id)
        ->and($service->hasExecutedConsent($visit))->toBeTrue()
        ->and($service->canAuthorRmeForPatient((int) $visit->patient_id, $this->doctor))->toBeTrue()
        ->and($service->canAuthorOdontogramFor($visit, $this->doctor))->toBeTrue();

    // Pre-consent odontogram charting has ended.
    $preConsent = rcdcVisit();
    expect($service->canAuthorOdontogramFor($preConsent, $this->doctor))->toBeFalse();
});
