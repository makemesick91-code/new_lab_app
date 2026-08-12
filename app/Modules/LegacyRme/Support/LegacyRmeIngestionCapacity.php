<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-3 — the measured verdict on whether the rendering
 * pipeline has room for new legacy migration work.
 *
 * Immutable and PII-free: queue depths, ages and byte counts describe
 * infrastructure, never a patient or a document.
 *
 * Callers branch on the CODE. `available` means every enforced threshold that
 * could be measured was inside its limit — it never means "we could not tell".
 */
final class LegacyRmeIngestionCapacity
{
    public const CODE_AVAILABLE = 'CAPACITY_AVAILABLE';

    /** Too many documents already waiting on the dedicated render queue. */
    public const CODE_QUEUE_DEPTH = 'CAPACITY_QUEUE_DEPTH';

    /** The oldest queued document is too old — the worker is not draining. */
    public const CODE_QUEUE_STALLED = 'CAPACITY_QUEUE_STALLED';

    /** The private disk no longer has room to rasterize safely. */
    public const CODE_DISK_LOW = 'CAPACITY_DISK_LOW';

    /** @var list<string> */
    public const CODES = [
        self::CODE_AVAILABLE,
        self::CODE_QUEUE_DEPTH,
        self::CODE_QUEUE_STALLED,
        self::CODE_DISK_LOW,
    ];

    /**
     * @param  array<string, scalar|null>  $measurements
     */
    private function __construct(
        public readonly bool $available,
        public readonly string $code,
        public readonly ?string $message,
        public readonly array $measurements,
    ) {}

    /**
     * @param  array<string, scalar|null>  $measurements
     */
    public static function available(array $measurements): self
    {
        return new self(true, self::CODE_AVAILABLE, null, $measurements);
    }

    /**
     * @param  array<string, scalar|null>  $measurements
     */
    public static function saturated(string $code, string $message, array $measurements): self
    {
        return new self(false, $code, $message, $measurements);
    }

    public function isSaturated(): bool
    {
        return ! $this->available;
    }

    /**
     * PII-free audit context. Only the decision and the threshold that tripped
     * are recorded; the full measurement set belongs in an operator report, not
     * in the clinical audit trail.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'rule_code' => $this->code,
            'pending_jobs' => $this->measurements['pending_jobs'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }
}
