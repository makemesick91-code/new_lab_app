<?php

it('documents Sprint 29 Phase 29.3 WhatsApp reminder manual pilot SOP', function (): void {
    $path = base_path('docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 29 Phase 29.3',
        'WhatsApp Reminder Manual Pilot SOP',
        '266a0d2',
        'Baseline:** Sprint 29.2 GO at `266a0d2`',
        'Sprint 29.2 GO at `266a0d2`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists the required SOP sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md'));

    foreach ([
        'Manual WhatsApp reminder scope',
        'Appointment reminder manual SOP',
        'Receivable / piutang follow-up manual SOP',
        'Cashier/payment/receivable guardrails',
        'RME control visit connected guardrails',
        'Privacy and consent rules',
        'Approved manual message templates',
        'Manual log template',
        'Escalation rules',
        'Manual pilot daily checklist',
        'Future automation readiness criteria',
        'Out-of-scope implementation list',
        'Risk and mitigation',
        'GO/NO-GO decision',
        'Safety confirmation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the no-automation safety statements', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md'));

    foreach ([
        'No WhatsApp API integration',
        'No WhatsApp automation',
        'No queue/job/notification change',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the required privacy statements', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md'));

    foreach ([
        'Do not expose No. KTP in WhatsApp messages',
        'Do not expose unnecessary clinical notes',
        'Use approved clinic phone/account only',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the required receivable safeguards', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md'));

    foreach ([
        'Active receivable follow-up is only for remaining balance > 0',
        'Rp0 invoice must not be followed up as active receivable',
        'Fully paid invoice must not be followed up as active receivable',
        'Disputed balance must be escalated, not silently fixed',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.3 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.3',
        'docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md',
        'Sprint 29.2 GO at `266a0d2`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
