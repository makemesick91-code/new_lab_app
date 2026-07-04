<?php

/**
 * NSF-10 — Backup verification governance configuration.
 *
 * Read-only registry consumed by App\Services\Foundation\BackupVerificationService
 * via foundation:backup-verify. Verification never reads dump contents beyond a
 * short header sniff and never restores/uploads the backup.
 */
return [
    'sprint' => 'NSF-10',

    // Backups must live under one of these directories (relative to base_path()).
    // Path traversal outside these roots is rejected.
    'allowed_directories' => [
        'storage/app/backups/deploy',
    ],

    'allowed_extensions' => ['sql'],

    // Below this size, a .sql dump is almost certainly broken/truncated.
    'min_size_bytes' => 1024,

    // A backup older than this is still valid but flagged WATCH (stale evidence).
    'stale_after_seconds' => 90 * 24 * 60 * 60,

    // Optional plain-SQL header markers sniffed from the first bytes of the file.
    // Missing markers is a WATCH (e.g. custom pg_dump format), not a FAIL.
    'sql_header_markers' => [
        '-- PostgreSQL database dump',
        'PGDMP',
        '-- MySQL dump',
    ],

    // Only this many leading bytes are ever read from the backup file.
    'header_sniff_bytes' => 4096,
];
