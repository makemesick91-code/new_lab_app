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
