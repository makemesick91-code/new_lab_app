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
