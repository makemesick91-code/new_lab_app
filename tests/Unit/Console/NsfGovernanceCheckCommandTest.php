<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture nsf governance check command', function () {
    expect(Artisan::all())->toHaveKey('architecture:nsf-governance-check');
});

it('runs and outputs valid JSON with governance summary', function () {
    $exitCode = Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'environment', 'metadata', 'summary', 'rules', 'privacy'])
        ->and($payload['summary']['rules'])->toBe(21)
        ->and($payload['privacy']['privacy_safe'])->toBeTrue();
});

it('includes nsf r001 through r021 rule results', function () {
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ruleIds = collect($payload['rules'])->pluck('rule_id')->unique()->all();

    foreach (range(1, 21) as $n) {
        $id = sprintf('NSF-R%03d', $n);
        expect($ruleIds)->toContain($id);
    }
});

it('has no duplicate nsf rule ids in output', function () {
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ids = collect($payload['rules'])->pluck('rule_id');
    expect($ids->count())->toBe($ids->unique()->count());
});

it('every nsf rule has required fields in config registry', function () {
    foreach (config('nsf.rules', []) as $rule) {
        expect($rule)->toHaveKeys(['rule_id', 'title', 'severity', 'applies_to', 'validation', 'status']);
    }
});

it('validates dmo alignment availability', function () {
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-dmo' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['dmo_alignment']['available'])->toBeTrue()
        ->and($payload['dmo_alignment']['governance_errors'])->toBe(0);
});

it('validates observability command availability', function () {
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['observability']['slow_query_audit_command_available'])->toBeTrue()
        ->and($payload['observability']['runtime_query_observability_command_available'])->toBeTrue();
});

it('strict mode exits non zero when errors exist', function () {
    $exitCode = Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--strict' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if (($payload['summary']['errors'] ?? 0) > 0) {
        expect($exitCode)->toBe(1);
    } else {
        expect($exitCode)->toBe(0);
    }
});

it('writes governance output only under storage app architecture', function () {
    $relative = 'nsf6-governance-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:nsf-governance-check', [
            '--json' => true,
            '--output' => $relative,
        ]);

        $fullPath = storage_path('app/architecture/'.$relative);

        expect($exitCode)->toBe(0)
            ->and(file_exists($fullPath))->toBeTrue();
    } finally {
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
});

it('does not expose sensitive row level data in governance output', function () {
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-privacy' => true,
    ]);
    $output = Artisan::output();

    expect($output)->not->toMatch('/\d{16}/')
        ->and($output)->not->toContain('Pasien Test');
});

it('foundation governance summary command runs', function () {
    expect(Artisan::all())->toHaveKey('architecture:foundation-governance-summary');

    $exitCode = Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['summary', 'nsf_governance', 'dmo_governance', 'owner_kpi_registry'])
        ->and($payload['summary']['nsf_rules'])->toBe(21);
});

it('dmo governance command still runs after nsf6 additions', function () {
    $exitCode = Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['summary']['rules'])->toBe(15);
});
