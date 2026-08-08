<?php

use Illuminate\Support\Facades\Process;

uses()->group('Cicd', 'Ci', 'SelfHostedRunner', 'FoundationGovernance');

/*
 * CICD-CTRL-3C — runtime detection must be deterministic.
 *
 * The self-hosted critical gate failed on the dedicated runner with
 * "host PHP is missing required extension(s): gd" while the health check in the
 * same job reported all extensions present, and while gd was demonstrably
 * installed on the host.
 *
 * The cause is a race, not a missing package. The checks were written as
 * `php -m | grep -qix "$ext"` under `set -o pipefail`. `grep -q` exits at the
 * first match, the producer then dies of SIGPIPE, and pipefail reports the
 * pipeline as failed — even though the match succeeded. Whether it triggers
 * depends on how quickly grep finds the line, so extensions early in the module
 * list are the ones that flap. Measured on the CI host, gd (14th of 53) was
 * falsely reported missing in 1 of 40 trials.
 *
 * The failure direction differs by site and one of them failed OPEN: the
 * forbidden-group check treated a spurious pipeline failure as "user is not in
 * the group", which is the wrong answer for a root-equivalence test.
 */

/** Run a shell snippet under the same options the CI scripts use. */
function raceShell(string $script): array
{
    $result = Process::run(['bash', '--noprofile', '--norc', '-c', "set -uo pipefail\n".$script]);

    return [$result->exitCode(), $result->output().$result->errorOutput()];
}

/** CI shell scripts whose detection logic must be race-free. */
function raceScannedScripts(): array
{
    return [
        'scripts/ci/self-hosted-php.sh',
        'scripts/ci/self-hosted-runner-health.sh',
        'scripts/ci/foundation-evidence-gates.sh',
    ];
}

// ---------------------------------------------------------------------------
// The mechanism, reproduced deterministically
// ---------------------------------------------------------------------------

it('pins that an early-exit pipeline can report failure on a successful match', function () {
    // A producer that keeps writing after the match makes the SIGPIPE certain
    // rather than probabilistic, so this reproduces the defect every time.
    [$code, $out] = raceShell(
        '{ echo gd; sleep 0.2; echo trailing; } | grep -qix gd; echo "pipeline_status=$?"'
    );

    expect($code)->toBe(0)
        ->and($out)->not->toContain('pipeline_status=0');
});

it('shows the here-string form reports success for the same match', function () {
    [$code, $out] = raceShell(
        'modules="$(printf "gd\ntrailing\n")"; grep -qix gd <<<"$modules"; echo "herestring_status=$?"'
    );

    expect($code)->toBe(0)
        ->and($out)->toContain('herestring_status=0');
});

// ---------------------------------------------------------------------------
// The shipped scripts use the safe form
// ---------------------------------------------------------------------------

it('never pipes into an early-exit grep in a CI detection script', function (string $script) {
    $source = file_get_contents(base_path($script));

    // Strip comments so the explanation of the defect does not read as the
    // defect. Only executable lines are scanned.
    $code = preg_replace('/^\s*#.*$/m', '', (string) $source);

    expect(preg_match('/\|\s*grep\s+-[a-zA-Z]*q/', (string) $code))
        ->toBe(0, "{$script} still pipes into an early-exit grep under pipefail");
})->with(raceScannedScripts());

it('captures the PHP module list once instead of per extension', function () {
    $wrapper = file_get_contents(base_path('scripts/ci/self-hosted-php.sh'));
    $health = file_get_contents(base_path('scripts/ci/self-hosted-runner-health.sh'));

    foreach ([$wrapper, $health] as $source) {
        $code = preg_replace('/^\s*#.*$/m', '', (string) $source);

        expect($code)->toContain('php -m')
            ->and(preg_match('/php -m[^\n]*\|\s*grep/', (string) $code))->toBe(0);
    }
});

// ---------------------------------------------------------------------------
// Resolution is stable when repeated
// ---------------------------------------------------------------------------

it('resolves the same runtime on every consecutive invocation', function () {
    // The gate calls the wrapper several times per job; a detection race shows
    // up as the resolved mode changing between otherwise identical calls.
    $localPhp = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

    $modes = [];
    for ($i = 0; $i < 12; $i++) {
        $result = Process::path(base_path())
            ->env(['CI_RUNNER_PHP_VERSION' => $localPhp, 'CI_RUNTIME_MODE' => 'auto'])
            ->run(['bash', 'scripts/ci/self-hosted-php.sh', '--print-runtime']);

        preg_match('/runtime_mode=(\S+)/', $result->output(), $m);
        $modes[] = $m[1] ?? 'unparsed';
    }

    expect(array_unique($modes))->toHaveCount(1, 'resolved runtime flapped across identical calls: '.implode(',', $modes));
});

it('reports every required extension consistently across repeated checks', function () {
    // Directly exercises the loop that flapped, against this machine's own PHP.
    $script = <<<'SH'
        set -uo pipefail
        modules="$(php -m)"
        for i in $(seq 1 25); do
            missing=""
            for extension in dom curl libxml mbstring zip pcntl pdo bcmath; do
                grep -qix "$extension" <<<"$modules" || missing="${missing} ${extension}"
            done
            echo "run=${i} missing=${missing}"
        done
        SH;

    [$code, $out] = raceShell($script);

    $lines = array_filter(explode("\n", trim($out)), fn ($l) => str_starts_with($l, 'run='));
    $results = array_unique(array_map(fn ($l) => preg_replace('/^run=\d+ /', '', $l), $lines));

    expect($code)->toBe(0)
        ->and($results)->toHaveCount(1, 'extension detection was not stable: '.implode(' | ', $results));
});
