<?php

use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

uses()->group('Foundation', 'FeatureFlag', 'LegacyRme');

/**
 * LEGACY-RME-PDF-ROLL-1 — feature flag runtime override readiness.
 *
 * The defect this pins: a flag may declare an `env_key` and still ignore the
 * environment completely, because `config:cache` stops Laravel from loading the
 * environment file at all. Any override that is not CAPTURED while the config
 * file is built is inert on exactly the deployment shape production runs.
 *
 * These tests reproduce the cached runtime honestly instead of trusting an
 * uncached local run: the config file is evaluated with an environment set, the
 * result is round-tripped through var_export() the way `config:cache` does, and
 * the flag is then resolved with the environment removed.
 */

/**
 * Evaluate the real config file with an explicitly controlled environment.
 *
 * Every variable named in $env is restored to its prior state afterwards, so a
 * test can never leak an override into the next one — these tests must assert
 * the resolution contract, not the ambient state of the process.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function ffBuildRegistry(array $env): array
{
    $restore = [];

    foreach ($env as $key => $value) {
        $restore[$key] = array_key_exists($key, $_ENV) ? $_ENV[$key] : null;

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('feature_flags.php');
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * Reproduce `config:cache`: var_export the built registry to a file, require it
 * back, and install it as config — which is exactly what a cached production
 * deployment resolves against. The environment is restored by ffBuildRegistry
 * before resolution happens, so a runtime env() read contributes nothing here,
 * just as it contributes nothing on a cached deployment.
 *
 * @param  array<string, string|null>  $env
 */
function ffCachedService(array $env): FeatureFlagService
{
    $registry = ffBuildRegistry($env);

    // FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — one allocation, one artifact. The
    // previous `.'.php'` derivation left the `tempnam()` file itself orphaned on
    // every call. `require` dispatches on content, never on the extension, so
    // the suffix bought nothing and cost one zero-byte orphan per invocation.
    $path = tempnam(sys_get_temp_dir(), 'ffcache');
    file_put_contents($path, '<?php return '.var_export($registry, true).';');

    try {
        $cached = require $path;
    } finally {
        @unlink($path);
    }

    config()->set('feature_flags.flags', $cached['flags']);

    return app(FeatureFlagService::class);
}

/** The legacy archive flag resolved the way a cached deployment resolves it. */
function ffResolveThroughConfigCache(?string $value): FeatureFlagService
{
    return ffCachedService(['FEATURE_RME_LEGACY_PDF_ARCHIVE' => $value]);
}

// ---------------------------------------------------------------------------
// The capture contract — the durable anti-regression guard.
// ---------------------------------------------------------------------------

it('captures a config-build-time env_value for every flag that declares an env_key', function () {
    $uncaptured = [];

    foreach (config('feature_flags.flags', []) as $key => $definition) {
        $envKey = $definition['env_key'] ?? '';

        if (is_string($envKey) && $envKey !== '' && ! array_key_exists('env_value', $definition)) {
            $uncaptured[] = $key;
        }
    }

    expect($uncaptured)->toBe([], 'these flags declare an env_key that is ignored under config:cache: '.implode(', ', $uncaptured));
});

it('fails governance when a flag declares an env_key without a capture', function () {
    $flags = config('feature_flags.flags');
    unset($flags['rme.legacy_pdf_archive']['env_value']);
    config()->set('feature_flags.flags', $flags);

    $governance = app(FeatureFlagService::class)->validateGovernance();
    $check = collect($governance['checks'])->firstWhere('check_id', 'FLAG-ENV-CAPTURE');

    expect($check['status'])->toBe('failed')
        ->and($check['message'])->toContain('rme.legacy_pdf_archive')
        ->and($governance['summary']['decision'])->toBe('FAIL');
});

it('keeps the shipped registry at GO with every capture in place', function () {
    $governance = app(FeatureFlagService::class)->validateGovernance();

    expect($governance['summary']['decision'])->toBe('GO')
        ->and(collect($governance['checks'])->firstWhere('check_id', 'FLAG-ENV-CAPTURE')['status'])->toBe('passed');
});

// ---------------------------------------------------------------------------
// The resolution matrix, resolved the way a cached deployment resolves it.
// ---------------------------------------------------------------------------

it('resolves the flag deterministically through a cached config', function (?string $env, bool $expected, string $via) {
    $service = ffResolveThroughConfigCache($env);
    $flag = $service->get('rme.legacy_pdf_archive');

    expect($flag['enabled'])->toBe($expected)
        ->and($flag['env_resolution'])->toBe($via)
        ->and($flag['env_captured'])->toBeTrue();
})->with([
    'unset falls back to the default' => [null, false, 'default'],
    'explicit false stays off' => ['false', false, 'env'],
    'explicit true turns on' => ['true', true, 'env'],
    'numeric 1 turns on' => ['1', true, 'env'],
    'numeric 0 stays off' => ['0', false, 'env'],
    'blank is treated as unconfigured' => ['', false, 'default'],
    'whitespace is treated as unconfigured' => ['   ', false, 'default'],
    'invalid value fails closed' => ['banana', false, 'invalid_fallback_default'],
]);

it('reports an invalid override without echoing the raw environment value', function () {
    $service = ffResolveThroughConfigCache('not-a-boolean-at-all');
    $flag = $service->get('rme.legacy_pdf_archive');

    expect($flag['env_value'])->toBe('invalid')
        ->and(json_encode($flag))->not->toContain('not-a-boolean-at-all');
});

it('fails governance on an unparseable override', function () {
    $governance = ffResolveThroughConfigCache('banana')->validateGovernance();

    expect(collect($governance['checks'])->firstWhere('check_id', 'FLAG-ENV-VALUE-VALID')['status'])->toBe('failed')
        ->and($governance['summary']['decision'])->toBe('FAIL');
});

it('rolls back to off deterministically once the override is set to false', function () {
    expect(ffResolveThroughConfigCache('true')->enabled('rme.legacy_pdf_archive'))->toBeTrue();
    expect(ffResolveThroughConfigCache('false')->enabled('rme.legacy_pdf_archive'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The Legacy RME guard inherits the canonical resolution — no second system.
// ---------------------------------------------------------------------------

it('resolves the legacy guard through FeatureFlagService rather than its own env read', function (?string $env, bool $expected) {
    ffResolveThroughConfigCache($env);

    expect(app(LegacyRmeFeatureGuard::class)->enabled())->toBe($expected);
})->with([
    'off by default' => [null, false],
    'on when overridden' => ['true', true],
    'off when rolled back' => ['false', false],
    'off when invalid' => ['banana', false],
]);

it('keeps the legacy archive off by default in the shipped registry', function () {
    $flag = app(FeatureFlagService::class)->get('rme.legacy_pdf_archive');

    expect($flag['default'])->toBeFalse()
        ->and($flag['enabled'])->toBeFalse()
        ->and($flag['risk_level'])->toBe('high')
        ->and(app(LegacyRmeFeatureGuard::class)->enabled())->toBeFalse();
});

it('blocks the legacy guard assertion while the flag is off', function () {
    expect(fn () => app(LegacyRmeFeatureGuard::class)->assertEnabled())
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Server-side only — nothing about a request may influence a flag.
// ---------------------------------------------------------------------------

it('ignores request input when resolving a flag', function () {
    $service = ffResolveThroughConfigCache(null);

    request()->merge([
        'rme.legacy_pdf_archive' => true,
        'feature_flags' => ['rme.legacy_pdf_archive' => true],
        'FEATURE_RME_LEGACY_PDF_ARCHIVE' => 'true',
    ]);

    expect($service->enabled('rme.legacy_pdf_archive'))->toBeFalse()
        ->and(app(LegacyRmeFeatureGuard::class)->enabled())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Non-regression for the flags that already worked.
// ---------------------------------------------------------------------------

it('keeps honouring the lab workflow v2 override that production relies on', function () {
    $service = ffCachedService(['FEATURE_LAB_WORKFLOW_V2' => 'true']);

    expect($service->enabled('lab.workflow_v2'))->toBeTrue()
        ->and($service->get('lab.workflow_v2')['env_resolution'])->toBe('env');
});

it('leaves every flag resolving to its declared default with no environment set', function () {
    // Built from an explicitly cleared environment rather than the ambient one,
    // so the assertion describes the shipped registry and never the machine.
    $env = [];
    foreach (config('feature_flags.flags', []) as $definition) {
        $envKey = $definition['env_key'] ?? '';
        if (is_string($envKey) && $envKey !== '') {
            $env[$envKey] = null;
        }
    }

    foreach (ffCachedService($env)->all() as $key => $flag) {
        expect($flag['enabled'])->toBe($flag['default'], "flag {$key} drifted from its declared default")
            ->and($flag['env_resolution'])->toBe('default');
    }
});
