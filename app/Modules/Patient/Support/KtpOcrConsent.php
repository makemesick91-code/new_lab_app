<?php

namespace App\Modules\Patient\Support;

/**
 * PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — the approved KTP OCR
 * consent wording (pilot decision D7).
 *
 * The single reader of config/patient_ktp_ocr_consent.php. The Blade consent
 * panel shows {@see title()} and {@see paragraphs()}; the OCR parse endpoint
 * accepts a request only when it names {@see version()}.
 *
 * Fails closed: wording that is missing or malformed is NOT usable, the panel
 * offers no "agree" button, and {@see acceptableVersions()} is empty so the
 * parse endpoint refuses every request. It never falls back to a default text.
 *
 * This class records no consent and writes nothing. The KTP holder's written
 * consent stays on the clinic's paper form (protocol §3); the application only
 * requires the operator to state, per form session, that it was given.
 */
final class KtpOcrConsent
{
    private const CONFIG = 'patient_ktp_ocr_consent';

    public static function version(): string
    {
        $version = config(self::CONFIG.'.version');

        return is_string($version) ? trim($version) : '';
    }

    public static function title(): string
    {
        $title = config(self::CONFIG.'.title');

        return is_string($title) ? trim($title) : '';
    }

    /**
     * @return list<string>
     */
    public static function paragraphs(): array
    {
        $paragraphs = config(self::CONFIG.'.paragraphs');
        if (! is_array($paragraphs)) {
            return [];
        }

        $clean = [];
        foreach ($paragraphs as $paragraph) {
            if (! is_string($paragraph) || trim($paragraph) === '') {
                return []; // one broken paragraph voids the wording, never a partial text
            }
            $clean[] = trim($paragraph);
        }

        return $clean;
    }

    public static function isUsable(): bool
    {
        return self::version() !== '' && self::title() !== '' && self::paragraphs() !== [];
    }

    /**
     * The versions the parse endpoint accepts: the current one, or none.
     *
     * @return list<string>
     */
    public static function acceptableVersions(): array
    {
        return self::isUsable() ? [self::version()] : [];
    }

    /**
     * Read-only summary for the pilot status command. Carries no wording.
     *
     * @return array{version: string, usable: bool, paragraphs: int}
     */
    public static function summary(): array
    {
        return [
            'version' => self::version(),
            'usable' => self::isUsable(),
            'paragraphs' => count(self::paragraphs()),
        ];
    }
}
