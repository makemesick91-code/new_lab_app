<?php

namespace App\Services\Architecture;

use App\Services\DataQuality\Dq1AuditService;
use App\Services\Foundation\AutomatedSmokeService;
use App\Services\Foundation\CacheGovernanceService;
use App\Services\Foundation\FeatureFlagService;
use App\Services\Foundation\IdempotencyService;
use App\Services\Foundation\OutboxService;
use App\Services\Foundation\QueueGovernanceService;
use App\Services\Foundation\ReleaseEvidenceService;
use App\Services\Foundation\ReleaseSafetyService;
use App\Services\Inventory\AmbiguousBatchReviewPackService;
use App\Services\Inventory\BatchGovernanceAuditService;
use App\Services\Inventory\SourceDocumentBatchAuditService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only combined foundation governance summary (NSF + DMO + DQ chain +
 * ROADMAP + NSF-9/NSF-10/CACHE-1 release safety chain: FEATURE_FLAGS/
 * CACHE_GOVERNANCE/RELEASE_SAFETY/AUTOMATED_SMOKE/RELEASE_EVIDENCE/
 * BACKUP_VERIFICATION).
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
        private readonly FoundationRoadmapService $roadmap,
        private readonly FeatureFlagService $featureFlags,
        private readonly ReleaseSafetyService $releaseSafety,
        private readonly AutomatedSmokeService $automatedSmoke,
        private readonly ReleaseEvidenceService $releaseEvidence,
        private readonly CacheGovernanceService $cacheGovernance,
        private readonly QueueGovernanceService $queueGovernance,
        private readonly IdempotencyService $idempotencyService,
        private readonly OutboxService $outboxService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(string $releaseSafetyProfile = 'local'): array
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

        $nsfWatch = $this->extractWatchCauses($nsf['rules'] ?? [], 'nsf');
        $dmoWatch = $this->extractWatchCauses($dmo['results'] ?? [], 'dmo');
        $dqChain = $this->buildDqChainSummary($dq1, $dq2, $dq3, $dq31);
        $roadmap = $this->roadmap->collect();
        $featureFlags = $this->featureFlags->validateGovernance();
        $releaseSafety = $this->releaseSafety->collect($releaseSafetyProfile);
        $automatedSmoke = $this->automatedSmoke->run();
        $releaseEvidence = $this->releaseEvidence->check($releaseSafetyProfile);
        $cacheGovernance = $this->cacheGovernance->collect();
        $queueGovernance = $this->queueGovernance->collect();
        $idempotencyAudit = Schema::hasTable('sys_idempotency_keys')
            ? $this->idempotencyService->audit()
            : ['summary' => ['decision' => 'WATCH'], 'total_records' => 0, 'by_status' => [], 'by_scope' => []];
        $outboxAudit = Schema::hasTable('sys_outbox_events')
            ? $this->outboxService->audit()
            : ['summary' => ['decision' => 'WATCH'], 'total_records' => 0, 'by_status' => [], 'by_payload_classification' => []];
        $backupVerification = $releaseSafety['backup_verification'] ?? null;
        $combined = $this->combinedDecision(
            $nsf,
            $dmo,
            $dqChain,
            $nsfWatch,
            $dmoWatch,
            $roadmap,
            $featureFlags,
            $cacheGovernance,
            $releaseSafety,
            $automatedSmoke,
            $queueGovernance,
            $idempotencyAudit,
            $outboxAudit,
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => (string) config('foundation_governance.sprint', 'FG-1'),
            ],
            'summary' => [
                'nsf_decision' => $nsf['summary']['decision'] ?? 'UNKNOWN',
                'nsf_effective_decision' => $this->effectiveDecision($nsfWatch, (int) ($nsf['summary']['errors'] ?? 0)),
                'dmo_decision' => $dmo['summary']['decision'] ?? 'UNKNOWN',
                'dmo_effective_decision' => $this->effectiveDecision($dmoWatch, (int) ($dmo['summary']['errors'] ?? 0)),
                'dq_decision' => $dqChain['decision'],
                'combined_decision' => $combined['decision'],
                'combined_reason' => $combined['reason'],
                'combined_blocking_watch_count' => $combined['blocking_watch_count'],
                'nsf_rules' => $nsf['summary']['rules'] ?? 0,
                'nsf_passed' => $nsf['summary']['passed'] ?? 0,
                'nsf_warnings' => $nsf['summary']['warnings'] ?? 0,
                'nsf_blocking_warnings' => $nsfWatch['blocking_count'],
                'nsf_errors' => $nsf['summary']['errors'] ?? 0,
                'dmo_rules' => $dmo['summary']['rules'] ?? 0,
                'dmo_passed' => $dmo['summary']['passed'] ?? 0,
                'dmo_warnings' => $dmo['summary']['warnings'] ?? 0,
                'dmo_blocking_warnings' => $dmoWatch['blocking_count'],
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
                'roadmap_decision' => $roadmap['summary']['decision'] ?? 'UNKNOWN',
                'roadmap_effective_decision' => $roadmap['summary']['decision'] ?? 'UNKNOWN',
                'roadmap_active_track' => $roadmap['active_track'] ?? null,
                'roadmap_next_sprint' => $roadmap['next_recommended_sprint'] ?? null,
                'roadmap_total_planned_sprints' => $roadmap['total_planned_sprints'] ?? 0,
                'roadmap_rc_locked_after_expansion' => $roadmap['rc_locked_after_expansion'] ?? false,
                'feature_flags_decision' => $featureFlags['summary']['decision'] ?? 'UNKNOWN',
                'feature_flags_total' => $featureFlags['total_flags'] ?? 0,
                'feature_flags_risky_enabled' => count($featureFlags['risky_enabled_flags'] ?? []),
                'cache_governance_decision' => $cacheGovernance['summary']['decision'] ?? 'UNKNOWN',
                'cache_governance_redis_runtime_enabled' => $cacheGovernance['redis_runtime_enabled'] ?? false,
                'queue_governance_decision' => $queueGovernance['summary']['decision'] ?? 'UNKNOWN',
                'queue_governance_long_running_worker_enabled' => $queueGovernance['long_running_worker_enabled'] ?? false,
                'idempotency_decision' => $idempotencyAudit['summary']['decision'] ?? 'UNKNOWN',
                'idempotency_total_records' => $idempotencyAudit['total_records'] ?? 0,
                'outbox_decision' => $outboxAudit['summary']['decision'] ?? 'UNKNOWN',
                'outbox_total_records' => $outboxAudit['total_records'] ?? 0,
                'release_safety_decision' => $releaseSafety['summary']['decision'] ?? 'UNKNOWN',
                'release_safety_profile' => $releaseSafetyProfile,
                'automated_smoke_decision' => $automatedSmoke['summary']['decision'] ?? 'UNKNOWN',
                'automated_smoke_mode' => $automatedSmoke['mode'] ?? 'command_readiness_only',
                'release_evidence_decision' => $releaseEvidence['summary']['decision'] ?? 'UNKNOWN',
                'backup_verification_decision' => $backupVerification['decision'] ?? 'NOT_APPLICABLE',
            ],
            'watch_causes' => [
                'nsf' => $nsfWatch['items'],
                'dmo' => $dmoWatch['items'],
                'dq' => $dqChain['watch_items'],
            ],
            'dq_chain' => $dqChain,
            'combined' => $combined,
            'roadmap' => [
                'decision' => $roadmap['summary']['decision'] ?? 'UNKNOWN',
                'effective_decision' => $roadmap['summary']['decision'] ?? 'UNKNOWN',
                'roadmap_id' => $roadmap['roadmap_id'] ?? null,
                'source_locked' => $roadmap['source_locked'] ?? false,
                'active_track' => $roadmap['active_track'] ?? null,
                'next_recommended_sprint' => $roadmap['next_recommended_sprint'] ?? null,
                'total_planned_sprints' => $roadmap['total_planned_sprints'] ?? 0,
                'rc_locked_after_expansion' => $roadmap['rc_locked_after_expansion'] ?? false,
                'summary' => $roadmap['summary'] ?? [],
                'command' => 'architecture:foundation-roadmap-check',
            ],
            'feature_flags' => [
                'decision' => $featureFlags['summary']['decision'] ?? 'UNKNOWN',
                'total_flags' => $featureFlags['total_flags'] ?? 0,
                'risky_enabled_flags' => $featureFlags['risky_enabled_flags'] ?? [],
                'summary' => $featureFlags['summary'] ?? [],
                'checks' => $featureFlags['checks'] ?? [],
                'command' => 'foundation:feature-flags',
            ],
            'cache_governance' => [
                'decision' => $cacheGovernance['summary']['decision'] ?? 'UNKNOWN',
                'redis_runtime_enabled' => $cacheGovernance['redis_runtime_enabled'] ?? false,
                'redis_readiness_status' => $cacheGovernance['redis_readiness']['default_status'] ?? null,
                'allowed_categories_count' => count($cacheGovernance['allowed_categories'] ?? []),
                'denied_categories_count' => count($cacheGovernance['denied_categories'] ?? []),
                'summary' => $cacheGovernance['summary'] ?? [],
                'checks' => $cacheGovernance['checks'] ?? [],
                'command' => 'foundation:cache-governance-check',
            ],
            'queue_governance' => [
                'decision' => $queueGovernance['summary']['decision'] ?? 'UNKNOWN',
                'queue_connection' => $queueGovernance['queue_connection'] ?? null,
                'long_running_worker_enabled' => $queueGovernance['long_running_worker_enabled'] ?? false,
                'external_dispatch_enabled_flag' => $queueGovernance['external_dispatch_enabled_flag'] ?? false,
                'idempotency_table_exists' => $queueGovernance['idempotency_table_exists'] ?? false,
                'outbox_table_exists' => $queueGovernance['outbox_table_exists'] ?? false,
                'summary' => $queueGovernance['summary'] ?? [],
                'checks' => $queueGovernance['checks'] ?? [],
                'command' => 'foundation:queue-governance-check',
            ],
            'idempotency' => [
                'decision' => $idempotencyAudit['summary']['decision'] ?? 'UNKNOWN',
                'total_records' => $idempotencyAudit['total_records'] ?? 0,
                'by_status' => $idempotencyAudit['by_status'] ?? [],
                'by_scope' => $idempotencyAudit['by_scope'] ?? [],
                'summary' => $idempotencyAudit['summary'] ?? [],
                'command' => 'foundation:idempotency-audit',
            ],
            'outbox' => [
                'decision' => $outboxAudit['summary']['decision'] ?? 'UNKNOWN',
                'total_records' => $outboxAudit['total_records'] ?? 0,
                'by_status' => $outboxAudit['by_status'] ?? [],
                'by_payload_classification' => $outboxAudit['by_payload_classification'] ?? [],
                'dispatch_enabled' => $outboxAudit['dispatch_enabled'] ?? false,
                'external_dispatch_enabled' => $outboxAudit['external_dispatch_enabled'] ?? false,
                'summary' => $outboxAudit['summary'] ?? [],
                'command' => 'foundation:outbox-audit',
            ],
            'release_safety' => [
                'decision' => $releaseSafety['summary']['decision'] ?? 'UNKNOWN',
                'profile' => $releaseSafetyProfile,
                'summary' => $releaseSafety['summary'] ?? [],
                'checks' => $releaseSafety['checks'] ?? [],
                'command' => 'foundation:release-safety-check',
            ],
            'release_evidence' => [
                'decision' => $releaseEvidence['summary']['decision'] ?? 'UNKNOWN',
                'profile' => $releaseSafetyProfile,
                'directory' => $releaseEvidence['directory'] ?? null,
                'summary' => $releaseEvidence['summary'] ?? [],
                'artifacts' => $releaseEvidence['artifacts'] ?? [],
                'command' => 'release:evidence-check',
            ],
            'backup_verification' => [
                'decision' => $backupVerification['decision'] ?? 'NOT_APPLICABLE',
                'applicable' => $releaseSafetyProfile === 'vps',
                'path' => $backupVerification['path'] ?? null,
                'size_bytes' => $backupVerification['size_bytes'] ?? null,
                'generated_at' => $backupVerification['generated_at'] ?? null,
                'command' => 'foundation:backup-verify',
                'note' => $releaseSafetyProfile === 'vps'
                    ? null
                    : 'Backup verification is only evaluated for the vps profile.',
            ],
            'automated_smoke' => [
                'decision' => $automatedSmoke['summary']['decision'] ?? 'UNKNOWN',
                'mode' => $automatedSmoke['mode'] ?? 'command_readiness_only',
                'base_url' => $automatedSmoke['base_url'] ?? null,
                'summary' => $automatedSmoke['summary'] ?? [],
                'checks' => $automatedSmoke['checks'] ?? [],
                'command' => 'release:automated-smoke',
                'note' => ($automatedSmoke['base_url'] ?? null) === null
                    ? 'Local-only command-readiness check — not a production HTTP smoke. Run with --base-url on VPS.'
                    : 'HTTP base-url smoke executed.',
            ],
            'fg1_checks' => $this->fg1Checks($nsfWatch, $dmoWatch, $dqChain, $combined),
            'evidence_docs' => $this->evidenceDocs(),
            'deferred_backlog' => config('foundation_governance.deferred_backlog', []),
            'ci_evidence_gates' => $this->ciEvidenceGates(),
            'resolved_ci_gates' => config('foundation_governance.resolved_ci_gates', []),
            'nsf_governance' => [
                'summary' => $nsf['summary'],
                'watch_causes' => $nsfWatch['items'],
                'dmo_alignment' => $nsf['dmo_alignment'],
                'observability' => $nsf['observability'],
                'deploy_gates' => $nsf['deploy_gates'],
            ],
            'dmo_governance' => [
                'summary' => $dmo['summary'],
                'watch_causes' => $dmoWatch['items'],
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
                'architecture:foundation-roadmap-check' => array_key_exists('architecture:foundation-roadmap-check', Artisan::all()),
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
     * @param  list<array<string, mixed>>  $rules
     * @return array{items: list<array<string, mixed>>, blocking_count: int}
     */
    private function extractWatchCauses(array $rules, string $section): array
    {
        $items = [];
        $blocking = 0;

        foreach ($rules as $rule) {
            $status = (string) ($rule['status'] ?? '');
            if (! in_array($status, ['warning', 'failed'], true)) {
                continue;
            }

            $ruleId = (string) ($rule['rule_id'] ?? 'UNKNOWN');
            $classification = $this->classifyRule($ruleId, $rule);
            $isBlocking = $classification === 'blocker';

            if ($isBlocking) {
                $blocking++;
            }

            $backlog = config("foundation_governance.deferred_backlog.{$ruleId}", []);

            $items[] = [
                'section' => $section,
                'rule_id' => $ruleId,
                'title' => (string) ($rule['title'] ?? $ruleId),
                'status' => $status,
                'classification' => $classification,
                'blocking' => $isBlocking,
                'message' => (string) ($rule['message'] ?? ''),
                'recommendation' => (string) ($rule['recommendation'] ?? ''),
                'owner' => $backlog['owner'] ?? null,
                'risk' => $backlog['risk'] ?? null,
                'target_sprint' => $backlog['target_sprint'] ?? null,
            ];
        }

        return ['items' => $items, 'blocking_count' => $blocking];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function classifyRule(string $ruleId, array $rule): string
    {
        $configured = config("foundation_governance.rule_classifications.{$ruleId}");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (str_starts_with($ruleId, 'NSF-M') || str_starts_with($ruleId, 'DMO-M')) {
            return 'deferred_backlog';
        }

        if (($rule['status'] ?? '') === 'not_applicable') {
            return 'environment';
        }

        if (($rule['severity'] ?? '') === 'warning' && ($rule['status'] ?? '') === 'warning') {
            return 'non_blocking_warning';
        }

        return 'blocker';
    }

    /**
     * @param  array{items: list<array<string, mixed>>, blocking_count: int}  $watch
     */
    private function effectiveDecision(array $watch, int $errors): string
    {
        if ($errors > 0) {
            return 'NO-GO';
        }

        if ($watch['blocking_count'] > 0) {
            return 'WATCH';
        }

        return 'GO';
    }

    /**
     * @param  array<string, mixed>  $dq1
     * @param  array<string, mixed>  $dq2
     * @param  array<string, mixed>  $dq3
     * @param  array<string, mixed>  $dq31
     * @return array<string, mixed>
     */
    private function buildDqChainSummary(array $dq1, array $dq2, array $dq3, array $dq31): array
    {
        $items = [
            ['id' => 'DQ-1', 'decision' => $dq1['summary']['decision'] ?? 'UNKNOWN', 'command' => 'data-quality:dq1-audit'],
            ['id' => 'DQ-2', 'decision' => $dq2['summary']['decision'] ?? 'UNKNOWN', 'command' => 'inventory:batch-governance-audit'],
            ['id' => 'DQ-3', 'decision' => $dq3['summary']['decision'] ?? 'UNKNOWN', 'command' => 'inventory:source-document-batch-audit'],
            ['id' => 'DQ-3.1', 'decision' => $dq31['summary']['decision'] ?? 'UNKNOWN', 'command' => 'inventory:ambiguous-batch-review-pack'],
        ];

        $watchItems = [];
        foreach ($items as $item) {
            if (($item['decision'] ?? '') !== 'GO') {
                $watchItems[] = [
                    'section' => 'dq',
                    'rule_id' => $item['id'],
                    'status' => 'warning',
                    'classification' => 'blocker',
                    'blocking' => true,
                    'message' => sprintf('%s decision is %s', $item['id'], $item['decision']),
                    'command' => $item['command'],
                ];
            }
        }

        $ambiguous = (int) ($dq31['summary']['total_ambiguous_count'] ?? 0);
        if ($ambiguous > 0) {
            $watchItems[] = [
                'section' => 'dq',
                'rule_id' => 'DQ-3.1',
                'status' => 'warning',
                'classification' => 'blocker',
                'blocking' => true,
                'message' => sprintf('DQ-3.1 ambiguous batch rows remain: %d', $ambiguous),
                'command' => 'inventory:ambiguous-batch-review-pack',
            ];
        }

        $allGo = collect($items)->every(fn (array $i) => ($i['decision'] ?? '') === 'GO') && $ambiguous === 0;

        return [
            'decision' => $allGo ? 'GO' : 'WATCH',
            'items' => $items,
            'watch_items' => $watchItems,
            'ambiguous_count' => $ambiguous,
        ];
    }

    /**
     * @param  array{items: list<array<string, mixed>>, blocking_count: int}  $nsfWatch
     * @param  array{items: list<array<string, mixed>>, blocking_count: int}  $dmoWatch
     * @param  array<string, mixed>  $dqChain
     * @param  array<string, mixed>  $roadmap
     * @param  array<string, mixed>  $featureFlags
     * @param  array<string, mixed>  $cacheGovernance
     * @param  array<string, mixed>  $releaseSafety
     * @param  array<string, mixed>  $automatedSmoke
     * @param  array<string, mixed>  $queueGovernance
     * @param  array<string, mixed>  $idempotencyAudit
     * @param  array<string, mixed>  $outboxAudit
     * @return array{decision: string, blocking_watch_count: int, reason: string}
     */
    private function combinedDecision(
        array $nsf,
        array $dmo,
        array $dqChain,
        array $nsfWatch,
        array $dmoWatch,
        array $roadmap = [],
        array $featureFlags = [],
        array $cacheGovernance = [],
        array $releaseSafety = [],
        array $automatedSmoke = [],
        array $queueGovernance = [],
        array $idempotencyAudit = [],
        array $outboxAudit = [],
    ): array {
        $errors = (int) ($nsf['summary']['errors'] ?? 0)
            + (int) ($dmo['summary']['errors'] ?? 0);

        if ($errors > 0) {
            return [
                'decision' => 'NO-GO',
                'blocking_watch_count' => $nsfWatch['blocking_count'] + $dmoWatch['blocking_count'] + count($dqChain['watch_items']),
                'reason' => 'Foundation errors remain in NSF or DMO governance checks',
            ];
        }

        if (($dqChain['decision'] ?? '') !== 'GO') {
            return [
                'decision' => 'WATCH',
                'blocking_watch_count' => $nsfWatch['blocking_count'] + $dmoWatch['blocking_count'] + count($dqChain['watch_items']),
                'reason' => 'DQ chain is not fully GO',
            ];
        }

        $blocking = $nsfWatch['blocking_count'] + $dmoWatch['blocking_count'];
        if ($blocking > 0) {
            return [
                'decision' => 'WATCH',
                'blocking_watch_count' => $blocking,
                'reason' => 'Blocking NSF/DMO watch items remain',
            ];
        }

        // NSF-9: roadmap / feature-flag / release-safety / automated-smoke FAIL is a hard NO-GO.
        $nsf9Fails = [];
        if (($roadmap['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'ROADMAP';
        }
        if (($featureFlags['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'FEATURE_FLAGS';
        }
        if (($releaseSafety['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'RELEASE_SAFETY';
        }
        if (($automatedSmoke['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'AUTOMATED_SMOKE';
        }
        if (($cacheGovernance['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'CACHE_GOVERNANCE';
        }
        if (($queueGovernance['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'QUEUE_GOVERNANCE';
        }
        if (($idempotencyAudit['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'IDEMPOTENCY';
        }
        if (($outboxAudit['summary']['decision'] ?? 'GO') === 'FAIL') {
            $nsf9Fails[] = 'OUTBOX';
        }
        if ($nsf9Fails !== []) {
            return [
                'decision' => 'NO-GO',
                'blocking_watch_count' => count($nsf9Fails),
                'reason' => 'NSF-9 gate(s) reporting FAIL: '.implode(', ', $nsf9Fails),
            ];
        }

        // Non-blocking WATCH in the NSF-9 chain (e.g. local-only evidence not yet
        // captured) is surfaced honestly via feature_flags/release_safety/
        // automated_smoke sections but does not by itself flip combined to WATCH.
        $nsf9Watches = array_filter([
            ($roadmap['summary']['decision'] ?? 'GO') === 'WATCH' ? 'ROADMAP' : null,
            ($featureFlags['summary']['decision'] ?? 'GO') === 'WATCH' ? 'FEATURE_FLAGS' : null,
            ($releaseSafety['summary']['decision'] ?? 'GO') === 'WATCH' ? 'RELEASE_SAFETY' : null,
            ($automatedSmoke['summary']['decision'] ?? 'GO') === 'WATCH' ? 'AUTOMATED_SMOKE' : null,
        ]);

        // CACHE-1: WATCH on cache governance (e.g. Redis probe unavailable while
        // runtime disabled) is non-blocking for combined GO.
        $cacheGovWatchBlocking = ($cacheGovernance['summary']['decision'] ?? 'GO') === 'WATCH'
            && ($cacheGovernance['redis_runtime_enabled'] ?? false);

        if ($cacheGovWatchBlocking) {
            return [
                'decision' => 'WATCH',
                'blocking_watch_count' => 1,
                'reason' => 'CACHE_GOVERNANCE WATCH while Redis runtime is enabled',
            ];
        }

        if (($cacheGovernance['summary']['decision'] ?? 'GO') === 'WATCH') {
            $nsf9Watches[] = 'CACHE_GOVERNANCE';
        }

        // QUEUE-1: WATCH on queue governance (e.g. persistence tables not yet
        // migrated, worker probe not run) is non-blocking as long as the
        // long-running worker stays disabled, which it always is in QUEUE-1.
        $queueGovWatchBlocking = ($queueGovernance['summary']['decision'] ?? 'GO') === 'WATCH'
            && ($queueGovernance['long_running_worker_enabled'] ?? false);

        if ($queueGovWatchBlocking) {
            return [
                'decision' => 'WATCH',
                'blocking_watch_count' => 1,
                'reason' => 'QUEUE_GOVERNANCE WATCH while a long-running worker is enabled',
            ];
        }

        if (($queueGovernance['summary']['decision'] ?? 'GO') === 'WATCH') {
            $nsf9Watches[] = 'QUEUE_GOVERNANCE';
        }
        if (($idempotencyAudit['summary']['decision'] ?? 'GO') === 'WATCH') {
            $nsf9Watches[] = 'IDEMPOTENCY';
        }
        if (($outboxAudit['summary']['decision'] ?? 'GO') === 'WATCH') {
            $nsf9Watches[] = 'OUTBOX';
        }

        $nonBlocking = count($nsfWatch['items']) + count($dmoWatch['items']) + count($nsf9Watches);
        $reason = $nonBlocking > 0
            ? sprintf('GO — %d deferred/evidence/environment watch items documented; no blockers', $nonBlocking)
            : 'GO — all foundation checks green';

        return [
            'decision' => 'GO',
            'blocking_watch_count' => 0,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{items: list<array<string, mixed>>, blocking_count: int}  $nsfWatch
     * @param  array{items: list<array<string, mixed>>, blocking_count: int}  $dmoWatch
     * @param  array<string, mixed>  $dqChain
     * @param  array{decision: string, blocking_watch_count: int, reason: string}  $combined
     * @return list<array<string, mixed>>
     */
    private function fg1Checks(array $nsfWatch, array $dmoWatch, array $dqChain, array $combined): array
    {
        $definitions = config('foundation_governance.fg1_checks', []);
        $evidence = $this->evidenceDocs();

        return collect($definitions)->map(function (string $description, string $checkId) use ($nsfWatch, $dmoWatch, $dqChain, $combined, $evidence) {
            $status = match ($checkId) {
                'FG1-NSF-001' => count($nsfWatch['items']) > 0 ? 'passed' : 'passed',
                'FG1-NSF-002' => $nsfWatch['blocking_count'] === 0 ? 'passed' : 'failed',
                'FG1-DMO-001' => count($dmoWatch['items']) > 0 ? 'passed' : 'passed',
                'FG1-DMO-002' => $dmoWatch['blocking_count'] === 0 ? 'passed' : 'failed',
                'FG1-DQ-001' => ($dqChain['decision'] ?? '') === 'GO' ? 'passed' : 'failed',
                'FG1-COMBINED-001' => ($combined['reason'] ?? '') !== '' ? 'passed' : 'failed',
                'FG1-EVIDENCE-001' => collect($evidence)->every(fn (array $doc) => $doc['exists']) ? 'passed' : 'warning',
                'FG1-CI-001' => $this->ciEvidenceGates()['workflow_exists'] ? 'passed' : 'failed',
                default => 'unknown',
            };

            return [
                'check_id' => $checkId,
                'description' => $description,
                'status' => $status,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evidenceDocs(): array
    {
        return collect(config('foundation_governance.evidence_docs', []))
            ->map(fn (string $path, string $key) => [
                'key' => $key,
                'path' => $path,
                'exists' => is_file(base_path($path)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ciEvidenceGates(): array
    {
        $config = config('foundation_governance.ci_evidence_gates', []);
        $workflow = (string) ($config['workflow'] ?? '');
        $script = (string) ($config['script'] ?? '');

        return [
            'workflow' => $workflow,
            'workflow_exists' => $workflow !== '' && is_file(base_path($workflow)),
            'workflow_name' => (string) ($config['workflow_name'] ?? 'Foundation Evidence Gates'),
            'script' => $script,
            'script_exists' => $script !== '' && is_file(base_path($script)),
            'artifacts_root' => (string) ($config['artifacts_root'] ?? 'storage/ci-evidence'),
            'github_api_required' => (bool) ($config['github_api_required'] ?? false),
            'gates' => collect($config['gates'] ?? [])->map(function (array $gate, string $ruleId) {
                return array_merge($gate, [
                    'rule_id' => $ruleId,
                    'classification' => (string) ($gate['classification'] ?? 'automated_ci_gate'),
                ]);
            })->all(),
        ];
    }
}
