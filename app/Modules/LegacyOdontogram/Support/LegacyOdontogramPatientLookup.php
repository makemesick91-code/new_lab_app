<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the outcome of one patient lookup.
 *
 * THE DEFECT THIS TYPE EXISTS TO PREVENT. The page used to model the lookup as a
 * nullable `?Patient`. Everything that was not a patient — an identifier the
 * operator could not possibly know, an unknown Nomor RM, a deleted patient, a
 * failing database — collapsed into `null` and rendered the SAME panel the page
 * shows before any input at all. The operator was told nothing, and nothing was
 * logged, because from the template's point of view nothing had happened.
 *
 * A lookup therefore reports a STATE, and every state has its own words:
 *
 *   IDLE       nothing entered yet — this is the only blank state
 *   FOUND      exactly one patient, identity attached
 *   AMBIGUOUS  several patients match; a human must choose (never this service)
 *   NOT_FOUND  an identifier WAS supplied and resolved to nobody
 *   TOO_SHORT  a partial Nomor RM too broad to search safely
 *   TOO_MANY   a partial Nomor RM matching more rows than may be listed
 *   ERROR      the lookup itself failed; the operator may retry
 *
 * AMBIGUOUS never auto-selects. A Nomor RM suffix can legitimately match several
 * patients, and picking one arbitrarily is how a chart ends up in the wrong
 * person's clinical history.
 */
final readonly class LegacyOdontogramPatientLookup
{
    public const STATE_IDLE = 'idle';

    public const STATE_FOUND = 'found';

    public const STATE_AMBIGUOUS = 'ambiguous';

    public const STATE_NOT_FOUND = 'not_found';

    public const STATE_TOO_SHORT = 'too_short';

    public const STATE_TOO_MANY = 'too_many';

    public const STATE_ERROR = 'error';

    /**
     * @param  list<LegacyOdontogramPatientIdentity>  $candidates
     */
    private function __construct(
        public string $state,
        public ?LegacyOdontogramPatientIdentity $identity = null,
        public array $candidates = [],
        public ?string $message = null,
    ) {}

    public static function idle(): self
    {
        return new self(self::STATE_IDLE);
    }

    public static function found(LegacyOdontogramPatientIdentity $identity): self
    {
        return new self(self::STATE_FOUND, identity: $identity, message: 'Pasien ditemukan');
    }

    /**
     * @param  list<LegacyOdontogramPatientIdentity>  $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(
            self::STATE_AMBIGUOUS,
            candidates: $candidates,
            message: 'Beberapa pasien cocok dengan identifier tersebut. Pilih pasien yang benar.',
        );
    }

    public static function notFound(): self
    {
        return new self(self::STATE_NOT_FOUND, message: 'Pasien tidak ditemukan');
    }

    public static function tooShort(int $minimumLength): self
    {
        return new self(self::STATE_TOO_SHORT, message: sprintf(
            'Masukkan minimal %d karakter Nomor RM.',
            $minimumLength,
        ));
    }

    public static function tooMany(): self
    {
        return new self(self::STATE_TOO_MANY, message: 'Terlalu banyak hasil. Lengkapi Nomor RM agar lebih spesifik.');
    }

    /**
     * Deliberately generic. The operator gets a retry instruction; the cause
     * goes to the log, never to the screen.
     */
    public static function error(): self
    {
        return new self(self::STATE_ERROR, message: 'Gagal mengambil data pasien. Silakan coba lagi.');
    }

    public function isFound(): bool
    {
        return $this->state === self::STATE_FOUND;
    }

    public function isIdle(): bool
    {
        return $this->state === self::STATE_IDLE;
    }

    public function isError(): bool
    {
        return $this->state === self::STATE_ERROR;
    }

    public function isAmbiguous(): bool
    {
        return $this->state === self::STATE_AMBIGUOUS;
    }
}
