<?php

namespace App\Modules\ClinicalPhoto\Services;

use App\Models\User;
use App\Modules\ClinicalPhoto\Interfaces\RmeClinicalPhotoRepositoryInterface;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Support\ClinicalPhotoNormalizer;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Clinical\ClinicalClock;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — clinical photos of an encounter.
 *
 * The one writer and the one reader. Behind the risky flag `rme.clinical_photos`
 * (default OFF, read only through FeatureFlagService): while it is off every
 * method refuses or answers nothing, so a caller that skips the route and the
 * policy is refused here too. Every rule that must also bind Super Admin
 * (Gate::before passes it through every policy) is decided in this class:
 * the flag, the uploader allowlist, the read scope.
 */
class RmeClinicalPhotoService
{
    public const FLAG = 'rme.clinical_photos';

    public const UPLOAD_PERMISSION = 'upload_rme_clinical_photos';

    public const VIEW_PERMISSION = 'view_rme_clinical_photos';

    /**
     * Rule 242 §2 — who uploads: Doctor and Perawat only (plan default). An
     * allowlist decided here, so it binds Super Admin, and a runtime grant of the
     * permission never extends it to another role.
     */
    public const UPLOAD_ROLES = ['Doctor', 'Perawat'];

    /**
     * Rule 242 §5 — who reads: the cross-branch clinical-document readers of rule
     * 227 §1 (the clinical roles and Owner). Front Office, Kasir and Admin Klinik
     * are not on it, so they never read a photo, even with a runtime grant.
     */
    public const READ_ROLES = RmeWorkingBranchScope::CROSS_BRANCH_DOCUMENT_READ_ROLES;

    public const DIRECTORY = 'rme-clinical-photos';

    public const AUDIT_TABLE = 'trx_rme_clinical_photos';

    public const AUDIT_UPLOADED = 'RME_CLINICAL_PHOTO_UPLOADED';

    public const AUDIT_VIEWED = 'RME_CLINICAL_PHOTO_VIEWED';

    public const MESSAGE_DISABLED = 'Fitur foto klinis belum diaktifkan.';

    public const MESSAGE_UPLOAD_PERMISSION = 'Akun ini tidak berwenang mengunggah foto klinis.';

    public const MESSAGE_UPLOAD_ROLE = 'Foto klinis hanya dapat diunggah oleh dokter atau perawat.';

    public const MESSAGE_UPLOAD_SCOPE = 'Anda tidak memiliki akses ke kunjungan ini untuk mengunggah foto klinis.';

    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    public function __construct(
        private readonly RmeClinicalPhotoRepositoryInterface $photos,
        private readonly RmeVisitConsentService $consents,
        private readonly ClinicalPhotoNormalizer $normalizer,
        private readonly FeatureFlagService $flags,
        private readonly RmeWorkingBranchScope $branchScope,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly ClinicalClock $clock,
        private readonly AuditLogService $auditLog,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    /**
     * Rule 242 §2 — the ACCOUNT side of the upload authority, shared by the
     * policy and the service: the permission, the Doctor/Perawat allowlist, the
     * visit's working branch and the doctor-patient scope. Null when allowed.
     * The encounter, the PTM and the documentation answer are
     * RmeVisitConsentService::assertClinicalPhotoAuthoringAllowed().
     */
    public function uploadAccountRefusal(?User $actor, ClinicVisit $visit): ?string
    {
        if (! $actor instanceof User || ! $actor->can(self::UPLOAD_PERMISSION)) {
            return self::MESSAGE_UPLOAD_PERMISSION;
        }

        if (! $actor->hasAnyRole(self::UPLOAD_ROLES)) {
            return self::MESSAGE_UPLOAD_ROLE;
        }

        if (! $this->branchScope->allows($actor, $visit->branch_id)
            || ! $this->doctorScope->doctorCanAccessVisit($actor, $visit)) {
            return self::MESSAGE_UPLOAD_SCOPE;
        }

        return null;
    }

    /**
     * Presentation for the photo UI (PR5): why this account cannot add a photo
     * to this visit right now, or null when it can. Locks and writes nothing;
     * never the authorization answer — upload() decides again.
     */
    public function uploadRefusal(ClinicVisit $visit, ?User $actor): ?string
    {
        if (! $this->enabled()) {
            return self::MESSAGE_DISABLED;
        }

        $refusal = $this->uploadAccountRefusal($actor, $visit);

        if ($refusal !== null || ! $actor instanceof User) {
            return $refusal ?? self::MESSAGE_UPLOAD_PERMISSION;
        }

        try {
            $this->consents->assertClinicalPhotoAuthoringAllowed($visit, $actor);
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        } catch (AuthorizationException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function canUpload(ClinicVisit $visit, ?User $actor): bool
    {
        return $this->uploadRefusal($visit, $actor) === null;
    }

    /**
     * Store one clinical photo of the live encounter. Order (rule 237:
     * authorization before validation; rule 224: validation before any write):
     *
     *  1. the flag (404 when off);
     *  2. the account (permission, Doctor/Perawat, branch, patient scope: 403);
     *  3. the encounter authority, unlocked (live encounter, PTM executed,
     *     documentation YA) — so a refused upload is never decoded;
     *  4. the image normalized: bounded, sniffed, re-encoded (422);
     *  5. one transaction: the visit row locked FIRST and step 3 re-decided on
     *     it, the object written under a fresh random key, the row inserted,
     *     the upload audited with ids only. A failure before the body finished
     *     removes only this call's object; after it, the object is kept.
     */
    public function upload(ClinicVisit $visit, User $actor, UploadedFile $photo): RmeClinicalPhoto
    {
        if (! $this->enabled()) {
            throw new NotFoundHttpException;
        }

        $refusal = $this->uploadAccountRefusal($actor, $visit);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        $this->consents->assertClinicalPhotoAuthoringAllowed($visit, $actor);

        // Bounded before it is read into memory; the normalizer bounds it again.
        if ((int) $photo->getSize() > ClinicalPhotoNormalizer::MAX_UPLOAD_BYTES) {
            throw ValidationException::withMessages(['photo' => ClinicalPhotoNormalizer::MESSAGE_TOO_LARGE]);
        }

        // Review H-1 — a failed read throws (FileException); it is never folded
        // into empty content, which would read as "no photo".
        $image = $this->normalizer->normalize($photo->getContent());

        $written = [];
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($visit, $actor, $image, &$written, &$bodyDone): RmeClinicalPhoto {
                $locked = $this->consents->assertClinicalPhotoAuthoringAllowed($visit, $actor);
                $consent = $this->consents->validConsentFor($locked);

                // Never derived from the request: clinic month + 128 random bits.
                $key = self::DIRECTORY.'/'.$this->clock->now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.jpg';
                // Review L-4 — recorded BEFORE the write, so a write that fails
                // part-way still has its partial object removed.
                $written[] = $key;
                ClinicalEvidenceStorage::disk()->put($key, $image['bytes']);

                $row = $this->photos->create([
                    'clinic_visit_id' => (int) $locked->getKey(),
                    'patient_id' => (int) $locked->patient_id,
                    'branch_id' => (int) $locked->branch_id,
                    'rme_visit_consent_id' => (int) $consent?->getKey(),
                    'uploaded_by' => (int) $actor->getKey(),
                    'photo_path' => $key,
                    'mime_type' => ClinicalPhotoNormalizer::OUTPUT_MIME,
                    'byte_size' => $image['byte_size'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'sha256' => $image['sha256'],
                    'original_sha256' => $image['original_sha256'],
                    'uploaded_at' => $this->clock->now(),
                ]);

                // Ids only — no key, hash, name or identity number.
                $this->auditLog->log(self::AUDIT_TABLE, (int) $row->getKey(), self::AUDIT_UPLOADED, null, [
                    'clinic_visit_id' => (int) $locked->getKey(),
                    'rme_visit_consent_id' => (int) $consent?->getKey(),
                ], $actor);

                $bodyDone = true;

                return $row;
            });
        } catch (Throwable $e) {
            if (! $bodyDone) {
                $this->discardUncommitted($written);
            }

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Read
    |--------------------------------------------------------------------------
    */

    /**
     * Rule 242 §5 — may this account read this visit's photos? The view
     * permission, a READ_ROLES role, the RME clinical-document branch scope
     * (rule 227 §1) and the doctor-patient scope — DoctorPatientScopeService is
     * also where the rule 243 §2 encounter scope applies while its flag is ON.
     * Never consent-gated (AIR-RMC-09).
     */
    public function mayViewVisit(?User $actor, ClinicVisit $visit): bool
    {
        return $actor instanceof User
            && $this->enabled()
            && $actor->can(self::VIEW_PERMISSION)
            && $actor->hasAnyRole(self::READ_ROLES)
            && $this->branchScope->allowsClinicalDocument($actor, $visit->branch_id)
            && $this->doctorScope->doctorCanAccessVisit($actor, $visit);
    }

    public function mayView(?User $actor, RmeClinicalPhoto $photo): bool
    {
        $visit = $photo->visit;

        return $visit instanceof ClinicVisit
            && (int) $visit->branch_id === (int) $photo->branch_id
            && $this->mayViewVisit($actor, $visit);
    }

    /**
     * A visit's photos for this viewer, or none. One scope decision for the
     * visit, then one query for the photos and one for their uploaders.
     *
     * @return Collection<int, RmeClinicalPhoto>
     */
    public function visibleForVisit(ClinicVisit $visit, ?User $viewer): Collection
    {
        if (! $this->mayViewVisit($viewer, $visit)) {
            return collect();
        }

        return $this->photos->forVisit((int) $visit->getKey());
    }

    /**
     * The stored JPEG for an authorized viewer (404 otherwise, including a
     * missing or damaged object: never an empty image). Each successful read is
     * audited with ids only.
     */
    public function imageFor(RmeClinicalPhoto $photo, User $viewer): string
    {
        if (! $this->enabled() || ! $this->mayView($viewer, $photo)) {
            throw new NotFoundHttpException;
        }

        $key = (string) $photo->getRawOriginal('photo_path');
        $disk = ClinicalEvidenceStorage::disk();

        try {
            $bytes = $key !== '' && $disk->exists($key) ? (string) $disk->get($key) : '';
        } catch (Throwable $e) {
            Log::warning('A stored clinical photo could not be read.', ['type' => $e::class]);
            $bytes = '';
        }

        if (! str_starts_with($bytes, self::JPEG_MAGIC)) {
            if ($bytes !== '') {
                Log::warning('A stored clinical photo is not a JPEG image.');
            }

            throw new NotFoundHttpException;
        }

        $this->auditLog->log(self::AUDIT_TABLE, (int) $photo->getKey(), self::AUDIT_VIEWED, null, [
            'clinic_visit_id' => (int) $photo->clinic_visit_id,
        ], $viewer);

        return $bytes;
    }

    /** @param  list<string>  $keys */
    private function discardUncommitted(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                ClinicalEvidenceStorage::disk()->delete($key);
            } catch (Throwable $e) {
                // A private orphan is the safe failure; never mask the original error.
                Log::warning('An uncommitted clinical photo object could not be removed.', ['type' => $e::class]);
            }
        }
    }
}
