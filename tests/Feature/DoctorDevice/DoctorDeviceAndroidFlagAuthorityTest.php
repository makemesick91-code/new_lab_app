<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (CC1-CG08) — the Android release governance
| scanner reads the enforcement flag only through FeatureFlagService (CG-08)
|--------------------------------------------------------------------------
|
| `enforcement_off` used to read `config('feature_flags.flags…')` directly and
| re-implement the dotted-key workaround FeatureFlagService already owns. It now
| asks the one flag authority; the outcome is unchanged for every registry that
| passes the registry's own governance check. The summary also reports the
| phase the scanner APPLIES (an unrecognised value resolves to phase_4a), with
| the declared value beside it.
|
| Selected by the existing `DoctorDevice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `ddfa` is unique because the critical gate loads every suite into one process.
*/

use App\Support\Android\AndroidReleaseGovernanceScanner;

require_once __DIR__.'/helpers.php';

/** Rewrite one flag in the registry. Flag keys contain dots, so the whole array is replaced. */
function ddfaSetFlag(string $key, ?array $definition): void
{
    $flags = (array) config('feature_flags.flags', []);

    if ($definition === null) {
        unset($flags[$key]);
    } else {
        $flags[$key] = $definition;
    }

    config()->set('feature_flags.flags', $flags);
}

function ddfaFlagKey(): string
{
    return (string) config('android_release.enforcement.flag_key');
}

it('FA-01 reads no feature flag straight from config (CG-08)', function () {
    $source = (string) file_get_contents((new ReflectionClass(AndroidReleaseGovernanceScanner::class))->getFileName());

    expect($source)->not->toContain("config('feature_flags")
        ->and($source)->toContain('FeatureFlagService::class');
});

it('FA-02 still passes enforcement_off with the registry as shipped', function () {
    expect(ddfaFlagKey())->not->toBe('')
        ->and(androidCheck('enforcement_off')['status'])->toBe('PASS');
});

it('FA-03 fails enforcement_off when the registry default is switched on', function () {
    $definition = (array) config('feature_flags.flags', [])[ddfaFlagKey()];
    ddfaSetFlag(ddfaFlagKey(), ['default' => true] + $definition);

    expect(androidCheck('enforcement_off')['status'])->toBe('FAIL');
});

it('FA-04 fails enforcement_off as "not declared" when the flag is missing from the registry', function () {
    ddfaSetFlag(ddfaFlagKey(), null);

    $check = androidCheck('enforcement_off');

    expect($check['status'])->toBe('FAIL')
        ->and(json_encode($check))->toContain('is not declared in the feature flag registry');
});

it('FA-05 reports the phase it applies, and the declared value beside it', function () {
    expect(androidScanner()->scan()['summary']['governance_phase'])->toBe('phase_4a');

    config()->set('android_release.enforcement.governance_phase', 'phase_4a_typo');
    $summary = androidScanner()->scan()['summary'];

    expect($summary['governance_phase'])->toBe('phase_4a')
        ->and($summary['governance_phase_declared'])->toBe('phase_4a_typo');
});
