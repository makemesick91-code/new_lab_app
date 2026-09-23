<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use RuntimeException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — a run refusing to proceed.
 *
 * Carries a MACHINE CODE alongside the operator's message. The message is
 * Indonesian prose for a human; the code is what a test asserts on and what a
 * wrapper script branches on. Asserting on prose makes a translation a test
 * failure and a reworded sentence a silent loss of coverage.
 *
 * Every one of these is a FAIL-CLOSED path. Nothing here is recoverable by
 * retrying the same command: either the estate is larger than the declared
 * ceiling, or the operator's approval does not match the delta in front of
 * them. Both are answered by looking, not by forcing.
 */
final class DoctorDeviceBulkAuthorizationRefusal extends RuntimeException
{
    /**
     * `refusalCode`, not `code`: \Exception already declares a non-readonly
     * int `$code`, and redeclaring it readonly is a fatal error. A domain code
     * and an exception code are different things anyway.
     */
    private function __construct(
        public readonly string $refusalCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The estate is bigger than this tool was authorised to reason about.
     *
     * Refusing beats degrading. The alternative — paginating, sampling, or just
     * loading it anyway — turns a bound into a suggestion, and a bulk writer
     * that quietly handles more than it was reviewed for is the failure this
     * ceiling exists to prevent.
     */
    public static function estateTooLarge(int $found, int $ceiling): self
    {
        return new self(
            'ESTATE_EXCEEDS_CEILING',
            sprintf(
                'Registri perangkat berisi %d baris, melebihi batas %d yang dideklarasikan di config/doctor_access.php. '
                .'Tinjau batas tersebut secara sadar sebelum menjalankan provisioning massal.',
                $found,
                $ceiling,
            ),
        );
    }

    /** The matrix itself, not the device list, is beyond the reviewed bound. */
    public static function matrixTooLarge(int $found, int $ceiling): self
    {
        return new self(
            'MATRIX_EXCEEDS_CEILING',
            sprintf(
                'Matriks otorisasi berisi %d pasangan, melebihi batas %d yang dideklarasikan di config/doctor_access.php.',
                $found,
                $ceiling,
            ),
        );
    }

    /**
     * The approval token does not match the delta computed from live state.
     *
     * Either the operator pasted the wrong value, or the estate moved between
     * the preview and the write. Both mean the same thing: nobody has read the
     * delta that is about to be applied.
     */
    public static function planDigestMismatch(string $supplied, string $actual): self
    {
        return new self(
            'PLAN_DIGEST_MISMATCH',
            sprintf(
                'Rencana berubah sejak pratinjau: --confirm-plan=%s tidak cocok dengan rencana saat ini (%s). '
                .'Jalankan ulang tanpa --apply, baca deltanya, lalu konfirmasi dengan nilai yang baru.',
                $supplied,
                $actual,
            ),
        );
    }

    public static function planDigestMissing(string $actual): self
    {
        return new self(
            'PLAN_DIGEST_REQUIRED',
            sprintf(
                '--apply memerlukan --confirm-plan=%s, yaitu sidik rencana yang dicetak oleh pratinjau. '
                .'Konfirmasi terikat pada delta baris yang tepat, bukan pada waktu.',
                $actual,
            ),
        );
    }
}
