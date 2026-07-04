<?php

namespace App\Services\Architecture;

use App\Services\DataQuality\Dq1AuditService;
use App\Services\Inventory\AmbiguousBatchReviewPackService;
use App\Services\Inventory\BatchGovernanceAuditService;
use App\Services\Inventory\SourceDocumentBatchAuditService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;

/**
 * Read-only combined foundation governance summary (NSF + DMO).
 */
class FoundationGovernanceSummaryService
{
    public function __construct(
        private readonly NsfApplicationRulesService $nsfRules,
        private readonly DmoApplicationRulesService $dmoRules,
        private readonly DmoFoundationService $dmoFoundation,
        private readonly OwnerKpiRegistryService $ownerKpiRegistry,
        private readonly Dq1AuditService $dq1Audit,
        private readonly BatchGovernanceAuditService $dq2Audit,
        private readonly SourceDocumentBatchAuditService $dq3Audit,
        private readonly AmbiguousBatchReviewPackService $dq31Review,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $nsf = $this->nsfRules->collect([
            'include_dmo' => true,
            'include_deploy_gates' => true,
            'include_observability' => true,
            'include_privacy' => true,
        ]);

        $dmo = $this->dmoRules->collect(['include_warnings' => true]);
        $foundation = $this->dmoFoundation->collect([
            'include_lineage' => true,
            'include_backlog' => true,
            'include_references' => true,
        ]);

        $ownerKpi = $this->ownerKpiRegistry->collect();
        $dq1 = $this->dq1Audit->audit();
        $dq2 = $this->dq2Audit->audit();
        $dq3 = $this->dq3Audit->audit();
        $dq31 = $this->dq31Review->generate();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => 'NSF-6',
            ],
            'summary' => [
                'nsf_decision' => $nsf['summary']['decision'] ?? 'UNKNOWN',
                'dmo_decision' => $dmo['summary']['decision'] ?? 'UNKNOWN',
                'combined_decision' => $this->combinedDecision($nsf, $dmo, $dq1, $dq2, $dq3, $dq31),
                'nsf_rules' => $nsf['summary']['rules'] ?? 0,
                'nsf_passed' => $nsf['summary']['passed'] ?? 0,
                'nsf_warnings' => $nsf['summary']['warnings'] ?? 0,
                'nsf_errors' => $nsf['summary']['errors'] ?? 0,
                'dmo_rules' => $dmo['summary']['rules'] ?? 0,
                'dmo_passed' => $dmo['summary']['passed'] ?? 0,
                'dmo_warnings' => $dmo['summary']['warnings'] ?? 0,
                'dmo_errors' => $dmo['summary']['errors'] ?? 0,
                'owner_kpi_canonical_count' => count($ownerKpi['canonical'] ?? []),
                'owner_kpi_alias_count' => count($ownerKpi['aliases'] ?? []),
                'dmo_entities' => $foundation['summary']['entities'] ?? 0,
                'dmo_metrics' => $foundation['summary']['metrics'] ?? 0,
                'dq1_decision' => $dq1['summary']['decision'] ?? 'UNKNOWN',
                'dq1_checks' => $dq1['summary']['checks'] ?? 0,
                'dq1_passed' => $dq1['summary']['passed'] ?? 0,
                'dq1_warnings' => $dq1['summary']['warnings'] ?? 0,
                'dq1_errors' => $dq1['summary']['errors'] ?? 0,
                'dq2_decision' => $dq2['summary']['decision'] ?? 'UNKNOWN',
                'dq2_checks' => $dq2['summary']['checks'] ?? 0,
                'dq2_passed' => $dq2['summary']['passed'] ?? 0,
                'dq2_warnings' => $dq2['summary']['warnings'] ?? 0,
                'dq2_errors' => $dq2['summary']['errors'] ?? 0,
                'dq2_missing_batch' => $dq2['summary']['missing_inventory_batch_id'] ?? 0,
                'dq3_decision' => $dq3['summary']['decision'] ?? 'UNKNOWN',
                'dq3_checks' => $dq3['summary']['checks'] ?? 0,
                'dq3_passed' => $dq3['summary']['passed'] ?? 0,
                'dq3_warnings' => $dq3['summary']['warnings'] ?? 0,
                'dq3_errors' => $dq3['summary']['errors'] ?? 0,
                'dq3_total_missing' => $dq3['summary']['total_missing'] ?? 0,
                'dq31_decision' => $dq31['summary']['decision'] ?? 'UNKNOWN',
                'dq31_ambiguous_count' => $dq31['summary']['total_ambiguous_count'] ?? 0,
            ],
            'nsf_governance' => [
                'summary' => $nsf['summary'],
                'dmo_alignment' => $nsf['dmo_alignment'],
                'observability' => $nsf['observability'],
                'deploy_gates' => $nsf['deploy_gates'],
            ],
            'dmo_governance' => [
                'summary' => $dmo['summary'],
            ],
            'owner_kpi_registry' => [
                'summary' => $ownerKpi['summary'] ?? [],
                'canonical_count' => count($ownerKpi['canonical'] ?? []),
                'alias_count' => count($ownerKpi['aliases'] ?? []),
                'blocked_count' => count($ownerKpi['blocked'] ?? []),
            ],
            'dmo_foundation' => [
                'summary' => $foundation['summary'] ?? [],
            ],
            'dq1_governance' => [
                'summary' => $dq1['summary'] ?? [],
                'command' => 'data-quality:dq1-audit',
            ],
            'dq2_governance' => [
                'summary' => $dq2['summary'] ?? [],
                'command' => 'inventory:batch-governance-audit',
            ],
            'dq3_governance' => [
                'summary' => $dq3['summary'] ?? [],
                'command' => 'inventory:source-document-batch-audit',
            ],
            'dq31_governance' => [
                'summary' => $dq31['summary'] ?? [],
                'review_command' => 'inventory:ambiguous-batch-review-pack',
                'repair_command' => 'inventory:repair-ambiguous-batch-links',
            ],
            'commands_available' => [
                'architecture:nsf-governance-check' => array_key_exists('architecture:nsf-governance-check', Artisan::all()),
                'architecture:dmo-governance-check' => array_key_exists('architecture:dmo-governance-check', Artisan::all()),
                'architecture:owner-kpi-registry' => array_key_exists('architecture:owner-kpi-registry', Artisan::all()),
                'architecture:dmo-foundation' => array_key_exists('architecture:dmo-foundation', Artisan::all()),
                'data-quality:dq1-audit' => array_key_exists('data-quality:dq1-audit', Artisan::all()),
                'inventory:batch-governance-audit' => array_key_exists('inventory:batch-governance-audit', Artisan::all()),
                'inventory:backfill-missing-batches' => array_key_exists('inventory:backfill-missing-batches', Artisan::all()),
                'inventory:source-document-batch-audit' => array_key_exists('inventory:source-document-batch-audit', Artisan::all()),
                'inventory:backfill-source-document-batches' => array_key_exists('inventory:backfill-source-document-batches', Artisan::all()),
                'inventory:ambiguous-batch-review-pack' => array_key_exists('inventory:ambiguous-batch-review-pack', Artisan::all()),
                'inventory:repair-ambiguous-batch-links' => array_key_exists('inventory:repair-ambiguous-batch-links', Artisan::all()),
            ],
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $nsf
     * @param  array<string, mixed>  $dmo
     * @param  array<string, mixed>  $dq1
     * @param  array<string, mixed>  $dq2
     * @param  array<string, mixed>  $dq3
     */
    private function combinedDecision(array $nsf, array $dmo, array $dq1, array $dq2, array $dq3, array $dq31): string
    {
        $nsfErrors = (int) ($nsf['summary']['errors'] ?? 0);
        $dmoErrors = (int) ($dmo['summary']['errors'] ?? 0);
        $dq1Errors = (int) ($dq1['summary']['errors'] ?? 0);
        $dq2Errors = (int) ($dq2['summary']['errors'] ?? 0);
        $dq3Errors = (int) ($dq3['summary']['errors'] ?? 0);

        if ($nsfErrors > 0 || $dmoErrors > 0 || $dq1Errors > 0 || $dq2Errors > 0 || $dq3Errors > 0) {
            return 'NO-GO';
        }

        $nsfWarnings = (int) ($nsf['summary']['warnings'] ?? 0);
        $dmoWarnings = (int) ($dmo['summary']['warnings'] ?? 0);
        $dq1Warnings = (int) ($dq1['summary']['warnings'] ?? 0);
        $dq2Warnings = (int) ($dq2['summary']['warnings'] ?? 0);
        $dq3Warnings = (int) ($dq3['summary']['warnings'] ?? 0);
        $dq31Ambiguous = (int) ($dq31['summary']['total_ambiguous_count'] ?? 0);

        if ($nsfWarnings > 0 || $dmoWarnings > 0 || $dq1Warnings > 0 || $dq2Warnings > 0 || $dq3Warnings > 0 || $dq31Ambiguous > 0) {
            return 'WATCH';
        }

        return 'GO';
    }
}
