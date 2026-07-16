<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;

/**
 * SATUSEHAT-4A — production onboarding checklist. Boolean/derived statuses
 * only: NEVER prints a credential value, NEVER marks an external item done
 * without real evidence, NEVER performs HTTP. External items stay
 * blocked_external until the SATUSEHAT-2 External Credential Closure Campaign.
 */
class SatusehatOnboardingChecklistService
{
    /**
     * @return array{items: list<array<string, string>>, summary: array<string, int>}
     */
    public function report(): array
    {
        $items = [];
        foreach ((array) config('satusehat_data_quality.onboarding_checklist', []) as $item) {
            $items[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'kind' => $item['kind'],
                'status' => $this->statusFor($item['key'], $item['kind']),
            ];
        }

        $summary = [];
        foreach ($items as $item) {
            $summary[$item['status']] = ($summary[$item['status']] ?? 0) + 1;
        }

        return ['items' => $items, 'summary' => $summary + ['total' => count($items)]];
    }

    private function statusFor(string $key, string $kind): string
    {
        return match ($key) {
            // Credential presence is reported as a boolean only — the value is
            // never read into any output.
            'sandbox_client_id' => filled(config('satusehat.client_id')) ? 'in_progress' : 'blocked_external',
            'sandbox_client_secret' => filled(config('satusehat.client_secret')) ? 'in_progress' : 'blocked_external',
            'sandbox_organization_id' => filled(config('satusehat.organization_id')) ? 'in_progress' : 'blocked_external',
            'sandbox_location_id' => filled(config('satusehat.location_id')) ? 'in_progress' : 'blocked_external',
            'satusehat2_live_sandbox_go' => (bool) config('satusehat.sandbox_verified') ? 'verified_external' : 'blocked_external',
            'production_credentials',
            'production_organization',
            'production_locations',
            'production_practitioner_mapping',
            'synthetic_patient_ihs',
            'synthetic_practitioner_ihs' => 'blocked_external',
            'management_approval' => (bool) config('satusehat.production_approved') ? 'approved' : 'not_started',
            'clinical_terminology_approval' => $this->hasVerifiedActiveMapping() ? 'ready_internal' : 'in_progress',
            'synthetic_rehearsal' => $this->hasRehearsalEvidence() ? 'ready_internal' : 'in_progress',
            'incident_runbook' => is_file(base_path('docs/runbooks/satusehat-4a-operational-readiness-runbook.md'))
                ? 'ready_internal' : 'in_progress',
            // Shipped by SATUSEHAT-4A itself.
            'patient_identity_workflow',
            'structured_diagnosis_foundation',
            'data_quality_workspace',
            'monitoring_observability',
            'rollback_plan' => 'ready_internal',
            'operator_training' => 'in_progress',
            default => $kind === 'external' ? 'blocked_external' : 'in_progress',
        };
    }

    private function hasVerifiedActiveMapping(): bool
    {
        return SatusehatCodeMapping::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function hasRehearsalEvidence(): bool
    {
        return SatusehatAuditLog::query()
            ->where('event', SatusehatAuditLog::EVENT_REHEARSAL_RUN)
            ->exists();
    }
}
