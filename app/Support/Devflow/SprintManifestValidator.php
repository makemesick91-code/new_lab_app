<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Sprint manifest validator.
 *
 * Read-only, fail-closed validation of a {@see SprintManifest} against the
 * schema in config/devflow.php + the sprint types in config/sprint_profiles.php.
 *
 * When `$changedFiles` is supplied, it also cross-checks the manifest's impact
 * flags against reality (schema/frontend/security flags must match the diff) —
 * the same contradiction CI enforces.
 *
 * Decision: any error -> NO-GO (invalid); warnings only -> WATCH; else GO.
 */
final class SprintManifestValidator
{
    /**
     * @param  list<string>|null  $changedFiles  paths relative to repo root, or null to skip diff checks
     * @return array{valid:bool,decision:string,errors:list<string>,warnings:list<string>}
     */
    public function validate(SprintManifest $manifest, ?array $changedFiles = null): array
    {
        $errors = [];
        $warnings = [];

        $schema = (array) config('devflow.manifest', []);
        $required = (array) ($schema['required_fields'] ?? []);
        $booleans = (array) ($schema['boolean_fields'] ?? []);
        $requiredBase = (string) ($schema['required_base_branch'] ?? '');
        $forbiddenBases = (array) ($schema['forbidden_base_branches'] ?? ['main', 'master']);
        $goTagRegex = (string) ($schema['go_tag_regex'] ?? '/^[a-z0-9-]+-go$/');

        // 1. Required fields present.
        foreach ($required as $field) {
            if (! $manifest->has($field) || $manifest->get($field) === null || $manifest->get($field) === '') {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // 2. Known sprint type.
        $type = $manifest->type();
        $profile = $manifest->profile();
        if ($type !== null && $profile === null) {
            $known = implode(', ', array_keys((array) config('sprint_profiles.types', [])));
            $errors[] = "Unknown sprint type: {$type}. Known types: {$known}";
        }

        // 3. Base branch.
        $base = $manifest->baseBranch();
        if ($base !== null) {
            if (in_array($base, $forbiddenBases, true)) {
                $errors[] = "Base branch may not target '{$base}'. Use: {$requiredBase}";
            } elseif ($requiredBase !== '' && $base !== $requiredBase) {
                $warnings[] = "Base branch '{$base}' differs from the canonical base '{$requiredBase}'.";
            }
        }

        // 4. Boolean fields are actually booleans.
        foreach ($booleans as $field) {
            if ($manifest->has($field) && ! is_bool($manifest->get($field))) {
                $errors[] = "Field '{$field}' must be a boolean (true/false).";
            }
        }

        // 5. GO tag naming (only enforced when a runtime deploy is expected,
        //    but always validated when present).
        $goTag = $manifest->goTag();
        if ($goTag !== null && preg_match($goTagRegex, $goTag) !== 1) {
            $errors[] = "GO tag '{$goTag}' is not valid (must be lowercase, dash-separated, and end with '-go').";
        }
        if ($goTag === null && $manifest->deployRequired()) {
            $warnings[] = 'deploy_required implies a runtime deploy but no go_tag is declared.';
        }

        // 6. Self-contradiction against the sprint type.
        if ($profile !== null) {
            $this->checkTypeContradictions($manifest, $profile, $errors, $warnings);
        }

        // 7. Manifest-vs-reality contradiction (fail closed).
        if ($changedFiles !== null) {
            $this->checkDiffContradictions($manifest, $changedFiles, $errors);
        }

        $valid = $errors === [];
        $decision = ! $valid ? 'NO-GO' : ($warnings !== [] ? 'WATCH' : 'GO');

        return [
            'valid' => $valid,
            'decision' => $decision,
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function checkTypeContradictions(SprintManifest $manifest, array $profile, array &$errors, array &$warnings): void
    {
        $type = (string) $manifest->type();

        // DOCS_ONLY / DATA_REPAIR must not carry a runtime code change.
        if (in_array($type, ['DOCS_ONLY', 'DATA_REPAIR'], true) && $manifest->flag('runtime_change')) {
            $errors[] = "Type {$type} contradicts runtime_change=true (split the runtime change into its own sprint).";
        }

        // Migration not allowed for this type but schema_change declared.
        if (($profile['migration_allowed'] ?? true) === false && $manifest->flag('schema_change')) {
            $errors[] = "Type {$type} does not allow schema_change=true.";
        }

        // Security type should acknowledge security impact.
        if ($type === 'SECURITY_FIX' && ! $manifest->flag('security_impact')) {
            $warnings[] = 'SECURITY_FIX declared without security_impact=true.';
        }

        // MIGRATION_HEAVY should declare schema_change.
        if ($type === 'MIGRATION_HEAVY' && ! $manifest->flag('schema_change')) {
            $errors[] = 'MIGRATION_HEAVY must declare schema_change=true.';
        }
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $errors
     */
    private function checkDiffContradictions(SprintManifest $manifest, array $changedFiles, array &$errors): void
    {
        $hasMigration = $this->anyMatch($changedFiles, ['#^database/migrations/#']);
        $hasFrontend = $this->anyMatch($changedFiles, [
            '#\.(js|jsx|ts|tsx|vue|css|scss)$#',
            '#(vite\.config|tailwind\.config|package\.json|package-lock\.json)#',
        ]);
        $hasSecurity = $this->anyMatch($changedFiles, [
            '#^app/Policies/#',
            '#^app/Http/Middleware/#',
            '#Seeder\.php$#',
            '#(RoleSeeder|PermissionSeeder)#',
        ]);

        if ($hasMigration && ! $manifest->flag('schema_change')) {
            $errors[] = 'schema_change=false but a migration file changed.';
        }
        if ($hasFrontend && ! $manifest->flag('frontend_change')) {
            $errors[] = 'frontend_change=false but JS/CSS/Vite/build files changed.';
        }
        if ($hasSecurity && ! $manifest->flag('security_impact')) {
            $errors[] = 'security_impact=false but a policy/middleware/permission file changed.';
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $patterns
     */
    private function anyMatch(array $files, array $patterns): bool
    {
        foreach ($files as $file) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
