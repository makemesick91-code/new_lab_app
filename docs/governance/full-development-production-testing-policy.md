---
kb_status: current
kb_verified_against: 75eeac7d
kb_updated: 2026-11-05
---

# FULL DEVELOPMENT & PRODUCTION TESTING POLICY — **ACTIVE**

| | |
|---|---|
| Mission ID | `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104` |
| Status | **ACTIVE** — in force for the whole RME revision programme, until the owner changes it |
| Authority | System Owner, written directive dated 2026-11-04 (priority P0, owner approved) |
| Recorded | 2026-11-05 00:00 WITA (UTC+8) |
| Canonical rule mirror | `.cursor/rules/221-full-development-production-testing-policy.mdc` |
| Always-loaded pointer | `CLAUDE.md` CG-13 and CG-14 |
| Production baseline when recorded | commit `6f22beb7` (tag `dms-biznet-first-production-deploy-infra-1-go`), measured on the host |
| Database classification | **TESTING DATABASE**: every current record belongs to the testing phase. Owner directive `DMS-CURRENT-DATABASE-TESTING-CLASSIFICATION-1`, 2026-11-05 (§0) |

> **Canonical status:** FULL DEVELOPMENT ENABLED — PRODUCTION TESTING ALLOWED — OPERATIONAL DEVICE TESTING FROZEN.
> Clinic reopening is a separate owner decision. This policy does not reopen anything.

## 0. Current database classification

The System Owner has declared, with authority, that **all current DaengtisiaMS database records are testing data**
(directive `DMS-CURRENT-DATABASE-TESTING-CLASSIFICATION-1`, 2026-11-05).

1. **Classification.** Biznet is the production *infrastructure* (CG-14), but its application database is still in the
   development and testing phase. It is classified as a **TESTING DATABASE**. Every existing record belongs to the
   testing phase. On the owner's declaration, no operational clinical data exists in the current system.
2. **Reports.** Current reports (owner KPI, doctor performance, receivables, inventory, estate and readiness figures)
   are testing results, not operational performance.
3. **What it does not change.** Development and controlled testing on Biznet stay authorised. Testing on operational
   clinic devices stays frozen (§4), and clinic reopening stays a separate owner decision (§8).
4. **No test-data isolation feature now.** No `is_test` column, test-only branch or operational-report exclusion
   system is required to separate current records. Reassess this only when the go-live transition is prepared (item 8).
5. **Database safety.** The classification does not authorise destructive operations. Preserve the existing database
   contents, the schema and migration history, application audit evidence, the immutable inventory movement history,
   and the backup and recovery systems. On Biznet, never run `migrate:fresh`, `db:wipe`, table truncation or
   unrestricted record deletion. Any reset or replacement of the database needs its own separately approved procedure.
6. **Privacy still applies in full.** "Testing data" does not mean the records hold no personal or sensitive
   information. Treat every record as potentially real personal data: KTP/NIK masking, private clinical-evidence
   storage, least-disclosure reporting and §3.1 apply unchanged (CG-11).
7. **History is preserved.** Earlier records that describe pilot operations, readiness ceremonies, legacy migrations
   or go-live events are kept exactly as written. Under this classification, the data they produced belongs to the
   testing phase.
8. **Future go-live.** Before any operational use, a separate **database transition plan** must be prepared and
   approved by the owner. It must cover:
   - disposition of the testing data;
   - preparation of the operational database;
   - initial master data;
   - clinical-record and MRN continuity where applicable;
   - branch configuration;
   - user roles and permissions;
   - data integrity;
   - backup and recovery;
   - operational readiness.

   No operational cutover is performed now.

## 1. What this policy replaces

| Earlier restriction | Where it was written | Now |
|---|---|---|
| Conflicting testing restrictions in `DMS-OPERATIONAL-LOCK-RME-DEVELOPMENT-20261104` | Named by the owner directive. **No copy exists** in the repository, its remote branches or the private operations records (searched 2026-11-05). | Superseded by name. There was nothing to edit. |
| "Production deploy: still blocked, needs separate approval" | Private development-resumption record `DMS-DEVELOPMENT-RESUMPTION-20261104`, §2 and §7 rule 1 | Deploys are **allowed** under release governance (§6) |
| "Production migrations: still blocked" | Same record, §2 | Allowed **only** as part of a deploy (`migrate --force` after the runner's backup) |
| "No development migrations, seeders or tests against the production database" | Same record, §7 rule 3 | Narrowed, not removed. Controlled functional testing through the running application is allowed. Automated suites, seeders, factories and development migrations still never touch the production database (§3). |
| "Synthetic data belongs in tests" | `CLAUDE.md` CG-13 | Synthetic data is also allowed in controlled production testing, under §3 |
| "Production is a single VPS pilot that serves real patient data" | `CLAUDE.md` introduction | Replaced on 2026-11-05: production infrastructure with a testing database (§0) |
| "Test records must not contaminate operational reports; read decision reports with test ids excluded" | This policy, §3.9 and R1 as merged in PR #591 | Replaced on 2026-11-05: every current record is testing data, so no exclusion applies (§0.4) |

Historical records were not rewritten. The private record keeps its original text, and this table is the supersession
note. Restrictions the directive did not lift stay in force: the Hostinger write freeze, the pilot-only doctor-device
enforcement, the backup protections, the clinic reopening gate, and every architecture, privacy and ledger rule.

## 2. What is allowed

| Environment | Development | Testing |
|---|---|---|
| Local workstation | Yes | Unit, feature, integration, database, permission, UI and browser automation |
| GitHub branches and CI | Yes | Required gates; whole-suite runs follow CG-18 (§9) |
| Isolated staging | Yes | End-to-end, functional, role-based, cross-module, Android app and Scanner Agent |
| Biznet production | Through the source-control and deploy workflow only, never by editing the live tree | Controlled functional verification, smoke tests, role and permission checks, RME, odontogram, consent, prescription and cashier-integration verification, browser compatibility, Android integration on **dedicated test devices**, and performance checks within approved resource limits |
| Operational clinic devices | No | **Frozen** (§4) |

## 3. Production testing rules

A production test task names its scope before it starts. Under CG-14, checks stay read-only unless the task
authorises a mutation, and a test task authorises only the records it declares.

1. **Identities.** Use dedicated test accounts and synthetic patients. Never use a real patient's data for development
   testing unless there is a separate lawful and authorised basis.
2. **Marking.** Every record a test creates must be clearly identifiable as test data, both by its name and in the
   task's evidence. Record the ids of what was created.
3. **Existing records.** Never modify an existing patient, clinical, financial or inventory record for a test.
4. **Money.** Never create a real financial transaction only to test. Cashier verification uses synthetic invoices on
   test identities, and settling one is a test record under rule 2.
5. **Cleanup is non-destructive.** Retire test records through the canonical lifecycle (cancel, void and similar
   operations, dry run first, then `--apply`). Never delete or rewrite protected clinical, financial or inventory
   history. Never truncate a business table. Never delete an inventory ledger row. Never run `migrate:fresh`,
   `db:wipe`, `schema:drop` or `migrate:reset`.
6. **No automated tooling against the production database.** Pest/PHPUnit suites, seeders, factories and development
   migrations never run on the production host or against the production database. Schema changes reach production
   only through the deploy runner.
7. **No REPL.** Never run `php artisan tinker` or psysh on production (CG-14).
8. **External systems.** Test records must not reach an external production system. SATUSEHAT submission and WhatsApp
   delivery stay disabled. A task that needs either needs its own decision.
9. **Isolation and reports.** Respect `BranchContext`, doctor assignments and permissions exactly as real users do.
   The whole current database is a testing database (§0), so reports need no test-record exclusion and no test-data
   flag is required. Still record the ids of what a test created (rule 2): the go-live transition plan accounts for
   the testing data (§0.8).
10. **Test data is not operational evidence.** A synthetic production record proves that the software behaves. It never
    stands in for real-world proof: a doctor's real-device readiness ceremony, a legacy migration wave, pilot
    acceptance, or stability under clinical load. CG-13's ban on fabricated evidence is unchanged.
11. **Audit.** Keep the audit trail intact. Never suppress, edit or delete audit rows that a test produced.

## 4. Operational clinic device testing freeze

These are **operational clinic devices**: doctors' daily clinical tablets, Front Office PCs, cashier workstations,
clinic administration PCs, operational Scanner Agent installations, and any other device assigned to a live clinic
workflow.

While the freeze is in force, these are **forbidden** on an operational device:

- feature, acceptance or UAT testing of RME, odontogram or Android UI;
- clinical-flow simulations and development debugging;
- installing an experimental or unapproved APK;
- test transactions against production from that device;
- repeating UAT only because a device belongs to a clinic.

These remain **allowed**: distributing an *approved* release through the operational rollout procedure, keeping the
installation inventory, and integrity checks. None of these may start a clinical test transaction, and none of them
replaces development testing.

The freeze holds until the owner explicitly lifts it.

## 5. Dedicated test devices

- Device testing uses dedicated devices: an Android test tablet or phone, a development workstation or test PC, a
  Scanner Agent test workstation, and browser test environments.
- A test device should match the operational target in the respects that matter: Android version, screen resolution,
  device capabilities, browser or WebView, network access, security controls, and scanner model and driver.
- A test device that needs trusted-device access goes through the normal enrolment, approval and credential paths. Never
  bypass device-security enforcement, and never widen doctor-device enforcement (pilot cohort, `global_permitted`) to
  make testing easier.
- Name a production-registered test device so it is recognisable as a test device, and record its id. Estate and
  readiness engines (`doctor:estate-resilience`, `doctor:fleet-readiness`) count any eligible device at a branch, so a
  test device can inflate those figures. Report readiness with and without it (§10).
- **No duplicate UAT.** A test that passed on a representative dedicated device is not repeated as development UAT on
  an operational device.
- **Compatibility is not assumed.** If an operational device differs materially (hardware, Android behaviour, scanner
  driver or security capability), write the untested compatibility down as a risk. Never claim compatibility with a
  configuration that no test represented.

## 6. Deployment governance

Production deployment is **allowed**. It is no longer frozen, and it runs under release governance:

1. Define the scope, branch from the remote base, implement, and run the applicable tests.
2. Verify permissions and branch isolation, pass the required CI on the exact candidate SHA, and review and merge the PR.
3. Verify the production baseline and backup health, then deploy only with the canonical runner **on the host**
   (CG-15, `docs/runbooks/production-host-identity.md`) after `PRODUCTION_TARGET=VERIFIED`.
4. Verify the deploy id and the deployed commit, run controlled production verification, and record the results.

The following still need a **specific risk review and owner approval** before they deploy: destructive database
changes, irreversible migrations, changes to doctor-device enforcement, and significant production security changes.

This workflow *is* the owner's current release governance that CG-15 points to. The commit approved for release is the
base-branch tip that passed review and the required CI. Confirm it from the host before `start`, because the runner
pins the tip at launch. Ordinary deploys need no separate per-deploy owner instruction. Two things still do: the
high-risk changes listed above, and the first deploy on a new host (CG-15).

**Background services.** The queue worker, the Laravel scheduler and the KTP cleanup timer are OFF. The db-backup and
BK-2 backup timers are ON. Turning on any one service needs a dependency review, a security review, an operational
impact assessment, a backup check, controlled activation and monitoring afterwards. Never enable them all at once, and
never weaken the independent backups.

## 7. RME revision workflow and GO criteria

RME is the primary development priority: patient management, doctor workflow, clinical records, financial integration,
security and legacy data. Each revision follows explicitly approved requirements and does not touch unrelated modules.

A revision is **RME REVISION GO** when all of the following hold:

1. the implementation is complete;
2. the required development tests pass;
3. the applicable CI passes;
4. permissions and branch isolation are validated;
5. the relevant dedicated-device testing passes;
6. the required production verification passes;
7. no critical regression remains;
8. the evidence is documented.

The other outcomes are **PARTIAL GO** and **NO-GO**. Development UAT on operational devices is not required. A revision
GO is **not** an authorization to reopen clinics.

When every owner-approved revision is done, the programme closes with
`DMS_RME_FINAL_DEVELOPMENT_AND_PRODUCTION_VERIFICATION_REPORT.md`, classified as **RME PROGRAM FINAL GO**, **PARTIAL
GO** or **NO-GO**.

## 8. Clinic operations

This policy governs development and testing only. Reopening the clinics needs a separate owner decision, and the
existing reopening conditions are unchanged. Passing development or production verification does not reopen anything.
Laravel maintenance mode is not used to enforce the device freeze.

## 9. Relationship to other rules

- **Full Suite (CG-18, rule 107).** This policy allows full automated suites in CI as an environment. The GLOBAL
  TEMPORARY FULL-SUITE POLICY is a separate owner policy about *when* the suite runs, and it stays ACTIVE until the
  owner retires it.
- **Production identity (CG-14, rule 220).** Biznet is production. Hostinger stays WRITE-FROZEN; never reactivate it.
- **Data and architecture (CG-04 to CG-13).** Unchanged. Branch isolation, three-layer authorization, separation of
  duties, additive migrations, the ledger-only inventory, KTP/NIK masking and `ClinicalClock` all apply to test activity.

## 10. Remaining risks

| # | Risk | Mitigation now | Owner follow-up |
|---|---|---|---|
| R1 | No schema-level test-data flag | **Not required now.** The owner classified the whole current database as testing data (§0.4), so there are no operational records to separate | Reassess when the go-live database transition plan is prepared (§0.8) |
| R2 | A registered test device inflates estate and readiness counters | Name it as a test device, record its id, and report readiness with and without it | Decide on an exclusion mechanism if test devices multiply |
| R3 | Dedicated devices may not represent every operational device | Write down untested compatibility | Procure test hardware that matches the clinic estate |
| R4 | Cashier and RME verification leave permanent ledger and audit rows | Synthetic identities and canonical void/cancel; never delete | None; this is by design |
| R5 | Deploys now happen more often against a database whose contents must be preserved (testing data that may hold real personal information) | Runner backup, canonical runner, high-risk changes need specific approval | The runner's verified pre-deploy dump; BK-2 health is not a deploy gate (rule 232) |
| R6 | The superseded `DMS-OPERATIONAL-LOCK-RME-DEVELOPMENT-20261104` text was never recorded, so its full content is unknown | Superseded by name; this document is the authority from now on | None |
| R7 | Deploys become routine, so a merge to the base branch is in effect a release candidate | The §6 workflow (review, required CI, host-side tip check) and specific approval for high-risk changes | The owner may still require a per-deploy instruction at any time by saying so |
| R8 | The testing classification is a declaration, not a measurement. Earlier records describe live pilot use and legacy archives of real patients, so the data can still hold real personal information | Privacy rules apply in full (§0.6); nothing is deleted or reset (§0.5) | Settle the disposition of this data in the go-live transition plan (§0.8) |
