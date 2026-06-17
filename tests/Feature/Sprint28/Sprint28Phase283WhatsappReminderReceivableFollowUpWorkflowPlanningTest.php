<?php

it('documents Sprint 28 Phase 28.3 WhatsApp reminder and receivable follow-up workflow planning', function (): void {
    $path = base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.3',
        'WhatsApp Reminder & Receivable Follow-up Workflow Planning',
        'Baseline:** Sprint 28.2 GO at `05539ef`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the appointment reminder workflow content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Appointment Reminder Workflow',
        'control visit schedule',
        'Patient WA number',
        'Operator/admin',
        'H-1 reminder, same-day reminder, missed appointment follow-up',
        'Confirmed, rescheduled, no response, wrong number, cancelled',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the receivable follow-up workflow content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Receivable Follow-up Workflow',
        'Active receivable with remaining balance > 0',
        'Rp0 invoice must not be included in active receivable follow-up',
        'preserve FIFO payment allocation',
        'Cashier/admin',
        'Gentle reminder, due follow-up, overdue follow-up',
        'Promised to pay, paid, partial payment, no response, dispute, wrong number',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME control workflow safety notes', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'RME Control Workflow Safety Notes',
        'same patient/RM but create a new visit',
        'Old RME/odontogram/invoice must not be overwritten',
        'Parent receivable can remain visible/payable in cashier control',
        'Payment allocation remains FIFO previous receivable first',
        'Parent receivable does not block control completion',
        'Rp0 invoice does not appear in active receivables or the follow-up queue',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes privacy-safe message template drafts', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Message Template Drafts',
        'Appointment reminder H-1',
        'Same-day appointment reminder',
        'Missed appointment follow-up',
        'Receivable gentle reminder',
        'Receivable due follow-up',
        'Payment received confirmation',
        'Do not include No. KTP',
        '[Nama Klinik]',
        '[Nama Operator]',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the operator and support planning checklists', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Operator / Cashier Handling Checklist',
        'Verify patient identity.',
        'Verify WA number.',
        'Send approved message only.',
        'Record response status.',
        'Support / Admin Planning Checklist',
        'Review data source candidates.',
        'Review privacy/sensitive data boundaries.',
        'Review future automation risks.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the manual log format', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Manual Log Format',
        'Sender role/name',
        'WA number verification status',
        'Workflow type',
        'Message template used',
        'Response status',
        'Follow-up date',
        'Amount reference if receivable-related',
        'Escalation owner',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes future automation candidate design and risk mitigation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'Future Automation Candidate Design',
        'Reminder queue candidate.',
        'Receivable follow-up queue candidate.',
        'Consent/opt-out candidate.',
        'Risk and Mitigation',
        'Wrong recipient',
        'Sensitive data leakage',
        'Rp0 invoice accidentally followed up',
        'FIFO/payment allocation misunderstanding',
        'GO / NO-GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('declares no production, migration, deploy, integration or destructive operation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md'));

    foreach ([
        'No deployment',
        'No migration',
        'No production code change',
        'No WhatsApp/API integration',
        'No destructive data operation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 28 Phase 28.3 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.3',
        'docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md',
        'Sprint 28.2 GO at `05539ef`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
