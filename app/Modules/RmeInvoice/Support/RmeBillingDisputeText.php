<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use Illuminate\Validation\ValidationException;

/**
 * Rules 203/204 — the ONE free-text rule of the billing dispute domain:
 * 10–1000 characters, and never a run of 13+ digits (an identity number such as
 * a 16-digit NIK is never billing prose).
 *
 * Shared by the dispute lifecycle (description, notes, resolution reason) and
 * branch reviewer designations (grant and revoke reasons), so the strictest
 * action can never end up with the loosest text check. Free text is stored in its
 * own record only — audit rows record its LENGTH.
 */
final class RmeBillingDisputeText
{
    public const MIN = 10;

    public const MAX = 1000;

    private const IDENTITY_NUMBER_PATTERN = '/\d{13,}/';

    public static function assert(mixed $value, string $field, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < self::MIN || $length > self::MAX) {
            throw ValidationException::withMessages([
                $field => "{$label} wajib diisi ".self::MIN.'–'.self::MAX.' karakter.',
            ]);
        }

        if (self::containsIdentityNumber($text)) {
            throw ValidationException::withMessages([
                $field => "{$label} tidak boleh memuat NIK atau nomor identitas (deretan 13 angka atau lebih).",
            ]);
        }

        return $text;
    }

    public static function containsIdentityNumber(string $text): bool
    {
        return preg_match(self::IDENTITY_NUMBER_PATTERN, $text) === 1;
    }
}
