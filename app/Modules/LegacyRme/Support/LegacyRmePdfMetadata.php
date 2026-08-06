<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1B — structural facts about a source PDF.
 *
 * Produced by a LegacyRmePdfInspectorInterface implementation. Carries only
 * structure — never document text, never author/producer metadata, and never
 * anything that could hold clinical content or patient identity.
 *
 * Notably absent by design: any notion of a "document date". The legacy RME
 * date is chosen manually by the operator and is NEVER derived from PDF
 * metadata (a 1A domain invariant).
 */
final class LegacyRmePdfMetadata
{
    /**
     * @param  array<int, array{page: int, width: float, height: float}>  $pageDimensions
     */
    private function __construct(
        public readonly int $pageCount,
        public readonly bool $encrypted,
        public readonly ?string $pdfVersion,
        public readonly array $pageDimensions,
    ) {}

    /**
     * @param  array<int, array{page: int, width: float, height: float}>  $pageDimensions
     */
    public static function make(
        int $pageCount,
        bool $encrypted = false,
        ?string $pdfVersion = null,
        array $pageDimensions = [],
    ): self {
        return new self($pageCount, $encrypted, $pdfVersion, array_values($pageDimensions));
    }

    /**
     * The largest page edge in PDF points, or null when no dimension is known.
     */
    public function largestDimension(): ?float
    {
        $largest = null;

        foreach ($this->pageDimensions as $dimension) {
            $largest = max($largest ?? 0.0, (float) $dimension['width'], (float) $dimension['height']);
        }

        return $largest;
    }

    /**
     * Pixels the largest page would occupy when rendered at the given DPI.
     * Used to refuse a render that would exhaust the worker.
     */
    public function largestPagePixelsAt(int $dpi): ?int
    {
        if ($this->pageDimensions === [] || $dpi <= 0) {
            return null;
        }

        $largest = 0;

        foreach ($this->pageDimensions as $dimension) {
            $pixels = (int) round(((float) $dimension['width'] / 72 * $dpi) * ((float) $dimension['height'] / 72 * $dpi));
            $largest = max($largest, $pixels);
        }

        return $largest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page_count' => $this->pageCount,
            'encrypted' => $this->encrypted,
            'pdf_version' => $this->pdfVersion,
            'page_dimensions' => $this->pageDimensions,
        ];
    }
}
