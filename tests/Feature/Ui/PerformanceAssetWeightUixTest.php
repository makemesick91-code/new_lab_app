<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'Uix', 'PerformanceAssetWeightUix');

// ---------------------------------------------------------------------------
// UIX-18 — Performance & asset-weight guardrails.
// The UI foundation is Blade + Tailwind + Alpine only. These tests keep the
// frontend dependency footprint and built-bundle weight low, and enforce the
// same rules through the read-only UI governance command.
// ---------------------------------------------------------------------------

/**
 * Heavy / SPA / chart / datatable / admin-template libraries that must never
 * be declared without explicit approval. Mirrors the governance command list.
 */
function uix18ForbiddenFrontendDeps(): array
{
    return [
        'react', 'react-dom', 'preact', 'vue', '@vue/runtime-dom', 'svelte',
        '@angular/core', 'jquery', 'bootstrap',
        'chart.js', 'chartjs', 'apexcharts', 'echarts', 'highcharts', 'plotly.js',
        'datatables.net', 'datatables.net-dt', 'ag-grid-community', 'handsontable',
        'moment', 'select2',
        '@tailwindcss/vite',
    ];
}

it('declares no heavy frontend dependency in package.json', function () {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    expect($package)->toBeArray();

    $declared = array_merge(
        array_keys($package['dependencies'] ?? []),
        array_keys($package['devDependencies'] ?? []),
    );

    foreach (uix18ForbiddenFrontendDeps() as $dep) {
        expect($declared)->not->toContain($dep);
    }
});

it('does not carry the unused Tailwind v4 vite plugin alongside Tailwind v3', function () {
    // UIX-18 removed @tailwindcss/vite: the app builds on Tailwind v3 via postcss,
    // so the v4 plugin only pulled an unused ~7MB native oxide toolchain.
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);
    $declared = array_merge(
        array_keys($package['dependencies'] ?? []),
        array_keys($package['devDependencies'] ?? []),
    );

    expect($declared)->not->toContain('@tailwindcss/vite');
    // Tailwind v3 is still present and drives the build.
    expect($declared)->toContain('tailwindcss');
});

it('ships no CDN <script src="http..."> injection in the x-ui components', function () {
    foreach (glob(base_path('resources/views/components/ui/*.blade.php')) as $component) {
        $body = (string) file_get_contents($component);
        expect(preg_match('/<script[^>]+src\s*=\s*["\']https?:/i', $body))
            ->toBe(0, 'CDN script injection found in '.basename($component));
    }
});

it('imports no chart / datatable / SPA framework in the JS entrypoint', function () {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    foreach (['from \'react\'', 'from "react"', 'from \'vue\'', 'from "vue"',
        'chart.js', 'datatables', 'apexcharts', 'jquery'] as $needle) {
        expect(stripos($js, $needle))->toBeFalse();
    }
});

it('keeps the built asset bundle within the weight budget when built', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! is_file($manifestPath)) {
        // Assets not built in this environment; the budget is enforced wherever
        // `npm run build` has run (local + VPS deploy). Nothing to assert here.
        expect(true)->toBeTrue();

        return;
    }

    $totalBytes = 0;
    foreach (glob(public_path('build/assets/*')) as $asset) {
        $totalBytes += (int) filesize($asset);
    }

    // Generous ceiling (~512KB raw) — the current lightweight bundle is ~182KB.
    // This guards against a heavy library ballooning the bundle, without being
    // brittle to normal growth.
    expect($totalBytes)->toBeLessThan(512 * 1024);
});

it('passes the UIX-18 performance/asset rules in the UI governance check', function () {
    // Assert on the rule channels (errors/warnings), not on raw output. The
    // command also emits an environment advisory naming UIX-18 when assets are
    // not built here — that is the same environment-scoped condition the budget
    // test above skips on, not a rule violation, so a raw substring match would
    // make this test depend on whether `npm run build` happened to have run.
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($report['decision'])->toBe('GO');

    foreach ([...$report['errors'], ...$report['warnings']] as $entry) {
        expect($entry)->not->toContain('UIX-18');
    }
});
