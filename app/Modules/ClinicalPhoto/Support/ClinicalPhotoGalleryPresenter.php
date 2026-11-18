<?php

namespace App\Modules\ClinicalPhoto\Support;

use App\Models\User;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use DateTimeInterface;

/**
 * DMS memo M4.4 / M1.x, PTM PR5 (rule 242 §10) — what the "Foto Klinis" card on
 * a visit page shows, decided once for the controller so Blade only renders
 * (CG-04, MEMO-F5). Presentation only: every answer comes from
 * RmeClinicalPhotoService, which decides each upload, read and void again.
 *
 * Null — nothing rendered, no markup and no photo route — while the flag is off
 * and for an account that may neither read this visit's photos nor upload to
 * it. The upload control is offered only when uploadRefusal() is null (rule
 * 135: never a control the server would refuse); an account the server would
 * let upload here at all (permission, Doctor/Perawat, branch, patient scope) but
 * that the encounter state refuses is given the server's reason instead. The
 * void form is offered per photo only when voidAccountRefusal() is null (the
 * permission, and never the account that uploaded it). The "dibatalkan" list
 * comes from voidedForVisit(), which answers nothing to a non-holder; only there
 * is the void reason — a hidden attribute — read, deliberately (rule 242 §7).
 *
 * Times are clinic wall-clock values (ClinicalClock wrote them), so they are
 * formatted as stored and labelled WITA, as the PTM signature times are.
 */
final class ClinicalPhotoGalleryPresenter
{
    public function __construct(
        private readonly RmeClinicalPhotoService $photos,
    ) {}

    /**
     * @return array{
     *     canView: bool,
     *     canUpload: bool,
     *     uploadRefusal: ?string,
     *     photos: list<array{id: int, width: int, height: int, uploader: string, uploadedAt: ?string, mayVoid: bool}>,
     *     voided: list<array{id: int, uploader: string, uploadedAt: ?string, voider: string, voidedAt: ?string, reason: string}>
     * }|null
     */
    public function forVisit(ClinicVisit $visit, ?User $viewer): ?array
    {
        if (! $viewer instanceof User || ! $this->photos->enabled()) {
            return null;
        }

        $canView = $this->photos->mayViewVisit($viewer, $visit);
        $isUploaderHere = $this->photos->uploadAccountRefusal($viewer, $visit) === null;

        if (! $canView && ! $isUploaderHere) {
            return null;
        }

        $uploadRefusal = $isUploaderHere ? $this->photos->uploadRefusal($visit, $viewer) : null;

        $photos = $this->photos->visibleForVisit($visit, $viewer)
            ->map(fn (RmeClinicalPhoto $photo): array => [
                'id' => (int) $photo->getKey(),
                'width' => (int) $photo->width,
                'height' => (int) $photo->height,
                'uploader' => $photo->uploader?->name ?? '—',
                'uploadedAt' => $this->clinicTime($photo->uploaded_at),
                'mayVoid' => $this->photos->voidAccountRefusal($viewer, $photo) === null,
            ])
            ->values()
            ->all();

        $voided = $this->photos->voidedForVisit($visit, $viewer)
            ->map(fn (RmeClinicalPhoto $photo): array => [
                'id' => (int) $photo->getKey(),
                'uploader' => $photo->uploader?->name ?? '—',
                'uploadedAt' => $this->clinicTime($photo->uploaded_at),
                'voider' => $photo->voider?->name ?? '—',
                'voidedAt' => $this->clinicTime($photo->voided_at),
                'reason' => (string) $photo->getAttribute('void_reason'),
            ])
            ->values()
            ->all();

        return [
            'canView' => $canView,
            'canUpload' => $isUploaderHere && $uploadRefusal === null,
            'uploadRefusal' => $uploadRefusal,
            'photos' => $photos,
            'voided' => $voided,
        ];
    }

    private function clinicTime(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : $instant->format('d/m/Y H:i').' WITA';
    }
}
