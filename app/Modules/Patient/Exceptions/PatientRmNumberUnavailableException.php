<?php

declare(strict_types=1);

namespace App\Modules\Patient\Exceptions;

use RuntimeException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — no automatic Nomor
 * RM can be issued: the branch is MAIN (never an RME branch) or the branch-year
 * sequence has reached its digit cap. The message is operator-facing and carries
 * no number.
 */
class PatientRmNumberUnavailableException extends RuntimeException
{
    public static function mainBranch(): self
    {
        return new self('Nomor RM otomatis tidak dapat dibuat untuk cabang MAIN. Pilih cabang pelayanan.');
    }

    public static function unknownBranch(): self
    {
        return new self('Nomor RM otomatis tidak dapat dibuat: kode cabang tidak dikenali.');
    }

    public static function exhausted(): self
    {
        return new self('Nomor RM otomatis untuk cabang dan tahun ini telah mencapai batas. Hubungi Supervisor RME.');
    }
}
