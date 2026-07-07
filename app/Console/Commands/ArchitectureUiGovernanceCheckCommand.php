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
