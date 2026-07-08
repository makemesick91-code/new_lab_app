<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'Uix', 'ResponsiveOperatorUix');

// ---------------------------------------------------------------------------
// UIX-16 — foundation table keeps its overflow scroll container.
// ---------------------------------------------------------------------------

it('renders the table foundation inside an overflow-x-auto scroll container', function () {
    $html = Blade::render('<x-ui.table><tbody><tr><td>a</td></tr></tbody></x-ui.table>');

    expect($html)->toContain('overflow-x-auto');
});

// ---------------------------------------------------------------------------
// UIX-16 — filter-bar stacks on narrow, goes horizontal at md, and wraps actions.
// ---------------------------------------------------------------------------

it('stacks the filter bar and wraps its action group', function () {
    $html = Blade::render(
        '<x-ui.filter-bar><input /><x-slot:actions><button>Go</button></x-slot:actions></x-ui.filter-bar>'
    );

    expect($html)->toContain('flex-col');
    expect($html)->toContain('md:flex-row');
    expect($html)->toContain('flex-wrap');
});

// ---------------------------------------------------------------------------
// UIX-16 — page-header stacks on narrow, goes horizontal at sm, and wraps actions.
// ---------------------------------------------------------------------------

it('stacks the page header and wraps its action group', function () {
    $html = Blade::render(
        '<x-ui.page-header title="X"><x-slot:actions><button>A</button></x-slot:actions></x-ui.page-header>'
    );

    expect($html)->toContain('flex-col');
    expect($html)->toContain('sm:flex-row');
    expect($html)->toContain('flex-wrap');
});

// ---------------------------------------------------------------------------
// UIX-16 — representative operator surfaces keep stacking detail grids
// (grid-cols-1 base) and carry no fixed non-stacking text-sm detail grid.
// ---------------------------------------------------------------------------

it('keeps responsive stacking detail grids on the representative operator surfaces', function () {
    $pages = [
        'resources/views/rme/cashier/show.blade.php',
        'resources/views/rme/cashier/payment/create.blade.php',
        'resources/views/inventory/products/index.blade.php',
        'resources/views/inventory/stock/card.blade.php',
        'resources/views/lab/case-candidates/show.blade.php',
    ];

    foreach ($pages as $page) {
        $contents = file_get_contents(base_path($page));

        // Stacking grid present (grid-cols-1 base that widens from sm up).
        expect($contents)->toMatch('/grid-cols-1[^"]*sm:grid-cols-[23]/');

        // No fixed non-stacking text-sm detail grid remains.
        expect(preg_match('/grid grid-cols-[23] gap-[^"]*text-sm/', $contents))->toBe(0);
    }
});

// ---------------------------------------------------------------------------
// UIX-16 — governance command stays GO under --strict.
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO under strict (UIX-16)', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
