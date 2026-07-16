<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Interfaces\SatusehatDataQualityRuleInterface;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;

abstract class AbstractDataQualityRule implements SatusehatDataQualityRuleInterface
{
    /**
     * Build one PII-free issue draft.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function issue(
        string $severity,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $fieldPath = null,
        ?string $remediation = null,
        ?string $ownerRole = null,
        ?string $initialStatus = null,
        array $metadata = [],
    ): array {
        return [
            'rule_code' => $this->code(),
            'severity' => $severity,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field_path' => $fieldPath,
            'message' => $message,
            'remediation_action' => $remediation,
            'owner_role' => $ownerRole,
            'initial_status' => $initialStatus ?? SatusehatDataQualityIssue::STATUS_OPEN,
            'metadata' => $metadata,
        ];
    }
}
