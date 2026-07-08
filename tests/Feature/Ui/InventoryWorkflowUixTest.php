<?php

/**
 * UIX-9 — Inventory analytics, charts & workflow forms polish. Presentation-only;
 * the deferred inventory analytics/executive dashboards and the procurement/opname
 * workflow form + detail surfaces adopt the DaengtisiaMS design system via x-ui.* +
 * x-inventory.* + semantic tokens. No inventory ledger / SUM-movement stock
 * calculation / stock-card / low-stock / valuation / batch expiry / PR-PO-GR /
 * transfer / stock-opname lifecycle logic changes, and no controller/service/query/
 * permission/policy/BranchContext/route/schema change. Stock stays ledger-derived.
 */

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Inventory');

// ---------------------------------------------------------------------------
// Routes still authorize (no logic regression — guests redirected, not 500).
// ---------------------------------------------------------------------------

it('redirects guests to login for the inventory analytics and workflow pages', function () {
    $this->get(route('inventory.analytics.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.executive-dashboard'))->assertRedirect(route('login'));
    $this->get(route('inventory.products.create'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Analytics + executive dashboard are the reference analytics/chart pages.
// ---------------------------------------------------------------------------

it('uses the design system on the analytics reference pages', function () {
    foreach ([
        'resources/views/inventory/analytics/index.blade.php',
        'resources/views/inventory/executive-dashboard.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        foreach (['x-ui.page-header', 'x-inventory.kpi-card'] as $component) {
            expect($html)->toContain($component);
        }
        expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
    }

    $analytics = file_get_contents(base_path('resources/views/inventory/analytics/index.blade.php'));
    foreach (['x-ui.card', 'x-ui.alert', 'x-ui.button'] as $component) {
        expect($analytics)->toContain($component);
    }
});

// ---------------------------------------------------------------------------
// Purchase order show is the reference workflow-detail page.
// ---------------------------------------------------------------------------

it('uses the design system on the workflow detail reference page', function () {
    $html = file_get_contents(base_path('resources/views/inventory/purchase-orders/show.blade.php'));
    foreach (['x-ui.page-header', 'x-ui.button', 'x-ui.alert'] as $component) {
        expect($html)->toContain($component);
    }
    expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
});

// ---------------------------------------------------------------------------
// Product form + GR create + opname create are the reference workflow forms.
// ---------------------------------------------------------------------------

it('uses the form components on the workflow form reference surfaces', function () {
    $productForm = file_get_contents(base_path('resources/views/inventory/products/_form.blade.php'));
    foreach (['x-ui.input', 'x-ui.select', 'x-ui.textarea'] as $component) {
        expect($productForm)->toContain($component);
    }

    foreach ([
        'resources/views/inventory/goods-receipts/create.blade.php',
        'resources/views/inventory/stock-opnames/create.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.button'] as $component) {
            expect($html)->toContain($component);
        }
    }
});

// ---------------------------------------------------------------------------
// Ledger-derivation guard: no mutable stock attribute assignment, no gold CTA.
// ---------------------------------------------------------------------------

it('keeps stock ledger-derived and gold accent-only across polished workflow views', function () {
    $files = [
        'resources/views/inventory/analytics/index.blade.php',
        'resources/views/inventory/executive-dashboard.blade.php',
        'resources/views/inventory/purchase-orders/show.blade.php',
        'resources/views/inventory/goods-receipts/create.blade.php',
        'resources/views/inventory/stock-opnames/create.blade.php',
        'resources/views/inventory/products/_form.blade.php',
        'resources/views/inventory/products/show.blade.php',
        'resources/views/inventory/batches/show.blade.php',
    ];
    foreach ($files as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->not->toMatch('/->(?:current_stock|derived_stock|stock_quantity|quantity_on_hand|stock_on_hand)\s*=(?!=)/');
        expect($html)->not->toContain('variant="gold"');
    }
});

// ---------------------------------------------------------------------------
// Governance command is satisfied (non-brittle, config-level).
// ---------------------------------------------------------------------------

it('passes the UI governance check for the UIX-9 surfaces', function () {
    $exit = Artisan::call('architecture:ui-governance-check');
    expect($exit)->toBe(0);
});
