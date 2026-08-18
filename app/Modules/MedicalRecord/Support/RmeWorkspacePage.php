<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Support;

use Carbon\CarbonInterface;

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1A — ONE page in the doctor's unified
 * "RME Tulisan Tangan Lengkap" page sequence.
 *
 * The product rule this type exists to express:
 *
 *     ONE PAGE NAVIGATION EXPERIENCE, TWO DATA SOURCES
 *
 *     native page = persisted, editable handwritten clinical sheet page
 *     legacy page = virtual, read-only page of an immutable archive PDF
 *
 * They share the previous/next/swipe navigation. They NEVER share persistence
 * semantics. A legacy page is a PROJECTION built per request; it is never a
 * ClinicVisit, never a MedicalRecord and never a handwriting page row, and
 * nothing here writes anything.
 *
 * The kind is an EXPLICIT field. Callers MUST branch on `isLegacy()`/`isNative()`
 * and must never infer the type from an id range, a workspace index, a label or
 * the shape of a URL.
 *
 * PII-free by construction: an index, a kind, a date, short labels, page
 * numbers and already-built policy-gated URLs. Never a KTP/NIK, never a patient
 * name, never a storage path, never a checksum, never clinical content.
 */
final class RmeWorkspacePage
{
    public const TYPE_NATIVE = 'native';

    public const TYPE_LEGACY = 'legacy';

    /**
     * @param  array<string, mixed>  $nativePage  the native handwriting book entry (native pages only)
     */
    private function __construct(
        public readonly string $type,
        public readonly int $workspaceIndex,
        public readonly bool $readonly,
        public readonly ?CarbonInterface $clinicalDate,
        public readonly array $nativePage = [],
        public readonly ?int $legacyRecordId = null,
        public readonly ?int $legacyPdfPage = null,
        public readonly ?int $legacyPdfPageCount = null,
        public readonly ?int $legacyDocumentIndex = null,
        public readonly ?string $legacyPageImageUrl = null,
        public readonly ?string $legacySourceUrl = null,
        public readonly bool $legacyHasRenderedPage = false,
    ) {}

    /**
     * An editable native handwriting page.
     *
     * @param  array<string, mixed>  $nativePage
     */
    public static function native(int $workspaceIndex, array $nativePage, ?CarbonInterface $clinicalDate = null): self
    {
        return new self(
            type: self::TYPE_NATIVE,
            workspaceIndex: $workspaceIndex,
            readonly: false,
            clinicalDate: $clinicalDate,
            nativePage: $nativePage,
        );
    }

    /**
     * A read-only page of a published legacy archive.
     *
     * `readonly` is hard-coded true rather than accepted as an argument: there
     * is no caller-supplied circumstance under which archive evidence becomes
     * writable, so the constructor must not offer that possibility at all.
     */
    public static function legacy(
        int $workspaceIndex,
        int $legacyRecordId,
        int $legacyPdfPage,
        int $legacyPdfPageCount,
        int $legacyDocumentIndex,
        ?CarbonInterface $clinicalDate = null,
        ?string $pageImageUrl = null,
        ?string $sourceUrl = null,
        bool $hasRenderedPage = false,
    ): self {
        return new self(
            type: self::TYPE_LEGACY,
            workspaceIndex: $workspaceIndex,
            readonly: true,
            clinicalDate: $clinicalDate,
            legacyRecordId: $legacyRecordId,
            legacyPdfPage: max(1, $legacyPdfPage),
            legacyPdfPageCount: max(1, $legacyPdfPageCount),
            legacyDocumentIndex: $legacyDocumentIndex,
            legacyPageImageUrl: $pageImageUrl,
            legacySourceUrl: $sourceUrl,
            legacyHasRenderedPage: $hasRenderedPage,
        );
    }

    public function isNative(): bool
    {
        return $this->type === self::TYPE_NATIVE;
    }

    public function isLegacy(): bool
    {
        return $this->type === self::TYPE_LEGACY;
    }

    /**
     * The label shown on the numbered page button.
     *
     * Legacy pages are prefixed with "L" so the archive is distinguishable
     * WITHOUT relying on colour alone (accessibility), and the doctor can tell
     * at a glance which buttons will open read-only evidence.
     */
    public function buttonLabel(): string
    {
        return $this->isLegacy() ? 'L'.$this->workspaceIndex : (string) $this->workspaceIndex;
    }

    /**
     * Compact in-document context, e.g. "Halaman 2 dari 4".
     *
     * Deliberately distinct from the GLOBAL "Halaman X dari Y" of the workspace
     * sequence, so a doctor reading page 2 of a 4-page archive that sits at
     * workspace position 6 of 9 is never confused about which is which.
     */
    public function legacyPageContext(): ?string
    {
        if (! $this->isLegacy()) {
            return null;
        }

        return sprintf('Halaman %d dari %d', $this->legacyPdfPage, $this->legacyPdfPageCount);
    }

    /**
     * Whether a rendered page image can be shown.
     *
     * False means the archive exists and may be read, but this page has no
     * rasterised image (imported before/without a working rasteriser, or the
     * render is missing on disk). The viewer then falls back to the inline
     * source PDF rather than showing a broken image.
     */
    public function hasRenderedPage(): bool
    {
        return $this->isLegacy() && $this->legacyHasRenderedPage && $this->legacyPageImageUrl !== null;
    }
}
