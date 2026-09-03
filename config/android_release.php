<?php

/*
|--------------------------------------------------------------------------
| FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5
| Android production release, signing, distribution and device governance
|--------------------------------------------------------------------------
|
| Phase 3 shipped the Android capability and left FIVE decisions explicitly
| UNRESOLVED: signing custody, backup/recovery, rotation, distribution, and
| device management. That was honest, and it is also a hard Phase 4 blocker —
| a clinic tablet cannot be provisioned until someone owns the signing key.
|
| This file is the machine-readable half of those decisions. The reasoning
| lives in docs/adr/0009-android-production-signing-distribution-and-device-
| management.md; the operational steps live in the runbooks named below. The
| scanner and the `android:release-readiness` command read THIS file, so a
| decision that is edited here without its doc going with it fails the gate.
|
| Nothing here enables anything. Doctor device enforcement stays OFF; this
| file contains no switch that could turn it on, and the readiness gate
| asserts that it never grows one.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Signing authority
    |--------------------------------------------------------------------------
    |
    | The decision that unblocks everything else.
    |
    | Play App Signing means Google holds the app signing key in its KMS. The
    | clinic holds only an UPLOAD key — and an upload key, unlike an app
    | signing key, can be reset if it is lost or compromised. That single
    | property is why this model was chosen: it converts the worst outcome in
    | Android release management (key loss => the app can never be updated,
    | only uninstalled and reinstalled, destroying every device enrolment)
    | from unrecoverable into a support ticket.
    |
    | Retrieved 2026-09-03 from developer.android.com/studio/publish/app-signing
    | and support.google.com/googleplay/android-developer/answer/9842756.
    |
    */
    'signing' => [
        // The near-permanent identity that end devices verify. Held by Google
        // KMS under Play App Signing — never on a workstation, never in CI.
        'app_signing_authority' => 'play_app_signing',

        // The only key material the clinic holds. Resettable via Play Console
        // if lost or compromised, which is the whole point.
        'upload_key_authority' => 'clinic_offline_custody',

        // Two custodians so a single departure or a single lost laptop is not
        // an outage. Roles, not names — names live in the governance doc,
        // which is where a person leaving is actually noticed.
        'minimum_custodians' => 2,

        // Where the upload keystore may live.
        'permitted_storage' => [
            'encrypted_offline_medium_held_by_custodian',
            'organisation_password_manager_secure_note_attachment',
        ],

        // Where it may never live. Each of these has bitten someone.
        'forbidden_storage' => [
            'git_repository',                 // history is forever, and public one day
            'shared_network_drive_unencrypted',
            'developer_workstation_home_directory_unencrypted',
            'ci_secret_used_by_pull_request_jobs', // a fork PR must never reach it
            'chat_or_email_attachment',
            'unencrypted_cloud_sync_folder',
        ],

        // Normal CI must not be able to sign a production release. PR CI runs
        // untrusted code by definition; giving it the upload key makes every
        // contributor a release authority.
        'pull_request_ci_may_sign' => false,

        // Release signing happens from a controlled operator context, gated by
        // a protected environment, never automatically on push.
        'release_signing_context' => 'protected_release_environment_manual_approval',

        // Play App Signing key rotation is limited and the choice is close to
        // permanent; the upload key is the resettable one. Recording this
        // stops a future sprint assuming rotation is routine.
        'app_signing_key_rotation' => 'constrained_treat_as_permanent',
        'upload_key_rotation' => 'resettable_via_play_console',

        'backup' => [
            'required' => true,
            'copies' => 2,                    // primary custodian + offline recovery copy
            'encryption' => 'required',
            'offsite_copy_required' => true,
            'restore_test_cadence_days' => 180,
        ],

        'runbook' => 'docs/runbooks/android-signing-key-backup-and-recovery.md',
        'governance_doc' => 'docs/governance/android-production-signing-governance.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution authority
    |--------------------------------------------------------------------------
    |
    | Managed Google Play private app: organisation-restricted, centrally
    | updatable, and the same channel that provisions dedicated devices at
    | scale. Publishing a private app creates a Play Developer account for the
    | organisation with no registration fee.
    |
    | The pilot exception is real and is written down rather than discovered:
    | a self-owned Device Owner tablet has NO Google account on it (that is a
    | precondition of `dpm set-device-owner`), so it cannot install from
    | Managed Google Play. For the single Phase 4 pilot device the Play-SIGNED
    | artifact is downloaded from Play Console and side-loaded over ADB. The
    | signing identity is identical to production; only the transport differs.
    |
    */
    'distribution' => [
        'canonical_channel' => 'managed_google_play_private_app',
        'artifact_format' => 'aab',           // Play App Signing requires a bundle
        'organisation_restricted' => true,

        'pilot_exception' => [
            'permitted' => true,
            'channel' => 'adb_sideload_of_play_signed_artifact',
            'max_devices' => 1,
            'requires_owner_signoff' => true,
            // The artifact still comes from Play, signed by the production
            // authority. Sideloading a locally signed build is NOT this.
            'artifact_source' => 'play_console_signed_universal_apk',
        ],

        // Deleting a private app does not release its package name, so the
        // package id is effectively permanent from first publish.
        'package_id_is_permanent' => true,
        'package_id' => 'com.daengtisia.clinic',

        'runbook' => 'docs/runbooks/android-release-distribution-and-rollback.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Device management / kiosk
    |--------------------------------------------------------------------------
    |
    | Two supported models, chosen by fleet size rather than preference. The
    | app already treats "not Device Owner" as a supported state, so it runs
    | correctly under both — it simply does not lock, and says so, instead of
    | implying protection it lacks.
    |
    | Screen pinning is not on this list and never will be: it is dismissible
    | by the user, so it is not a security control.
    |
    */
    'device_management' => [
        'supported_models' => [
            // Phase 4 pilot. Factory reset, no accounts, our own
            // ClinicDeviceAdminReceiver becomes Device Owner over ADB.
            'self_owned_device_owner',
            // Fleet. The EMM's DPC is Device Owner and lock-task-allowlists
            // our package by policy. Our app is a managed payload, not the DO.
            'emm_managed_dedicated_device',
        ],
        'pilot_model' => 'self_owned_device_owner',
        'scale_model' => 'emm_managed_dedicated_device',

        // The fleet size at which hand-provisioning stops being defensible.
        'scale_model_required_at_devices' => 5,

        'forbidden_kiosk_mechanisms' => [
            'screen_pinning',                 // user-dismissible
            'launcher_replacement_only',      // cosmetic
            'hidden_exit_gesture',            // security by obscurity
            'hardcoded_exit_pin',             // a shared secret on every device
        ],

        // A QR provisioning payload is readable by anyone who can photograph
        // the screen. Google's own guidance says it must not carry secrets.
        'qr_provisioning_may_carry_secrets' => false,

        'runbook' => 'docs/runbooks/android-clinic-device-provisioning.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Version and rollback policy
    |--------------------------------------------------------------------------
    |
    | The rollback rule is the one people get wrong. Play refuses a versionCode
    | that is not greater than the current one, and Android itself blocks
    | downgrade installs. So "roll back" on Play means: halt the rollout (new
    | users fall back to the previous release) and then ship the older CODE
    | under a HIGHER versionCode. There is no decrement, ever.
    |
    | Retrieved 2026-09-03 from developer.android.com/studio/publish/versioning
    | and support.google.com/googleplay/android-developer/answer/16285429.
    |
    */
    'versioning' => [
        'version_code_monotonic' => true,
        'version_code_reuse_permitted' => false,
        'version_code_decrement_permitted' => false,
        'rollback_mechanism' => 'halt_rollout_then_forward_fix_with_higher_version_code',
        // The first release on a track cannot be halted — there is nothing to
        // fall back to. That makes the pilot's first build a one-way door.
        'first_release_cannot_be_halted' => true,

        'release_metadata_required' => [
            'version_name',
            'version_code',
            'git_commit',
            'ci_run_id',
            'artifact_sha256',
            'signing_certificate_fingerprint_sha256',
            'release_channel',
        ],

        // Validated as 64 hex characters. A key holding a placeholder is not
        // provenance, and a manifest that gates a release has to mean something.
        'release_metadata_sha256_fields' => [
            'artifact_sha256',
            'signing_certificate_fingerprint_sha256',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server / client compatibility
    |--------------------------------------------------------------------------
    |
    | A server deploy must not brick a tablet that has not updated yet. No
    | minimum-version hard block is implemented in Phase 3.5 — implementing one
    | now would be the fastest way to lock a clinic out of its own records.
    |
    */
    'compatibility' => [
        'minimum_client_version_block_implemented' => false,
        'grace_period_days' => 30,
        'device_api_breaking_change_requires_new_path' => true,
        'server_deploy_may_break_older_client' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Clinic tablet specification
    |--------------------------------------------------------------------------
    |
    | Anchored to Android Enterprise Recommended (AER), which is the only
    | vendor-neutral validation that actually binds an OEM to a published
    | security-update end date. Figures below are the AER knowledge-worker
    | minimums for current Android releases, retrieved 2026-09-03 from
    | support.google.com/work/android/answer/16751490.
    |
    | Concrete models are deliberately NOT pinned here. The AER directory is
    | the live authority and devices are archived from it when an OEM stops
    | shipping updates; a model list frozen into a config becomes a
    | procurement trap. The dated shortlist lives in the procurement doc.
    |
    */
    'device_specification' => [
        'authority' => 'android_enterprise_recommended',
        'authority_directory' => 'https://androidenterprisepartners.withgoogle.com/devices/',

        'minimum' => [
            'android_version' => 13,
            'ram_gb' => 4,
            'storage_gb' => 32,
            'cpu_ghz' => 1.4,
            'architecture' => '64-bit',
            'screen_inches' => 10.0,
            'encrypted_by_default' => true,
            'hardware_backed_keystore' => true,   // Phase 3 identity depends on it
            'device_owner_capable' => true,
            'security_update_cadence_days' => 90,
            'guaranteed_update_end_date_published' => true,
        ],

        'recommended' => [
            'android_version' => 14,
            'ram_gb' => 6,
            'storage_gb' => 128,
            'screen_inches' => 11.0,
            'stylus' => true,                     // handwriting RME is the primary input
            'strongbox' => true,                  // preferred, never mandated
            'battery_hours' => 8,
        ],

        'pilot_preferred' => [
            'android_version' => 14,
            'ram_gb' => 6,
            'storage_gb' => 128,
            'stylus' => true,
            'aer_listed' => true,
            'local_warranty' => true,
        ],

        // StrongBox is requested opportunistically by the app and falls back to
        // the TEE. Mandating it would refuse a lot of acceptable clinic
        // hardware for a marginal gain.
        'strongbox_mandatory' => false,

        'procurement_doc' => 'docs/operations/clinic-tablet-procurement-specification.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 pilot
    |--------------------------------------------------------------------------
    */
    'pilot' => [
        'device_count' => 1,
        'branch_count' => 1,
        'requires_real_device' => true,
        'requires_production_signing_identity' => true,
        'requires_owner_signoff' => true,
        'rollback_owner_named_before_start' => true,

        // Every one of these must be observed on the real device, recorded, and
        // signed off before Phase 4 can claim anything. Emulator results do not
        // satisfy any of them.
        'acceptance_checks' => [
            'artifact_signed_by_production_authority',
            'signing_certificate_fingerprint_recorded',
            'real_android_keystore_identity_generated',
            'hardware_backed_result_recorded_truthfully',
            'strongbox_result_recorded_truthfully',
            'device_owner_established',
            'lock_task_active',
            'home_escape_blocked',
            'recents_escape_blocked',
            'external_browser_escape_blocked',
            'reboot_returns_to_clinic_app',
            'external_origin_blocked',
            'cleartext_blocked',
            'tls_error_fails_closed',
            'device_enrolment_verified',
            'device_active_accepted',
            'device_disabled_denied',
            'device_revoked_denied',
            'doctor_login_through_trusted_device',
            'daily_branch_lock_verified',
            'device_branch_intersection_verified',
            'room_selection_verified',
            'other_room_patients_hidden',
            'cross_room_direct_access_denied',
            'doctor_rme_print_denied',
            'doctor_odontogram_print_denied',
            'session_invalidated_after_revoke',
            'rollback_verified',
        ],

        'plan_doc' => 'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-3-5.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement rollout
    |--------------------------------------------------------------------------
    |
    | Designed here, activated nowhere. Phase 3.5 ends with enforcement OFF.
    | There is deliberately no flag: Phase 2 decided that a flag is created by
    | the phase that needs one, and creating it "in preparation" is how a
    | half-wired switch gets flipped by someone who assumes it is finished.
    |
    */
    'enforcement' => [
        'active' => false,
        'doctor_browser_login_denied' => false,
        'flag_exists' => false,
        'may_be_enabled_in_phase' => 5,

        'stages' => [
            'off',
            'pilot_branch_or_device',
            'selected_doctors_approved_device_set',
            'all_ready_doctors',
            'global_doctor_enforcement',
        ],
        'current_stage' => 'off',

        // Turning enforcement on without these is how a clinic loses a day.
        'global_prerequisites' => [
            'real_device_pilot_passed',
            'every_enforced_doctor_has_an_active_device',
            'spare_device_available_per_branch',
            'device_loss_runbook_rehearsed',
            'rollback_to_browser_login_proven',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner contract
    |--------------------------------------------------------------------------
    |
    | What `android:release-readiness` and the governance suite actually check.
    | Patterns live here rather than inline in the scanner so the scanner's own
    | source never contains the string it forbids — Phase 3 learned that the
    | hard way when a TLS guard went red on the comment explaining itself.
    |
    */
    'scanner' => [
        'gradle_module_path' => 'android/daengtisia-clinic',
        'app_build_file' => 'android/daengtisia-clinic/app/build.gradle.kts',
        'gradle_wrapper' => 'android/daengtisia-clinic/gradlew',
        'gradle_wrapper_required_index_mode' => '100755',

        // Extraction is ANCHORED to this nested path, never a file-wide search
        // for `release {`. String literals are preserved on purpose (the
        // forbidden tokens contain one), so a file-wide match is hijackable by
        // any earlier string containing `release {` — the scan would then read
        // a decoy and affirm that the real build type is clean.
        'release_block_path' => ['android', 'buildTypes', 'release'],

        // The command is designed to run on untrusted fork pull requests, so
        // every read it performs is bounded.
        'max_scanned_file_bytes' => 1048576,

        // A release build that borrows the debug key ships a publicly known
        // signing identity. Any of these inside the `release { }` block is a
        // production release signed by a key everyone has.
        'forbidden_release_signing_tokens' => [
            'signingConfigs.getByName("debug")',
            'signingConfigs.debug',
            'getByName("debug").signingConfig',
        ],

        // Committed key material. Checked against the git index, not the
        // working tree: an ignored file on disk proves nothing about history.
        'forbidden_tracked_path_patterns' => [
            '*.jks',
            '*.keystore',
            '*.p12',
            '*.pfx',
            'keystore.properties',
            'signing.properties',
        ],

        'required_gitignore_patterns' => [
            'android/**/*.jks',
            'android/**/*.keystore',
            'android/**/*.p12',
            'android/**/*.pfx',
            'android/**/keystore.properties',
            'android/**/signing.properties',
        ],

        // The armed set. A hardcoded subset in the scanner guarded two of six
        // patterns and let the other four — including the properties files that
        // carry the keystore passwords — be deleted in silence.
        'key_material_guard_minimum' => [
            '*.jks',
            '*.keystore',
            '*.p12',
            '*.pfx',
            'keystore.properties',
            'signing.properties',
        ],
        'gitignore_guard_minimum' => [
            'android/**/*.jks',
            'android/**/*.keystore',
        ],

        // Presence assertions. A stripper that removes too much, or a scan
        // pointed at the wrong file, must fail loudly rather than pass with
        // nothing to look at.
        // Asserted against the EXTRACTED release block, so they must be things
        // that live INSIDE it. `release` would be useless here: the extraction
        // regex already matched it, so it can never fail.
        'required_release_block_markers' => [
            'isMinifyEnabled',
            'proguardFiles',
        ],

        'required_documents' => [
            'docs/governance/android-production-signing-governance.md',
            'docs/adr/0009-android-production-signing-distribution-and-device-management.md',
            'docs/runbooks/android-release-distribution-and-rollback.md',
            'docs/runbooks/android-signing-key-backup-and-recovery.md',
            'docs/runbooks/android-device-loss-replacement-and-decommission.md',
            'docs/runbooks/android-clinic-device-provisioning.md',
            'docs/operations/clinic-tablet-procurement-specification.md',
            'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-3-5.md',
        ],

        // Any of these appearing in an authentication or session surface means
        // enforcement has started. Phase 3 already pins this; the readiness
        // command re-reports it so one command answers "is it still off?".
        // Broadened past app/Http/Middleware, which is not where this codebase
        // actually registers route middleware. The canonical pattern is a class
        // under app/Modules/**/Middleware aliased in bootstrap/app.php (the
        // `visit.room` precedent), and a Gate::before or login listener in a
        // provider would be just as invisible to a narrow scan. The one
        // invariant this phase most needs to hold is the one worth over-
        // covering.
        // Must exist AND be scanned. A surface that silently vanishes is
        // coverage loss, not a pass, so absence here is a FAIL.
        'enforcement_coupling_surfaces' => [
            'app/Http/Controllers/Auth',
            'app/Http/Middleware',
            'app/Services/Auth',
            'app/Modules/ClinicVisit/Middleware',
            'bootstrap/app.php',
        ],

        // Locations that do NOT exist today and must be scanned the moment they
        // appear. Phase 3 ruled that device middleware, if ever added, lives in
        // app/Modules/DoctorDevice/Middleware and never app/Http/Middleware —
        // which makes this the exact path Phase 4/5 enforcement would take.
        // Absence is expected; presence is scanned like any other surface.
        'enforcement_coupling_watch_surfaces' => [
            'app/Modules/DoctorDevice/Middleware',
            'app/Modules/DoctorDevice/Listeners',
        ],

        // app/Providers is deliberately NOT scanned. RepositoryServiceProvider
        // already references DoctorDevice to register the Phase 2 policies, and
        // registering a policy for the admin registry is not enforcement
        // coupling. Adding it here would make the check go red on a codebase
        // that obeys the rule — the failure mode that trains people to delete
        // the check.
        'enforcement_coupling_token' => 'DoctorDevice',
    ],
];
