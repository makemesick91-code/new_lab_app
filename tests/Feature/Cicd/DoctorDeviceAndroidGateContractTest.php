<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'DoctorDevice', 'Android');

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — Android CI gate contract.
 *
 * The Android job runs `./gradlew` from a fresh clone, so the wrapper's execute
 * bit has to live in the git index. It is not enough for the file to be
 * executable on a developer's disk: git stores only 100644 or 100755, and a
 * wrapper committed as 100644 checks out non-executable on the runner and the
 * gate dies with exit 126 before a single test runs.
 *
 * That is exactly how this failed, so the index mode is asserted directly
 * rather than the working tree — reading the working tree would pass on the
 * machine where the file happens to be chmod'ed and prove nothing.
 */
function gitIndexMode(string $relativePath): string
{
    $result = Process::path(base_path())->run(['git', 'ls-files', '-s', '--', $relativePath]);

    expect($result->successful())->toBeTrue('git ls-files failed for '.$relativePath);

    $output = trim($result->output());

    expect($output)->not->toBe('', $relativePath.' is not tracked by git.');

    return explode(' ', $output)[0];
}

it('commits the gradle wrapper with the execute bit so a fresh CI clone can run it', function () {
    expect(gitIndexMode('android/daengtisia-clinic/gradlew'))
        ->toBe('100755', 'gradlew must be committed executable, or the Android gate exits 126.');
});

it('keeps the android gate invoking the wrapper it just proved executable', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));

    $job = $workflow['jobs']['android_clinic_app_gate'] ?? null;

    expect($job)->not->toBeNull('The Phase 3 Android gate job is missing.');

    $gradleSteps = collect($job['steps'] ?? [])
        ->filter(fn (array $step): bool => str_contains($step['run'] ?? '', './gradlew'));

    // If the gate ever stops calling ./gradlew, the execute-bit assertion above
    // silently stops protecting anything.
    expect($gradleSteps)->not->toBeEmpty('The Android gate no longer invokes ./gradlew.');

    // The wrapper is only reachable from the module directory, so every step
    // that calls it must run there.
    $gradleSteps->each(function (array $step): void {
        expect($step['working-directory'] ?? null)->toBe('android/daengtisia-clinic');
    });
});
