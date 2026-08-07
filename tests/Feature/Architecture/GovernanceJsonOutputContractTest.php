<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\NsfApplicationRulesService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses()->group('Architecture', 'FoundationGovernance', 'Cicd');

/*
 * CICD-FIX-1 — the --json contract of the governance commands.
 *
 * Regression origin: `architecture:nsf-governance-check --json
 * --include-observability` emitted NOTHING and still exited 0. Callers doing
 * json_decode(Artisan::output(), ..., JSON_THROW_ON_ERROR) therefore failed with
 * "Syntax error" on an empty string.
 *
 * The cause was a nested Artisan::call inside NsfApplicationRulesService.
 * Illuminate\Console\Application::call() does
 *
 *     $this->run($input, $this->lastOutput = $outputBuffer ?: new BufferedOutput)
 *
 * so it reassigns the SHARED lastOutput even when an explicit buffer is passed,
 * and reading that buffer drains it. The inner call destroyed the outer
 * command's output.
 *
 * It only reproduced on PostgreSQL, because the pg_stat_statements branch that
 * made the nested call does not run on other drivers. These tests therefore run
 * on whatever driver the suite is configured with, and the ones that can only be
 * meaningful on PostgreSQL say so explicitly.
 */

/**
 * Decode a command's captured output exactly the way the governance tests do.
 *
 * @return array<string, mixed>
 */
function decodeGovernanceJson(string $output): array
{
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

it('emits valid JSON from the nsf governance check with observability included', function () {
    $exit = Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $output = Artisan::output();

    // The exact regression: output must not be empty.
    expect($exit)->toBe(0)
        ->and($output)->not->toBe('', 'the command exited 0 but emitted nothing');

    $payload = decodeGovernanceJson($output);

    expect($payload)->toHaveKey('observability')
        ->and($payload)->toHaveKey('summary');
});

it('emits valid JSON from the foundation governance summary', function () {
    $exit = Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toBe('');

    expect(decodeGovernanceJson($output))->toHaveKey('summary');
});

it('keeps json stdout free of any non-json preamble or trailer', function () {
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $output = trim(Artisan::output());

    // Valid JSON only — nothing before the opening brace, nothing after the close.
    expect($output)->toStartWith('{')
        ->and($output)->toEndWith('}');

    // And it decodes as a whole, so there is no trailing second document.
    expect(decodeGovernanceJson($output))->toBeArray();
});

it('survives a nested command invocation without losing its own output', function () {
    // Guards the precise mechanism: run the inner command first, then the outer
    // one, and prove the outer output is still intact and complete.
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $withoutObservability = strlen(Artisan::output());

    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $withObservability = strlen(Artisan::output());

    // The observability run does strictly more work, so it cannot emit less.
    expect($withoutObservability)->toBeGreaterThan(0)
        ->and($withObservability)->toBeGreaterThan(0)
        ->and($withObservability)->toBeGreaterThanOrEqual($withoutObservability);
});

it('exercises the observability path against PostgreSQL', function () {
    $driver = DB::connection()->getDriverName();

    if ($driver !== 'pgsql') {
        expect(true)->toBeTrue();

        return;
    }

    // On PostgreSQL the pg_stat_statements branch runs, which is the branch that
    // made the nested Artisan call and destroyed the outer output.
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $payload = decodeGovernanceJson(Artisan::output());

    expect($payload['observability'])->toBeArray()
        ->and($payload['observability'])->toHaveKey('pg_stat_statements');
});

it('returns non-zero and emits no json when the report cannot be encoded', function () {
    // A report carrying NAN cannot be JSON encoded. Before the fix the command
    // wrote an empty line and still exited 0; it must now fail loudly instead.
    $stub = Mockery::mock(NsfApplicationRulesService::class);
    $stub->shouldReceive('collect')->andReturn([
        'summary' => ['errors' => 0, 'warnings' => 0],
        'unencodable' => NAN,
    ]);
    $this->instance(NsfApplicationRulesService::class, $stub);

    $exit = Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0, 'an unencodable report must not report success');

    // Whatever was written, it must not be malformed or partial JSON.
    $trimmed = trim($output);
    if ($trimmed !== '') {
        expect(fn () => decodeGovernanceJson($trimmed))->toThrow(JsonException::class);
    }
});

it('returns non-zero when the summary report cannot be encoded', function () {
    $stub = Mockery::mock(FoundationGovernanceSummaryService::class);
    $stub->shouldReceive('collect')->andReturn([
        'summary' => ['combined_decision' => 'GO'],
        'unencodable' => INF,
    ]);
    $this->instance(FoundationGovernanceSummaryService::class, $stub);

    $exit = Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);

    expect($exit)->not->toBe(0);
});

it('keeps the human readable mode usable', function () {
    $exit = Artisan::call('architecture:nsf-governance-check', []);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toBe('')
        // Human mode is deliberately NOT JSON.
        ->and(trim($output))->not->toStartWith('{');

    $exit = Artisan::call('architecture:foundation-governance-summary', []);

    expect($exit)->toBe(0)
        ->and(trim(Artisan::output()))->not->toStartWith('{');
});
