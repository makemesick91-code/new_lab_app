<?php

/**
 * CICD-CTRL-3 — Dedicated Self-Hosted CI Runner contract.
 *
 * Read-only registry that declares the self-hosted runner safety contract so
 * App\Support\Cicd\SelfHostedRunnerScanner and
 * App\Services\Foundation\SelfHostedRunnerGovernanceService can verify the
 * Foundation Evidence Gates workflow, the runner health script, and the
 * production-isolation guard honour it — without mutating anything.
 *
 * SAFETY CONTRACT:
 *  - GitHub Actions stays the authoritative CI control plane. A self-hosted
 *    runner adds execution capacity; it never becomes a second source of truth
 *    and never makes CI independent of a GitHub Actions outage.
 *  - The production VPS is NEVER a general CI runner. Deployment stays on its
 *    own boundary (scripts/deploy-vps-runner.sh, executed ON the VPS).
 *  - The runner holds no production environment file, no production database
 *    credential, and no production SSH private key.
 *  - Heavy jobs opt in by an explicit label set; they never inherit a bare
 *    `self-hosted` label that any other runner could satisfy.
 *  - Runner outage must QUEUE a required job, never let it silently pass.
 *  - Falling back to GitHub-hosted is an explicit operator action and must run
 *    an equivalent gate, never a weaker one.
 */
return [
    'sprint' => 'CICD-CTRL-3',

    // Master switch for the CICD-CTRL-3 governance surface.
    'enabled' => (bool) env('CI_RUNNER_GOVERNANCE_ENABLED', true),

    // Files the governance layer inspects. Missing any is a hard FAIL.
    'files' => [
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'deploy_workflow' => '.github/workflows/deploy-vps.yml',
        'runner_health_script' => 'scripts/ci/self-hosted-runner-health.sh',
        'classifier_script' => 'scripts/ci/resolve-gates.sh',
        'ci_runtime_wrapper' => 'scripts/ci/self-hosted-php.sh',
    ],

    // Identity of the dedicated runner. Registration uses exactly this name so
    // an unrelated runner can never silently pick up DaengtisiaMS heavy jobs.
    'runner' => [
        'name' => env('CI_RUNNER_NAME', 'daengtisia-ci-01'),
        'service_user' => env('CI_RUNNER_SERVICE_USER', 'github-runner'),
        'work_folder' => '_work',
    ],

    /*
     * The label set a heavy self-hosted job must target. `daengtisia-ci` is the
     * custom label that makes the target unambiguous — a bare `self-hosted`
     * runs-on is forbidden (see `forbidden_workflow_markers`).
     */
    'required_labels' => [
        'self-hosted',
        'linux',
        'x64',
        'daengtisia-ci',
    ],

    // The custom label that uniquely identifies this project's runner.
    'custom_label' => 'daengtisia-ci',

    /*
     * Jobs that MUST remain on GitHub-hosted runners. The classifier decides
     * which gates run; it must itself always run on neutral infrastructure so a
     * dead self-hosted runner can never stop the routing decision from being
     * made. Deployment never runs on a general CI runner.
     */
    'always_github_hosted_jobs' => [
        'classify',
        'deploy',
    ],

    /*
     * Heavy jobs approved for self-hosted execution. Migration is incremental
     * (CICD-CTRL-3 starts with the critical regression gate); adding an entry
     * here requires extending the tests and re-running the governance check.
     */
    'self_hosted_heavy_jobs' => [
        'critical_test_gate_self_hosted',
    ],

    /*
     * Runner mode resolution. `github-hosted` is the fail-safe default: if the
     * repository variable is unset, or the runner is decommissioned, CI keeps
     * running on GitHub-hosted infrastructure with no code change.
     */
    'runner_mode' => [
        'default' => 'github-hosted',
        'allowed' => ['github-hosted', 'self-hosted'],
        'repository_variable' => 'CI_RUNNER_MODE',
        'dispatch_input' => 'runner_mode',
    ],

    /*
     * Markers that MUST appear in the workflow for the self-hosted routing to
     * be considered safe.
     */
    'required_workflow_markers' => [
        'daengtisia-ci',
        'runner_mode',
        'ci:assert-non-production-database',
        // The self-hosted variant must execute through the pinned CI runtime,
        // never against the host PHP.
        'scripts/ci/self-hosted-php.sh',
        'REQUIRED_PHP',
    ],

    /*
     * Markers that MUST NEVER appear in the workflow.
     *
     * `runs-on: self-hosted` (bare, unlabelled) would let any self-hosted
     * runner on the account pick up the job. `paths-ignore` is inherited from
     * the CICD-CTRL-1 contract and stays forbidden.
     */
    'forbidden_workflow_markers' => [
        'runs-on: self-hosted',
        'paths-ignore',
    ],

    /*
     * Filter tokens the NSF-R011 critical gate MUST select — CICD-CTRL-3D.
     *
     * The critical filter is a fixed allowlist and the selective gate covers
     * only Inventory/Lab/Ui/Permission/AccessControl, so nothing required ever
     * executed tests/Feature/Cicd. The CI system's own regression tests could
     * therefore break while every required PR gate stayed green — which is
     * exactly what happened: SelfHostedHealthFailClosedTest asserted "no
     * container engine reachable" by pointing PATH at the host's real bin
     * directories, passed on the dedicated runner and on a developer machine,
     * and failed only on GitHub-hosted images that ship /usr/bin/podman. It
     * surfaced three and a half hours later in the full suite, after the merge.
     *
     * A gate that cannot fail on its own tooling is not a gate. Every token
     * here must appear in the filter of BOTH critical gate variants, so the
     * coverage holds whichever runner the job is routed to.
     */
    'critical_gate_required_filters' => [
        'Cicd',
    ],

    /*
     * Test suites the NSF-R011 critical gate MUST actually select —
     * CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1.
     *
     * `critical_gate_required_filters` above declares that a TOKEN is present.
     * That is not the same claim as "this suite runs", and the difference was
     * load-bearing: every file in tests/Unit/Services/Monitoring was selected
     * only because its filename happened to begin with `PilotPerformanceSnapshot`.
     * MonitoringLogSourceResilienceTest — the suite pinning that the monitor
     * reads where the application actually writes — did not, so it was absent
     * from the critical gate while its seven siblings ran. Coverage rested on a
     * filename coincidence, and renaming any sibling would have removed it just
     * as silently.
     *
     * Each entry is a repository-relative test file. The scanner derives the
     * name PHPUnit filters against and requires some token in EVERY critical
     * variant's filter to match it. A declared file that no token selects, or
     * that no longer exists, FAILS the gate — a rename can move coverage, but
     * it can never quietly delete it.
     *
     * Membership is the monitor-truthfulness contract: the suites that keep the
     * production monitor from reporting OK without evidence (log-source
     * resolution, timestamp faithfulness, physical scan coverage, the undated
     * severity ladder, restore-drill timestamps). Scope stays bounded on
     * purpose — tests/Feature/Foundation/FoundationMonitoring* covers the
     * read-only MON-1 console rather than the monitor's own verdict, and is
     * deliberately NOT promoted here. Adding a suite is a governance decision,
     * not a reflex.
     */
    'critical_gate_mandatory_suites' => [
        // MONITORING-LOG-SOURCE-RESILIENCE-1 — the monitor reads where the
        // application writes; a missing or unreadable source fails closed.
        'tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php',
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotLogSourceTest.php',

        // MONITORING-LOG-TIMESTAMP-ROLLOVER-1 — a malformed date is rejected by
        // literal round-trip, never silently rolled into a valid one.
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotTimestampRolloverTest.php',

        // MONITORING-LOG-COVERAGE-ANCHOR-INJECTION-1 — coverage is byte-offset,
        // so log content can never certify bytes that were not read.
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotCoverageAnchorTest.php',

        // MONITORING-UNDATED-SEVERITY-ESCALATION-1 — undated and fresh errors
        // share one severity ladder.
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotUndatedSeverityTest.php',

        // The analyzer, classifier and resource section behind every verdict,
        // plus the command that is the only production entry point.
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotLogAnalyzerTest.php',
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotClassifierTest.php',
        'tests/Unit/Services/Monitoring/PilotPerformanceSnapshotResourceSectionTest.php',
        'tests/Unit/Console/PilotPerformanceSnapshotCommandTest.php',

        // RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1 — drill evidence is only as
        // trustworthy as the timestamp it carries.
        'tests/Feature/Foundation/RestoreDrillTimestampFaithfulnessTest.php',

        // RESTORE-DRILL-EVIDENCE-READ-STATE-1 — an unreadable or unread artifact
        // is never reported as a malformed one, and no read fault is permitted
        // to become more permissive than the flattened state it replaced.
        'tests/Feature/Foundation/RestoreDrillEvidenceReadStateTest.php',

        // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — clinical evidence stays off any
        // publicly served disk and is readable only through an authenticated,
        // policy-gated route.
        //
        // This DELIBERATELY BROADENS the registry beyond monitor truthfulness.
        // The precedent is earned: the exposure this suite pins was live in
        // production, was proven with an unauthenticated fetch, and was missed
        // for weeks precisely because nothing in CI asserted it. A control that
        // exists but is never selected by the gate is not a control.
        'tests/Feature/Storage/ClinicalEvidencePrivacyTest.php',

        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 1 — a Doctor is
        // confined to the ACTIVE patients of the treatment room they are online
        // in, and may never print an RME or odontogram.
        //
        // Registered under the ClinicalEvidencePrivacy precedent: this is an
        // authorization boundary, and the only thing standing between a doctor
        // and another room's live patients is a policy guard plus a query scope.
        // Without an explicit token the suite matched no critical filter and 24
        // of its 28 cases ran nowhere in CI — a control nothing selects is not a
        // control.
        'tests/Feature/RME/DoctorRoomScopedAccessAndPrintDenyTest.php',

        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 2 — the clinic
        // device registry: lifecycle, terminal revocation, branch binding,
        // audit, and the guarantee that authentication is NOT coupled to the
        // registry while enforcement is off.
        //
        // Same precedent as the sibling entries above: this is an
        // authorization surface plus a standing no-enforcement contract, and
        // Phase 1 already proved that a suite no filter token selects is a
        // control nothing enforces.
        'tests/Feature/DoctorDevice/DoctorDeviceRegistryTest.php',
        'tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php',

        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — the
        // cryptographic enrolment protocol and the device HTTP channel,
        // including the standing proof that authentication is NOT coupled to
        // the device registry while enforcement is off.
        'tests/Feature/DoctorDevice/DoctorDeviceEnrollmentProtocolTest.php',
        'tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php',

        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — the release
        // governance that stands between a clinic tablet and a debug-signed,
        // unrecoverably-keyed, un-rollback-able production app.
        //
        // Declared rather than left to the `DoctorDevice` filter token for the
        // reason Phase 1 established: a token match is an accident of naming,
        // and nothing would tell us the day it stopped selecting. What this
        // pins is not hypothetical — a debug-signed release ships a publicly
        // known signing identity, and a lost app signing key destroys every
        // device enrolment in the fleet.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidReleaseGovernanceTest.php',

        // REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1 — the
        // direct-APK release contract. With Google Play out of the chain,
        // nothing between the build and a clinic tablet checks that the
        // artifact is the one DaengtisiaMS signed except this verifier, so the
        // gate has to select the suite that pins it.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidDirectApkReleaseTest.php',

        // REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 — the
        // git trust boundary the readiness gate depends on, and the Phase 4A
        // pilot authorization's bound. Both are the kind of control that fails
        // silently: a wildcard trust exemption still reports GO, and a
        // sign-off read one rung too broadly is a clinic-wide lockout. The
        // gate has to select the suite that would notice.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidRuntimeIdentityReadinessTest.php',

        // EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1. The hardware gate
        // for the pilot tablet: the key the app generates must measure
        // TRUSTED_ENVIRONMENT or STRONGBOX and be non-exportable, a device
        // capability flag is never accepted as proof of it, and the pass must
        // move signing, enrolment and enforcement not at all.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidRealDevicePreflightTest.php',

        // PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1. The custody
        // designation for a key that cannot be replaced, and the separation
        // between a destination being READY and a backup EXISTING.
        //
        // Declared rather than left to the `DoctorDevice` filter token for the
        // reason Phase 1 established. The specific failure this selects for is
        // silent in the worst way: config drifting until the scanner announces
        // three encrypted backups that were never written, read by someone
        // deciding whether it is safe to proceed. A gate that stopped running
        // would not say so, and the first evidence of the drift would be a
        // lost key with no recoverable copy.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningCustodyReadinessTest.php',

        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1. The production
        // signing identity now EXISTS, which inverts the risk this registry
        // entry guards. The custody suite above was declared because config
        // could drift into claiming backups nobody wrote; this one is declared
        // because config can now drift into claiming a release is installable.
        //
        // It pins the split between the certificate being RECORDED (evidence
        // that a key exists) and PINNED (the trust anchor that arms
        // android:verify-release), and it proves the verifier does not fall
        // back to the recorded value. If that fallback ever appeared, every
        // install-time authenticity control would be armed by a governance
        // record instead of by an explicit decision — and nothing else in the
        // tree would notice.
        'tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningKeyProvisioningTest.php',

        // REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1 — declared
        // for the same reason and one sharper one. The suite it replaces the
        // gap in already existed and still passed while `disk_encryption =>
        // true` described a workstation with no encryption on it, so the
        // failure mode here is not a gate that stops running but a gate that
        // keeps running against a boolean nobody measured. These tests pin the
        // host fact as false and force every clause of the vault record to be
        // real, which only helps for as long as they are actually selected.
        'tests/Feature/DoctorDevice/DoctorDeviceCustodian1EncryptedVaultTest.php',

        // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the
        // doctor/device authorization lifecycle, the automatic request on first
        // app login, the approval inbox's authority, and the app-only gate.
        //
        // Declared rather than left to the `DoctorDevice` filter token for the
        // reason Phase 1 established: a token match is an accident of naming and
        // nothing would tell us the day it stopped selecting. What these pin is
        // not hypothetical in either direction. One direction is a bypass — a
        // refused doctor re-queueing themselves, a session outliving the tablet
        // it was approved on. The other is worse: the enforcement-OFF cases are
        // the only thing standing between shipping this capability and locking
        // every doctor out of a live clinic, and a suite the gate does not run
        // cannot stop that.
        'tests/Feature/DoctorDevice/DoctorDeviceAuthorizationLifecycleTest.php',
        'tests/Feature/DoctorDevice/DoctorDeviceAppLoginRequestTest.php',
        'tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php',
        'tests/Feature/DoctorDevice/DoctorDeviceApprovalAccessTest.php',

        // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — the branch-code alias
        // policy and the collision-safe rename that depends on it.
        //
        // This meets the bar the STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 entry set,
        // for the same reason: the failure these pin was LIVE in production, not
        // hypothetical. Production had already been renamed TKM1 -> TLK1 by hand
        // while the code had not, so RM-derived branch derivation resolved to no
        // branch at all and one patient's legacy archive was unreachable — and
        // nothing in CI asserted otherwise. The same drift would have let the
        // branch seeder create a SECOND "Cabang Telkomas", splitting one clinic's
        // patients across two branch ids, which is the isolation boundary.
        //
        // Declared here rather than left to the filter token because branch
        // identity and a collision-safe patient-identifier migration are exactly
        // the controls that must never quietly stop being selected.
        'tests/Feature/Branch/TelkomasBranchCodeAliasTest.php',
        'tests/Feature/Branch/TelkomasBranchCodeMigrationTest.php',

        // REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1 — the same two controls for
        // the SECOND branch that was renamed, Cabang Sunu.
        //
        // Declared explicitly rather than trusted to a filter token, because a
        // token match is an accident of naming: `Branch` selects these today,
        // and nothing would tell us the day it stopped. The drift these pin was
        // live in production for the same reason Telkomas' was — the branch row
        // read SPN4 while the application still said SUN4, so one patient's
        // Nomor RM named a branch code that existed nowhere and the ACTIVE
        // rollout wave listed a spelling the branch master no longer used,
        // locking an approved branch out of its own wave.
        //
        // That this is the second occurrence is the argument, not a reason to
        // relax: a renamed branch is now a recurring operation, and the alias
        // registry plus the collision-safe migration are what keep it safe.
        'tests/Feature/Branch/SunuBranchCodeAliasTest.php',
        'tests/Feature/Branch/SunuBranchCodeMigrationTest.php',

        // FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1 — the Full Suite baseline contract,
        // and the receipt regression that proved it had a hole.
        //
        // Both are selected today, but only through a filter token that any
        // future edit could drop silently. The one authorised consolidated Full
        // Suite (run 32700184849) reddened on exactly the shape this guard now
        // pins, and it reddened because the guard did not yet cover assertions
        // against PDF-extracted text. Declaring them here makes the selection
        // enforced rather than incidental — the same reasoning the entry above
        // records: a control that exists but is never selected is not a control.
        'tests/Feature/Cicd/FullSuiteBaselineContractTest.php',
        'tests/Feature/RME/RmeReceiptOnePageTest.php',

        // FIX-PDF-TEMPFILE-LEAK-1 — the temporary-file ownership contract for
        // the shared PDF inspection helper.
        //
        // The helper this pins is called by every PDF assertion in the suite,
        // so a regression in it leaks once per assertion rather than once. It
        // is selected today by the `Cicd` token, but the leak it closes was
        // invisible for the same reason the entries above were: nothing
        // required ever asserted the property. Declaring it makes the
        // selection enforced rather than incidental.
        'tests/Feature/Cicd/PdfTempFileLifecycleContractTest.php',

        // FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — the same ownership contract for
        // the artifacts that must OUTLIVE their creator, which no `finally`
        // can reach. Ten prefixes across eight test files had accumulated 392
        // orphans between them; six of those call sites had no cleanup on any
        // path at all. Declared here for the same reason as the entry above:
        // the property is only protected if something required asserts it.
        'tests/Feature/Cicd/TempFileSiblingLeakContractTest.php',

        // REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 — the clinical
        // authorization boundary this revision moves, and the three conditions
        // it deliberately does NOT move.
        //
        // Declared rather than left to a filter token for the exact reason this
        // registry exists. The suite happens to be selected today by the bare
        // `Odontogram` token, i.e. by a substring of its filename — the same
        // "coverage rested on a filename coincidence" failure the docblock above
        // describes. What it pins is not cosmetic: that charting is permitted
        // before consent, and that the RME gate, the finish-examination gate,
        // the actor/branch/patient scope, the examination-started precondition
        // and the permanent read-only status of historical charts all survive
        // that change. A future rename must move this coverage, never drop it.
        'tests/Feature/RME/RmeConsentOdontogramPreConsentEditTest.php',

        // FIX-CI-GATE-WORKDIR-TEMPFILE-LEAK-1 — R-22. The registry above is the
        // OWNER, but nothing required that governed test code actually go
        // through it, so two release-gate call sites allocated their workdir
        // with a raw mkdir and stranded 18 trees per green run. This suite
        // asserts the property the registry cannot assert about itself: that
        // the surface uses the allocation API at all, under any prefix,
        // including one nobody has registered anywhere.
        'tests/Feature/Cicd/CiGateWorkdirOwnershipContractTest.php',

        // BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1 — the producer ↔ consumer
        // contract for dedicated queues.
        //
        // Declared here for the reason the entries above record, with a second
        // production incident behind it rather than one. A queue nobody
        // consumes produces no error, no failed job and no log line; the only
        // thing that can catch it is a check that runs. This suite lives under
        // tests/Feature/Queue, which no existing filter token selected, so
        // without both this declaration and its token in the filter it would
        // have been a control that exists and never runs — exactly the shape
        // this sprint was called in to fix.
        'tests/Feature/Queue/QueueProducerConsumerParityTest.php',

        // FEATURE-LEGACY-IMPORT-HUB-1 — the daily admission ceiling on legacy
        // imports, and its wiring into all three importers.
        //
        // DECLARED DELIBERATELY, ON THE STORAGE-PUBLIC-CLINICAL-EVIDENCE-1
        // PRECEDENT. This is a production admission control on a surface that
        // writes patient master data and clinical archives, and its most likely
        // failure is silent: an importer that simply stops calling the counter
        // leaves every quota unit test green while the ceiling stops existing.
        // A mutation run confirmed that shape — unwiring each importer in turn
        // was caught ONLY by the integration suite. Selection is therefore
        // enforced here rather than left to resting on the `LegacyImportHub`
        // filter token, which a future edit could drop silently.
        //
        // The concurrency suite is deliberately NOT declared: it skips on any
        // driver without row locks, and CI runs SQLite, so declaring a suite
        // that can only ever skip there would read as evidence it is not.
        'tests/Feature/LegacyImportHub/LegacyImportHubQuotaTest.php',
        'tests/Feature/LegacyImportHub/LegacyImportHubIntegrationTest.php',

        // BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1 — the patient selector
        // behind "Kunjungan Baru", and the SQL-level guard on the fault that
        // took it down in production.
        //
        // DECLARED ON THE STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 PRECEDENT, and
        // this entry is the reason the precedent keeps earning itself. The
        // combobox suite already existed, already covered branch scope, IDOR,
        // privacy, bounds and wildcards — and matched NO token in either
        // critical filter. It therefore only ever ran where a developer ran it:
        // on SQLite. The one combination that fails is PostgreSQL on PHP <= 8.3,
        // which is exactly what the gate runs and exactly what serves
        // production, so a control that was written correctly still let a total
        // outage of the registration workflow reach real operators.
        //
        // Declaring both files makes that selection enforced instead of
        // incidental: coverage that nothing required is not coverage.
        'tests/Feature/RME/NewVisitPatientSearchComboboxTest.php',
        'tests/Feature/RME/NewVisitPatientSearchRuntimeTest.php',

        // REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — the global patient
        // identity lookup, and the boundary it deliberately does NOT cross.
        //
        // Declared for the same reason as the two entries above, plus one this
        // sprint adds: it holds a distinction that is easy to erase by accident.
        // Identity lookup is global; visit branch authority is not. A future
        // change that lets the patient's origin branch (or a request branch_id)
        // decide the visit branch would register people at the wrong clinic and
        // would still look correct in every branch-scoped test that remains.
        // The suite also pins the widened scope's disclosure ceiling — four
        // identity fields — which now spans the whole registry rather than one
        // branch, so a leak here is worth more to an attacker than it was.
        'tests/Feature/RME/NewVisitGlobalPatientLookupTest.php',

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the daily working-branch lock
        // for Kasir and Admin Klinik, and the Super Admin approval that is the
        // only way past it.
        //
        // DECLARED ON THE SAME PRECEDENT AS THE TWO ENTRIES ABOVE. This is a
        // persistent authorization boundary over a FINANCIAL scope: the branch
        // it pins decides which invoices, receivables and payments a cashier may
        // act on. Its most likely failure is silent — a future caller that moves
        // the working branch without going through the guard, or an
        // `activeContextBranchId()` that stops consulting the durable authority,
        // leaves every unit assertion green while the lock stops existing.
        //
        // Nothing else in the suite would notice: before this sprint the free
        // re-selection was the DOCUMENTED behaviour, so no existing test
        // forbids it. Selection is therefore enforced here rather than left to
        // rest on a filter token a future edit could drop silently.
        //
        // The concurrency suite is deliberately NOT declared, for exactly the
        // reason stated above: it skips on any driver without row locks, and CI
        // runs SQLite.
        'tests/Feature/AccessControl/DailyBranchContextLockTest.php',
        'tests/Feature/AccessControl/DailyBranchContextBypassTest.php',
        'tests/Feature/AccessControl/BranchChangeApprovalTest.php',
        'tests/Feature/LegacyImportHub/LegacyImportHubSurfaceTest.php',

        // REVISION-RME-REPORTS-TODAY-DEFAULT-1 — the RME reports' default period.
        // Declared for the same reason as the branch-lock suites above: the
        // guarantee is a NEGATIVE one (a bare report, and its CSV export, must
        // never return the branch's whole history), and an all-history default
        // was the DOCUMENTED behaviour until this sprint, so no other test
        // forbids it. The suite also carries the branch × date matrix proving a
        // historical filter cannot widen the authorised branch scope.
        'tests/Feature/RME/RmeReportTodayDefaultTest.php',

        // BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1 — the clinical UI capability
        // must mirror the server capability for the same act.
        //
        // DECLARED ON THE STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 PRECEDENT, which
        // this entry meets on its own terms: the mismatch it pins was LIVE in
        // production. A doctor with an unconsented in-progress examination was
        // offered "Buat Halaman RM Pertama" and the server refused the create,
        // because the empty state gated on MedicalRecordPolicy::create — a
        // permission — while the act is gated by
        // RmeVisitConsentService::canAuthorRmeForPatient(). Nothing in CI
        // asserted the two agreed, so the drift was invisible for as long as it
        // existed.
        //
        // What makes declaration worth more than the token here is the shape of
        // the likely regression. The failure mode is a UI that becomes MORE
        // permissive than the service, and that direction produces no error, no
        // exception and no log line — only a control that fails when pressed.
        // The suite also pins the two boundaries a careless "align the UI"
        // change would take with it: the odontogram stays editable before
        // consent, and finishing the examination stays refused. Coverage that
        // nothing required is not coverage.
        'tests/Feature/RME/RmePreConsentFirstPageUiGateTest.php',
    ],

    /*
     * The Critical Gate warning contract — CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1.
     *
     * The gate used to conclude `success` while reporting
     * `Tests: 2222 warnings, 9 passed` — 128 of its 129 test files marked WARN.
     * Failures were always enforced, but warnings had no contract at all, so
     * 99.6% of the gate's own output was noise and a genuinely new warning was
     * indistinguishable from the baseline.
     *
     * Root cause of that baseline: the application environment file is OPTIONAL
     * by framework design and is deliberately never committed, so CI supplies
     * configuration through each job's `env:` block instead. The framework
     * still resolves the path and reads it on every application boot, and with
     * nothing there the read failed and PHPUnit recorded a warning on every
     * test that boots the application — which is every test except the one
     * file that never boots it. CI now writes an EMPTY environment file, which
     * makes the by-design "no file-based configuration" state explicit: the
     * read succeeds and yields zero variables, byte-for-byte the configuration
     * CI already resolved.
     *
     * `expected_warning_count` is a DECLARED baseline, not a tolerance to be
     * raised. There is deliberately no warning-text allowlist: an expected
     * condition is represented at its causal boundary, never by matching the
     * text of a warning that is still being emitted.
     */
    'critical_gate_warning_contract' => [
        'enabled' => (bool) env('CI_CRITICAL_GATE_WARNING_CONTRACT_ENABLED', true),

        // Raising this to absorb a new warning is a governance violation.
        'expected_warning_count' => (int) env('CI_CRITICAL_GATE_EXPECTED_WARNINGS', 0),

        'log' => 'storage/ci-evidence/nsf-r011-critical-tests.log',

        'command' => 'ci:assert-critical-gate-warning-contract',

        /*
         * Every job that boots the application under test must provision the
         * environment file first. Matched by STEP NAME so the marker carries no
         * filename literal — the release-evidence safety scan bans that literal
         * in captured governance text.
         */
        'environment_provisioning_step' => 'Provision the CI environment file',

        'environment_provisioning_jobs' => [
            'critical_test_gate',
            'critical_test_gate_self_hosted',
            'selective_module_gate',
            'release_safety_gate',
            'nsf10_release_evidence_gate',
            'full_suite_gate',
        ],

        /*
         * Shapes that would make the gate lie rather than fix its cause. None
         * of these may appear in the workflow or in the CI shell helpers.
         */
        'forbidden_suppression_markers' => [
            'error_reporting(0)',
            'grep -v warning',
            'grep -v file_get_contents',
            'ignoreSuppressionOfPhpWarnings',
            'restrictWarnings',
        ],
    ],

    /*
     * Steps whose exit status MUST survive a pipe.
     *
     * A shell pipeline reports the status of its LAST command, so
     * `producer | tee evidence` reports tee's success and discards the
     * producer's failure. The runner health check was written that way: it
     * printed `DECISION: NO-GO`, exited 1, and the step still went green —
     * migrations and Pest then ran on a runner that had just failed its own
     * safety check.
     *
     * Every step named here writes evidence through a pipe AND gates something
     * safety-critical, so each must either declare `shell: bash` (which adds
     * `-o pipefail`) or capture PIPESTATUS explicitly. Evidence is never
     * dropped to work around this — it is preserved and the status propagated.
     *
     * CICD-CTRL-3B extends the list to the NSF-9 and NSF-10 producers. That gap
     * is why the defect survived CICD-CTRL-3A: the check below was already
     * correct, but it only ran against the steps named here, and the release
     * gates were not among them. Every one of these producers exits non-zero
     * for exactly one reason — the governance decision is FAIL (GO and WATCH
     * both exit 0) — so a swallowed status turned a release-blocking NO-GO into
     * a green required check.
     */
    'strict_pipeline_steps' => [
        // CICD-CTRL-3A — self-hosted runner and critical regression gate.
        'Runner health check (CICD-CTRL-3)',
        'Assert authoritative PHP runtime (CICD-CTRL-3)',
        'Self-hosted runner smoke evidence (CICD-CTRL-3)',
        'Run critical regression tests',

        // CICD-CTRL-3B — NSF-9 release safety & automated smoke gate.
        'Foundation roadmap check',
        'Feature flags governance',
        'Release safety check',
        'Automated smoke (command-readiness only, no base URL in CI)',

        // CICD-CTRL-3B — NSF-10 release evidence gate.
        'Capture NSF-10 release evidence (ci profile)',
        'Check NSF-10 release evidence (ci profile)',
        'Release safety check (ci profile)',

        /*
         * CICD-FIX-6 — NSF-R011 full suite gate.
         *
         * This is the step the false-green problem is named after: a run once
         * logged `Tests: 1202 failed` and still concluded `success`, because
         * `php artisan test | tee ...` reports tee's status under GitHub's
         * default `bash -e` shell, which carries no `-o pipefail`.
         *
         * CICD-CTRL-3 repaired the step by declaring `shell: bash`, but never
         * added it here — and the scanner only inspects steps named in this
         * list. So the longest and most expensive gate in the pipeline was the
         * one gate its own guard could not see: deleting that `shell: bash`
         * line would silently restore a green full suite over a red one.
         */
        'Run full Pest suite',
    ],

    /*
     * Runtime evidence must describe what actually executed.
     *
     * The self-hosted evidence used to name a container engine unconditionally,
     * so a run on a host with no Podman — executing the native runtime — still
     * reported "engine=rootless podman" while its own smoke line read
     * `container_engine=` (empty). Evidence that contradicts itself is worse
     * than no evidence, because it gets quoted in closure reports.
     */
    'runtime_evidence' => [
        // The wrapper resolves and reports the mode; the workflow must ask it
        // rather than assert a mode of its own.
        'required_marker' => '--print-runtime',

        /*
         * Claims that assert one specific engine unconditionally.
         *
         * These ban HARD-CODED engine claims, not legitimate resolved output.
         * The resolver emits `container_engine=podman` for a real container-mode
         * runner and that must keep working; what is forbidden is a literal
         * baked into the workflow, which stays fixed while the runtime changes
         * underneath it.
         *
         * CICD-CTRL-3C adds the third such literal. CICD-CTRL-3A derived the
         * smoke and PHP-assertion steps from the resolver and banned the two
         * markers below, but the NSF-R011 evidence-summary step still asserted
         * an engine and image of its own, and none of the existing markers
         * matched its wording. It shipped a self-hosted artifact claiming a
         * container runtime on a host with no container engine, contradicting
         * the same job's log. The literal is assembled here rather than written
         * out so this registry does not itself become a match for the scan.
         */
        'forbidden_markers' => [
            'engine=rootless podman',
            'container_engine=$(podman --version)',
            'runtime='.'rootless-podman',
        ],
    ],

    /*
     * Production commands that must never appear in the CI workflow. Deployment
     * and rollback belong to the VPS boundary, not to a CI runner.
     */
    'forbidden_ci_production_commands' => [
        'deploy-vps-runner.sh',
        'deploy-vps.sh',
        'rollback-vps.sh',
    ],

    /*
     * Production database isolation guard (`ci:assert-non-production-database`).
     *
     * The guard is rule-based, not IP-based: a CI database must be local and
     * must carry a CI/test name. That blocks every remote database, including
     * ones nobody thought to denylist.
     */
    'database_guard' => [
        // APP_ENV values a CI run may use.
        'allowed_app_envs' => ['testing'],

        // Hosts a CI database may live on. Anything else is a hard FAIL.
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_ALLOWED_HOSTS', '127.0.0.1,localhost,::1,postgres'))
        ))),

        // Database names a CI run may use, exact match.
        'allowed_databases' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_ALLOWED_DATABASES', 'testing,daengtisia_ci'))
        ))),

        // Additional accepted name shapes for disposable CI databases.
        'allowed_database_patterns' => [
            '/^daengtisia_ci(_[a-z0-9_]+)?$/',
            '/^testing(_[a-z0-9_]+)?$/',
            '/_(test|testing|ci)$/',
        ],

        /*
         * Known production database names. Explicit denial on top of the
         * allowlist so a misconfigured allowlist still cannot reach production.
         */
        'denied_databases' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_DENIED_DATABASES', 'asia_dental_lab_pilot,asia_dental_lab'))
        ))),

        // Substrings that mark a database name as production-like.
        'denied_database_substrings' => ['pilot', 'prod', 'production', 'live'],
    ],

    /*
     * Paths that must NOT exist on the runner (checked by the health script).
     * The runner is a CI machine; production material has no reason to be there.
     */
    'forbidden_runner_paths' => [
        'production_ssh_key' => '.ssh/daengtisiams_vps_ed25519',
        'production_deploy_key' => '.ssh/daengtisiams_deploy',
    ],

    /*
     * Host tooling the runner must provide. The runner is pre-provisioned so CI
     * jobs never need sudo at run time.
     *
     * PHP, Composer and Poppler are deliberately NOT host requirements — they
     * come from the pinned CI image (see `ci_runtime`), because the host OS
     * cannot supply the authoritative PHP version.
     */
    'required_host_tooling' => [
        'node',
        'npm',
        'psql',
        'git',
    ],

    /*
     * Tooling required in addition to the above, per runtime mode.
     *
     * In native mode the host itself is the authoritative runtime, so PHP,
     * Composer and Poppler become host requirements. In podman mode they are
     * supplied by the pinned image instead and must NOT be demanded of the host.
     */
    'required_host_tooling_by_mode' => [
        'native' => ['php', 'composer', 'pdfinfo', 'pdftoppm'],
        'podman' => ['podman'],
    ],

    // PHP major.minor the CI runtime must provide, matching the GitHub-hosted gate.
    'required_php_version' => env('CI_RUNNER_PHP_VERSION', '8.3'),

    /*
     * Authoritative CI runtime.
     *
     * The contract is SEMANTIC RUNTIME EQUIVALENCE, not a specific container
     * engine: whatever executes a CI command must provide the same PHP
     * major.minor, the same extension set and the same Poppler binaries as the
     * GitHub-hosted gate. The wrapper proves that at run time in either mode and
     * never falls back to a mismatched PHP.
     *
     * native — the host OS ships the authoritative PHP (Ubuntu 24.04 ships 8.3).
     *          Preferred: no image build, no rootless plumbing, no userns
     *          mapping, fewer moving parts to drift.
     * podman — the runtime comes from the pinned image. Required when the host
     *          cannot supply the authoritative PHP; this is why the wrapper was
     *          originally written, on an Ubuntu 26.04 host that shipped only
     *          PHP 8.5. Runs ROOTLESS under the dedicated service user.
     *
     * Docker is forbidden in BOTH modes: the docker group is root-equivalent.
     */
    'ci_runtime' => [
        'mode' => env('CI_RUNTIME_MODE', 'auto'),
        'allowed_modes' => ['native', 'podman'],

        // Container engine used when (and only when) mode resolves to podman.
        'engine' => 'podman',
        'rootless' => true,
        'image' => env('CI_PHP_IMAGE', 'localhost/daengtisia-ci-php:8.3'),
        'containerfile' => '.github/ci-runtime/Containerfile.php83',
        'wrapper_script' => 'scripts/ci/self-hosted-php.sh',

        // The base image must be pinned by digest, never by a floating tag.
        'require_digest_pin' => true,

        // Extension set the image must provide, mirroring the setup-php list
        // used by the GitHub-hosted critical gate.
        'required_extensions' => [
            'dom',
            'curl',
            'libxml',
            'mbstring',
            'zip',
            'pcntl',
            'pdo',
            'pdo_pgsql',
            'bcmath',
            'gd',
            'exif',
        ],

        /*
         * Poppler must exist inside the image: the critical filter covers
         * LegacyRme, whose Poppler suite SKIPS when these are missing. Omitting
         * them would silently make the self-hosted gate weaker than the
         * authoritative one.
         */
        'required_binaries' => ['pdfinfo', 'pdftoppm'],

        /*
         * Group membership that must never be granted to the service user.
         *
         * These are checked unconditionally by the health script — never behind
         * a container-runtime probe, because a missing tool must not be able to
         * suppress a root-equivalence finding.
         *
         * docker / lxd  : both hand out root-equivalent control of the host.
         * sudo / wheel  : direct privilege escalation.
         * root          : membership in the root group.
         * adm / disk / shadow : privileged read of logs, raw devices, and the
         *                 password database respectively.
         */
        'forbidden_service_user_groups' => [
            'docker',
            'sudo',
            'lxd',
            'wheel',
            'root',
            'adm',
            'disk',
            'shadow',
        ],
    ],

    /*
     * CI database parity.
     *
     * The authoritative gate runs `postgres:16` as a service container. The
     * runner host ships PostgreSQL 18, and that version difference produced
     * self-hosted-only test failures (aborted-transaction cascades out of
     * Dq1AuditService) — so the CI database is pinned to the same major version
     * the gate uses, supplied the same way the PHP runtime is: a pinned image
     * under rootless Podman, loopback-only.
     *
     * The host PostgreSQL is deliberately left untouched; a co-tenant project
     * on this machine may depend on it.
     */
    'ci_database' => [
        'engine' => 'podman',
        'rootless' => true,
        'image' => env('CI_DB_IMAGE', 'docker.io/library/postgres:16'),
        // Resolved 2026-08-07; PostgreSQL 16.14, the same major production runs.
        'image_digest' => 'sha256:670391653713782e51974845b217c56fed4dd8729142299c43c919a8d3e15e00',
        'service_unit' => 'ci-pg16.service',
        'host' => env('CI_RUNNER_DB_HOST', '127.0.0.1'),
        'port' => (int) env('CI_RUNNER_DB_PORT', 5433),
        'database' => env('CI_RUNNER_DB_NAME', 'daengtisia_ci'),
        'username' => env('CI_RUNNER_DB_USER', 'daengtisia_ci_user'),

        // Must match the major version of the workflow's service container.
        'required_major_version' => env('CI_RUNNER_DB_MAJOR', '16'),

        // Pinned by digest for the same reason as the PHP runtime.
        'require_digest_pin' => true,
    ],

    /*
     * Resource guards enforced by the health script before a heavy job runs.
     * Below these thresholds the runner reports NO-GO instead of thrashing.
     */
    'resource_guards' => [
        /*
         * Sized for the actual CI host, not for a hypothetical large one.
         *
         * The previous 40GB floor was set against a 231GB laptop disk. On the
         * 58GB Biznet VPS it would report NO-GO permanently — a guard that can
         * never pass is not a guard, it is an outage. 15GB still leaves room for
         * a full vendor/ + node_modules/ + workspace + database on this host,
         * and the bounded-cache policy below keeps it from creeping.
         *
         * RAM: 2GB available is the floor for one sequential heavy job on a
         * 7.8GB host with 4GB of swap behind it.
         */
        'min_free_disk_gb' => (int) env('CI_RUNNER_MIN_FREE_DISK_GB', 15),
        'min_available_ram_mb' => (int) env('CI_RUNNER_MIN_AVAILABLE_RAM_MB', 2048),
    ],

    /*
     * Concurrency posture. Heavy jobs run one at a time on this hardware; the
     * Pest worker count is set from a real benchmark, never from core count.
     */
    'concurrency' => [
        'max_parallel_heavy_jobs' => (int) env('CI_RUNNER_MAX_HEAVY_JOBS', 1),
        'pest_workers' => (int) env('CI_RUNNER_PEST_WORKERS', 2),
        'pest_workers_benchmarked' => [1, 2, 3],
    ],

    // The CICD-CTRL-3 governance rule ids (published into the foundation summary).
    'governance_section' => 'self_hosted_runner_governance',
];
