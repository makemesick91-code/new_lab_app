<?php

/**
 * UIX-7 — Lab pipeline polish. Presentation-only; the Lab pipeline scan surfaces
 * (lab order list/detail, RME case candidates, production board/detail, QC
 * queue/detail, delivery queue/detail) adopt the DaengtisiaMS design system via
 * x-ui.* + the shared x-lab.status-badge component + semantic tokens. No LabOrder
 * lifecycle / RME→Lab candidate generation / payment / invoice logic changes, and
 * no controller/service/query/permission/policy/BranchContext/route/schema change.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'UiFoundation', 'Lab');

// ---------------------------------------------------------------------------
// Shared x-lab.status-badge maps lab statuses to semantic tones (no logic).
// ---------------------------------------------------------------------------

it('maps lab lifecycle statuses to semantic tones', function () {
    expect(Blade::render('<x-lab.status-badge status="QC_PASSED" />'))->toContain('text-success-700');
    expect(Blade::render('<x-lab.status-badge status="ON_HOLD" />'))->toContain('text-warning-700');
    expect(Blade::render('<x-lab.status-badge status="CANCELLED" />'))->toContain('text-danger-700');
    expect(Blade::render('<x-lab.status-badge status="IN_PRODUCTION" />'))->toContain('text-info-700');
});

it('maps priority and QC results to semantic tones', function () {
    expect(Blade::render('<x-lab.status-badge status="SUPER_URGENT" />'))->toContain('text-danger-700');
    expect(Blade::render('<x-lab.status-badge status="URGENT" />'))->toContain('text-warning-700');
    expect(Blade::render('<x-lab.status-badge status="PASS" />'))->toContain('text-success-700');
    expect(Blade::render('<x-lab.status-badge status="FAIL" />'))->toContain('text-danger-700');
});

it('renders Indonesian labels for lab statuses', function () {
    expect(Blade::render('<x-lab.status-badge status="IN_DELIVERY" />'))->toContain('Dalam Pengiriman');
    expect(Blade::render('<x-lab.status-badge status="REMAKE" />'))->toContain('Perbaikan');
});

// ---------------------------------------------------------------------------
// Lab routes still authorize (no logic regression — guests redirected).
// ---------------------------------------------------------------------------

it('redirects guests to login for lab pipeline pages', function () {
    $this->get(route('lab-orders.index'))->assertRedirect(route('login'));
    $this->get(route('lab-case-candidates.index'))->assertRedirect(route('login'));
    $this->get(route('production.board'))->assertRedirect(route('login'));
    $this->get(route('quality-control.queue'))->assertRedirect(route('login'));
    $this->get(route('deliveries.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Reference surfaces adopt the design-system components.
// ---------------------------------------------------------------------------

it('uses the list standard on the lab order index', function () {
    $html = file_get_contents(base_path('resources/views/lab-orders/index.blade.php'));

    foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-lab.status-badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
        expect($html)->toContain($component);
    }
    expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
});

it('uses page-header, card and status badge on the lab detail surfaces', function () {
    foreach ([
        'resources/views/lab-orders/show.blade.php',
        'resources/views/production/show.blade.php',
        'resources/views/quality-control/show.blade.php',
        'resources/views/deliveries/show.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->toContain('x-ui.page-header');
        expect($html)->toContain('x-lab.status-badge');
        expect($html)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
    }
});

it('never renders full KTP/NIK across the polished lab views', function () {
    foreach ([
        'resources/views/lab-orders/index.blade.php',
        'resources/views/lab-orders/show.blade.php',
        'resources/views/lab/case-candidates/index.blade.php',
        'resources/views/lab/case-candidates/show.blade.php',
        'resources/views/production/board.blade.php',
        'resources/views/production/show.blade.php',
        'resources/views/quality-control/queue.blade.php',
        'resources/views/quality-control/show.blade.php',
        'resources/views/deliveries/index.blade.php',
        'resources/views/deliveries/show.blade.php',
    ] as $file) {
        $html = file_get_contents(base_path($file));
        expect($html)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    }
});

// ---------------------------------------------------------------------------
// Governance stays GO with the UIX-7 rules applied.
// ---------------------------------------------------------------------------

it('passes the UI governance check under strict mode', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
});
