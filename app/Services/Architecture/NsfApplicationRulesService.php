<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * NSF-6 application-level governance validation.
 * Read-only — validates foundation guardrails against config/nsf.php.
 */
class NsfApplicationRulesService
{
    public function __construct(
        private readonly DmoApplicationRulesService $dmoRules,
    ) {}

    /**
     * @param  array{
     *     strict?: bool,
     *     include_dmo?: bool,
     *     include_deploy_gates?: bool,
     *     include_observability?: bool,
     *     include_privacy?: bool,
     * }  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $includeDmo = (bool) ($options['include_dmo'] ?? false);
        $includeDeployGates = (bool) ($options['include_deploy_gates'] ?? false);
        $includeObservability = (bool) ($options['include_observability'] ?? false);
        $includePrivacy = (bool) ($options['include_privacy'] ?? false);

        $rules = [];
        $rules = array_merge($rules, $this->validateRegistryCompleteness());
        $rules = array_merge($rules, $this->validateR001());
        $rules = array_merge($rules, $this->validateR002());
        $rules = array_merge($rules, $this->validateR003());
        $rules = array_merge($rules, $this->validateR004());
        $rules = array_merge($rules, $this->validateR005());
        $rules = array_merge($rules, $this->validateR006());
        $rules = array_merge($rules, $this->validateR007());
        $rules = array_merge($rules, $this->validateR008());
        $rules = array_merge($rules, $this->validateR009($includeObservability));
        $rules = array_merge($rules, $this->validateR010());
        $rules = array_merge($rules, $this->validateR011());
        $rules = array_merge($rules, $this->validateR012());
        $rules = array_merge($rules, $this->validateR013());
        $rules = array_merge($rules, $this->validateR014());
        $rules = array_merge($rules, $this->validateR015());
        $rules = array_merge($rules, $this->validateR016($includeDmo));
        $rules = array_merge($rules, $this->validateR017());
        $rules = array_merge($rules, $this->validateR018());
        $rules = array_merge($rules, $this->validateR019());
        $rules = array_merge($rules, $this->validateR020());
        $rules = array_merge($rules, $this->validateR021());
        $rules = array_merge($rules, $this->validateDeferredWarnings());

        $dmoAlignment = $this->collectDmoAlignment($includeDmo);
        $observability = $this->collectObservability($includeObservability);
        $deployGates = $includeDeployGates ? $this->collectDeployGates() : [];

        $passed = collect($rules)->where('status', 'passed')->count();
        $warnings = collect($rules)->where('status', 'warning')->count();
        $errors = collect($rules)->where('status', 'failed')->where('severity', 'error')->count();

        $decision = match (true) {
            $errors > 0 => 'NO-GO',
            $warnings > 0 => 'WATCH',
            default => 'GO',
        };

        $report = [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => 'NSF-6',
                'rules_source' => 'config/nsf.php',
                'strict' => (bool) ($options['strict'] ?? false),
            ],
            'summary' => [
                'rules' => count(config('nsf.rules', [])),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'decision' => $decision,
            ],
            'rules' => $rules,
            'dmo_alignment' => $dmoAlignment,
            'observability' => $observability,
            'deploy_gates' => $deployGates,
            'backlog' => $this->backlogEntries(),
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => false,
                'include_privacy_scan' => $includePrivacy,
            ],
        ];

        if ($includePrivacy) {
            $report['privacy']['scan_passed'] = $this->privacyScanPassed($report);
        }

        return $report;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateRegistryCompleteness(): array
    {
        $configured = config('nsf.rules', []);
        $ids = collect($configured)->pluck('rule_id');
        $dupes = $ids->duplicates()->unique()->values();

        if ($dupes->isNotEmpty()) {
            return [$this->ruleResult(
                'NSF-REGISTRY',
                'Duplicate NSF rule IDs in config',
                'failed',
                $dupes->all(),
                'Remove duplicate rule_id entries from config/nsf.php',
                'error',
            )];
        }

        $required = ['rule_id', 'title', 'severity', 'applies_to', 'validation', 'status'];
        $incomplete = [];

        foreach ($configured as $rule) {
            foreach ($required as $field) {
                if (! Arr::has($rule, $field) || $rule[$field] === '' || $rule[$field] === []) {
                    $incomplete[] = ($rule['rule_id'] ?? 'unknown').":{$field}";
                }
            }
        }

        if ($incomplete !== []) {
            return [$this->ruleResult(
                'NSF-REGISTRY',
                'Incomplete NSF rule definitions in config',
                'failed',
                $incomplete,
                'Ensure every rule has rule_id, title, severity, applies_to, validation, status',
                'error',
            )];
        }

        $expected = collect(range(1, 21))->map(fn (int $n) => sprintf('NSF-R%03d', $n));
        $missing = $expected->diff($ids)->values();

        if ($missing->isNotEmpty()) {
            return [$this->ruleResult(
                'NSF-REGISTRY',
                'Missing NSF rules in config registry',
                'failed',
                $missing->all(),
                'Add all NSF-R001 through NSF-R021 to config/nsf.php',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-REGISTRY',
            'NSF rule registry complete (21 rules, no duplicates)',
            'passed',
            ['config/nsf.php'],
            'Keep registry updated when adding foundation rules',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR001(): array
    {
        $class = 'App\\Modules\\Branch\\Services\\BranchContext';

        if (! class_exists($class) || ! method_exists($class, 'requireId')) {
            return [$this->ruleResult(
                'NSF-R001',
                'BranchContext::requireId() not available',
                'failed',
                [$class],
                'Restore BranchContext central branch resolution',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R001',
            'BranchContext branch isolation service is available',
            'passed',
            [$class],
            'Continue scoping branch-owned queries via BranchContext::requireId()',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR002(): array
    {
        $movementModel = 'App\\Modules\\Inventory\\Models\\InventoryMovement';
        $stockService = 'App\\Modules\\Inventory\\Services\\InventoryStockService';

        if (! class_exists($movementModel) || ! class_exists($stockService)) {
            return [$this->ruleResult(
                'NSF-R002',
                'Inventory ledger movement model or stock service missing',
                'failed',
                [$movementModel, $stockService],
                'Preserve ledger-derived stock via InventoryMovement aggregation',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R002',
            'Inventory ledger movement model and stock service are present',
            'passed',
            ['trx_inventory_movements', $stockService],
            'Stock must remain SUM(quantity_in) - SUM(quantity_out)',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR003(): array
    {
        $forbiddenTables = config('nsf.mutable_stock_forbidden_tables', []);
        $forbiddenColumns = config('nsf.mutable_stock_forbidden_columns', []);
        $violations = [];

        $migrationPath = database_path('migrations');
        $files = glob($migrationPath.'/*.php') ?: [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (str_starts_with($basename, 'create_rpt_') || str_contains($basename, 'analytics_summary')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach ($forbiddenTables as $table) {
                if (! str_contains($contents, $table)) {
                    continue;
                }

                foreach ($forbiddenColumns as $column) {
                    if (preg_match('/\$table->[^(]*\(\s*[\'"]'.preg_quote($column, '/').'[\'"]/i', $contents)) {
                        $violations[] = "{$basename}:{$table}.{$column}";
                    }
                }
            }
        }

        if ($violations !== []) {
            return [$this->ruleResult(
                'NSF-R003',
                'Mutable stock columns detected in core inventory migrations',
                'failed',
                $violations,
                'Remove mutable stock columns; use movement ledger aggregation only',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R003',
            'No mutable stock columns found on core inventory tables (analytics snapshots excluded)',
            'passed',
            $forbiddenTables,
            'Do not add current_stock/qty_on_hand to canonical inventory tables',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR004(): array
    {
        $services = config('nsf.movement_workflow_services', []);
        $missing = array_values(array_filter($services, fn (string $class) => ! class_exists($class)));

        if ($missing !== []) {
            return [$this->ruleResult(
                'NSF-R004',
                'Inventory movement workflow services missing',
                'failed',
                $missing,
                'Route procurement/transfer/opname through movement ledger services',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R004',
            'Inventory movement workflow services are present',
            'passed',
            $services,
            'Keep purchase/transfer/opname/adjustment on movement ledger',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR005(): array
    {
        return $this->commandAvailabilityRule(
            'NSF-R005',
            'performance:slow-query-audit',
            'Safe index governance requires slow query audit command',
            'Document index proposals with slow-query-audit evidence',
            'warning',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR006(): array
    {
        $docs = [
            'docs/sprints/hotfix-nsf-2-1-sqlite-test-migration-compatibility.md',
            'docs/sprints/sprint-nsf-2-safe-index-pack-query-plan-hardening.md',
        ];

        $missing = array_values(array_filter($docs, fn (string $path) => ! is_file(base_path($path))));

        if ($missing !== []) {
            return [$this->ruleResult(
                'NSF-R006',
                'Migration safety documentation missing',
                'warning',
                $missing,
                'Document PostgreSQL-safe and SQLite-compatible migration patterns',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R006',
            'Migration safety documentation is present',
            'passed',
            $docs,
            'Keep migrations idempotent and driver-aware',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR007(): array
    {
        $commandFile = app_path('Console/Commands/PerformanceRuntimeQueryObservabilityCommand.php');

        if (! is_file($commandFile)) {
            return [$this->ruleResult(
                'NSF-R007',
                'Runtime query observability command file missing',
                'failed',
                [$commandFile],
                'Restore driver-aware observability command',
                'error',
            )];
        }

        $contents = (string) file_get_contents($commandFile);
        $hasGuard = str_contains($contents, 'pgsql') || str_contains($contents, 'database_driver');

        return [$this->ruleResult(
            'NSF-R007',
            $hasGuard
                ? 'Driver-aware SQL guard patterns present in observability command'
                : 'Driver-aware SQL guard not detected in observability command',
            $hasGuard ? 'passed' : 'warning',
            ['PerformanceRuntimeQueryObservabilityCommand'],
            'Guard PostgreSQL-specific SQL behind driver checks',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR008(): array
    {
        $commands = config('nsf.observability_commands', []);
        $missing = array_values(array_filter($commands, fn (string $name) => ! $this->commandExists($name)));

        if ($missing !== []) {
            return [$this->ruleResult(
                'NSF-R008',
                'Required observability commands missing',
                'failed',
                $missing,
                'Keep performance audit and runtime observability commands registered',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R008',
            'Observability commands are registered',
            'passed',
            $commands,
            'Run observability commands during foundation sprint evidence',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR009(bool $deepCheck): array
    {
        $driver = (string) config('database.default');

        if ($driver !== 'pgsql') {
            return [$this->ruleResult(
                'NSF-R009',
                'pg_stat observability guardrail applies on VPS PostgreSQL (not applicable locally)',
                'not_applicable',
                [$driver],
                'Validate pg_stat observability on VPS with --include-observability during deploy',
                'warning',
            )];
        }

        if (! $deepCheck) {
            return [$this->ruleResult(
                'NSF-R009',
                'pg_stat observability deep-check skipped — rerun with --include-observability on deploy',
                'skipped',
                ['include_observability' => false],
                'Run architecture:nsf-governance-check --include-observability during VPS deploy',
                'info',
            )];
        }

        $databaseStat = $this->inspectPgStatDatabase();
        $statementsStat = $this->inspectPgStatStatements();
        $evidence = [
            'pg_stat_database' => $databaseStat,
            'pg_stat_statements' => $statementsStat,
        ];

        if (! ($databaseStat['readable'] ?? false)) {
            return [$this->ruleResult(
                'NSF-R009',
                'pg_stat_database not readable: '.($databaseStat['reason'] ?? 'permission denied'),
                'warning',
                $evidence,
                'Grant SELECT on pg_stat_database to the application DB role',
                'warning',
            )];
        }

        $message = 'pg_stat_database readable for PostgreSQL observability';
        if ($statementsStat['available'] ?? false) {
            $message .= '; pg_stat_statements also available';
        } else {
            $message .= '; pg_stat_statements optional ('.($statementsStat['reason'] ?? 'not installed').')';
        }

        return [$this->ruleResult(
            'NSF-R009',
            $message,
            'passed',
            $evidence,
            ($statementsStat['available'] ?? false)
                ? 'Continue periodic runtime observability during deploy evidence'
                : 'pg_stat_database satisfies NSF-R009; install pg_stat_statements optionally for query-level observability',
            'warning',
        )];
    }

    /**
     * @return array{readable: bool, view: string, database?: ?string, reason?: string}
     */
    private function inspectPgStatDatabase(): array
    {
        try {
            DB::selectOne('SELECT 1 FROM pg_stat_database WHERE datname = current_database() LIMIT 1');
            DB::selectOne('SELECT numbackends FROM pg_stat_database WHERE datname = current_database() LIMIT 1');
            $row = DB::selectOne('SELECT current_database() AS name');

            return [
                'readable' => true,
                'view' => 'pg_stat_database',
                'database' => $row !== null ? (string) $row->name : null,
            ];
        } catch (\Throwable $exception) {
            return [
                'readable' => false,
                'view' => 'pg_stat_database',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{available: bool, extension_installed?: bool, preloaded?: bool, reason?: ?string}
     */
    private function inspectPgStatStatements(): array
    {
        if (! $this->commandExists('performance:runtime-query-observability')) {
            return [
                'available' => false,
                'reason' => 'performance:runtime-query-observability command missing',
            ];
        }

        try {
            /*
             * This nested command must NOT go through Artisan::call().
             *
             * Illuminate\Console\Application::call() does:
             *
             *     $this->run($input, $this->lastOutput = $outputBuffer ?: new BufferedOutput)
             *
             * so it reassigns the shared `lastOutput` even when an explicit
             * buffer is supplied. Reading that buffer then DRAINS it. The net
             * effect was that this inner call destroyed the output of whichever
             * command was running on the outside: the caller asked for its own
             * output afterwards and received this command's already-emptied
             * buffer instead.
             *
             * The visible symptom was `architecture:nsf-governance-check --json
             * --include-observability` appearing to emit nothing at all (exit 0,
             * zero bytes), so callers doing
             * json_decode(Artisan::output(), ..., JSON_THROW_ON_ERROR) failed
             * with "Syntax error" on an empty string. It reproduced only on
             * PostgreSQL because this pg_stat_statements branch does not run
             * otherwise.
             *
             * Running the command object directly keeps its output fully
             * isolated and leaves `lastOutput` untouched.
             */
            $buffer = new BufferedOutput;

            Artisan::getFacadeRoot()
                ->getArtisan()
                ->find('performance:runtime-query-observability')
                ->run(new ArrayInput([
                    '--json' => true,
                    '--limit' => 1,
                    '--min-calls' => 1,
                ]), $buffer);

            $payload = json_decode($buffer->fetch(), true);
            $pgStat = is_array($payload) ? ($payload['pg_stat_statements'] ?? []) : [];
            $available = (bool) ($pgStat['available'] ?? false);

            return [
                'available' => $available,
                'extension_installed' => (bool) ($pgStat['extension_installed'] ?? false),
                'preloaded' => (bool) ($pgStat['preloaded'] ?? false),
                'reason' => $available ? null : (string) ($pgStat['setup_instructions'] ?? 'pg_stat_statements not available'),
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR010(): array
    {
        $terms = config('nsf.privacy_forbidden_terms', []);

        return [$this->ruleResult(
            'NSF-R010',
            'Privacy forbidden terms registry declared for evidence commands',
            'passed',
            $terms,
            'Never emit patient names, KTP/NIK, phone, address, diagnosis, or raw financial rows',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR011(): array
    {
        $workflow = (string) config('foundation_governance.ci_evidence_gates.workflow', '');
        $workflowPath = $workflow !== '' ? base_path($workflow) : '';
        $script = (string) config('foundation_governance.ci_evidence_gates.script', '');
        $scriptPath = $script !== '' ? base_path($script) : '';

        if ($workflowPath !== '' && is_file($workflowPath) && $scriptPath !== '' && is_file($scriptPath)) {
            return [$this->ruleResult(
                'NSF-R011',
                'Full suite gate automated via Foundation Evidence Gates CI workflow',
                'passed',
                [
                    $workflow,
                    $script,
                    'jobs: critical_test_gate (PR), full_suite_gate (dispatch/schedule/push)',
                ],
                'Verify GitHub Actions run for PR/GO tag; full suite on schedule or workflow_dispatch',
                'warning',
            )];
        }

        return [$this->ruleResult(
            'NSF-R011',
            'Full suite gate CI workflow missing — manual evidence required',
            'warning',
            ['php artisan test'],
            'Add .github/workflows/foundation-evidence-gates.yml before GO',
            'warning',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR012(): array
    {
        $workflow = (string) config('foundation_governance.ci_evidence_gates.workflow', '');
        $workflowPath = $workflow !== '' ? base_path($workflow) : '';

        if ($workflowPath !== '' && is_file($workflowPath)) {
            return [$this->ruleResult(
                'NSF-R012',
                'Build gate automated via Foundation Evidence Gates quality_gate job',
                'passed',
                [
                    $workflow,
                    'npm run build',
                    './vendor/bin/pint --test',
                ],
                'Verify GitHub Actions quality_gate job on PR before GO tag',
                'warning',
            )];
        }

        return [$this->ruleResult(
            'NSF-R012',
            'Build gate CI workflow missing — manual npm build and pint evidence required',
            'warning',
            ['npm run build', './vendor/bin/pint'],
            'Add Foundation Evidence Gates workflow before GO tag',
            'warning',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR013(): array
    {
        $deployScript = base_path('scripts/deploy-vps.sh');
        $workflow = base_path('.github/workflows/deploy-vps.yml');

        if (! is_file($deployScript)) {
            return [$this->ruleResult(
                'NSF-R013',
                'VPS deploy script missing pre-deploy backup gate',
                'failed',
                [$deployScript],
                'Add pg_dump backup step to deploy script',
                'error',
            )];
        }

        $contents = (string) file_get_contents($deployScript);
        $hasBackup = str_contains($contents, 'pg_dump') && str_contains($contents, 'backups/deploy');

        return [$this->ruleResult(
            'NSF-R013',
            $hasBackup
                ? 'Deploy backup gate documented in VPS deploy script'
                : 'Deploy backup gate not found in VPS deploy script',
            $hasBackup ? 'passed' : 'failed',
            [$deployScript, $workflow],
            'Record backup path and size in sprint evidence',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR014(): array
    {
        $deployScript = base_path('scripts/deploy-vps.sh');

        if (! is_file($deployScript)) {
            return [$this->ruleResult(
                'NSF-R014',
                'VPS deploy smoke gate script missing',
                'failed',
                [$deployScript],
                'Add smoke checks for /login and protected routes',
                'error',
            )];
        }

        $contents = (string) file_get_contents($deployScript);
        $hasSmoke = str_contains($contents, 'Smoke check') || str_contains($contents, 'smoke');

        return [$this->ruleResult(
            'NSF-R014',
            $hasSmoke
                ? 'Deploy smoke gate documented in VPS deploy script'
                : 'Deploy smoke gate not found in VPS deploy script',
            $hasSmoke ? 'passed' : 'failed',
            [$deployScript],
            'Verify /login 200 and protected routes 302 without 500',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR015(): array
    {
        $composerPath = base_path('composer.json');

        if (! is_file($composerPath)) {
            return [$this->ruleResult(
                'NSF-R015',
                'composer.json not found for distributed tech scan',
                'failed',
                ['composer.json'],
                'Keep distributed tech out until NDA approval',
                'error',
            )];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $require = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );

        $forbidden = config('nsf.forbidden_distributed_packages', []);
        $found = array_values(array_intersect($forbidden, $require));

        if ($found !== []) {
            return [$this->ruleResult(
                'NSF-R015',
                'Forbidden distributed technology packages detected',
                'failed',
                $found,
                'Remove distributed packages until NDA sprint approval',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R015',
            'No forbidden distributed technology packages in composer.json',
            'passed',
            $forbidden,
            'Defer Redis/Kafka/GraphQL/gRPC/NoSQL/LB/CDN until NDA',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR016(bool $includeDmo): array
    {
        if (! $this->commandExists('architecture:dmo-governance-check') || ! is_file(base_path('config/dmo.php'))) {
            return [$this->ruleResult(
                'NSF-R016',
                'DMO governance alignment tooling missing',
                'failed',
                ['architecture:dmo-governance-check', 'config/dmo.php'],
                'Keep DMO governance check available for metric/report alignment',
                'error',
            )];
        }

        if (! $includeDmo) {
            return [$this->ruleResult(
                'NSF-R016',
                'DMO alignment command available (run with --include-dmo for live status)',
                'passed',
                ['architecture:dmo-governance-check'],
                'New KPIs/reports must pass DMO governance check',
                'error',
            )];
        }

        $dmo = $this->dmoRules->collect(['include_warnings' => true]);
        $errors = (int) ($dmo['summary']['errors'] ?? 0);

        return [$this->ruleResult(
            'NSF-R016',
            $errors === 0
                ? 'DMO governance check reports no error-level failures'
                : "DMO governance check reports {$errors} error(s)",
            $errors === 0 ? 'passed' : 'failed',
            ['architecture:dmo-governance-check'],
            'Resolve DMO governance errors before NDA work',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR017(): array
    {
        return $this->commandAvailabilityRule(
            'NSF-R017',
            'architecture:owner-kpi-registry',
            'Owner KPI registry command is available',
            'Keep Owner KPI changes aligned with OwnerKpiRegistryService',
            'error',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR018(): array
    {
        $commands = config('nsf.governance_commands', []);
        $missing = array_values(array_filter($commands, fn (string $name) => ! $this->commandExists($name)));

        if ($missing !== []) {
            return [$this->ruleResult(
                'NSF-R018',
                'Governance commands missing from application',
                'failed',
                $missing,
                'Register read-only architecture governance commands',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R018',
            'Read-only governance commands are registered',
            'passed',
            $commands,
            'Governance commands must not mutate operational data',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR019(): array
    {
        $pattern = base_path('docs/sprints');
        $hasRollbackDocs = false;

        if (is_dir($pattern)) {
            foreach (glob($pattern.'/*.md') ?: [] as $file) {
                $contents = (string) file_get_contents($file);
                if (str_contains(strtolower($contents), 'rollback')) {
                    $hasRollbackDocs = true;
                    break;
                }
            }
        }

        return [$this->ruleResult(
            'NSF-R019',
            $hasRollbackDocs
                ? 'Foundation sprint rollback documentation pattern exists'
                : 'Rollback documentation not found in sprint docs',
            $hasRollbackDocs ? 'passed' : 'warning',
            ['docs/sprints'],
            'Document rollback plan in every foundation sprint evidence',
            'warning',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR020(): array
    {
        $roots = config('nsf.evidence_roots', []);
        $missing = [];

        foreach ($roots as $root) {
            $path = storage_path(str_replace('storage/', '', $root));
            if (! is_dir($path)) {
                $missing[] = $root;
            }
        }

        if ($missing !== []) {
            return [$this->ruleResult(
                'NSF-R020',
                'Evidence root directories missing',
                'warning',
                $missing,
                'Create storage/app/architecture and storage/app/performance for evidence',
                'error',
            )];
        }

        return [$this->ruleResult(
            'NSF-R020',
            'Evidence path roots are available',
            'passed',
            $roots,
            'Write governance evidence under storage/app/architecture or storage/app/performance',
            'error',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR021(): array
    {
        return [$this->ruleResult(
            'NSF-R021',
            'NDA readiness boundary declared — distributed implementation must not violate NSF/DMO guardrails',
            'passed',
            ['nda_boundary'],
            'NDA sprints must pass both NSF and DMO governance checks',
            'info',
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateDeferredWarnings(): array
    {
        $results = [];

        foreach (config('nsf.deferred_warnings', []) as $id => $message) {
            $results[] = $this->ruleResult(
                $id,
                $message,
                'warning',
                ['deferred_backlog'],
                'Documented backlog — does not block GO when no errors exist',
                'warning',
            );
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commandAvailabilityRule(
        string $ruleId,
        string $command,
        string $passedMessage,
        string $recommendation,
        string $severity,
    ): array {
        if (! $this->commandExists($command)) {
            return [$this->ruleResult(
                $ruleId,
                "Required command [{$command}] is not registered",
                'failed',
                [$command],
                $recommendation,
                $severity,
            )];
        }

        return [$this->ruleResult(
            $ruleId,
            $passedMessage,
            'passed',
            [$command],
            $recommendation,
            $severity,
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectDmoAlignment(bool $includeDmo): array
    {
        if (! $includeDmo || ! $this->commandExists('architecture:dmo-governance-check')) {
            return [
                'available' => $this->commandExists('architecture:dmo-governance-check'),
                'governance_errors' => null,
                'governance_warnings' => null,
                'decision' => null,
            ];
        }

        $dmo = $this->dmoRules->collect(['include_warnings' => true]);

        return [
            'available' => true,
            'governance_errors' => (int) ($dmo['summary']['errors'] ?? 0),
            'governance_warnings' => (int) ($dmo['summary']['warnings'] ?? 0),
            'decision' => (string) ($dmo['summary']['decision'] ?? 'UNKNOWN'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectObservability(bool $deepCheck): array
    {
        $base = [
            'slow_query_audit_command_available' => $this->commandExists('performance:slow-query-audit'),
            'runtime_query_observability_command_available' => $this->commandExists('performance:runtime-query-observability'),
            'pg_stat_expected_on_vps' => (string) config('database.default') === 'pgsql',
        ];

        if ($deepCheck && config('database.default') === 'pgsql') {
            $base['pg_stat_database'] = $this->inspectPgStatDatabase();
            if ($base['runtime_query_observability_command_available']) {
                $base['pg_stat_statements'] = $this->inspectPgStatStatements();
            }
        }

        return $base;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectDeployGates(): array
    {
        $gates = config('nsf.deploy_gates', []);
        $deployScript = is_file(base_path('scripts/deploy-vps.sh'))
            ? (string) file_get_contents(base_path('scripts/deploy-vps.sh'))
            : '';

        $checks = [
            'pre_deploy_db_backup' => str_contains($deployScript, 'pg_dump'),
            'composer_install' => str_contains($deployScript, 'composer install'),
            'npm_build' => str_contains($deployScript, 'npm run build'),
            'migrate_force' => str_contains($deployScript, 'migrate --force'),
            'cache_rebuild' => str_contains($deployScript, 'config:cache'),
            'service_restart' => str_contains($deployScript, 'php8.3-fpm'),
            'smoke_check' => str_contains(strtolower($deployScript), 'smoke'),
        ];

        return array_map(fn (string $gate) => [
            'gate' => $gate,
            'documented' => (bool) ($checks[$gate] ?? false),
            'status' => ($checks[$gate] ?? false) ? 'passed' : 'warning',
        ], $gates);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backlogEntries(): array
    {
        return collect(config('nsf.deferred_warnings', []))
            ->map(fn (string $message, string $id) => [
                'id' => $id,
                'message' => $message,
                'status' => 'deferred',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function privacyScanPassed(array $report): bool
    {
        $text = collect($report['rules'] ?? [])
            ->map(fn (array $rule) => ($rule['message'] ?? '').' '.($rule['recommendation'] ?? ''))
            ->implode(' ');

        $terms = config('nsf.privacy_forbidden_terms', []);

        foreach ($terms as $term) {
            if (stripos($text, $term) !== false) {
                return false;
            }
        }

        return ! preg_match('/\d{16}/', $text);
    }

    private function commandExists(string $name): bool
    {
        return array_key_exists($name, Artisan::all());
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, mixed>
     */
    private function ruleResult(
        string $ruleId,
        string $message,
        string $status,
        array $targets,
        string $recommendation,
        string $severity,
    ): array {
        $configured = collect(config('nsf.rules', []))->firstWhere('rule_id', $ruleId);

        return [
            'rule_id' => $ruleId,
            'title' => (string) ($configured['title'] ?? $ruleId),
            'severity' => $severity,
            'status' => $status,
            'targets' => $targets,
            'message' => $message,
            'recommendation' => $recommendation,
            'privacy_safe' => true,
        ];
    }
}
