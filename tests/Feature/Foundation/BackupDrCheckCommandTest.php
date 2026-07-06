<?php

use App\Services\Foundation\BackupDrGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation', 'BackupDr');

it('passes with GO on the repo default configuration', function () {
    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('backup_dr_ready')
        ->and($report['backup_dr_enabled'])->toBeTrue()
        ->and($report['backup_script_ok'])->toBeTrue()
        ->and($report['backup_verify_present'])->toBeTrue()
        ->and($report['backup_retention_present'])->toBeTrue()
        ->and($report['backup_no_destructive_command'])->toBeTrue()
        ->and($report['restore_rehearsal_ok'])->toBeTrue()
        ->and($report['restore_rehearsal_present'])->toBeTrue()
        ->and($report['restore_rehearsal_fail_fast'])->toBeTrue()
        ->and($report['restore_rehearsal_non_production_guard'])->toBeTrue()
        ->and($report['restore_rehearsal_no_production_restore'])->toBeTrue()
        ->and($report['objectives_ok'])->toBeTrue()
        ->and($report['rto_minutes'])->toBeGreaterThan(0)
        ->and($report['rpo_minutes'])->toBeGreaterThan(0)
        ->and($report['evidence_profiles_ok'])->toBeTrue()
        ->and($report['release_safety_ok'])->toBeTrue()
        ->and($report['deployment_rollback_decision'])->toBe('GO')
        ->and($report['cicd_enterprise_gate_decision'])->toBe('GO');

    expect(Artisan::call('foundation:backup-dr-check'))->toBe(0)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(0);
});

it('publishes the twelve ENT12 rules', function () {
    $ids = array_column(BackupDrGovernanceService::rules(), 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT12-BDR%03d', $n));
    }
});

it('emits privacy-safe JSON without a long digit run', function () {
    Artisan::call('foundation:backup-dr-check', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['sprint'])->toBe('ENT-12')
        ->and($decoded['privacy']['privacy_safe'])->toBeTrue()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);
});

it('fails when the backup script is missing', function () {
    config(['backup_dr.automation_files.backup_script' => 'scripts/this-backup-script-never-exists.sh']);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['backup_script_ok'])->toBeFalse();

    expect(Artisan::call('foundation:backup-dr-check'))->toBe(1)
        ->and(Artisan::call('foundation:backup-dr-check', ['--strict' => true]))->toBe(1);
});

it('fails when the restore-rehearsal script is missing', function () {
    config(['backup_dr.automation_files.restore_rehearsal_script' => 'scripts/this-rehearsal-never-exists.sh']);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['restore_rehearsal_ok'])->toBeFalse()
        ->and($report['restore_rehearsal_present'])->toBeFalse();
});

it('fails when the release-safety pre-deploy gate loses the ENT-12 command', function () {
    config(['backup_dr.required_pre_deploy_gate_command' => 'foundation:this-command-never-exists']);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('fails when a required evidence artifact is missing from a profile', function () {
    config(['backup_dr.evidence.artifact' => 'this-artifact-is-not-required.json']);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['evidence_profiles_ok'])->toBeFalse();
});

it('fails when RTO/RPO objectives are not documented', function () {
    config(['backup_dr.objectives.rto_minutes' => 0]);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['objectives_ok'])->toBeFalse();
});

it('fails when a production-endangering command is present in a scanned script', function () {
    // Force the scanner to look for a pattern the backup script legitimately
    // contains (pg_dump) — proves the destructive-command guard flips the
    // decision to FAIL for the backup script specifically.
    config(['backup_dr.forbidden_destructive_patterns' => ['pg_dump']]);

    $report = app(BackupDrGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['backup_no_destructive_command'])->toBeFalse();
});
