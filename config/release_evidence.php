<?php

/**
 * NSF-10 — Release evidence artifact standard.
 *
 * Read-only registry of which safe artifacts a release evidence capture must
 * produce per profile, and what release:evidence-check requires to declare
 * GO / WATCH / FAIL. Consumed by:
 *  - App\Services\Foundation\ReleaseEvidenceService
 *  - release:evidence-capture / release:evidence-check
 *
 * Closes the NSF-9 RELEASE_SAFETY WATCH by making evidence capture/check a
 * real, repeatable command instead of an ad-hoc local file check.
 *
 * SAFETY:
 *  - Every artifact here is produced by re-running an existing read-only
 *    governance command (via Artisan::call) and writing its JSON output.
 *  - No .env, credentials, DB dump contents, PII, or full logs are ever
 *    written to an evidence artifact.
 */
return [
    'sprint' => 'NSF-10',

    'profiles' => [
        // Local developer runs — advisory only, never required for a decision.
        'local' => [
            'directory' => 'storage/release-evidence/local',
            'max_age_seconds' => null,
            'required_artifacts' => [],
            'optional_artifacts' => [
                'foundation-roadmap-check.json',
                'feature-flags.json',
                'automated-smoke.json',
                'foundation-governance-summary.json',
                'nsf-governance-check.json',
                'postgres-runtime-check.json',
            ],
        ],

        // CI (GitHub Actions) — required artifacts must exist for the job run.
        'ci' => [
            'directory' => 'storage/ci-evidence',
            'max_age_seconds' => 6 * 60 * 60,
            'required_artifacts' => [
                'foundation-roadmap-check.json',
                'feature-flags.json',
                'cache-governance-check.json',
                'queue-governance-check.json',
                'idempotency-outbox-check.json',
                'developer-console-check.json',
                'health-check-check.json',
                'security-compliance-check.json',
                'cicd-enterprise-gate-check.json',
                'deployment-rollback-check.json',
                'idempotency-audit.json',
                'outbox-audit.json',
                'db-performance-check.json',
                'postgres-runtime-check.json',
                'reporting-summary-check.json',
                'reporting-summary-refresh-dry-run.json',
                'automated-smoke.json',
                'foundation-governance-summary.json',
                'nsf-governance-check.json',
            ],
            'optional_artifacts' => [],
        ],

        // VPS / production deploy — full chain including backup + deploy runtime.
        'vps' => [
            'directory' => 'storage/release-evidence/latest',
            'max_age_seconds' => 6 * 60 * 60,
            'required_artifacts' => [
                'backup-verify.json',
                'foundation-roadmap-check.json',
                'feature-flags.json',
                'cache-governance-check.json',
                'queue-governance-check.json',
                'idempotency-outbox-check.json',
                'developer-console-check.json',
                'health-check-check.json',
                'security-compliance-check.json',
                'cicd-enterprise-gate-check.json',
                'deployment-rollback-check.json',
                'idempotency-audit.json',
                'outbox-audit.json',
                'db-performance-check.json',
                'postgres-runtime-check.json',
                'reporting-summary-check.json',
                'reporting-summary-refresh-dry-run.json',
                'release-safety-check.json',
                'automated-smoke.json',
                'foundation-governance-summary.json',
                'nsf-governance-check.json',
                'deploy-runtime.json',
            ],
            'optional_artifacts' => [
                'automated-smoke-http.json',
                'dq-audits.txt',
                'dmo-governance-check.json',
            ],
        ],
    ],

    // Artifacts must never contain any of these (case-insensitive substring
    // scan on the raw artifact bytes before they are written to disk).
    'forbidden_patterns' => [
        'APP_KEY=',
        'DB_PASSWORD',
        'DB_USERNAME',
        '-----BEGIN',
        '.env',
        'PGPASSWORD',
    ],

    // Artifacts must never contain a 16-digit run (KTP/NIK-shaped) sequence.
    'forbidden_regex' => [
        '/\d{16}/',
    ],

    // Guard against accidentally capturing a full log dump as an "evidence" artifact.
    'max_artifact_bytes' => 2 * 1024 * 1024,
];
