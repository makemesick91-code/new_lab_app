<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Carbon\CarbonInterface;

/**
 * LEGACY-RME-PDF-1C — one row of the patient's merged RME history.
 *
 * Legacy and native RME stay SEPARATE domain entities: a legacy record is never
 * converted into a ClinicVisit or a native MedicalRecord, and a native visit is
 * never rewritten as an archive row. This value object is a presentation
 * projection built for one screen — it carries no behaviour and owns no state.
 *
 * PII-free by construction: a date, a kind, short labels and an already-built
 * URL. Never a KTP/NIK, never a storage path, never clinical content.
 */
final class LegacyRmeTimelineEntry
{
    public const KIND_LEGACY = 'LEGACY';

    public const KIND_NATIVE = 'NATIVE';

    private function __construct(
        public readonly string $kind,
        public readonly ?CarbonInterface $date,
        public readonly string $label,
        public readonly ?string $reference,
        public readonly ?string $detail,
        public readonly ?string $url,
        public readonly int $sourceId,
        public readonly ?CarbonInterface $endDate = null,
        public readonly bool $isCurrent = false,
    ) {}

    /**
     * LEGACY-RME-PDF-HISTORY-1 — `$endDate` is the archive's LATEST clinical
     * date (`latest_rme_date`). One legacy PDF may cover several encounters, so
     * rendering only the representative (earliest) date would claim the
     * document is a single visit.
     */
    public static function legacy(
        ?CarbonInterface $date,
        string $label,
        ?string $detail,
        ?string $url,
        int $sourceId,
        ?CarbonInterface $endDate = null,
    ): self {
        return new self(
            self::KIND_LEGACY,
            $date,
            $label,
            null,
            $detail,
            $url,
            $sourceId,
            self::normalizeEndDate($date, $endDate),
        );
    }

    public static function native(
        ?CarbonInterface $date,
        string $label,
        ?string $reference,
        ?string $detail,
        ?string $url,
        int $sourceId,
        bool $isCurrent = false,
    ): self {
        return new self(
            self::KIND_NATIVE,
            $date,
            $label,
            $reference,
            $detail,
            $url,
            $sourceId,
            null,
            $isCurrent,
        );
    }

    public function isLegacy(): bool
    {
        return $this->kind === self::KIND_LEGACY;
    }

    /**
     * Whether this entry spans several clinical dates rather than one.
     */
    public function hasDateRange(): bool
    {
        return $this->endDate !== null;
    }

    /**
     * The entry's clinical date for display: a single date, or the archive's
     * earliest–latest range when the document covers several encounters.
     *
     * Uses the same `d/m/Y` presentation as the rest of the RME history so a
     * legacy row and a native row read on one scale.
     */
    public function dateLabel(): string
    {
        if ($this->date === null) {
            return '—';
        }

        $start = $this->date->format('d/m/Y');

        return $this->endDate === null
            ? $start
            : $start.' – '.$this->endDate->format('d/m/Y');
    }

    /**
     * A latest date is only a RANGE when it is strictly after the earliest one.
     *
     * An equal value is a single-date document, and an inverted value (bad or
     * partially migrated data) would render a backwards range that misstates
     * the patient's history — in both cases the entry falls back to the single
     * representative date rather than showing something misleading.
     */
    private static function normalizeEndDate(?CarbonInterface $date, ?CarbonInterface $endDate): ?CarbonInterface
    {
        if ($date === null || $endDate === null) {
            return null;
        }

        return $endDate->greaterThan($date) ? $endDate : null;
    }

    /**
     * Deterministic ordering key: clinical date first, then kind, then the
     * source id. The 1A date rule makes a legacy/native date collision
     * impossible for valid data, but the ordering must still be stable if one
     * ever occurs — never left to the database's row order.
     */
    public function sortKey(): string
    {
        return sprintf(
            '%s|%d|%012d',
            $this->date?->format('Y-m-d') ?? '9999-12-31',
            $this->isLegacy() ? 0 : 1,
            $this->sourceId,
        );
    }
}
