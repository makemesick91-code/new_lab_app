<?php

/**
 * UIX-22 — Enterprise-clean UI/UX final closure. Governance/docs/test only: closes the
 * UIX-6 → UIX-21 UI foundation series, records the closure state durably, and proves every
 * prior UIX rule/marker is preserved (none removed or weakened). No controller/service/
 * route/permission/policy/Gate/Spatie/BranchContext/business-logic change; no view restyled;
 * Blade + Tailwind + Alpine only.
 */

use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Uix');

// ---------------------------------------------------------------------------
// Governance gate — the closed foundation still passes strict on a clean tree.
// ---------------------------------------------------------------------------

it('passes the UI governance check with the UIX-22 closure in place', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--strict' => true]);

    expect($exit)->toBe(0);
});

// ---------------------------------------------------------------------------
// Closure — every UIX-6 → UIX-21 sprint evidence doc is preserved.
// ---------------------------------------------------------------------------

it('preserves every UIX-6 through UIX-21 sprint evidence doc', function () {
    $docs = [
        'uix-6-inventory-polish.md',
        'uix-7-lab-pipeline-polish.md',
        'uix-8-reports-print-pdf-polish.md',
        'uix-9-inventory-analytics-workflow-forms-polish.md',
        'uix-10-rme-visit-queue-patient-workspace-polish.md',
        'uix-11-rme-medical-record-odontogram-print-bundle-polish.md',
        'uix-12-rme-cashier-receivable-follow-up-polish.md',
        'uix-13-owner-dashboard-kpi-polish.md',
        'uix-14-settings-master-data-access-control-polish.md',
        'uix-15-global-component-foundation-hardening.md',
        'uix-16-responsive-tablet-operator-smoke-polish.md',
        'uix-17-accessibility-error-empty-state-polish.md',
        'uix-18-performance-asset-weight-audit.md',
        'uix-19-navigation-sidebar-information-architecture-polish.md',
        'uix-20-permission-aware-ui-consistency-polish.md',
        'uix-21-ui-rules-enforcement-governance-lock.md',
    ];

    foreach ($docs as $doc) {
        expect(is_file(base_path('docs/sprints/'.$doc)))
            ->toBeTrue("Missing UIX foundation-series evidence doc: docs/sprints/{$doc}");
    }
});

// ---------------------------------------------------------------------------
// Closure — the UIX-22 closure evidence doc exists.
// ---------------------------------------------------------------------------

it('adds the UIX-22 closure evidence doc', function () {
    expect(is_file(base_path('docs/sprints/uix-22-enterprise-clean-uiux-final-closure.md')))
        ->toBeTrue();
});

// ---------------------------------------------------------------------------
// Preservation — every UIX-6 → UIX-22 rule sentinel remains in the governance command.
// ---------------------------------------------------------------------------

it('preserves the UIX-6 through UIX-22 governance rules', function () {
    $command = file_get_contents(base_path('app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php'));

    foreach (['UIX-6', 'UIX-7', 'UIX-8', 'UIX-9', 'UIX-10', 'UIX-11', 'UIX-12', 'UIX-13', 'UIX-14', 'UIX-15', 'UIX-16', 'UIX-17', 'UIX-18', 'UIX-19', 'UIX-20', 'UIX-21', 'UIX-22'] as $marker) {
        expect($command)->toContain($marker);
    }

    // Core invariants stay enforced (none weakened by the closure).
    expect($command)->toContain('Forbidden heavy frontend dependency');   // UIX-18 dep guard
    expect($command)->toContain('divide-hairline');                       // UIX-15 table token
    expect($command)->toContain('aria-describedby');                      // UIX-17 a11y
    expect($command)->toContain('restricted-notice');                     // UIX-20 permission-aware
    expect($command)->toContain('CDN <script');                           // UIX-21 app-shell CDN lock
});

// ---------------------------------------------------------------------------
// Documentation lock — the closure state and honest disclaimer are captured durably.
// ---------------------------------------------------------------------------

it('documents the UIX-22 closure and keeps the honest-limitation disclaimer', function () {
    $designDoc = file_get_contents(base_path('docs/ui_design_system.md'));
    expect($designDoc)->toContain('UIX-22');
    expect($designDoc)->toContain('Inventory Sprint 68.45');

    $govDoc = file_get_contents(base_path('docs/ui/daengtisiams-ui-governance.md'));
    expect($govDoc)->toContain('UIX-22');
    expect($govDoc)->toContain('No formal WCAG');
});
