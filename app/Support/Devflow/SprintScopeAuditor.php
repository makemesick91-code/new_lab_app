<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Sprint scope coherence auditor.
 *
 * Enforces the "one sprint = one outcome" rule by checking a manifest's type
 * against the actual change set: too many unrelated modules, a docs-only
 * manifest carrying runtime code, or a hotfix carrying a wide refactor all
 * downgrade the decision. Read-only, deterministic.
 *
 * Decision: GO (coherent), WATCH (too broad but safe), NO-GO (contradiction).
 */
final class SprintScopeAuditor
{
    /**
     * @param  list<string>  $changedFiles
     * @return array{decision:string,module_count:int,touched_modules:list<string>,reasons:list<string>}
     */
    public function audit(SprintManifest $manifest, array $changedFiles): array
    {
        $profile = $manifest->profile();
        $type = (string) ($manifest->type() ?? 'UNKNOWN');
        $reasons = [];
        $errors = 0;
        $warnings = 0;

        $modules = $this->touchedModules($changedFiles);
        $codeChanged = $this->hasCodeChange($changedFiles);
        $refactorBreadth = $this->refactorBreadth($changedFiles);

        // Docs-only / data-repair manifests must not carry runtime code.
        if (in_array($type, ['DOCS_ONLY', 'DATA_REPAIR'], true) && $codeChanged) {
            $reasons[] = "Type {$type} but runtime/code files changed — split the code change into its own sprint.";
            $errors++;
        }

        // Hotfix must not carry a wide refactor.
        $allowRefactor = (bool) ($profile['allow_refactor'] ?? true);
        if (! $allowRefactor && $refactorBreadth > 10) {
            $reasons[] = "Type {$type} carries {$refactorBreadth} changed code files — looks like a refactor, not a scoped fix.";
            $errors++;
        }

        // Module count vs the type's cap.
        $maxModules = (int) ($profile['max_modules'] ?? 99);
        if (count($modules) > $maxModules) {
            $reasons[] = 'Touches '.count($modules).' modules ('.implode(', ', $modules).") — type {$type} expects <= {$maxModules}.";
            if (in_array($type, ['HOTFIX', 'RUNTIME_FIX'], true)) {
                $errors++;
            } else {
                $warnings++;
            }
        } elseif (count($modules) > 3 && in_array($type, ['HOTFIX'], true)) {
            $reasons[] = 'Hotfix spans multiple modules — reconsider scope.';
            $warnings++;
        }

        if ($reasons === []) {
            $reasons[] = 'Scope is coherent for the declared sprint type.';
        }

        $decision = $errors > 0 ? 'NO-GO' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'decision' => $decision,
            'module_count' => count($modules),
            'touched_modules' => $modules,
            'reasons' => array_values($reasons),
        ];
    }

    /** @param list<string> $files @return list<string> */
    private function touchedModules(array $files): array
    {
        $modules = [];
        foreach ($files as $f) {
            if (preg_match('#^app/Modules/([^/]+)/#', $f, $m) === 1) {
                $modules[$m[1]] = true;
            }
        }

        return array_keys($modules);
    }

    /** @param list<string> $files */
    private function hasCodeChange(array $files): bool
    {
        foreach ($files as $f) {
            if (preg_match('#\.(php|js|ts|vue|blade\.php)$#', $f) === 1
                && ! str_starts_with($f, 'docs/')
                && ! str_starts_with($f, 'tests/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $files */
    private function refactorBreadth(array $files): int
    {
        return count(array_filter($files, static fn ($f) => str_starts_with($f, 'app/') && str_ends_with($f, '.php')));
    }
}
