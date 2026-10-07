<?php

namespace App\Modules\PatientMerge\Support;

use Carbon\CarbonInterface;

/**
 * What the duplicate-resolution screens and audit rows may show of a person.
 *
 * KTP/NIK is masked to its last four digits, phone numbers to their last
 * four — enough for an operator holding the patient's card to recognise
 * them, never enough to reconstruct the number. The full values are only
 * ever compared on the server.
 */
final class PatientIdentityMask
{
    public static function ktp(?string $ktp): ?string
    {
        $ktp = trim((string) $ktp);

        if ($ktp === '') {
            return null;
        }

        return mb_strlen($ktp) <= 4 ? str_repeat('*', mb_strlen($ktp)) : '************'.mb_substr($ktp, -4);
    }

    public static function phone(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        return mb_strlen($digits) <= 4 ? str_repeat('*', mb_strlen($digits)) : str_repeat('*', mb_strlen($digits) - 4).mb_substr($digits, -4);
    }

    /** Display form of one identity field — masked when the field is sensitive. */
    public static function field(string $field, mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d-m-Y');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return match ($field) {
            'ktp_number' => self::ktp((string) $value),
            'phone', 'whatsapp_number' => self::phone((string) $value),
            'gender' => match (mb_strtolower((string) $value)) {
                'male' => 'Laki-laki',
                'female' => 'Perempuan',
                'other' => 'Lainnya',
                default => (string) $value,
            },
            'date_of_birth' => date('d-m-Y', strtotime(substr((string) $value, 0, 10)) ?: 0),
            default => mb_strimwidth((string) $value, 0, 120, '…'),
        };
    }
}
