<?php

declare(strict_types=1);

namespace App\Modules\Patient\Support;

use App\Modules\Patient\Services\PatientMedicalRecordNumberService;

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — the parsed components of a canonical Nomor RM.
 *
 * The format is owned by {@see PatientMedicalRecordNumberService},
 * which has composed it since Sprint 23.8:
 *
 *     DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}
 *
 * This value object is the OUTPUT of the matching parser on that same service.
 * It exists so that no other layer has to re-implement the split: the legacy
 * archive resolves a document's branch from `branchCode`, and a controller, a
 * form request or a Blade view must never carry its own regex for this.
 *
 * It is a pure value: it holds no database state, performs no lookup, and
 * decides nothing about whether the branch it names actually exists.
 */
final class MedicalRecordNumberParts
{
    private function __construct(
        public readonly string $prefix,
        public readonly string $branchCode,
        public readonly string $year,
        /** The manual sequence, preserved VERBATIM (leading zeros included). */
        public readonly string $sequence,
    ) {}

    public static function make(string $prefix, string $branchCode, string $year, string $sequence): self
    {
        return new self($prefix, $branchCode, $year, $sequence);
    }

    /**
     * Recompose the canonical string. Round-tripping a parsed value must always
     * return the original, which is what makes the parser safe to rely on.
     */
    public function toString(): string
    {
        return $this->prefix.'-'.$this->branchCode.'-'.$this->year.'-'.$this->sequence;
    }
}
