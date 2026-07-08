<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * UIX-1 — Luxury Healthcare Design System governance check (read-only).
 *
 * Lightweight, non-brittle verification that the UI foundation is present and
 * that the "gold is never a primary CTA" rule holds in the foundation button
 * component. Never mutates files/data and never touches the network.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - FAIL  → non-zero always
 *  - WATCH → 0 by default; non-zero with --strict
 */
class ArchitectureUiGovernanceCheckCommand extends Command
{
    protected $signature = 'architecture:ui-governance-check
        {--json : Output JSON report}
        {--strict : Exit non-zero on warnings as well}';

    protected $description = 'Read-only UIX-1 design-system governance check (docs, tokens, x-ui components, gold-CTA rule).';

    public function handle(): int
    {
        $base = base_path();

        $requiredDocs = [
            'docs/ui/daengtisiams-design-canvas.html',
            'docs/ui/daengtisiams-developer-guide.html',
            'docs/ui/daengtisiams-ui-foundation.md',
            'docs/ui/daengtisiams-design-tokens.md',
            'docs/ui/daengtisiams-ui-governance.md',
            'docs/ui/daengtisiams-implementation-checklist.md',
        ];

        $requiredComponents = [
            'button', 'card', 'badge', 'table', 'input', 'select', 'textarea',
            'alert', 'modal', 'empty-state', 'skeleton', 'page-header',
            'filter-bar', 'kpi-card',
        ];

        $requiredTokens = ['brand', 'gold', 'navy', 'canvas', 'hairline'];

        $errors = [];
        $warnings = [];

        foreach ($requiredDocs as $doc) {
            if (! is_file($base.'/'.$doc)) {
                $errors[] = "Missing UI doc: {$doc}";
            }
        }

        foreach ($requiredComponents as $c) {
            if (! is_file($base."/resources/views/components/ui/{$c}.blade.php")) {
                $errors[] = "Missing x-ui component: {$c}";
            }
        }

        $tailwind = @file_get_contents($base.'/tailwind.config.js') ?: '';
        foreach ($requiredTokens as $token) {
            if (! str_contains($tailwind, $token.':') && ! str_contains($tailwind, "{$token} ")) {
                $errors[] = "Missing design token in tailwind.config.js: {$token}";
            }
        }

        // Gold must never be the primary CTA: the button component's `primary`
        // variant line must not reference gold.
        $button = @file_get_contents($base.'/resources/views/components/ui/button.blade.php') ?: '';
        foreach (preg_split('/\R/', $button) as $line) {
            if (str_contains($line, "'primary' =>") && stripos($line, 'gold') !== false) {
                $errors[] = 'Gold used as primary CTA in ui/button.blade.php (forbidden).';
            }
        }

        // Governance doc should state the gold-not-CTA rule (soft signal).
        $govDoc = @file_get_contents($base.'/docs/ui/daengtisiams-ui-governance.md') ?: '';
        if (stripos($govDoc, 'gold') === false) {
            $warnings[] = 'UI governance doc does not mention gold usage rules.';
        }

        // --- UIX-2 — Owner Dashboard polish rules (lightweight, non-brittle). ---
        // These files must exist and stay on the design system (no legacy teal
        // brand color, x-ui.kpi-card adopted, gold reserved as accent only).
        $ownerDashboardFiles = [
            'resources/views/dashboard.blade.php',
            'resources/views/dashboards/owner-kpi.blade.php',
        ];

        foreach ($ownerDashboardFiles as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing owner dashboard view: {$file}";
            }
        }

        $ownerKpiView = @file_get_contents($base.'/resources/views/dashboards/owner-kpi.blade.php') ?: '';

        // Owner KPI block must use the x-ui.kpi-card foundation component.
        if ($ownerKpiView !== '' && ! str_contains($ownerKpiView, 'x-ui.kpi-card')) {
            $errors[] = 'Owner KPI dashboard does not use the x-ui.kpi-card component.';
        }

        // No legacy teal brand color may be reintroduced in owner dashboard files
        // (UIX-1 migrated the brand color teal → blue).
        foreach ($ownerDashboardFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents !== '' && preg_match('/\b(?:bg|text|border|ring)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
        }

        // Gold must stay an accent, never a button/CTA variant, in the owner KPI view.
        if ($ownerKpiView !== '' && str_contains($ownerKpiView, 'variant="gold"')) {
            $errors[] = 'Gold used as a button/CTA in owner-kpi.blade.php (gold is accent-only).';
        }

        // UIX-2 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-2-dashboard-owner-polish.md')) {
            $warnings[] = 'UIX-2 sprint evidence doc is missing (docs/sprints/uix-2-dashboard-owner-polish.md).';
        }

        // --- UIX-13 — Owner Dashboard & KPI polish rules (lightweight, non-brittle). ---
        // The owner landing view + branch-admin dashboard must stay on the design
        // system: no legacy palette, hand-rolled branch-summary tables replaced by
        // x-ui.table + x-ui.empty-state, and the read-only branch filter on x-ui.select.
        $uix13DashboardFiles = [
            'resources/views/dashboard.blade.php',
            'resources/views/dashboards/branch-admin.blade.php',
        ];

        foreach ($uix13DashboardFiles as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing dashboard view: {$file}";
            }
        }

        $ownerLandingView = @file_get_contents($base.'/resources/views/dashboard.blade.php') ?: '';

        // Owner landing branch drilldown summaries must use foundation table +
        // empty-state components (no hand-rolled dashboard tables/empty states).
        if ($ownerLandingView !== '' && ! str_contains($ownerLandingView, 'x-ui.table')) {
            $errors[] = 'Owner dashboard branch summaries do not use the x-ui.table component.';
        }
        if ($ownerLandingView !== '' && ! str_contains($ownerLandingView, 'x-ui.empty-state')) {
            $errors[] = 'Owner dashboard does not use the x-ui.empty-state component for no-data states.';
        }
        // Read-only branch/period filter must use the x-ui.select foundation control.
        if ($ownerLandingView !== '' && ! str_contains($ownerLandingView, 'x-ui.select')) {
            $errors[] = 'Owner dashboard branch filter does not use the x-ui.select component.';
        }

        // No legacy palette / hardcoded hex may be reintroduced in these views.
        foreach ($uix13DashboardFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring)-(?:teal|emerald|amber|rose|sky)-\d/', $contents)) {
                $errors[] = "Legacy palette class found in {$file} (use semantic tokens).";
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-gray-\d/', $contents)) {
                $errors[] = "Legacy gray class found in {$file} (use navy/ink/hairline tokens).";
            }
            if (preg_match('/#[0-9a-fA-F]{6}\b/', $contents)) {
                $errors[] = "Hardcoded hex color found in {$file} (use semantic tokens).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only).";
            }
        }

        // Dashboard stays read-only — no create/update/delete form verbs may appear.
        foreach ($uix13DashboardFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/method=["\'](?:POST|PUT|PATCH|DELETE)["\']/i', $contents)
                || preg_match('/@method\(["\'](?:PUT|PATCH|DELETE)["\']\)/i', $contents)) {
                $errors[] = "Read-only dashboard {$file} contains a mutating form (dashboard must stay read-only).";
            }
        }

        // No PII / KTP / NIK may be rendered on the owner/branch dashboards.
        foreach ($uix13DashboardFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents !== '' && preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Sensitive identifier (KTP/NIK) rendered in {$file} (must never be exposed).";
            }
        }

        // UIX-13 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-13-owner-dashboard-kpi-polish.md')) {
            $warnings[] = 'UIX-13 sprint evidence doc is missing (docs/sprints/uix-13-owner-dashboard-kpi-polish.md).';
        }

        // --- UIX-3 — Kunjungan list is the reference list page (lightweight). ---
        // Standard list pages must be built on x-ui.* foundation components and
        // semantic tokens (no legacy teal brand color, no hardcoded hex color).
        $visitsIndex = 'resources/views/rme/visits/index.blade.php';
        if (! is_file($base.'/'.$visitsIndex)) {
            $errors[] = "Missing Kunjungan list view: {$visitsIndex}";
        } else {
            $view = @file_get_contents($base.'/'.$visitsIndex) ?: '';

            $requiredListComponents = [
                'x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table',
                'x-ui.badge', 'x-ui.button', 'x-ui.empty-state',
            ];
            foreach ($requiredListComponents as $component) {
                if (! str_contains($view, $component)) {
                    $errors[] = "Kunjungan list view does not use the {$component} component.";
                }
            }

            // Status badges must resolve tone via the design-system :status map.
            if (! str_contains($view, ':status')) {
                $errors[] = 'Kunjungan list status badge does not use the x-ui.badge :status map.';
            }

            // No legacy teal brand color (UIX-1 migrated teal → blue).
            if (preg_match('/\b(?:bg|text|border|ring)-teal-\d/', $view)) {
                $errors[] = "Legacy teal brand class found in {$visitsIndex} (use brand/token classes).";
            }

            // No hardcoded hex colors in the reference list page.
            if (preg_match('/#[0-9a-fA-F]{3,6}\b/', $view)) {
                $errors[] = "Hardcoded hex color found in {$visitsIndex} (use semantic tokens).";
            }
        }

        // UIX-3 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-3-kunjungan-list-polish.md')) {
            $warnings[] = 'UIX-3 sprint evidence doc is missing (docs/sprints/uix-3-kunjungan-list-polish.md).';
        }

        // --- UIX-4 — RME + Odontogram are the reference clinical pages. ---
        // Clinical detail/odontogram pages must stay on x-ui.* + semantic tokens,
        // must not reintroduce legacy teal or hardcoded hex, must not use gold as a
        // CTA, and must never render KTP/NIK on the clinical detail surface.
        $rmeDetailView = 'resources/views/rme/visits/show.blade.php';
        $odontogramView = 'resources/views/rme/visits/odontogram/show.blade.php';

        foreach ([$rmeDetailView, $odontogramView] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing clinical view: {$file}";
            }
        }

        $rmeDetail = @file_get_contents($base.'/'.$rmeDetailView) ?: '';
        if ($rmeDetail !== '') {
            // RME detail must be built on the adopted foundation components.
            foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.badge', 'x-ui.button', 'x-ui.alert'] as $component) {
                if (! str_contains($rmeDetail, $component)) {
                    $errors[] = "RME detail view does not use the {$component} component.";
                }
            }
            // Status badge must resolve tone via the design-system :status map.
            if (! str_contains($rmeDetail, ':status')) {
                $errors[] = 'RME detail status badge does not use the x-ui.badge :status map.';
            }
        }

        // No legacy teal / hardcoded hex / gold-CTA in the clinical pages, and no
        // KTP/NIK field ever echoed on the clinical detail surface (privacy).
        foreach ([$rmeDetailView, $odontogramView] as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (preg_match('/#[0-9a-fA-F]{3,6}\b/', $contents)) {
                $errors[] = "Hardcoded hex color found in {$file} (use semantic tokens).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never a clinical action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Sensitive KTP/NIK field rendered in {$file} (forbidden on clinical detail surface).";
            }
        }

        // UIX-4 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-4-rme-odontogram-polish.md')) {
            $warnings[] = 'UIX-4 sprint evidence doc is missing (docs/sprints/uix-4-rme-odontogram-polish.md).';
        }

        // --- UIX-5 — Kasir / Payment is the reference financial workflow page. ---
        // Cashier/payment pages must stay on x-ui.* + semantic tokens, must not
        // reintroduce legacy teal, must never use gold as a CTA, and must never
        // render KTP/NIK on a cashier/payment surface (privacy). This is
        // presentation-only governance — no payment/consent/receivable logic here.
        $cashierIndexView = 'resources/views/rme/cashier/index.blade.php';
        $cashierPaymentView = 'resources/views/rme/cashier/payment/create.blade.php';

        foreach ([$cashierIndexView, $cashierPaymentView] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing cashier/payment view: {$file}";
            }
        }

        // Cashier list is a reference list page (UIX-3 list standard).
        $cashierIndex = @file_get_contents($base.'/'.$cashierIndexView) ?: '';
        if ($cashierIndex !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($cashierIndex, $component)) {
                    $errors[] = "Cashier list view does not use the {$component} component.";
                }
            }
        }

        // Payment detail must be built on the adopted foundation components,
        // including the consent-gate alert.
        $cashierPayment = @file_get_contents($base.'/'.$cashierPaymentView) ?: '';
        if ($cashierPayment !== '') {
            foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.badge', 'x-ui.button', 'x-ui.alert'] as $component) {
                if (! str_contains($cashierPayment, $component)) {
                    $errors[] = "Cashier payment view does not use the {$component} component.";
                }
            }
        }

        // No legacy teal / gold-CTA / KTP-NIK across the polished cashier surfaces.
        // (The shared clinical-summary partial is intentionally excluded; it keeps
        // neutral gray clinical labels per the UIX-4 precedent. Hex is not scanned
        // because the receipt requires print-only `background: #fff`.)
        $cashierFiles = [
            'resources/views/rme/cashier/index.blade.php',
            'resources/views/rme/cashier/show.blade.php',
            'resources/views/rme/cashier/create.blade.php',
            'resources/views/rme/cashier/payment/create.blade.php',
            'resources/views/rme/cashier/receipt/show.blade.php',
            'resources/views/rme/cashier/receivables.blade.php',
            'resources/views/rme/cashier/handoff.blade.php',
            'resources/views/rme/cashier/follow-ups/create.blade.php',
        ];
        foreach ($cashierFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never a payment action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Sensitive KTP/NIK field rendered in {$file} (forbidden on cashier/payment surface).";
            }
        }

        // UIX-5 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-5-kasir-payment-polish.md')) {
            $warnings[] = 'UIX-5 sprint evidence doc is missing (docs/sprints/uix-5-kasir-payment-polish.md).';
        }

        // --- UIX-12 — RME cashier / receivable / follow-up financial polish. ---
        // Presentation-only: the standardized financial summary card
        // (x-rme.invoice-summary → total / paid / remaining / status) is the
        // canonical way to surface an RME invoice's money state. It must be reused
        // on the invoice detail and payment surfaces, must never render KTP/NIK,
        // must not use legacy teal or a gold CTA, and must never recompute a
        // payment/receivable/invoice-status value. No payment/consent/receivable/
        // carry-over logic is asserted here.
        $invoiceSummaryComponent = 'resources/views/components/rme/invoice-summary.blade.php';
        if (! is_file($base.'/'.$invoiceSummaryComponent)) {
            $errors[] = "Missing RME financial summary component: {$invoiceSummaryComponent}";
        } else {
            $summaryView = @file_get_contents($base.'/'.$invoiceSummaryComponent) ?: '';
            // The summary reads existing accessors, never writes a mutable state.
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $summaryView)) {
                $errors[] = "Legacy teal brand class found in {$invoiceSummaryComponent} (use brand/token classes).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $summaryView)) {
                $errors[] = "Sensitive KTP/NIK field rendered in {$invoiceSummaryComponent} (forbidden on financial surface).";
            }
        }

        // The invoice detail + payment surfaces must reuse the shared summary.
        foreach ([
            'resources/views/rme/cashier/show.blade.php',
            'resources/views/rme/cashier/payment/create.blade.php',
        ] as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents !== '' && ! str_contains($contents, 'x-rme.invoice-summary')) {
                $errors[] = "Financial surface {$file} does not use the x-rme.invoice-summary card.";
            }
        }

        // UIX-12 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-12-rme-cashier-receivable-follow-up-polish.md')) {
            $warnings[] = 'UIX-12 sprint evidence doc is missing (docs/sprints/uix-12-rme-cashier-receivable-follow-up-polish.md).';
        }

        // --- UIX-6 — Inventory pages adopt the shared design system. ---
        // Presentation-only governance: inventory scan surfaces must stay on
        // x-ui.* + semantic tokens, must not reintroduce legacy teal, must never
        // use gold as a CTA/status, and must never introduce a mutable stock
        // attribute assignment (stock stays ledger-derived). No ledger, stock,
        // procurement, transfer, or opname business logic is asserted here.
        $inventoryProductsIndex = 'resources/views/inventory/products/index.blade.php';
        $inventoryStockCard = 'resources/views/inventory/stock/card.blade.php';

        foreach ([$inventoryProductsIndex, $inventoryStockCard] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing inventory view: {$file}";
            }
        }

        // Product list is the reference inventory list page (UIX-3 list standard).
        $inventoryProducts = @file_get_contents($base.'/'.$inventoryProductsIndex) ?: '';
        if ($inventoryProducts !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($inventoryProducts, $component)) {
                    $errors[] = "Inventory product list view does not use the {$component} component.";
                }
            }
        }

        // Stock card is the reference ledger-derived detail page.
        $inventoryCard = @file_get_contents($base.'/'.$inventoryStockCard) ?: '';
        if ($inventoryCard !== '') {
            foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.badge', 'x-ui.table'] as $component) {
                if (! str_contains($inventoryCard, $component)) {
                    $errors[] = "Inventory stock card view does not use the {$component} component.";
                }
            }
        }

        // No legacy teal / gold-CTA / mutable-stock assignment across the polished
        // inventory scan surfaces.
        $inventoryFiles = [
            'resources/views/inventory/dashboard.blade.php',
            'resources/views/inventory/products/index.blade.php',
            'resources/views/inventory/stock/index.blade.php',
            'resources/views/inventory/stock/card.blade.php',
            'resources/views/inventory/alerts/index.blade.php',
            'resources/views/inventory/batches/index.blade.php',
            'resources/views/inventory/purchase-requests/index.blade.php',
            'resources/views/inventory/purchase-orders/index.blade.php',
            'resources/views/inventory/goods-receipts/index.blade.php',
            'resources/views/inventory/stock-transfers/index.blade.php',
            'resources/views/inventory/stock-opnames/index.blade.php',
        ];
        foreach ($inventoryFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never an inventory action or status).";
            }
            // Stock must remain ledger-derived — no mutable stock attribute write
            // in a presentation view.
            if (preg_match('/->(?:current_stock|derived_stock|stock_quantity|quantity_on_hand|stock_on_hand)\s*=(?!=)/', $contents)) {
                $errors[] = "Mutable stock attribute assignment found in {$file} (stock stays ledger-derived).";
            }
        }

        // UIX-6 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-6-inventory-polish.md')) {
            $warnings[] = 'UIX-6 sprint evidence doc is missing (docs/sprints/uix-6-inventory-polish.md).';
        }

        // --- UIX-7 — Lab pipeline pages adopt the shared design system. ---
        // Presentation-only governance: the Lab pipeline scan surfaces (order list/
        // detail, RME case candidates, production, QC, delivery) must stay on
        // x-ui.* + the shared x-lab.status-badge + semantic tokens, must not
        // reintroduce legacy teal, must never use gold as a CTA, and must never
        // render full KTP/NIK. No LabOrder lifecycle, RME→Lab candidate generation,
        // payment, or invoice business logic is asserted here.
        $labStatusBadge = 'resources/views/components/lab/status-badge.blade.php';
        if (! is_file($base.'/'.$labStatusBadge)) {
            $errors[] = "Missing shared lab status badge component: {$labStatusBadge}";
        }

        $labOrderIndex = 'resources/views/lab-orders/index.blade.php';
        $labOrderShow = 'resources/views/lab-orders/show.blade.php';

        foreach ([$labOrderIndex, $labOrderShow] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing lab view: {$file}";
            }
        }

        // Lab order list is the reference lab list page (UIX-3 list standard).
        $labIndexContents = @file_get_contents($base.'/'.$labOrderIndex) ?: '';
        if ($labIndexContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-lab.status-badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($labIndexContents, $component)) {
                    $errors[] = "Lab order list view does not use the {$component} component.";
                }
            }
        }

        // Lab order detail is the reference lab detail page.
        $labShowContents = @file_get_contents($base.'/'.$labOrderShow) ?: '';
        if ($labShowContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.button', 'x-lab.status-badge'] as $component) {
                if (! str_contains($labShowContents, $component)) {
                    $errors[] = "Lab order detail view does not use the {$component} component.";
                }
            }
        }

        // No legacy teal / gold-CTA / rendered KTP across the polished lab surfaces.
        // (Hex is intentionally not scanned — the delivery signature pad keeps a JS
        // canvas ink color; the UIX-5 precedent also skips hex for the same reason.)
        $labFiles = [
            'resources/views/lab-orders/index.blade.php',
            'resources/views/lab-orders/show.blade.php',
            'resources/views/lab-orders/create.blade.php',
            'resources/views/lab-orders/edit.blade.php',
            'resources/views/lab-orders/_form.blade.php',
            'resources/views/lab/case-candidates/index.blade.php',
            'resources/views/lab/case-candidates/show.blade.php',
            'resources/views/production/board.blade.php',
            'resources/views/production/show.blade.php',
            'resources/views/production/work-logs.blade.php',
            'resources/views/quality-control/queue.blade.php',
            'resources/views/quality-control/show.blade.php',
            'resources/views/deliveries/index.blade.php',
            'resources/views/deliveries/show.blade.php',
        ];
        foreach ($labFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never a lab action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Full KTP/NIK rendered in {$file} (identity numbers must never be shown in lab views).";
            }
        }

        // UIX-7 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-7-lab-pipeline-polish.md')) {
            $warnings[] = 'UIX-7 sprint evidence doc is missing (docs/sprints/uix-7-lab-pipeline-polish.md).';
        }

        // --- UIX-8 — Reports, print & PDF surfaces adopt the shared design system. ---
        // Presentation-only governance: the polished report screens must stay on
        // x-ui.* + semantic tokens, must not reintroduce legacy teal, must never use
        // gold as a CTA, and must never render full KTP/NIK anywhere in a report,
        // print, or export view. No report calculation, receivable, payment, stock
        // valuation, or KPI business logic is asserted here.
        $rmePatientsReport = 'resources/views/rme/reports/patients.blade.php';
        $rmePaymentsReport = 'resources/views/rme/reports/payments.blade.php';
        $inventoryReportsIndex = 'resources/views/inventory/reports/index.blade.php';

        foreach ([$rmePatientsReport, $rmePaymentsReport, $inventoryReportsIndex] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing report view: {$file}";
            }
        }

        // RME report screens are the reference report list pages (UIX-3 list standard).
        foreach ([$rmePatientsReport, $rmePaymentsReport] as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($contents, $component)) {
                    $errors[] = "RME report view {$file} does not use the {$component} component.";
                }
            }
        }

        // Inventory reports hub is the reference inventory report page.
        $inventoryReports = @file_get_contents($base.'/'.$inventoryReportsIndex) ?: '';
        if ($inventoryReports !== '' && ! str_contains($inventoryReports, 'x-ui.page-header')) {
            $errors[] = 'Inventory reports view does not use the x-ui.page-header component.';
        }

        // No legacy teal / gold-CTA / rendered KTP across the polished report and
        // print surfaces. (Hex is intentionally not scanned — the print/PDF templates
        // keep inline brand hex; the UIX-5 receipt precedent skips hex the same way.)
        $reportFiles = [
            'resources/views/rme/reports/patients.blade.php',
            'resources/views/rme/reports/payments.blade.php',
            'resources/views/rme/reports/print/patients.blade.php',
            'resources/views/rme/reports/print/payments.blade.php',
            'resources/views/inventory/reports/index.blade.php',
            'resources/views/inventory/reports/batch-disposals/index.blade.php',
            'resources/views/inventory/reports/batch-monthly-closing/index.blade.php',
            'resources/views/inventory/reports/room-stock/refill-checklist.blade.php',
            'resources/views/reports/payments.blade.php',
        ];
        foreach ($reportFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never a report action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Full KTP/NIK rendered in {$file} (identity numbers must never be shown in report/print/export views).";
            }
        }

        // UIX-8 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-8-reports-print-pdf-polish.md')) {
            $warnings[] = 'UIX-8 sprint evidence doc is missing (docs/sprints/uix-8-reports-print-pdf-polish.md).';
        }

        // --- UIX-9 — Inventory analytics, charts & workflow forms adopt the shared design system. ---
        // Presentation-only governance for the deferred inventory analytics/chart and
        // workflow form/detail surfaces. The polished references must stay on x-ui.* +
        // x-inventory.* + semantic tokens, must not reintroduce legacy teal, must never
        // use gold as a CTA, and must never introduce a mutable stock attribute
        // assignment (stock stays ledger-derived). No ledger, stock, procurement,
        // transfer, opname, batch, or analytics calculation is asserted here.
        $analyticsIndex = 'resources/views/inventory/analytics/index.blade.php';
        $executiveDashboard = 'resources/views/inventory/executive-dashboard.blade.php';
        $poShow = 'resources/views/inventory/purchase-orders/show.blade.php';
        $productForm = 'resources/views/inventory/products/_form.blade.php';

        foreach ([$analyticsIndex, $executiveDashboard, $poShow, $productForm] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing inventory UIX-9 view: {$file}";
            }
        }

        // Analytics index + executive dashboard are the reference analytics/chart pages
        // (both build cards from x-ui.page-header + x-inventory.kpi-card).
        foreach ([$analyticsIndex, $executiveDashboard] as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            foreach (['x-ui.page-header', 'x-inventory.kpi-card'] as $component) {
                if (! str_contains($contents, $component)) {
                    $errors[] = "Inventory analytics view {$file} does not use the {$component} component.";
                }
            }
        }

        // Analytics index also wraps its summary block in the shared x-ui.card.
        $analyticsContents = @file_get_contents($base.'/'.$analyticsIndex) ?: '';
        if ($analyticsContents !== '' && ! str_contains($analyticsContents, 'x-ui.card')) {
            $errors[] = "Inventory analytics view {$analyticsIndex} does not use the x-ui.card component.";
        }

        // Purchase order show is the reference workflow-detail page.
        $poShowContents = @file_get_contents($base.'/'.$poShow) ?: '';
        if ($poShowContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.button', 'x-ui.alert'] as $component) {
                if (! str_contains($poShowContents, $component)) {
                    $errors[] = "Inventory workflow detail view {$poShow} does not use the {$component} component.";
                }
            }
        }

        // Product form is the reference workflow-form partial.
        $productFormContents = @file_get_contents($base.'/'.$productForm) ?: '';
        if ($productFormContents !== '') {
            foreach (['x-ui.input', 'x-ui.select', 'x-ui.textarea'] as $component) {
                if (! str_contains($productFormContents, $component)) {
                    $errors[] = "Inventory product form {$productForm} does not use the {$component} component.";
                }
            }
        }

        // No legacy teal / gold-CTA / mutable-stock assignment across the polished
        // inventory analytics + workflow surfaces.
        $uix9Files = [
            'resources/views/inventory/analytics/index.blade.php',
            'resources/views/inventory/executive-dashboard.blade.php',
            'resources/views/inventory/purchase-orders/show.blade.php',
            'resources/views/inventory/purchase-requests/show.blade.php',
            'resources/views/inventory/goods-receipts/show.blade.php',
            'resources/views/inventory/goods-receipts/create.blade.php',
            'resources/views/inventory/stock-transfers/show.blade.php',
            'resources/views/inventory/stock-opnames/show.blade.php',
            'resources/views/inventory/stock-opnames/create.blade.php',
            'resources/views/inventory/products/_form.blade.php',
            'resources/views/inventory/products/show.blade.php',
            'resources/views/inventory/batches/show.blade.php',
        ];
        foreach ($uix9Files as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never an inventory action or status).";
            }
            if (preg_match('/->(?:current_stock|derived_stock|stock_quantity|quantity_on_hand|stock_on_hand)\s*=(?!=)/', $contents)) {
                $errors[] = "Mutable stock attribute assignment found in {$file} (stock stays ledger-derived).";
            }
        }

        // UIX-9 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-9-inventory-analytics-workflow-forms-polish.md')) {
            $warnings[] = 'UIX-9 sprint evidence doc is missing (docs/sprints/uix-9-inventory-analytics-workflow-forms-polish.md).';
        }

        // --- UIX-10 — RME visit, queue & patient workspace operator surfaces. ---
        // Presentation-only governance for the RME daily operator pages (queue,
        // room worklist, visit create/edit forms, workspace nav, RM lookup). The
        // polished references must stay on x-ui.* + semantic tokens, must not
        // reintroduce legacy teal, must never use gold as a CTA, and must never
        // render a full KTP/NIK/identity number. No RME workflow, status
        // transition, room gate, or cashier logic is asserted here.
        $queueIndex = 'resources/views/rme/patient-queue/index.blade.php';
        $roomWorklist = 'resources/views/rme/visits/room-worklist.blade.php';
        $visitCreate = 'resources/views/rme/visits/create.blade.php';
        $visitEdit = 'resources/views/rme/visits/edit.blade.php';

        foreach ([$queueIndex, $roomWorklist, $visitCreate, $visitEdit] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing RME UIX-10 view: {$file}";
            }
        }

        // Antrian Pasien is the reference queue list page.
        $queueContents = @file_get_contents($base.'/'.$queueIndex) ?: '';
        if ($queueContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($queueContents, $component)) {
                    $errors[] = "RME queue view {$queueIndex} does not use the {$component} component.";
                }
            }
            if (! str_contains($queueContents, ':status')) {
                $errors[] = 'RME queue status badge does not use the x-ui.badge :status map.';
            }
        }

        // Room worklist follows the same list standard (header + filter + table).
        $worklistContents = @file_get_contents($base.'/'.$roomWorklist) ?: '';
        if ($worklistContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table'] as $component) {
                if (! str_contains($worklistContents, $component)) {
                    $errors[] = "RME room worklist view {$roomWorklist} does not use the {$component} component.";
                }
            }
        }

        // Visit create/edit forms use the standardized page header.
        foreach ([$visitCreate, $visitEdit] as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents !== '' && ! str_contains($contents, 'x-ui.page-header')) {
                $errors[] = "RME visit form {$file} does not use the x-ui.page-header component.";
            }
        }

        // No legacy teal / gold-CTA / rendered KTP-NIK across the polished RME
        // operator surfaces. The visit create form legitimately *inputs* a KTP
        // field (never rendered back), so only attribute-access echoes are banned.
        $uix10Files = [
            'resources/views/rme/patient-queue/index.blade.php',
            'resources/views/rme/visits/room-worklist.blade.php',
            'resources/views/rme/visits/create.blade.php',
            'resources/views/rme/visits/edit.blade.php',
            'resources/views/rme/visits/_form.blade.php',
            'resources/views/rme/partials/cross-branch-rm-lookup.blade.php',
            'resources/views/rme/visits/partials/rm-sheet-nav.blade.php',
            'resources/views/rme/visits/partials/visit-nav-arrows.blade.php',
            'resources/views/rme/visits/partials/visit-workflow-nav.blade.php',
            'resources/views/rme/dashboard/index.blade.php',
            'resources/views/rme/online-context/select.blade.php',
        ];
        foreach ($uix10Files as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never an RME operator action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Rendered KTP/NIK attribute found in {$file} (identity numbers must never reach the RME UI).";
            }
        }

        // UIX-10 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-10-rme-visit-queue-patient-workspace-polish.md')) {
            $warnings[] = 'UIX-10 sprint evidence doc is missing (docs/sprints/uix-10-rme-visit-queue-patient-workspace-polish.md).';
        }

        // --- UIX-11 — RME medical record, odontogram & print bundle surfaces. ---
        // Presentation-only governance for the doctor-facing clinical
        // documentation pages (medical record list/detail/empty, odontogram
        // detail) and the print/PDF bundle. These must stay on x-ui.* + semantic
        // tokens, must not reintroduce legacy teal, must never use gold as a CTA,
        // and must never render a full KTP/NIK/identity number. No RME workflow,
        // finalization, handwriting-required, room gate, or cashier logic is
        // asserted here — the clinical print templates keep inline brand hex and
        // stay table-based (no flexbox-only dompdf layout is enforced by tokens).
        $rmRecordDetail = 'resources/views/rme/visits/medical-record/show.blade.php';
        $rmRecordIndex = 'resources/views/rme/visits/medical-record/index.blade.php';
        $rmRecordEmpty = 'resources/views/rme/visits/medical-record/empty.blade.php';
        $odontoDetail = 'resources/views/rme/visits/odontogram/show.blade.php';

        foreach ([$rmRecordDetail, $rmRecordIndex, $rmRecordEmpty, $odontoDetail] as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing RME UIX-11 clinical view: {$file}";
            }
        }

        // Medical record detail is the reference clinical documentation page.
        $rmDetailContents = @file_get_contents($base.'/'.$rmRecordDetail) ?: '';
        if ($rmDetailContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.badge', 'x-ui.button', 'x-ui.alert'] as $component) {
                if (! str_contains($rmDetailContents, $component)) {
                    $errors[] = "RME medical record detail {$rmRecordDetail} does not use the {$component} component.";
                }
            }
        }

        // Medical record list follows the UIX-3 list standard.
        $rmIndexContents = @file_get_contents($base.'/'.$rmRecordIndex) ?: '';
        if ($rmIndexContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state'] as $component) {
                if (! str_contains($rmIndexContents, $component)) {
                    $errors[] = "RME medical record list {$rmRecordIndex} does not use the {$component} component.";
                }
            }
        }

        // Odontogram detail is the reference odontogram documentation page.
        $odontoContents = @file_get_contents($base.'/'.$odontoDetail) ?: '';
        if ($odontoContents !== '') {
            foreach (['x-ui.page-header', 'x-ui.card', 'x-ui.badge', 'x-ui.button'] as $component) {
                if (! str_contains($odontoContents, $component)) {
                    $errors[] = "RME odontogram detail {$odontoDetail} does not use the {$component} component.";
                }
            }
        }

        // No legacy teal / gold-CTA / rendered KTP-NIK across the polished clinical
        // documentation views. (The canvas <script> bakes the RM paper template
        // with neutral ink hex into the saved PNG — hex is not scanned here, per
        // the UIX-4 clinical-page precedent.)
        $uix11ClinicalFiles = [
            $rmRecordDetail,
            $rmRecordIndex,
            $rmRecordEmpty,
            $odontoDetail,
        ];
        foreach ($uix11ClinicalFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-teal-\d/', $contents)) {
                $errors[] = "Legacy teal brand class found in {$file} (use brand/token classes).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only, never a clinical action).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Rendered KTP/NIK attribute found in {$file} (identity numbers must never reach the clinical UI).";
            }
        }

        // Print bundle: no residual teal hex, and no rendered KTP/NIK. Print
        // templates stay table-based (inline brand hex is allowed here — UIX-8
        // precedent) and are the only clinical surface where raw hex is expected.
        $uix11PrintFiles = [
            'resources/views/rme/visits/print.blade.php',
            'resources/views/rme/visits/print-pdf.blade.php',
            'resources/views/rme/visits/odontogram/print.blade.php',
            'resources/views/rme/visits/partials/print-body.blade.php',
            'resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php',
        ];
        foreach ($uix11PrintFiles as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/#0f766e|#0d9488/i', $contents)) {
                $errors[] = "Legacy teal hex found in print template {$file} (use brand hex #1D4ED8/#1E40AF).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Rendered KTP/NIK attribute found in print template {$file} (identity numbers must never reach print/PDF).";
            }
        }

        // UIX-11 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-11-rme-medical-record-odontogram-print-bundle-polish.md')) {
            $warnings[] = 'UIX-11 sprint evidence doc is missing (docs/sprints/uix-11-rme-medical-record-odontogram-print-bundle-polish.md).';
        }

        // --- UIX-14 — Settings, master data & access-control polish (lightweight). ---
        // Settings/master-data/access-control list pages are the reference admin list
        // surface: built on x-ui.* foundation components + semantic tokens, no legacy
        // palette / hardcoded hex, gold never a CTA, and no PII (KTP/NIK) rendered.
        $uix14ListViews = [
            'resources/views/settings/clinic-rooms/index.blade.php',
            'resources/views/settings/treatment-categories/index.blade.php',
            'resources/views/settings/treatments/index.blade.php',
            'resources/views/settings/tariffs/index.blade.php',
            'resources/views/settings/payment-methods/index.blade.php',
            'resources/views/settings/branches/index.blade.php',
            'resources/views/settings/wa-reminder-templates/index.blade.php',
            'resources/views/settings/users/index.blade.php',
            'resources/views/settings/roles/index.blade.php',
            'resources/views/settings/permissions/index.blade.php',
        ];

        $uix14RequiredListComponents = [
            'x-ui.filter-bar', 'x-ui.table', 'x-ui.badge', 'x-ui.button', 'x-ui.empty-state',
        ];

        foreach ($uix14ListViews as $file) {
            if (! is_file($base.'/'.$file)) {
                $errors[] = "Missing settings UIX-14 list view: {$file}";

                continue;
            }
            $contents = @file_get_contents($base.'/'.$file) ?: '';

            foreach ($uix14RequiredListComponents as $component) {
                if (! str_contains($contents, $component)) {
                    $errors[] = "Settings list view {$file} does not use {$component}.";
                }
            }

            // No legacy palette / hardcoded hex / gray legacy may be reintroduced.
            if (preg_match('/\b(?:bg|text|border|ring|divide)-(?:teal|indigo|emerald|amber|rose|sky|purple)-\d/', $contents)) {
                $errors[] = "Legacy palette class found in {$file} (use semantic tokens).";
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide)-gray-\d/', $contents)) {
                $errors[] = "Legacy gray class found in {$file} (use navy/ink/hairline tokens).";
            }
            if (preg_match('/#[0-9a-fA-F]{6}\b/', $contents)) {
                $errors[] = "Hardcoded hex color found in {$file} (use semantic tokens).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Sensitive identifier (KTP/NIK) rendered in {$file} (must never be exposed).";
            }
        }

        // Master-data form reference: the clinic-room form adopts the x-ui form controls.
        $clinicRoomForm = @file_get_contents($base.'/resources/views/settings/clinic-rooms/_form.blade.php') ?: '';
        if ($clinicRoomForm !== '') {
            foreach (['x-ui.input', 'x-ui.select', 'x-ui.textarea'] as $component) {
                if (! str_contains($clinicRoomForm, $component)) {
                    $errors[] = "Master-data form reference clinic-rooms/_form does not use {$component}.";
                }
            }
        }

        // Settings/access-control forms must stay off the legacy teal/indigo palette,
        // free of hardcoded hex, never use gold as a CTA, and never render KTP/NIK.
        $uix14FormViews = [
            'resources/views/settings/clinic-rooms/_form.blade.php',
            'resources/views/settings/clinic-rooms/create.blade.php',
            'resources/views/settings/clinic-rooms/edit.blade.php',
            'resources/views/settings/roles/_form.blade.php',
            'resources/views/settings/users/_form.blade.php',
            'resources/views/settings/wa-reminder-templates/_form.blade.php',
        ];

        foreach ($uix14FormViews as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents === '') {
                continue;
            }
            if (preg_match('/\b(?:bg|text|border|ring|divide|focus:border|focus:ring)-(?:teal|indigo)-\d/', $contents)) {
                $errors[] = "Legacy teal/indigo class found in settings form {$file} (use brand/semantic tokens).";
            }
            if (preg_match('/#[0-9a-fA-F]{6}\b/', $contents)) {
                $errors[] = "Hardcoded hex color found in settings form {$file} (use semantic tokens).";
            }
            if (str_contains($contents, 'variant="gold"')) {
                $errors[] = "Gold used as a button/CTA in {$file} (gold is accent-only).";
            }
            if (preg_match('/->(?:ktp_number|ktp|nik|identity_number)\b/', $contents)) {
                $errors[] = "Sensitive identifier (KTP/NIK) rendered in {$file} (must never be exposed).";
            }
        }

        // WA reminder template list must keep the manual-only safety notice
        // (templates are copied by an operator; the system never auto-sends WA).
        $waTemplateIndex = @file_get_contents($base.'/resources/views/settings/wa-reminder-templates/index.blade.php') ?: '';
        if ($waTemplateIndex !== '' && ! str_contains($waTemplateIndex, 'belum mengirim WhatsApp otomatis')) {
            $errors[] = 'WA reminder template list lost the manual-only safety notice (no auto-send guarantee).';
        }

        // UIX-14 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-14-settings-master-data-access-control-polish.md')) {
            $warnings[] = 'UIX-14 sprint evidence doc is missing (docs/sprints/uix-14-settings-master-data-access-control-polish.md).';
        }

        // --- UIX-15 — Global component foundation hardening (lightweight, non-brittle). ---
        // The shared x-ui.* foundation is the single design system: the status badge
        // is case-insensitive and covers the canonical financial/procurement/lifecycle
        // statuses, the table uses semantic tokens (no legacy gray), and domain
        // components route through the foundation rather than forking a second system.
        $badge = @file_get_contents($base.'/resources/views/components/ui/badge.blade.php') ?: '';
        if ($badge !== '') {
            // Case-insensitive status resolution (UIX-15).
            if (! str_contains($badge, 'Str::lower') && ! str_contains($badge, 'strtolower')) {
                $errors[] = 'x-ui.badge status resolution must be case-insensitive (UIX-15).';
            }
            // Canonical financial statuses must be mapped so invoice states are consistent.
            foreach (["'unpaid'", "'partial'", "'void'", "'paid'"] as $key) {
                if (! str_contains($badge, $key)) {
                    $errors[] = "x-ui.badge is missing the canonical status mapping for {$key} (UIX-15).";
                }
            }
        }

        // The foundation table must stay on semantic tokens (no legacy gray divider).
        $table = @file_get_contents($base.'/resources/views/components/ui/table.blade.php') ?: '';
        if ($table !== '') {
            if (preg_match('/\b(?:divide|text|bg|border)-gray-\d/', $table)) {
                $errors[] = 'x-ui.table uses a legacy gray class (use hairline/navy/ink tokens) (UIX-15).';
            }
            if (! str_contains($table, 'divide-hairline')) {
                $errors[] = 'x-ui.table must use the divide-hairline token (UIX-15).';
            }
        }

        // Domain badge components must route through the x-ui.badge foundation
        // (documented boundary — they add domain labels/tones, not a second system).
        $domainBadgeComponents = [
            'resources/views/components/lab/status-badge.blade.php',
            'resources/views/components/rme/invoice-summary.blade.php',
        ];
        foreach ($domainBadgeComponents as $file) {
            $contents = @file_get_contents($base.'/'.$file) ?: '';
            if ($contents !== '' && ! str_contains($contents, 'x-ui.badge')) {
                $errors[] = "Domain component {$file} must render through x-ui.badge (no forked design system) (UIX-15).";
            }
        }

        // Design system doc should document the hardened foundation (soft signal).
        $designDoc = @file_get_contents($base.'/docs/ui_design_system.md') ?: '';
        if ($designDoc !== '' && stripos($designDoc, 'UIX-15') === false) {
            $warnings[] = 'docs/ui_design_system.md does not document the UIX-15 global component foundation.';
        }

        // UIX-15 sprint evidence doc should exist (soft signal).
        if (! is_file($base.'/docs/sprints/uix-15-global-component-foundation-hardening.md')) {
            $warnings[] = 'UIX-15 sprint evidence doc is missing (docs/sprints/uix-15-global-component-foundation-hardening.md).';
        }

        $decision = $errors !== [] ? 'FAIL' : ($warnings !== [] ? 'WATCH' : 'GO');

        $payload = [
            'decision' => $decision,
            'docs_checked' => count($requiredDocs),
            'components_checked' => count($requiredComponents),
            'tokens_checked' => count($requiredTokens),
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("UIX-1 UI governance check: {$decision}");
            foreach ($errors as $e) {
                $this->error("  ✗ {$e}");
            }
            foreach ($warnings as $w) {
                $this->warn("  ! {$w}");
            }
            if ($errors === [] && $warnings === []) {
                $this->line('  ✓ docs, tokens, and x-ui components present; gold-CTA rule holds.');
            }
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }

        if ($decision === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
