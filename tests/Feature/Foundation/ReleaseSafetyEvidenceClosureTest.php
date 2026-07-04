<?php

use App\Services\Foundation\ReleaseEvidenceService;
use App\Services\Foundation\ReleaseSafetyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'ReleaseSafety', 'Nsf10');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/nsf10-safety-ci-evidence';
    $this->vpsDir = 'storage/framework/testing/nsf10-safety-vps-evidence';
    $this->backupDir = 'storage/framework/testing/nsf10-safety-backups';

    config([
        'release_evidence.profiles.ci.directory' => $this->ciDir,
        'release_evidence.profiles.vps.directory' => $this->vpsDir,
        'backup_governance.allowed_directories' => [$this->backupDir],
    ]);

    File::deleteDirectory(base_path($this->ciDir));
    File::deleteDirectory(base_path($this->vpsDir));
    File::deleteDirectory(base_path($this->backupDir));
    File::makeDirectory(base_path($this->backupDir), 0755, true);
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
    File::deleteDirectory(base_path($this->vpsDir));
    File::deleteDirectory(base_path($this->backupDir));
});

it('release safety check local profile remains honest and never fakes GO', function () {
    $report = app(ReleaseSafetyService::class)->collect('local');

    expect($report['profile'])->toBe('local')
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($report['evidence_chain']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('release safety check ci profile returns GO after evidence capture', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    // release:evidence-check self-persists its own release-evidence-check.json
    // artifact (an optional artifact), so run the real command — not just the
    // service — to reach the same steady-state GO an operator would see.
    Artisan::call('release:evidence-check', ['--profile' => 'ci']);

    $report = app(ReleaseSafetyService::class)->collect('ci');

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report['evidence_chain']['decision'])->toBe('GO');
});

it('release safety check ci profile is not GO before evidence is captured', function () {
    $report = app(ReleaseSafetyService::class)->collect('ci');

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['evidence_chain']['decision'])->toBe('FAIL');
});

it('release safety check vps profile requires backup verification evidence', function () {
    // vps evidence directory does not exist yet — no backup-verify.json captured.
    $report = app(ReleaseSafetyService::class)->collect('vps');

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['backup_verification'])->toBeNull();
});

it('release safety check vps profile reaches GO once backup and evidence are captured', function () {
    $backupPath = $this->backupDir.'/pre_nsf10_test.sql';
    file_put_contents(base_path($backupPath), str_repeat("-- PostgreSQL database dump line\n", 100));

    app(ReleaseEvidenceService::class)->capture('vps', 'http://127.0.0.1', $backupPath);
    Artisan::call('release:evidence-check', ['--profile' => 'vps']);

    $report = app(ReleaseSafetyService::class)->collect('vps');

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report['backup_verification']['decision'])->toBe('GO');
});

it('release safety check vps profile fails when the captured backup verification is FAIL', function () {
    $backupPath = $this->backupDir.'/too-small.sql';
    file_put_contents(base_path($backupPath), '-- tiny');

    app(ReleaseEvidenceService::class)->capture('vps', null, $backupPath);

    $report = app(ReleaseSafetyService::class)->collect('vps');

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['backup_verification']['decision'])->toBe('FAIL');
});

it('release safety check config is still profile-backward-compatible with no argument', function () {
    $report = app(ReleaseSafetyService::class)->collect();

    expect($report['profile'])->toBe('local')
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});
