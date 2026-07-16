<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Every structured diagnosis needs an ACTIVE, clinically reviewed SATUSEHAT
 * Condition mapping. Codes are never guessed — a missing mapping goes to
 * clinical review, never auto-created.
 */
class DiagnosisMappingRule extends AbstractDataQualityRule
{
    public function __construct(
        private readonly SatusehatMappingRepositoryInterface $mappings,
    ) {}

    public function code(): string
    {
        return 'diagnosis_mapping';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $issues = [];

        foreach ($context->diagnoses() as $dx) {
            /** @var MedicalRecordDiagnosis $dx */
            $master = $dx->clinicalDiagnosis;
            if ($master === null) {
                continue;
            }

            $mapping = $this->mappings->findActive(
                $context->environment,
                'diagnosis',
                (int) $master->id,
                (string) $master->code,
                SatusehatSubmissionItem::RESOURCE_CONDITION,
            );

            if ($mapping === null) {
                $issues[] = $this->issue(
                    SatusehatDataQualityIssue::SEVERITY_SOFT,
                    "Diagnosis {$master->code} belum memiliki mapping SATUSEHAT aktif.",
                    'clinical_diagnosis',
                    (int) $master->id,
                    'condition_mapping',
                    'Buat + review mapping Condition pada halaman Mapping Kode.',
                    'Clinical Reviewer',
                    null,
                    ['diagnosis_code' => (string) $master->code],
                );
            }
        }

        return $issues;
    }
}
