<?php

use App\Services\Foundation\BackupVerificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'BackupVerification');

beforeEach(function () {
    $this->backupDir = 'storage/framework/testing/nsf10-backups';
    config(['backup_governance.allowed_directories' => [$this->backupDir]]);

    File::deleteDirectory(base_path($this->backupDir));
    File::makeDirectory(base_path($this->backupDir), 0755, true);
});

afterEach(function () {
    File::deleteDirectory(base_path($this->backupDir));
});

function nsf10FakeBackupContents(int $lines = 100): string
{
    $out = "-- PostgreSQL database dump\n";
    for ($i = 0; $i < $lines; $i++) {
        $out .= "-- fixture line {$i} for NSF-10 backup verification tests\n";
    }

    return $out;
}

it('backup governance config exists', function () {
    expect(config('backup_governance'))->toBeArray()
        ->and(config('backup_governance.min_size_bytes'))->toBeGreaterThan(0)
        ->and(config('backup_governance.allowed_extensions'))->toContain('sql');
});

it('registers the backup verify command', function () {
    expect(Artisan::all())->toHaveKey('foundation:backup-verify');
});

it('backup verify returns GO for a valid fake backup fixture', function () {
    $path = $this->backupDir.'/pre_nsf10_test.sql';
    file_put_contents(base_path($path), nsf10FakeBackupContents());

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report['summary']['decision'])->toBe('GO');
});

it('backup verify fails for a missing file', function () {
    $report = app(BackupVerificationService::class)->verify($this->backupDir.'/does-not-exist.sql');

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('backup verify fails for a zero byte file', function () {
    $path = $this->backupDir.'/empty.sql';
    file_put_contents(base_path($path), '');

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('backup verify fails for a file below the minimum size', function () {
    $path = $this->backupDir.'/too-small.sql';
    file_put_contents(base_path($path), '-- tiny');

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('backup verify fails for a path outside allowed directories', function () {
    $report = app(BackupVerificationService::class)->verify('composer.json');

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('backup verify fails for an unexpected file extension', function () {
    $path = $this->backupDir.'/pre_nsf10_test.txt';
    file_put_contents(base_path($path), nsf10FakeBackupContents());

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('backup verify warns (not fails) for a stale but otherwise valid backup', function () {
    config(['backup_governance.stale_after_seconds' => 1]);

    $path = $this->backupDir.'/stale.sql';
    file_put_contents(base_path($path), nsf10FakeBackupContents());
    touch(base_path($path), time() - 3600);

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report['summary']['decision'])->toBe('WATCH')
        ->and($report['summary']['errors'])->toBe(0);
});

it('backup verify never reads or exposes dump contents beyond the header sniff', function () {
    $path = $this->backupDir.'/pre_nsf10_test.sql';
    file_put_contents(base_path($path), nsf10FakeBackupContents());

    $report = app(BackupVerificationService::class)->verify($path);

    expect($report)->toHaveKey('privacy')
        ->and($report['privacy']['dump_contents_read'])->toBeFalse();
});
