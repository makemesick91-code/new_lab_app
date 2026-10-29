<?php

/**
 * SECURITY-DEPENDENCY-UPDATES-1 — the locked versions of every package patched
 * for a published advisory stay at or above the first fixed release.
 *
 * `composer audit` and `npm audit` answer "is anything known-vulnerable today";
 * they run against a live advisory feed, so CI does not run them on every PR.
 * This test is the offline half: a later `composer update`, a merge resolution
 * or a hand-edited lock that pulls one of these packages back below its fix
 * fails here, with the advisory that the floor exists for.
 */

/** @return array<string, string> package => locked version (leading "v" removed) */
function depFloorComposerLocked(): array
{
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $versions = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        $versions[$package['name']] = ltrim((string) $package['version'], 'v');
    }

    return $versions;
}

/** @return array<string, string> package => locked version */
function depFloorNpmLocked(): array
{
    $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);
    $versions = [];
    foreach ($lock['packages'] ?? [] as $path => $package) {
        if (str_starts_with($path, 'node_modules/') && ! str_contains(substr($path, 13), 'node_modules/')) {
            $versions[substr($path, 13)] = (string) ($package['version'] ?? '');
        }
    }

    return $versions;
}

dataset('composer floors', [
    'laravel/framework (TokenGuard bypass, relationship-inclusion destruction, debug-page XSS, signed-URL path confusion)' => ['laravel/framework', '12.69.0'],
    'guzzlehttp/guzzle (non-canonical host/cookie-domain bypass, Proxy-Authorization leak, cookie scope)' => ['guzzlehttp/guzzle', '7.15.2'],
    'league/commonmark (DoS family, AttributesExtension XSS filter bypass, DisallowedRawHtml bypass)' => ['league/commonmark', '2.10.2'],
    'dompdf/dompdf (SVG local file read, chroot bypass, image resource exhaustion)' => ['dompdf/dompdf', '3.1.6'],
    'league/flysystem (WhitespacePathNormalizer control characters)' => ['league/flysystem', '3.35.3'],
    'symfony/http-foundation (ReDoS in HeaderUtils::split)' => ['symfony/http-foundation', '7.4.19'],
    'symfony/yaml (unbounded inline recursion, dev)' => ['symfony/yaml', '7.4.18'],
]);

dataset('npm floors', [
    'axios (prototype-pollution auth/config gadgets; shipped in the browser bundle)' => ['axios', '1.20.0'],
    'vite (launch-editor argument handling)' => ['vite', '6.4.4'],
    'postcss (source-map path traversal)' => ['postcss', '8.5.23'],
    'shell-quote via concurrently (newline quoting, quadratic parse)' => ['shell-quote', '1.8.5'],
    'follow-redirects via axios (cross-origin credential headers)' => ['follow-redirects', '1.16.1'],
    'form-data via axios (CRLF injection)' => ['form-data', '4.0.6'],
    'nanoid (generator loop)' => ['nanoid', '3.3.18'],
    'browserslist (memory growth, prototype write)' => ['browserslist', '4.28.7'],
    'postcss-selector-parser (selector-parsing DoS)' => ['postcss-selector-parser', '6.1.5'],
    'source-map-js (event-loop DoS)' => ['source-map-js', '1.2.2'],
    'baseline-browser-mapping (process termination on invalid input)' => ['baseline-browser-mapping', '2.11.0'],
]);

it('locks the composer package at or above its first fixed release', function (string $package, string $floor) {
    $locked = depFloorComposerLocked();

    expect($locked)->toHaveKey($package);
    expect(version_compare($locked[$package], $floor, '>='))
        ->toBeTrue("{$package} is locked at {$locked[$package]}, below the fixed release {$floor}");
})->with('composer floors');

it('locks the npm package at or above its first fixed release', function (string $package, string $floor) {
    $locked = depFloorNpmLocked();

    expect($locked)->toHaveKey($package);
    expect(version_compare($locked[$package], $floor, '>='))
        ->toBeTrue("{$package} is locked at {$locked[$package]}, below the fixed release {$floor}");
})->with('npm floors');

it('keeps every patched package on the major version the application was built for', function () {
    $composer = depFloorComposerLocked();
    $npm = depFloorNpmLocked();

    // A security update here is a patch or minor release; a major upgrade is a
    // separate decision with its own review.
    foreach (['laravel/framework' => '12', 'guzzlehttp/guzzle' => '7', 'league/commonmark' => '2', 'dompdf/dompdf' => '3', 'league/flysystem' => '3', 'symfony/http-foundation' => '7'] as $package => $major) {
        expect(explode('.', $composer[$package])[0])->toBe($major, "{$package} left major {$major}");
    }
    foreach (['axios' => '1', 'vite' => '6', 'postcss' => '8'] as $package => $major) {
        expect(explode('.', $npm[$package])[0])->toBe($major, "{$package} left major {$major}");
    }
});
