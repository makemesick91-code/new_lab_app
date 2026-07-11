<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Audit depth planner.
 *
 * Turns a manifest's sprint type into the mandatory audit level and the
 * concrete list of things to inspect, scoped by the actual changed files.
 * Read-only. Prevents "audit the whole repo on every hotfix".
 */
final class SprintAuditPlanner
{
    /**
     * @param  list<string>  $changedFiles
     * @return array{
     *   type:?string,
     *   audit_level:int,
     *   level_name:string,
     *   inspect:list<string>,
     *   changed_files:list<string>,
     *   changed_routes_hint:list<string>,
     *   changed_policies:list<string>,
     *   migrations:list<string>,
     *   integration_risks:list<string>,
     *   suggested_commands:list<string>
     * }
     */
    public function plan(SprintManifest $manifest, array $changedFiles): array
    {
        $profile = $manifest->profile();
        $level = (int) ($profile['audit_level'] ?? 3); // fail closed = deepest
        $levelDef = (array) config("sprint_profiles.audit_levels.{$level}", []);

        $policies = array_values(array_filter($changedFiles, static fn ($f) => str_starts_with($f, 'app/Policies/') || str_contains($f, 'Policy.php')));
        $migrations = array_values(array_filter($changedFiles, static fn ($f) => str_starts_with($f, 'database/migrations/')));
        $routeHints = array_values(array_filter($changedFiles, static fn ($f) => str_contains($f, 'routes/') || str_contains($f, 'Controller.php')));

        $integrationRisks = [];
        if ($manifest->flag('branch_isolation_impact')) {
            $integrationRisks[] = 'Branch isolation impact — re-verify BranchContext scoping on every touched query.';
        }
        if ($manifest->flag('ledger_impact')) {
            $integrationRisks[] = 'Ledger impact — verify stock is derived from movements only (no mutable stock).';
        }
        if ($manifest->flag('security_impact')) {
            $integrationRisks[] = 'Security impact — verify EFFECTIVE permission checks + 3-layer enforcement (route/policy/controller).';
        }
        if ($migrations !== []) {
            $integrationRisks[] = 'Schema change — confirm additive-only migration; never migrate:fresh/db:wipe on VPS.';
        }

        $commands = [
            'php artisan sprint:manifest-check',
            'php artisan sprint:test-plan',
        ];
        if ($level >= 3) {
            $commands[] = 'php artisan foundation:shared-service-audit --strict';
            $commands[] = 'php artisan foundation:devflow-check --strict';
        }

        return [
            'type' => $manifest->type(),
            'audit_level' => $level,
            'level_name' => (string) ($levelDef['name'] ?? 'Foundation'),
            'inspect' => array_values((array) ($levelDef['inspect'] ?? [])),
            'changed_files' => array_values($changedFiles),
            'changed_routes_hint' => $routeHints,
            'changed_policies' => $policies,
            'migrations' => $migrations,
            'integration_risks' => $integrationRisks,
            'suggested_commands' => $commands,
        ];
    }
}
