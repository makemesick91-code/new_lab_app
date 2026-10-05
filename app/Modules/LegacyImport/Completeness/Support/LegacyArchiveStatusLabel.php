<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — Indonesian labels and badge
 * tones for the states this page reports.
 *
 * NO INVENTED STATES. Every label here maps onto a value the backend actually
 * holds: the four effective states of {@see LegacyArchiveDocumentState}, the
 * nine canonical staging statuses, and the review triage vocabulary. There is
 * no "Menunggu Verifikasi", no "Arsip Sebagian" and no other operator-friendly
 * fiction — if a label existed with no state behind it, an operator would wait
 * for a transition that can never happen.
 *
 * ONE STAGING VOCABULARY, TWO ARCHIVES. The RME and odontogram staging state
 * machines are declared independently and currently agree value-for-value, so a
 * single label map serves both. That agreement is asserted by a test rather
 * than assumed: if either machine ever gains a status the other lacks, the
 * assertion fails and this map has to be split instead of silently falling back
 * to a generic label for one archive only.
 *
 * PRESENTATION ONLY. Nothing here decides anything; a wrong label is a cosmetic
 * defect, while the states it renders were decided server-side.
 */
final class LegacyArchiveStatusLabel
{
    private function __construct() {}

    /**
     * The label for one document column.
     *
     * The raw staging status refines IN_PROGRESS — "Siap Ditinjau" and "Gagal"
     * are very different next actions — and a blocking review triage overrides
     * it, because an item a reviewer has held will not progress on its own no
     * matter what the pipeline status says.
     */
    public static function document(string $state, ?string $rawStatus = null, bool $reviewBlocked = false): string
    {
        if ($state !== LegacyArchiveDocumentState::IN_PROGRESS) {
            return match ($state) {
                LegacyArchiveDocumentState::PUBLISHED => 'Published',
                LegacyArchiveDocumentState::VOID => 'Void / Perlu Koreksi',
                LegacyArchiveDocumentState::MISSING => 'Belum Ada',
                default => $state,
            };
        }

        if ($reviewBlocked) {
            return 'Ditahan Peninjau';
        }

        return match ($rawStatus) {
            LegacyRmeImportStatus::READY_FOR_REVIEW => 'Siap Ditinjau',
            LegacyRmeImportStatus::REVIEWED => 'Ditinjau',
            LegacyRmeImportStatus::FAILED => 'Gagal — Dapat Diulang',
            default => 'Dalam Proses',
        };
    }

    /**
     * The badge tone for one document column.
     *
     * Deliberately not colour-only: every tone here accompanies a text label,
     * so the status is never conveyed by colour alone.
     */
    public static function documentTone(string $state, bool $reviewBlocked = false): string
    {
        if ($state === LegacyArchiveDocumentState::IN_PROGRESS) {
            return $reviewBlocked ? 'warning' : 'info';
        }

        return match ($state) {
            LegacyArchiveDocumentState::PUBLISHED => 'success',
            LegacyArchiveDocumentState::VOID => 'danger',
            LegacyArchiveDocumentState::MISSING => 'neutral',
            default => 'neutral',
        };
    }

    public static function completeness(string $verdict): string
    {
        return match ($verdict) {
            LegacyArchiveCompleteness::COMPLETE => 'Lengkap',
            LegacyArchiveCompleteness::IN_PROGRESS => 'Dalam Proses',
            LegacyArchiveCompleteness::INCOMPLETE => 'Belum Lengkap',
            default => $verdict,
        };
    }

    public static function completenessTone(string $verdict): string
    {
        return match ($verdict) {
            LegacyArchiveCompleteness::COMPLETE => 'success',
            LegacyArchiveCompleteness::IN_PROGRESS => 'info',
            LegacyArchiveCompleteness::INCOMPLETE => 'warning',
            default => 'neutral',
        };
    }
}
