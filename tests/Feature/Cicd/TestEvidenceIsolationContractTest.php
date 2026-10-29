<?php

declare(strict_types=1);

use Illuminate\Support\Facades\ParallelTesting;

/*
|--------------------------------------------------------------------------
| CI-PERF-02 — test evidence is isolated per process and never written to a
| canonical evidence path
|--------------------------------------------------------------------------
| Release evidence, restore-drill evidence and rollback manifests are written
| by tests too. Three properties keep that safe once tests run in parallel:
|
|   1. no test touches a CANONICAL evidence directory (the ones production and
|      the CI gate itself use);
|   2. every fixed test-storage directory is made unique per worker through
|      isolatedTestStoragePath(), and is unchanged in a serial run;
|   3. the helper refuses anything outside storage/framework/testing.
|
| The needles below are assembled from fragments so this file can never match
| itself.
*/

/** @return list<string> every test PHP file, excluding this guard */
function evidenceIsolationTestFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && $file->getRealPath() !== realpath(__FILE__)) {
            $files[] = $file->getRealPath();
        }
    }
    sort($files);

    return $files;
}

function evidenceIsolationRelative(string $absolute): string
{
    return ltrim(substr($absolute, strlen(base_path())), '/');
}

afterEach(function () {
    ParallelTesting::resolveTokenUsing(null);
});

it('returns the path unchanged when no parallel worker token exists', function () {
    ParallelTesting::resolveTokenUsing(fn () => false);

    expect(isolatedTestStoragePath('storage/framework/testing/rpt1-ci-evidence'))
        ->toBe('storage/framework/testing/rpt1-ci-evidence');
});

it('gives every parallel worker its own directory', function () {
    $paths = [];
    foreach (['1', '2', '3', '4'] as $token) {
        ParallelTesting::resolveTokenUsing(fn () => $token);
        $paths[$token] = isolatedTestStoragePath('storage/framework/testing/rpt1-ci-evidence');
    }

    expect($paths)->toBe([
        '1' => 'storage/framework/testing/rpt1-ci-evidence-p1',
        '2' => 'storage/framework/testing/rpt1-ci-evidence-p2',
        '3' => 'storage/framework/testing/rpt1-ci-evidence-p3',
        '4' => 'storage/framework/testing/rpt1-ci-evidence-p4',
    ])->and(array_unique($paths))->toHaveCount(4);
});

it('refuses to isolate anything outside storage/framework/testing', function (string $path) {
    expect(fn () => isolatedTestStoragePath($path))->toThrow(InvalidArgumentException::class);
})->with([
    'the CI evidence directory' => ['storage/'.'ci-evidence'],
    'the production evidence directory' => ['storage/'.'release-evidence/latest'],
    'the canonical restore-drill directory' => ['storage/app/'.'readiness/restore-drills'],
    'the testing root itself' => ['storage/framework/testing/'],
    'a traversal out of the testing root' => ['storage/framework/testing/../../app'],
    'an absolute path' => ['/tmp/x'],
]);

it('keeps every test away from the canonical evidence directories', function () {
    // Filesystem-call forms only: string assertions about configuration
    // (for example a pinned default path) are allowed.
    $needles = [
        "storage_path('".'app/readiness',
        "storage_path('".'release-evidence',
        "storage_path('".'ci-evidence',
        "base_path('storage/".'release-evidence',
        "base_path('storage/".'ci-evidence',
        "base_path('storage/".'app/readiness',
    ];

    $offenders = [];
    foreach (evidenceIsolationTestFiles() as $file) {
        foreach (file($file) as $number => $line) {
            foreach ($needles as $needle) {
                if (str_contains($line, $needle)) {
                    $offenders[] = evidenceIsolationRelative($file).':'.($number + 1);
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('routes every fixed test-storage directory through the per-worker helper', function () {
    $literal = "'storage/framework/".'testing/';

    $offenders = [];
    foreach (evidenceIsolationTestFiles() as $file) {
        if (str_ends_with($file, '/tests/Pest.php')) {
            continue; // the helper's own definition
        }
        foreach (file($file) as $number => $line) {
            if (! str_contains($line, $literal)) {
                continue;
            }
            $isolated = str_contains($line, 'isolatedTestStoragePath(');
            $randomised = str_contains($line, 'random_bytes(') || str_contains($line, 'bin2hex(');
            if (! $isolated && ! $randomised) {
                $offenders[] = evidenceIsolationRelative($file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the clinical-evidence migration manifests inside a per-test storage root', function () {
    // The migration command writes manifests under storage_path(); this suite
    // must redirect storage_path() before running it.
    $suite = (string) file_get_contents(base_path('tests/Feature/Storage/ClinicalEvidencePrivacyTest.php'));

    expect($suite)->toContain('useStoragePath(')
        ->and($suite)->toContain("isolatedTestStoragePath('storage/framework/testing/clinical-evidence-storage-root')");
});
