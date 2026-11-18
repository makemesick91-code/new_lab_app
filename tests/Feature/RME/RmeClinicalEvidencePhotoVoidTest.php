<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M4.4 / M1.x, PTM PR4b (rule 242 §9) — clinical photos: VOID.
|--------------------------------------------------------------------------
|
| Committed clinical evidence is never deleted (rule 224 §4). A wrong photo is
| corrected by a supervised void: its own permission (`void_rme_clinical_photos`,
| Supervisor RME; Super Admin through `*`), a written reason of 10–1000
| characters, maker ≠ checker (never the account that uploaded it — this binds
| Super Admin), only on a photo the voider may read (so never Front Office,
| Kasir or Admin Klinik), once. The visit row is locked first, then the photo
| row; the object stays; the void is audited with ids only (never the reason).
| Not consent-gated and not limited to the live encounter: a wrong-patient photo
| found after the visit finished is still corrected.
|
| Selected by the existing `ClinicalEvidence` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcpv` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const RCPV_FLAG = 'rme.clinical_photos';
const RCPV_REASON = 'Foto tertukar dengan pasien lain pada unggahan ini.';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'CPV1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Batal Foto']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->supervisor = userInRole('Supervisor RME');

    rcpvFlag(true);
});

function rcpvFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[RCPV_FLAG]['default'] = $on;
    $flags[RCPV_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function rcpvJpeg(): string
{
    $image = imagecreatetruecolor(160, 120);
    imagefilledrectangle($image, 0, 0, 159, 119, imagecolorallocate($image, 30, 140, 70));
    ob_start();
    imagejpeg($image, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A photo uploaded by $uploader (default: the treating doctor) on an executed-PTM encounter. */
function rcpvPhoto(?User $uploader = null): RmeClinicalPhoto
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Batal Rahasia']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ])->refresh();

    $consents = app(RmeVisitConsentService::class);
    $consent = $consents->sign($visit, test()->frontOffice, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan',
        'treatment_summary' => 'Pencabutan',
        'documentation_consent' => '1',
        'consenter_signature' => validPodSignatureData(),
    ]);
    $consents->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);

    return app(RmeClinicalPhotoService::class)->upload($visit->fresh(), $uploader ?? test()->doctor, UploadedFile::fake()->createWithContent('foto.jpg', rcpvJpeg()));
}

function rcpvPost(User $user, RmeClinicalPhoto|string $photo, ?string $reason = RCPV_REASON)
{
    $key = $photo instanceof RmeClinicalPhoto ? (string) $photo->id : $photo;

    return test()->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->post('/rme/clinical-photos/'.$key.'/void', $reason === null ? [] : ['void_reason' => $reason]);
}

function rcpvRefusal(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return [ValidationException::class, collect($e->errors())->flatten()->first()];
    } catch (AuthorizationException $e) {
        return [AuthorizationException::class, $e->getMessage()];
    } catch (NotFoundHttpException) {
        return [NotFoundHttpException::class, ''];
    }

    return ['none', ''];
}

/** The evidence a void must never touch: the row's identity columns and the object's bytes. */
function rcpvEvidence(RmeClinicalPhoto $photo): array
{
    $row = RmeClinicalPhoto::query()->findOrFail($photo->id);
    $key = $row->getRawOriginal('photo_path');

    return [
        'row' => collect($row->getAttributes())->except(['voided_at', 'voided_by', 'void_reason', 'updated_at'])->all(),
        'bytes' => hash('sha256', (string) ClinicalEvidenceStorage::disk()->get($key)),
    ];
}

it('CPV-01 with the flag OFF the void route answers 404 to every permission holder and the service refuses', function () {
    $photo = rcpvPhoto();
    rcpvFlag(false);

    foreach ([$this->supervisor, userInRole('Super Admin')] as $user) {
        rcpvPost($user, $photo)->assertNotFound();
    }

    expect(rcpvRefusal(fn () => app(RmeClinicalPhotoService::class)->void($photo, $this->supervisor, RCPV_REASON)))
        ->toBe([NotFoundHttpException::class, ''])
        ->and($photo->fresh()->voided_at)->toBeNull();

    rcpvFlag(true);
    rcpvPost($this->supervisor, $photo)->assertOk();
});

it('CPV-02 a Supervisor RME voids with a reason: the row is marked, the object and every evidence column stay, the audit carries ids only', function () {
    $photo = rcpvPhoto();
    $before = rcpvEvidence($photo);

    rcpvPost($this->supervisor, $photo)->assertOk();

    $row = $photo->fresh();
    expect($row->isVoided())->toBeTrue()
        ->and($row->voided_by)->toBe($this->supervisor->id)
        ->and($row->void_reason)->toBe(RCPV_REASON)
        // Free text: readable as an attribute, never serialised with the model.
        ->and(array_key_exists('void_reason', $row->toArray()))->toBeFalse()
        ->and(json_encode($row))->not->toContain('tertukar')
        ->and(rcpvEvidence($photo))->toBe($before)
        ->and(RmeClinicalPhoto::query()->count())->toBe(1);

    $log = AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VOIDED)->sole();
    expect($log->entity_type)->toBe(RmeClinicalPhotoService::AUDIT_TABLE)
        ->and((int) $log->entity_id)->toBe($photo->id)
        ->and((int) $log->performed_by)->toBe($this->supervisor->id)
        ->and($log->new_values)->toBe(['clinic_visit_id' => $photo->clinic_visit_id])
        ->and(json_encode([$log->old_values, $log->new_values]))->not->toContain('tertukar');
});

it('CPV-03 refuses a reason under 10 or over 1000 characters, or none, and changes nothing', function (?string $reason) {
    $photo = rcpvPhoto();

    rcpvPost($this->supervisor, $photo, $reason)->assertStatus(422)->assertJsonValidationErrors('void_reason');

    if ($reason !== null) {
        expect(rcpvRefusal(fn () => app(RmeClinicalPhotoService::class)->void($photo, $this->supervisor, $reason))[0])
            ->toBe(ValidationException::class);
    }

    expect($photo->fresh()->voided_at)->toBeNull()
        ->and(AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VOIDED)->count())->toBe(0);
})->with([
    'none' => [null],
    'blank' => ['          '],
    'nine characters' => ['Salah ft.'],
    'over 1000 characters' => [str_repeat('a', 1001)],
]);

it('CPV-04 maker ≠ checker: the account that uploaded the photo never voids it, Super Admin included', function () {
    // An account that is both a Doctor (so it may upload) and a supervisor of the void.
    $uploader = $this->doctor;
    $uploader->givePermissionTo(RmeClinicalPhotoService::VOID_PERMISSION);
    $photo = rcpvPhoto($uploader->fresh());

    rcpvPost($uploader->fresh(), $photo)->assertForbidden();
    expect(rcpvRefusal(fn () => app(RmeClinicalPhotoService::class)->void($photo, $uploader->fresh(), RCPV_REASON)))
        ->toBe([AuthorizationException::class, RmeClinicalPhotoService::MESSAGE_VOID_SELF]);

    // The same rule binds Super Admin, who passes every policy through Gate::before.
    $superAdmin = userInRole('Super Admin');
    $superAdmin->assignRole('Perawat'); // so it may upload (Super Admin needs no online context)
    $own = rcpvPhoto($superAdmin->fresh());

    rcpvPost($superAdmin->fresh(), $own)->assertForbidden();
    expect($photo->fresh()->voided_at)->toBeNull()->and($own->fresh()->voided_at)->toBeNull();

    // The identity is the guard: another account voids both.
    rcpvPost($this->supervisor, $photo)->assertOk();
    rcpvPost(userInRole('Super Admin'), $own)->assertOk();
});

it('CPV-05 who voids: no permission 403; Front Office, Kasir or Admin Klinik even with a grant 404 (they never read a photo)', function () {
    $photo = rcpvPhoto();
    $perawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $this->branch))->fresh();
    $kasir = tap(User::factory()->create(), fn (User $u) => rmeMakeKasirActive($u, $this->branch))->fresh();
    $adminKlinik = rmeAdminClinicUser($this->branch);

    foreach ([$this->doctor, $perawat, $this->frontOffice, $kasir, $adminKlinik] as $user) {
        rcpvPost($user, $photo)->assertForbidden();
    }

    foreach ([$this->frontOffice, $kasir, $adminKlinik] as $user) {
        $user->givePermissionTo(RmeClinicalPhotoService::VOID_PERMISSION);
        rcpvPost($user->fresh(), $photo)->assertNotFound();
        expect(rcpvRefusal(fn () => app(RmeClinicalPhotoService::class)->void($photo->fresh(), $user->fresh(), RCPV_REASON)))
            ->toBe([NotFoundHttpException::class, '']);
    }

    expect($photo->fresh()->voided_at)->toBeNull();
});

it('CPV-06 out of the read scope answers 404 for every voider, Super Admin included', function () {
    $photo = rcpvPhoto();
    $this->branch->forceFill(['is_rme_enabled' => false])->save();

    rcpvPost($this->supervisor, $photo)->assertNotFound();
    rcpvPost(userInRole('Super Admin'), $photo)->assertNotFound();
    rcpvPost($this->supervisor, 'abc')->assertNotFound();
    rcpvPost($this->supervisor, '0'.$photo->id)->assertNotFound();

    expect($photo->fresh()->voided_at)->toBeNull();
});

it('CPV-07 voids once: a second void is refused and the first reason and voider stay', function () {
    $photo = rcpvPhoto();
    rcpvPost($this->supervisor, $photo)->assertOk();
    $first = $photo->fresh();

    $other = userInRole('Supervisor RME');
    rcpvPost($other, $photo, 'Alasan kedua yang berbeda.')->assertStatus(422)
        ->assertJsonPath('errors.photo.0', RmeClinicalPhotoService::MESSAGE_VOID_ALREADY);

    $after = $photo->fresh();
    expect($after->voided_by)->toBe($first->voided_by)
        ->and($after->void_reason)->toBe(RCPV_REASON)
        ->and($after->voided_at->equalTo($first->voided_at))->toBeTrue()
        ->and(AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VOIDED)->count())->toBe(1);
});

it('CPV-08 locks the visit row first, then the photo row, inside the write transaction', function () {
    $photo = rcpvPhoto();
    $statements = [];

    Event::listen(TransactionBeginning::class, function () use (&$statements): void {
        $statements[] = '-- begin';
    });
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    app(RmeClinicalPhotoService::class)->void($photo, $this->supervisor, RCPV_REASON);

    $update = collect($statements)->search(fn (string $sql) => str_starts_with($sql, 'update "trx_rme_clinical_photos"'));
    expect($update)->not->toBeFalse();

    $begin = collect($statements)->take($update)->keys()->filter(fn (int $i) => $statements[$i] === '-- begin')->last();
    $locks = collect(array_slice($statements, $begin + 1, $update - $begin - 1))
        ->filter(fn (string $sql) => str_contains($sql, 'for no key update') || str_contains($sql, 'for update'))
        ->values();

    expect($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('"trx_clinic_visits"')
        ->and($locks[0])->toContain('for no key update')
        ->and($locks[1])->toContain('"trx_rme_clinical_photos"')
        ->and($locks[1])->toContain('for no key update');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'row locks are PostgreSQL');

it('CPV-09 the decision is re-made on the locked row: a void committed after the pre-check refuses the second', function () {
    $photo = rcpvPhoto();
    $stale = RmeClinicalPhoto::query()->findOrFail($photo->id);

    app(RmeClinicalPhotoService::class)->void($photo, $this->supervisor, RCPV_REASON);

    // The stale in-memory copy still reads "not voided"; the locked row decides.
    expect($stale->voided_at)->toBeNull()
        ->and(rcpvRefusal(fn () => app(RmeClinicalPhotoService::class)->void($stale, userInRole('Supervisor RME'), 'Alasan lain yang cukup panjang.')))
        ->toBe([ValidationException::class, RmeClinicalPhotoService::MESSAGE_VOID_ALREADY]);
});

it('CPV-10 never a delete path; the void is not consent- or encounter-gated, and the evidence stays', function () {
    $photo = rcpvPhoto();
    $evidence = rcpvEvidence($photo);

    // A voided consent and a finished visit: the correction still happens.
    $consent = RmeVisitConsent::query()->where('clinic_visit_id', $photo->clinic_visit_id)->sole();
    $consentSupervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
    app(RmeVisitConsentService::class)->void($consent, $consentSupervisor, 'Persetujuan dibatalkan untuk diuji.');
    $photo->visit->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();

    rcpvPost($this->supervisor, $photo)->assertOk();

    $names = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'clinical-photos'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()->values()->all();
    expect($names)->not->toContain('DELETE')
        ->and(fn () => $photo->fresh()->delete())->toThrow(LogicException::class)
        ->and(RmeClinicalPhoto::query()->count())->toBe(1)
        ->and(rcpvEvidence($photo))->toBe($evidence);
});

/** Two photos on one visit (like for like); the second voided by the supervisor. */
function rcpvLiveAndVoided(): array
{
    $live = rcpvPhoto();
    $visit = $live->visit;
    $voided = app(RmeClinicalPhotoService::class)->upload($visit->fresh(), test()->doctor, UploadedFile::fake()->createWithContent('foto.jpg', rcpvJpeg()));
    rcpvPost(test()->supervisor, $voided)->assertOk();

    return [$visit->fresh(), $live, $voided->fresh()];
}

/** @return array<string, User> readers in scope WITHOUT the void permission */
function rcpvPlainReaders(): array
{
    return [
        'treating doctor' => test()->doctor,
        'Perawat' => tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, test()->branch))->fresh(),
        'Owner with a view grant' => tap(userInRole('Owner'), fn (User $u) => $u->givePermissionTo(RmeClinicalPhotoService::VIEW_PERMISSION))->fresh(),
    ];
}

it('CPV-11a hide (owner, 2026-11-18): a voided photo leaves the normal gallery for every reader, the voider included', function () {
    [$visit, $live] = rcpvLiveAndVoided();
    $service = app(RmeClinicalPhotoService::class);

    foreach (rcpvPlainReaders() + ['Supervisor RME' => $this->supervisor, 'Super Admin' => userInRole('Super Admin')] as $label => $reader) {
        expect($service->visibleForVisit($visit, $reader)->pluck('id')->all())->toBe([$live->id], $label);
    }

    expect(RmeClinicalPhoto::query()->count())->toBe(2);
});

it('CPV-11b restrict: only a void-permission holder gets a voided photo\'s image, each view audited with ids only; anyone else 404', function () {
    [$visit, $live, $voided] = rcpvLiveAndVoided();
    $service = app(RmeClinicalPhotoService::class);

    foreach (rcpvPlainReaders() as $label => $reader) {
        test()->actingAs($reader)->get('/rme/clinical-photos/999999999/image')->assertNotFound();
        test()->actingAs($reader)->get('/rme/clinical-photos/'.$voided->id.'/image')->assertNotFound();
        expect($service->mayView($reader, $voided))->toBeFalse($label)
            ->and(fn () => $service->imageFor($voided, $reader))->toThrow(NotFoundHttpException::class);
        // The live photo is unaffected.
        test()->actingAs($reader)->get('/rme/clinical-photos/'.$live->id.'/image')->assertOk();
    }
    expect(AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VOIDED_VIEWED)->count())->toBe(0);

    $superAdmin = userInRole('Super Admin');
    foreach ([$this->supervisor, $superAdmin] as $holder) {
        $response = test()->actingAs($holder)->get('/rme/clinical-photos/'.$voided->id.'/image');
        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }

    $logs = AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VOIDED_VIEWED)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('performed_by')->map(fn ($id) => (int) $id)->all())->toBe([$this->supervisor->id, $superAdmin->id])
        ->and($logs->every(fn (AuditLog $log) => $log->new_values === ['clinic_visit_id' => $visit->id] && (int) $log->entity_id === $voided->id))->toBeTrue()
        ->and(AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VIEWED)->where('entity_id', $voided->id)->count())->toBe(0)
        ->and(RmeClinicalPhoto::query()->count())->toBe(2)
        ->and(ClinicalEvidenceStorage::disk()->exists($voided->getRawOriginal('photo_path')))->toBeTrue();
});

it('CPV-11c the separate "dibatalkan" list for PR5: void-permission holders only, uploader and voider loaded', function () {
    [$visit, , $voided] = rcpvLiveAndVoided();
    $service = app(RmeClinicalPhotoService::class);

    foreach (rcpvPlainReaders() as $label => $reader) {
        expect($service->mayViewVoided($reader))->toBeFalse($label)
            ->and($service->voidedForVisit($visit, $reader))->toHaveCount(0);
    }

    foreach ([$this->supervisor, userInRole('Super Admin')] as $holder) {
        $list = $service->voidedForVisit($visit, $holder);
        expect($service->mayViewVoided($holder))->toBeTrue()
            ->and($list->pluck('id')->all())->toBe([$voided->id])
            ->and($list->first()->relationLoaded('voider'))->toBeTrue()
            ->and($list->first()->relationLoaded('uploader'))->toBeTrue()
            ->and(array_key_exists('void_reason', $list->first()->toArray()))->toBeFalse();
    }

    // Out of the read scope, even a holder lists nothing.
    $this->branch->forceFill(['is_rme_enabled' => false])->save();
    expect($service->voidedForVisit($visit->fresh(), $this->supervisor))->toHaveCount(0);
});
