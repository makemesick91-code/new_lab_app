<?php

use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci');

/**
 * CICD-COMPOSER-CACHE-RELEASE-GATES-1 — every GitHub-hosted job that installs
 * Composer dependencies restores the Composer download cache first.
 *
 * Three hosted jobs already did; NSF-9, NSF-10 and the Full Suite did not and
 * downloaded every package from Packagist on every run (8-9 s against 3 s, and a
 * hard dependency on Packagist being reachable). The cache holds downloaded
 * archives only: `composer install` still resolves from composer.lock, so the
 * installed versions cannot change. The self-hosted runner keeps its own
 * persistent cache and deliberately uses no actions/cache.
 */
function ccWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

/** @return list<array{0: string, 1: array}> jobs that run `composer install` directly */
function ccComposerJobs(): array
{
    $jobs = [];
    foreach (ccWorkflow()['jobs'] as $name => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (str_contains((string) ($step['run'] ?? ''), 'composer install')) {
                $jobs[] = [$name, $job];
                break;
            }
        }
    }

    return $jobs;
}

function ccIsSelfHosted(array $job): bool
{
    return in_array('self-hosted', (array) ($job['runs-on'] ?? []), true);
}

it('restores the composer cache before every hosted composer install', function () {
    $hosted = array_filter(ccComposerJobs(), fn (array $j) => ! ccIsSelfHosted($j[1]));

    expect(count($hosted))->toBeGreaterThanOrEqual(6, 'Expected the six hosted jobs that install Composer dependencies.');

    foreach ($hosted as [$name, $job]) {
        $steps = $job['steps'];
        $install = null;
        foreach ($steps as $i => $step) {
            if (str_contains((string) ($step['run'] ?? ''), 'composer install')) {
                $install = $i;
                break;
            }
        }

        $before = array_slice($steps, 0, $install);
        $cache = array_values(array_filter($before, fn (array $s) => str_starts_with((string) ($s['uses'] ?? ''), 'actions/cache@')));
        $dirStep = array_values(array_filter($before, fn (array $s) => ($s['id'] ?? null) === 'composer-cache'));

        expect($cache)->toHaveCount(1, "{$name}: no actions/cache before composer install.")
            ->and($dirStep)->toHaveCount(1, "{$name}: no composer-cache directory step.")
            ->and($cache[0]['with']['path'] ?? null)->toBe('${{ steps.composer-cache.outputs.dir }}', "{$name}: cache path is not Composer's download directory.")
            ->and(str_contains((string) ($cache[0]['with']['key'] ?? ''), "hashFiles('**/composer.lock')"))->toBeTrue("{$name}: cache key is not tied to composer.lock.");
    }
});

it('never caches the installed vendor directory, only downloaded archives', function () {
    foreach (ccWorkflow()['jobs'] as $name => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (! str_starts_with((string) ($step['uses'] ?? ''), 'actions/cache@')) {
                continue;
            }
            expect(str_contains((string) ($step['with']['path'] ?? ''), 'vendor'))->toBeFalse("{$name}: caching vendor/ would bypass composer.lock.");
        }
    }
});

it('keeps actions/cache off the self-hosted runner', function () {
    $selfHosted = array_filter(ccComposerJobs(), fn (array $j) => ccIsSelfHosted($j[1]));

    expect($selfHosted)->not->toBeEmpty();

    foreach ($selfHosted as [$name, $job]) {
        foreach ($job['steps'] as $step) {
            expect(str_starts_with((string) ($step['uses'] ?? ''), 'actions/cache@'))->toBeFalse("{$name} uses the runner's persistent cache.");
        }
    }
});
