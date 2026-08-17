<?php

/*
 * CICD-BASELINE-REVERIFY-1 — the Full Suite baseline contract.
 *
 * The repository carried a documented residual of "9 pre-existing Full Suite
 * failures" from CICD-CTRL-3. Those nine were real, were catalogued, and were
 * closed by CICD-FIX-6 (`fe36f06`): run 31293873172 reported `9 failed` and the
 * very next full suite, run 31335720157, reported none. The current expected
 * Full Suite failure baseline is therefore ZERO.
 *
 * A retired baseline is only safe if two properties hold, and both are pinned
 * here rather than left to prose:
 *
 *   1. No expected-failure allowance survives anywhere in the CI or governance
 *      surface. The nine were only ever an evidence note — they were never
 *      encoded as a machine-readable allowlist — and that must stay true, or a
 *      future red suite could be subtracted down to green.
 *
 *   2. The suite is deterministic. A baseline of zero is worthless if the suite
 *      reddens at random, because the first false red teaches everyone to
 *      ignore it. The one proven source of non-determinism found during this
 *      revalidation was an assertion comparing a faker-generated name against a
 *      Blade-escaped response body, so the escaping contract is pinned too.
 *
 * The exit-status half of the contract — that a Pest failure actually reddens
 * the gate instead of being swallowed by `| tee` — is already pinned by
 * NsfReleaseGateExitPropagationTest and is deliberately not duplicated here.
 */

use Illuminate\Support\Facades\File;

/**
 * Every PHP test file in the suite, as path => contents.
 *
 * This file is excluded from its own scan. It has to quote the offending
 * patterns verbatim in order to explain and match them, which would otherwise
 * make the guard report itself — the same self-scan trap that forced the
 * deployment scanners to keep their literals in config rather than in source.
 *
 * @return array<string, string>
 */
function baselineTestSources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $sources = [];
    $self = str_replace('\\', '/', __FILE__);

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_replace('\\', '/', $file->getPathname()) === $self) {
            continue;
        }

        $sources[$file->getRelativePathname()] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

it('encodes no expected-failure allowance in the CI or governance surface', function () {
    /*
     * Tokens that would mean "this many failures are acceptable". Searching for
     * the mechanism rather than the number keeps the guard useful after the
     * count changes: a future baseline of two would be just as unsafe to encode
     * as the historical nine.
     */
    $forbidden = [
        'expected_failures',
        'expected-failures',
        'failure_baseline',
        'failure-baseline',
        'baseline_failures',
        'allowed_failures',
        'allowed-failures',
        'known_failures',
        'accepted_failures',
    ];

    $surface = [
        '.github/workflows/foundation-evidence-gates.yml',
        'scripts/ci/resolve-gates.sh',
        'config/ci_runner.php',
        'config/ci_runtime_control.php',
    ];

    foreach ($surface as $relative) {
        $path = base_path($relative);

        if (! File::exists($path)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        foreach ($forbidden as $token) {
            expect(str_contains($contents, $token))->toBeFalse(
                "{$relative} declares '{$token}' — the Full Suite baseline is zero and must not carry a failure allowance."
            );
        }
    }
});

it('leaves no raw response-body assertion against a dynamic value', function () {
    /*
     * `expect($response->content())->toContain($var)` compares against the raw
     * rendered HTML, so any value Blade escapes will not match. Laravel's
     * `assertSee()` escapes the expected value by default and is the correct
     * tool. This is the exact shape that reddened run 31928614428.
     */
    $offenders = [];

    foreach (baselineTestSources() as $relative => $contents) {
        if (preg_match('/(?:getContent|content)\(\)\)?->toContain\(\s*\$/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'These tests assert a dynamic value against the raw response body; use assertSee(), which escapes like Blade does.'
    );
});

it('escapes the dynamic half of every unescaped assertSee', function () {
    /*
     * `assertSee($value, false)` switches escaping off. That is legitimate when
     * the assertion targets raw markup — `value="…"`, a URL, an id — but the
     * dynamic value interpolated into it still has to be escaped by hand with
     * `e()`, because the view escaped it on the way out.
     *
     * Only property reads are flagged. Formatting helpers and casts produce
     * digits and are not escapable.
     */
    $offenders = [];

    foreach (baselineTestSources() as $relative => $contents) {
        preg_match_all('/assertSee\((.*?),\s*false\s*\)/s', $contents, $matches);

        foreach ($matches[1] as $argument) {
            // A property read such as ->name / ->description that is not wrapped in e().
            $readsProperty = preg_match('/\$\w+(?:->\w+)*->(?:name|description|title|address|notes)\b/', $argument) === 1;

            if ($readsProperty && ! str_contains($argument, 'e(')) {
                $offenders[] = $relative.': '.trim($argument);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Escaping is off for these assertions but the interpolated value is not passed through e().'
    );
});

it('matches an escaped name the way the view renders it', function () {
    /*
     * The behavioural half of the contract. A name carrying an HTML-special
     * character must be found by the escaping-aware assertion and must NOT be
     * present verbatim in the body — which is precisely why the raw comparison
     * was unreliable.
     */
    $name = "Oswaldo O'Kon & Sons";

    $rendered = (string) app('blade.compiler')->render('<span>{{ $value }}</span>', ['value' => $name]);

    expect($rendered)->not->toContain($name)
        ->and($rendered)->toContain(e($name))
        ->and(e($name))->toContain('&#039;')
        ->and(e($name))->toContain('&amp;');
});
