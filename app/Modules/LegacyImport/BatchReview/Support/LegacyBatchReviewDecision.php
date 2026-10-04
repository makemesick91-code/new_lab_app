<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * What a human reviewer attested about ONE document — FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 PR1 §3, §4.
 *
 * These three are operator attestations, not lifecycle states. Only REVIEWED
 * causes anything canonical to happen (it drives one call to the canonical
 * single-item review). BLOCKED and NEEDS_ATTENTION are triage: they record a
 * judgement and withhold the item from publishing without touching the
 * canonical import at all.
 *
 * THERE IS DELIBERATELY NO "APPROVE ALL" VALUE. The vocabulary has no way to
 * express "the whole queue is fine" because the product rule is that review is
 * per item (§1, §13, §24). A value meaning "all of them" is exactly the
 * shortcut this feature must not ship.
 */
final class LegacyBatchReviewDecision
{
    private function __construct() {}

    /** Inspected and attested fit to publish. Drives the canonical review. */
    public const REVIEWED = 'REVIEWED';

    /**
     * Inspected and must NOT be published. Sticky triage; requires a reason.
     * Never calls cancel(), never releases the patient's single-active slot.
     */
    public const BLOCKED = 'BLOCKED';

    /**
     * Inspected and unresolved — someone else, or more information, is needed.
     * Also withheld from publishing, but distinct from BLOCKED so that "I
     * decided no" and "I could not decide" do not collapse into one bucket.
     */
    public const NEEDS_ATTENTION = 'NEEDS_ATTENTION';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REVIEWED, self::BLOCKED, self::NEEDS_ATTENTION];
    }

    public static function isValid(?string $decision): bool
    {
        return $decision !== null && in_array($decision, self::all(), true);
    }

    /**
     * Decisions that raise sticky triage and therefore require a reason code.
     *
     * @return list<string>
     */
    public static function triaging(): array
    {
        return [self::BLOCKED, self::NEEDS_ATTENTION];
    }

    public static function isTriaging(?string $decision): bool
    {
        return $decision !== null && in_array($decision, self::triaging(), true);
    }

    public static function label(?string $decision): string
    {
        return match ($decision) {
            self::REVIEWED => 'Sudah ditinjau',
            self::BLOCKED => 'Ditahan',
            self::NEEDS_ATTENTION => 'Perlu perhatian',
            default => (string) $decision,
        };
    }
}
