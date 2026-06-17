<?php

use Illuminate\Support\Facades\File;

it('confirms sprint 27 phase 27.7 final closure document exists and records go no-go posture', function () {
    $path = base_path('docs/sprint_27_phase_27_7_rme_control_workflow_final_closure_go_no_go_report.md');

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('Sprint 27 Phase 27.7 — RME Control Workflow Final Closure & Sprint 27 GO/NO-GO Report')
        ->toContain('Final closure, report-only, GO/NO-GO documentation')
        ->toContain('GO CANDIDATE FOR PR REVIEW')
        ->toContain('Final Sprint 27 GO is allowed only after')
        ->toContain('No deployment in Phase 27.7')
        ->toContain('No migration')
        ->toContain('No production code change');
});

it('confirms sprint 27 phase 27.7 document preserves final rme control workflow business rules', function () {
    $content = File::get(base_path('docs/sprint_27_phase_27_7_rme_control_workflow_final_closure_go_no_go_report.md'));

    expect($content)
        ->toContain('Pasien kontrol memakai pasien/RM yang sama')
        ->toContain('Setiap kontrol tetap membuat visit baru')
        ->toContain('Control visit tidak boleh overwrite visit lama')
        ->toContain('Payment allocation FIFO')
        ->toContain('Parent receivable tidak menjadi blocker status control visit')
        ->toContain('Free control')
        ->toContain('Paid control')
        ->toContain('Invoice kontrol gratis Rp0 tidak boleh muncul di active receivables')
        ->toContain('Active receivables hanya invoice dengan remaining > 0')
        ->toContain('Receipt harus menampilkan allocation');
});

it('confirms sprint 27 phase 27.7 document includes phase anchors tags validation and final go conditions', function () {
    $content = File::get(base_path('docs/sprint_27_phase_27_7_rme_control_workflow_final_closure_go_no_go_report.md'));

    expect($content)
        ->toContain('Phase 27.3')
        ->toContain('Phase 27.4')
        ->toContain('Phase 27.4.1')
        ->toContain('Phase 27.4.2')
        ->toContain('Phase 27.5')
        ->toContain('Phase 27.6')
        ->toContain('f74ad78')
        ->toContain('e8cbb8a')
        ->toContain('82155c8')
        ->toContain('b908722')
        ->toContain('sprint-27-phase-27-7-rme-control-workflow-final-closure-go-no-go-report-go')
        ->toContain('sprint-27-rme-control-workflow-go')
        ->toContain('Focused Validation Plan');
});

it('confirms sprint history includes sprint 27 phase 27.7 closure summary', function () {
    $content = File::get(base_path('docs/sprint_history.md'));

    expect($content)
        ->toContain('Sprint 27 Phase 27.7 — RME Control Workflow Final Closure & Sprint 27 GO/NO-GO Report')
        ->toContain('docs/sprint_27_phase_27_7_rme_control_workflow_final_closure_go_no_go_report.md')
        ->toContain('GO CANDIDATE FOR PR REVIEW')
        ->toContain('No deployment, no migration, no destructive data operation, and no production code change');
});
