<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

/**
 * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — the vocabulary of tariff APPROVAL
 * PROVENANCE (owner D21h) and the single rule for an approval reference.
 *
 * A tariff carries provenance only when it arrived through the governed import
 * with an owner-approval reference. "Positive price in force" (coverage) and
 * "approved price in force" (provenance) are separate questions: a branch is
 * strict-ELIGIBLE only when both are true.
 *
 *   VERIFIED    every tariff in force carries an approval reference (at least one)
 *   UNVERIFIED  at least one tariff in force carries none (seeded, typed in master
 *               data, or edited after its approval)
 *   NONE        no tariff in force at all
 */
final class TariffProvenance
{
    public const VERIFIED = 'VERIFIED';

    public const UNVERIFIED = 'UNVERIFIED';

    public const NONE = 'NONE';

    public const REFERENCE_MAX = 120;

    /**
     * A single token, 6–120 printable ASCII characters, starting with a letter
     * or digit, with no run of 13+ digits: it is a pointer to the owner's approval
     * evidence (a signed price list, a decision record), never free text, never a
     * patient identifier and never something a spreadsheet would read as a formula.
     */
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{5,119}$/D';

    private function __construct() {}

    /**
     * Normalize a supplied reference: trimmed, or null when absent/blank. Does
     * NOT validate — call isValidReference() on the result.
     */
    public static function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $trimmed = trim($reference);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function isValidReference(string $reference): bool
    {
        // A run of 13+ digits is the shape of a KTP/NIK or phone number; a
        // governance reference never needs one, and this value lands in the
        // audit trail (security review).
        return preg_match(self::REFERENCE_PATTERN, $reference) === 1
            && preg_match('/\d{13,}/', $reference) !== 1;
    }

    /**
     * @param  int  $inForce  tariffs in force (resolved, positive)
     * @param  int  $verified  of those, how many carry an approval reference
     */
    public static function classify(int $inForce, int $verified): string
    {
        return match (true) {
            $inForce === 0 => self::NONE,
            $verified === $inForce => self::VERIFIED,
            default => self::UNVERIFIED,
        };
    }
}
