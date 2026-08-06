<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1B — the complete outcome of rasterizing one document.
 *
 * Pages are always returned normalized and ordered by page number, so the
 * caller never has to reason about the raw output filenames a renderer happens
 * to produce.
 */
final class LegacyRmeRasterizationResult
{
    /**
     * @param  list<LegacyRmeRenderedPage>  $pages
     */
    private function __construct(
        public readonly array $pages,
        public readonly int $dpi,
        public readonly string $format,
    ) {}

    /**
     * @param  array<int, LegacyRmeRenderedPage>  $pages
     */
    public static function make(array $pages, int $dpi, string $format = 'png'): self
    {
        $ordered = array_values($pages);
        usort($ordered, fn (LegacyRmeRenderedPage $a, LegacyRmeRenderedPage $b) => $a->pageNumber <=> $b->pageNumber);

        return new self($ordered, $dpi, $format);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * @return list<int>
     */
    public function pageNumbers(): array
    {
        return array_map(fn (LegacyRmeRenderedPage $page) => $page->pageNumber, $this->pages);
    }
}
