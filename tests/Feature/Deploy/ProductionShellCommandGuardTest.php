<?php

use App\Support\Deploy\ProductionShellCommandGuard;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1 — a mechanical guard against
 * running Tinker/PsySH on production.
 *
 * The prohibition has been written down four times (rules 113, 121, 125 and
 * two runbooks) and the command has still been executed against production
 * twice. Prose is not a control. Worse, the repository itself modelled the
 * behaviour: three tracked scripts — one of them the release orchestrator —
 * invoked `artisan tinker`, and two of them did it to ASK whether they were
 * running on production, so the forbidden command ran before the check that
 * would have refused it.
 *
 * PsySH writes real ERROR records into laravel.log, which pins the monitoring
 * log signal to WATCH for 24 hours. The cost is a blinded monitor, not a
 * stylistic preference.
 */
function shellGuard(): ProductionShellCommandGuard
{
    return app(ProductionShellCommandGuard::class);
}

it('rejects every shape of the forbidden invocation', function (string $command) {
    expect(shellGuard()->isForbidden($command))->toBeTrue("Guard did not catch: {$command}");
})->with([
    'php artisan tinker',
    'php artisan tinker --version',
    'php artisan tinker --help',
    'php  artisan   tinker',                       // whitespace is not a bypass
    './artisan tinker --execute="echo 1;"',
    'cd /var/www/app && php artisan tinker',
    'ssh host "php artisan tinker --version"',     // the remote-execution shape
    'vendor/bin/psysh',
    'psysh',
]);

it('does not block legitimate artisan commands', function (string $command) {
    expect(shellGuard()->isForbidden($command))->toBeFalse("Guard false-positived on: {$command}");
})->with([
    'php artisan migrate --force',
    'php artisan db:seed --class=PermissionSeeder --force',
    'php artisan permission:cache-reset',
    'php artisan foundation:security-compliance-check',
    'php artisan android:release-readiness',
    'php artisan satusehat:diagnose --json',
    'php artisan queue:failed',
    // A control that reddens on obedience gets deleted. `tinker` as a prefix of
    // a longer, unrelated command name is not the forbidden command.
    'php artisan tinkerbell:sync',
    'echo "do not run artisan-tinkering by hand"',
]);

it('finds no forbidden invocation in the deploy and release scripts', function () {
    $result = shellGuard()->scan();

    expect($result['findings'])->toBe(
        [],
        'A tracked deploy/release script still invokes the forbidden command.',
    );
    expect($result['status'])->toBe('PASS');

    // Non-vacuity. A guard pointed at an empty file list, or at paths that do
    // not exist, would also report PASS — and would have reported PASS on the
    // very repository that shipped three violations.
    expect($result['files_scanned'])->toBeGreaterThanOrEqual(5);
    expect($result['files_missing'])->toBe([], 'Declared scan target does not exist.');
});

it('fails when a declared scan target does not exist', function () {
    // Mutation M8: dropping the `$missing === []` term from the status was not
    // observable, because every declared script currently exists. Without this
    // test a rename could point the scan at nothing and it would report PASS
    // for having looked at nothing — the precise way this class of control
    // dies quietly.
    $result = shellGuard()->scanPaths([sys_get_temp_dir().'/definitely-not-a-script-'.bin2hex(random_bytes(6))]);

    expect($result['status'])->toBe('FAIL');
    expect($result['files_missing'])->toHaveCount(1);
    expect($result['files_scanned'])->toBe(0);
});

it('detects a forbidden invocation in a script rather than trusting the current tree', function () {
    // The assertion above is only worth anything if the scan can see the
    // violation. Reproduce the exact shape that shipped: a script asking
    // whether it is on production by running the command it must not run.
    // tempArtifactFile(), not tempnam()+'.sh': tempnam CREATES the file it
    // names, so deriving a suffixed path leaves the allocation orphaned and
    // cleans a file that was never the one allocated. The guard scans explicit
    // paths, so the extension is decoration.
    $file = tempArtifactFile('guard-scan-');

    file_put_contents($file, <<<'SH'
    #!/usr/bin/env bash
    set -euo pipefail
    APP_ENV_VALUE="$(php artisan tinker --execute='echo app()->environment();')"
    echo "$APP_ENV_VALUE"
    SH);

    try {
        $findings = shellGuard()->scanPaths([$file])['findings'];

        expect($findings)->toHaveCount(1);
        expect($findings[0]['line'])->toBe(3);
        expect($findings[0]['file'])->toBe($file);
    } finally {
        tempArtifactRemove($file);
    }
});

it('is actually wired into the release path before anything runs on production', function () {
    // An unwired guard is theatre. The release orchestrator reaches production
    // through scripts/deploy-vps-runner.sh; the guard has to run BEFORE that
    // line, or it is a check that fires after the damage.
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('scripts/sprint-release.sh'))) ?: [];

    // Line numbers of EXECUTION, not of first mention. The script names the
    // deploy runner in its header comment on line 6, so a naive strpos()
    // comparison would fail on a correctly ordered script — and, worse, would
    // pass on a broken one whose header happened to be worded differently.
    $lineOf = function (string $needle) use ($lines): ?int {
        foreach ($lines as $index => $line) {
            $code = trim($line);

            if ($code === '' || str_starts_with($code, '#')) {
                continue;
            }

            if (str_contains($code, $needle)) {
                return $index + 1;
            }
        }

        return null;
    };

    $guardAt = $lineOf('deploy:forbidden-command-check');
    $remoteAt = $lineOf('bash scripts/deploy-vps-runner.sh');

    expect($guardAt)->not->toBeNull('sprint-release.sh does not invoke the guard at all.');

    // Mutation M9: prefixing the line with `true ||` left the string exactly
    // where this test was looking while neutering the gate. Presence of the
    // text is not invocation, so assert the shape that actually gates: the
    // guard is the command being RUN, and its failure aborts the release.
    $guardLine = trim($lines[$guardAt - 1]);

    expect($guardLine)->toStartWith(
        'php artisan deploy:forbidden-command-check',
        'The guard is not the command being executed; something short-circuits it.',
    );
    // NOTE: toContain() is variadic — a second argument is another NEEDLE, not
    // a failure message. Passing one here asserted the message text was in the
    // line and failed on a correctly wired script.
    expect(str_contains($guardLine, '|| die'))
        ->toBeTrue('The guard runs but its failure does not abort the release.');
    expect($remoteAt)->not->toBeNull('sprint-release.sh no longer executes the deploy runner.');
    expect($guardAt)->toBeLessThan(
        $remoteAt,
        'The guard runs after the deploy runner — it would fire once the command had already reached production.',
    );
});

it('registers this suite in the critical gate so something selects it', function () {
    // A token match is an accident of naming. This guard is the only mechanical
    // thing standing between a habit that has already reached production twice
    // and production, so the gate names the file rather than hoping a filter
    // catches it.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/Deploy/ProductionShellCommandGuardTest.php');
});

it('leaves no runbook instructing an operator to run the forbidden command', function () {
    // The two production incidents did not come from a script. They came from a
    // human following a documented step. A runbook that tells an operator to
    // run it is the same defect as a script that runs it, and neither the
    // scanner nor the rules were looking at runbooks.
    $guard = shellGuard();
    $offenders = [];

    foreach (glob(base_path('docs/runbooks/*.md')) ?: [] as $runbook) {
        foreach (preg_split('/\R/', (string) file_get_contents($runbook)) ?: [] as $index => $line) {
            // Only lines that INSTRUCT. A prohibition quotes the command in
            // order to forbid it, and reddening on those would delete the
            // documents carrying the rule.
            if (! str_starts_with(trim($line), 'php artisan')) {
                continue;
            }

            // `-- never` is this repository's convention for a row in a
            // do-NOT-do-this table. Quoting the command in order to forbid it
            // is the opposite of instructing it, and reddening on those rows
            // would delete the documents that carry the prohibition. Kept
            // narrow deliberately: the marker, not merely the word "never"
            // appearing somewhere on the line.
            if (preg_match('/--\s*never\b/i', $line) === 1) {
                continue;
            }

            if ($guard->isForbidden($line)) {
                $offenders[] = basename($runbook).':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Runbook still instructs the forbidden command: '.implode(', ', $offenders));
});

it('does not write an operator-supplied secret into its own output', function () {
    // Security review, MEDIUM. `--json` output is designed to be captured into
    // release evidence, and evidence is committed. A command handed to this
    // gate is the operator's text and can carry a credential, so the control
    // that protects production must not be the thing that durably records a
    // secret. The forbidden command still has to be REPORTED — masking is not
    // silence.
    $guard = shellGuard();
    $excerpt = $guard->excerpt('php artisan tinker --password=hunter2SuperSecret');

    expect($guard->isForbidden('php artisan tinker --password=hunter2SuperSecret'))->toBeTrue();
    expect($excerpt)->not->toContain('hunter2SuperSecret');
});

it('refuses to read a scan target larger than the bound instead of loading it', function () {
    // Security review, LOW. An unbounded read on the release path is an OOM
    // waiting to happen in the one gate standing between a forbidden command
    // and production. Over the bound the file is REPORTED, never silently
    // skipped — skipping would be a fail-open.
    $file = tempArtifactFile('guard-oversize-');

    try {
        $limit = (int) config('android_release.scanner.max_scanned_file_bytes', 1048576);
        file_put_contents($file, str_repeat('x', $limit + 1024));

        $result = shellGuard()->scanPaths([$file]);

        expect($result['status'])->toBe('FAIL');
        expect($result['findings'][0]['pattern'])->toBe('unreadable');
    } finally {
        tempArtifactRemove($file);
    }
});
