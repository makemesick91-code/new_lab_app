<?php

use App\Services\Foundation\RestoreDrillEvidenceService;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

function restoreDrillService(): RestoreDrillEvidenceService
{
    return app(RestoreDrillEvidenceService::class);
}

/**
 * Write an evidence payload to a temp file and return its path.
 *
 * @param  array<string, mixed>  $overrides
 */
function writeDrillEvidence(array $overrides = []): string
{
    $base = [
        'schema_version' => 1,
        'drill_id' => 'roll-5-1a-20260710-010203',
        'environment' => 'staging',
        // Absolute, non-project path => the local-backup existence check is skipped.
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 123456,
        'restore_target' => 'daengtisiams_restore_drill_20260710',
        'production_overwrite' => false,
        'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'duration_seconds' => 120,
        'operator' => 'ops',
        'commands_summary' => ['createdb', 'psql restore (password hidden)', 'dropdb'],
        'verification' => [
            'db_connectivity' => 'GO',
            'migration_consistency' => 'GO',
            'app_boot' => 'GO',
            'health_routes' => 'GO',
            'sample_readonly_queries' => 'GO',
            'pii_redaction_confirmed' => true,
        ],
        'decision' => 'GO',
        'notes' => ['safe'],
    ];

    $payload = array_replace($base, $overrides);
    $dir = storage_path('app/readiness/restore-drills');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/test-'.uniqid().'.json';
    file_put_contents($path, json_encode($payload));

    return $path;
}

afterEach(function () {
    foreach (glob(storage_path('app/readiness/restore-drills/test-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

it('returns WATCH when no evidence file is present', function () {
    $result = restoreDrillService()->evaluate(storage_path('app/readiness/restore-drills/does-not-exist.json'));

    expect($result['status'])->toBe('WATCH')
        ->and($result['unsafe'])->toBeFalse()
        ->and($result['details']['evidence_present'])->toBeFalse();
});

it('returns GO for valid, recent, safe staging evidence', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence());

    expect($result['status'])->toBe('GO')
        ->and($result['unsafe'])->toBeFalse()
        ->and($result['details']['production_overwrite'])->toBeFalse()
        ->and($result['details']['evidence_present'])->toBeTrue();
});

it('returns an unsafe FAIL when production_overwrite is true', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence(['production_overwrite' => true]));

    expect($result['status'])->toBe('FAIL')
        ->and($result['unsafe'])->toBeTrue();
});

it('returns an unsafe FAIL when the drill targeted a production environment', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence(['environment' => 'production']));

    expect($result['status'])->toBe('FAIL')
        ->and($result['unsafe'])->toBeTrue();
});

it('rejects evidence containing a secret-like string as FAIL', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence([
        'notes' => ['leaked db_password=SuperSecret123 in a note'],
    ]));

    expect($result['status'])->toBe('FAIL')
        ->and($result['unsafe'])->toBeTrue();
});

it('rejects evidence containing a KTP/NIK-like value as FAIL', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence([
        'notes' => ['patient 3201234567890123 restored'],
    ]));

    expect($result['status'])->toBe('FAIL');
});

it('treats stale evidence as WATCH', function () {
    config()->set('rollout_readiness.thresholds.restore_drill_stale_hours', 1);
    $old = gmdate('Y-m-d\TH:i:s\Z', time() - 7200); // 2h ago, threshold 1h

    $result = restoreDrillService()->evaluate(writeDrillEvidence(['completed_at' => $old]));

    expect($result['status'])->toBe('WATCH')
        ->and($result['details']['stale'])->toBeTrue();
});

it('FAILs invalid schema (missing required field)', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence(['drill_id' => '']));

    expect($result['status'])->toBe('FAIL')
        ->and($result['details']['schema_valid'])->toBeFalse();
});

it('FAILs when the evidence own decision or a verification sub-check is FAIL', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence([
        'verification' => [
            'db_connectivity' => 'GO', 'migration_consistency' => 'GO', 'app_boot' => 'GO',
            'health_routes' => 'GO', 'sample_readonly_queries' => 'FAIL', 'pii_redaction_confirmed' => true,
        ],
    ]));

    expect($result['status'])->toBe('FAIL');
});

it('never echoes a raw secret value back in the result', function () {
    $result = restoreDrillService()->evaluate(writeDrillEvidence([
        'notes' => ['db_password=TopSecretValue999'],
    ]));

    $raw = json_encode($result);
    expect($raw)->not->toContain('TopSecretValue999');
});
