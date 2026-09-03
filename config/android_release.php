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
    | REVISED by REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
    | Play App Signing is SUPERSEDED: see docs/adr/0010-android-direct-apk-
    | signing-and-distribution.md.
    |
    | DaengtisiaMS now owns the production Android app signing key outright.
    | That is the owner's decision, and it comes with a consequence that must
    | not be softened: under Play App Signing the clinic held only a RESETTABLE
    | upload key, so key loss was a support ticket. Holding the app signing key
    | itself makes loss UNRECOVERABLE.
    |
    | The blast radius is worse here than for a typical app. Android requires an
    | update to be signed by the same certificate; anything else can only be
    | installed after an UNINSTALL, and uninstalling erases app data, and app
    | data is where the Android Keystore device identity lives. So losing this
    | key does not merely end updates — it forces a reinstall that destroys
    | EVERY enrolled device's identity and re-enrols the whole fleet by hand.
    |
    | The custody rules below are therefore deliberately STRICTER than the
    | Play-era ones (three copies, not two; a 90-day restore drill, not 180).
    | Removing Google as custodian removed the safety net, so the governance has
    | to supply it.
    |
    | Platform behaviour retrieved 2026-09-03 from
    | source.android.com/docs/security/features/apksigning and
    | developer.android.com/studio/publish/app-signing.
    |
    */
    'signing' => [
        // The production app signing key is owned and operated by DaengtisiaMS.
        // No third party holds it, and no third party can restore it.
        'app_signing_authority' => 'self_managed_daengtisiams',
        'production_key_custody' => 'daengtisiams_offline_custody',

        // ------------------------------------------------------------------
        // THE TRUST ANCHOR
        // ------------------------------------------------------------------
        //
        // The SHA-256 fingerprint of the production signing certificate, as a
        // lower-case 64-character hex string. This is the ONLY authority an
        // installer can verify an artifact against.
        //
        // Security review caught the first version reading the expected
        // fingerprint out of the release manifest that ships beside the APK.
        // That verifies self-consistency, not authenticity: an attacker who
        // can replace the APK replaces the manifest in the same motion, sets
        // the digest to their artifact and the fingerprint to their own key,
        // and every check passes. It was a checksum wearing a signature's
        // clothes — and with Google Play gone, this control is the ONLY thing
        // between a substituted artifact and a clinic tablet.
        //
        // NULL until the production key is provisioned. While it is null,
        // `android:verify-release` cannot authenticate anything and FAILS
        // CLOSED saying so. That is correct rather than unfortunate: there is
        // no authority to verify against yet, and a manifest cannot vouch for
        // itself. Pinning this is a Phase-4 entry step.
        'production_certificate_sha256' => null,
        'production_certificate_pin_required_before_install' => true,

        // Stated as a value rather than left implicit, because every custody
        // rule below exists because of it.
        'key_loss_recoverable' => false,
        'key_loss_consequence' => 'fleet_wide_reenrolment_after_forced_reinstall',

        // Three custodians, one more than the Play era. With no resettable
        // upload key behind us, two simultaneous losses would be terminal.
        'minimum_custodians' => 3,

        'permitted_storage' => [
            'encrypted_offline_medium_held_by_custodian',
            'organisation_password_manager_secure_note_attachment',
            'encrypted_offsite_recovery_medium',
        ],

        // Where it may never live. Each of these has bitten someone.
        'forbidden_storage' => [
            'git_repository',                 // history is forever, and repositories become public
            'shared_network_drive_unencrypted',
            'developer_workstation_home_directory_unencrypted',
            'ci_secret_used_by_pull_request_jobs', // a fork PR must never reach it
            'chat_or_email_attachment',
            'unencrypted_cloud_sync_folder',
            'clinic_android_device',          // the device never carries the signing key
            'production_vps',                 // the server signs nothing
        ],

        // Pull-request CI executes untrusted code. A key it can reach makes
        // every contributor — and every fork — a release authority.
        'pull_request_ci_may_sign' => false,

        // Signing happens on the designated signing workstation under manual
        // approval, never automatically on push.
        'release_signing_context' => 'designated_signing_workstation_manual_approval',

        // Android 13+ supports rotation via the v3.1 signing block, but it is
        // constrained and does not apply to older devices. Treat the key as
        // permanent and plan custody accordingly rather than banking on it.
        'app_signing_key_rotation' => 'constrained_treat_as_permanent',

        'backup' => [
            'required' => true,
            'copies' => 3,                    // primary + offsite recovery + sealed cold copy
            'encryption' => 'required',
            'offsite_copy_required' => true,
            'restore_test_cadence_days' => 90,
        ],

        'runbook' => 'docs/runbooks/android-signing-key-backup-and-recovery.md',
        'governance_doc' => 'docs/governance/android-production-signing-governance.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution authority
    |--------------------------------------------------------------------------
    |
    | REVISED by REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
    | Managed Google Play is SUPERSEDED.
    |
    | Distribution is a signed APK installed directly by authorised
    | DaengtisiaMS Admin/IT. Google Play, Managed Google Play and Play App
    | Signing are NOT used and are NOT a prerequisite for anything.
    |
    | This removes the Phase-3.5 "pilot exception" entirely rather than
    | widening it. That exception existed only because a self-owned Device
    | Owner tablet has no Google account and so could not install from Managed
    | Google Play. With direct installation as the canonical channel there is
    | no mismatch to except: the pilot and the fleet use the same path.
    |
    | The artifact is an APK, not an App Bundle. A bundle is a Play upload
    | format that Google converts into device APKs; with no Play in the chain
    | there is nothing to perform that conversion, so an AAB is not installable
    | and cannot be the release artifact.
    |
    */
    'distribution' => [
        'canonical_channel' => 'direct_admin_managed_apk',
        'artifact_format' => 'apk',
        'organisation_restricted' => true,

        // Explicit negatives. Asserted by tests, so a future sprint cannot
        // reintroduce a Play dependency without the contract going red.
        'google_play_required' => false,
        'play_app_signing_used' => false,
        'managed_google_play_used' => false,
        'play_developer_account_required' => false,

        // The controlled internal source an authorised installer fetches from.
        // Not chat, not email, not a shared drive: the channel has to carry
        // identity, metadata, a checksum, access control and version history.
        'release_source' => 'protected_github_release_artifact',
        'release_source_requirements' => [
            'artifact_identity',
            'release_metadata',
            'sha256_published',
            'access_controlled',
            'version_history',
        ],
        'public_unauthenticated_artifact_hosting_permitted' => false,

        // The APK filename pattern operators will actually type.
        'artifact_name_pattern' => 'DaengtisiaMS-Clinic-v{versionName}.apk',
        'manifest_name_pattern' => 'DaengtisiaMS-Clinic-v{versionName}.release.json',

        'package_id' => 'com.daengtisia.clinic',

        // Still true, and now for a platform reason rather than a Play one:
        // the package name plus the signing certificate together are the
        // update identity, and neither can be changed after the first install
        // without an uninstall that erases app data.
        'package_id_is_permanent' => true,

        'runbook' => 'docs/runbooks/android-release-distribution-and-rollback.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Device management / kiosk
    |--------------------------------------------------------------------------
    |
    | Unchanged by the distribution revision: Device Owner + Lock Task is the
    | kiosk mechanism, and how the APK arrives has no bearing on that.
    |
    | What DID change is the fleet posture. Phase 3.5 said an EMM was REQUIRED
    | at five devices, and that requirement came from Managed Google Play being
    | the distribution channel — not from kiosk needs. With direct installation
    | canonical, direct admin management stays product-supported at any fleet
    | size. An MDM/EMM may be RECOMMENDED as the fleet grows, but adopting one
    | is an owner decision, not an automatic trigger.
    |
    */
    'device_management' => [
        'supported_models' => [
            // Factory reset, no accounts, our own ClinicDeviceAdminReceiver
            // becomes Device Owner. Works for the pilot and for the fleet.
            'self_owned_device_owner',
            // Available if the owner later adopts an EMM. Not required.
            'emm_managed_dedicated_device',
        ],
        'pilot_model' => 'self_owned_device_owner',
        'fleet_model' => 'self_owned_device_owner',

        // Advisory, not a gate. Phase 3.5's `scale_model_required_at_devices`
        // is deliberately gone: it encoded a Play dependency as a device rule.
        'mdm_recommended_at_devices' => 10,
        'mdm_required' => false,

        'forbidden_kiosk_mechanisms' => [
            'screen_pinning',                 // user-dismissible
            'launcher_replacement_only',      // cosmetic
            'hidden_exit_gesture',            // security by obscurity
            'hardcoded_exit_pin',             // a shared secret on every device
        ],

        // Only relevant if an EMM is ever adopted; harmless to keep true.
        'qr_provisioning_may_carry_secrets' => false,

        'runbook' => 'docs/runbooks/android-clinic-device-provisioning.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Version and rollback policy
    |--------------------------------------------------------------------------
    |
    | REVISED. Phase 3.5 described rollback as "halt the rollout, then
    | forward-fix" — but "halt the rollout" is a Play Console operation and
    | there is no Play Console any more. The forward-fix half survives, because
    | it rests on PLATFORM behaviour rather than on Play.
    |
    | A precision the Play-era wording hid, and which matters more now:
    |
    |   PLATFORM rule  : the update's versionCode must be >= the installed one.
    |   PLAY rule      : Play additionally refused anything not strictly >.
    |   OUR rule       : strictly >, enforced by us.
    |
    | Play was silently supplying the stricter half. Removing Play removes that
    | enforcement, so the governance has to state it: two different builds must
    | never share a versionCode, or "which build is on that tablet?" stops
    | having an answer. The platform would happily install one over the other.
    |
    | Retrieved 2026-09-03 from source.android.com/docs/security/features/
    | apksigning and developer.android.com/studio/publish/versioning.
    |
    */
    'versioning' => [
        'version_code_monotonic' => true,
        'version_code_reuse_permitted' => false,
        'version_code_decrement_permitted' => false,

        // The honest split. Do not conflate them: one is enforced by Android,
        // the other only by us.
        'platform_version_code_rule' => 'update_must_be_greater_or_equal',
        'governance_version_code_rule' => 'new_release_must_be_strictly_greater',

        'rollback_mechanism' => 'stop_distribution_then_forward_fix_with_higher_version_code',

        // There is no supported production downgrade. Installing an older
        // versionCode requires uninstalling first, and uninstalling erases app
        // data — which destroys the Keystore device identity and forces
        // re-enrolment. `adb install -d` only bypasses this for debuggable
        // builds, which a production release is not.
        'production_downgrade_supported' => false,
        'downgrade_requires_uninstall' => true,
        'uninstall_destroys_device_identity' => true,

        'release_metadata_required' => [
            'package_name',
            'version_name',
            'version_code',
            'git_commit',
            'ci_run_id',
            'build_variant',
            'apk_filename',
            'artifact_sha256',
            'signing_certificate_fingerprint_sha256',
            'release_channel',
            'approval_status',
        ],

        // Validated as 64 hex characters. A key holding a placeholder is not
        // provenance, and a manifest that gates an install has to mean something.
        'release_metadata_sha256_fields' => [
            'artifact_sha256',
            'signing_certificate_fingerprint_sha256',
        ],

        'approved_release_states' => ['approved'],
        'approved_build_variants' => ['release'],
        'release_channel_value' => 'direct_admin_managed_apk',
    ],

    /*
    |--------------------------------------------------------------------------
    | Installation authority
    |--------------------------------------------------------------------------
    |
    | Direct installation means a human installs the APK, so who that human is
    | becomes a security control rather than a logistics detail.
    |
    | Application Super Admin is NOT the same authority as device installer.
    | A DaengtisiaMS Super Admin approves a device in Master Data; installing an
    | operating-system package on clinic hardware is a separate, IT-side
    | permission and is not implied by any application role.
    |
    */
    'installation' => [
        'authorized_installer_role' => 'daengtisiams_admin_it',
        'application_super_admin_implies_install_authority' => false,

        // Preferred separation: whoever signs is not automatically whoever
        // installs. Below the headcount for that, record the exception rather
        // than pretending it is not one.
        'signer_and_installer_separation_preferred' => true,

        'workstation_requirements' => [
            'organisation_controlled_machine',
            'malware_hygiene_maintained',
            'artifact_fetched_from_release_source',
            'sha256_verified_before_install',
            'signer_fingerprint_verified_before_install',
            'install_action_recorded',
        ],

        // The signing key does not live on the installer workstation unless
        // that workstation IS the designated signing authority.
        'installer_workstation_holds_signing_key' => false,

        // ADB is acceptable for controlled provisioning. It is not a fleet
        // management strategy, and leaving USB debugging on afterwards turns
        // every kiosk tablet into an open install surface.
        'adb_permitted_for_provisioning' => true,
        'adb_debugging_disabled_after_provisioning' => true,

        'runbook' => 'docs/runbooks/android-direct-apk-installation.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Update contract
    |--------------------------------------------------------------------------
    |
    | The behaviour an operator most needs to predict, and the one where a
    | wrong assumption costs the whole fleet its enrolment.
    |
    */
    'update_contract' => [
        // Same package + same signer + higher versionCode = in-place update.
        // App data survives, so the Android Keystore device identity survives,
        // so the DoctorDevice enrolment survives.
        'in_place_update_preserves_app_data' => true,
        'in_place_update_preserves_device_identity' => true,
        'in_place_update_preserves_enrolment' => true,

        // Anything else cannot install over the existing app at all. Android
        // requires an uninstall first, and that erases app data.
        'signer_mismatch_blocks_update' => true,
        'signer_mismatch_requires_uninstall' => true,

        // Deliberate and load-bearing: possession of an APK is not trust.
        'uninstall_destroys_keystore_identity' => true,
        'clear_app_data_destroys_keystore_identity' => true,
        'reinstall_requires_fresh_enrolment' => true,
        'trust_restored_by_installation_alone' => false,
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
        'requires_google_play_setup' => false,
        'requires_owner_signoff' => true,
        'rollback_owner_named_before_start' => true,

        // Every one of these must be observed on the real device, recorded, and
        // signed off before Phase 4 can claim anything. Emulator results do not
        // satisfy any of them.
        'acceptance_checks' => [
            'artifact_signed_by_production_authority',
            'signing_certificate_fingerprint_recorded',
            'apk_sha256_verified_before_install',
            'release_manifest_verified_before_install',
            'direct_install_performed_by_authorized_admin',
            'adb_debugging_disabled_after_provisioning',
            'in_place_update_preserved_device_enrolment',
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

        /*
        | REVISED by REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
        |
        | Phase 2 ruled that "the flag is created by the phase that needs one",
        | and Phase 3.5 therefore recorded `flag_exists => false`. That revision
        | is the phase that needs one: it ships the complete app-only gate, the
        | doctor/device authorization domain and session binding, and a
        | capability with no switch is a capability that can only be released by
        | editing code under pressure.
        |
        | The rule the Phase 2 stance was actually protecting — "no half-wired
        | switch waiting for someone who assumes it is finished" — is preserved
        | and made STRONGER: the flag is fully wired, and `flag_default_off` is
        | verified against the real feature-flag registry rather than against
        | this file, so this configuration cannot claim an off state the
        | application does not have.
        */
        'flag_exists' => true,
        'flag_key' => 'doctor.trusted_device_enforcement',
        'flag_default_off' => true,

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
            'docs/adr/0010-android-direct-apk-signing-and-distribution.md',
            'docs/runbooks/android-release-distribution-and-rollback.md',
            'docs/runbooks/android-direct-apk-installation.md',
            'docs/runbooks/android-signing-key-backup-and-recovery.md',
            'docs/runbooks/android-device-loss-replacement-and-decommission.md',
            'docs/runbooks/android-clinic-device-provisioning.md',
            'docs/operations/clinic-tablet-procurement-specification.md',
            'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-3-5.md',
            'docs/sprints/revision-doctor-android-direct-apk-signing-distribution-1.md',
        ],

        // ------------------------------------------------------------------
        // Google Play remnant detection
        // ------------------------------------------------------------------
        //
        // The ACTIVE authority must not require Google Play. Historical
        // records may still describe the superseded decision, so they are
        // listed as historical rather than scanned — deleting them would be
        // rewriting history, and scanning them would make the guard red on a
        // repository that already obeys the rule.
        //
        // Every historical file must carry a supersession marker, which is
        // itself checked: that is what keeps "historical" from becoming a
        // parking space for a live contradiction.
        'play_dependency_terms' => [
            'Play App Signing',
            'Managed Google Play',
            'Play Console',
            'Play Developer account',
        ],
        'play_scan_paths' => [
            'config/android_release.php',
            'docs/governance/android-production-signing-governance.md',
            'docs/runbooks/android-release-distribution-and-rollback.md',
            'docs/runbooks/android-direct-apk-installation.md',
            'docs/runbooks/android-signing-key-backup-and-recovery.md',
            'docs/runbooks/android-clinic-device-provisioning.md',
            'docs/operations/clinic-tablet-procurement-specification.md',
        ],
        'play_historical_paths' => [
            'docs/adr/0009-android-production-signing-distribution-and-device-management.md',
            'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-3-5.md',
        ],
        'supersession_marker' => 'SUPERSEDED',

        // The marker must be DECLARED, not merely mentioned.
        //
        // Mutant M11 survived a check that accepted the marker anywhere in the
        // file: it rewrote ADR 0009's status line to "Accepted, current
        // authority" while an incidental body paragraph still contained the
        // word SUPERSEDED, so a document asserting it was live authority passed
        // the supersession check. A header window does not fix it either —
        // that paragraph is near the top too.
        //
        // So the marker has to sit on a STATUS or HEADING line: `**Status:**`
        // for an ADR, a `### ...` banner for a sprint doc (optionally quoted).
        // Prose in a paragraph no longer counts.
        // Anchored to the STATUS VALUE, not merely to a status-or-heading line.
        //
        // The first version allowed `#{1,4}\s[^\n]*SUPERSEDED`, so ANY heading
        // in the file mentioning the word satisfied it — and even a status line
        // reading "Active, current authority. This ADR is NOT SUPERSEDED" passed,
        // because the word appeared somewhere on it. Security review demonstrated
        // both. The marker must now be the status VALUE itself.
        'supersession_declaration_pattern' => '/^\\s*>?\\s*\\*\\*Status:\\*\\*\\s*\\**\\s*SUPERSEDED\\b/mi',

        // A term scan alone cannot tell "requires Play" from "Play is NOT
        // used" — the prose explaining the decision necessarily names the thing
        // it rejects. Rule 141 §14, a third time.
        //
        // So the LOAD-BEARING control is `required_distribution_negatives`
        // below: machine-readable booleans that prose cannot satisfy. Those are
        // what actually prevent a Play dependency returning.
        //
        // The marker scan is a WEAKER BACKSTOP, and its limits are stated
        // rather than overclaimed. It matches markers file-wide with no
        // proximity to the Play mention, so a document that reintroduced a Play
        // requirement while containing an unrelated "not used" elsewhere would
        // pass it. Security review demonstrated exactly that. It is kept
        // because it costs nothing and catches the careless case; it is not
        // relied upon for the careful one.
        'play_negation_markers' => [
            'SUPERSEDED',
            'NOT used',
            'not used',
            'not required',
            'not a prerequisite',
        ],

        // The real assertion. Prose cannot satisfy these.
        'required_distribution_negatives' => [
            'google_play_required',
            'play_app_signing_used',
            'managed_google_play_used',
            'play_developer_account_required',
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

        /*
        | REVISED by REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
        |
        | Phase 3.5 asserted that NO authentication surface referenced the
        | device registry. An app-only gate cannot exist under that rule — the
        | login path has to be able to ask "may this doctor's session exist?".
        |
        | So the check narrows to what was really being protected: the auth path
        | may consult exactly ONE gate, by name, and may never grow its own copy
        | of the device rules. Anything outside this allow-list — a proof
        | service, a direct authorization query, a second flag read — is still a
        | FAIL, because a second decision point is how enforcement starts
        | disagreeing with itself.
        |
        | `DoctorDevice` alone is the module namespace segment, not a decision.
        */
        'enforcement_coupling_allowed_symbols' => [
            'DoctorDevice',
            'DoctorAppLoginGate',
            'DoctorDeviceSessionService',
            'EnsureDoctorDeviceSession',
        ],
    ],
];
