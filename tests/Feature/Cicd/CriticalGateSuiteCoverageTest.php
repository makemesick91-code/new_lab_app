<?php

use App\Services\Foundation\SelfHostedRunnerGovernanceService;
use App\Support\Cicd\SelfHostedRunnerScanner;

/**
 * CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1 — mandatory critical suites run.
 *
 * The NSF-R011 critical gate selects tests with a fixed `--filter` allowlist,
 * and until now the only contract over it asked whether a TOKEN was present.
 * That question cannot see the failure that matters. Every suite in
 * tests/Unit/Services/Monitoring was selected because its filename began with
 * `PilotPerformanceSnapshot`; MonitoringLogSourceResilienceTest — the suite
 * pinning that the monitor reads where the application actually writes — did
 * not, and so never ran in a required gate while its seven siblings did.
 * Coverage was a filename coincidence, and a rename would have removed it
 * exactly as silently as the original omission created it.
 *
 * These tests assert the property directly: a declared suite is selected, by
 * every variant, and any drift — a rename, a move, a deletion, a token dropped
 * from one variant — FAILS rather than disappearing.
 */
$writeWorkflow = function (string $yaml): string {
    $relative = 'storage/framework/cimctc1-wf-'.bin2hex(random_bytes(6)).'.yml';
    file_put_contents(base_path($relative), $yaml);
    config()->set('ci_runner.files.ci_workflow', $relative);

    return $relative;
};

// A stand-in workflow carrying both critical variants. `FoundationGovernance`
// is the anchor the scanner narrows on, so it must be present in both.
$workflowWith = function (string $hostedFilter, string $selfHostedFilter): string {
    return <<<YAML
    jobs:
      critical_test_gate:
        steps:
          - run: php artisan test --filter='{$hostedFilter}'
      critical_test_gate_self_hosted:
        steps:
          - run: php artisan test --filter='{$selfHostedFilter}'
    YAML;
};

// ---------------------------------------------------------------------------
// The contract holds for the real workflow and the real registry
// ---------------------------------------------------------------------------

it('selects every mandatory critical suite in every critical gate variant', function () {
    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();

    expect($posture['ok'])->toBeTrue(implode('; ', $posture['issues']))
        ->and($posture['issues'])->toBe([])
        ->and($posture['declared'])->not->toBeEmpty()
        // One filter per critical variant: GitHub-hosted and self-hosted.
        ->and($posture['critical_filters'])->toHaveCount(2);

    foreach ($posture['suites'] as $suite) {
        expect($suite['exists'])->toBeTrue("mandatory critical suite {$suite['path']} does not exist");

        // Matched by BOTH variants, not averaged across them.
        expect(array_keys($suite['matched_by']))->toBe(
            [0, 1],
            "mandatory critical suite {$suite['path']} is not selected by every critical gate variant"
        );
    }
});

it('closes the named residual: the log-source resilience suite is directly selected', function () {
    // The specific suite this sprint exists for. Before the fix it matched no
    // token in either variant and ran only in the deferred full suite.
    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();

    $target = 'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php';

    $suite = collect($posture['suites'])->firstWhere('path', $target);

    expect($suite)->not->toBeNull('the log-source resilience suite is not declared mandatory')
        ->and($suite['exists'])->toBeTrue()
        ->and($suite['matched_by'])->toHaveCount(2);
});

it('declares every monitor-truthfulness suite, so a new one cannot be added undeclared', function () {
    // Completeness, not just correctness. A future monitoring sprint that adds
    // a suite to this directory without declaring it would otherwise inherit
    // the same silent-omission failure mode this sprint closed.
    $declared = array_map(
        static fn (string $path): string => basename($path),
        (array) config('ci_runner.critical_gate_mandatory_suites', [])
    );

    $present = array_map('basename', glob(base_path('tests/Unit/Services/Monitoring/*Test.php')) ?: []);

    expect($present)->not->toBeEmpty();

    foreach ($present as $file) {
        // Not `toContain($file, $message)`: toContain() is variadic, so a second
        // argument is read as another needle rather than a failure message.
        expect(in_array($file, $declared, true))->toBeTrue(
            "tests/Unit/Services/Monitoring/{$file} is not declared in critical_gate_mandatory_suites"
        );
    }
});

// ---------------------------------------------------------------------------
// Derivation is pinned against the runner's real naming
// ---------------------------------------------------------------------------

it('derives the name the test runner really builds from a file path', function () {
    // Self-referential on purpose. Pest generates this very test's class from
    // this very file, so comparing the derived identity with the class actually
    // running proves the derivation without guessing at the naming scheme —
    // and keeps proving it if either the path or the scheme changes.
    $scanner = app(SelfHostedRunnerScanner::class);
    $method = (new ReflectionClass($scanner))->getMethod('testIdentity');
    $method->setAccessible(true);

    $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', __FILE__);
    $identity = $method->invoke($scanner, $relative);

    expect(get_class($this))->toContain($identity);
});

it('treats a path written in another shape as the same declared suite', function () {
    $scanner = app(SelfHostedRunnerScanner::class);
    $method = (new ReflectionClass($scanner))->getMethod('testIdentity');
    $method->setAccessible(true);

    $canonical = $method->invoke($scanner, 'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php');

    foreach ([
        './tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        '././tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        'tests\\Unit\\Services\\Monitoring\\MonitoringLogSourceResilienceTest.php',
    ] as $variant) {
        expect($method->invoke($scanner, $variant))->toBe($canonical);
    }
});

// ---------------------------------------------------------------------------
// Negative controls — drift must FAIL, never vanish
// ---------------------------------------------------------------------------

it('goes red when a mandatory suite is renamed beyond every token', function () use ($writeWorkflow, $workflowWith) {
    // The rename control. The contract is unchanged and the file still exists;
    // only its name moved out of the filter's reach. Silence here would be the
    // original defect, reintroduced.
    $filter = 'FoundationGovernance|PilotPerformanceSnapshot|MonitoringLogSource';
    $relative = $writeWorkflow($workflowWith($filter, $filter));

    config()->set('ci_runner.critical_gate_mandatory_suites', [
        // Same suite, renamed to something no token matches.
        'tests/Unit/Services/Monitoring/LogResolverContractTest.php',
    ]);

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();
    @unlink(base_path($relative));

    expect($posture['ok'])->toBeFalse()
        ->and(implode(' ', $posture['issues']))
        ->toContain('does not select mandatory suite');
});

it('goes red when one variant drops the token and the other keeps it', function () use ($writeWorkflow, $workflowWith) {
    // Coverage must hold whichever runner the job routes to. Averaging the two
    // variants would let a real gap ride on the other one's green.
    $relative = $writeWorkflow($workflowWith(
        'FoundationGovernance|MonitoringLogSource',
        'FoundationGovernance'
    ));

    config()->set('ci_runner.critical_gate_mandatory_suites', [
        'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
    ]);

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();
    @unlink(base_path($relative));

    expect($posture['ok'])->toBeFalse()
        ->and(implode(' ', $posture['issues']))->toContain('filter #1 does not select mandatory suite');
});

it('goes red on a stale registry entry rather than skipping it', function () use ($writeWorkflow, $workflowWith) {
    // A declared suite that no longer exists is a dead contract. Skipping it
    // silently would let the registry drift into decoration.
    $filter = 'FoundationGovernance|SuiteDeletedByAnEarlierSprint';
    $relative = $writeWorkflow($workflowWith($filter, $filter));

    config()->set('ci_runner.critical_gate_mandatory_suites', [
        'tests/Unit/Services/Monitoring/SuiteDeletedByAnEarlierSprintTest.php',
    ]);

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();
    @unlink(base_path($relative));

    // The token matches, so only existence can fail it — which is the point.
    expect($posture['ok'])->toBeFalse()
        ->and(implode(' ', $posture['issues']))->toContain('the registry is stale');
});

it('never treats an empty mandatory registry as satisfied', function () use ($writeWorkflow, $workflowWith) {
    // Zero declared suites trivially satisfies "all declared suites are
    // selected". An empty required scope is not success.
    $relative = $writeWorkflow($workflowWith('FoundationGovernance', 'FoundationGovernance'));

    config()->set('ci_runner.critical_gate_mandatory_suites', []);

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();
    @unlink(base_path($relative));

    expect($posture['ok'])->toBeFalse()
        ->and(implode(' ', $posture['issues']))->toContain('no mandatory critical suite is declared');
});

it('fails closed when the workflow itself cannot be read', function () {
    config()->set('ci_runner.files.ci_workflow', 'storage/framework/definitely-not-here.yml');

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();

    expect($posture['ok'])->toBeFalse()
        ->and($posture['exists'])->toBeFalse()
        ->and($posture['issues'])->toContain('the CI workflow is missing');
});

it('collapses a duplicated entry instead of letting it pad the declared set', function () use ($writeWorkflow, $workflowWith) {
    // A repeated entry must not inflate the count, and must not let a genuine
    // gap hide behind a satisfied twin.
    $filter = 'FoundationGovernance|MonitoringLogSource';
    $relative = $writeWorkflow($workflowWith($filter, $filter));

    config()->set('ci_runner.critical_gate_mandatory_suites', [
        'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        './tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotClassifierTest.php',
    ]);

    $posture = app(SelfHostedRunnerScanner::class)->criticalGateSuiteCoveragePosture();
    @unlink(base_path($relative));

    expect($posture['declared'])->toHaveCount(2)
        // The un-duplicated second entry has no matching token, so the gap it
        // represents still surfaces.
        ->and($posture['ok'])->toBeFalse()
        ->and(implode(' ', $posture['issues']))->toContain('PilotPerformanceSnapshotClassifierTest');
});

// ---------------------------------------------------------------------------
// The fix stays bounded
// ---------------------------------------------------------------------------

it('does not promote unrelated suites through the token it adds', function () {
    // A bare `Monitoring` token would have selected 15 further files — matched
    // through the word appearing in unrelated test DESCRIPTIONS, not because
    // they carry a monitoring contract. Critical runtime is already the most
    // expensive required gate, so the token stays narrow enough to select the
    // declared suite and nothing else.
    $scanner = app(SelfHostedRunnerScanner::class);
    $method = (new ReflectionClass($scanner))->getMethod('testIdentity');
    $method->setAccessible(true);

    $token = 'MonitoringLogSource';

    foreach ([
        'tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php',
        'tests/Feature/Foundation/FiveBranchRolloutReadinessServiceTest.php',
        'tests/Feature/Foundation/FoundationMonitoringAccessTest.php',
        'tests/Feature/Sprint43/Sprint43OperationalMonitoringEvidenceReviewPilotHealthCheckTest.php',
    ] as $unrelated) {
        expect(stripos($method->invoke($scanner, $unrelated), $token))
            ->toBeFalse("the added token promotes the unrelated suite {$unrelated}");
    }
});

// ---------------------------------------------------------------------------
// The contract is enforced where a gate can actually see it
// ---------------------------------------------------------------------------

it('turns the runner governance decision red when mandatory coverage breaks', function () {
    // Enforced in two required places: this suite (selected by the `Cicd`
    // token) and the governance report the evidence gates consume. Only the
    // registry is mutated, so the failure is unambiguously this check's.
    config()->set('ci_runner.critical_gate_mandatory_suites', [
        'tests/Unit/Services/Monitoring/NeverSelectedByAnyTokenTest.php',
    ]);

    $report = app(SelfHostedRunnerGovernanceService::class)->collect();

    $check = collect($report['checks'])->firstWhere('check_id', 'CICDCTRL3-CRITICAL-SUITE-COVERAGE');

    expect($check)->not->toBeNull('the suite coverage check is not part of the governance report')
        ->and($check['status'])->toBe('failed')
        ->and($report['critical_suite_coverage_ok'])->toBeFalse()
        ->and($report['decision'])->toBe('FAIL');
});

it('reports the mandatory coverage as satisfied for the real configuration', function () {
    $report = app(SelfHostedRunnerGovernanceService::class)->collect();

    $check = collect($report['checks'])->firstWhere('check_id', 'CICDCTRL3-CRITICAL-SUITE-COVERAGE');

    expect($check)->not->toBeNull()
        ->and($check['status'])->toBe('passed')
        ->and($report['critical_suite_coverage_ok'])->toBeTrue();
});
