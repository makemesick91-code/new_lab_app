<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

/**
 * What actually happened when ONE document was published — PR2 §4, §8.
 *
 * An outcome, never a decision. The adapter calls the canonical publish service
 * and reports what that service did; this object re-decides nothing.
 *
 * `created` IS THE IDEMPOTENCY SIGNAL AND IT MATTERS. The canonical publish
 * returns an EXISTING record with `created: false` when one is already filed
 * for that import — that is how a double-clicked confirm, a replayed request,
 * and two concurrent runs racing the same document all converge on exactly one
 * publication. A run must count that as "already published", not as a fresh
 * publication, or a report of 100 items could claim 103.
 */
final class LegacyBatchPublishOutcome
{
    private function __construct(
        public readonly bool $published,
        public readonly ?int $recordId,
        public readonly bool $created,
        public readonly ?string $reasonCode,
        public readonly ?string $reasonMessage,
    ) {}

    /** The canonical publish created a new archive record. */
    public static function created(int $recordId): self
    {
        return new self(true, $recordId, true, null, null);
    }

    /**
     * The canonical publish found an existing record and returned it.
     *
     * Reported as published (the document IS in the archive) but NOT created,
     * and tagged ALREADY_PUBLISHED so the report can distinguish the two.
     */
    public static function alreadyPublished(int $recordId): self
    {
        return new self(
            true,
            $recordId,
            false,
            LegacyBatchPublishReason::ALREADY_PUBLISHED,
            LegacyBatchPublishReason::label(LegacyBatchPublishReason::ALREADY_PUBLISHED),
        );
    }

    public static function refused(string $code, ?string $message): self
    {
        return new self(false, null, false, $code, $message);
    }

    public function isRefused(): bool
    {
        return ! $this->published;
    }
}
