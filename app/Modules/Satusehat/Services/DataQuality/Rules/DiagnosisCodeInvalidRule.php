<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * SATUSEHAT-4B — a recorded diagnosis references a master code that fails the
 * official code-system format (config-declared pattern; unknown systems are
 * never guessed/format-checked). HARD — invalid terminology can never be
 * waived into SATUSEHAT-ready status.
 */
class DiagnosisCodeInvalidRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'diagnosis_code_invalid';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $patterns = (array) config('clinical_diagnosis_rollout.code_patterns', []);
        if ($patterns === []) {
            return [];
        }

        $issues = [];
        $seenMasterIds = [];

        foreach ($context->diagnoses() as $dx) {
            $master = $dx->clinicalDiagnosis;
            if ($master === null || isset($seenMasterIds[(int) $master->id])) {
                continue;
            }
            $seenMasterIds[(int) $master->id] = true;

            $pattern = $patterns[(string) $master->code_system] ?? null;
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (preg_match($pattern, (string) $master->code) !== 1) {
                $issues[] = $this->issue(
                    SatusehatDataQualityIssue::SEVERITY_HARD,
                    "Kode diagnosis \"{$master->code}\" tidak sesuai format resmi {$master->code_system}.",
                    'clinical_diagnosis',
                    (int) $master->id,
                    'code',
                    'Deprecate terminologi ini dan buat entri baru dengan kode resmi yang benar.',
                    'Clinical Reviewer',
                    null,
                    ['diagnosis_code' => (string) $master->code, 'code_system' => (string) $master->code_system],
                );
            }
        }

        return $issues;
    }
}
