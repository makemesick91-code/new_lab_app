<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — clinical photos: UPLOAD.
|--------------------------------------------------------------------------
|
| Owner decisions of 2026-11-17 (binding, CG-22): plan defaults accepted, and the
| photos flag stays OFF. A clinical photo is clinical evidence: a JPEG on the
| private clinical evidence disk (rule 121), row + object one unit (rule 224).
|
|  - Flag `rme.clinical_photos` (high, default OFF): with it off every route
|    answers 404 to an account that holds its permission, and the service refuses.
|  - Upload: Doctor or Perawat only (an allowlist in the service, so it binds
|    Super Admin), on the LIVE encounter they may work on, with an EXECUTED PTM
|    (rule 241 §2) whose clinical-documentation answer is YA (2026.1: the combined
|    clause 8; v2026.2: clause 8). Clause 9 (publication) never gates or permits.
|  - Validation before any write: JPEG/PNG sniffed from the bytes, size and pixel
|    bounds read from the header before decoding, re-encoded as JPEG (EXIF/GPS
|    stripped, orientation applied, long edge ≤ 2560); a fresh random key.
|  - Visit row locked FIRST, the decision re-made on it; a failure removes only
|    this call's object; audit ids only.
|
| State is built through the services (sign, countersign, void), never a fixture.
| Selected by the existing `ClinicalEvidence` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcpu` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicalPhoto\Support\ClinicalPhotoNormalizer;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const RCPU_FLAG = 'rme.clinical_photos';
const RCPU_TEMPLATE_FLAG = 'rme.consent_template_v2026_2';
const RCPU_CODE = 'PERSETUJUAN_TINDAKAN_MEDIS';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'CPU1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Foto Klinis']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->perawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $this->branch))->fresh();

    // Never inherit an environment override; the photos capability is armed per test.
    rcpuFlag(RCPU_FLAG, true);
    rcpuFlag(RCPU_TEMPLATE_FLAG, false);
});

function rcpuFlag(string $key, bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[$key]['default'] = $on;
    $flags[$key]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

/** TEST-ONLY stand-in for approved v2026.2 wording (never the clinic's text). */
function rcpuArmTemplateV2026_2(): void
{
    rcpuFlag(RCPU_TEMPLATE_FLAG, true);
    $candidate = config('rme_consent.candidate_templates.'.RCPU_CODE);
    $candidate['wording_status'] = 'approved';
    $candidate['clauses'] = array_replace($candidate['clauses'], [
        8 => 'Klausul uji 8: dokumentasi klinis (YA/TIDAK).',
        9 => 'Klausul uji 9: publikasi/promosi (YA/TIDAK).',
    ]);
    config()->set('rme_consent.candidate_templates.'.RCPU_CODE, $candidate);
}

function rcpuVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS, ?Patient $patient = null): ClinicVisit
{
    $patient ??= Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Foto Rahasia']);

    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => $status,
    ])->refresh();
}

/** The patient's consent through the real service (front office), never a factory row. */
function rcpuPatientSigns(ClinicVisit $visit, bool $documentation, ?bool $publication = null): RmeVisitConsent
{
    $payload = [
        'template_code' => RCPU_CODE,
        'consenter_relationship' => 'self',
        'medical_action' => 'Penambalan gigi 36',
        'treatment_summary' => 'Penambalan',
        'documentation_consent' => $documentation ? '1' : '0',
        'consenter_signature' => validPodSignatureData(),
    ];

    if ($publication !== null) {
        $payload['publication_consent'] = $publication ? '1' : '0';
    }

    return app(RmeVisitConsentService::class)->sign($visit, test()->frontOffice, $payload);
}

function rcpuDoctorSigns(RmeVisitConsent $consent): void
{
    app(RmeVisitConsentService::class)->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);
}

/** An in-progress visit whose PTM is EXECUTED with the given clause-8 answer. */
function rcpuEncounter(bool $documentation = true, ?bool $publication = null): ClinicVisit
{
    $visit = rcpuVisit();
    rcpuDoctorSigns(rcpuPatientSigns($visit, $documentation, $publication));

    return $visit->fresh();
}

/** A real JPEG with an asymmetric mark (so a rotation is observable). */
function rcpuJpeg(int $width = 320, int $height = 200, int $quality = 90): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 120, 90));
    imagefilledrectangle($image, 0, 0, intdiv($width, 3), intdiv($height, 4), imagecolorallocate($image, 10, 20, 250));
    ob_start();
    imagejpeg($image, null, $quality);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A PNG with transparency. */
function rcpuPng(int $width = 120, int $height = 90): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagealphablending($image, false);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 10, 10, 50, 50, imagecolorallocatealpha($image, 0, 128, 0, 0));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/**
 * A JPEG carrying an EXIF APP1 segment: Orientation, an ImageDescription marker
 * and a GPS IFD (latitude S 5° 8'), hand-built little-endian TIFF.
 */
function rcpuJpegWithExif(int $width, int $height, int $orientation, string $marker = 'RCPU-SECRET-LOCATION'): string
{
    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

    $description = $marker."\0";
    // Word-aligned, as TIFF wants every offset.
    $descriptionBlock = str_pad($description, strlen($description) + strlen($description) % 2, "\0");
    // IFD0 at 8: three entries (ImageDescription, Orientation, GPS pointer), sorted by tag.
    $ifd0Size = 2 + 3 * 12 + 4;
    $descriptionOffset = 8 + $ifd0Size;
    $gpsOffset = $descriptionOffset + strlen($descriptionBlock);
    $gpsSize = 2 + 2 * 12 + 4;
    $latitudeOffset = $gpsOffset + $gpsSize;

    $tiff = 'II'.pack('vV', 42, 8)
        .pack('v', 3)
        .$entry(0x010E, 2, strlen($description), pack('V', $descriptionOffset))
        .$entry(0x0112, 3, 1, pack('v', $orientation))
        .$entry(0x8825, 4, 1, pack('V', $gpsOffset))
        .pack('V', 0)
        .$descriptionBlock
        .pack('v', 2)
        .$entry(0x0001, 2, 2, "S\0")
        .$entry(0x0002, 5, 3, pack('V', $latitudeOffset))
        .pack('V', 0)
        .pack('V6', 5, 1, 8, 1, 0, 1);

    $payload = "Exif\0\0".$tiff;
    $jpeg = rcpuJpeg($width, $height);

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/** EXIF of some bytes, read the way an attacker would. */
function rcpuExif(string $bytes): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);
    $exif = @exif_read_data($stream);
    fclose($stream);

    return is_array($exif) ? $exif : [];
}

function rcpuUpload(string $bytes, string $name = 'foto.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function rcpuPost(User $user, ClinicVisit $visit, string $bytes, string $name = 'foto.jpg')
{
    return test()->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->post(route('rme.visits.clinical-photos.store', $visit), ['photo' => rcpuUpload($bytes, $name)]);
}

/** Every object on the clinical disk. */
function rcpuObjects(): array
{
    return ClinicalEvidenceStorage::disk()->allFiles(RmeClinicalPhotoService::DIRECTORY);
}

/** The service refusal of $call as [class, first message]. */
function rcpuRefusal(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return [ValidationException::class, collect($e->errors())->flatten()->first()];
    } catch (AuthorizationException $e) {
        return [AuthorizationException::class, $e->getMessage()];
    } catch (NotFoundHttpException $e) {
        return [NotFoundHttpException::class, ''];
    }

    return ['none', ''];
}

function rcpuServiceUpload(ClinicVisit $visit, ?User $actor = null, ?string $bytes = null): RmeClinicalPhoto
{
    return app(RmeClinicalPhotoService::class)->upload($visit->fresh(), $actor ?? test()->doctor, rcpuUpload($bytes ?? rcpuJpeg()));
}

/*
|--------------------------------------------------------------------------
| The flag — ships OFF, and OFF means not reachable
|--------------------------------------------------------------------------
*/

it('CPU-01 ships the flag high-risk, default OFF, env-captured, and the routes behind their own permissions', function () {
    $shipped = (require config_path('feature_flags.php'))['flags'][RCPU_FLAG];

    expect($shipped['default'])->toBeFalse()
        ->and($shipped['risk_level'])->toBe('high')
        ->and($shipped['env_key'])->toBe('FEATURE_RME_CLINICAL_PHOTOS')
        ->and(array_key_exists('env_value', $shipped))->toBeTrue()
        ->and(RmeClinicalPhotoService::FLAG)->toBe(RCPU_FLAG);

    // A fresh registry with no override reads OFF.
    $flags = config('feature_flags.flags');
    $flags[RCPU_FLAG] = array_merge($shipped, ['env_value' => null]);
    config()->set('feature_flags.flags', $flags);
    expect(app(FeatureFlagService::class)->enabled(RCPU_FLAG))->toBeFalse();

    $store = app('router')->getRoutes()->getByName('rme.visits.clinical-photos.store');
    expect($store->gatherMiddleware())->toContain('permission:upload_rme_clinical_photos')
        ->and($store->gatherMiddleware())->toContain('visit.room');
});

it('CPU-01b with the flag OFF the upload route answers 404 to every permission holder and the service refuses; nothing is written', function () {
    $visit = rcpuEncounter();
    rcpuFlag(RCPU_FLAG, false);

    $superAdmin = userInRole('Super Admin');

    foreach ([$this->doctor, $this->perawat, $superAdmin] as $user) {
        rcpuPost($user, $visit, rcpuJpeg())->assertNotFound();
    }

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))->toBe([NotFoundHttpException::class, ''])
        ->and(app(RmeClinicalPhotoService::class)->uploadRefusal($visit, $this->doctor))->not->toBeNull()
        ->and(RmeClinicalPhoto::query()->count())->toBe(0)
        ->and(rcpuObjects())->toBe([]);

    // The guard is the flag: the same request succeeds once it is on.
    rcpuFlag(RCPU_FLAG, true);
    rcpuPost($this->doctor, $visit, rcpuJpeg())->assertCreated();
    expect(RmeClinicalPhoto::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Consent: the documentation answer (clause 8), never clause 9
|--------------------------------------------------------------------------
*/

it('CPU-02 on a 2026.1 consent the combined clause-8 YA permits and TIDAK refuses', function () {
    $yes = rcpuEncounter(documentation: true);
    rcpuPost($this->doctor, $yes, rcpuJpeg())->assertCreated();

    $no = rcpuEncounter(documentation: false);
    expect(RmeVisitConsent::query()->where('clinic_visit_id', $no->id)->value('template_version'))->toBe('2026.1');

    rcpuPost($this->doctor, $no, rcpuJpeg())
        ->assertStatus(422)
        ->assertJsonPath('errors.photo.0', RmeVisitConsentService::MESSAGE_PHOTO_DOCUMENTATION_REFUSED);

    expect(RmeClinicalPhoto::query()->pluck('clinic_visit_id')->all())->toBe([$yes->id])
        ->and(rcpuObjects())->toHaveCount(1);
});

it('CPU-03 on v2026.2 clause 8 alone decides: clause 9 neither gates nor permits', function () {
    rcpuArmTemplateV2026_2();

    $cases = [
        'clause 8 YA, clause 9 TIDAK' => [true, false, true],
        'clause 8 YA, clause 9 YA' => [true, true, true],
        'clause 8 TIDAK, clause 9 YA' => [false, true, false],
        'clause 8 TIDAK, clause 9 TIDAK' => [false, false, false],
    ];

    foreach ($cases as $label => [$documentation, $publication, $allowed]) {
        $visit = rcpuEncounter($documentation, $publication);
        $consent = RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
        expect($consent->template_version)->toBe('2026.2', $label);

        $response = rcpuPost($this->doctor, $visit, rcpuJpeg());

        if ($allowed) {
            $response->assertCreated();
        } else {
            $response->assertStatus(422)->assertJsonPath('errors.photo.0', RmeVisitConsentService::MESSAGE_PHOTO_DOCUMENTATION_REFUSED);
        }
    }

    expect(RmeClinicalPhoto::query()->count())->toBe(2);
});

it('CPU-03b the photo module never reads the clause-9 answer (TVS-09 pins the readers)', function () {
    $sources = collect(File::allFiles(app_path('Modules/ClinicalPhoto')))
        ->map(fn ($file) => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($sources)->not->toBe('')
        ->and($sources)->not->toContain('publication_consent')
        ->and($sources)->not->toContain('publicationClause(')
        ->and($sources)->not->toContain('allowsPublication(')
        ->and($sources)->not->toContain('allowsDocumentationPublication(');

    $consentService = (string) file_get_contents(app_path('Modules/Consent/Services/RmeVisitConsentService.php'));
    expect($consentService)->toContain('allowsClinicalDocumentation()');
});

/*
|--------------------------------------------------------------------------
| The executed PTM (rule 241 §2) and the live encounter
|--------------------------------------------------------------------------
*/

it('CPU-04 refuses while the PTM awaits the patient or the doctor, accepts once executed, and a void reopens the gate', function () {
    $visit = rcpuVisit();

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))
        ->toBe([ValidationException::class, RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_PATIENT]);

    $consent = rcpuPatientSigns($visit, true);
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))
        ->toBe([ValidationException::class, RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_DOCTOR]);

    rcpuDoctorSigns($consent);
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))->toBe(['none', '']);

    $supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
    app(RmeVisitConsentService::class)->void($consent->fresh(), $supervisor, 'Salah pasien pada formulir persetujuan.');

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))
        ->toBe([ValidationException::class, RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_PATIENT])
        ->and(RmeClinicalPhoto::query()->count())->toBe(1)
        ->and(rcpuObjects())->toHaveCount(1);
});

it('CPU-05 accepts only the live in-progress encounter: never a queued, finished or earlier visit', function () {
    $visit = rcpuEncounter();

    foreach ([ClinicVisit::STATUS_CASHIER_PENDING, ClinicVisit::STATUS_COMPLETED, ClinicVisit::STATUS_CANCELLED] as $status) {
        $visit->forceFill(['status' => $status])->save();
        expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))
            ->toBe([ValidationException::class, RmeVisitConsentService::MESSAGE_PHOTO_NOT_IN_EXAMINATION], $status);
    }

    // The earlier, completed visit of a patient who is now examined again.
    $later = rcpuVisit(ClinicVisit::STATUS_IN_PROGRESS, $visit->patient);
    rcpuDoctorSigns(rcpuPatientSigns($later, true));

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))
        ->toBe([ValidationException::class, RmeVisitConsentService::MESSAGE_PHOTO_NOT_IN_EXAMINATION])
        ->and(rcpuRefusal(fn () => rcpuServiceUpload($later)))->toBe(['none', ''])
        ->and(RmeClinicalPhoto::query()->pluck('clinic_visit_id')->all())->toBe([$later->id]);
});

/*
|--------------------------------------------------------------------------
| Who may upload — Doctor and Perawat only; Super Admin bound
|--------------------------------------------------------------------------
*/

it('CPU-06 the treating Doctor and a Perawat at the branch upload', function () {
    $visit = rcpuEncounter();

    rcpuPost($this->doctor, $visit, rcpuJpeg())->assertCreated();
    rcpuPost($this->perawat, $visit, rcpuJpeg())->assertCreated();

    expect(RmeClinicalPhoto::query()->orderBy('id')->pluck('uploaded_by')->all())
        ->toBe([$this->doctor->id, $this->perawat->id]);
});

it('CPU-06b refuses every other role with 403, Super Admin included (the allowlist lives in the service), and a runtime grant changes nothing', function () {
    $visit = rcpuEncounter();

    $supervisor = userInRole('Supervisor RME');
    $superAdmin = userInRole('Super Admin');
    $owner = userInRole('Owner');
    $kasir = tap(User::factory()->create(), fn (User $u) => rmeMakeKasirActive($u, $this->branch))->fresh();
    $adminKlinik = rmeAdminClinicUser($this->branch);

    // No upload permission: the route refuses first.
    foreach ([$supervisor, $owner, $this->frontOffice, $kasir, $adminKlinik] as $user) {
        rcpuPost($user, $visit, rcpuJpeg())->assertForbidden();
    }

    // Super Admin passes every permission and policy through Gate::before; the
    // service's role allowlist is what refuses it.
    rcpuPost($superAdmin, $visit, rcpuJpeg())->assertForbidden();
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, $superAdmin)))
        ->toBe([AuthorizationException::class, RmeClinicalPhotoService::MESSAGE_UPLOAD_ROLE]);

    // A runtime grant of the permission never adds a role to the allowlist.
    foreach ([$supervisor, $owner, $this->frontOffice, $kasir, $adminKlinik] as $user) {
        $user->givePermissionTo(RmeClinicalPhotoService::UPLOAD_PERMISSION);
        rcpuPost($user->fresh(), $visit, rcpuJpeg())->assertForbidden();
        expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, $user->fresh())))
            ->toBe([AuthorizationException::class, RmeClinicalPhotoService::MESSAGE_UPLOAD_ROLE]);
    }

    expect(RmeClinicalPhoto::query()->count())->toBe(0)
        ->and(rcpuObjects())->toBe([]);

    // The allowlist is the guard: the same Super Admin account, once it also holds the Perawat role, uploads.
    $superAdmin->assignRole('Perawat');
    rcpuPost($superAdmin->fresh(), $visit, rcpuJpeg())->assertCreated();
});

it('CPU-06c the permission and the role are both needed: a direct grant without the role, or the role without the grant, is refused', function () {
    $visit = rcpuEncounter();

    // The role without the permission.
    $perawatRole = Role::findByName('Perawat', 'web');
    $perawatRole->revokePermissionTo(RmeClinicalPhotoService::UPLOAD_PERMISSION);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    rcpuPost($this->perawat->fresh(), $visit, rcpuJpeg())->assertForbidden();

    // The permission without the role.
    $bare = userWith(['view_clinic_visits', 'manage_clinic_visits', RmeClinicalPhotoService::UPLOAD_PERMISSION]);
    rcpuPost($bare, $visit, rcpuJpeg())->assertForbidden();
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, $bare)))
        ->toBe([AuthorizationException::class, RmeClinicalPhotoService::MESSAGE_UPLOAD_ROLE]);

    expect(RmeClinicalPhoto::query()->count())->toBe(0);

    // Both together: the Perawat uploads again once the role carries the permission.
    $perawatRole->givePermissionTo(RmeClinicalPhotoService::UPLOAD_PERMISSION);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    rcpuPost($this->perawat->fresh(), $visit, rcpuJpeg())->assertCreated();
});

it('CPU-07 branch, room and patient scope: another branch, another room or an unlinked doctor is refused', function () {
    $visit = rcpuEncounter();

    // A Perawat working at another branch.
    $otherBranch = Branch::factory()->create(['code' => 'CPU2', 'is_active' => true, 'is_rme_enabled' => true]);
    $farPerawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $otherBranch))->fresh();
    rcpuPost($farPerawat, $visit, rcpuJpeg())->assertForbidden();

    // Another doctor, online in another room of the same branch, never on this patient.
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $otherDoctor = rmeMakeDoctorOnline(Doctor::factory()->withAllowedBranches([$this->branch])->create(), $this->branch, $otherRoom)->fresh();
    rcpuPost($otherDoctor, $visit, rcpuJpeg())->assertForbidden();
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, $otherDoctor))[0])->toBe(AuthorizationException::class);

    expect(RmeClinicalPhoto::query()->count())->toBe(0);

    // The scope is the guard: the same Perawat working at the visit's branch uploads.
    rmeMakePerawatActive($farPerawat, $this->branch);
    rcpuPost($farPerawat->fresh(), $visit, rcpuJpeg())->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Validation before any write
|--------------------------------------------------------------------------
*/

it('CPU-08 refuses anything but a decodable JPEG or PNG, sniffed from the bytes, before anything is written', function (string $bytes, string $name) {
    $visit = rcpuEncounter();

    rcpuPost($this->doctor, $visit, $bytes, $name)->assertStatus(422)->assertJsonValidationErrors('photo');

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, $bytes))[0])->toBe(ValidationException::class)
        ->and(RmeClinicalPhoto::query()->count())->toBe(0)
        ->and(rcpuObjects())->toBe([]);
})->with([
    'a GIF' => [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'foto.gif'],
    'a GIF named .jpg' => [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'foto.jpg'],
    'HTML named .jpg' => ['<html><body><script>alert(1)</script></body></html>', 'foto.jpg'],
    'SVG named .png' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'foto.png'],
    'PHP named .jpg' => ['<?php echo 1; ?>', 'foto.jpg'],
    'JPEG magic then garbage' => ["\xFF\xD8\xFF\xE0".str_repeat('A', 4000), 'foto.jpg'],
    'PNG magic then garbage' => ["\x89PNG\r\n\x1a\n".str_repeat('B', 4000), 'foto.png'],
]);

it('CPU-08b refuses an oversized upload, a decompression bomb header and a too-small image before decoding', function () {
    $visit = rcpuEncounter();

    // Over the byte bound (HTTP rule and the service both).
    rcpuPost($this->doctor, $visit, rcpuJpeg().str_repeat("\0", ClinicalPhotoNormalizer::MAX_UPLOAD_BYTES))
        ->assertStatus(422)->assertJsonValidationErrors('photo');
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, rcpuJpeg().str_repeat("\0", ClinicalPhotoNormalizer::MAX_UPLOAD_BYTES))))
        ->toBe([ValidationException::class, ClinicalPhotoNormalizer::MESSAGE_TOO_LARGE]);

    // A PNG whose header claims 30000 x 30000 (900 MP) with almost no data.
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $bomb = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 30000, 30000, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0", 1000))).$chunk('IEND', '');
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, $bomb)))
        ->toBe([ValidationException::class, ClinicalPhotoNormalizer::MESSAGE_DIMENSIONS]);

    // Within bytes but over the pixel bound in one edge.
    $wide = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', ClinicalPhotoNormalizer::MAX_EDGE + 1, 10, 8, 2, 0, 0, 0)).$chunk('IEND', '');
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, $wide)))
        ->toBe([ValidationException::class, ClinicalPhotoNormalizer::MESSAGE_DIMENSIONS]);

    // A thumbnail is not a clinical photo.
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, rcpuJpeg(ClinicalPhotoNormalizer::MIN_EDGE - 1, 200))))
        ->toBe([ValidationException::class, ClinicalPhotoNormalizer::MESSAGE_DIMENSIONS]);

    // Empty.
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit, null, '')))->toBe([ValidationException::class, ClinicalPhotoNormalizer::MESSAGE_REQUIRED]);

    expect(RmeClinicalPhoto::query()->count())->toBe(0)->and(rcpuObjects())->toBe([]);

    // The bounds are the guard: an ordinary photo of the same visit is accepted.
    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))->toBe(['none', '']);
});

it('CPU-08c re-encodes a polyglot: a payload appended to a real JPEG never reaches the disk', function () {
    $visit = rcpuEncounter();
    $payload = '<?php system($_GET["c"]); ?><script>alert(document.cookie)</script>';

    rcpuPost($this->doctor, $visit, rcpuJpeg().$payload)->assertCreated();

    $photo = RmeClinicalPhoto::query()->sole();
    $stored = ClinicalEvidenceStorage::disk()->get($photo->getRawOriginal('photo_path'));

    expect($stored)->not->toContain('<?php')
        ->and($stored)->not->toContain('<script')
        ->and(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF")
        ->and($photo->mime_type)->toBe('image/jpeg')
        ->and($photo->original_sha256)->toBe(hash('sha256', rcpuJpeg().$payload))
        ->and($photo->sha256)->toBe(hash('sha256', $stored));
});

it('CPU-09 strips EXIF and GPS, applies the orientation, bounds the long edge and flattens a PNG into JPEG', function () {
    $visit = rcpuEncounter();

    // The fixture really carries the metadata (so the check below is not vacuous).
    $original = rcpuJpegWithExif(300, 120, 6);
    $exif = rcpuExif($original);
    expect($exif['GPSLatitudeRef'] ?? null)->toBe('S')
        ->and($exif['Orientation'] ?? null)->toBe(6)
        ->and($original)->toContain('RCPU-SECRET-LOCATION');

    $photo = rcpuServiceUpload($visit, null, $original);
    $stored = ClinicalEvidenceStorage::disk()->get($photo->getRawOriginal('photo_path'));
    $storedExif = rcpuExif($stored);

    expect($stored)->not->toContain("Exif\0\0")
        ->and($stored)->not->toContain('RCPU-SECRET-LOCATION')
        ->and(array_key_exists('GPSLatitudeRef', $storedExif))->toBeFalse()
        ->and(array_key_exists('Orientation', $storedExif))->toBeFalse()
        // Orientation 6 (rotate 90° clockwise to view): 300 x 120 is stored upright as 120 x 300.
        ->and([$photo->width, $photo->height])->toBe([120, 300])
        ->and(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([120, 300]);

    // A large photo is bounded to the long edge.
    $large = rcpuServiceUpload($visit, null, rcpuJpeg(3000, 1500, 70));
    expect([$large->width, $large->height])->toBe([ClinicalPhotoNormalizer::OUTPUT_LONG_EDGE, 1280]);

    // A PNG with transparency is stored as an opaque JPEG.
    $png = rcpuServiceUpload($visit, null, rcpuPng());
    $pngStored = ClinicalEvidenceStorage::disk()->get($png->getRawOriginal('photo_path'));
    expect(substr($pngStored, 0, 3))->toBe("\xFF\xD8\xFF")
        ->and(getimagesizefromstring($pngStored)['mime'])->toBe('image/jpeg')
        ->and($png->mime_type)->toBe('image/jpeg');
});

/*
|--------------------------------------------------------------------------
| Row + object one unit (rule 224), visit lock first, fresh key
|--------------------------------------------------------------------------
*/

it('CPU-10 a failed row insert or audit leaves neither a row nor an object (only this call\'s object is removed)', function () {
    $visit = rcpuEncounter();
    $kept = rcpuServiceUpload($visit);
    $keptKey = $kept->getRawOriginal('photo_path');

    RmeClinicalPhoto::creating(function (): void {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => rcpuServiceUpload($visit))->toThrow(RuntimeException::class, 'insert failed');
    expect(RmeClinicalPhoto::query()->count())->toBe(1)
        ->and(rcpuObjects())->toBe([$keptKey]);

    RmeClinicalPhoto::flushEventListeners();
    AuditLog::creating(function (AuditLog $log): void {
        if ($log->action === RmeClinicalPhotoService::AUDIT_UPLOADED) {
            throw new RuntimeException('audit failed');
        }
    });

    expect(fn () => rcpuServiceUpload($visit))->toThrow(RuntimeException::class, 'audit failed');
    expect(RmeClinicalPhoto::query()->count())->toBe(1)
        ->and(rcpuObjects())->toBe([$keptKey]);
});

it('CPU-10b a failure after the body finished keeps the committed object (never delete committed evidence)', function () {
    $visit = rcpuEncounter();

    RmeClinicalPhoto::created(function (): void {
        DB::afterCommit(function (): void {
            throw new RuntimeException('after commit');
        });
    });

    expect(fn () => rcpuServiceUpload($visit))->toThrow(RuntimeException::class, 'after commit');

    $photo = RmeClinicalPhoto::query()->sole();
    expect(rcpuObjects())->toBe([$photo->getRawOriginal('photo_path')]);
});

it('CPU-10c every upload is a fresh random key, never derived from the request', function () {
    $visit = rcpuEncounter();

    $a = rcpuServiceUpload($visit, null, rcpuJpeg());
    $b = app(RmeClinicalPhotoService::class)->upload($visit->fresh(), $this->doctor, rcpuUpload(rcpuJpeg(), '../../../public/evil.php.jpg'));

    $keys = [$a->getRawOriginal('photo_path'), $b->getRawOriginal('photo_path')];

    expect($keys[0])->not->toBe($keys[1]);
    foreach ($keys as $key) {
        expect($key)->toMatch('#^'.RmeClinicalPhotoService::DIRECTORY.'/\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#')
            ->and($key)->not->toContain('evil');
    }

    expect(ClinicalEvidenceStorage::diskName())->not->toBeIn(ClinicalEvidenceStorage::FORBIDDEN_DISKS);
});

it('CPU-11 locks the visit row FOR NO KEY UPDATE inside the write transaction, before the photo insert', function () {
    $visit = rcpuEncounter();
    $statements = [];

    Event::listen(TransactionBeginning::class, function () use (&$statements): void {
        $statements[] = '-- begin';
    });
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    rcpuServiceUpload($visit);

    $isLock = fn (string $sql): bool => str_contains($sql, 'trx_clinic_visits') && str_contains($sql, 'for no key update');
    $insert = collect($statements)->search(fn (string $sql) => str_starts_with($sql, 'insert into "trx_rme_clinical_photos"'));
    expect($insert)->not->toBeFalse();

    // The write transaction is the last one begun before the insert; the lock must be inside it.
    $begin = collect($statements)->take($insert)->keys()->filter(fn (int $i) => $statements[$i] === '-- begin')->last();
    expect($begin)->not->toBeNull();

    // The first row the write locks or writes inside it is the visit row.
    $inside = array_slice($statements, $begin + 1, $insert - $begin - 1);
    $firstTouch = collect($inside)->first(fn (string $sql) => (bool) preg_match('/\bfor (no key )?update\b|\bfor (key )?share\b|^(insert|update|delete)\b/', $sql));
    expect($firstTouch)->not->toBeNull()
        ->and($isLock((string) $firstTouch))->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'row locks are PostgreSQL');

it('CPU-11b re-decides on the locked row: a void or a finish committed after the pre-check refuses, and nothing is written', function (string $change) {
    $visit = rcpuEncounter();
    $consent = RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->sole();
    $supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);

    // Between the unlocked pre-check and the lock: normalization runs there.
    app()->bind(ClinicalPhotoNormalizer::class, fn () => new class($change, $consent, $supervisor, $visit) extends ClinicalPhotoNormalizer
    {
        public function __construct(private string $change, private RmeVisitConsent $consent, private User $supervisor, private ClinicVisit $visit) {}

        public function normalize(string $binary): array
        {
            if ($this->change === 'void') {
                app(RmeVisitConsentService::class)->void($this->consent->fresh(), $this->supervisor, 'Dibatalkan saat unggahan berjalan.');
            } else {
                $this->visit->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();
            }

            return parent::normalize($binary);
        }
    });

    expect(rcpuRefusal(fn () => rcpuServiceUpload($visit)))->toBe([ValidationException::class, $change === 'void'
        ? RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_PATIENT
        : RmeVisitConsentService::MESSAGE_PHOTO_NOT_IN_EXAMINATION]);

    expect(RmeClinicalPhoto::query()->count())->toBe(0)->and(rcpuObjects())->toBe([]);
})->with(['void', 'finish']);

/*
|--------------------------------------------------------------------------
| Audit, authorization before validation, the stored row
|--------------------------------------------------------------------------
*/

it('CPU-12 audits the upload with ids only — no key, hash, name or identity number', function () {
    $visit = rcpuEncounter();
    $photo = rcpuServiceUpload($visit);
    $consentId = RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->value('id');

    $log = AuditLog::query()->where('action', RmeClinicalPhotoService::AUDIT_UPLOADED)->sole();
    $json = json_encode($log->new_values);

    expect($log->entity_type)->toBe(RmeClinicalPhotoService::AUDIT_TABLE)
        ->and((int) $log->entity_id)->toBe($photo->id)
        ->and((int) $log->performed_by)->toBe($this->doctor->id)
        ->and($log->new_values)->toBe(['clinic_visit_id' => $visit->id, 'rme_visit_consent_id' => (int) $consentId])
        ->and($log->old_values)->toBeNull()
        ->and($json)->not->toContain($photo->getRawOriginal('photo_path'))
        ->and($json)->not->toContain($photo->sha256)
        ->and($json)->not->toContain('Pasien Foto Rahasia');
});

it('CPU-13 authorizes before it validates: a refused account never has its payload decoded', function () {
    $visit = rcpuEncounter();
    $probe = new stdClass;
    $probe->decoded = 0;

    app()->bind(ClinicalPhotoNormalizer::class, fn () => new class($probe) extends ClinicalPhotoNormalizer
    {
        public function __construct(private stdClass $probe) {}

        public function normalize(string $binary): array
        {
            $this->probe->decoded++;

            return parent::normalize($binary);
        }
    });

    $superAdmin = userInRole('Super Admin');
    rcpuPost($superAdmin, $visit, 'not an image', 'foto.jpg')->assertForbidden();
    expect(rcpuRefusal(fn () => rcpuServiceUpload(rcpuVisit(), $this->doctor, 'not an image'))[0])->toBe(ValidationException::class);
    expect($probe->decoded)->toBe(0);

    // The probe counts: an authorized upload decodes once.
    rcpuServiceUpload($visit);
    expect($probe->decoded)->toBe(1);
});

it('CPU-14 the stored row: denormalised scope, clinic time, the consent that allowed it, the key hidden; the row refuses delete and edits', function () {
    $visit = rcpuEncounter();
    $photo = rcpuServiceUpload($visit)->fresh();

    expect($photo->clinic_visit_id)->toBe($visit->id)
        ->and($photo->patient_id)->toBe($visit->patient_id)
        ->and($photo->branch_id)->toBe($visit->branch_id)
        ->and($photo->rme_visit_consent_id)->toBe((int) RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->value('id'))
        ->and($photo->uploaded_at)->not->toBeNull()
        ->and($photo->voided_at)->toBeNull()
        ->and(array_key_exists('photo_path', $photo->toArray()))->toBeFalse()
        ->and(array_key_exists('original_sha256', $photo->toArray()))->toBeFalse();

    expect(fn () => $photo->delete())->toThrow(LogicException::class);
    expect(fn () => $photo->forceFill(['photo_path' => 'elsewhere.jpg'])->save())->toThrow(LogicException::class);
    expect(fn () => $photo->fresh()->forceFill(['width' => 1])->save())->toThrow(LogicException::class);

    expect(RmeClinicalPhoto::query()->count())->toBe(1);
});

it('CPU-15 the presentation predicate for PR5 names the refusal and stays read-only', function () {
    $service = app(RmeClinicalPhotoService::class);
    $visit = rcpuVisit();

    expect($service->uploadRefusal($visit, $this->doctor))->toBe(RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_PATIENT)
        ->and($service->uploadRefusal($visit, userInRole('Supervisor RME')))->toBe(RmeClinicalPhotoService::MESSAGE_UPLOAD_PERMISSION)
        ->and($service->uploadRefusal($visit, null))->toBe(RmeClinicalPhotoService::MESSAGE_UPLOAD_PERMISSION);

    rcpuDoctorSigns(rcpuPatientSigns($visit, true));
    expect($service->uploadRefusal($visit->fresh(), $this->doctor))->toBeNull()
        ->and($service->canUpload($visit->fresh(), $this->doctor))->toBeTrue()
        ->and(RmeClinicalPhoto::query()->count())->toBe(0);
});
