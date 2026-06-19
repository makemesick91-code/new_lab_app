<?php

// Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness.
// Documentation + checklist regression test only — validates the Sprint 48 Inventory audit
// documentation, empty-database setup workflow, smoke-test readiness checklist, defect register,
// and the sprint history entry. No Inventory runtime behavior is exercised or changed.

use Illuminate\Support\Facades\File;

function sprint48InventoryDocPath(): string
{
    return base_path('docs/sprint_48_inventory_workflow_audit_empty_database_setup_smoke_test_readiness.md');
}

function sprint48InventoryDoc(): string
{
    return File::get(sprint48InventoryDocPath());
}

function sprint48HistoryDoc(): string
{
    return File::get(base_path('docs/sprint_history.md'));
}

it('has the Sprint 48 Inventory documentation file', function () {
    expect(File::exists(sprint48InventoryDocPath()))->toBeTrue();
});

it('contains the sprint title, branch, feature tag, future GO tag and Sprint 47 baseline', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('# Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness')
        ->and($doc)->toContain('feature/sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness')
        ->and($doc)->toContain('sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness')
        ->and($doc)->toContain('sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go')
        ->and($doc)->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->and($doc)->toContain('cd16bf5');
});

it('states the Inventory focus, RME closure and stopped governance loop', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('Sprint 48 is Inventory-focused.')
        ->and($doc)->toContain('RME is considered complete/closed for current planning.')
        ->and($doc)->toContain('Sprint 48 stops the Pilot Health Check governance loop.');
});

it('states it is local controlled audit plus documentation plus checklist regression test only', function () {
    expect(sprint48InventoryDoc())
        ->toContain('This sprint is local controlled audit + documentation + checklist regression test only.');
});

it('includes the Inventory module map', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 8. Inventory module map')
        ->and($doc)->toContain('app/Modules/Inventory/')
        ->and($doc)->toContain('InventoryStockService')
        ->and($doc)->toContain('InventoryMovement')
        ->and($doc)->toContain('view_inventory')
        ->and($doc)->toContain('manage_inventory');
});

it('includes the empty-database setup principle and ledger formula', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 9. Empty-database setup principle')
        ->and($doc)->toContain('current stock = stock in - stock out');
});

it('includes required initial data and initial setup order', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 10. Required initial data')
        ->and($doc)->toContain('## 11. Initial setup order')
        ->and($doc)->toContain('Input opening stock through stock movement.');
});

it('includes branch / user / role / permission prerequisites', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 12. Branch / user / role / permission prerequisites')
        ->and($doc)->toContain('Cross-branch stock leakage must be rejected.');
});

it('includes master data setup sections for location, unit, category, supplier and product', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 13. Inventory location setup')
        ->and($doc)->toContain('## 14. Product unit setup')
        ->and($doc)->toContain('## 15. Product category setup')
        ->and($doc)->toContain('## 16. Supplier setup')
        ->and($doc)->toContain('## 17. Product / item setup');
});

it('includes the opening stock workflow', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 18. Opening stock workflow')
        ->and($doc)->toContain('Opening stock must not directly update a `current_stock` column.');
});

it('includes stock receive, usage/out and adjustment in/out workflows', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 19. Stock receive workflow')
        ->and($doc)->toContain('## 20. Stock usage / stock out workflow')
        ->and($doc)->toContain('## 21. Adjustment in workflow')
        ->and($doc)->toContain('## 22. Adjustment out workflow')
        ->and($doc)->toContain('Adjustment out must not make stock negative.');
});

it('includes stock card, current stock and low-stock monitoring', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 23. Stock card workflow')
        ->and($doc)->toContain('## 24. Current stock calculation')
        ->and($doc)->toContain('Current Stock = Total Stock In - Total Stock Out')
        ->and($doc)->toContain('## 25. Low-stock monitoring')
        ->and($doc)->toContain('Low stock appears when current stock <= minimum stock.');
});

it('includes the stock opname workflow', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 26. Stock opname workflow')
        ->and($doc)->toContain('If physical greater: adjustment in.')
        ->and($doc)->toContain('If physical lower: adjustment out.');
});

it('includes branch-aware/location-aware rules, access control and inactive guards', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 28. Branch-aware and location-aware rules')
        ->and($doc)->toContain('## 29. Permission and access control checks')
        ->and($doc)->toContain('## 30. Inactive product/location rules')
        ->and($doc)->toContain('Inactive product cannot be used for a new movement.')
        ->and($doc)->toContain('Inactive location cannot be used for a new movement.');
});

it('includes the smoke-test readiness checklist', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 31. Smoke-test readiness checklist')
        ->and($doc)->toContain('User without Inventory permission cannot access Inventory.')
        ->and($doc)->toContain('Adjustment out is rejected if stock is insufficient.')
        ->and($doc)->toContain('Branch A stock does not leak to Branch B.')
        ->and($doc)->toContain('No direct stock mutation is required.');
});

it('includes the empty-database seed/input templates', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 32. Empty-database seed/input templates')
        ->and($doc)->toContain('### Product Unit Template')
        ->and($doc)->toContain('### Product Template')
        ->and($doc)->toContain('### Opening Stock Template');
});

it('includes the defect/risk register candidates', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 33. Defect/risk register candidates')
        ->and($doc)->toContain('Cross-branch stock leakage.')
        ->and($doc)->toContain('Sprint 49 — Inventory Bugfix Batch 1 & Workflow Stabilization');
});

it('states no direct stock mutation and that stock changes go through movements/ledger entries', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('No direct stock mutation.')
        ->and($doc)->toContain('Inventory stock must be changed through stock movements / ledger entries.');
});

it('forbids deployment, production access and unsafe operational actions', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('No deployment.')
        ->and($doc)->toContain('No VPS/production/server access.')
        ->and($doc)->toContain('No production database/log/file access.')
        ->and($doc)->toContain('No production command execution.')
        ->and($doc)->toContain('No production backup.')
        ->and($doc)->toContain('No production restore.')
        ->and($doc)->toContain('No rollback execution.')
        ->and($doc)->toContain('No `.env` change.')
        ->and($doc)->toContain('No dependency/package install.')
        ->and($doc)->toContain('No migration/schema change.')
        ->and($doc)->toContain('No runtime behavior change.');
});

it('preserves KTP hidden, WhatsApp manual-only, zero-remaining receivable and overpayment guard', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('KTP / `ktp_number` remains hidden and is not part of Inventory workflow.')
        ->and($doc)->toContain('WhatsApp remains manual-only and is not part of Inventory automation.')
        ->and($doc)->toContain('Zero-remaining receivable rule remains preserved.')
        ->and($doc)->toContain('Overpayment guard remains preserved.')
        ->and($doc)->toContain('Financial rules are not rewritten.');
});

it('includes validation commands', function () {
    $doc = sprint48InventoryDoc();
    expect($doc)->toContain('## 35. Validation commands')
        ->and($doc)->toContain('php artisan test --filter=Sprint48InventoryWorkflowAuditEmptyDatabaseSetupSmokeTestReadiness')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('records a Sprint 48 entry in the sprint history with validation references', function () {
    $history = sprint48HistoryDoc();
    expect($history)->toContain('## Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness')
        ->and($history)->toContain('Sprint 47 GO / `cd16bf5`')
        ->and($history)->toContain('Pilot Health Check governance loop is')
        ->and($history)->toContain('php artisan test --filter=Sprint48InventoryWorkflowAuditEmptyDatabaseSetupSmokeTestReadiness');
});
