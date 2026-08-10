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
    ) {}

    public static function legacy(
        ?CarbonInterface $date,
        string $label,
        ?string $detail,
        ?string $url,
        int $sourceId,
    ): self {
        return new self(self::KIND_LEGACY, $date, $label, null, $detail, $url, $sourceId);
    }

    public static function native(
        ?CarbonInterface $date,
        string $label,
        ?string $reference,
        ?string $detail,
        ?string $url,
        int $sourceId,
    ): self {
        return new self(self::KIND_NATIVE, $date, $label, $reference, $detail, $url, $sourceId);
    }

    public function isLegacy(): bool
    {
        return $this->kind === self::KIND_LEGACY;
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
