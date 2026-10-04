<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

/**
 * An ADVISORY verdict about one document — PR2 §6, §12.
 *
 * READ THIS BEFORE TRUSTING IT. "Eligible" means "the server would accept this
 * right now". It is NOT a promise and it is NOT the gate. Between a pre-flight
 * and the publish itself a colleague may publish the same patient's document,
 * a reviewer may raise a block, a branch may be de-admitted, or the source may
 * drift — so the canonical publish re-decides EVERYTHING under its own row
 * lock, and an item that looked eligible here can still be refused there.
 *
 * That is the design working, not a bug, and it is the same posture the
 * mass-upload ELIGIBLE verdict already documents.
 *
 * What this exists for is §12's honest confirmation screen: "Selected: N,
 * Eligible now: M, Blocked after revalidation: K" — so the operator presses a
 * button that says "Publish M Items" rather than a misleading "Publish All".
 */
final class LegacyBatchPublishEligibility
{
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reasonCode,
        public readonly ?string $reasonMessage,
        /** Non-null when an archive record already exists for this import. */
        public readonly ?int $existingRecordId,
    ) {}

    public static function eligible(): self
    {
        return new self(true, null, null, null);
    }

    public static function refused(string $code, ?string $message = null): self
    {
        return new self(false, $code, $message ?? LegacyBatchPublishReason::label($code), null);
    }

    /**
     * Already in the archive. Refused as an ATTEMPT (there is nothing to do)
     * but benign as an OUTCOME, and the existing record is carried so the
     * report can link to it.
     */
    public static function alreadyPublished(int $recordId): self
    {
        return new self(
            false,
            LegacyBatchPublishReason::ALREADY_PUBLISHED,
            LegacyBatchPublishReason::label(LegacyBatchPublishReason::ALREADY_PUBLISHED),
            $recordId,
        );
    }

    public function isBenign(): bool
    {
        return LegacyBatchPublishReason::isBenign($this->reasonCode);
    }
}
