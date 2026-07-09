<?php

/*
|--------------------------------------------------------------------------
| ROLL-5-1 — Five Branch Controlled Production Rollout Readiness Registry
|--------------------------------------------------------------------------
|
| Single canonical, read-only description of the readiness required to roll
| DaengtisiaMS out to FIVE clinic branches in controlled stages this year.
|
| ROLL-5-1 rule: this registry DESCRIBES and ORCHESTRATES existing foundations
| (MON-1 monitoring, ENT-8/LB-1 health, ENT-11 deploy/rollback, ENT-12
| backup/DR, NSF-9/10 release gates, CICD-CTRL-1, inventory + doctor-performance
| audits). It does NOT re-implement any of them.
|
| Governance invariant (enforced by tests): every stage has a monotonically
| increasing `branch_target`, the three stages cover 1 -> 3 -> 5 branches, and
| no signal executes an expensive command on a web request.
|
| This sprint certifies CONTROLLED 5-branch rollout readiness only. It does NOT
| certify national scale, HA cluster, external pentest, or full DR certification.
|
*/

return [

    'enabled' => env('ROLLOUT_READINESS_ENABLED', true),

    // Total branches this controlled rollout targets this year.
    'target_branch_count' => 5,

    // Read-only UI. Reuses the ENT-7 Developer Console permission (Super Admin
    // only via Gate::before). ROLL-5-1 adds NO new permission.
    'ui' => [
        'route_name' => 'foundation.rollout.five-branch-readiness',
        'route_path' => '/foundation/rollout/five-branch-readiness',
        'permission' => 'view_developer_console',
    ],

    // Environments where debug-on / unexpected maintenance are UNSAFE FAIL.
    'production_like_environments' => ['production', 'pilot', 'staging'],

    /*
    |----------------------------------------------------------------------
    | Rollout stages: 1 branch -> 3 total -> 5 total.
    |----------------------------------------------------------------------
    | branch_target is the CUMULATIVE number of live branches at that stage.
    */
    'stages' => [
        'stage_1' => [
            'key' => 'stage_1',
            'label' => 'Stage 1 — Pilot (1 cabang)',
            'branch_target' => 1,
            'description' => 'Satu cabang pilot aktif dengan pemantauan ketat.',
        ],
        'stage_2' => [
            'key' => 'stage_2',
            'label' => 'Stage 2 — +2 cabang (3 total)',
            'branch_target' => 3,
            'description' => 'Menambah dua cabang setelah Stage 1 stabil.',
        ],
        'stage_3' => [
            'key' => 'stage_3',
            'label' => 'Stage 3 — +2 cabang (5 total)',
            'branch_target' => 5,
            'description' => 'Menambah dua cabang terakhir menuju target lima cabang.',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Required readiness categories. Each ROLL-5 signal declares one of these.
    |----------------------------------------------------------------------
    */
    'categories' => [
        'app_health' => 'Kesehatan Aplikasi',
        'branch_data_readiness' => 'Kesiapan Data Cabang',
        'role_permission_readiness' => 'Kesiapan Peran & Izin',
        'rme_readiness' => 'Kesiapan RME',
        'cashier_payment_readiness' => 'Kesiapan Kasir & Pembayaran',
        'inventory_procurement_readiness' => 'Kesiapan Inventory & Pengadaan',
        'backup_evidence' => 'Bukti Backup',
        'restore_drill_evidence' => 'Bukti Uji Restore',
        'monitoring_readiness' => 'Kesiapan Monitoring (MON-1)',
        'audit_command_readiness' => 'Kesiapan Perintah Audit',
        'deploy_rollback_readiness' => 'Kesiapan Deploy & Rollback',
        'capacity_smoke' => 'Uji Kapasitas Ringan',
    ],

    /*
    |----------------------------------------------------------------------
    | Required operational roles that must exist before rollout.
    |----------------------------------------------------------------------
    | Missing role => WATCH (blocker to fix, not an unsafe FAIL).
    */
    'required_roles' => [
        'Super Admin',
        'Owner',
        'Admin Klinik',
        'Doctor',
        'Kasir',
        'Perawat',
        'Kepala Cabang',
        'Admin Warehouse',
    ],

    /*
    |----------------------------------------------------------------------
    | Role-permission leak guards. A leak here is an UNSAFE FAIL (security).
    |----------------------------------------------------------------------
    | The authoritative detector remains inventory:procurement-workflow-audit;
    | ROLL-5 adds a light readiness echo of the most critical boundary.
    */
    'role_permission_leaks' => [
        // Kepala Cabang must stay PR-create-only (never create a PO).
        'Kepala Cabang' => ['manage_purchase_order'],
    ],

    /*
    |----------------------------------------------------------------------
    | Route surfaces that must be registered for each domain to be rollout-ready.
    |----------------------------------------------------------------------
    | Missing route => WATCH (surface not wired), never leaks patient data.
    */
    'route_surfaces' => [
        'rme_readiness' => [
            'rme.visits.index' => 'Daftar kunjungan RME',
            'rme.patient-queue.index' => 'Antrian pasien RME',
            'rme.reports.doctor-performance' => 'Laporan kinerja dokter',
        ],
        'cashier_payment_readiness' => [
            'rme.cashier.index' => 'Kasir RME',
        ],
        'inventory_procurement_readiness' => [
            'inventory.reports.index' => 'Laporan inventory',
            'inventory.goods-receipts.create' => 'Penerimaan barang (GR)',
            'inventory.purchase-requests.workflow' => 'Alur PR cabang',
        ],
        'app_health' => [
            'health.live' => 'Health liveness',
            'health.ready' => 'Health readiness',
        ],
    ],

    // The RME room gate is a queue-stage requirement (Sprint 60.8). ROLL-5
    // verifies the middleware alias is registered (defense-in-depth surface).
    'room_gate_middleware_alias' => 'visit.room',

    /*
    |----------------------------------------------------------------------
    | ROLL-5-1A — Staging restore-drill evidence contract.
    |----------------------------------------------------------------------
    | Canonical, versioned JSON evidence proving a SAFE staging/disposable
    | restore drill was performed. Read-only + schema-validated by
    | RestoreDrillEvidenceService. NO migration — evidence lives on disk.
    |
    | Safety invariants (enforced by the parser):
    |  - `production_overwrite` MUST be exactly false, else UNSAFE FAIL.
    |  - `environment` MUST NOT be a production-like environment.
    |  - Evidence must never contain secrets / KTP / NIK / raw dumps.
    |  - Missing evidence => WATCH (never a fake GO). Unsafe => FAIL.
    */
    'restore_drill' => [
        'schema_version' => 1,
        // Where the drill script writes evidence, and the CLI --create-template.
        'canonical_evidence_path' => 'storage/app/readiness/restore-drills/latest.json',
        // Verification sub-checks a valid drill records (each GO|WATCH|FAIL|UNKNOWN).
        'verification_keys' => [
            'db_connectivity',
            'migration_consistency',
            'app_boot',
            'health_routes',
            'sample_readonly_queries',
        ],
        // A drill whose environment is one of these is an UNSAFE FAIL — a drill
        // must never target production/pilot/live.
        'forbidden_environments' => ['production', 'pilot', 'live', 'prod'],
        // A safe disposable/staging restore target name MUST contain one of these.
        'safe_target_markers' => ['restore_drill', 'staging', 'test', 'rehearsal', 'disposable', 'scratch'],
        // Execution-ready disposable-DB drill helper (presence readiness only).
        'drill_script' => 'scripts/rollout-restore-drill.sh',
        'evidence_template_doc' => 'docs/evidence/rollout/restore-drill-template.md',
    ],

    /*
    |----------------------------------------------------------------------
    | Required commands operators run before each rollout stage (documented
    | in the runbook; ROLL-5 references them, never re-implements them).
    |----------------------------------------------------------------------
    */
    'required_commands' => [
        'foundation:monitoring-observability-check --include-audits --strict',
        'inventory:procurement-workflow-audit --strict',
        'rme:doctor-performance-access-audit --strict',
        'foundation:ci-runtime-control-check --strict',
        'foundation:security-compliance-check',
        'foundation:roadmap-check --strict',
    ],

    // Audit commands that --include-audits (CLI only) may invoke. Each is the
    // authoritative source; ROLL-5 only reports their exit status.
    'includable_audits' => [
        'foundation:monitoring-observability-check' => ['--include-audits', '--strict'],
        'inventory:procurement-workflow-audit' => ['--strict'],
        'rme:doctor-performance-access-audit' => ['--strict'],
        'foundation:ci-runtime-control-check' => ['--strict'],
    ],

    /*
    |----------------------------------------------------------------------
    | Path conventions (mirror ENT-11/12 + MON-1 — not new locations).
    |----------------------------------------------------------------------
    */
    'paths' => [
        'backup_directory' => 'storage/app/backups/deploy',
        'evidence_directory' => 'storage/release-evidence/latest',
        // ROLL-5-1A canonical restore-drill evidence locations (schema-validated
        // by RestoreDrillEvidenceService). The ENT-12 DR `restore-rehearsal.json`
        // is a DIFFERENT schema and is deliberately NOT parsed here.
        'restore_drill_evidence' => [
            'storage/app/readiness/restore-drills/latest.json',
            'storage/release-evidence/latest/restore-drill.json',
        ],
        'restore_drill_runbook' => 'docs/runbooks/roll-5-backup-restore-drill-runbook.md',
        'controlled_rollout_runbook' => 'docs/runbooks/roll-5-controlled-rollout-runbook.md',
        // ENT-11/12 deploy + rollback + backup scripts (presence readiness).
        'deploy_scripts' => [
            'scripts/deploy-vps.sh',
            'scripts/deploy-vps-runner.sh',
            'scripts/rollback-vps.sh',
            'scripts/backup-vps.sh',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Safe thresholds.
    |----------------------------------------------------------------------
    */
    'thresholds' => [
        // Restore-drill evidence older than this many hours => WATCH (re-run).
        'restore_drill_stale_hours' => env('ROLLOUT_RESTORE_DRILL_STALE_HOURS', 720), // 30 days
        // Backup considered stale (WATCH) if older than this many hours.
        'backup_stale_hours' => env('ROLLOUT_BACKUP_STALE_HOURS', 48),
        // Lightweight capacity smoke: a bounded read query slower than this
        // (milliseconds) => WATCH. A hard timeout / query error => FAIL.
        'capacity_query_watch_ms' => env('ROLLOUT_CAPACITY_QUERY_WATCH_MS', 1500),
        'capacity_query_fail_ms' => env('ROLLOUT_CAPACITY_QUERY_FAIL_MS', 8000),
    ],

    /*
    |----------------------------------------------------------------------
    | Lightweight capacity smoke targets. Bounded, read-only COUNT probes on
    | high-traffic tables — NOT a national-scale load test, NO data mutation,
    | NO request storm, NO paid SaaS. Missing table => skipped (UNKNOWN).
    |----------------------------------------------------------------------
    */
    'capacity_probes' => [
        ['label' => 'Kunjungan RME (count)', 'table' => 'trx_clinic_visits'],
        ['label' => 'Invoice RME (count)', 'table' => 'trx_rme_invoices'],
        ['label' => 'Pergerakan Inventory (count)', 'table' => 'trx_inventory_movements'],
        ['label' => 'Pasien (count)', 'table' => 'mst_patients'],
    ],
];
