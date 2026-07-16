<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4A — master clinical diagnosis governance. Master entries are
 * clinical reference data only; SATUSEHAT mapping stays a separate, reviewed
 * lifecycle in mst_satusehat_code_mappings.
 */
class ClinicalDiagnosisService
{
    /**
     * @param  array{code_system?: string, code: string, display: string, source?: ?string, notes?: ?string}  $data
     */
    public function create(array $data, User $actor): ClinicalDiagnosis
    {
        $codeSystem = trim((string) ($data['code_system'] ?? 'ICD-10'));
        $code = trim((string) $data['code']);

        return DB::transaction(function () use ($codeSystem, $code, $data, $actor) {
            $existing = ClinicalDiagnosis::withTrashed()
                ->where('code_system', $codeSystem)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages([
                    'code' => 'Kombinasi code system + kode diagnosis sudah terdaftar.',
                ]);
            }

            if ($existing !== null) {
                // Restore + refresh instead of violating the unique constraint.
                $existing->restore();
                $existing->update([
                    'display' => trim((string) $data['display']),
                    'status' => ClinicalDiagnosis::STATUS_ACTIVE,
                    'source' => $data['source'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                return $existing;
            }

            return ClinicalDiagnosis::create([
                'code_system' => $codeSystem,
                'code' => $code,
                'display' => trim((string) $data['display']),
                'status' => ClinicalDiagnosis::STATUS_ACTIVE,
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function deprecate(ClinicalDiagnosis $diagnosis, User $actor): ClinicalDiagnosis
    {
        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_DEPRECATED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $diagnosis;
    }
}
