<?php

/*
 * CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1 — the Critical Gate warning
 * contract.
 *
 * The NSF-R011 Critical Test Gate concluded `success` while reporting
 * `Tests: 2222 warnings, 9 passed` — 128 of its 129 test files marked WARN.
 * The gate was truthful about failures, but it had no opinion whatsoever about
 * warnings, so 99.6% of its own output was noise and a genuinely new warning
 * was indistinguishable from the baseline.
 *
 * ROOT CAUSE, evidenced rather than assumed. Every warning was one PHP warning
 * raised at vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73, reading the
 * application environment file at the repository root, reached through
 * Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables. That file is
 * OPTIONAL by framework design (`safeLoad`) and is deliberately never
 * committed, so CI configures the application through each job's `env:` block
 * — but the framework still resolves the path and reads it on EVERY
 * application boot. Hence 128 of 129: every test that boots the application
 * warned, and the single file that does not boot it (a pure unit test with no
 * `uses(TestCase::class)`) passed.
 *
 * An earlier explanation blamed an absent frontend build manifest. That was
 * retracted and is NOT the cause: tests/TestCase.php calls `withoutVite()`,
 * the Critical Gate never builds the frontend, and no manifest path appears in
 * any warning. This file pins the evidenced cause so the retracted one cannot
 * return.
 *
 * THE FIX IS AT THE CAUSAL BOUNDARY, NOT ON THE OUTPUT. CI now writes an EMPTY
 * environment file, which is the accurate representation of the state it is
 * already in: the read succeeds and yields zero variables, resolving
 * byte-for-byte the same configuration. Nothing is suppressed — no `@`, no
 * stderr filtering, no warning-text allowlist, no PHPUnit suppression toggle.
 *
 * Two properties are pinned here because prose cannot hold them:
 *
 *   1. The contract fails CLOSED. Missing, unreadable, failed-read, empty and
 *      summary-less evidence are five distinct states and NONE of them is a
 *      pass. A read failure is never folded into "valid empty content".
 *
 *   2. Real failure detection is preserved. The contract never reports a run
 *      containing failures as clean, and the workflow exits on the test status
 *      BEFORE consulting the contract, so it can never turn a red gate green.
 */

use App\Support\Cicd\CriticalGateWarningContract;
use Illuminate\Support\Facades\Artisan;

/** Absolute path to the authoritative workflow. */
function cgwcWorkflowPath(): string
{
    return base_path('.github/workflows/foundation-evidence-gates.yml');
}

function cgwcWorkflow(): string
{
    return (string) file_get_contents(cgwcWorkflowPath());
}

/** Write a temporary evidence log and return its path. */
function cgwcLog(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'cgwc');
    file_put_contents($path, $contents);

    return $path;
}

/* ------------------------------------------------------------------ *
 * 1. Resource-state discipline — the defect class this sprint closes. *
 * ------------------------------------------------------------------ */

it('reports an absent evidence log as missing and fails closed', function () {
    $contract = new CriticalGateWarningContract;
    $path = sys_get_temp_dir().'/cgwc-does-not-exist-'.uniqid().'.log';

    $result = $contract->evaluate($path);

    expect($result['log_state'])->toBe(CriticalGateWarningContract::LOG_MISSING)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO)
        ->and($result['reasons'])->not->toBeEmpty();
});

it('reports an unreadable evidence log distinctly from an absent one', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Running as root: file permissions cannot make a file unreadable.');
    }

    $path = cgwcLog('Tests: 10 passed (10 assertions)');
    chmod($path, 0000);

    $result = (new CriticalGateWarningContract)->evaluate($path);

    chmod($path, 0644);
    @unlink($path);

    expect($result['log_state'])->toBe(CriticalGateWarningContract::LOG_UNREADABLE)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

it('never collapses a legitimately empty log into a clean run', function () {
    $path = cgwcLog('');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    // Empty is its own state, and it is NOT "zero warnings, therefore GO".
    expect($result['log_state'])->toBe(CriticalGateWarningContract::LOG_EMPTY)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO)
        ->and($result['observed_warning_count'])->toBeNull();
});

it('keeps missing, unreadable and empty as three distinct states', function () {
    $contract = new CriticalGateWarningContract;

    $missing = $contract->readLog(sys_get_temp_dir().'/cgwc-nope-'.uniqid());
    $emptyPath = cgwcLog('');
    $empty = $contract->readLog($emptyPath);
    $fullPath = cgwcLog('Tests: 1 passed');
    $full = $contract->readLog($fullPath);
    @unlink($emptyPath);
    @unlink($fullPath);

    expect($missing['state'])->toBe(CriticalGateWarningContract::LOG_MISSING)
        ->and($missing['contents'])->toBeNull()
        ->and($empty['state'])->toBe(CriticalGateWarningContract::LOG_EMPTY)
        ->and($empty['contents'])->toBe('')
        ->and($full['state'])->toBe(CriticalGateWarningContract::LOG_READ)
        ->and($full['contents'])->not->toBe('');
});

it('fails closed when the log carries no test summary at all', function () {
    $path = cgwcLog("PHP Fatal error: something exploded\nno summary here\n");

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['summary_found'])->toBeFalse()
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

/* --------------------------------------------- *
 * 2. The warning contract itself.               *
 * --------------------------------------------- */

it('reproduces the observed baseline as unexplained warnings', function () {
    // The exact summary line the Critical Gate emitted at the sprint baseline.
    $path = cgwcLog('  Tests:    2222 warnings, 9 passed (9328 assertions)');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['observed_warning_count'])->toBe(2222)
        ->and($result['expected_warning_count'])->toBe(0)
        ->and($result['unexplained_warning_count'])->toBe(2222)
        ->and($result['tests_reported'])->toBe(2231)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

it('accepts a run with no warnings at all', function () {
    $path = cgwcLog('  Tests:    2231 passed (9328 assertions)');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['observed_warning_count'])->toBe(0)
        ->and($result['unexplained_warning_count'])->toBe(0)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_GO);
});

it('reads the LAST summary line when the log repeats it', function () {
    // The workflow echoes the summary back into the step summary, so the log
    // legitimately contains it more than once. Only the final one is the run.
    $path = cgwcLog(implode("\n", [
        '  Tests:    2222 warnings, 9 passed (9328 assertions)',
        'some intervening output',
        '  Tests:    2231 passed (9328 assertions)',
    ]));

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['observed_warning_count'])->toBe(0)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_GO);
});

it('never counts the assertion total as a test outcome', function () {
    $path = cgwcLog('  Tests:    3 passed (9328 assertions)');

    $result = (new CriticalGateWarningContract)->parseSummary((string) file_get_contents($path));
    @unlink($path);

    expect($result['counts'])->toBe(['passed' => 3])
        ->and($result['total'])->toBe(3);
});

/* ------------------------------------------------------------------ *
 * 3. Real failure detection is preserved — the anti-false-green half. *
 * ------------------------------------------------------------------ */

it('never reports a run containing failures as clean', function () {
    $path = cgwcLog('  Tests:    2 failed, 2229 passed (9328 assertions)');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['observed_failure_count'])->toBe(2)
        ->and($result['observed_warning_count'])->toBe(0)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

it('fails closed when the gate reports zero tests', function () {
    $path = cgwcLog('  Tests:    0 passed (0 assertions)');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['tests_reported'])->toBe(0)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

it('surfaces a single new warning above a clean baseline', function () {
    // The property the 2222-warning baseline destroyed: one new warning must
    // be visible. This is the sprint's primary risk question, pinned.
    $path = cgwcLog('  Tests:    1 warnings, 2230 passed (9328 assertions)');

    $result = (new CriticalGateWarningContract)->evaluate($path);
    @unlink($path);

    expect($result['unexplained_warning_count'])->toBe(1)
        ->and($result['decision'])->toBe(CriticalGateWarningContract::DECISION_NO_GO);
});

/* --------------------------------------------- *
 * 4. The command's exit contract.                *
 * --------------------------------------------- */

it('exits zero only for a clean run and non-zero otherwise', function () {
    $clean = cgwcLog('  Tests:    2231 passed (9328 assertions)');
    $dirty = cgwcLog('  Tests:    2222 warnings, 9 passed (9328 assertions)');

    $cleanStatus = Artisan::call('ci:assert-critical-gate-warning-contract', ['--log' => $clean]);
    $dirtyStatus = Artisan::call('ci:assert-critical-gate-warning-contract', ['--log' => $dirty]);
    $missingStatus = Artisan::call('ci:assert-critical-gate-warning-contract', [
        '--log' => sys_get_temp_dir().'/cgwc-absent-'.uniqid(),
    ]);

    @unlink($clean);
    @unlink($dirty);

    expect($cleanStatus)->toBe(0)
        ->and($dirtyStatus)->not->toBe(0)
        ->and($missingStatus)->not->toBe(0);
});

it('emits machine-readable json without leaking a credential', function () {
    $path = cgwcLog('  Tests:    2231 passed (9328 assertions)');

    Artisan::call('ci:assert-critical-gate-warning-contract', ['--log' => $path, '--json' => true]);
    $output = Artisan::output();
    @unlink($path);

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['decision'])->toBe(CriticalGateWarningContract::DECISION_GO)
        ->and($output)->not->toContain('PGPASSWORD')
        ->and($output)->not->toContain('DB_PASSWORD');
});

/* --------------------------------------------- *
 * 5. Workflow posture.                           *
 * --------------------------------------------- */

it('provisions the environment file in every job that boots the application', function () {
    $workflow = cgwcWorkflow();
    $config = (array) config('ci_runner.critical_gate_warning_contract');
    $step = (string) $config['environment_provisioning_step'];
    $jobs = (array) $config['environment_provisioning_jobs'];

    // One provisioning step per declared job — no job that boots the
    // application may be left resolving a path to nothing.
    expect(substr_count($workflow, 'name: '.$step))->toBe(count($jobs));

    foreach ($jobs as $job) {
        expect($workflow)->toContain($job.':');
    }
});

it('provisions the environment file before running tests, within every job', function () {
    $workflow = cgwcWorkflow();
    $config = (array) config('ci_runner.critical_gate_warning_contract');
    $step = (string) $config['environment_provisioning_step'];

    /*
     * Ordering must be asserted PER JOB, not across the file. Jobs are
     * independent checkouts, so a provisioning step in a later job says
     * nothing about an earlier one — a whole-file position comparison would
     * pass while a job still ran its suite unprovisioned.
     */
    $blocks = preg_split('/^  (?=[a-z_0-9]+:$)/m', $workflow) ?: [];
    $checked = 0;

    foreach ($blocks as $block) {
        if (! str_contains($block, 'artisan test')) {
            continue;
        }

        $provision = strpos($block, 'name: '.$step);
        $firstRun = strpos($block, 'artisan test');

        expect($provision)->not->toBeFalse()
            ->and($provision)->toBeLessThan($firstRun);

        $checked++;
    }

    // Guard the guard: a regex that matched nothing would vacuously pass.
    expect($checked)->toBeGreaterThanOrEqual(4);
});

it('asserts the warning contract in both critical gate variants', function () {
    $workflow = cgwcWorkflow();
    $command = (string) config('ci_runner.critical_gate_warning_contract.command');

    // Both variants share the required check name, so coverage must not depend
    // on which runner the job is routed to.
    expect(substr_count($workflow, $command))->toBe(2);
});

it('gives the test exit status strict precedence over the warning contract', function () {
    $workflow = cgwcWorkflow();

    // A failing suite must exit before the contract is consulted, in BOTH
    // variants, or a warning verdict could overwrite a red gate.
    expect(substr_count($workflow, 'if [ "$TEST_STATUS" -ne 0 ]; then'))->toBe(2)
        ->and(substr_count($workflow, 'exit "$CONTRACT_STATUS"'))->toBe(2);
});

it('declares a zero expected warning baseline with no text allowlist', function () {
    $config = (array) config('ci_runner.critical_gate_warning_contract');

    expect($config['expected_warning_count'])->toBe(0)
        // An expected condition is represented at its causal boundary. A
        // warning-text allowlist would let a still-emitting warning be waved
        // through, which is the failure mode this sprint exists to prevent.
        ->and($config)->not->toHaveKey('allowed_warning_patterns')
        ->and($config)->not->toHaveKey('ignored_warning_messages');
});

/* --------------------------------------------- *
 * 6. No broad suppression anywhere in CI.        *
 * --------------------------------------------- */

it('never suppresses warnings instead of fixing their cause', function () {
    $config = (array) config('ci_runner.critical_gate_warning_contract');
    $markers = (array) $config['forbidden_suppression_markers'];

    $files = array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('scripts/ci/*.sh')) ?: [],
        [base_path('phpunit.xml')],
    );

    // This file has to quote the forbidden markers in order to assert them, so
    // it must never scan itself — a self-scanning guard reports its own text.
    $self = str_replace('\\', '/', __FILE__);

    foreach ($files as $file) {
        if (! is_file($file) || str_replace('\\', '/', $file) === $self) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        foreach ($markers as $marker) {
            /*
             * PHPUnit's assertion is used deliberately instead of
             * `expect()->not->toContain()`: that matcher is VARIADIC, so a
             * message passed as a second argument silently becomes a second
             * needle and the explanation never reaches the failure output.
             */
            $this->assertStringNotContainsString($marker, $contents, sprintf(
                '%s must not suppress warnings via "%s"; fix the cause instead.',
                basename($file),
                $marker
            ));
        }
    }
});

it('does not let the suppressed-read pattern spread through application code', function () {
    /*
     * The Critical Gate warning came from a third-party package that already
     * uses the suppression operator correctly, so the remedy was to provision
     * the resource — never to copy that shape into application code.
     *
     * Three application files predate this sprint and were audited here rather
     * than rewritten, because none of them contributes to the Critical Gate
     * baseline (an empty environment file alone takes it to zero) and two of
     * them are already correct:
     *
     *   - PilotPerformanceSnapshotService::readMemInfo() — exemplary: an
     *     is_readable() guard, then an explicit `=== false` check. A read
     *     failure is never folded into empty content.
     *   - RestoreDrillEvidenceService — casts a failed read to a string, so an
     *     unreadable evidence file reports "invalid JSON" rather than
     *     "unreadable". It still FAILS CLOSED (verified: the cast yields '',
     *     json_decode returns null, and the `! is_array` branch calls fail()),
     *     so the decision is correct and only the reason is less specific.
     *   - ArchitectureUiGovernanceCheckCommand — 74 presence probes over
     *     optional design-system files, where "unreadable" and "absent" are
     *     deliberately the same outcome.
     *
     * This is an EXACT-SET assertion, not an allowlist with headroom: a new
     * file adopting the pattern fails, and so does cleaning one up without
     * updating this inventory. It can never quietly absorb a new offender.
     */
    $audited = [
        'app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php',
        'app/Services/Foundation/RestoreDrillEvidenceService.php',
        'app/Services/Monitoring/PilotPerformanceSnapshotService.php',
    ];

    $appFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $found = [];

    foreach ($appFiles as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), '@file_get_contents')) {
            $found[] = str_replace(base_path().'/', '', str_replace('\\', '/', $file->getPathname()));
        }
    }

    sort($found);

    expect($found)->toBe($audited);
});
