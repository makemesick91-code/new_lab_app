<?php

namespace App\Services\Architecture;

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
                'combined_decision' => $this->combinedDecision($nsf, $dmo),
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
            'commands_available' => [
                'architecture:nsf-governance-check' => array_key_exists('architecture:nsf-governance-check', Artisan::all()),
                'architecture:dmo-governance-check' => array_key_exists('architecture:dmo-governance-check', Artisan::all()),
                'architecture:owner-kpi-registry' => array_key_exists('architecture:owner-kpi-registry', Artisan::all()),
                'architecture:dmo-foundation' => array_key_exists('architecture:dmo-foundation', Artisan::all()),
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
     */
    private function combinedDecision(array $nsf, array $dmo): string
    {
        $nsfErrors = (int) ($nsf['summary']['errors'] ?? 0);
        $dmoErrors = (int) ($dmo['summary']['errors'] ?? 0);

        if ($nsfErrors > 0 || $dmoErrors > 0) {
            return 'NO-GO';
        }

        $nsfWarnings = (int) ($nsf['summary']['warnings'] ?? 0);
        $dmoWarnings = (int) ($dmo['summary']['warnings'] ?? 0);

        if ($nsfWarnings > 0 || $dmoWarnings > 0) {
            return 'WATCH';
        }

        return 'GO';
    }
}
