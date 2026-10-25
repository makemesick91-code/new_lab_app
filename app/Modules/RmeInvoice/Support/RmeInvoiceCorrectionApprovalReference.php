<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the owner's case-specific approval
 * reference for a correction (rule 202 form; owner decisions PP-2 and PP-7).
 *
 * The filled form stays in the clinic's private channel; only its REFERENCE is
 * stored: 6–120 characters of `[A-Za-z0-9._:/-]`, never 13 or more digits in one
 * run of numbers — counted ACROSS the allowed punctuation, so `3171-0123-4567-8901`
 * or `317101234567.8901` (a NIK split by separators) is refused like the unbroken
 * form. A letter ends a run, which is what lets an invoice number such as
 * `RME-202610-000042` (12 digits) through. A phone number of 12 digits or fewer is
 * NOT caught by this threshold; that residual is recorded in rule 207 §D.
 *
 * MANDATORY for a POST_PAYMENT correction — enforced by the service under the
 * invoice lock (the type is only known there) and backed by a database CHECK.
 * OPTIONAL for a PRE_PAYMENT correction, where it is stored if given: requiring it
 * there is an open owner question and is not decided here.
 *
 * The application can check that a reference was RECORDED and is well-formed; it
 * cannot check that the Owner actually signed the form behind it. That remains a
 * human control, and nothing here may be reported as proof of it.
 */
final class RmeInvoiceCorrectionApprovalReference
{
    public const MIN = 6;

    public const MAX = 120;

    private const PATTERN = '/^[A-Za-z0-9._:\/-]{6,120}$/D';

    /** A run of digits that may be split by the separators the pattern allows. */
    private const NUMBER_RUN = '/\d+(?:[._:\/-]+\d+)*/';

    /** At or above this many digits in one run, a reference could carry a NIK/KTP. */
    public const MAX_DIGITS_PER_RUN = 12;

    /**
     * The trimmed reference, or null when none was given. A given but malformed
     * reference is refused — it is never silently dropped.
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            self::refuse();
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $value) !== 1 || self::longestDigitRun($value) > self::MAX_DIGITS_PER_RUN) {
            self::refuse();
        }

        return $value;
    }

    /** The most digits found in one run of numbers, separators between them ignored. */
    public static function longestDigitRun(string $value): int
    {
        preg_match_all(self::NUMBER_RUN, $value, $matches);

        $longest = 0;
        foreach ($matches[0] as $run) {
            $longest = max($longest, strlen((string) preg_replace('/\D/', '', $run)));
        }

        return $longest;
    }

    /**
     * @throws ValidationException
     */
    private static function refuse(): never
    {
        throw ValidationException::withMessages([
            'owner_approval_reference' => '[CORRECTION_OWNER_APPROVAL_REFERENCE_INVALID] Referensi persetujuan Owner harus 6–120 karakter (huruf, angka, . _ : / -) dan tidak boleh memuat 13 digit atau lebih dalam satu rangkaian angka (termasuk angka yang dipisah tanda baca).',
        ]);
    }
}
