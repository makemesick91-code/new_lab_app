<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'GlobalComponentFoundationUix');

// ---------------------------------------------------------------------------
// UIX-15 — x-ui.badge is case-insensitive and covers canonical statuses.
// ---------------------------------------------------------------------------

it('resolves badge status case-insensitively for uppercase invoice codes', function () {
    // Uppercase lifecycle codes (invoice PAID/UNPAID/VOID/PARTIAL) previously fell
    // through to the neutral tone; UIX-15 resolves them to their semantic tone.
    expect(Blade::render('<x-ui.badge status="PAID" />'))->toContain('text-success-700');
    expect(Blade::render('<x-ui.badge status="UNPAID" />'))->toContain('text-warning-700');
    expect(Blade::render('<x-ui.badge status="VOID" />'))->toContain('text-danger-700');
    expect(Blade::render('<x-ui.badge status="PARTIAL" />'))->toContain('text-warning-700');
});

it('keeps the existing lowercase badge status mappings (backward compatible)', function () {
    expect(Blade::render('<x-ui.badge status="paid" />'))->toContain('text-success-700');
    expect(Blade::render('<x-ui.badge status="cashier_pending" />'))->toContain('text-gold-700');
    expect(Blade::render('<x-ui.badge status="cancelled" />'))->toContain('text-danger-700');
    expect(Blade::render('<x-ui.badge status="in_progress" />'))->toContain('text-info-700');
});

it('maps the new canonical procurement/lifecycle statuses', function () {
    expect(Blade::render('<x-ui.badge status="submitted" />'))->toContain('text-info-700');
    expect(Blade::render('<x-ui.badge status="posted" />'))->toContain('text-success-700');
    expect(Blade::render('<x-ui.badge status="received" />'))->toContain('text-success-700');
    expect(Blade::render('<x-ui.badge status="registered" />'))->toContain('text-info-700');
});

it('falls back to the neutral tone for an unknown badge status', function () {
    expect(Blade::render('<x-ui.badge status="totally_unknown" />'))->toContain('text-ink-soft');
});

it('still honours the explicit tone prop (no status)', function () {
    expect(Blade::render('<x-ui.badge tone="danger">X</x-ui.badge>'))->toContain('text-danger-700');
});

// ---------------------------------------------------------------------------
// UIX-15 — x-ui.table uses semantic tokens (no legacy gray drift).
// ---------------------------------------------------------------------------

it('renders the table foundation on semantic tokens, not legacy gray', function () {
    $html = Blade::render('<x-ui.table><tbody><tr><td>a</td></tr></tbody></x-ui.table>');

    expect($html)->toContain('divide-hairline');
    expect($html)->not->toContain('divide-gray-200');
});

// ---------------------------------------------------------------------------
// UIX-15 — domain badge components route through the x-ui.badge foundation.
// ---------------------------------------------------------------------------

it('renders the lab status badge through the x-ui.badge foundation', function () {
    $html = Blade::render('<x-lab.status-badge status="DELIVERED" />');

    // Domain component maps DELIVERED -> success tone and Indonesian label,
    // but the visual tone comes from the shared x-ui.badge foundation.
    expect($html)->toContain('text-success-700');
    expect($html)->toContain('Terkirim');
});

// ---------------------------------------------------------------------------
// UIX-15 — governance command stays GO under --strict.
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO under strict (UIX-15)', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
