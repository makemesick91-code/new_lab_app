<?php

// Sprint 52 — Inventory Operator Pilot Input Template & Stock Opname Readiness.
// Documentation/history checklist regression over the Sprint 52 operator-template doc + sprint history.
// Template/readiness focused — no Inventory runtime behavior is changed and no stock is mutated.

use Illuminate\Support\Facades\File;

function sprint52InventoryDocPath(): string
{
    return base_path('docs/sprint_52_inventory_operator_pilot_input_template_stock_opname_readiness.md');
}

function sprint52InventoryDoc(): string
{
    return File::get(sprint52InventoryDocPath());
}

function sprint52HistoryDoc(): string
{
    return File::get(base_path('docs/sprint_history.md'));
}

// ---------------------------------------------------------------------------
// File presence + identity
// ---------------------------------------------------------------------------

it('has the Sprint 52 Inventory operator template documentation file', function () {
    expect(File::exists(sprint52InventoryDocPath()))->toBeTrue();
});

it('contains the sprint title, branch, feature tag, future GO tag, base branch and Sprint 51 baseline', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('# Sprint 52 — Inventory Operator Pilot Input Template & Stock Opname Readiness')
        ->and($doc)->toContain('feature/sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness')
        ->and($doc)->toContain('sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness')
        ->and($doc)->toContain('sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go')
        ->and($doc)->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->and($doc)->toContain('ceb2bab');
});

// ---------------------------------------------------------------------------
// Focus + boundary statements
// ---------------------------------------------------------------------------

it('states the Inventory operator-template focus and local/test documentation-only scope', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('Sprint 52 is Inventory operator-template focused.')
        ->and($doc)->toContain('Sprint 52 is local/test documentation and checklist regression only.')
        ->and($doc)->toContain('Sprint 52 prepares templates only and does not execute real stock opname.')
        ->and($doc)->toContain('Sprint 52 does not import real data.');
});

it('states the no-production and no-change safety boundaries', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('No production/VPS/server access.')
        ->and($doc)->toContain('No production database/log/file access.')
        ->and($doc)->toContain('No deployment.')
        ->and($doc)->toContain('No production command execution.')
        ->and($doc)->toContain('No production backup.')
        ->and($doc)->toContain('No production restore.')
        ->and($doc)->toContain('No rollback execution.')
        ->and($doc)->toContain('No `.env` change.')
        ->and($doc)->toContain('No dependency/package install.')
        ->and($doc)->toContain('No migration/schema change.')
        ->and($doc)->toContain('No runtime behavior change.')
        ->and($doc)->toContain('No direct stock mutation.')
        ->and($doc)->toContain('Inventory stock remains ledger-based.');
});

it('preserves the standing guards and closed workstreams', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('RME remains complete/closed for current planning.')
        ->and($doc)->toContain('Pilot Health Check governance loop remains stopped.')
        ->and($doc)->toContain('KTP / ktp_number remains hidden and is not part of Inventory workflow.')
        ->and($doc)->toContain('WhatsApp remains manual-only and is not part of Inventory automation.')
        ->and($doc)->toContain('Zero-remaining receivable rule remains preserved.')
        ->and($doc)->toContain('Overpayment guard remains preserved.')
        ->and($doc)->toContain('Financial rules are not rewritten.');
});

// ---------------------------------------------------------------------------
// Carry-forward + principles + roles + order
// ---------------------------------------------------------------------------

it('documents the Sprint 48-51 carry-forward, operator principle, roles and preparation order', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 7. Sprint 48–51 carry-forward summary')
        ->and($doc)->toContain('## 8. Operator pilot input principle')
        ->and($doc)->toContain('## 10. Data ownership and review roles')
        ->and($doc)->toContain('## 11. Data preparation order');
});

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

it('includes the branch and inventory location template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 12. Branch and inventory location template')
        ->and($doc)->toContain('branch_code,branch_name,location_code,location_name,location_type,is_active,notes')
        ->and($doc)->toContain('MKS,Makassar,GUD-MKS-01,Gudang Utama,warehouse,yes,Dummy example');
});

it('includes the product unit template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 13. Product unit template')
        ->and($doc)->toContain('unit_code,unit_name,is_active,notes')
        ->and($doc)->toContain('PCS,Pieces,yes,Dummy example');
});

it('includes the product category template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 14. Product category template')
        ->and($doc)->toContain('category_code,category_name,is_active,notes')
        ->and($doc)->toContain('ACR,Bahan Acrylic,yes,Dummy example');
});

it('includes the supplier template with dummy data only', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 15. Supplier template')
        ->and($doc)->toContain('supplier_code,supplier_name,contact_name,phone,email,address,is_active,notes')
        ->and($doc)->toContain('SUP-DUMMY-001,PT Contoh Dental Supply')
        ->and($doc)->toContain('Do not include real supplier private contact data in Sprint 52 documentation.');
});

it('includes the product/item template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 16. Product / item template')
        ->and($doc)->toContain('sku,product_name,category_code,unit_code,default_supplier_code,min_stock,is_active,notes')
        ->and($doc)->toContain('ACR-PINK-001,Acrylic Resin Pink,ACR,GRAM,SUP-DUMMY-001,500,yes,Dummy example');
});

it('includes the opening stock template entered through the ledger', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 17. Opening stock template')
        ->and($doc)->toContain('opening_date,branch_code,location_code,sku,qty,unit_code,counted_by,reviewed_by,notes')
        ->and($doc)->toContain('opening stock must be entered through stock movement / ledger');
});

it('includes the stock opname count sheet template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 18. Stock opname count sheet template')
        ->and($doc)->toContain('opname_date,branch_code,location_code,sku,system_qty,physical_qty,difference,unit_code,counted_by,notes')
        ->and($doc)->toContain('difference = physical_qty - system_qty');
});

it('includes the stock opname adjustment review template', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 19. Stock opname adjustment review template')
        ->and($doc)->toContain('opname_date,branch_code,location_code,sku,difference,recommended_action,approved_action,reviewer,approval_status,reason,notes')
        ->and($doc)->toContain('adjustment out must not create negative stock');
});

// ---------------------------------------------------------------------------
// Checklists + boundaries
// ---------------------------------------------------------------------------

it('includes the data validation checklist', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 20. Data validation checklist')
        ->and($doc)->toContain('Required fields are not empty')
        ->and($doc)->toContain('No KTP/patient/WA/credential/token data appears');
});

it('includes the operator input checklist', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 21. Operator input checklist')
        ->and($doc)->toContain('Operator inputs opening stock through movement/ledger')
        ->and($doc)->toContain('Operator does not edit current stock directly');
});

it('includes the reviewer checklist', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 22. Reviewer checklist')
        ->and($doc)->toContain('Reviewer approves adjustment in/out before input')
        ->and($doc)->toContain('Reviewer verifies stock card after adjustment');
});

it('includes the stock opname readiness checklist', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 23. Stock opname readiness checklist')
        ->and($doc)->toContain('Negative stock guard understood')
        ->and($doc)->toContain('No direct stock mutation');
});

it('includes the stock opname execution boundary', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 24. Stock opname execution boundary')
        ->and($doc)->toContain('It does not execute a')
        ->and($doc)->toContain('TYPE_ADJUSTMENT_IN');
});

it('includes the pilot handoff checklist', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 25. Pilot handoff checklist')
        ->and($doc)->toContain('Ready for pilot input decision');
});

it('includes common input mistakes and prevention', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 26. Common input mistakes and prevention');
});

it('includes acceptance criteria for pilot input readiness', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 27. Acceptance criteria for pilot input readiness');
});

it('includes the follow-up recommendation for Sprint 53', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 28. Follow-up recommendation')
        ->and($doc)->toContain('Sprint 53 — Inventory Pilot Data Entry Dry-Run & Template Validation');
});

it('includes the safety confirmation and validation commands', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('## 29. Safety confirmation')
        ->and($doc)->toContain('All example rows must be dummy/example data only.')
        ->and($doc)->toContain('## 30. Validation commands')
        ->and($doc)->toContain('php artisan test --filter=Sprint52InventoryOperatorPilotInputTemplateStockOpnameReadiness');
});

it('confirms no sensitive/real data appears in the documentation', function () {
    $doc = sprint52InventoryDoc();
    expect($doc)->toContain('No real patient data, KTP, WA number, credentials, tokens, `.env`, production logs, database dumps, or real supplier private data.');
});

// ---------------------------------------------------------------------------
// Sprint history regression
// ---------------------------------------------------------------------------

it('records the Sprint 52 entry in the sprint history', function () {
    $history = sprint52HistoryDoc();
    expect($history)->toContain('## Sprint 52 — Inventory Operator Pilot Input Template & Stock Opname Readiness')
        ->and($history)->toContain('feature/sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness')
        ->and($history)->toContain('ceb2bab');
});
