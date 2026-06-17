<?php

use Illuminate\Support\Facades\File;

it('confirms sprint 27 phase 27.5 closure document exists and includes required operator checklist', function () {
    $path = base_path('docs/sprint_27_phase_27_5_rme_control_workflow_stabilization_regression_closure.md');

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('Operator Checklist')
        ->toContain('Cara daftar kontrol')
        ->toContain('Cara buat billing kontrol gratis')
        ->toContain('Cara bayar cicilan tagihan lama dari halaman kontrol')
        ->toContain('Cara cek status visit completed')
        ->toContain('Cara cek piutang aktif')
        ->toContain('Cara cek receipt')
        ->toContain('Cara export piutang');
});

it('confirms closure document lists final control visit business rules', function () {
    $content = File::get(base_path('docs/sprint_27_phase_27_5_rme_control_workflow_stabilization_regression_closure.md'));

    expect($content)
        ->toContain('Final Business Rules for Control Workflow')
        ->toContain('same patient/RM identity')
        ->toContain('Every control still creates a new visit')
        ->toContain('must not overwrite the old visit')
        ->toContain('Payment allocation uses FIFO')
        ->toContain('Previous receivables are not blockers')
        ->toContain('Invoice control gratis Rp0 must not appear in active receivables')
        ->toContain('Active receivables must include only invoices with positive remaining balance');
});

it('confirms closure document covers free control paid control carry-over allocation zero receivable exclusion receipt export and manual vps smoke', function () {
    $content = File::get(base_path('docs/sprint_27_phase_27_5_rme_control_workflow_stabilization_regression_closure.md'));

    expect($content)
        ->toContain('Kontrol gratis tanpa parent receivable')
        ->toContain('Kontrol gratis dengan parent `UNPAID`')
        ->toContain('Kontrol gratis dengan parent `PARTIAL`')
        ->toContain('Kontrol berbiaya tambahan dengan parent `PARTIAL`')
        ->toContain('Kontrol berbiaya tambahan dengan parent `PAID`')
        ->toContain('Parent receivable still has remaining > 0')
        ->toContain('Invoice kontrol Rp0')
        ->toContain('Export piutang')
        ->toContain('Receipt after split allocation')
        ->toContain('Manual smoke checklist')
        ->toContain('VPS deployment notes')
        ->toContain('No Migration Expected');
});
