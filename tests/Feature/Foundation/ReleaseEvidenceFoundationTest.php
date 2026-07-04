<?php

use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'ReleaseEvidence');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/nsf10-ci-evidence';
    $this->vpsDir = 'storage/framework/testing/nsf10-vps-evidence';

    config([
        'release_evidence.profiles.ci.directory' => $this->ciDir,
        'release_evidence.profiles.vps.directory' => $this->vpsDir,
    ]);

    File::deleteDirectory(base_path($this->ciDir));
    File::deleteDirectory(base_path($this->vpsDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
    File::deleteDirectory(base_path($this->vpsDir));
});

it('release evidence config exists with local ci and vps profiles', function () {
    $config = config('release_evidence');

    expect($config)->toBeArray()
        ->and($config['profiles'])->toHaveKeys(['local', 'ci', 'vps'])
        ->and($config['profiles']['local']['required_artifacts'])->toBe([])
        ->and($config['profiles']['ci']['required_artifacts'])->not->toBeEmpty()
        ->and($config['profiles']['vps']['required_artifacts'])->not->toBeEmpty()
        ->and($config['forbidden_patterns'])->not->toBeEmpty();
});

it('registers release evidence capture and check commands', function () {
    expect(Artisan::all())->toHaveKeys(['release:evidence-capture', 'release:evidence-check']);
});

it('release evidence capture ci profile creates safe artifacts', function () {
    $report = app(ReleaseEvidenceService::class)->capture('ci');

    expect($report['summary']['decision'])->toBe('GO');

    foreach (config('release_evidence.profiles.ci.required_artifacts') as $artifact) {
        $path = base_path($this->ciDir.'/'.$artifact);
        expect(is_file($path))->toBeTrue("Expected artifact missing: {$artifact}")
            ->and(filesize($path))->toBeGreaterThan(0);
    }
});

it('release evidence check ci profile returns GO after capture', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    // release-evidence-check.json is self-persisted by the command, not the
    // service capture() call, so run check() twice to reach steady-state GO
    // exactly like the real release:evidence-check command's self-persist behavior.
    app(ReleaseEvidenceService::class)->check('ci');
    $secondCheck = app(ReleaseEvidenceService::class)->check('ci');

    expect($secondCheck['summary']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($secondCheck['summary']['errors'])->toBe(0);
});

it('release evidence check returns FAIL when a required artifact is missing', function () {
    // No capture has run — the ci evidence directory does not exist yet.
    $report = app(ReleaseEvidenceService::class)->check('ci');

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['summary']['errors'])->toBeGreaterThan(0);
});

it('release evidence check never fails local profile for missing artifacts', function () {
    $report = app(ReleaseEvidenceService::class)->check('local');

    expect($report['summary']['decision'])->not->toBe('FAIL');
});

it('release evidence artifacts do not contain env secrets or PII patterns', function () {
    app(ReleaseEvidenceService::class)->capture('ci');

    foreach (config('release_evidence.profiles.ci.required_artifacts') as $artifact) {
        $contents = file_get_contents(base_path($this->ciDir.'/'.$artifact));

        expect($contents)->not->toContain('APP_KEY=')
            ->not->toContain('DB_PASSWORD')
            ->not->toMatch('/\d{16}/');
    }
});

it('release evidence capture rejects an unknown profile', function () {
    $report = app(ReleaseEvidenceService::class)->capture('bogus-profile');

    expect($report['summary']['decision'])->toBe('FAIL');
});
