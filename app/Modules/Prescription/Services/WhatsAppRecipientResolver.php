<?php

namespace App\Modules\Prescription\Services;

use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — resolve the number a
 * prescription may actually be sent to.
 *
 * Deterministic and fail-closed: an unusable number raises instead of silently
 * dialling something else. A patient's WhatsApp number wins over the general
 * phone number; neither is ever invented.
 */
class WhatsAppRecipientResolver
{
    public function resolveForPatient(?Patient $patient): string
    {
        if ($patient === null) {
            throw new WhatsAppDeliveryException('Data pasien tidak ditemukan untuk resep ini.');
        }

        foreach ([$patient->whatsapp_number, $patient->phone] as $candidate) {
            $normalised = $this->normalise((string) ($candidate ?? ''));

            if ($normalised !== null) {
                return $normalised;
            }
        }

        throw new WhatsAppDeliveryException(
            'Pasien belum memiliki nomor WhatsApp/telepon yang valid. Lengkapi data pasien terlebih dahulu.'
        );
    }

    /**
     * Normalise an Indonesian number to the E.164 digits Cloud API expects
     * (no leading '+'). Returns null when the value cannot be trusted.
     */
    public function normalise(string $raw): ?string
    {
        $country = (string) config('whatsapp.recipient.default_country_code', '62');
        $min = (int) config('whatsapp.recipient.min_digits', 9);
        $max = (int) config('whatsapp.recipient.max_digits', 15);

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // 0812... -> 62812...   |   62812... stays   |   812... -> 62812...
        if (str_starts_with($digits, '0')) {
            $digits = $country.ltrim(substr($digits, 1), '0');
        } elseif (! str_starts_with($digits, $country)) {
            $digits = $country.$digits;
        }

        $subscriber = substr($digits, strlen($country));

        // Reject a country code with nothing (or nonsense) behind it.
        if ($subscriber === '' || ! ctype_digit($subscriber)) {
            return null;
        }

        return (strlen($digits) >= $min && strlen($digits) <= $max) ? $digits : null;
    }
}
