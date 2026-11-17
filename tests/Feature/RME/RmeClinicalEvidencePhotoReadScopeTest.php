<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — clinical photos: READ scope.
|--------------------------------------------------------------------------
|
| A clinical photo is read only through the policy-gated image route (rule 121:
| no public URL, the object key never leaves the server), `private, no-store`,
| `nosniff`, and every successful view is audited with ids only.
|
| Who reads: the RME clinical-document read scope (rule 227 §1:
| RmeWorkingBranchScope::allowsClinicalDocument) narrowed to the clinical roles
| and Owner, plus the doctor-patient scope (DoctorPatientScopeService, which is
| also where the rule 243 §2 encounter scope applies when its flag is ON). Never
| Front Office, Kasir or Admin Klinik, even with a runtime grant. Out of scope
| answers 404. The service re-asserts all of it, so Super Admin is bound too.
| Reads are never consent-gated (AIR-RMC-09). With the flag OFF nothing reads.
|
| Selected by the existing `ClinicalEvidence` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcpr` is unique because the critical gate loads every suite into one process.
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
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const RCPR_FLAG = 'rme.clinical_photos';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'CPR1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Baca Foto']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->perawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $this->branch))->fresh();

    rcprFlag(true);
});

function rcprFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[RCPR_FLAG]['default'] = $on;
    $flags[RCPR_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function rcprJpeg(int $width = 200, int $height = 150): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 90, 160, 200));
    ob_start();
    imagejpeg($image, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** An executed-PTM encounter (documentation YA) through the real services. */
function rcprEncounter(?Patient $patient = null): ClinicVisit
{
    $patient ??= Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Baca Rahasia']);
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
        'medical_action' => 'Scaling',
        'treatment_summary' => 'Scaling',
        'documentation_consent' => '1',
        'consenter_signature' => validPodSignatureData(),
    ]);
    $consents->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);

    return $visit->fresh();
}

function rcprPhoto(?ClinicVisit $visit = null): RmeClinicalPhoto
{
    $visit ??= rcprEncounter();

    return app(RmeClinicalPhotoService::class)->upload($visit->fresh(), test()->doctor, UploadedFile::fake()->createWithContent('foto.jpg', rcprJpeg()));
}

function rcprGet(User $user, RmeClinicalPhoto|string $photo)
{
    $key = $photo instanceof RmeClinicalPhoto ? (string) $photo->id : $photo;

    return test()->actingAs($user)->get('/rme/clinical-photos/'.$key.'/image');
}

function rcprViews(): int
{
    return AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VIEWED)->count();
}

it('CPR-01 with the flag OFF nobody reads, Super Admin included, and the service lists nothing', function () {
    $photo = rcprPhoto();
    $visit = $photo->visit;
    rcprFlag(false);

    $readers = [$this->doctor, $this->perawat, userInRole('Supervisor RME'), userInRole('Super Admin')];

    foreach ($readers as $reader) {
        rcprGet($reader, $photo)->assertNotFound();
        expect(app(RmeClinicalPhotoService::class)->visibleForVisit($visit, $reader))->toHaveCount(0);
    }

    expect(fn () => app(RmeClinicalPhotoService::class)->imageFor($photo, $this->doctor))->toThrow(NotFoundHttpException::class)
        ->and(rcprViews())->toBe(0);

    // The flag is the guard.
    rcprFlag(true);
    rcprGet($this->doctor, $photo)->assertOk();
});

it('CPR-02 clinical readers get the image privately: no-store, nosniff, inline, never the object key; each view audited with ids only', function () {
    $photo = rcprPhoto();
    $key = $photo->getRawOriginal('photo_path');
    $stored = ClinicalEvidenceStorage::disk()->get($key);

    $farBranch = Branch::factory()->create(['code' => 'CPR2', 'is_active' => true, 'is_rme_enabled' => true]);
    $farPerawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $farBranch))->fresh();

    $readers = [
        'treating doctor' => $this->doctor,
        'Perawat at the branch' => $this->perawat,
        'Perawat elsewhere (clinical documents are cross-branch, rule 227 §1)' => $farPerawat,
        'Supervisor RME' => userInRole('Supervisor RME'),
        'Super Admin' => userInRole('Super Admin'),
    ];

    foreach ($readers as $label => $reader) {
        $response = rcprGet($reader, $photo);
        $response->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        expect($response->headers->get('Content-Type'))->toBe('image/jpeg', $label)
            ->and($cache)->toContain('no-store')
            ->and($cache)->toContain('private')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and((string) $response->headers->get('Content-Disposition'))->toContain('inline')
            ->and((string) $response->headers->get('Content-Disposition'))->toContain('foto-klinis-'.$photo->id.'.jpg')
            ->and((string) $response->headers->get('Content-Disposition'))->not->toContain(basename($key))
            ->and($response->getContent())->toBe($stored);
    }

    $logs = AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_VIEWED)->orderBy('id')->get();
    expect($logs)->toHaveCount(count($readers));

    foreach ($logs as $log) {
        expect($log->entity_type)->toBe(RmeClinicalPhotoService::AUDIT_TABLE)
            ->and((int) $log->entity_id)->toBe($photo->id)
            ->and($log->new_values)->toBe(['clinic_visit_id' => $photo->clinic_visit_id])
            ->and(json_encode($log->new_values))->not->toContain($key);
    }
});

it('CPR-03 never Front Office, Kasir or Admin Klinik — 403 without the permission, 404 with a runtime grant', function () {
    $photo = rcprPhoto();

    $kasir = tap(User::factory()->create(), fn (User $u) => rmeMakeKasirActive($u, $this->branch))->fresh();
    $adminKlinik = rmeAdminClinicUser($this->branch);

    foreach ([$this->frontOffice, $kasir, $adminKlinik] as $user) {
        rcprGet($user, $photo)->assertForbidden();

        $user->givePermissionTo(RmeClinicalPhotoService::VIEW_PERMISSION);
        rcprGet($user->fresh(), $photo)->assertNotFound();
        expect(app(RmeClinicalPhotoService::class)->visibleForVisit($photo->visit, $user->fresh()))->toHaveCount(0)
            ->and(app(RmeClinicalPhotoService::class)->mayView($user->fresh(), $photo))->toBeFalse();
    }

    expect(rcprViews())->toBe(0);
});

it('CPR-03b Owner is not seeded the permission; an explicit grant follows the clinical read scope', function () {
    $photo = rcprPhoto();
    $owner = userInRole('Owner');

    rcprGet($owner, $photo)->assertForbidden();

    $owner->givePermissionTo(RmeClinicalPhotoService::VIEW_PERMISSION);
    rcprGet($owner->fresh(), $photo)->assertOk();
});

it('CPR-04 the doctor-patient scope: an unrelated doctor answers 404 until they treat the patient', function () {
    $photo = rcprPhoto();
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $otherRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create();
    $other = rmeMakeDoctorOnline($otherRecord, $this->branch, $otherRoom)->fresh();

    rcprGet($other, $photo)->assertNotFound();
    expect(app(RmeClinicalPhotoService::class)->visibleForVisit($photo->visit, $other))->toHaveCount(0);

    // The scope is the guard: once the doctor has treated the patient, they read.
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $photo->patient_id,
        'doctor_id' => $otherRecord->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    rcprGet($other, $photo)->assertOk();
});

it('CPR-04b the read delegates to DoctorPatientScopeService::doctorCanAccessVisit (where the rule 243 §2 encounter scope applies)', function () {
    $photo = rcprPhoto();

    $scope = Mockery::mock(DoctorPatientScopeService::class)->makePartial();
    $scope->shouldReceive('doctorCanAccessVisit')->andReturn(false);
    app()->instance(DoctorPatientScopeService::class, $scope);

    rcprGet($this->doctor, $photo)->assertNotFound();
    expect(app(RmeClinicalPhotoService::class)->mayView($this->doctor, $photo))->toBeFalse();
});

it('CPR-05 branch scope binds every reader, Super Admin included: a branch outside the RME set reads nothing', function () {
    $photo = rcprPhoto();
    $superAdmin = userInRole('Super Admin');
    $supervisor = userInRole('Supervisor RME');

    $this->branch->forceFill(['is_rme_enabled' => false])->save();

    // Accounts that need no online context reach the route; the doctor's own
    // workspace is closed by its context middleware first, so it is asked here.
    foreach ([$supervisor, $superAdmin] as $reader) {
        rcprGet($reader, $photo)->assertNotFound();
    }

    expect(app(RmeClinicalPhotoService::class)->mayView($this->doctor, $photo->fresh()))->toBeFalse()
        ->and(fn () => app(RmeClinicalPhotoService::class)->imageFor($photo->fresh(), $superAdmin))->toThrow(NotFoundHttpException::class)
        ->and(rcprViews())->toBe(0);

    $this->branch->forceFill(['is_rme_enabled' => true])->save();
    rcprGet($superAdmin, $photo)->assertOk();
});

it('CPR-06 a non-canonical photo id answers 404, never a 500', function () {
    $photo = rcprPhoto();
    $errors = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$errors): void {
        if (in_array($e->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $errors[] = $e->message;
        }
    });

    foreach (['abc', '0'.$photo->id, '99999999999999999999', '-1', '1.0'] as $key) {
        rcprGet(userInRole('Super Admin'), $key)->assertNotFound();
    }

    expect($errors)->toBe([]);
});

it('CPR-07 a missing or damaged object is an honest 404, not an empty image', function () {
    $photo = rcprPhoto();
    $key = $photo->getRawOriginal('photo_path');

    ClinicalEvidenceStorage::disk()->put($key, '<html>not a jpeg</html>');
    rcprGet($this->doctor, $photo)->assertNotFound();

    ClinicalEvidenceStorage::disk()->delete($key);
    rcprGet($this->doctor, $photo)->assertNotFound();

    expect(rcprViews())->toBe(0);
});

it('CPR-08 lists a visit\'s photos in a constant number of queries, uploader eager-loaded, without the object key', function () {
    $visit = rcprEncounter();
    rcprPhoto($visit);

    $count = function () use ($visit): int {
        $service = app(RmeClinicalPhotoService::class);
        $fresh = $visit->fresh();
        $doctor = $this->doctor->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $photos = $service->visibleForVisit($fresh, $doctor);
        $photos->each(fn (RmeClinicalPhoto $p) => $p->uploader?->name);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    };

    $count(); // warm the permission and branch caches
    $one = $count();

    foreach (range(1, 5) as $_) {
        rcprPhoto($visit);
    }

    $six = $count();
    $photos = app(RmeClinicalPhotoService::class)->visibleForVisit($visit->fresh(), $this->doctor->fresh());

    expect($photos)->toHaveCount(6)
        ->and($six)->toBe($one)
        ->and($photos->every(fn (RmeClinicalPhoto $p) => $p->relationLoaded('uploader')))->toBeTrue()
        ->and(json_encode($photos->toArray()))->not->toContain(RmeClinicalPhotoService::DIRECTORY.'/');
});

it('CPR-09 reads are never consent-gated: a voided consent or a finished visit leaves the photos readable', function () {
    $photo = rcprPhoto();
    $consent = RmeVisitConsent::query()->where('clinic_visit_id', $photo->clinic_visit_id)->sole();
    $supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);

    app(RmeVisitConsentService::class)->void($consent, $supervisor, 'Dibatalkan untuk diuji ulang.');
    rcprGet($this->doctor, $photo)->assertOk();

    $photo->visit->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();
    rcprGet($this->doctor, $photo)->assertOk();
    expect(app(RmeClinicalPhotoService::class)->visibleForVisit($photo->visit->fresh(), $this->doctor))->toHaveCount(1);
});
