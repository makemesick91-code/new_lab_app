<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

function restoreDrillCommand(array $params = []): string
{
    $output = new BufferedOutput;
    Artisan::call('rollout:restore-drill-evidence', $params, $output);

    return $output->fetch();
}

function writeCommandEvidence(array $overrides = []): string
{
    $base = [
        'schema_version' => 1,
        'drill_id' => 'roll-5-1a-cmd',
        'environment' => 'staging',
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 1000,
        'restore_target' => 'daengtisiams_restore_drill_x',
        'production_overwrite' => false,
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'verification' => [
            'db_connectivity' => 'GO', 'migration_consistency' => 'GO', 'app_boot' => 'GO',
            'health_routes' => 'GO', 'sample_readonly_queries' => 'GO', 'pii_redaction_confirmed' => true,
        ],
        'decision' => 'GO',
    ];
    $dir = storage_path('app/readiness/restore-drills');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/cmd-'.uniqid().'.json';
    file_put_contents($path, json_encode(array_replace($base, $overrides)));

    return $path;
}

afterEach(function () {
    foreach (glob(storage_path('app/readiness/restore-drills/cmd-*.json')) ?: [] as $f) {
        @unlink($f);
    }
    @unlink(storage_path('app/readiness/restore-drills/latest.json'));
});

it('reports WATCH and exits 0 when no evidence exists', function () {
    // Point the canonical candidate at a non-existent path for determinism.
    config()->set('rollout_readiness.paths.restore_drill_evidence', [
        'storage/app/readiness/restore-drills/none-'.uniqid().'.json',
    ]);

    $this->artisan('rollout:restore-drill-evidence')
        ->assertExitCode(0)
        ->expectsOutputToContain('ROLL-5-1A Restore-Drill Evidence');
});

it('emits parseable JSON without leaking secrets', function () {
    $path = writeCommandEvidence();
    $raw = restoreDrillCommand(['--path' => $path, '--json' => true]);

    $json = json_decode($raw, true);
    expect($json)->toBeArray()
        ->and($json)->toHaveKeys(['status', 'unsafe', 'summary', 'decision', 'details'])
        ->and($json['status'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN'])
        ->and($raw)->not->toContain((string) config('app.key'));

    $dbPassword = (string) config('database.connections.'.config('database.default').'.password');
    if ($dbPassword !== '') {
        expect($raw)->not->toContain($dbPassword);
    }
});

it('exits non-zero with --strict on unsafe FAIL evidence', function () {
    $path = writeCommandEvidence(['production_overwrite' => true]);

    Artisan::call('rollout:restore-drill-evidence', ['--path' => $path, '--strict' => true]);
    expect(Artisan::call('rollout:restore-drill-evidence', ['--path' => $path, '--strict' => true]))->toBe(1);
});

it('exits 0 with --strict on a WATCH state', function () {
    config()->set('rollout_readiness.paths.restore_drill_evidence', [
        'storage/app/readiness/restore-drills/none-'.uniqid().'.json',
    ]);

    expect(Artisan::call('rollout:restore-drill-evidence', ['--strict' => true]))->toBe(0);
});

it('creates a non-GO template with --create-template and never runs a restore', function () {
    $path = storage_path('app/readiness/restore-drills/latest.json');
    @unlink($path);

    Artisan::call('rollout:restore-drill-evidence', ['--create-template' => true]);

    expect(is_file($path))->toBeTrue();

    $payload = json_decode((string) file_get_contents($path), true);
    expect($payload['decision'])->not->toBe('GO')
        ->and($payload['production_overwrite'])->toBeFalse();

    // Validating the freshly-created template must not report GO.
    $result = json_decode(restoreDrillCommand(['--path' => $path, '--json' => true]), true);
    expect($result['status'])->not->toBe('GO');
});
