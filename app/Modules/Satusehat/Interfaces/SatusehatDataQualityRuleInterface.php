<?php

namespace App\Modules\Satusehat\Interfaces;

use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * SATUSEHAT-4A — data-quality rule contract.
 *
 * Rules are deterministic, idempotent, branch-aware (context is already
 * branch-scoped), side-effect-free on evaluate, and NEVER perform HTTP.
 * A rule returns zero or more issue drafts; persistence + fingerprint
 * idempotency live in SatusehatDataQualityIssueService.
 */
interface SatusehatDataQualityRuleInterface
{
    public function code(): string;

    /**
     * @return list<array{rule_code: string, severity: string, entity_type: ?string, entity_id: ?int, field_path: ?string, message: string, remediation_action: ?string, owner_role: ?string, initial_status: ?string, metadata: array<string, mixed>}>
     */
    public function evaluate(SatusehatDataQualityContext $context): array;
}
