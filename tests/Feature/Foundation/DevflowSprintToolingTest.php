<?php

declare(strict_types=1);

use App\Services\Foundation\DevflowGovernanceService;
use App\Support\Devflow\SharedFoundationScanner;
use App\Support\Devflow\SprintAuditPlanner;
use App\Support\Devflow\SprintEvidenceGenerator;
use App\Support\Devflow\SprintManifest;
use App\Support\Devflow\SprintManifestValidator;
use App\Support\Devflow\SprintScopeAuditor;
use App\Support\Devflow\SprintTestPlanner;

/*
 * DEVFLOW-1 — safe sprint acceleration tooling.
 * Pure-logic + command-exit tests. Read-only tooling; nothing mutates state.
 */

function validManifestArray(array $overrides = []): array
{
    return array_merge([
        'id' => 'FIX-XYZ',
        'type' => 'HOTFIX',
        'module' => 'Lab',
        'base_branch' => config('devflow.manifest.required_base_branch'),
        'runtime_change' => true,
        'schema_change' => false,
        'frontend_change' => false,
        'security_impact' => false,
        'deploy_required' => true,
        'go_tag' => 'fix-xyz-go',
    ], $overrides);
}

// ---------------------------------------------------------------- Manifest ---

it('accepts a valid manifest as GO', function () {
    $result = (new SprintManifestValidator)->validate(SprintManifest::fromArray(validManifestArray()));
    expect($result['valid'])->toBeTrue();
    expect($result['decision'])->toBe('GO');
});

it('fails a manifest missing a required field', function () {
    $data = validManifestArray();
    unset($data['module']);
    $result = (new SprintManifestValidator)->validate(SprintManifest::fromArray($data));
    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('module');
});

it('rejects a manifest targeting main', function () {
    $result = (new SprintManifestValidator)->validate(SprintManifest::fromArray(validManifestArray(['base_branch' => 'main'])));
    expect($result['valid'])->toBeFalse();
    expect($result['decision'])->toBe('NO-GO');
});

it('rejects an unknown sprint type', function () {
    $result = (new SprintManifestValidator)->validate(SprintManifest::fromArray(validManifestArray(['type' => 'NONSENSE'])));
    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('Unknown sprint type');
});

it('rejects an invalid GO tag', function () {
    $result = (new SprintManifestValidator)->validate(SprintManifest::fromArray(validManifestArray(['go_tag' => 'BadTag_NoGoSuffix'])));
    expect($result['valid'])->toBeFalse();
});

it('fails on a contradictory schema flag vs a changed migration', function () {
    $manifest = SprintManifest::fromArray(validManifestArray(['type' => 'RUNTIME_FIX', 'schema_change' => false]));
    $result = (new SprintManifestValidator)->validate($manifest, ['database/migrations/2026_01_01_000000_add_x.php']);
    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('schema_change=false');
});

it('fails on a contradictory frontend flag vs changed assets', function () {
    $manifest = SprintManifest::fromArray(validManifestArray(['type' => 'MODULE_SPRINT', 'frontend_change' => false]));
    $result = (new SprintManifestValidator)->validate($manifest, ['resources/js/app.js']);
    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('frontend_change=false');
});

it('fails on a contradictory security flag vs a changed policy', function () {
    $manifest = SprintManifest::fromArray(validManifestArray(['type' => 'MODULE_SPRINT', 'security_impact' => false]));
    $result = (new SprintManifestValidator)->validate($manifest, ['app/Policies/LabOrderPolicy.php']);
    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('security_impact=false');
});

it('fails DOCS_ONLY carrying a runtime change', function () {
    $manifest = SprintManifest::fromArray(validManifestArray(['type' => 'DOCS_ONLY', 'runtime_change' => true, 'deploy_required' => false]));
    $result = (new SprintManifestValidator)->validate($manifest);
    expect($result['valid'])->toBeFalse();
});

// --------------------------------------------------------------- Test plan ---

it('maps a notification change to the notifications category and its related closure', function () {
    $plan = (new SprintTestPlanner)->plan(['app/Modules/LabOrder/Support/LabWorkflowNotificationDestinationResolver.php']);
    expect($plan['matched_categories'])->toContain('notifications');
    expect($plan['related_categories'])->toContain('access_control');
});

it('includes ledger when inventory ledger code changes', function () {
    $plan = (new SprintTestPlanner)->plan(['app/Modules/Inventory/Services/InventoryStockService.php']);
    expect($plan['all_categories'])->toContain('ledger');
});

it('includes payment filters when rme cashier changes', function () {
    $plan = (new SprintTestPlanner)->plan(['resources/views/rme/cashier/create.blade.php']);
    expect($plan['focused_filters'])->toContain('Payment');
});

it('escalates the full suite on a policy change', function () {
    $plan = (new SprintTestPlanner)->plan(['app/Policies/LabOrderPolicy.php']);
    expect($plan['escalate_full_suite'])->toBeTrue();
    expect($plan['ci_jobs'])->toContain('full_suite_gate');
});

it('escalates the full suite on a migration change', function () {
    $plan = (new SprintTestPlanner)->plan(['database/migrations/2026_01_01_000000_add_x.php']);
    expect($plan['escalate_full_suite'])->toBeTrue();
});

it('escalates the full suite on a shared foundation change', function () {
    $plan = (new SprintTestPlanner)->plan(['config/shared_foundations.php']);
    expect($plan['escalate_full_suite'])->toBeTrue();
});

it('does not escalate for a ui-only view change', function () {
    $plan = (new SprintTestPlanner)->plan(['resources/views/components/ui/badge.blade.php']);
    expect($plan['escalate_full_suite'])->toBeFalse();
    expect($plan['matched_categories'])->toContain('ui_navigation');
});

it('escalates the full suite when the change set is unresolved', function () {
    $plan = (new SprintTestPlanner)->plan([], false);
    expect($plan['escalate_full_suite'])->toBeTrue();
    expect(implode(' ', $plan['escalation_reasons']))->toContain('unknown');
});

it('escalates on an unmatched changed file (fail closed)', function () {
    $plan = (new SprintTestPlanner)->plan(['some/unknown/path/File.php']);
    expect($plan['escalate_full_suite'])->toBeTrue();
    expect($plan['unmatched_files'])->toContain('some/unknown/path/File.php');
});

it('produces a deterministic plan for the same input', function () {
    $a = (new SprintTestPlanner)->plan(['app/Modules/Inventory/Services/InventoryStockService.php']);
    $b = (new SprintTestPlanner)->plan(['app/Modules/Inventory/Services/InventoryStockService.php']);
    expect($a)->toEqual($b);
    expect($a['focused_filters'])->toEqual(array_values(array_unique($a['focused_filters'])));
});

// -------------------------------------------------------------- Scope audit ---

it('passes a coherent single-module hotfix', function () {
    $manifest = SprintManifest::fromArray(validManifestArray());
    $result = (new SprintScopeAuditor)->audit($manifest, ['app/Modules/LabOrder/Services/LabWorkflowRequestService.php']);
    expect($result['decision'])->toBe('GO');
});

it('rejects a docs-only manifest carrying runtime code', function () {
    $manifest = SprintManifest::fromArray(validManifestArray(['type' => 'DOCS_ONLY', 'runtime_change' => false, 'deploy_required' => false]));
    $result = (new SprintScopeAuditor)->audit($manifest, ['app/Modules/LabOrder/Services/LabWorkflowRequestService.php']);
    expect($result['decision'])->toBe('NO-GO');
});

it('rejects a hotfix carrying a wide refactor', function () {
    $files = array_map(fn ($i) => "app/Modules/LabOrder/Services/Service{$i}.php", range(1, 12));
    $manifest = SprintManifest::fromArray(validManifestArray());
    $result = (new SprintScopeAuditor)->audit($manifest, $files);
    expect($result['decision'])->toBe('NO-GO');
});

// -------------------------------------------------------------- Audit plan ---

it('emits audit level 1 for a hotfix and level 3 for a foundation sprint', function () {
    $planner = new SprintAuditPlanner;
    expect($planner->plan(SprintManifest::fromArray(validManifestArray(['type' => 'HOTFIX'])), [])['audit_level'])->toBe(1);
    expect($planner->plan(SprintManifest::fromArray(validManifestArray(['type' => 'FOUNDATION_SPRINT'])), [])['audit_level'])->toBe(3);
});

// ----------------------------------------------------------------- Evidence ---

it('renders real values and marks missing evidence as NOT AVAILABLE', function () {
    $gen = app(SprintEvidenceGenerator::class);
    $evidence = $gen->build(SprintManifest::fromArray(validManifestArray()));
    expect($evidence['sprint_id'])->toBe('FIX-XYZ');
    expect($evidence['deploy'])->toBe('NOT AVAILABLE');
    expect($gen->toMarkdown($evidence))->toContain('Sprint Evidence');
});

it('redacts sensitive labels and long digit runs in evidence', function () {
    $gen = app(SprintEvidenceGenerator::class);
    $evidence = $gen->build(SprintManifest::fromArray(validManifestArray()), [
        'logs' => 'token=abcdef123 patient 3172010101010001 ok',
    ]);
    expect($evidence['logs'])->not->toContain('abcdef123');
    expect($evidence['logs'])->not->toContain('3172010101010001');
});

// ------------------------------------------------------- Shared foundations ---

it('passes the shared foundation registry audit', function () {
    $result = (new SharedFoundationScanner(base_path()))->scan();
    expect($result['decision'])->toBe('GO');
    expect($result['summary']['errors'])->toBe(0);
});

// ------------------------------------------------------- Devflow governance ---

it('reports a GO devflow governance decision with all rules', function () {
    $report = app(DevflowGovernanceService::class)->collect();
    expect($report['decision'])->toBe('GO');
    $ruleIds = array_column($report['rules'], 'id');
    expect($ruleIds)->toContain('DEVFLOW-R001')->toContain('DEVFLOW-R010');
});

// ------------------------------------------------------------- Command exit ---

it('exits success for a valid manifest via sprint:manifest-check', function () {
    $this->artisan('sprint:manifest-check', ['--manifest' => base_path('.sprint/current.yml'), '--no-diff-check' => true])
        ->assertExitCode(0);
});

it('exits failure for a NO-GO release-check when CI is asserted failed', function () {
    $this->artisan('sprint:release-check', ['--manifest' => base_path('.sprint/current.yml'), '--ci-passed' => 'false'])
        ->assertExitCode(1);
});

it('exits success for foundation:devflow-check --strict', function () {
    $this->artisan('foundation:devflow-check', ['--strict' => true])->assertExitCode(0);
});

it('exits success for foundation:shared-service-audit --strict', function () {
    $this->artisan('foundation:shared-service-audit', ['--strict' => true])->assertExitCode(0);
});

// ------------------------------------------------- Dependency-free YAML parse ---

it('parses the manifest without symfony/yaml via the built-in fallback (VPS --no-dev)', function () {
    $raw = <<<'YAML'
    # comment
    id: FIX-XYZ
    type: HOTFIX
    module: Lab
    runtime_change: true
    schema_change: false
    test_profiles:
      - focused
      - related_regression
    go_tag: fix-xyz-go
    YAML;

    $m = new ReflectionMethod(SprintManifest::class, 'parseSimpleYaml');
    $m->setAccessible(true);
    $parsed = $m->invoke(null, $raw);

    expect($parsed['id'])->toBe('FIX-XYZ');
    expect($parsed['runtime_change'])->toBeTrue();
    expect($parsed['schema_change'])->toBeFalse();
    expect($parsed['test_profiles'])->toBe(['focused', 'related_regression']);
    expect($parsed['go_tag'])->toBe('fix-xyz-go');
});
