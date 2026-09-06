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
        // ARMED by PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1.
        //
        // It was null for as long as there was nothing to point at, and while
        // it was null `android:verify-release` authenticated nothing and failed
        // closed saying so. The key now exists — generated once on custodian 1,
        // backed up twice, restored from both destination copies — so the
        // authority this field names is real and the control is live.
        //
        // The value is the SAME certificate as `..._recorded` below, and the
        // custody state machine refuses any scan where those two disagree. The
        // fields are not redundant: RECORDED is a FACT about a key that exists,
        // PINNED is a DECISION to enforce, and this task made only the second.
        //
        // Required form: exactly 64 contiguous hex characters, lower case.
        // The colon-separated form `keytool -list` prints is REJECTED rather
        // than normalized — a normalizer that strips separators also quietly
        // accepts values a reviewer would reject. Case is compared
        // insensitively everywhere, so an upper-case paste is a formatting
        // error and never a substitution alarm.
        //
        // CHANGING THIS VALUE IS A SIGNER ROTATION, not a config tweak. The key
        // is unrecoverable and `app_signing_key_rotation` is
        // `constrained_treat_as_permanent`; a different certificate here means
        // every enrolled tablet must be UNINSTALLED to accept an update, which
        // erases the Keystore identity and re-enrols the fleet by hand.
        //
        // Arming it unlocks nothing downstream. No APK has been built, no
        // tablet touched, the pilot rung is authorized but not activated, and
        // doctor enforcement is off.
        'production_certificate_sha256' => '79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9',
        'production_certificate_pin_required_before_install' => true,

        // ------------------------------------------------------------------
        // THE TRUST ANCHOR'S EVIDENCE, WHICH IS NOT THE TRUST ANCHOR
        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1
        // ------------------------------------------------------------------
        //
        // The SHA-256 fingerprint of the production signing certificate that
        // now exists, recorded as a lower-case 64-character hex string.
        //
        // Until this task those two facts were one field, because both were
        // false at the same time: no key existed, so nothing could be
        // recorded and nothing could be pinned. They separate here for the
        // first time, and collapsing them again would be a real loss:
        //
        //   RECORDED  is a FACT.  This certificate is the one the production
        //             private key belongs to. It became true the moment the
        //             key was generated and it can never change, because the
        //             key can never be regenerated.
        //
        //   PINNED    is a DECISION to enforce. It arms
        //             `android:verify-release`, which is the only thing
        //             standing between a substituted APK and a clinic tablet.
        //             It is deliberately still null: pinning is the separate
        //             task PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1.
        //
        // This is the same split REVISION-PRODUCTION-SIGNING-CUSTODIAN1-
        // ENCRYPTED-VAULT-1 made when one ambiguous `disk_encryption` boolean
        // was carrying two different claims. One field answering two
        // questions is how a record starts lying without anyone editing it.
        //
        // A fingerprint is a PUBLIC identifier — it is derived from the
        // certificate, which ships inside every signed APK. Committing it
        // discloses nothing. The private key, the keystore and its passwords
        // are not here, are not anywhere in this repository, and are not on
        // the production server or in CI.
        //
        // `custody_state_machine_consistent` binds this to the custody record
        // in both directions: a key claimed without a recorded certificate is
        // fiction, and a certificate recorded without a key is fiction too.
        'production_certificate_sha256_recorded' => '79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9',

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

        // ------------------------------------------------------------------
        // CUSTODY DESIGNATION
        // PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1
        // ------------------------------------------------------------------
        //
        // Everything above this point is POLICY: how many copies, which media
        // are permitted, what may never happen. It was written before anyone
        // had been named. This block is the owner's DESIGNATION — who holds
        // what, where, under which endpoint controls — so that a later and
        // separate provisioning task has somewhere lawful to put the key at
        // the moment it is created.
        //
        // READ THIS BEFORE READING ANYTHING BELOW IT:
        //
        //   A destination being READY means it is prepared to receive a copy.
        //   It does NOT mean a copy is there. The two are recorded as separate
        //   flags — `*_ready` and `*_created` — precisely so they can never be
        //   read as the same statement.
        //
        //   PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 changed the answer
        //   to the second question, and only the second. When this block was
        //   written there was no production signing key anywhere in the world,
        //   no backup copy on any medium and no rehearsed recovery, and every
        //   `*_created` flag below was false. A key now exists, both encrypted
        //   backups exist on their destinations, and recovery has been
        //   rehearsed from both. The `*_ready` flags did not move, because
        //   readiness was already true and is not what changed.
        //
        //   What has NOT happened, and is asserted as not having happened:
        //   the certificate is not pinned (`production_certificate_sha256` is
        //   still null), no production APK has been built or signed, no device
        //   has been enrolled, and no enforcement — pilot or global — is on.
        //
        // No secret material belongs here. Not a passphrase, not a hardware
        // serial, not a filesystem UUID, not a private address. This file is
        // committed, and a committed secret is a published secret. The
        // `custody_records_no_secret_material` scanner check enforces that
        // rather than trusting anyone to remember it.
        'custody' => [
            // The custody lifecycle, in order. A state may only be claimed
            // when every state before it is genuinely true.
            'states' => [
                'deferred',
                'designated',
                'ready_for_provisioning',
                'key_provisioned',
                'backups_created',
                'recovery_verified',
                'operational',
            ],

            // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 advanced this from
            // 'ready_for_provisioning'. The key was generated once, on
            // custodian 1, inside the LUKS2 vault; both encrypted backups were
            // written to their destinations, and both were RESTORED FROM THE
            // DESTINATION COPY — never from the staging source — and proven to
            // yield a usable private key. `custody_status_matches_recorded_facts`
            // now refuses any status the flags below do not actually support.
            'status' => 'recovery_verified',

            // Three DESTINATIONS, which is not the same claim as three
            // independent people. See `single_party_can_reach_all_copies`.
            'model' => 'three_destination_custody',

            // ---------------------------------------------------------------
            // The negative facts. These are the whole point of this record.
            // ---------------------------------------------------------------
            // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
            //
            // These were the negative facts, and the paragraph above them has
            // been left standing on purpose. It is the record of what this
            // block meant before a key existed, and a future reader needs to
            // see that these flags moved because artifacts were created — not
            // because a sentence was rewritten.
            //
            // Every one is an ARTIFACT claim. Each backup was verified by
            // RESTORING FROM THE DESTINATION COPY and reading the recovered
            // entry back, never by re-hashing the file that was sent.
            'production_signing_key_provisioned' => true,
            'backup_1_key_copy_created' => true,
            'backup_2_key_copy_created' => true,

            // Custodian 3 carries the sealed-cold and the offsite property on
            // a single medium — the concentration already declared in
            // `single_party_access_compensating_controls`. One artifact
            // satisfies all three of these flags, and saying so plainly is
            // better than three flags implying three separate media.
            'sealed_cold_backup_created' => true,
            'offsite_backup_created' => true,

            // Both destination copies were restored and produced a
            // PrivateKeyEntry under the production alias whose certificate
            // fingerprint equals the primary's, and the recovered private key
            // was proven usable by generating a certificate signing request.
            // A deliberately corrupted disposable copy of the offsite artifact
            // was rejected by the decryption step, so the positive result is
            // known not to be vacuous.
            'recovery_verified' => true,

            // ...and the readiness facts they are deliberately kept apart from.
            'primary_signing_workstation_ready' => true,
            'backup_destination_1_ready' => true,
            'sealed_cold_destination_ready' => true,
            'offsite_destination_ready' => true,

            'custodians' => [
                // -----------------------------------------------------------
                // CUSTODIAN 1 — primary signing authority.
                // The only place the first production key may be generated.
                // -----------------------------------------------------------
                'custodian_1' => [
                    'role' => 'primary_signing_authority',
                    'roles' => [
                        'primary_signing_authority',
                        'primary_key_holder',
                    ],
                    'responsible_party' => 'Raushan Fikri Ridha / IT',
                    'media' => 'primary_it_workstation',
                    'medium_type' => 'workstation',
                    'os' => 'ubuntu',
                    'location' => 'Cabang Pusat',
                    'authorized_access' => ['IT'],

                    // -------------------------------------------------------
                    // REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1
                    //
                    // This workstation is NOT full-disk encrypted, and saying
                    // so plainly is the point of this field.
                    //
                    // The record used to read `disk_encryption => true`. It
                    // was measured against the machine during the provisioning
                    // attempt and found untrue: `/` and `/home` mount straight
                    // from raw ext4 partitions (`/dev/sda1`, `/dev/sda3`),
                    // there is no `/dev/mapper` device, no LUKS signature, no
                    // eCryptfs and no fscrypt. A single ambiguous boolean had
                    // been carrying a claim nobody had checked, and the
                    // release gate was reporting PASS on the strength of it.
                    //
                    // So the claim is split in two. The host fact is recorded
                    // as the false thing it is, and the control that actually
                    // protects the key gets its own explicit record below.
                    // Nothing here may be read as "the workstation is
                    // encrypted" — it is not.
                    // -------------------------------------------------------
                    'host_full_disk_encryption' => false,

                    // The control that does the work: a dedicated LUKS2
                    // container, created non-destructively beside the host
                    // filesystem, which is where the production signing
                    // keystore must live once it exists. Closed at rest and
                    // opened only by an interactive custody action.
                    //
                    // `verified => true` records that the container was
                    // opened, mounted, written to, closed, reopened and
                    // proven to hide its contents while closed — not that a
                    // boolean was typed. See the signing custody runbook.
                    //
                    // No path, UUID or size is recorded here: none of them
                    // improve the governance claim and each widens what a
                    // committed file discloses about the operator's machine.
                    'primary_secret_storage' => [
                        'type' => 'dedicated_encrypted_vault',
                        'encryption' => 'luks2',
                        'verified' => true,
                        'default_state' => 'closed',
                        'auto_unlock' => false,
                        'plaintext_keyfile' => false,
                        'outside_repository' => true,
                    ],

                    'login_password' => true,
                    'screen_lock' => true,
                    'initial_key_generation_authority' => true,
                    'status' => 'holds_primary_key',
                ],

                // -----------------------------------------------------------
                // CUSTODIAN 2 — encrypted backup destination 1.
                // A destination, never a signing authority.
                // -----------------------------------------------------------
                'custodian_2' => [
                    'role' => 'encrypted_backup_destination',
                    'roles' => [
                        'encrypted_backup_destination',
                    ],
                    'responsible_party' => 'IT + Admin Klinik',
                    'media' => 'admin_klinik_workstation',
                    'medium_type' => 'workstation',
                    'os' => 'windows',
                    'location' => 'Klinik Daengtisia',
                    'authorized_access' => ['IT', 'Admin Klinik'],

                    // Renamed from `disk_encryption` by
                    // REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1
                    // so the field says which claim it is making. The value is
                    // unchanged and remains an operator DECLARATION about a
                    // remote Windows machine — this scanner has never measured
                    // it, and after what the same boolean concealed on
                    // custodian 1 that distinction is worth stating.
                    //
                    // It is not load-bearing for secrecy either way: whatever
                    // this machine's disk does, custodian 2's copy has to
                    // arrive inside its own encrypted container, because
                    // Admin Klinik can log in here and must not reach the
                    // signing key by doing so.
                    'host_full_disk_encryption' => true,

                    'login_password' => true,
                    'screen_lock' => true,
                    'initial_key_generation_authority' => false,
                    'status' => 'holds_encrypted_backup',
                ],

                // -----------------------------------------------------------
                // CUSTODIAN 3 — sealed-cold AND offsite destination.
                //
                // The owner assigned both properties to one medium. The
                // governance table had expected them on separate holders, so
                // the concentration is recorded openly in
                // `single_party_access_compensating_controls` rather than
                // being smoothed over.
                // -----------------------------------------------------------
                'custodian_3' => [
                    'role' => 'sealed_cold_destination',
                    'roles' => [
                        'encrypted_backup_destination',
                        'sealed_cold_destination',
                        'offsite_destination',
                    ],
                    'responsible_party' => 'IT',
                    'media' => 'usb',
                    'medium_type' => 'removable_medium',
                    'location' => 'Kantor Management Klinik',
                    'authorized_access' => ['IT'],
                    'initial_key_generation_authority' => false,
                    'status' => 'holds_encrypted_backup',
                ],
            ],

            // Rules that bind the media themselves, independent of who holds
            // them. Each exists because the alternative has burned someone.
            'media_rules' => [
                // The USB may keep whatever unrelated data is already on it.
                // Requiring a wipe would be this record inventing an authority
                // it was never granted, over data belonging to someone else.
                'existing_unrelated_data_wipe_required' => false,

                // But signing material goes into an encrypted container or it
                // does not go on at all.
                'plaintext_signing_material_permitted' => false,
                'encrypted_container_required' => true,

                // A key and the passphrase that opens it on one medium is one
                // theft, not two.
                'password_stored_with_key_permitted' => false,

                // Cold means cold.
                'offline_when_not_in_approved_use' => true,
                'general_daily_use_after_custody_permitted' => false,
            ],

            // Where the FIRST production key may be generated, and where it
            // may never be. The scanner cross-checks the custodian entries
            // against these rather than trusting the flags alone.
            'permitted_initial_key_generation_media' => [
                'primary_it_workstation',
            ],
            'forbidden_initial_key_generation_media' => [
                'production_vps',
                'pull_request_ci',
                'ci_runner',
                'self_hosted_ci_runner',
                'admin_klinik_workstation',
                'usb',
                'clinic_android_device',
                'cloud_vm',
                'temporary_container',
                'browser',
            ],

            // ---------------------------------------------------------------
            // The honest caveat.
            // ---------------------------------------------------------------
            //
            // "Three custodians" in the governance table meant three
            // independent holders, so that compromising one person could not
            // reach every copy. The owner's designation names three
            // DESTINATIONS, and IT is responsible for custodian 1 and 3 and
            // holds access to custodian 2. One party can therefore reach all
            // three copies.
            //
            // That is an accepted residual risk, not an oversight, and it is
            // written down here so no future reader mistakes three media for
            // three people.
            'single_party_can_reach_all_copies' => true,
            'single_party_access_compensating_controls' => [
                'every_copy_encrypted_at_rest',
                'passphrase_held_separately_from_every_medium',
                'sealed_cold_copy_stays_offline_and_sealed_between_declared_operations',
                'restore_drill_every_90_days_and_after_any_custodian_change',
                'second_independent_human_custodian_is_an_open_item_before_operational_state',
            ],

            'runbook' => 'docs/runbooks/android-signing-key-backup-and-recovery.md',
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
            // PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — the pilot model.
            //
            // An existing, in-use tablet. The production-signed APK is
            // installed alongside whatever else is on the device, no reset, no
            // Device Owner, no lock task. It is listed FIRST because it is the
            // only model the Phase 4A pilot may use, and the owner has refused
            // a factory reset for that pilot.
            'self_owned_non_destructive',
            // Factory reset, no accounts, our own ClinicDeviceAdminReceiver
            // becomes Device Owner. Still the model for a DEDICATED clinic
            // device, and still where the six deferred kiosk acceptance checks
            // in `phase_4a.deferred_to_dedicated_device_phase` are proved.
            'self_owned_device_owner',
            // Available if the owner later adopts an EMM. Not required.
            'emm_managed_dedicated_device',
        ],

        // CHANGED by PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1, from
        // 'self_owned_device_owner'.
        //
        // Android grants Device Owner only on a device with no accounts on it,
        // which in practice means immediately after a factory reset. The owner
        // has refused a factory reset for this pilot, and the pilot tablet is
        // already in use. So the previous value described a pilot that could
        // not be run: an activation agent reading it had to wipe the device
        // against an explicit instruction, record six acceptance checks as PASS
        // falsely, or stall. See `phase_4a` for what replaces the properties
        // Device Owner was providing.
        'pilot_model' => 'self_owned_non_destructive',

        // Unchanged. A dedicated, wiped, Device-Owner tablet is still the right
        // answer for a rolled-out fleet; it is just not available for a pilot
        // on a tablet that is already in service.
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
            // NOT `git_commit`. The evidence describing a release is committed
            // AFTER the build, so "the commit" is ambiguous exactly where it
            // must not be: one of the two commits built the artifact and the
            // other only describes it. No production manifest exists yet, so
            // this is the one moment the name can be made unambiguous for free.
            'release_source_sha',
            'release_source_tree',
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
        // 40 hex, validated with an end-of-string anchor. The predecessor
        // shipped two regexes without /D, where PHP's `$` also matches before
        // a trailing newline — so "<hex>\n" validated, and the mismatch was
        // then reported as a substituted signing key.
        'release_metadata_git_object_fields' => [
            'release_source_sha',
            'release_source_tree',
        ],

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
    | Release provenance — which commit built the APK, and which only says so
    |--------------------------------------------------------------------------
    |
    | A signed release needs two commits and they are not interchangeable.
    |
    |   RELEASE SOURCE   the exact commit and tree the APK was compiled from.
    |                    Frozen, clean, and green in CI BEFORE the signing
    |                    ceremony, because the signature attests to those bytes.
    |
    |   FINAL CANDIDATE  the evidence-only commit that records what the ceremony
    |                    produced — artifact digest, signer fingerprint, CI run.
    |                    It cannot exist earlier: those facts do not exist until
    |                    after the build is signed. It is what gets merged and
    |                    deployed, and it must pass CI again on its own tree.
    |
    | Conflating them is provenance forgery, not bookkeeping: an evidence commit
    | that presents itself as the build source asserts a build that never
    | happened from that tree, and the APK's signature would appear to vouch
    | for code the signer never saw.
    |
    */
    'release_provenance' => [
        'model' => 'two_commit',

        'release_source' => [
            'meaning' => 'the exact commit and tree the production APK was compiled from',
            'manifest_fields' => ['release_source_sha', 'release_source_tree'],
            'must_be_clean_worktree' => true,
            'must_pass_ci_before_signing' => true,
        ],

        'final_candidate' => [
            'meaning' => 'the evidence-only commit recording the signed artifact, later merged and deployed',
            'may_differ_from_release_source' => true,
            'must_not_claim_to_have_built_the_apk' => true,
            'must_pass_ci_before_merge' => true,
            'permitted_changes' => [
                'release_evidence_manifest',
                'sprint_documentation',
                'governance_records',
            ],
        ],
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
    | Real-device hardware preflight
    |--------------------------------------------------------------------------
    |
    | EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1.
    |
    | The distinction this block exists to make, because getting it wrong is
    | how a device passes a security gate it never met:
    |
    |   a DEVICE CAPABILITY FLAG is not proof of anything.
    |
    | `android.hardware.hardware_keystore` says the platform declares a
    | hardware keystore. It does not say the key this app actually generated
    | went there. A keystore can fall back to software for a specific key —
    | for an unsupported algorithm, a spec the hardware refuses, a StrongBox
    | request on hardware without one — and the feature flag keeps reading
    | exactly the same. Reading the flag and concluding "hardware-backed" is
    | reading the brochure and concluding the car started.
    |
    | The authoritative proof is a property of the GENERATED KEY: obtain a
    | `KeyInfo` for the private key that `DeviceIdentityManager` actually
    | produced and read `KeyInfo.getSecurityLevel()` (API 31+). That value
    | describes where THAT key lives. `isInsideSecureHardware()` is the
    | pre-31 compatibility path only, is deprecated, and must never be the
    | preferred proof on a device that can answer the newer question.
    |
    | Nothing here activates anything. A preflight PASS closes exactly one
    | gate — the hardware one — and leaves signing custody, the production
    | key, the certificate pin, enrolment and the enforcement ladder exactly
    | where they were.
    |
    */
    'real_device_preflight' => [

        // ------------------------------------------------------------------
        // The rule (durable; applies to every future pilot device)
        // ------------------------------------------------------------------

        // Read the key, not the brochure.
        'capability_flag_is_sufficient_proof' => false,
        'authoritative_proof' => 'android_keystore_keyinfo_security_level',
        'preferred_api' => 'KeyInfo.getSecurityLevel',
        'legacy_compatibility_api' => 'KeyInfo.isInsideSecureHardware',
        'legacy_api_is_preferred_proof' => false,

        // TRUSTED_ENVIRONMENT is the TEE; STRONGBOX is a separate secure
        // element. Either satisfies Phase 4A. SOFTWARE does not, and neither
        // does UNKNOWN_SECURE — an unattested claim is not a measurement.
        'accepted_security_levels' => [
            'TRUSTED_ENVIRONMENT',
            'STRONGBOX',
        ],
        'rejected_security_levels' => [
            'SOFTWARE',
            'UNKNOWN_SECURE',
        ],

        // A key that can leave the device is not a device identity.
        'private_key_must_be_non_exportable' => true,

        // StrongBox is requested opportunistically and falls back to the TEE.
        // Mandating it would refuse most acceptable clinic hardware.
        'strongbox_required' => false,
        'strongbox_preferred' => true,

        // A device whose boot chain is unverified, or whose bootloader or
        // vbmeta is unlocked, can be re-imaged under the app. The keystore
        // guarantee is only as good as the boot state carrying it.
        'verified_boot_state_required' => 'green',
        'bootloader_must_be_locked' => true,
        'vbmeta_must_be_locked' => true,
        'emulator_permitted' => false,

        // Serials identify a physical object a person carries. Governance
        // needs the class of device, never the instance, so evidence uses a
        // logical label and the serial stays out of the repository.
        'device_serial_may_be_committed' => false,
        'device_reference_style' => 'logical_label',

        // ------------------------------------------------------------------
        // Recorded evidence — the pilot tablet, observed on real hardware
        // ------------------------------------------------------------------
        'evidence' => [
            'recorded_by' => 'EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1',
            'evidence_doc' => 'docs/sprints/evidence-phase4a-real-device-keyinfo-preflight-1.md',

            'device_label' => 'PHASE4A_PILOT_TABLET_01',
            'manufacturer' => 'samsung',
            'model' => 'SM-X236B',
            'product' => 'gta11pxx',
            'android_version' => '16',
            'api_level' => 36,
            'security_patch' => '2026-07-05',

            'physical_device' => true,
            'emulator' => false,
            'verified_boot_state' => 'green',
            'bootloader_locked' => true,
            'vbmeta_locked' => true,

            // Capability flags: recorded as context, never as the proof.
            'hardware_keystore_capability' => true,
            'app_attest_key_capability' => true,
            'strongbox_available' => false,

            // The existing protocol suite, run targeted on the real tablet.
            // It proves the enrolment protocol end to end; on its own it does
            // NOT establish where the key lives.
            'protocol_suite' => 'android/daengtisia-clinic/app/src/androidTest/java/com/daengtisia/clinic/DeviceIdentityInstrumentedTest.kt',
            'protocol_tests_run' => 7,
            'protocol_tests_failed' => 0,
            'protocol_result' => 'PASS',

            // The hardware gate itself: a temporary instrumentation probe that
            // read KeyInfo off the key DeviceIdentityManager generated. It was
            // an evidence probe and was never committed.
            'keyinfo_probe_committed' => false,
            'keyinfo_tests_run' => 1,
            'keyinfo_tests_failed' => 0,
            'keyinfo_result' => 'PASS',

            // The measurement.
            'key_security_level' => 'TRUSTED_ENVIRONMENT',
            'hardware_backed_private_key' => true,
            'private_key_exportable' => false,
            'strongbox_backed' => false,

            // The probe used the instrumented test alias, which setUp() and
            // tearDown() both destroy. The production alias was never touched.
            'test_key_alias' => 'daengtisiams_instrumented_test_key',
            'production_key_alias_untouched' => 'daengtisiams_device_identity_v1',
            'production_device_identity_created' => false,

            'debug_app_residue' => false,
            'test_instrumentation_residue' => false,
        ],

        // ------------------------------------------------------------------
        // What a preflight PASS does NOT unlock
        // ------------------------------------------------------------------
        //
        // Stated as explicit negatives because the damaging failure mode here
        // is not a wrong value, it is an absent one being read as consent.
        'unlocks' => [
            'signing_custody_completion' => false,
            'production_signing_key_provisioning' => false,
            'certificate_pinning' => false,
            'production_apk_build' => false,
            'production_apk_installation' => false,
            'production_enrollment' => false,
            'pilot_enforcement_activation' => false,
            'global_enforcement_activation' => false,
        ],
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

        /*
        | REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 — the
        | owner has authorized a Phase 4A PILOT. That authorization is recorded
        | here; it is NOT exercised anywhere. `active`, `doctor_browser_login_denied`
        | and `current_stage` above are unchanged, and the feature flag still
        | defaults to false, so no doctor's login changes because of this block.
        |
        | The distinction this records is the one that matters clinically:
        | one named doctor at one named branch is a recoverable experiment,
        | and the global rung is a clinic-wide lockout if the fleet is not
        | enrolled first. They are different decisions and they get different
        | phases.
        */
        'owner_signoff' => [
            'phase_4a_pilot_authorized' => true,

            // Recorded so nothing downstream can read this block as an
            // instruction. Authorization is permission to act later.
            'pilot_activated' => false,

            // The rung this sign-off reaches, by name from `stages` below.
            // Explicitly NOT `global_doctor_enforcement`.
            'authorized_scope' => 'pilot_branch_or_device',

            // Human authority, deliberately as written rather than as an id.
            // Resolving these to production rows belongs to the phase that
            // actually runs the pilot; an id copied between environments is
            // how the wrong doctor gets enrolled.
            'pilot_doctor' => 'drg Karmila',
            'pilot_branch' => 'Cabang Sunu',
            'rollback_owner' => 'Custodian 1 / Raushan Fikri Ridha / IT',
            'rollback_action' => 'The rollback owner disables pilot enforcement and returns the ladder to off. Browser login works again immediately; no device is unenrolled and no authorization is altered.',

            'recorded_by' => 'REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1',
        ],

        /*
        | The phase in which each SCOPE may FIRST be switched on.
        |
        | Split because one number could not carry two answers. Phase 4A may
        | run the pilot rung. Nothing before Phase 5 may run the global one,
        | and the owner sign-off above deliberately does not reach it.
        */
        'pilot_may_be_enabled_in_phase' => '4A',
        'global_may_be_enabled_in_phase' => 5,

        /*
        | RETAINED. Rules 141/142, ADR 0009/0011 and both Phase-4 closure
        | records cite this key by name, so removing it would orphan every
        | citation. What it may no longer do is be read two ways: it is the
        | GLOBAL bound, it now says which scope it bounds, and the scanner
        | fails if it ever disagrees with `global_may_be_enabled_in_phase`.
        |
        | It does NOT mean "nothing may be enabled before phase 5". The pilot
        | rung is bounded separately, and above.
        */
        'may_be_enabled_in_phase' => 5,
        'may_be_enabled_in_phase_scope' => 'global_doctor_enforcement',

        'stages' => [
            'off',
            'pilot_branch_or_device',
            'selected_doctors_approved_device_set',
            'all_ready_doctors',
            'global_doctor_enforcement',
        ],

        // Named rather than assumed to be the last element: a stage appended
        // for a future rung would silently move "global" if this were derived
        // from position.
        'global_enforcement_stage' => 'global_doctor_enforcement',

        'current_stage' => 'off',

        // Turning enforcement on without these is how a clinic loses a day.
        'global_prerequisites' => [
            'real_device_pilot_passed',
            'every_enforced_doctor_has_an_active_device',
            'spare_device_available_per_branch',
            'device_loss_runbook_rehearsed',
            'rollback_to_browser_login_proven',
        ],

        /*
        | WHO enforcement applies to.
        |
        | PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1. `stages` above has listed
        | `pilot_branch_or_device` since Phase 3.5, and nothing implemented it:
        | `doctor.trusted_device_enforcement` is one boolean, and its registry
        | entry says plainly that turning it on "DENIES browser login for every
        | account holding the Doctor role". The two reachable states were
        | therefore nobody and everybody. `App\Support\Android\
        | AndroidDoctorEnforcementScope` reads this block and turns the middle
        | stage into something real.
        |
        | Both values below have to be changed for a fleet-wide denial to be
        | possible, and neither of them is the enforcement flag. Arming the flag
        | on its own can no longer lock a fleet out.
        */
        'scope' => [
            // The POLICY only. This file never reads the environment, on
            // purpose: `android:release-readiness` must be safe to run on a fork
            // pull request, and that property is kept with a bright line rather
            // than a per-key judgement about which environment reads happen to
            // be harmless. An asserted absence of environment reads is worth
            // more than an audited list of permitted ones — and the governance
            // suite enforces exactly that on this file, so the prose here avoids
            // the function-call spelling it forbids.
            //
            // The host-set values — which mode, and which doctor — live in the
            // runtime config named here.
            'runtime_config' => 'doctor_device_enforcement',

            'modes' => ['pilot', 'unscoped'],

            // What a deployment gets when it declares nothing: a pilot scope
            // with no target, which enforces for nobody.
            'default_mode' => 'pilot',

            // Fleet-wide denial, and deliberately NOT reachable from the
            // environment.
            //
            // Every doctor in every branch is the blast radius, so flipping this
            // is a source-control change that goes through review — not a
            // variable somebody sets on a host at 2am during an incident.
            // Phase 5, once `global_prerequisites` above are met.
            'global_permitted' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4A — the non-destructive doctor pilot
    |--------------------------------------------------------------------------
    |
    | PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1.
    |
    | THE DEFECT THIS BLOCK EXISTS TO FIX.
    |
    | `pilot.acceptance_checks` records thirty-three checks for Phase 4A. Six of
    | them — device_owner_established, lock_task_active, home_escape_blocked,
    | recents_escape_blocked, external_browser_escape_blocked and
    | reboot_returns_to_clinic_app — are Device Owner and lock-task properties.
    | Android grants Device Owner only on a device carrying no accounts, i.e.
    | after a factory reset. The owner has refused a factory reset for this
    | pilot and the tablet is already in service, so those six checks were
    | unsatisfiable and the recorded pilot could not be run as written.
    |
    | RULE 147 — WHAT THE DEFERRAL GIVES UP.
    |
    | Deleting six checks is the easy half. Lock task was also the thing that
    | physically stopped a doctor reaching a browser, so removing it moves the
    | app-only boundary entirely onto the server. That boundary does hold there:
    | DoctorAppLoginGate denies a browser session from the ABSENCE of a
    | server-verified device binding, re-checks it on every protected request,
    | and a signature over a server-issued nonce is the only thing that can
    | create one. Leaving the app is not a bypass, because leaving the app
    | grants nothing.
    |
    | But it holds only while enforcement is actually armed FOR THIS DOCTOR, and
    | that is what `enforcement.scope` had no way to express. Without kiosk and
    | without scoping there was no state in which "app-only pilot" was true:
    | either every doctor in every branch was denied a browser, or the pilot
    | doctor could simply open Chrome. So the deferral and the scope mechanism
    | are one change, not two.
    |
    */
    'phase_4a' => [
        'model' => 'self_owned_non_destructive',

        // The owner's decision, recorded so it cannot be re-litigated by
        // someone reading only the fleet model.
        'factory_reset_required' => false,
        'device_owner_required' => false,
        'full_kiosk_required' => false,
        'managed_google_play_required' => false,

        // Logical label only. `real_device_preflight.device_serial_may_be_
        // committed` is false and stays false: a serial in source control is a
        // hardware identifier nobody needs in order to run a pilot.
        'pilot_device_label' => 'PHASE4A_PILOT_TABLET_01',

        // Moved OUT of Phase 4A, not deleted. They remain the acceptance
        // criteria for a dedicated, wiped, Device-Owner device, and they remain
        // listed in `pilot.acceptance_checks` as the historical Phase 3.5
        // record. A check that is deferred is a check somebody still owes.
        'deferred_to_dedicated_device_phase' => [
            'device_owner_established',
            'lock_task_active',
            'home_escape_blocked',
            'recents_escape_blocked',
            'external_browser_escape_blocked',
            'reboot_returns_to_clinic_app',
        ],

        // What replaces them. The first three are device hygiene an operator
        // performs by hand because no Device Owner policy is there to enforce
        // it. The last two are the ones that actually matter: without kiosk,
        // app-only is a server property, and a server property that is not
        // armed for the pilot doctor is not a property at all.
        'compensating_controls' => [
            'device_screen_lock_enabled',
            'usb_debugging_disabled_and_verified',
            'install_unknown_sources_permission_revoked_after_install',
            'app_only_boundary_enforced_server_side',
            'pilot_enforcement_scope_armed_before_app_only_claimed',
        ],

        // The Phase 4A matrix: the twenty-seven applicable Phase 3.5 checks,
        // plus seven the non-destructive model introduces. Executed by the
        // ACTIVATION sprint, never by this one.
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

            // New, and specific to a device that was not wiped.
            'device_screen_lock_verified',
            'usb_debugging_disabled_verified',
            'unknown_sources_permission_revoked_after_install',
            'pilot_enforcement_scope_armed_for_pilot_doctor_only',
            'pilot_doctor_browser_login_denied',
            // The one that proves the scope mechanism did its job. A doctor at
            // another branch must still be able to log in through a browser
            // while the pilot is enforced. If this fails, the pilot has become
            // a fleet-wide lockout.
            'non_pilot_doctor_browser_login_still_works',
            'pilot_enforcement_disarmed_on_rollback',
        ],

        // Run in this order before the APK is allowed near the tablet. Every
        // comparison is exact and full-length; a prefix match on a fingerprint
        // is not a verification.
        // The subset the readiness gate insists on. Declared here rather than
        // inline in the scanner so the scanner's own source never carries the
        // tool names it is asserted not to invoke — the Phase 3 self-match
        // failure class, which cost a working TLS guard its life.
        'preinstall_verification_required' => [
            'apk_sha256',
            'apksigner_verify',
            'signer_matches_pinned_certificate',
            'application_id',
            'version_code',
        ],

        'preinstall_verification' => [
            'apk_filename',
            'apk_sha256',
            'apksigner_verify',
            'signer_certificate_sha256',
            'signer_matches_pinned_certificate',
            'application_id',
            'version_name',
            'version_code',
        ],

        // Everything this sprint did NOT do. Asserted false by the readiness
        // gate, so "prepared" can never be read as "started".
        'activation_boundary' => [
            'apk_distributed' => false,
            'apk_installed' => false,
            'tablet_touched' => false,
            'adb_used' => false,
            'device_enrolled' => false,
            'pilot_activated' => false,
            'pilot_browser_denial_active' => false,
            'global_enforcement_active' => false,
        ],

        'preparation' => [
            'states' => [
                'off',
                'preparation',
                'ready_for_pilot',
                'activation',
                'pilot_active',
                'stabilization',
                'phase4a_go',
            ],

            // Where this sprint stops.
            'state' => 'ready_for_pilot',
            'terminal_preparation_state' => 'ready_for_pilot',

            // Rule 147: an unrecognised state must not be read as ready, and a
            // state at or past activation must not be reachable by a sprint
            // that touched no hardware. Listed explicitly rather than derived
            // from position, because a reordering of `states` would otherwise
            // silently change which ones imply a running pilot.
            'states_that_imply_activation' => [
                'activation',
                'pilot_active',
                'stabilization',
                'phase4a_go',
            ],

            'requires_signing_key_access' => false,
            'requires_device_access' => false,
            'requires_adb' => false,
        ],

        /*
        | Rollback, decided before activation rather than during an incident.
        |
        | Uninstall is absent from every routine route on purpose: it destroys
        | the Android Keystore identity and the enrolment with it, so the cheap
        | reflex is the one that costs a re-enrolment.
        */
        'rollback' => [
            'server_side_pilot_problem' => 'Set the enforcement scope mode away from pilot, or clear ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID, and clear the config cache. The pilot doctor returns to browser login immediately. No device is unenrolled and no authorization is altered.',
            'device_authorization_problem' => 'Disable or revoke the (doctor, device) authorization through Approval Device Dokter. The open session stops on its next protected request because the gate re-checks the binding every time. The device and its key survive.',
            'bad_apk_behaviour' => 'Forward-fix. Build a new APK from the same application id and the same signing identity with a strictly higher versionCode, and update in place. Never a downgrade and never an uninstall.',
            'app_unusable' => 'Disarm pilot enforcement first so the doctor can work in a browser the same minute, then diagnose the app with no clinical time pressure. Do not uninstall to "start clean".',
            'signer_mismatch' => 'STOP. A different signer cannot update in place, and uninstalling to force it destroys the device identity. Do not re-sign and do not uninstall; establish which artifact is wrong first.',
            'device_identity_lost' => 'Treat as a new device: enrol again, approve the new (doctor, device) pair through Approval Device Dokter, and record why the previous identity was lost. Installation alone restores no trust.',
        ],

        // The recorded release manifest, named outright.
        //
        // The first version of the preparation scanner interpolated a version
        // string from config into this path. Nothing could reach that config in
        // production, so it was not exploitable — but it was a path assembled
        // from a value, in a class whose entire claim is that it only reads, and
        // the scanner now refuses anything that is not exactly this basename
        // inside exactly this directory. A read-only auditor should not have a
        // traversal surface to argue about.
        'release_manifest' => 'docs/evidence/android-release/DaengtisiaMS-Clinic-v0.3.0-phase3.release.json',
        'release_manifest_directory' => 'docs/evidence/android-release',

        'operator_checklist' => 'docs/runbooks/android-phase4a-pilot-activation-checklist.md',

        // Of the events below, these three must be individually accountable: an
        // approval with no named approver and a rejection with no recorded
        // reason are the two that get re-litigated a month later.
        'audit_events_mandatory' => [
            'authorization_approved',
            'authorization_rejected_with_reason',
            'session_invalidated_with_reason',
        ],

        'audit_events_required' => [
            'device_enrollment_requested',
            'authorization_pending_created',
            'authorization_approved',
            'authorization_rejected_with_reason',
            'device_disabled',
            'device_revoked',
            'session_invalidated_with_reason',
            'pilot_enforcement_scope_changed',
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

        /*
        | How git says it refused to open a repository owned by somebody else.
        |
        | The deployed tree is root-owned while the application runs as an
        | unprivileged identity (INFRA-SEC-RUNTIME-1), which is the boundary
        | working as designed — so this refusal is expected and the scanner
        | answers it with a trust scoped to exactly that one repository for
        | exactly one invocation. This marker exists only so the operator-facing
        | message can name what happened instead of blaming the repository.
        |
        | Matching on it never changes a verdict. An unreadable index is a FAIL
        | whether or not this string appears.
        */
        'git_ownership_error_markers' => [
            'detected dubious ownership',
        ],

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
            // apksigner writes a v4 signature (.idsig) BESIDE every APK it
            // signs. The list covered .apk/.aab and every keystore extension
            // and missed this one, so the first real ceremony left a signing
            // artifact that git offered to commit. It carries no private key;
            // it is still signing output, and signing output is not source.
            'android/**/*.idsig',
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

        // The canonical Device Owner / lock-task acceptance checks.
        //
        // PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 first had the scanner walk
        // `phase_4a.deferred_to_dedicated_device_phase` to prove nothing was
        // dropped, which proves nothing: deleting an entry from the list also
        // deletes it from the loop, and the requirement vanishes silently. The
        // authority for WHICH checks are owed has to sit outside the list that
        // says where they went.
        'dedicated_device_kiosk_checks' => [
            'device_owner_established',
            'lock_task_active',
            'home_escape_blocked',
            'recents_escape_blocked',
            'external_browser_escape_blocked',
            'reboot_returns_to_clinic_app',
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
            'docs/sprints/revision-android-release-readiness-phase4a-pilot-authority-1.md',
            'docs/sprints/evidence-phase4a-real-device-keyinfo-preflight-1.md',
            'docs/sprints/production-android-signing-custody-readiness-1.md',
            // PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1. The checklist is
            // listed here as well as in `phase_4a.operator_checklist` on
            // purpose: the activation sprint is told to follow it line by line,
            // and a runbook that can go missing without reddening a gate is a
            // runbook that will go missing.
            'docs/runbooks/android-phase4a-pilot-activation-checklist.md',
            'docs/sprints/phase4a-doctor-android-pilot-preparation-1.md',
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
