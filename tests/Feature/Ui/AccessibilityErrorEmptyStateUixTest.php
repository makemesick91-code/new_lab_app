<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

uses()->group('Ui', 'Uix', 'AccessibilityErrorEmptyStateUix');

// ---------------------------------------------------------------------------
// UIX-17 — form fields associate their error text with the input for screen
// readers (aria-describedby + matching id), and expose the invalid state.
// ---------------------------------------------------------------------------

it('associates the input error text via aria-describedby and marks it invalid', function () {
    $html = Blade::render(
        '<x-ui.input name="patient_name" label="Nama" error="Wajib diisi" />'
    );

    expect($html)->toContain('aria-describedby="patient_name-error"');
    expect($html)->toContain('id="patient_name-error"');
    expect($html)->toContain('aria-invalid="true"');
    expect($html)->toContain('Wajib diisi');
});

it('associates the input help text via aria-describedby when there is no error', function () {
    $html = Blade::render(
        '<x-ui.input name="dob" label="Tanggal Lahir" help="Format tahun dari tanggal kunjungan" />'
    );

    expect($html)->toContain('aria-describedby="dob-help"');
    expect($html)->toContain('id="dob-help"');
    expect($html)->not->toContain('aria-invalid');
});

it('marks a required input with a visible marker and aria-required', function () {
    $html = Blade::render('<x-ui.input name="amount" label="Nominal" :required="true" />');

    expect($html)->toContain('aria-required="true"');
    expect($html)->toContain('required');
    expect($html)->toContain('text-danger'); // visible required asterisk
});

// ---------------------------------------------------------------------------
// UIX-17 — select and textarea share the same accessible error association.
// ---------------------------------------------------------------------------

it('associates the select error text via aria-describedby', function () {
    $html = Blade::render(
        '<x-ui.select name="branch_id" label="Cabang" error="Pilih cabang"><option>A</option></x-ui.select>'
    );

    expect($html)->toContain('aria-describedby="branch_id-error"');
    expect($html)->toContain('id="branch_id-error"');
    expect($html)->toContain('aria-invalid="true"');
});

it('associates the textarea help text via aria-describedby', function () {
    $html = Blade::render(
        '<x-ui.textarea name="notes" label="Catatan" help="Opsional" />'
    );

    expect($html)->toContain('aria-describedby="notes-help"');
    expect($html)->toContain('id="notes-help"');
});

// ---------------------------------------------------------------------------
// UIX-17 — button loading state announces itself to assistive tech.
// ---------------------------------------------------------------------------

it('announces the button loading state with aria-busy and an sr-only label', function () {
    $html = Blade::render('<x-ui.button :loading="true">Simpan</x-ui.button>');

    expect($html)->toContain('aria-busy="true"');
    expect($html)->toContain('disabled');
    expect($html)->toContain('sr-only');
});

// ---------------------------------------------------------------------------
// UIX-17 — alert keeps its role; empty-state explains what happened / next.
// ---------------------------------------------------------------------------

it('renders alerts with role=alert so operator messages are announced', function () {
    $html = Blade::render('<x-ui.alert variant="warning">Konsen belum ditandatangani</x-ui.alert>');

    expect($html)->toContain('role="alert"');
});

it('renders an empty state with an explanatory description', function () {
    $html = Blade::render(
        '<x-ui.empty-state title="Belum ada kunjungan" description="Belum ada pasien pada antrian hari ini." />'
    );

    expect($html)->toContain('Belum ada kunjungan');
    expect($html)->toContain('Belum ada pasien pada antrian hari ini.');
});

// ---------------------------------------------------------------------------
// UIX-17 — the UI governance command enforces the accessibility contract.
// ---------------------------------------------------------------------------

it('passes the UIX-17 accessibility rules in the UI governance check', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->not->toContain('UIX-17');
});
