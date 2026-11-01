<?php

/**
 * NSF-9 — Feature flag foundation (release safety).
 *
 * Read-only, config-driven, env-overridable feature flag registry.
 * Consumed by:
 *  - App\Services\Foundation\FeatureFlagService
 *  - foundation:feature-flags (governance command)
 *
 * RULES:
 *  - Every flag MUST define the required metadata fields (see
 *    FeatureFlagService::REQUIRED_METADATA).
 *  - Future risky foundation infra flags (cache/queue/pgbouncer/storage/
 *    stateless/lb/replica/partitioning/search/national-architecture) MUST
 *    default to false.
 *  - Governance/safety flags introduced in NSF-9 itself may default true
 *    only because the corresponding capability is implemented in NSF-9.
 *  - This file never toggles business behavior on its own — services must
 *    explicitly call FeatureFlagService::enabled() to branch on a flag.
 *
 * LEGACY-RME-PDF-ROLL-1 — runtime override capture.
 *  - `env_key` alone is NOT enough to make an override work. Once a deployment
 *    runs `config:cache`, Laravel stops loading the environment file entirely,
 *    so a runtime env() call inside FeatureFlagService reads nothing and the
 *    declared default silently wins. The override has to be CAPTURED into the
 *    config array while this config file is being built.
 *  - Capture is therefore applied systematically at the bottom of this file to
 *    every flag that declares an `env_key`, instead of being hand-written per
 *    flag (which is how 25 of 28 flags ended up with an inert `env_key`).
 *  - FeatureFlagService::validateGovernance() FAILS on any flag that declares
 *    an `env_key` without a captured `env_value`, so the gap cannot come back.
 */
$registry = [
    'flags' => [

        // --- Future foundation infra readiness flags (risky, default OFF) ---

        'foundation.cache.redis_readiness' => [
            'name' => 'Redis Cache Readiness',
            'description' => 'Enables Redis-backed caching once CACHE-1 invalidation governance ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_CACHE_REDIS_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'CACHE-1',
            'dependencies' => ['CACHE-1'],
            'rollback_action' => 'Set env override to false; no data migration required.',
        ],
        'foundation.cache.invalidation_governance' => [
            'name' => 'Cache Invalidation Governance',
            'description' => 'Enforces mandatory invalidation rules/tests before any critical value is cached.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_CACHE_INVALIDATION_GOVERNANCE',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'CACHE-1',
            'dependencies' => ['CACHE-1'],
            'rollback_action' => 'Set env override to false; caching stays disabled by dependency.',
        ],
        'foundation.queue.outbox_readiness' => [
            'name' => 'Queue Outbox Readiness',
            'description' => 'Enables the queue outbox pattern for reliable async dispatch once QUEUE-1 ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_OUTBOX_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'QUEUE-1',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; no queue jobs dispatched via outbox.',
        ],
        'foundation.queue.idempotency_required' => [
            'name' => 'Queue Idempotency Required',
            'description' => 'Enforces idempotency keys on every queue job before async workflows go live.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_IDEMPOTENCY_REQUIRED',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'QUEUE-1',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; idempotency enforcement stays advisory-only.',
        ],
        'foundation.queue.worker_readiness' => [
            'name' => 'Queue Worker Readiness',
            'description' => 'Marks the queue worker as ready to run; does NOT start a long-running worker process itself.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_WORKER_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'QUEUE-1',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; no worker readiness assumed by deploy gates.',
        ],
        'foundation.queue.external_dispatch_enabled' => [
            'name' => 'Queue External Dispatch Enabled',
            'description' => 'Enables dispatching outbox events to external systems. Stays false until a future sprint approves real external dispatch.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_EXTERNAL_DISPATCH_ENABLED',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'QUEUE-2',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; outbox events stay pending/local, no external calls made.',
        ],
        'foundation.db.pg_bouncer_readiness' => [
            'name' => 'PgBouncer Readiness',
            'description' => 'Marks PgBouncer readiness detection/probe as implemented (DBPERF-2). Does NOT route application traffic through PgBouncer — production cutover is a separate flag.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_DB_PG_BOUNCER_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DBPERF-2',
            'dependencies' => ['DBPERF-1', 'DBPERF-2'],
            'rollback_action' => 'Set env override to false; direct DB connections remain in use.',
        ],
        'foundation.db.pg_bouncer_cutover_enabled' => [
            'name' => 'PgBouncer Production Cutover Enabled',
            'description' => 'Enables routing production application DB traffic through PgBouncer. Stays false until an owner explicitly approves cutover with a verified rollback plan (out of scope for DBPERF-2).',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_DB_PG_BOUNCER_CUTOVER_ENABLED',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'DBPERF-3',
            'dependencies' => ['DBPERF-2'],
            'rollback_action' => 'Set env override to false and revert DB_HOST/DB_PORT to direct PostgreSQL; restart php-fpm only, never PostgreSQL.',
        ],
        'foundation.db.postgres_runtime_tuning_recommendations' => [
            'name' => 'PostgreSQL Runtime Tuning Recommendations',
            'description' => 'Enables read-only PostgreSQL runtime setting recommendations (current vs suggested value, classification, risk, rollback note). Never applies any setting.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_DB_POSTGRES_RUNTIME_TUNING_RECOMMENDATIONS',
            'owner' => 'platform-foundation',
            'risk_level' => 'medium',
            'rollout_status' => 'implemented',
            'review_target' => 'DBPERF-2',
            'dependencies' => ['DBPERF-2'],
            'rollback_action' => 'Set env override to false; recommendation report stops being generated (audit-only, nothing to roll back).',
        ],
        'foundation.db.postgres_runtime_apply_enabled' => [
            'name' => 'PostgreSQL Runtime Apply Enabled',
            'description' => 'Would allow applying PostgreSQL runtime tuning recommendations. Stays false in DBPERF-2 and beyond until an approved maintenance window explicitly enables it.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_DB_POSTGRES_RUNTIME_APPLY_ENABLED',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'DBPERF-3',
            'dependencies' => ['DBPERF-2'],
            'rollback_action' => 'Set env override to false; no runtime setting is ever applied by this flag alone in DBPERF-2.',
        ],
        'foundation.reporting.materialized_summary_readiness' => [
            'name' => 'Materialized Summary Reporting Readiness',
            'description' => 'Marks RPT-1 materialized-summary readiness governance as implemented. Does not switch report reads.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_REPORTING_MATERIALIZED_SUMMARY_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'medium',
            'rollout_status' => 'implemented',
            'review_target' => 'RPT-1',
            'dependencies' => ['RPT-1'],
            'rollback_action' => 'Set env override to false; reports keep reading transactional tables.',
        ],
        'foundation.reporting.rpt_summary_governance' => [
            'name' => 'Reporting Summary Governance',
            'description' => 'Enables RPT-1 governance checks for rpt_* summaries and materialized-view readiness. Does not switch report reads.',
            'default' => true,
            'env_key' => 'FEATURE_FOUNDATION_REPORTING_RPT_SUMMARY_GOVERNANCE',
            'owner' => 'platform-foundation',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'RPT-1',
            'dependencies' => ['RPT-1'],
            'rollback_action' => 'Set env override to false and remove reporting summary gates from future deploy if governance must be temporarily advisory.',
        ],
        'foundation.reporting.summary_runtime_reads_enabled' => [
            'name' => 'Reporting Summary Runtime Reads Enabled',
            'description' => 'Would allow report read paths to use approved rpt_* summaries. Stays false in RPT-1.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_REPORTING_SUMMARY_RUNTIME_READS_ENABLED',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'RPT-2',
            'dependencies' => ['RPT-1'],
            'rollback_action' => 'Set env override to false; all reports continue using transactional source-of-truth queries.',
        ],
        'foundation.reporting.summary_auto_refresh_enabled' => [
            'name' => 'Reporting Summary Auto Refresh Enabled',
            'description' => 'Would allow automated refresh of approved reporting summaries. Stays false in RPT-1; no scheduler or queue worker is started.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_REPORTING_SUMMARY_AUTO_REFRESH_ENABLED',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'RPT-2',
            'dependencies' => ['RPT-1'],
            'rollback_action' => 'Set env override to false; manual dry-run remains available and no scheduled refresh runs.',
        ],
        'foundation.storage.object_storage_readiness' => [
            'name' => 'Object Storage Readiness',
            'description' => 'Enables S3-compatible object storage for uploaded assets once STORAGE-1 ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_STORAGE_OBJECT_STORAGE_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'STORAGE-1',
            'dependencies' => ['STORAGE-1'],
            'rollback_action' => 'Set env override to false; assets remain on local disk.',
        ],
        'foundation.stateless_app_readiness' => [
            'name' => 'Stateless App Readiness',
            'description' => 'Marks the app as safe to run stateless (externalized session/cache/storage).',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_STATELESS_APP_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'STATELESS-1',
            'dependencies' => ['STORAGE-1', 'STATELESS-1'],
            'rollback_action' => 'Set env override to false; keep sticky local-disk/session behavior.',
        ],
        'foundation.load_balancer_pilot' => [
            'name' => 'Load Balancer Pilot',
            'description' => 'Enables the load balancer pilot in front of multiple stateless app instances.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_LOAD_BALANCER_PILOT',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'LB-1',
            'dependencies' => ['STATELESS-1', 'LB-1'],
            'rollback_action' => 'Set env override to false; route traffic to single instance.',
        ],
        'foundation.read_replica_readiness' => [
            'name' => 'Read Replica Readiness',
            'description' => 'Marks read replica replication/read-write-split design as ready (routing stays off).',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_READ_REPLICA_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'REPLICA-1',
            'dependencies' => ['LB-1', 'CACHE-1', 'REPLICA-1'],
            'rollback_action' => 'Set env override to false; all reads stay on primary.',
        ],
        'foundation.partitioning_design_only' => [
            'name' => 'Partitioning Design Only',
            'description' => 'Marks table partitioning strategy as designed; production partitioning stays forbidden.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_PARTITIONING_DESIGN_ONLY',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'PART-1',
            'dependencies' => ['DBPERF-1', 'RPT-1', 'PART-1'],
            'rollback_action' => 'Set env override to false; no partitioning behavior is ever gated by this flag alone.',
        ],
        'foundation.search_log_explorer_readiness' => [
            'name' => 'Search & Log Explorer Readiness',
            'description' => 'Enables the search engine/log explorer foundation once SEARCH-1 ships with PII redaction.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_SEARCH_LOG_EXPLORER_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'SEARCH-1',
            'dependencies' => ['NSF-10', 'QUEUE-1', 'SEARCH-1'],
            'rollback_action' => 'Set env override to false; no search/log explorer surface exposed.',
        ],
        'foundation.national_distributed_architecture_plan' => [
            'name' => 'National Distributed Architecture Plan',
            'description' => 'Marks the national multi-region/multi-branch distributed architecture plan as ready.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_NATIONAL_DISTRIBUTED_ARCHITECTURE_PLAN',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'NDA-1',
            'dependencies' => ['REPLICA-1', 'LB-1', 'PART-1', 'STORAGE-1', 'SEARCH-1', 'NDA-1'],
            'rollback_action' => 'Set env override to false; plan/design only, no production change to roll back.',
        ],

        // --- Lab Workflow V2 (parallel engine, legacy read-only) ---

        'lab.workflow_v2' => [
            'name' => 'Lab Workflow V2',
            'description' => 'Activates the Lab Workflow V2 state machine (Cabang pickup, courier transport, internal/external production, QC/rework, delivery proof) for NEW lab orders. When off, new orders keep the legacy Sprint 3-7 pipeline. Legacy orders always stay read-only legacy regardless of this flag.',
            'default' => false,
            'env_key' => 'FEATURE_LAB_WORKFLOW_V2',
            // Captured at config-BUILD time so the override keeps working under
            // `config:cache` (runtime env() returns null once config is cached).
            'env_value' => env('FEATURE_LAB_WORKFLOW_V2'),
            'owner' => 'lab-workflow',
            'risk_level' => 'high',
            'rollout_status' => 'in_progress',
            'review_target' => 'LAB-WORKFLOW-V2',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false; new orders revert to the legacy Lab pipeline and existing V2 orders stay readable (no data migration required).',
        ],
        'patient.ktp_camera_ocr' => [
            'name' => 'KTP Camera Capture & OCR',
            'description' => 'REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — camera capture of an Indonesian KTP during patient registration plus browser-side, self-hosted OCR (tesseract.js) that SUGGESTS registration values. Nothing is saved from OCR without operator review and an explicit confirmation; the registration is validated exactly like manual entry. The image is stored through the existing private KTP document flow. PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT: this flag alone enables NOBODY — eligibility additionally requires the server-side pilot scope in config/patient_ktp_ocr_pilot.php (cohort, single branch, period, bound tablet), decided only by KtpCameraOcrPilotGate.',
            'default' => false,
            'env_key' => 'FEATURE_PATIENT_KTP_CAMERA_OCR',
            'env_value' => env('FEATURE_PATIENT_KTP_CAMERA_OCR'),
            'owner' => 'patient-registration',
            'risk_level' => 'medium',
            'rollout_status' => 'implemented',
            'review_target' => 'PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT',
            'dependencies' => [],
            'rollback_action' => 'Set the env override to false and rebuild the config cache; the camera/OCR controls disappear and the existing scanner-agent + manual-upload KTP flow is unchanged. No data is created by OCR, so nothing needs migrating back.',
        ],

        // --- SATUSEHAT integration (readiness foundation; external send stays off) ---

        'satusehat.integration_readiness' => [
            'name' => 'SATUSEHAT Integration Readiness',
            'description' => 'Marks the SATUSEHAT readiness foundation (candidate/readiness/preview/mapping/identifier governance + controlled submission filter) as implemented. Does NOT enable any external SATUSEHAT API call — the runtime gateway stays DisabledSatusehatGateway while SATUSEHAT_ENABLED=false.',
            'default' => false,
            'env_key' => 'FEATURE_SATUSEHAT_INTEGRATION_READINESS',
            // Captured at config-BUILD time so the override survives config:cache.
            'env_value' => env('FEATURE_SATUSEHAT_INTEGRATION_READINESS'),
            'owner' => 'satusehat',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'SATUSEHAT-1',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false; SATUSEHAT menus/routes can be hidden via permission/config. No candidate/audit/mapping data is deleted and no external call is ever made by this flag.',
        ],
        'satusehat.external_submission_enabled' => [
            'name' => 'SATUSEHAT External Submission Enabled',
            'description' => 'Allows the HTTP gateway to authenticate and submit FHIR resources to the SATUSEHAT sandbox API. SATUSEHAT-2 ships the real sandbox adapter but it stays OFF by default: the actual runtime switches are SATUSEHAT_ENABLED + SATUSEHAT_SEND_ENABLED (both false), sandbox-only, production fail-closed. This governance marker documents the capability; enabling requires sandbox credentials + a verified live round-trip.',
            'default' => false,
            'env_key' => 'FEATURE_SATUSEHAT_EXTERNAL_SUBMISSION_ENABLED',
            'env_value' => env('FEATURE_SATUSEHAT_EXTERNAL_SUBMISSION_ENABLED'),
            'owner' => 'satusehat',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'SATUSEHAT-2',
            'dependencies' => ['SATUSEHAT-1'],
            'rollback_action' => 'Set SATUSEHAT_SEND_ENABLED=false then SATUSEHAT_ENABLED=false and rebuild config cache; the runtime binding falls back to DisabledSatusehatGateway and no network request is ever made. Production stays fail-closed.',
        ],

        // --- Release safety / governance flags (implemented in NSF-9) ---

        'release.automated_smoke_required' => [
            'name' => 'Automated Smoke Required',
            'description' => 'Requires release:automated-smoke to pass (GO) before a release is considered safe to deploy.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_AUTOMATED_SMOKE_REQUIRED',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'NSF-10',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to make smoke advisory only (not recommended).',
        ],
        'release.rollback_checklist_required' => [
            'name' => 'Rollback Checklist Required',
            'description' => 'Requires the release safety rollback checklist to be completed before a GO tag is created.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_ROLLBACK_CHECKLIST_REQUIRED',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'NSF-10',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to skip checklist enforcement (not recommended).',
        ],
        'release.feature_flag_required_for_risky_changes' => [
            'name' => 'Feature Flag Required For Risky Changes',
            'description' => 'Requires any risky future foundation infra change to ship behind a feature flag default-off.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_FEATURE_FLAG_REQUIRED_FOR_RISKY_CHANGES',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'RC-1',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to relax the requirement (not recommended).',
        ],

        // --- LEGACY-RME-PDF — legacy RME PDF archive (runtime shipped, stays OFF) ---

        'rme.legacy_pdf_archive' => [
            'name' => 'Legacy RME PDF Migration',
            'description' => 'MIGRATION / INGESTION / WRITE capability for the legacy RME PDF archive: LEGACY-RME-PDF-1A shipped the schema, permissions and date rules, 1B the private upload runtime and Poppler page rendering, 1C the human review gate, the atomic idempotent publish into an immutable legacy record and the patient RME history integration behind a private viewer, 1D the terminal reasoned VOID that retracts without erasing plus the doctor-facing read-only viewer and authorized private print/PDF export. The runtime exists but stays OFF in production until an explicit controlled-rollout decision; while it is off every legacy upload, processing, retry, review, publish, void and branch-admission path is refused server-side. LEGACY-RME-PDF-HISTORY-1A — THIS FLAG DOES NOT GATE READING AN ALREADY-PUBLISHED ARCHIVE. Published legacy evidence is the patient real medical history, so with migration off (and with no branch admitted) it stays readable to an authorized clinical reader through the patient-centric RME history, the private viewer and authorized print/export; read is governed by the record being PUBLISHED plus canonical authorization (read permission, server-resolved branch scope, and for a doctor the treating-relationship scope), never by this flag. LEGACY-RME-PDF-ROLL-2 governs the controlled enablement itself: run `legacy-rme:rollout-readiness --expect=off --strict` before switching it on, and `--expect=on` immediately after, so the switch is proven rather than assumed.',
            'default' => false,
            'env_key' => 'FEATURE_RME_LEGACY_PDF_ARCHIVE',
            'owner' => 'rme',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'LEGACY-RME-PDF-1D',
            'dependencies' => [],
            'rollback_action' => 'Set the env override to false and clear the config cache; every legacy upload/processing/retry/review/publish/void/branch-admission path is refused again, so no new evidence can enter or leave the archive. Nothing is deleted — the schema, already imported staging rows, published legacy records and their files on the private disk all stay exactly as they are. LEGACY-RME-PDF-HISTORY-1A: this rollback stops MUTATIONS, it does not hide already-published evidence — an authorized clinical reader keeps reading a published archive, which is deliberate so a rollback never removes a patient medical history from the doctor treating them. To contain a READ incident use the mechanisms built for it: revoke view_legacy_rme_archive / view_legacy_rme_imports from the affected actors, and/or VOID the offending record, which stops its bytes streaming immediately while preserving it as auditable evidence.',
        ],

        // --- REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 —
        //     doctor trusted-device enforcement (capability shipped, stays OFF) ---

        'doctor.trusted_device_enforcement' => [
            'name' => 'Doctor Trusted Device Enforcement',
            'description' => 'ENFORCEMENT switch for the doctor trusted-device lock. The capability is complete: a doctor logging in from the Clinic App proves the device key cryptographically, an unknown (doctor, device) pair automatically becomes a PENDING request, Approval -> Approval Device Dokter approves or refuses it, and an approved session is bound to that device and re-verified on every protected request. THIS FLAG IS THE ONLY THING THAT MAKES ANY OF IT BINDING. While it is off nothing in the login path touches the device registry: the app-only gate returns before its first query, the session middleware returns on its first line, no login ticket is ever minted, and ticket redemption is refused outright. So a doctor keeps logging in through an ordinary browser exactly as before, and an empty authorization table cannot lock anybody out. Turning it on DENIES browser login for every account holding the Doctor role and permits only an approved device, which is a clinical lockout if the fleet is not enrolled and approved first — so it belongs to a controlled Phase 4 pilot on real hardware, never to a deploy.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 4',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache. Doctor browser login works again immediately and every doctor session stops being device-checked; no data is changed, no device is unenrolled and no authorization is altered. Nothing needs to be migrated back, because the flag only ever gated a decision - it never wrote one.',
        ],

        'doctor.pwa_webauthn_device_login' => [
            'name' => 'Doctor PWA WebAuthn Device Login',
            'description' => 'CAPABILITY switch for letting a BROWSER earn the same device-bound session that, until now, only the Android Clinic App could earn. A WebAuthn credential registered against an approved mst_doctor_devices row is asserted after the password step, and on success the ordinary session binding is written and re-verified on every protected request exactly as ticket redemption writes it. THIS FLAG DOES NOT ENFORCE ANYTHING AND CANNOT LOCK ANYBODY OUT. It is only reachable at all when doctor.trusted_device_enforcement is ALSO on, because that is the only condition under which a doctor browser login is denied and therefore the only condition under which an assertion is ever requested; with enforcement off no doctor is ever asked for a credential. Turning this flag on ADMITS rather than denies, and it can only admit an account that already holds a registered credential on an ACTIVE device with an ACTIVE authorization - so on a fleet with no credentials registered it changes nothing. It is NOT a replacement for the Android path: both write the same binding, the Android endpoints are untouched, and the APK is not retired. A PWA being installed grants nothing; the credential is the proof.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DOCTOR-PWA-WEBAUTHN-1',
            'dependencies' => ['doctor.trusted_device_enforcement'],
            'rollback_action' => 'Set the environment override to false and clear the config cache. Requesting assertion options and completing a WebAuthn login are both refused immediately, and a denied doctor is denied exactly as they were before this sprint - which means the Android Clinic App path is the remaining way in, unchanged. It ALSO invalidates existing WebAuthn-authenticated doctor sessions on their next protected request, including on a device that also carries an Android Keystore key, WITHOUT disabling the Android Keystore path for that same approved device - an Android session on the same tablet is untouched, because the session records which proof authenticated it and each proof is re-verified as itself (DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1; before it, a dual-proof device kept the browser session alive and this rollback was nominal on exactly the device the pilot runs on). No data is altered: registered credentials, devices and authorizations all stay exactly as they are, so re-enabling needs no re-enrolment. Nothing needs migrating back, because the flag only ever gated a decision - it never wrote one.',
        ],

        // --- DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 —
        //     one authenticated doctor session, and a permanent clinical branch
        //     with approved temporary cover. Both capabilities ship OFF. ---

        'doctor.single_active_session' => [
            'name' => 'Doctor Single Active Session',
            'description' => 'ENFORCEMENT switch for one authenticated session per doctor. A lease is claimed on the framework Login event rather than inside a login controller, because a remember-me recaller cookie re-authenticates a browser through no controller at all, and it is re-validated on every protected request. A SECOND login is DENIED and the FIRST is NEVER evicted, so the clinician already seeing patients keeps working and the person arriving second is told where the live session is. NO SECOND LOGIN EVER EVICTS A LIVE SESSION. A lease ends when the doctor logs out, when an authorized operator releases it with a written reason through the audited force-logout action, gated by release_doctor_session_leases, or when the doctor EFFECTIVE CLINICAL BRANCH stops matching the branch the session was established under - a cover that started, a cover that expired, or an approved permanent transfer, which is the doctor.branch_lock capability below and is inert while that flag is off. In every one of those cases the release is DATA, not an in-process logout: the evicted browser is torn down on its next request, never in place. An incumbent whose server-side session row no longer exists is DEAD and is reclaimed at once, so a doctor who finished on the ward tablet and walked to the office PC is never locked out; an incumbent whose session is alive is refused however idle it is, because reclaiming on an idle timer is eviction by the back door and would invert the whole rule. THE ENGINE ALSO REFUSES TO ARM WHEN IT CANNOT OBSERVE AN INCUMBENT: liveness is read from the server-side sessions table, so on any session driver other than database every incumbent would read DEAD and the rule would silently degrade to newest-login-wins, which is a fail-OPEN. It disarms instead of guessing. WHILE THIS FLAG IS OFF NOTHING IS CLAIMED AND NOTHING IS DENIED: the claim listener returns before its first query and the per-request check returns on its first line, so a doctor holds as many sessions as they could before this sprint. Turning it on can refuse a login, so it belongs to a supervised activation window on real hardware, never to a deploy.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The claim listener and the per-request revalidation both return immediately, so no login is refused and no session is torn down: a doctor denied a second session a moment ago can open one now, and every session that is already open stays open. NO DATA IS TOUCHED. Existing lease rows stay exactly as they are, and the partial unique index still permits at most one unreleased lease per user, which is harmless while nothing claims one. Re-enabling therefore needs no cleanup: a stale lease whose server-side session row is gone is reclaimed by the next claim, and the escape hatch for a lease genuinely stuck behind a live session is the audited force-logout action, gated by release_doctor_session_leases, never a manual UPDATE. This flag never writes a branch, a device, an authorization or a credential, so nothing has to be migrated back.',
        ],

        'doctor.branch_lock' => [
            'name' => 'Doctor Home Branch Lock and Approved Temporary Cover',
            'description' => 'ENFORCEMENT switch for the doctor clinical branch: a permanent HOME_LOCKED_BRANCH, an optional APPROVED TEMPORARY COVER carrying explicit starts_at and ends_at, and an EFFECTIVE_CLINICAL_BRANCH that is the active cover when there is one and the home lock otherwise. Every doctor starts UNSET and there is NO BACKFILL, because production carries no authoritative per-doctor branch and an inferred one would be a guess recorded as a fact. UNSET is the absence of a lock row, and while a doctor is UNSET branch selection, operational lists and writes behave exactly as they did before this sprint: UNSET is the compatibility state, not missing data. Once SET the doctor cannot self-switch, the operational lists and the visit-creation write path both resolve through the effective branch, and the physical tablet branch decides nothing. Per-record authorization is deliberately NOT narrowed, and historical archive reads stay cross-branch, so a doctor keeps reading the history a patient made at another branch. Expiry is resolved from current timestamps on every read and never by a scheduled job, so a delayed worker can never leave an expired cover effective. A locked branch that later loses is_active or is_rme_enabled degrades to UNSET rather than stopping the doctor dead; that degradation is deliberately NOT audited per request, because the resolver runs on every protected request of every doctor, so it is surfaced on the approver queue instead. THIS FLAG ARMS NO DEVICE ENFORCEMENT and cannot refuse a login by itself. Turning it on changes what a SET doctor may see and write, so it belongs to a supervised window after at least one lock has been approved through the canonical screen.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_BRANCH_LOCK',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1',
            'dependencies' => ['doctor.single_active_session'],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The branch resolver stops consulting the lock, so every doctor resolves a working branch exactly as they did before this sprint and every operational list widens back to the legacy behaviour at once, including for doctors whose lock is SET. The write-path assertion stops firing with it, so a visit may again be created wherever the pre-sprint rules allowed. NO DATA IS TOUCHED: home locks, approved covers, pending requests and the entire approval history stay exactly as they are, so re-enabling needs no re-approval and no re-entry. It logs nobody out by itself, because session invalidation on cover start, on cover expiry and on permanent transfer is the lease mechanism, which is a separate flag. That is exactly why this capability refuses to arm unless the lease is armed too, and why the refusal lives in DoctorEffectiveBranchResolver::enabled() instead of only in the dependencies array above: nothing in this registry reads a dependency, so a declaration on its own would be decorative. Nothing has to be migrated back, because the flag only ever gated a decision - it never wrote one.',
        ],

        // --- REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 —
        //     four named front-desk accounts, each pinned to one branch's
        //     registered hardware. Ships OFF and arms per account. ---

        'front_office.branch_device_lock' => [
            'name' => 'Front Office Branch Device Lock',
            'description' => 'ENFORCEMENT switch for the four-account Front Office branch-device lock. For an ARMED account, login requires all four of: the account itself, a WebAuthn credential asserted from the browser, that credential resolving to a registered mst_doctor_devices row which is active and cryptographically verified, and that device branch_id equalling the branch the account is pinned to. The same decision is re-evaluated on every protected request, and the branch context is pinned so the online-context selector cannot widen it. SCOPE IS THE COHORT, NOT THE ROLE. Production carries EIGHT Front Office accounts and the owner approved FOUR; membership is by user id paired with a branch code in config/front_office_device_lock.php, whose committed cohort is EMPTY. So this flag being on enforces for NOBODY until an (account, branch) pair is armed in the environment, which is what makes per-account rollout possible without touching the other seven. A fifth armed id does not widen the lock: it exceeds the committed max_cohort_size and fails CLOSED for everyone armed. Role-wide enforcement is a source-controlled false and is not reachable from the environment. WHILE THIS FLAG IS OFF NOTHING IN THE LOGIN PATH READS THE DEVICE REGISTRY: the decision service returns not-in-scope before its first query, the session middleware returns on its first line, and requesting or completing an assertion is refused outright - so every Front Office account logs in exactly as it did before and an empty credential registry cannot lock anybody out. Turning it on for an account whose branch device holds no registered credential DENIES that account, which is a front-desk lockout at that branch - so it belongs to a supervised per-account ceremony on real hardware, never to a deploy.',
            'default' => false,
            'env_key' => 'FEATURE_FRONT_OFFICE_BRANCH_DEVICE_LOCK',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The decision service returns not-in-scope immediately, so no Front Office login is refused, no session is torn down in place, and the branch context guard stops pinning - an armed account denied a moment ago can log in now, and the online-context selector behaves exactly as it did before this sprint. It ALSO invalidates existing device-bound Front Office sessions on their next protected request, which is the intended direction: the session simply stops being device-checked. NO DATA IS TOUCHED. Registered devices, their branch ownership and every WebAuthn credential stay exactly as they are, so re-enabling needs no re-enrolment and no re-approval. Nothing needs migrating back, because the flag only ever gated a decision - it never wrote one. To roll back a SINGLE account instead of the capability, remove that one pair from FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT and clear the config cache; the remaining armed accounts are unaffected.',
        ],

        // --- REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 —
        //     the SAME four named front-desk accounts, pinned to one branch each,
        //     WITHOUT any device or WebAuthn requirement. Ships OFF and arms per
        //     account. Independent of front_office.branch_device_lock: either
        //     flag pins, only the device flag demands proof. ---

        'front_office.branch_context_lock' => [
            'name' => 'Front Office Branch Context Lock',
            'description' => 'ENFORCEMENT switch for the four-account Front Office BRANCH CONTEXT lock, and NOTHING ELSE. For an ARMED account the effective branch context is pinned to the one branch its cohort entry names: the resolver returns that branch ahead of the online context, the four online-context selection guards refuse any other branch, and the selector offers only the pinned one. THE NAMED INVARIANT IS THAT THIS IS NOT A DEVICE REQUIREMENT: BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT. Turning this flag on cannot cause a credential lookup, a WebAuthn assertion ceremony, a device-cookie requirement, or a login denial for a missing credential - none of those are reachable from this flag at all, because the login controller, the device session middleware and the WebAuthn controller do not reference the branch-pin resolver. That is what makes this safe to arm for a front desk holding no registered credential, which is the whole reason it exists separately from front_office.branch_device_lock. The converse invariant also holds and is deliberate: DEVICE LOCK IMPLIES BRANCH PIN, so arming front_office.branch_device_lock pins branches whether or not this flag is on - the already-GO device capability promised that and this flag must not retract it by omission. SCOPE IS THE COHORT, NOT THE ROLE, and it is the SAME cohort the device layer reads (FrontOfficeBranchDeviceCohort) - one user-to-branch source of truth, one ceiling of four, one branch allowlist. Production carries EIGHT Front Office accounts and the owner approved FOUR; the committed cohort is EMPTY, so this flag being on pins NOBODY until an (account, branch) pair is armed in the environment. The other four accounts stay unchanged because they are ABSENT from the cohort, not because anything names them. A fifth armed id exceeds max_cohort_size and fails CLOSED for everyone armed. An armed account whose mapping is undecidable resolves to NO branch rather than falling through to a wider one. Arming an account here and LATER arming the device flag will additionally demand WebAuthn from that same account - the two layers share the cohort, so that is a supervised ceremony, not a deploy.',
            'default' => false,
            'env_key' => 'FEATURE_FRONT_OFFICE_BRANCH_CONTEXT_LOCK',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The branch-pin resolver stops pinning, so every Front Office account resolves its branch through the ordinary online-context tiers exactly as it did before, and the selector offers the full RME branch list again. No session is invalidated and no login is affected, because this flag never gated a login. NOTE: if front_office.branch_device_lock is ALSO on, branches stay pinned by that layer - DEVICE LOCK IMPLIES BRANCH PIN - so a full unpin requires both flags off.',
        ],

        // --- FIX-04b — legacy ODONTOGRAM chart archive (runtime shipped, stays OFF) ---

        'rme.legacy_odontogram_archive' => [
            'name' => 'Legacy Odontogram Archive Migration',
            'description' => 'MIGRATION / INGESTION / WRITE capability for the legacy ODONTOGRAM chart archive: uploading a scanned historical paper odontogram as PDF, rasterizing its pages onto a private disk, reviewing them and publishing an immutable, read-only legacy odontogram record that appears in the patient odontogram history alongside their native odontograms. Entirely separate from the legacy RME PDF archive: its own tables, its own permissions and this own flag, so neither capability can be opened or rolled back as a side effect of the other. Publishing never creates a clinic visit, a native odontogram, a medical record, an invoice, a payment, a lab order or a SATUSEHAT record. While this flag is off every upload, processing, retry, review, publish and void path is refused server-side. THIS FLAG DOES NOT GATE READING AN ALREADY-PUBLISHED ARCHIVE: published evidence is the patient real clinical history, so it stays readable to an authorized clinical reader through the policy-gated private viewer; read is governed by the record being PUBLISHED plus canonical authorization (read permission, server-resolved branch scope, and for a doctor the treating-relationship scope), never by this flag.',
            'default' => false,
            'env_key' => 'FEATURE_RME_LEGACY_ODONTOGRAM_ARCHIVE',
            'owner' => 'rme',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'FIX-04b',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache; every legacy odontogram upload/processing/retry/review/publish/void path is refused again, so no new evidence can enter or leave the archive. Nothing is deleted - the schema, staged rows, published records and their files on the private disk all stay exactly as they are. This rollback stops MUTATIONS, it does not hide already-published evidence: an authorized clinical reader keeps reading a published archive, which is deliberate so a rollback never removes a patient clinical history from the doctor treating them. To contain a READ incident, revoke view_legacy_odontogram_archive from the affected actors and/or VOID the offending record, which stops its bytes streaming immediately while preserving it as auditable evidence.',
        ],

        // --- FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 (rule 190) ---

        'rme.invoice_correction' => [
            'name' => 'RME Invoice Correction (pre-payment)',
            'description' => 'MUTATION capability for RME invoice corrections (pre-payment only): a cashier submits a correction of an UNPAID invoice that has no payment (original document snapshotted and fingerprinted); a DIFFERENT account approves it, and under owner decision D1a (2026-10-21) that approval APPLIES the correction in the same transaction - invoice items and totals recomputed server-side, the original (v0) and revised documents frozen as immutable versions - or rejects it. Maker != checker != applier is enforced per account in the service under the invoice row lock, Super Admin included. Grants (rule 195, RoleSeeder): Kasir request, Admin Klinik approve, Front Office both (owner D1b); no operational role holds the separate apply permission, which serves only the recovery path for a pre-D1a APPROVED row. WHERE an ON flag reaches is decided by the source-controlled, fail-closed pilot allowlist config/rme_invoice_correction.php (owner D1c: SPN4 only; no environment override); submit/approve/apply are refused outside it, reject/cancel are not. Prices are manual cashier entry (D21 revised, rule 194); discount policy (D21c) and Rp0 (D21g) remain pending owner decisions - corrections keep the 0 <= discount <= gross bound and refuse every new Rp0 line or total. Post-payment correction, refund, payment reversal, patient credit and Rp0 adjustment are NOT implemented and are refused regardless of this flag. While this flag is off every submit/approve/reject/cancel/apply path is refused server-side and the mutation routes answer 404. THIS FLAG DOES NOT GATE READING existing correction records, their history or frozen document versions. An active correction blocks payment (D10) independently of this flag, and every payment is bound to the invoice correction_version the cashier saw (rule 191).',
            'default' => false,
            'env_key' => 'FEATURE_RME_INVOICE_CORRECTION',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2',
            'dependencies' => [],
            'rollback_action' => 'Resolve (approve - which applies under D1a - reject or cancel; reject/cancel stay possible outside the pilot allowlist) every active correction FIRST - an active correction keeps blocking payment of its invoice after the flag is off, and with the flag off nobody can resolve it. Then set the environment override to false and rebuild the config cache as the runtime user; every correction submit/approve/reject/cancel/apply path is refused again and the mutation routes answer 404. Nothing is deleted or reverted: applied corrections stay applied, and their frozen original/revised document versions, events and audit rows remain readable to permission holders and to the read-only oversight roles. An applied correction is never rolled back by dropping a table - the schema must stay, because the frozen original is the only true copy of the pre-correction document.',
        ],
        'rme.invoice_postpayment_correction' => [
            'name' => 'RME Invoice Correction (post-payment)',
            'description' => 'MUTATION capability for correcting an RME invoice that has already received money (status PARTIAL or PAID), per owner decisions PP-1 to PP-10 (ratified 2026-10-25) and rule 206/207. It is a SECOND switch on top of rme.invoice_correction (both must be on) and reuses the same fail-closed SPN4 pilot allowlist and the same maker/checker identities. The correction type is derived by the server from the payment ledger under the invoice row lock, never submitted. Every POST_PAYMENT submission requires a case-specific owner approval reference (PP-2/PP-7, enforced in code and by a database CHECK). Approval applies it atomically (D1a): items and totals recomputed, the original and revised documents frozen, the invoice status re-derived from its own ledger by the same helper payments use (paid >= total => PAID, else PARTIAL), every issued receipt frozen as an immutable snapshot first (PP-5), and - when the new total is below the money received - an OPEN overpayment obligation recorded for the difference (PP-3 option A). No payment row is ever written, no refund, reversal, settlement or patient credit exists, no negative receivable is created, and a correction never generates or changes lab work (PP-8). Corrections of an invoice with lab work, an OPEN obligation, a cancelled visit or a Rp0 original are refused. While this flag is off every POST_PAYMENT submit/approve is refused server-side and nothing about the pre-payment capability changes. NOT gated by this flag: every payment now issues one immutable receipt snapshot inside its own transaction (PP-5, the foundation post-payment correction depends on), so the payment path writes receipt snapshots whether this flag is on or off. A POST_PAYMENT correction also requires a completed visit, and its owner approval reference may not be reused on another invoice. Activation needs its own owner decision (PP-10) and is blocked while the pre-payment pilot is NO_GO.',
            'default' => false,
            'env_key' => 'FEATURE_RME_INVOICE_POSTPAYMENT_CORRECTION',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'ACTIVATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1',
            'dependencies' => [],
            'rollback_action' => 'Resolve every active POST_PAYMENT correction first (approve, reject or cancel - reject and cancel are not gated by this flag) because an active correction blocks payment of its invoice. Then set the environment override to false and rebuild the config cache as the runtime user. Nothing is deleted or reverted: applied post-payment corrections stay applied, and their frozen documents, receipt snapshots, OPEN overpayment obligations, events and audit rows remain readable. An OPEN obligation is money the clinic owes and must never be removed by a rollback.',
        ],
        'rme.billing_refund_request' => [
            'name' => 'RME Refund Request & Approval',
            'description' => 'MUTATION capability for REQUESTING and DECIDING the pay-back of an OPEN overpayment obligation (Sprint 4/7, owner decisions RF-1 to RF-14 of 2026-10-31, rule 215). A Front Office account at its assigned working branch files a request for the FULL obligation amount (server-derived, RF-4), with a mandatory case-specific Owner approval reference consumed in the shared registry (rule 214) and a mandatory patient request reference (RF-5). A reviewer who is not the maker - Super Admin (every active RME branch), Supervisor RME (its authorized scope) or Kepala Cabang (its own managed branch) - approves or rejects it; an approved request may be revoked before settlement. A billing dispute on the invoice refuses both submission and approval (RF-7). APPROVED never means money left the clinic: no settlement, payment, reversal, patient credit, ledger or KPI change exists in this capability, and the obligation, invoice, items, payments and receipts are never written. Pilot SPN4 only (RF-3) with a source-controlled identity allowlist that ships EMPTY (RF-1a), so even with this flag on nobody can act until verified identities are recorded. While this flag is off every operational refund route answers 404 and every service call is refused; the read-only oversight pages stay readable, like the correction and obligation oversight pages, so records remain auditable after a rollback.',
            'default' => false,
            'env_key' => 'FEATURE_RME_BILLING_REFUND_REQUEST',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'ACTIVATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1',
            'dependencies' => [],
            'rollback_action' => 'Withdraw or revoke every REQUESTED or APPROVED refund request through the service first (the release paths need the flag on), then set the environment override to false and rebuild the config cache as the runtime user. Nothing is deleted: refund requests, their events, the consumed Owner and patient references and the audit rows remain readable, and no money has moved, so the rollback is non-financial. Never edit or delete a refund row to undo a decision.',
        ],
        'rme.billing_refund_settlement' => [
            'name' => 'RME Refund Settlement',
            'description' => 'MUTATION capability for RECORDING that money owed back on an APPROVED refund request actually left the clinic (Sprint 5/7, owner decisions RF-9 C, RF-10 B and RF-11 of 2026-10-31, rule 216). A designated settler who is not the request approver claims the payout (DISBURSING), pays outside the system by bank transfer or cash, and confirms it with the method-specific proof: a bank reference, the destination bank and the last four digits of the account plus a transfer receipt, or a handover number, the recipient relation and the signed handover acknowledgment. Confirmation moves the settlement, the request and the obligation to SETTLED in one transaction at server time and reduces net received (RFS-1); incoming payments are never written. An uncertain transfer is held as OUTCOME_UNKNOWN until reconciled with evidence, never retried automatically. Full amount only. Requires rme.billing_refund_request as well. Pilot SPN4 with a source-controlled settler designation list that ships EMPTY, because the Owner has not appointed the settler role, so even with both flags on nobody can settle. While this flag is off every operational settlement route answers 404 and every service call is refused; the read-only oversight pages stay readable.',
            'default' => false,
            'env_key' => 'FEATURE_RME_BILLING_REFUND_SETTLEMENT',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'ACTIVATION-RME-REFUND-SETTLEMENT-1',
            'dependencies' => ['rme.billing_refund_request'],
            'rollback_action' => 'First resolve every DISBURSING or OUTCOME_UNKNOWN settlement through the service (confirm with evidence, or abort with the no-money attestation), because an in-flight claim means money may already have left. Then set the environment override to false and rebuild the config cache as the runtime user. Nothing is deleted or reverted: SETTLED settlements, their evidence files, events and audit rows, and the SETTLED requests and obligations remain readable and keep reducing net received. Never edit or delete a settlement row, never re-open a settled obligation, and never pay out again to undo a rollback.',
        ],
        'rme.zero_value_closure' => [
            'name' => 'RME Zero-Value Closure (Selesai Tanpa Biaya)',
            'description' => 'MUTATION capability (Sprint 6/7, owner decisions S6-D1..S6-D8 of 2026-11-01, rule 217) to close a LEGITIMATE Rp0 invoice of a Konsultasi Dokter or a Kontrol visit without fabricating a payment. The branch cashier presses "Selesai Tanpa Biaya"; under the invoice row lock the server checks that the original total is Rp0, the invoice is UNPAID with no payment, no correction and no active billing dispute, the visit is cashier_pending, and every line is genuinely free (unit price 0, no discount, never the TRT-FREESC voucher) and is an approved free service: a consultation-category line on any visit, or a Kontrol-service-category line on a VALID server-derived Kontrol (parent examined, same patient) — a Kontrol never makes a normally paid treatment free (owner S6-Q3 revised, 2026-11-01). Lab lines are never closed free. With this flag ON a Kontrol invoice cannot be ISSUED with a normally paid treatment at Rp0 (S6-Q3b), and a cashier batch that pays only an earlier receivable leaves a closable Rp0 visit cashier_pending instead of completing it around an UNPAID Rp0 invoice. The invoice becomes the terminal status NO_CHARGE (not PAID), the visit becomes completed in the same transaction, and one audit row is written. No payment, refund, obligation or receivable is written or erased. While this flag is off the closure route answers 404, every service call is refused, Kontrol issuance and the cashier batch behave exactly as before, and every page renders exactly as before.',
            'default' => false,
            'env_key' => 'FEATURE_RME_ZERO_VALUE_CLOSURE',
            'owner' => 'rme',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'ACTIVATION-RME-RP0-CONTROL-CONSULTATION-1',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and rebuild the config cache as the runtime user. Nothing is reverted: NO_CHARGE invoices stay NO_CHARGE, their visits stay completed, and their audit rows remain. Never set a NO_CHARGE invoice back to UNPAID by hand and never record a payment to stand in for a closure.',
        ],
    ],
];

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-1 — config-BUILD-time runtime override capture
|--------------------------------------------------------------------------
|
| This runs while the config file is being built, which is the only moment the
| environment is still readable on a `config:cache`d deployment. The resolved
| value is a plain scalar, so the array stays var_export()-safe for caching.
|
| An explicit `env_value` written on a flag definition still wins, so a flag can
| opt out of the generic capture if it ever needs a computed override.
*/
foreach ($registry['flags'] as $key => $definition) {
    $envKey = $definition['env_key'] ?? null;

    if (is_string($envKey) && $envKey !== '' && ! array_key_exists('env_value', $definition)) {
        $registry['flags'][$key]['env_value'] = env($envKey);
    }
}

return $registry;
