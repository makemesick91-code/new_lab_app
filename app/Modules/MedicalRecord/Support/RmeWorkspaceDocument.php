<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Support;

use Carbon\CarbonInterface;

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1 — one selectable document in the doctor's RME
 * workspace document rail.
 *
 * A PRESENTATION PROJECTION and nothing else. It exists so the workspace can
 * offer "the patient's RME documents" as one practical list without the two
 * sides ever becoming one data model:
 *
 *   - NATIVE  — a `MedicalRecord` sheet. Editable per its own permissions, and
 *               selected by reloading the workspace with `?sheet=`.
 *   - LEGACY  — a PUBLISHED `LegacyRmeRecord`. Immutable historical archive
 *               evidence, opened READ ONLY in an overlay viewer.
 *
 * A legacy record is NEVER converted into a ClinicVisit or a MedicalRecord to
 * appear here, and nothing in this object is persisted: it is rebuilt per
 * request from data the caller already resolved under its own authorization.
 *
 * The kind is an EXPLICIT field. Callers must branch on `isLegacy()` and never
 * infer the type from an id range or by parsing the label.
 *
 * PII-free by construction: a date, a kind, short labels, a page count and an
 * already-built URL. Never a KTP/NIK, never a storage path, never a hash,
 * never clinical content.
 */
final class RmeWorkspaceDocument
{
    public const TYPE_NATIVE = 'native';

    public const TYPE_LEGACY = 'legacy';

    private function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?CarbonInterface $clinicalDate,
        public readonly string $label,
        public readonly ?string $detail,
        public readonly bool $readOnly,
        public readonly bool $isCurrent,
        public readonly ?string $url,
        public readonly int $pageCount,
    ) {}

    public static function native(
        int $sheetId,
        ?CarbonInterface $clinicalDate,
        string $label,
        ?string $detail,
        bool $isCurrent,
        ?string $url,
    ): self {
        return new self(
            type: self::TYPE_NATIVE,
            id: $sheetId,
            clinicalDate: $clinicalDate,
            label: $label,
            detail: $detail,
            // A native sheet's editability is decided by the native policy at
            // the point of editing, never by this projection. `false` here
            // means only "this rail does not force it read-only".
            readOnly: false,
            isCurrent: $isCurrent,
            url: $url,
            pageCount: 0,
        );
    }

    public static function legacy(
        int $recordId,
        ?CarbonInterface $clinicalDate,
        string $label,
        ?string $detail,
        ?string $url,
        int $pageCount,
    ): self {
        return new self(
            type: self::TYPE_LEGACY,
            id: $recordId,
            clinicalDate: $clinicalDate,
            label: $label,
            detail: $detail,
            // Not a UI preference — a published archive has no edit path at
            // all, so every legacy document in this rail is read-only.
            readOnly: true,
            // A historical archive is never the encounter being written now.
            isCurrent: false,
            url: $url,
            pageCount: max(0, $pageCount),
        );
    }

    public function isLegacy(): bool
    {
        return $this->type === self::TYPE_LEGACY;
    }

    public function isNative(): bool
    {
        return $this->type === self::TYPE_NATIVE;
    }

    /**
     * Whether the overlay viewer can page through rendered images.
     *
     * A record imported before/without a working rasterizer has a page count
     * but no rendered pages; the viewer falls back to the inline source PDF
     * rather than showing broken images.
     */
    public function hasPages(): bool
    {
        return $this->isLegacy() && $this->pageCount > 0;
    }

    /**
     * Deterministic ordering key — clinical date first, then kind, then id, so
     * a same-day collision can never fall back to the database's row order.
     */
    public function sortKey(): string
    {
        return sprintf(
            '%s|%d|%012d',
            $this->clinicalDate?->format('Y-m-d') ?? '0000-01-01',
            $this->isLegacy() ? 0 : 1,
            $this->id,
        );
    }
}
