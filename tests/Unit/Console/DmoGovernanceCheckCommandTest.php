<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture dmo governance check command', function () {
    expect(Artisan::all())->toHaveKey('architecture:dmo-governance-check');
});

it('runs and outputs valid JSON with governance summary', function () {
    $exitCode = Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'environment', 'metadata', 'summary', 'results', 'privacy'])
        ->and($payload['summary']['rules'])->toBe(15)
        ->and($payload['privacy']['privacy_safe'])->toBeTrue();
});

it('includes dmo r001 through r015 rule results', function () {
    Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ruleIds = collect($payload['results'])->pluck('rule_id')->unique()->all();

    foreach (range(1, 15) as $n) {
        $id = sprintf('DMO-R%03d', $n);
        expect($ruleIds)->toContain($id);
    }
});

it('validates owner kpi mapping to canonical metric', function () {
    Artisan::call('architecture:dmo-governance-check', ['--json' => true, '--domain' => 'owner']);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r009 = collect($payload['results'])->where('rule_id', 'DMO-R009');

    expect($r009->where('status', 'failed')->count())->toBe(0)
        ->and($r009->count())->toBeGreaterThan(5);
});

it('validates metric grain dimensions and sensitivity via governance rules', function () {
    Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $failedR002 = collect($payload['results'])->where('rule_id', 'DMO-R002')->where('status', 'failed');
    $failedR012 = collect($payload['results'])->where('rule_id', 'DMO-R012')->where('status', 'failed');

    expect($failedR002->count())->toBe(0)
        ->and($failedR012->count())->toBe(0);
});

it('strict mode exits non zero when errors exist', function () {
    $exitCode = Artisan::call('architecture:dmo-governance-check', [
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
    $relative = 'dmo2-governance-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:dmo-governance-check', [
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
    Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->not->toMatch('/\d{16}/')
        ->and($output)->not->toContain('Pasien Test');
});

it('dmo foundation command still runs after dmo2 additions', function () {
    $exitCode = Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['governance_rules'])->toHaveCount(15);
});
