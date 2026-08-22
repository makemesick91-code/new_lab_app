<?php

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotDiskProbe;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * TEST-BASELINE-MONITORING-FAILURES-1 — the disk half of the snapshot aggregate.
 *
 * Free disk is the only genuinely non-deterministic input to `overall_status`, and
 * until the probe was injectable it could not be stated in a test: the assertions
 * that existed silently described whichever machine ran them. These pin the
 * production escalation — a low disk MUST still reach the aggregate and the exit
 * code — so the boundary cannot be weakened without a test failing.
 */
uses(TestCase::class);

function pilotSnapshotWithDisk(?float $freeGb): array
{
    // A log file with no error-like lines, so the logs section is OK and the disk is
    // the only thing that can move the aggregate.
    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-disk-');
    file_put_contents($logPath, '[2026-07-01 11:00:00] production.INFO: nothing to see here'.PHP_EOL);

    $snapshot = (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe($freeGb)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    return $snapshot;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports OK and exit code 0 when free disk is clear of the boundary', function () {
    $snapshot = pilotSnapshotWithDisk(100.0);

    expect($snapshot['sections']['resources']['status'])->toBe('OK')
        ->and($snapshot['sections']['resources']['metrics']['disk_free_gb'])->toBe(100.0)
        ->and($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['overall_status'])->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(0);
});

it('escalates a low disk to WATCH in the aggregate and the exit code', function () {
    $snapshot = pilotSnapshotWithDisk(15.0);

    expect($snapshot['sections']['resources']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['overall_status'])->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(1);
});

it('escalates a critically low disk to FIX in the aggregate and the exit code', function () {
    $snapshot = pilotSnapshotWithDisk(5.0);

    expect($snapshot['sections']['resources']['status'])->toBe('FIX')
        ->and($snapshot['overall_status'])->toBe('FIX')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(3);
});

it('treats an unreadable disk as WATCH and warns, never as OK', function () {
    $snapshot = pilotSnapshotWithDisk(null);

    expect($snapshot['sections']['resources']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['resources']['metrics']['disk_free_gb'])->toBeNull()
        ->and($snapshot['overall_status'])->toBe('WATCH')
        ->and($snapshot['warnings'])->toContain('Could not read disk free space.');
});

it('uses the real filesystem probe when none is injected', function () {
    // Guards the wiring: production constructs the service without arguments, so the
    // default probe must be the real one and must report the actual host disk.
    $snapshot = (new PilotPerformanceSnapshotService)->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => null,
    ]);

    $expectedGb = round(((float) disk_free_space(storage_path())) / 1024 / 1024 / 1024, 2);

    expect($snapshot['sections']['resources']['metrics']['disk_free_gb'])->toBe($expectedGb)
        ->and($snapshot['sections']['resources']['status'])
        ->toBe(PilotPerformanceSnapshotClassifier::classifyDiskFreeGb($expectedGb));
});

it('resolves the real disk probe from the container and binds no substitute anywhere', function () {
    // The probe is a constructor seam, so the one way a substitute could reach
    // production is a container binding. Resolving the command's dependency the way
    // the command does proves the default holds here...
    $resolved = app(PilotPerformanceSnapshotService::class);

    $probe = (new ReflectionClass($resolved))->getProperty('diskProbe')->getValue($resolved);

    expect($probe::class)->toBe(PilotPerformanceSnapshotDiskProbe::class);

    // ...but a binding registered behind an environment check would simply not exist
    // under APP_ENV=testing, so a resolve-time assertion cannot see it. Scan the source
    // instead: nothing outside the Monitoring service and its own tests may name the
    // probe, which catches an environment-guarded binding that only wakes on pilot.
    $roots = [base_path('app'), base_path('config'), base_path('bootstrap'), base_path('routes')];
    $offenders = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, 'Services/Monitoring/PilotPerformanceSnapshot')) {
                continue; // the service declares the dependency; the probe declares itself
            }

            if (str_contains((string) file_get_contents($path), 'PilotPerformanceSnapshotDiskProbe')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($offenders)->toBe([]);
});
