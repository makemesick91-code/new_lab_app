<?php

namespace Database\Seeders;

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use Illuminate\Database\Seeder;

/**
 * SATUSEHAT-4A — small ICD-10 dental reference set (WHO ICD-10, public
 * classification). Idempotent (firstOrCreate on code_system+code). These are
 * CLINICAL master entries only — no SATUSEHAT mapping is created or activated
 * here; Condition mappings still require the reviewed mapping lifecycle.
 */
class ClinicalDiagnosisSeeder extends Seeder
{
    /** @var list<array{code: string, display: string}> */
    private const ICD10_DENTAL = [
        ['code' => 'K00.6', 'display' => 'Disturbances in tooth eruption'],
        ['code' => 'K01.1', 'display' => 'Impacted teeth'],
        ['code' => 'K02.1', 'display' => 'Caries of dentine'],
        ['code' => 'K02.9', 'display' => 'Dental caries, unspecified'],
        ['code' => 'K03.6', 'display' => 'Deposits (accretions) on teeth'],
        ['code' => 'K04.0', 'display' => 'Pulpitis'],
        ['code' => 'K04.1', 'display' => 'Necrosis of pulp'],
        ['code' => 'K04.5', 'display' => 'Chronic apical periodontitis'],
        ['code' => 'K04.7', 'display' => 'Periapical abscess without sinus'],
        ['code' => 'K05.0', 'display' => 'Acute gingivitis'],
        ['code' => 'K05.1', 'display' => 'Chronic gingivitis'],
        ['code' => 'K05.3', 'display' => 'Chronic periodontitis'],
        ['code' => 'K08.1', 'display' => 'Loss of teeth due to accident, extraction or local periodontal disease'],
        ['code' => 'K08.3', 'display' => 'Retained dental root'],
        ['code' => 'Z01.2', 'display' => 'Dental examination'],
    ];

    public function run(): void
    {
        foreach (self::ICD10_DENTAL as $row) {
            ClinicalDiagnosis::query()->firstOrCreate(
                ['code_system' => 'ICD-10', 'code' => $row['code']],
                [
                    'display' => $row['display'],
                    'status' => ClinicalDiagnosis::STATUS_ACTIVE,
                    'source' => 'WHO ICD-10',
                ],
            );
        }
    }
}
