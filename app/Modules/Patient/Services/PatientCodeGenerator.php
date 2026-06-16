<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;

/**
 * Sprint 23 Phase 23.3 — patient code (medical record number) generator.
 *
 * Produces a collision-safe, configurable identifier for new patients.
 * Format and prefix come from config/patient.php and are TEMPORARY until the
 * clinic owner confirms a final business format. Existing patients keep their
 * stored code untouched.
 */
class PatientCodeGenerator
{
    /**
     * Generate the next available patient code for the given period.
     */
    public function generate(?Carbon $period = null): string
    {
        $config = config('patient.code');
        $period = $period ?? Carbon::now();

        $prefix = (string) ($config['prefix'] ?? 'DG');
        $separator = (string) ($config['separator'] ?? '-');
        $periodFormat = (string) ($config['period_format'] ?? 'Ym');
        $seqLength = max(1, (int) ($config['seq_length'] ?? 6));

        $segments = array_filter([
            $prefix,
            $periodFormat !== '' ? $period->format($periodFormat) : null,
        ], fn ($segment) => $segment !== null && $segment !== '');

        $base = implode($separator, $segments).$separator;

        $next = $this->nextSequence($base);

        // Guard against rare races / pre-existing manual codes by advancing
        // the sequence until an unused code is found.
        do {
            $code = $base.str_pad((string) $next, $seqLength, '0', STR_PAD_LEFT);
            $next++;
        } while ($this->codeExists($code));

        return $code;
    }

    private function nextSequence(string $base): int
    {
        $last = Patient::withTrashed()
            ->where('medical_record_number', 'like', $base.'%')
            ->orderByDesc('medical_record_number')
            ->value('medical_record_number');

        if ($last === null) {
            return 1;
        }

        $suffix = substr($last, strlen($base));

        return ctype_digit($suffix) ? ((int) $suffix) + 1 : 1;
    }

    private function codeExists(string $code): bool
    {
        return Patient::withTrashed()
            ->where('medical_record_number', $code)
            ->exists();
    }
}
