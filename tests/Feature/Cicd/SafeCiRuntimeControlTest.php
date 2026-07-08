<?php

use App\Services\Foundation\CiRuntimeControlGovernanceService;
use App\Support\Cicd\CiRuntimeControlScanner;
use Illuminate\Support\Facades\Process;

uses()->group('Cicd', 'Ci', 'CiRuntimeControl', 'FoundationGovernance');

/**
 * Runs the real classifier script against a synthetic changed-file list and
 * returns the parsed key=value output.
 *
 * @param  list<string>  $files
 * @return array<string, string>
 */
function resolveGates(array $files): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'cicd-ctrl-');
    file_put_contents($tmp, implode("\n", $files)."\n");

    try {
        $result = Process::path(base_path())->run([
            'bash', base_path('scripts/ci/resolve-gates.sh'),
            '--changed-files-file', $tmp,
        ]);
    } finally {
        @unlink($tmp);
    }

    expect($result->successful())->toBeTrue();

    $out = [];
    foreach (preg_split('/\r?\n/', $result->output()) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }
    }

    return $out;
}

it('classifies a docs-only change as docs_only and skips the critical gate', function () {
    $r = resolveGates(['docs/sprints/foo.md', 'docs/architecture/bar.md', 'README.md']);

    expect($r['gate_profile'])->toBe('docs_only')
        ->and($r['run_critical_tests'])->toBe('false')
        ->and($r['run_ui_tests'])->toBe('false')
        ->and($r['run_permission_tests'])->toBe('false')
        ->and($r['run_inventory_tests'])->toBe('false')
        ->and($r['run_full_suite'])->toBe('false');
});

it('never weakens the gate for app / route / migration / config / test changes', function () {
    foreach ([
        ['app/Services/Foo.php'],
        ['routes/web.php'],
        ['database/migrations/2026_07_09_000000_add_thing.php'],
        ['config/app.php'],
        ['tests/Feature/RME/SomeTest.php'],
        ['bootstrap/app.php'],
    ] as $files) {
        $r = resolveGates($files);
        expect($r['gate_profile'])->toBe('runtime_app', 'profile for '.$files[0]);
        expect($r['run_critical_tests'])->toBe('true', 'critical for '.$files[0]);
    }
});

it('classifies permission / policy / middleware changes as permissions_security and runs permission tests', function () {
    foreach ([
        'app/Policies/LabCaseCandidatePolicy.php',
        'app/Http/Middleware/EnsureVisitRoomAssigned.php',
        'database/seeders/PermissionSeeder.php',
    ] as $file) {
        $r = resolveGates([$file]);
        expect($r['gate_profile'])->toBe('permissions_security', 'profile for '.$file);
        expect($r['run_critical_tests'])->toBe('true')
            ->and($r['run_permission_tests'])->toBe('true');
    }
});

it('forces the strongest gate and all module tests for workflow / script / dependency changes', function () {
    foreach ([
        ['.github/workflows/foundation-evidence-gates.yml' => 'ci_workflow'],
        ['scripts/ci/resolve-gates.sh' => 'ci_workflow'],
        ['composer.lock' => 'dependency_or_build'],
        ['package.json' => 'dependency_or_build'],
        ['phpunit.xml' => 'dependency_or_build'],
    ] as $case) {
        $file = array_key_first($case);
        $expected = $case[$file];
        $r = resolveGates([$file]);
        expect($r['gate_profile'])->toBe($expected, 'profile for '.$file);
        expect($r['run_critical_tests'])->toBe('true')
            ->and($r['run_inventory_tests'])->toBe('true')
            ->and($r['run_rme_tests'])->toBe('true')
            ->and($r['run_lab_tests'])->toBe('true')
            ->and($r['run_permission_tests'])->toBe('true')
            ->and($r['run_full_suite'])->toBe('required');
    }
});

it('runs critical tests and UI module tests for view/asset changes', function () {
    $r = resolveGates(['resources/views/rme/visits/show.blade.php']);
    expect($r['run_critical_tests'])->toBe('true')
        ->and($r['run_ui_tests'])->toBe('true');

    $css = resolveGates(['resources/css/app.css']);
    expect($css['gate_profile'])->toBe('ui_only')
        ->and($css['run_critical_tests'])->toBe('true')
        ->and($css['run_ui_tests'])->toBe('true');
});

it('flags inventory and lab module tests when those modules change', function () {
    $inv = resolveGates(['app/Modules/Inventory/Services/StockService.php']);
    expect($inv['run_critical_tests'])->toBe('true')
        ->and($inv['run_inventory_tests'])->toBe('true');

    $lab = resolveGates(['resources/views/lab/orders/index.blade.php']);
    expect($lab['run_critical_tests'])->toBe('true')
        ->and($lab['run_lab_tests'])->toBe('true');
});

it('resolves an unknown path to unknown_high_risk and runs everything', function () {
    $r = resolveGates(['some/weird/artifact.bin']);
    expect($r['gate_profile'])->toBe('unknown_high_risk')
        ->and($r['run_critical_tests'])->toBe('true')
        ->and($r['run_full_suite'])->toBe('required');
});

it('never picks a weak gate for a mixed docs + code change', function () {
    $r = resolveGates(['docs/x.md', 'app/Services/Foo.php']);
    expect($r['gate_profile'])->toBe('runtime_app')
        ->and($r['run_critical_tests'])->toBe('true');
});

it('defaults to the strongest gate when the change set is empty', function () {
    $r = resolveGates([]);
    expect($r['gate_profile'])->toBe('unknown_high_risk')
        ->and($r['run_critical_tests'])->toBe('true');
});

it('keeps the safety invariant: docs_only is the only skip-critical profile', function () {
    expect(array_values((array) config('ci_runtime_control.skip_critical_profiles')))->toBe(['docs_only'])
        ->and(config('ci_runtime_control.default_profile'))->toBe('unknown_high_risk');

    $scanner = app(CiRuntimeControlScanner::class);
    $invariant = $scanner->safetyInvariantPosture();
    expect($invariant['ok'])->toBeTrue();
    expect($invariant['issues'])->toBe([]);
});

it('confirms the classifier script and workflow wiring pass the scanner', function () {
    $scanner = app(CiRuntimeControlScanner::class);

    $classifier = $scanner->classifierScriptPosture();
    expect($classifier['exists'])->toBeTrue()
        ->and($classifier['ok'])->toBeTrue()
        ->and($classifier['missing_markers'])->toBe([]);

    $workflow = $scanner->workflowPosture();
    expect($workflow['exists'])->toBeTrue()
        ->and($workflow['ok'])->toBeTrue()
        ->and($workflow['forbidden_present'])->toBe([])
        ->and($workflow['missing_always_on'])->toBe([])
        ->and($workflow['full_suite_fallback'])->toBeTrue();
});

it('never adds unsafe path filtering to the workflow', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));
    expect($workflow)->not->toContain('paths-ignore');
});

it('publishes the CICDCTRL rules and reports GO through the governance service', function () {
    $report = app(CiRuntimeControlGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['classifier_ok'])->toBeTrue()
        ->and($report['workflow_ok'])->toBeTrue()
        ->and($report['safety_invariant_ok'])->toBeTrue()
        ->and($report['enterprise_gate_decision'])->toBe('GO');

    foreach (range(1, 11) as $n) {
        $id = sprintf('CICDCTRL-R%03d', $n);
        expect(collect($report['rules'])->pluck('id'))->toContain($id);
    }
});

it('foundation:ci-runtime-control-check exits zero under strict', function () {
    $exit = Artisan::call('foundation:ci-runtime-control-check', ['--strict' => true]);
    expect($exit)->toBe(0);
});
