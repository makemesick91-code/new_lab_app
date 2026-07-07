<?php

/**
 * UIX-8 — Reports, print & PDF polish. Presentation-only; the polished report
 * screens (RME patient/payment reports, the inventory reports hub + batch report
 * indexes, and the reporting-module payments report) adopt the DaengtisiaMS design
 * system via x-ui.* + semantic tokens, and the RME report print templates are
 * retinted to brand blue. No report calculation / receivable / payment / stock
 * valuation / KPI logic changes, and no controller/service/query/permission/
 * policy/BranchContext/route/schema change. KTP/NIK are never rendered in any
 * report, print, or export view.
 */

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Report');

// ---------------------------------------------------------------------------
// Report routes still authorize (no logic regression — guests redirected, not 500).
// ---------------------------------------------------------------------------

it('redirects guests to login for report pages instead of erroring', function () {
    $this->get(route('rme.reports.patients'))->assertRedirect(route('login'));
    $this->get(route('rme.reports.payments'))->assertRedirect(route('login'));
    $this->get(route('reports.payments'))->assertRedirect(route('login'));
    $this->get(route('inventory.reports.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// RME report screens are the reference report list pages (UIX-3 list standard).
// ---------------------------------------------------------------------------

it('uses the list standard on the RME report screens', function () {
    foreach ([
        'resources/views/rme/reports/patients.blade.php',
        'resources/views/rme/reports/payments.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
            expect($html)->toContain($component);
        }
        expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
    }
});

it('uses the page header on the inventory and reporting-module report pages', function () {
    foreach ([
        'resources/views/inventory/reports/index.blade.php',
        'resources/views/inventory/reports/batch-disposals/index.blade.php',
        'resources/views/inventory/reports/batch-monthly-closing/index.blade.php',
        'resources/views/reports/payments.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->toContain('x-ui.page-header');
        expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
    }
});

// ---------------------------------------------------------------------------
// Print/PDF templates are retinted to brand blue and stay table-safe.
// ---------------------------------------------------------------------------

it('retints RME report print templates to brand blue', function () {
    foreach ([
        'resources/views/rme/reports/print/patients.blade.php',
        'resources/views/rme/reports/print/payments.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->toContain('#1D4ED8');
        expect(strtolower($html))->not->toContain('#0f766e');
    }
});

it('keeps print/pdf templates table-based, not flexbox-driven, for the data grid', function () {
    foreach ([
        'resources/views/inventory/reports/room-stock/refill-checklist.blade.php',
        'resources/views/inventory/stock-transfers/checklist-pdf.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->toContain('<table');
        expect(strtolower($html))->not->toContain('#0f766e');
    }
});

// ---------------------------------------------------------------------------
// KTP/NIK privacy across the polished report + print surfaces.
// ---------------------------------------------------------------------------

it('never renders full KTP/NIK across the polished report and print views', function () {
    foreach ([
        'resources/views/rme/reports/patients.blade.php',
        'resources/views/rme/reports/payments.blade.php',
        'resources/views/rme/reports/print/patients.blade.php',
        'resources/views/rme/reports/print/payments.blade.php',
        'resources/views/inventory/reports/index.blade.php',
        'resources/views/reports/payments.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }
});

// ---------------------------------------------------------------------------
// Governance stays GO with the UIX-8 rules applied.
// ---------------------------------------------------------------------------

it('passes the UI governance check under strict mode', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
});
