<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * Rule 203 — the ONLY two ways a billing dispute may be resolved (and its
 * payment hold released). Both are derived from processes the repository
 * already authorizes; nothing here invents a financial power.
 *
 * CORRECTION_APPLIED — SYSTEM-VERIFIED. An invoice correction that went through
 *   the existing maker-checker workflow (D1/D1a/D1e, rule 195–201) is APPLIED and
 *   produced the invoice's CURRENT version, and that version is newer than the
 *   one that was disputed. The two-account review already happened inside the
 *   correction, so this outcome is not self-certified. It is only reachable where
 *   the correction capability exists (today: the SPN4 pilot, maker 29 / checker 1).
 *
 * ORIGINAL_CONFIRMED_CORRECT — HUMAN DETERMINATION by the branch dispute
 *   authority that the invoice as issued is right (the report was mistaken).
 *   Because nothing in the system can verify it, it is refused to the reporter
 *   (a second account must decide), needs a written reason and a resolution
 *   reference, and only applies to the invoice exactly as it was disputed (same
 *   correction_version, no correction in flight).
 *
 * NOT outcomes, deliberately: write-off, debt forgiveness, cancellation, a price
 * override, Rp0, PAID, refund, "patient left", "correction rejected/cancelled".
 * None of those is an authorized way to make a known-wrong invoice collectable.
 */
final class RmeBillingDisputeOutcome
{
    public const CORRECTION_APPLIED = 'CORRECTION_APPLIED';

    public const ORIGINAL_CONFIRMED_CORRECT = 'ORIGINAL_CONFIRMED_CORRECT';

    public const LABELS = [
        self::CORRECTION_APPLIED => 'Selesai dengan koreksi tagihan yang telah diterapkan',
        self::ORIGINAL_CONFIRMED_CORRECT => 'Tagihan asli dinyatakan benar',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $outcome): string
    {
        return $outcome === null ? '-' : (self::LABELS[$outcome] ?? $outcome);
    }
}
