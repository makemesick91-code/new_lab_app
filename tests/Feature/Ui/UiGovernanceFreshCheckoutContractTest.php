<?php

/**
 * CICD-FIX-3 — UI governance fresh-checkout & build-artifact contract.
 *
 * The UI governance decision is a statement about the TRACKED SOURCE TREE, so
 * it must be reproducible in any clean checkout. `public/build` is generated,
 * gitignored output that no CI test gate builds, so "the frontend was not built
 * in this environment" must never by itself turn the decision non-GO — while a
 * genuine missing-UI-evidence defect must still be caught.
 *
 * These tests pin BOTH directions so the check can never degrade into a
 * command that reports GO for every possible state.
 */

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'Uix', 'UiGovernanceContract');

/**
 * Run the governance check with the built frontend output relocated, so the
 * assertion holds identically on a developer machine that has run
 * `npm run build` and in a clean CI checkout that never will.
 */
function uiGovernanceWithoutBuiltAssets(array $options): array
{
    $buildDir = public_path('build');
    $stashed = $buildDir.'.cicd-fix-3-stashed';

    $relocated = is_dir($buildDir) && rename($buildDir, $stashed);

    try {
        $exit = Artisan::call('architecture:ui-governance-check', $options + ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($relocated) {
            rename($stashed, $buildDir);
        }
    }

    return [$exit, $report];
}

// ---------------------------------------------------------------------------
// Direction 1 — a clean checkout with no build output is still GO.
// ---------------------------------------------------------------------------

it('reports GO under strict in a fresh checkout where the frontend was never built', function () {
    [$exit, $report] = uiGovernanceWithoutBuiltAssets(['--strict' => true]);

    expect($exit)->toBe(0);
    expect($report['decision'])->toBe('GO');
    expect($report['build_manifest_present'])->toBeFalse();

    // The signal is reported, not suppressed — it is just classified as an
    // environment observation rather than a source-tree governance warning.
    expect($report['warnings'])->toBe([]);
    expect($report['advisories'])->not->toBe([]);
    expect(implode("\n", $report['advisories']))->toContain('public/build/manifest.json');
});

it('never lets the environment advisory leak into the rule channels', function () {
    [, $report] = uiGovernanceWithoutBuiltAssets(['--strict' => true]);

    // Assert unconditionally on the joined channels so this holds even when the
    // rule channels are empty (the expected clean-tree case).
    $ruleChannels = implode("\n", [...$report['errors'], ...$report['warnings']]);

    expect($ruleChannels)->not->toContain('public/build/manifest.json');
    expect($ruleChannels)->not->toContain('npm run build');
});

// ---------------------------------------------------------------------------
// Direction 2 — the build-manifest signal is still enforceable on demand.
// ---------------------------------------------------------------------------

it('still fails strict when a context explicitly requires the build manifest', function () {
    [$exit, $report] = uiGovernanceWithoutBuiltAssets([
        '--strict' => true,
        '--require-build-manifest' => true,
    ]);

    expect($exit)->toBe(1);
    expect($report['decision'])->toBe('WATCH');
    expect($report['advisories'])->toBe([]);
    expect(implode("\n", $report['warnings']))->toContain('public/build/manifest.json');
});

it('keeps a required-but-missing build manifest a WATCH, never a silent pass', function () {
    // Without --strict the same state is still surfaced as WATCH; only the exit
    // code softens, exactly as the documented decision → exit contract says.
    [$exit, $report] = uiGovernanceWithoutBuiltAssets(['--require-build-manifest' => true]);

    expect($exit)->toBe(0);
    expect($report['decision'])->toBe('WATCH');
});

// ---------------------------------------------------------------------------
// Direction 3 — genuine missing UI evidence is still a hard failure.
// ---------------------------------------------------------------------------

it('still FAILs when a required UI foundation artifact is genuinely missing', function () {
    // Prove the decision machinery is intact: hiding a real required component
    // must produce a hard FAIL, so "always GO" can never be the outcome.
    $component = resource_path('views/components/ui/button.blade.php');
    $stashed = $component.'.cicd-fix-3-stashed';

    expect(is_file($component))->toBeTrue();
    rename($component, $stashed);

    try {
        $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        rename($stashed, $component);
    }

    // FAIL is non-zero even without --strict.
    expect($exit)->toBe(1);
    expect($report['decision'])->toBe('FAIL');
    expect(implode("\n", $report['errors']))->toContain('button');

    expect(is_file($component))->toBeTrue();
});
