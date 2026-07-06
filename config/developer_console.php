<?php

/*
|--------------------------------------------------------------------------
| ENT-7 — Developer Assistance Console
|--------------------------------------------------------------------------
|
| Super-Admin-only, read-only diagnostic console. Every surface is
| permission-gated, audited, and PII/secret masked. The console never
| renders passwords, tokens, sessions, cookies, API keys, environment
| file contents, or full KTP/NIK values.
|
*/

return [

    'metadata' => [
        'sprint' => 'ENT-7',
        'title' => 'Developer Assistance Console',
        'policy_doc' => 'docs/architecture/developer-assistance-console-governance.md',
    ],

    'governance_section' => 'developer_console_governance',
    'readiness_command' => 'foundation:developer-console-check',

    // Console availability. Route disappears entirely when disabled.
    'enabled' => (bool) env('DEV_CONSOLE_ENABLED', true),

    // The console must never expose a mutating (non-GET/HEAD) route.
    'read_only' => true,

    // Spatie permission gating the console route and sidebar entry.
    'permission' => 'view_developer_console',

    // Every console page view writes an immutable sys_audit_logs row.
    'audit_access' => [
        'enabled' => true,
        'entity_type' => 'developer_console',
        'action' => 'VIEW_DEVELOPER_CONSOLE',
    ],

    // PII/secret masking applied to every free-text excerpt (log lines,
    // exception messages) before it is rendered or exported.
    'masking' => [
        'enabled' => true,
        // KTP/NIK-shaped digit runs are always collapsed.
        'mask_long_digit_runs' => true,
        'long_digit_run_min' => 13,
        // key=value / key: value pairs whose key matches one of these
        // fragments have the value replaced with the mask token.
        'sensitive_key_fragments' => [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'app_key', 'authorization', 'cookie', 'session', 'dsn',
            'private_key', 'access_key', 'credential',
        ],
        'mask_emails' => true,
        'mask_token' => '[MASKED]',
    ],

    'sections' => [
        'application_log',
        'failed_jobs',
        'audit_events',
        'slow_queries',
        'deploy_evidence',
        'storage_health',
        'runtime_health',
        'disk_backup',
    ],

    'application_log' => [
        'path' => 'logs/laravel.log', // relative to storage_path()
        'tail_lines' => 80,
        'max_line_length' => 300,
    ],

    'failed_jobs' => [
        'limit' => 10,
        'max_exception_length' => 200,
    ],

    'audit_events' => [
        'limit' => 10,
    ],

    'slow_queries' => [
        'limit' => 10,
    ],

    'deploy_evidence' => [
        'directory' => 'release-evidence/latest', // relative to storage_path()
        'max_files' => 25,
    ],

    'disk_backup' => [
        'backup_directory' => 'app/backups/deploy', // relative to storage_path()
        'max_files' => 5,
    ],
];
