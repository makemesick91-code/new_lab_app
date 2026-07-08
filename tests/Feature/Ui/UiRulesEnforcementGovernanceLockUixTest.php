<?php

/**
 * UIX-21 — UI/UX rules enforcement & governance lock. Governance-only: locks the
 * UIX-6 → UIX-20 UI foundation behind three additive, non-brittle enforcement
 * rules and preserves every prior rule. No controller/service/route/permission/
 * policy/Gate/Spatie/BranchContext/business-logic change; no view restyled;
 * Blade + Tailwind + Alpine only.
 */

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Uix');

// ---------------------------------------------------------------------------
// Governance gate — the lock passes strict on a clean tree.
// ---------------------------------------------------------------------------

it('passes the UI governance check with the UIX-21 lock rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--strict' => true]);

    expect($exit)->toBe(0);
});

// ---------------------------------------------------------------------------
// H1 — vite.config framework-plugin lock (Blade + Alpine only build).
// ---------------------------------------------------------------------------

it('keeps the vite build free of React/Vue/Svelte/Angular/Solid plugins', function () {
    $config = file_get_contents(base_path('vite.config.js'));

    foreach (['@vitejs/plugin-react', '@vitejs/plugin-vue', 'plugin-svelte', 'plugin-solid', '@analogjs/'] as $plugin) {
        expect($config)->not->toContain($plugin);
    }
    // The Vite build stays on the Laravel plugin.
    expect($config)->toContain('laravel-vite-plugin');
});

// ---------------------------------------------------------------------------
// H2 — no CDN <script src="http..."> in the navigation/layout shell foundation.
// ---------------------------------------------------------------------------

it('keeps the app shell free of CDN script injection', function () {
    $shellFiles = [
        'resources/views/layouts/app.blade.php',
        'resources/views/layouts/sidebar.blade.php',
        'resources/views/layouts/partials/sidebar.blade.php',
        'resources/views/layouts/partials/topbar.blade.php',
    ];

    foreach ($shellFiles as $file) {
        $path = base_path($file);
        if (! is_file($path)) {
            continue;
        }
        expect((bool) preg_match('/<script[^>]+src\s*=\s*["\']https?:/i', file_get_contents($path)))
            ->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// H3 — x-ui.card action group keeps flex-wrap (responsive action guarantee).
// ---------------------------------------------------------------------------

it('keeps the x-ui.card action group wrapping on narrow widths', function () {
    $card = file_get_contents(base_path('resources/views/components/ui/card.blade.php'));

    expect($card)->toContain('$actions');
    expect($card)->toContain('flex-wrap');
});

// ---------------------------------------------------------------------------
// Preservation — UIX-6 → UIX-20 rule sentinels remain in the governance command.
// ---------------------------------------------------------------------------

it('preserves the UIX-6 through UIX-20 governance rules', function () {
    $command = file_get_contents(base_path('app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php'));

    // Each prior sprint block must still be present (none removed/weakened).
    foreach (['UIX-6', 'UIX-7', 'UIX-8', 'UIX-9', 'UIX-10', 'UIX-11', 'UIX-12', 'UIX-13', 'UIX-14', 'UIX-15', 'UIX-16', 'UIX-17', 'UIX-18', 'UIX-19', 'UIX-20', 'UIX-21'] as $marker) {
        expect($command)->toContain($marker);
    }

    // Core invariants stay enforced.
    expect($command)->toContain('Forbidden heavy frontend dependency');   // UIX-18 dep guard
    expect($command)->toContain('divide-hairline');                       // UIX-15 table token
    expect($command)->toContain('aria-describedby');                      // UIX-17 a11y
    expect($command)->toContain('restricted-notice');                     // UIX-20 permission-aware
});

// ---------------------------------------------------------------------------
// Documentation lock — the enforced standard is captured durably.
// ---------------------------------------------------------------------------

it('documents the UIX-21 lock and keeps the honest-limitation disclaimer', function () {
    expect(file_get_contents(base_path('docs/ui_design_system.md')))->toContain('UIX-21');
    expect(file_get_contents(base_path('docs/ui/daengtisiams-ui-governance.md')))
        ->toContain('No formal WCAG');
    expect(is_file(base_path('docs/sprints/uix-21-ui-rules-enforcement-governance-lock.md')))
        ->toBeTrue();
});
