<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVFLOW-1 — Sprint Classification Profiles
|--------------------------------------------------------------------------
|
| Canonical, read-only source of truth for DaengtisiaMS sprint types.
| Each profile declares the mandatory audit depth, test profiles, CI
| profile, and release requirements a sprint of that type MUST satisfy.
| Tooling (sprint:new, sprint:audit-plan, sprint:test-plan,
| sprint:scope-audit, sprint:release-check) and the manifest validator
| read from here. Nothing in this file executes anything.
|
| Audit levels (see docs/engineering/foundation-sprint-template.md):
|   1 = Scoped     (changed call-site + route + policy + direct deps + tests)
|   2 = Module     (module services/repo/routes/schema/integrations)
|   3 = Foundation (cross-module + CI + deploy + architecture + governance)
|
| CI profiles map 1:1 to config/ci_runtime_control.php intent and are used
| for documentation/validation only — they NEVER weaken the CICD-CTRL-1
| classifier, which stays default-strong and fail-closed.
|
*/

return [

    'version' => 1,

    // The canonical, exhaustive set of sprint types. Unknown types are rejected
    // by the manifest validator (fail closed).
    'types' => [

        'HOTFIX' => [
            'label' => 'Runtime hotfix — smallest coherent scope',
            'audit_level' => 1,
            'ci_profile' => 'hotfix_runtime',
            'default_test_profiles' => ['focused', 'related_regression'],
            'deploy_required' => true,          // if runtime_change=true
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,        // additive only
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => false,          // hotfix must not carry large refactors
            'max_modules' => 2,
            'notes' => 'Focused audit; deploy when runtime changes; no broad refactor.',
        ],

        'RUNTIME_FIX' => [
            'label' => 'Non-hotfix runtime behaviour fix within a module',
            'audit_level' => 2,
            'ci_profile' => 'module_runtime',
            'default_test_profiles' => ['focused', 'module_regression'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => false,
            'max_modules' => 3,
            'notes' => 'Module-level audit and regression.',
        ],

        'MODULE_SPRINT' => [
            'label' => 'Feature work inside one module',
            'audit_level' => 2,
            'ci_profile' => 'module_runtime',
            'default_test_profiles' => ['focused', 'module_regression'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => true,
            'max_modules' => 3,
            'notes' => 'Module feature; deploy + rollback + evidence.',
        ],

        'FOUNDATION_SPRINT' => [
            'label' => 'Cross-module / governance / shared-foundation work',
            'audit_level' => 3,
            'ci_profile' => 'shared_foundation',
            'default_test_profiles' => ['focused', 'cross_module_regression', 'full_required'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => true,
            'allow_refactor' => true,
            'max_modules' => 99,
            'notes' => 'Deeper architecture audit; docs/rules mandatory; full required gates.',
        ],

        'UI_POLISH' => [
            'label' => 'Presentation-only polish (no business logic)',
            'audit_level' => 1,
            'ci_profile' => 'ui_only',
            'default_test_profiles' => ['focused', 'ui_regression'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => false,
            'browser_required' => false,     // recommended when interactive UI changes
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => false,
            'max_modules' => 99,
            'notes' => 'No business-logic/query/schema change; frontend build required.',
        ],

        'DATA_REPAIR' => [
            'label' => 'Operational data repair (no code change)',
            'audit_level' => 1,
            'ci_profile' => 'docs_only',     // when no code changes
            'default_test_profiles' => [],
            'deploy_required' => false,      // no runtime code → no deploy/GO tag
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => false,
            'browser_required' => false,
            'rollback_required' => true,     // backup + before/after mandatory
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => false,
            'max_modules' => 99,
            'backup_mandatory' => true,
            'dry_run_mandatory' => true,
            'notes' => 'Backup + dry-run + before/after; no new GO tag unless runtime changes.',
        ],

        'DOCS_ONLY' => [
            'label' => 'Documentation / governance text only',
            'audit_level' => 1,
            'ci_profile' => 'docs_only',
            'default_test_profiles' => [],
            'deploy_required' => false,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => false,
            'browser_required' => false,
            'rollback_required' => false,
            'evidence_required' => true,
            'full_foundation_audit' => false,
            'allow_refactor' => false,
            'max_modules' => 99,
            'notes' => 'Markdown only; classifier may safely skip the critical Pest step.',
        ],

        'INFRA_RELEASE' => [
            'label' => 'CI/CD / deploy / scripts / release infrastructure',
            'audit_level' => 3,
            'ci_profile' => 'infra_release',
            'default_test_profiles' => ['focused', 'cross_module_regression', 'full_required'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => true,
            'allow_refactor' => true,
            'max_modules' => 99,
            'notes' => 'CI/deploy changes escalate to full required gates.',
        ],

        'SECURITY_FIX' => [
            'label' => 'Security / RBAC / branch-isolation / PII fix',
            'audit_level' => 3,
            'ci_profile' => 'security_change',
            'default_test_profiles' => ['focused', 'security_regression', 'full_required'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => true,
            'allow_refactor' => false,
            'max_modules' => 99,
            'notes' => 'Always escalates security regression + full required gates.',
        ],

        'MIGRATION_HEAVY' => [
            'label' => 'Schema-changing sprint (additive only)',
            'audit_level' => 3,
            'ci_profile' => 'schema_change',
            'default_test_profiles' => ['focused', 'module_regression', 'schema_regression', 'full_required'],
            'deploy_required' => true,
            'deploy_conditional_on' => 'runtime_change',
            'migration_allowed' => true,
            'browser_required' => false,
            'rollback_required' => true,
            'evidence_required' => true,
            'full_foundation_audit' => true,
            'allow_refactor' => false,
            'max_modules' => 99,
            'additive_migration_only' => true,
            'notes' => 'Additive migrations only; never migrate:fresh/db:wipe; schema + release gates.',
        ],
    ],

    // Test-profile identifiers referenced above. Each maps to how sprint:test /
    // sprint:test-plan expand it (the actual test files come from the
    // regression matrix + git diff). Documentation-level only.
    'test_profiles' => [
        'focused' => 'Tests directly covering the changed code (from git diff + matrix).',
        'related_regression' => 'Related categories of the impacted matrix entries.',
        'module_regression' => 'Full test suite of the impacted module(s).',
        'cross_module_regression' => 'Impacted modules + all their related categories.',
        'ui_regression' => 'UI + navigation + view-compile + frontend build.',
        'security_regression' => 'AccessControl + BranchContext + auth + permission suites.',
        'schema_regression' => 'Migration + release-safety + affected-module suites.',
        'full_required' => 'All CICD-CTRL-1 required gates (NSF-9/10/R011/R012).',
    ],

    // Human guidance surfaced by sprint:audit-plan.
    'audit_levels' => [
        1 => [
            'name' => 'Scoped',
            'inspect' => ['changed call-sites', 'affected routes', 'affected policies', 'direct dependencies', 'existing tests for the touched code'],
        ],
        2 => [
            'name' => 'Module',
            'inspect' => ['module services', 'module repository + interface', 'module routes', 'module schema usage', 'module integrations/notifications'],
        ],
        3 => [
            'name' => 'Foundation',
            'inspect' => ['cross-module callers', 'CI workflow + classifier', 'deploy + rollback + backup scripts', 'architecture governance', 'shared-foundation registry', 'release evidence/safety config'],
        ],
    ],
];
