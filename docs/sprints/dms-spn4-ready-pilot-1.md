---
kb_status: current
kb_verified_against: d628e0cb
kb_updated: 2026-11-23
---

# DMS-SPN4-READY-PILOT-1 — Fast-Track Ready Pilot SPN4: Design Lock

| Field | Value |
|---|---|
| Sprint id | `DMS-SPN4-READY-PILOT-1` |
| Status | **DESIGN LOCKED (G1).** The owner approved this text on 2026-11-22 (decisions DL-1 to DL-7 below). **G4: AUDIT COMPLETE — PILOT BLOCKERS REMAIN OPEN. G5: Path B selected; formal G5 closure pending OD-5** (§10, §12). **G6: R6-B1, REM-2/R2-6 and REM-1 RELEASED in release-1** (§13, §13.13). **Release-1: CLOSED on 2026-11-24 (deploy `20261124-021647` verified), interim release without a GO tag; two gate-9 production checks NOT RUN under owner exceptions** (§13.12, §13.13). **Release-2 (R6-B5, `dms-spn4-r6b5-r2-20261124`): DEPLOYED AND VERIFIED on 2026-11-24 UTC (deploy `20261124-164148`, runtime `7f58a3af`), CLOSED; no GO tag** (§13.14, §13.15). **Pilot: NO-GO.** No implementation is authorised by this document |
| Base at drafting | `6f790a7ed3f275ea32c14704464822864313e84a` (G4/G5 record drafted on `71cc096b5874ec7ffa73aa4fbc9db4271d41e4f5`; G6 record on `72bf77219f27a5221948336351179e0ee12ac25a`; manifest switch reconciled on `d628e0cb241bb82963a50cab125560175e0f1387`) |
| Type, module | `SECURITY_FIX`, `Rme`. Manifest: `.sprint/current.yml` from the manifest switch (§9.1). It was first parked at `.sprint/dms-spn4-ready-pilot-1.yml` through PR-C2 (#686); the switch removes that copy |
| Foundation-First categories | SECURITY FIX · DEPLOYMENT / OPERATIONS · BACKUP / RESTORE / MONITORING / CI-CD GATES · FOUNDATION DOCUMENTATION |
| Gate 1 RME-LAB-FEE-APPROVAL-1 | **NO-GO**. The seven Gate 1 blockers stay open, and LAB-fee stays disabled |
| `.sprint/current.yml` | **DMS-SPN4-READY-PILOT-1** from the merge of the manifest switch (§9.1), which is the owner's switch decision. Before it, DMS-RME-COMPREHENSIVE-CLOSURE-1 (CC1). CC1 is parked verbatim at `.sprint/dms-rme-comprehensive-closure-1.yml` and stays OPEN |
| BK-2 | **SKIPPED** (rule 232; never a gate) |
| Canonical language | English. An Indonesian summary of the owner's decisions follows the owner decision record |

**Authority** (`CLAUDE.md`): owner decisions recorded in `docs/decisions/` and the canonical `.cursor/rules/` set the
required behaviour. Code, migrations, configuration and tests are evidence of current behaviour. A mismatch is
implementation drift: report it with evidence, and fix it only in an authorised sprint.

**Status vocabulary:** PASS · FAIL · BLOCKED (evidence incomplete) · NOT RUN · OWNER DECISION. **NOT RUN is never
PASS.**

---

## 1. Purpose

Make **one branch, SPN4 Sunu** (branch id 5; rule 165), ready for a **controlled RME pilot** on the verified Biznet
platform. The pilot covers this flow: front office → visit → doctor's medical record → cashier.

The pilot may start only when every blocker in §3 is PASS, or has an owner decision where the blocker allows one.

This design lock **does not** activate the pilot. The database cutover (G12) and the pilot activation and clinic
opening (G13) each need their own owner authorisation.

## 2. Scope

**In scope:**
- evidence for, and handling of, the blockers in §3;
- SECURITY FIX corrections of proven defects, each in its own PR with its own authorisation;
- the database transition plan of `docs/governance/full-development-production-testing-policy.md` §0.8;
- the SPN4 UAT scripts;
- operational readiness.

**Out of scope:**
- new features (POST-FOUNDATION BACKLOG);
- **LAB-fee in any form**: rules 245–249 and the amendments to rules 215/216 stay DESIGN LOCKED, NOT IMPLEMENTED;
- roll-out to TLK1, LDK2 or ATG3;
- any change to production database roles or data outside the authorised paths;
- changes to `.sprint/current.yml`;
- testing on operational clinic devices (policy §4);
- enabling risky flags (CG-08);
- arming `front_office.branch_device_lock` (rule 165 #11).

---

## 3. Pilot blockers

### R-1 — Effective PostgreSQL role capability (R6-02, P0 security)

**Subject:** the application's runtime login role, and every role it can reach.

**PostgreSQL 16 semantics the classification relies on** (each point reproduced on a local PostgreSQL 16.15
fixture cluster; Annex A):
- *Role attributes* (`SUPERUSER`, `BYPASSRLS`, `CREATEROLE`, `CREATEDB`, `REPLICATION`) are never inherited through
  membership. They become effective only on the role itself, or after `SET ROLE` to a role that holds them.
- `SET ROLE` needs a membership chain in which **every** grant has `SET` (from the session user). A chain with one
  grant lacking `SET` does not allow it, even if the other grants have it.
- *Privileges*, including those of predefined roles such as `pg_read_server_files`, follow `INHERIT` chains. They are
  also effective after `SET ROLE` to any role that inherits them. A mixed path (`SET` first, then `INHERIT`) is
  therefore effective, although `pg_has_role(current_user, …, 'USAGE')` and `…'SET'` both report false for it.
- An `ADMIN OPTION` is usable when it is held by the login role, by a role it inherits from, or by a role it can
  `SET ROLE` to. A usable `ADMIN OPTION` on a role lets the login role **grant that role to itself `WITH SET`**, and
  so gain its attributes and privileges, except for roles with `SUPERUSER`, which only a superuser can grant.
  `pg_has_role(…, '… WITH ADMIN OPTION')` ignores `INHERIT` and `SET` and over-reports, so it is not used to decide.
- **Actors**: every role whose identity the login role can assume. These are the login role itself; every role
  reachable by an all-`SET` chain; and every non-`SUPERUSER` role it can grant to itself through a usable
  `ADMIN OPTION`, together with that role's `SET` reach. This is repeated until nothing new is added. The effective
  capability of the login role is the union of the attributes and the inherited privileges of all its actors.

**What is audited:**
1. the login role's attributes;
2. **recursive** membership over every path with **no depth limit**, with `admin_option`, `inherit_option` and
   `set_option` on each grant;
3. **`SET ROLE`** reach and the actor set;
4. effective privileges, including the session's `is_superuser`;
5. **server-file capability**: the predefined roles **`pg_read_server_files`**, `pg_write_server_files` and
   `pg_execute_server_program`, and `EXECUTE` on server-file functions, **for every actor**;
6. usable `ADMIN OPTION`;
7. object ownership, which feeds R-2.

**Fail-closed:** R1-S3 checks that its recursive closures equal PostgreSQL's own role resolution (`pg_has_role`
`MEMBER`, `SET`, `USAGE`). A failed check makes the verdict BLOCKED. A truncated or incomplete walk can therefore
never produce PASS.

**Evidence:**
- The owner runs the R6-A query pack (`docs/architecture/database-least-privilege-r6.md` §9) and the supplementary pack
  **R1-S1…R1-S5** (Annex A) personally, as the application login role, through `php artisan db` inside
  `BEGIN READ ONLY … ROLLBACK`.
- The owner hands over the **original output**: role names, booleans and counts only, with no patient data and no
  credentials.
- The assistant never runs these queries, never uses tinker (CG-14), and never infers results from code.

**Classification by effective capability:**

| Class | Finding (any one is enough) | Consequence |
|---|---|---|
| **CRITICAL** | **E1** login role `rolsuper` or `rolbypassrls`, or `is_superuser = on`. **E2** another **actor** has `rolsuper` or `rolbypassrls` (reached by `SET ROLE` at any depth, or by a self-grant through a usable `ADMIN OPTION`). **E3** an actor has the privileges of `pg_execute_server_program` or `pg_write_server_files`, or `EXECUTE` on the server-file **write** function `lo_export(oid,text)` | **Pilot NO-GO. Path B (R-2) is mandatory**; Path A is not available |
| **HIGH** | **H1** login role `rolcreaterole`, `rolreplication` or `rolcreatedb`. **H2** another actor has one of those attributes. **H3** an actor has the privileges of **`pg_read_server_files`**, or `EXECUTE` on a server-file **read** function (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `pg_stat_file`, `lo_import`), meaning it can read files as the database server's OS user. **H4** an actor has the privileges of `pg_read_all_data`, `pg_write_all_data`, `pg_signal_backend`, `pg_checkpoint` or `pg_create_subscription`. **H5** an actor holds a usable `ADMIN OPTION` on any role. **H6** member, through any path, of a `rolsuper`/`rolbypassrls` role that is not an actor: no usable `SET` or `ADMIN` path, which includes an `ADMIN OPTION` on a `SUPERUSER` role. The attribute is not effective, but this is an anomaly | Must enter the R-2 assessment. Path A only if each HIGH finding is revoked through a planned change, or covered by a tested compensating control (owner decision OD-3) |
| **PASS** | None of the above, and every R1-S3 check is `ok` | Continue to R-2. R6-01 stays open |
| **BLOCKED** | Any query of R6-A or R1-S not run; incomplete output; an error; **an R1-S3 check (C1–C4) `FAILED`**; a cross-check (R1-S4, R1-S5) showing a capability that R1-S3 did not report; or output that cannot be matched to the login role the application uses | No pilot and no partial conclusion. Run again |

The R-1 class is the **verdict row of R1-S3**, read together with its findings and checks. R1-S1, R1-S2, R1-S4 and
R1-S5 are evidence and cross-checks; none of them can lower the class that R1-S3 reports.

### R-2 — One database identity for migrations and runtime (R6-01)

**Facts (VERIFIED, repository):**
- One identity runs migrations and also serves web, queue and scheduler, and almost certainly owns every object.
- A table owner can run `DISABLE TRIGGER USER`, `ALTER` and `DROP`.
- Guard triggers exist on only three clinical tables.
- Rules 245 (least privilege) and 248 (append-only) are DESIGN LOCKED, NOT IMPLEMENTED. This is implementation drift.

**Approving this charter does not accept the R6-01 risk.** Acceptance exists only as the separate owner decision A6
below. It is never automatic and never implied by any gate other than G5.

**Path B — remediate first** (mandatory if R-1 is CRITICAL; otherwise the owner's choice):
- R6-B, in the repository: a `pgsql_migrate` connection, a `foundation:db-privilege-check` command (planned, does not
  exist yet) and a CI role-separation job;
- R6-C: a rehearsal on a restored copy;
- R6-D, in production: a maintenance window after a verified dump, run by the administrator with the owner's sudo
  password (rule 235).

Each stage has its own authorisation. The pilot starts only after R6-D is verified **and R-1 is re-run as PASS**.

**Path A — pilot with R6-01 open.** Available only if R-1 = PASS and **all** of the following hold. If any is missing,
R-2 is **BLOCKED**.

| Element | Minimum content | Evidence |
|---|---|---|
| **A1** Evidence-based risk assessment | Built from the R-1 and R6-A output: objects the runtime owns, guard triggers and their function owners, `SECURITY DEFINER`, RLS, and HIGH findings. Scenario: a compromised runtime disables triggers, or alters or deletes SPN4 medical-record or audit rows. Likelihood, impact, and the one-branch blast radius | An assessment document that quotes output lines |
| **A2** Tested compensating controls | A control counts only with test evidence; an untested control is NOT RUN. **K1** daily backup verified, plus a restore that passed within the freshness window the owner sets (OD-2). **K2** a scheduled integrity check of the guard triggers (`pg_trigger.tgenabled`) during the pilot, with evidence that it detects a disabled trigger in a test environment; it needs implementation (G6). **K3** no direct database access for staff (verify login roles and network access to the database). **K4** application guards on medical records and audit, proven by focused tests. **K5** code rollback (`foundation:deployment-rollback-check` GO) and an escalation procedure | Test evidence per control |
| **A3** Time limit | Path A lasts at most **N days** (OD-2) and always ends before the roll-out to a second branch, whichever comes first | Recorded start and end dates |
| **A4** Stop conditions | Stop the pilot at once if: K2 detects a disabled trigger or unexpected DDL; R-1 changes; a security incident occurs; a backup or restore fails; the time limit expires; or the owner withdraws the acceptance | Written stop procedure |
| **A5** Remediation plan | R6-B…R6-D with target dates, owners and per-stage authorisations, completed before the A3 limit | Dated plan |
| **A6** Separate owner risk acceptance | A CG-22 decision of its own, signed by the owner, naming the R-1 findings, the A1 assessment, the tested A2 controls, the A3 limit and the A4 conditions | Owner decision record (G5) |

### D-1 / D-2 — Data: protection is not cutover eligibility

Two separate dimensions:

| Dimension | Meaning | Applies to |
|---|---|---|
| **Protection class** | Every record is protected in full as personal data (CG-11; rule 221 §19). Nothing is deleted, reset or rewritten (CG-09, CG-13) | **Every** record: legacy, UNKNOWN, in-app entries from the testing phase, recorded test data |
| **Pilot eligibility status** | Whether a record may enter SPN4's operational pilot data after the cutover: `ELIGIBLE`, `PENDING VERIFICATION` or `NOT ELIGIBLE` | Set per provenance class and by verification |

**Protected as real data does not mean eligible for cutover.** Legacy and UNKNOWN records stay protected, but they
remain `PENDING VERIFICATION` until verified.

| Provenance class | Determined by | Initial status | Condition for ELIGIBLE |
|---|---|---|---|
| **L** legacy import | `import_batch_id`, `legacy_patient_id` / `legacy_import_id` (columns VERIFIED in the schema) | PENDING VERIFICATION | D5 for SPN4 recorded (C-1); canonical MRN; no duplicates; batch integrity (batch origin matches SPN4); owner disposition |
| **T** recorded test data | Test-data naming and the recorded test-id list (rule 221 §6) | **NOT ELIGIBLE** | Never. Its disposition is set in the §0.8 plan |
| **A** in-app entry during testing | No import provenance and not on the test list; traced through the creating account (test or staff) and the audit trail | PENDING VERIFICATION | Proven to be a staff entry about a real patient, verified per record by the owner with the branch |
| **U** UNKNOWN | Provenance cannot be established | PENDING VERIFICATION, fully protected | Provenance resolved, or an owner decision per record. Otherwise NOT ELIGIBLE |

**D-1 PASS** when the owner approves the database transition plan of policy §0.8 (G9). The plan covers:
- disposition of the testing data;
- preparation of the operational database;
- initial master data;
- medical-record and MRN continuity;
- branch configuration;
- user roles and permissions;
- data integrity;
- backup and recovery;
- operational readiness;
- the class table above.

**D-2 PASS** when, for **SPN4 (branch id 5)**:
- every patient, visit, medical-record, invoice/payment and document record has a provenance class;
- U = 0, or every U record has an owner decision;
- the owner has approved the ELIGIBLE list. Records that are not ELIGIBLE are excluded from pilot data without being
  deleted.

The owner runs the read-only queries; only counts per class and status are shared. This is an analysis for §0.8, not a
classification feature (rule 221 §19 forbids one). The database stays **TESTING** until G12.

### Other blockers

| Id | Blocker | Evidence | PASS |
|---|---|---|---|
| **L-1** | LAB-fee containment | Read-only code audit and focused tests: no reachable LAB_FEE path, flag or UI in SPN4 | Nothing reachable |
| **S-1** | SPN4 branch isolation (CG-05) | Focused BranchContext, RmeWorkingBranchScope and RME tests on **PostgreSQL 16**; out-of-scope records answer 404 | Pass |
| **S-2** | KTP privacy (CG-11) and OPS-KTP | Audit of masking in the SPN4 flow. The KTP prune timer (CC1 §10.1, run by the owner) is currently VERIFIED `inactive/disabled` | Masking proven, and the timer `active/enabled` |
| **S-3** | Three-layer authorisation and maker ≠ checker (CG-06/07) | Focused tests | Pass |
| **S-4** | SPN4 front-office posture (rule 165) | `php artisan rbac:front-office-branch-pin-audit` (read-only, no arming flag) on the host as the runtime user, under G4. `front_office.branch_device_lock` stays OFF | Cohort matches rule 165, measured rather than inferred |
| **C-1** | D5 for SPN4 (CC1 §10.8) | Confirmation by the SPN4 branch lead (recorded by role), then the canonical closure path, dry run first | Recorded |
| **A-1** | Doctor access and UAT devices | UAT only on dedicated non-operational devices, with a 1:1 test doctor account. **If a trusted Android device is needed, D4 (CC1 §10.7) must PASS.** Enforcement is not weakened (global OFF, `global_permitted` false). Frozen clinic devices are not used | Mode set, and D4 PASS where needed |
| **O-1** | Operational readiness | The eight read-only VPS checks at baseline; backup verified; code rollback; monitoring without new ERROR lines | Baseline |
| **O-2** | Release, only if runtime changes | PR CI with the critical tests run; dispatch on the tip; installed-guard replay PASS; contract 3; `verify` → `DEPLOY RUNNER OK` | Release verified |

**CC1 items that do not block:**
- D5 for TLK1, LDK2 and ATG3;
- CC1-ADJ-1, R7-1-FS and DEFERRED items;
- M5.x and M6.2;
- M6.1 for other branches;
- the CC1 GO tag.

The owner marks which items of the private register (R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4, D3-NEW-3/5, RA-3/5) touch
SPN4 (OD-5). Each marked item becomes an additional blocker.

---

## 4. Acceptance criteria

All criteria start as NOT RUN.

| Id | Criterion |
|---|---|
| **AT-1** | R-1 PASS on complete original output (R6-A + R1-S1…S5): the R1-S3 verdict is PASS, every R1-S3 check is `ok`, and no cross-check contradicts it |
| **AT-2** | R-2 satisfied by one of two paths: Path B (R6-D verified and R-1 re-run as PASS), or Path A (A1–A6 met) |
| **AT-3** | L-1 PASS |
| **AT-4** | S-1 and S-3 PASS on PostgreSQL 16, selected in both critical variants (CG-19) |
| **AT-5** | S-2 PASS (masking and OPS-KTP active) and S-4 PASS |
| **AT-6** | DEVFLOW GO with `--manifest=.sprint/dms-spn4-ready-pilot-1.yml`; Full Suite DEFERRED (CG-18) |
| **AT-7** | For every runtime change: PR CI with the critical tests run, dispatch, guard replay, and a verified release (O-2) |
| **AT-8** | O-1 PASS (VPS baseline, backup, smoke) |
| **AO-1** | §0.8 transition plan approved (D-1); D-2 PASS |
| **AO-2** | C-1 (D5 for SPN4) recorded |
| **AO-3** | A-1: doctor access mode, dedicated devices, 1:1 test account, and D4 where needed |
| **AO-4** | SPN4 role accounts follow separation of duties |
| **AO-5** | SPN4 UAT script with steps, expected results and evidence per item; SPN4 management UAT (M6.1) |
| **AO-6** | Code rollback and escalation documented; for Path A, the A4 stop procedure |
| **AO-7** | GO / WATCH / NO-GO report. The cutover and the activation stay owner decisions |

## 5. Owner gates

| Gate | Authorisation |
|---|---|
| G1 | Record this Design Lock (through PR-C1) |
| G2 | PR-C1 (charter): implement, then merge |
| G3 | PR-C2 (parked manifest): implement, then merge |
| G4 | Read-only VPS/DB checks: the eight checks, S-4, and the owner-run R-1, R1-S and D-1/D-2 queries |
| G5 | R6 path decision. For Path A, the separate risk acceptance A6 |
| G6 | Implementation authorisation per correction, including K2 under Path A |
| G7 | Merge per PR |
| G8 | Dispatch on the target tip |
| G9 | Approval of the §0.8 database transition plan |
| G10 | Controlled UAT (dedicated devices, test identities, rule 221), the doctor access mode, and D4 where needed |
| G11 | Release decision (contract 3, deploy on the VPS) |
| G12 | Database cutover for SPN4 and use of real patients |
| G13 | Pilot activation and clinic opening |

## 6. Seven-day plan

One developer, 12–15 hours per day. Starts after G1.

| Day | Work | Gates |
|---|---|---|
| 1 | PR-C1 and PR-C2; query packs (R6-A + R1-S, D-1/D-2) ready for the owner; §0.8 outline | G2, G3 |
| 2 | Owner runs the queries; the assistant analyses R-1 and D-2; read-only audits L-1 and S-1…S-4 | G4 |
| 3 | R-1 classification and the R6 path; under Path A, the A1 assessment and K1–K5 design; under Path B, the R6-B plan; SECURITY FIX design | G5, G6 |
| 4 | Implementation (corrections; K2 under Path A) in an isolated worktree; PostgreSQL 16 tests; DEVFLOW | — |
| 5 | PR CI, merge, dispatch; test the A2 controls; complete §0.8; UAT scripts | G7, G8, G9 |
| 6 | OPS-KTP, D5 for SPN4, the doctor access decision and D4; controlled UAT on dedicated devices | G10 |
| 7 | Release if needed; GO / WATCH / NO-GO report; under Path A, the A6 signature | G11 (G12, G13 separate) |

**Schedule risks:**
- Path B, D4 (BLOCKED_EXTERNAL) and K2 can each push the plan past seven days.
- The A6 signature cannot be accelerated.

## 7. Prohibitions

Until the matching gate:
- no commit, push, PR, merge, application-code, database or VPS change, deploy, migration, GO tag, rollback or BK-2;
- no testing on operational clinic devices;
- no LAB-fee;
- no change to `.sprint/current.yml`;
- no arming of risky flags.

## 8. Owner decisions still open

| # | Decision | When |
|---|---|---|
| OD-2 | N days for Path A, and the restore-freshness window for K1 | Before G5 |
| OD-3 | Handling of HIGH findings in R-1 | After R-1 |
| OD-4 | R6 path A or B; under A, the separate acceptance A6 | G5 |
| OD-5 | Private-register items that touch SPN4 | Before G5 |
| OD-6 | Doctor access mode; a trusted Android device makes D4 mandatory | Before G10 |
| OD-7 | Disposition of SPN4 records in classes U and A, and the ELIGIBLE list | G9 |
| OD-8 | Dedicated test devices and a 1:1 test doctor account | Before G10 |
| OD-9 | Durable rule 250 (optional) | G1 or G2 |
| OD-10 | Cutover (G12) and pilot activation (G13) | Last |

OD-1 (approval of charter v4 and of this final text) was decided by the owner on 2026-11-22 (DL-1).

**Status after G4 and G5 (2026-11-22, §10 G5-1 and G5-2):**
- OD-4 is decided: Path B, with no A6.
- OD-2 does not apply.
- OD-3 is not needed, because R-1 has no HIGH finding.
- OD-5 is PENDING OWNER CLASSIFICATION (§12.6).
- OD-6 to OD-10 remain open.

## 9. Manifest and GO tag

- `.sprint/current.yml` declares this sprint from the merge of the manifest switch of §9.1. Before that merge it
  declared CC1, and the SPN4 manifest was **parked** at `.sprint/dms-spn4-ready-pilot-1.yml` (PR-C2, #686), with
  DEVFLOW run through `--manifest`.
- After the switch, CC1 is the parked manifest, at `.sprint/dms-rme-comprehensive-closure-1.yml`, and its DEVFLOW
  checks run through `--manifest`.
- Docs, rules and tests close without a deploy and without a tag (CG-16).
- A runtime release needs its own manifest-switch decision (OD-B3-1 precedent) and the `go_tag`
  `dms-spn4-ready-pilot-1-go`. No multi-sprint exception is used.

### 9.1 Manifest switch for the first SPN4 runtime release (proposed 2026-11-23)

The owner's directive of 2026-11-23 asked for this switch as a pull request and authorised neither its merge nor a
deploy. **The owner's merge of that pull request is the switch decision (CG-22).** Until then `.sprint/current.yml`
stays CC1.

**Why the switch is needed.** The release guard (rule 229, rule 236), the signing helper and
`scripts/sprint-release.sh` read the GO tag from the release commit's own `.sprint/current.yml`. A release of the
merged SPN4 runtime changes must therefore be a commit whose `current.yml` declares `dms-spn4-ready-pilot-1-go`.

**What the switch does:**
- `.sprint/current.yml` becomes the SPN4 manifest, with `runtime_change: true` and `deploy_required: true`. Every
  other field is unchanged.
- The CC1 manifest is parked verbatim at `.sprint/dms-rme-comprehensive-closure-1.yml`. CC1 stays OPEN with its
  `go_tag` unchanged.
- The parked copy `.sprint/dms-spn4-ready-pilot-1.yml` is removed, so that no two manifests declare one `go_tag`.

**Release scope.**
- In scope: R6-B1 (#689, `1e985d8e`) and REM-2/R2-6 (#690, `295b3d5e`), both MERGED, NOT DEPLOYED.
- Also in scope: REM-1 (#696, `d628e0cb`; its governance amendment #692, `13cc32e5`), MERGED, NOT DEPLOYED. It
  changes only the CI workflow and `config/ci_runner.php`. It reaches the host with the release, changes no runtime
  behaviour there, and closes with the sprint.
- Not in scope: schema, migration, permission, role, flag, timer or package changes, and any production role or
  privilege change (R6-D is separately authorised).

**What the switch does not authorise.**
- It does not authorise a deploy. The release follows `docs/runbooks/production-host-identity.md` §6g: one
  `workflow_dispatch` CI run on the exact release SHA, the owner's written approval and signature, `submit-approval`,
  `check-approval`, then `start` and `verify`.
- It does not create the GO tag. The tag comes only after a release, its production verification and the owner's
  explicit decision (CG-16). The owner decided (§10 G6-R1, §13.12) that the first SPN4 release is an **interim
  runtime release that creates no GO tag**. The sprint stays OPEN after it, and the manifest keeps declaring
  `go_tag: dms-spn4-ready-pilot-1-go` for the sprint's final closure.

The pilot, Gate 1 RME-LAB-FEE-APPROVAL-1, G12 and G13 stay as recorded in §13.9.

## 10. Owner decision record (CG-22)

| # | Date | Decision |
|---|---|---|
| DL-1 | 2026-11-22 | The owner approves the final Design Lock text, based on the review of charter v4 and its final conditions. The conditions are: `pg_read_server_files` is checked in R-1; risk is classified by effective PostgreSQL capability, including recursive membership and `SET ROLE`; and approving the charter is not an acceptance of the R6-01 risk |
| DL-2 | 2026-11-22 | English is the canonical language of this document. The owner's decisions are also summarised in Indonesian (§11) |
| DL-3 | 2026-11-22 | Every provision on R6 security, data classification, risk acceptance, blockers, AT-1…AT-8, AO-1…AO-7 and G1–G13 is approved |
| DL-4 | 2026-11-22 | Gate 1 of RME-LAB-FEE-APPROVAL-1 stays NO-GO, and LAB-fee stays disabled |
| DL-5 | 2026-11-22 | PR-C1 is authorised: a new isolated worktree and branch from the verified base; this document plus one entry in `docs/00-INDEX.md`; DEVFLOW and local documentation tests; commit, push, and a PR without auto-merge; CI checked to completion; then a stop before the merge for the owner's review |
| DL-6 | 2026-11-22 | Not authorised by DL-5: application code; database or migrations; `.sprint/current.yml`; PR-C2; production access or changes; deploy; GO tags; BK-2; enabling LAB-fee. Approving the charter is not an acceptance of the R6-01 risk, and G1 is not formally complete until PR-C1 is merged and verified |
| DL-7 | 2026-11-22 | Review of PR-C1 #685. The owner authorises a correction of Annex A so that the role audit never concludes PASS from a truncated walk: R1-S3 without a fixed depth limit and fail-closed (BLOCKED when incomplete), `ADMIN OPTION` read by effective capability and membership path, and R1-S3 checked against R1-S4. Validation on an isolated local PostgreSQL 16 with temporary fixtures, and no repository test file. Repository changes stay limited to the two approved documentation files. The fix lands as a new commit on the PR #685 branch, with no amend or force-push |
| G2–G3 | 2026-11-22 | PR-C1 #685 was merged into the base as `a6b32c16`: G1 recorded, G2 done. PR-C2 #686 was merged as `71cc096b` and added the parked manifest: G3 done. `.sprint/current.yml` is unchanged (CC1) |
| G4-1 | 2026-11-22 | The owner authorises the read-only G4 audit. It covers: repository baseline and DEVFLOW; the eight VPS checks through the operator path; the R6-A pack, R1-S1…R1-S5 and a D-2 aggregate pack, run by the owner as the application login role in read-only transactions; S-4; and read-only audits of L-1, S-1…S-3, C-1, A-1, O-1 and O-2. Not authorised: production changes, sudo by the assistant, BK-2, LAB-fee, cutover, acceptance of the R6-01 risk, G5, P0 implementation |
| G4-2 | 2026-11-22 | The owner closes G4 as **AUDIT COMPLETE — PILOT BLOCKERS REMAIN OPEN**, on the reconciled owner-run evidence (§12): R-1 PASS; R6-01 exposure verified; D-2 BLOCKED. A complete audit does not mean the blockers pass. No production query is run again to close G4 |
| G5-1 | 2026-11-22 | **OD-4 = Path B — remediation before production cutover.** Path A is not chosen. No R6-01 risk is accepted through A6, and approving the charter is not a risk acceptance. OD-2 does not apply. Database privilege separation is completed before G12/G13. R6-01 is not marked CLOSED before R6-D is verified. R-1 is run again after R6-D and must be PASS. UAT G10 on the TESTING database may be planned separately, and stays subject to its blockers and to the G10 authorisation |
| G5-2 | 2026-11-22 | The Path B selection is approved. Formal G5 closure waits for OD-5 (PENDING OWNER CLASSIFICATION, §12.6) and for this record to be merged. OD-3 is not needed, because R-1 has no HIGH finding. No change to database privileges is authorised now |
| G5-3 | 2026-11-22 | The owner authorises preparation only: this G4/G5 record as a local patch; the OD-5 reconciliation; the R6-B, R6-C and R6-D designs; the blocker inventory and remediation sequence; safe local checks and DEVFLOW; and a PR package for review. Each of the following needs a new authorisation: commit, push, PR, merge, G6 implementation, production change, deploy, cutover, pilot activation |
| G6-DQ1 | 2026-11-23 | Owner approval of 2026-11-23 (directive "G6 Decision Reconciliation and REM-1 Publication"). **D-Q1:** the runtime gets the privileges of `pg_read_all_stats` and `pg_read_all_settings`, not membership in `pg_monitor`. Applied in R6-D; recorded as an explicit exception to R6 §2 |
| G6-DQ2 | 2026-11-23 | Same approval. **D-Q2:** creating the read-only role is deferred until a real consumer needs it |
| G6-DQ3 | 2026-11-23 | Same approval. **D-Q3:** every table's runtime privilege class is declared explicitly, per table, in its migration or a helper, fail closed (a table without a class fails). Open details: §13.10 |
| G6-DQ4 | 2026-11-23 | Same approval. **D-Q4:** rule 248 layer 1 is mandatory before R6-D. Open details: §13.10 |
| G6-DQ5 | 2026-11-23 | Same approval. **D-Q5:** revoking `TEMP` from `PUBLIC` is deferred until a risk review |
| G6-DQ6 | 2026-11-23 | Same approval. **D-Q6:** the owner of a restored database is the authorised superuser, never the runtime. Open details: §13.10 |
| G6-DQ7 | 2026-11-23 | Same approval. **D-Q7:** the restore drill runs through the privileged operator, and the runtime is never given CREATEDB |
| G6-RV1 | 2026-11-23 | Owner review follow-up (directive "G6 — FINAL REVIEW FOLLOW-UP"). **#693: CONDITIONAL GO.** The conditions are: label the §13.3 finding as historical; state REM-1 as MERGED, NOT DEPLOYED with #696 and CI run `45131035559` as evidence; keep the header, §9, §9.1 and §13.9 consistent; keep D-Q1 to D-Q7; change no manifest field and nothing in the parked CC1 manifest; add commits without amend or force-push; run DEVFLOW and CI on the latest head; stop before the merge. The merge still needs the owner's explicit order. **#695:** the security correction is accepted as the code-review baseline. Its merge is deferred until the release order is settled. No restore drill runs on the production VPS |
| G6-DQ3a | 2026-11-23 | Owner decisions of 2026-11-23 (rows G6-DQ3a to G6-DQ6b). The directive of G6-RV1 states that these details had the owner's design direction from an earlier review. That text was not on record, so the owner was asked in session, then confirmed each answer in two written decisions: "Keputusan desain Owner untuk empat pertanyaan CG-22" (D-Q3-a, D-Q3-b, D-Q4-a, D-Q6-a) and "Keputusan Owner CG-22 — D-Q4-b dan D-Q6-b". **D-Q3-a:** the 186 historical tables get an explicit registry of their runtime privilege class; their migrations are not edited (CG-09). CI compares the registry with the PostgreSQL 16 schema and fails on an unclassified table or on any mismatch. Implementation: OPEN (R6-B2) |
| G6-DQ3b | 2026-11-23 | **D-Q3-b:** every new migration declares its runtime privilege class explicitly. A migration never executes GRANT. GRANT is applied only by the authorised R6-B3 provisioning, with a dry run, verification and rollback. Implementation: OPEN (R6-B2, R6-B3) |
| G6-DQ4a | 2026-11-23 | **D-Q4-a:** R6-B5 adds a column guard trigger on `trx_inventory_movements`. The ledger stays immutable: UPDATE is allowed only on the approved columns `inventory_batch_id` and `updated_at`, and only for a validated legitimate operation. An UPDATE of any other column, DELETE and TRUNCATE are refused. Tested on PostgreSQL 16 with positive and negative scenarios. Implementation: OPEN (R6-B5) |
| G6-DQ4b | 2026-11-23 | **D-Q4-b:** R6-B5 brings additive migrations (triggers, foreign keys changed to RESTRICT). The first SPN4 release keeps `schema_change: false` as long as it carries no schema change. Before R6-B5 is released, a separate manifest pull request sets `schema_change: true`. CI, the additive migration, compatibility, backup and rollback are verified before a release permission is requested. The manifest change is not a permission to run a production migration |
| G6-DQ6a | 2026-11-23 | **D-Q6-a:** when a role that owns objects in a dump does not exist on the target, the restore stops, fail closed, and reports the missing role. It never creates the role, never ignores ownership and never gives ownership to the runtime. An authorised operator repairs the role separately before the restore is repeated. Implementation: OPEN |
| G6-DQ6b | 2026-11-23 | **D-Q6-b:** no exception: D-Q6 applies to every restore, pre-R6 dumps included. No `--legacy-pre-r6-owner` flag is created or enabled. A restored database is owned by the authorised PostgreSQL superuser, and application object ownership follows the R6 design. A missing required role stops the restore, fail closed. Legacy dump compatibility goes through a separate procedure controlled by an authorised operator, which validates the final ownership and privileges. The runtime is never given CREATEDB, CREATE on a schema or ownership to work around a restore problem |
| G6-AUTH | 2026-11-23 | Both written decisions of G6-DQ3a. The PostgreSQL 16 validation of §13.11 (33/33) is test evidence, not a production authorisation. Local implementation and tests of R6-B5 and R6-B6 continue by dependency, with positive, negative and PostgreSQL 16 regression tests. Not authorised: deploy, production migration, production privilege change, R6-D, GO tag, BK-2, SPN4 pilot activation; each needs its own approval gate |
| G6-R1 | 2026-11-23 | Owner directive "OWNER DIRECTIVE — G6 RELEASE-1 AND R6 CONTINUATION". The owner approves the first SPN4 deployment as an **interim runtime release without creating a GO tag**, subject to formal release authorisation (runbook §6g). Release ID `dms-spn4-runtime-r1-20261123`. Scope: R6-B1 (#689), REM-2/R2-6 (#690), REM-1 (#696) and the SPN4 manifest switch (#693). B5 CI-CD-PR-ONLY reaches the host inertly; it is not a separate sprint deployment. The release is not the R6-B5 schema migration. The sprint stays OPEN; no sprint GO is declared; the manifest `go_tag` is kept. Detail and gates: §13.12 |
| G6-CG11 | 2026-11-23 | Directive of G6-RV1. **CG-11 design direction:** identity numbers are masked by default, with a controlled reveal. Implementing it on the consent page needs its own contract and tests. Until then the consent page is unchanged and CG-11 stays OPEN |
| G6-OD5 | 2026-11-23 | Directive of G6-RV1. **OD-5 stays PENDING** until the private register can be reconciled |
| G6-R1-DSP | 2026-11-24 | Owner directive "G6 RELEASE-1 CI DISPATCH ONLY". One `workflow_dispatch` on the release SHA, default inputs: run `45277338107`. Re-dispatch, signature and deploy not authorised by it (§13.13) |
| G6-R1-SIG | 2026-11-24 | Owner directive "G6 RELEASE-1 PRE-SIGNATURE GATE" (read-only), then the owner's written approval "**APPROVED FOR OWNER SIGNATURE ONLY**" of `4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a` for release `dms-spn4-runtime-r1-20261123`, CI `45277338107`, scope as in §13.12. Conditions: no GO tag; the sprint stays OPEN; the Full Suite stays DEFERRED; no production migration or privilege change; R6-01 not closed; pilot, G12, G13 and LAB-fee NO-GO; the freeze holds; submission, deploy and rollback need their own authorisation. The owner signed in the owner's own terminal (§13.13) |
| G6-R1-SUB | 2026-11-24 | Owner directive "G6 RELEASE-1 CONTRACT-3 APPROVAL SUBMISSION": one `submit-approval` of the verified bundle, then a read-only `check-approval`. No deploy authorised (§13.13) |
| G6-R1-DEP | 2026-11-24 | Owner directive "RELEASE-1 PRODUCTION DEPLOYMENT", confirmed by the owner in writing before `start`: one deploy of the approved SHA, then `follow`, `verify` and read-only post-deploy checks. No GO tag, no rollback without an owner decision (§13.13) |
| G6-R1-EXC-MG | 2026-11-24 | Directive "G6 RELEASE-1 CG-22 CLOSURE FINAL PREPARATION", option M-A, approved by the owner in writing. The owner accepts `PatientMergeForeignKeyCatalogRoleSeparationTest` 7/7 on the release SHA (CI `45277338107`) as limited substitute evidence for the R6-B1 merge-guard check of gate 9 (§13.12). **MERGE GUARD PRODUCTION TEST: NOT RUN. CI SUBSTITUTE EVIDENCE: PASS.** Reason: the guard runs only inside a patient-merge case, and creating one writes production data. Residual risk: the production behaviour is not observed directly; it rests on code identity and on CI of the same SHA. No merge case is created in production (§13.13) |
| G6-R1-EXC-NIK | 2026-11-24 | Same directive, option N-A, approved by the owner in writing. The owner accepts `PatientKtpNeverFlashedTest` 7/7, `RmeVisitConsentGuardianIdentityNeverFlashedTest` 3/3, `RmeRefundRequestNikNeverFlashedTest` 3/3 and `PatientKtpDatabaseErrorLogRedactionTest` 10/10 on the release SHA as limited substitute evidence for the REM-2/R2-6 check of gate 9. **NIK SESSION FLASH PRODUCTION TEST: NOT RUN. CI SUBSTITUTE EVIDENCE: PASS.** Reason: the check needs an authenticated submission, which writes session and audit data. Residual risk as for G6-R1-EXC-MG. No login, POST or test session in production (§13.13) |
| G6-R2-ID | 2026-11-24 | Directive "R6-B5 RELEASE-2 R2-1 DOCS PR PUBLICATION ONLY". The owner, in writing, sets the release-2 (R6-B5) release ID **`dms-spn4-r6b5-r2-20261124`** and approves publishing the R2-1 record (§13.14) for review. This authorises no merge, dispatch, signature, submission, deploy, production query or migration; each needs its own decision. Deployment stays on hold |
| G6-R2-RO | 2026-11-24 | Gate R2-3, approved by the owner in writing. The owner runs the read-only Annex B pack on production through the application login role. Claude does not connect to the production database and evaluates the shared rows (§13.15) |
| G6-R2-CI | 2026-11-24 | Gate R2-6, approved by the owner in writing: exactly one `workflow_dispatch` on the candidate, with default inputs → run `45415743102`. The base is frozen from the dispatch until the deploy is verified (§13.15) |
| G6-R2-SIG | 2026-11-24 (2026-11-25 WITA) | Gate R2-7, the owner's written approval of the exact candidate `7f58a3afe11ac6beeaff26b0028646831f4b9793`. The owner accepts the Full Suite as DEFERRED, not passed; records the WATCH `RELEASE-SAFETY-EVIDENCE-CHAIN`; and approves signature only, with a 48 h window. The owner signed in the owner's own terminal (§13.15) |
| G6-R2-SUB | 2026-11-24 (2026-11-25 WITA) | The owner's written authorisation of one `submit-approval` and one read-only `check-approval`. No deploy (§13.15) |
| G6-R2-DEP | 2026-11-24 (2026-11-25 WITA) | The owner's written authorisation of **one production start** of `dms-spn4-r6b5-r2-20261124` at `7f58a3af`. Conditions: final preflight PASS with fresh R2Q7–R2Q9; no old psql session; outside 02:25–02:40 WITA; no automatic retry, rollback or restore; R2-8 verification afterwards. No merge, dispatch, R6-D, GO tag or pilot (§13.15) |
| G6-R2-CLS | 2026-11-25 | Directive "CG-22 RELEASE-2 FORMAL CLOSURE". The owner approves the administrative closure record of release-2 (§13.15). It authorises no GO tag, deploy, migration or pilot activation |

## 11. Ringkasan keputusan Owner (Bahasa Indonesia)

- **DL-1.** Owner menyetujui naskah final Design Lock berdasarkan hasil review charter v4. Isinya: pemeriksaan
  `pg_read_server_files` di R-1; klasifikasi risiko berdasarkan kemampuan efektif role PostgreSQL, termasuk keanggotaan
  rekursif dan `SET ROLE`; serta penegasan bahwa persetujuan charter **bukan** penerimaan risiko R6-01.
- **DL-2.** Bahasa Inggris adalah bahasa kanonik dokumen ini. Keputusan Owner diringkas dalam bahasa Indonesia di
  bagian ini.
- **DL-3.** Seluruh ketentuan keamanan R6, klasifikasi data, penerimaan risiko, blocker, AT-1–AT-8, AO-1–AO-7, dan
  G1–G13 disetujui.
- **DL-4.** Gate 1 RME-LAB-FEE-APPROVAL-1 tetap **NO-GO**, dan LAB-fee tetap nonaktif.
- **DL-5.** PR-C1 diizinkan: worktree dan branch baru yang terisolasi dari base terverifikasi; dokumen ini ditambah
  satu entri di `docs/00-INDEX.md`; DEVFLOW dan pengujian dokumentasi lokal; commit, push, dan PR tanpa auto-merge;
  pemeriksaan CI sampai selesai; lalu berhenti sebelum merge untuk review Owner.
- **DL-6.** Tidak diizinkan: mengubah kode aplikasi, database, atau migrasi; mengubah `.sprint/current.yml`; membuat
  PR-C2; mengakses atau mengubah produksi; deploy; GO tag; BK-2; mengaktifkan LAB-fee. Persetujuan charter bukan
  penerimaan risiko R6-01, dan G1 belum selesai secara formal sebelum PR-C1 di-merge dan diverifikasi.
- **DL-7.** Review PR-C1 #685: Owner mengizinkan perbaikan Annex A supaya audit role tidak pernah menyimpulkan PASS
  dari penelusuran yang terpotong. Rinciannya:
  - R1-S3 tanpa batas kedalaman tetap dan fail-closed (BLOCKED bila tidak lengkap);
  - `ADMIN OPTION` dinilai dari kemampuan efektif dan jalur keanggotaan;
  - R1-S3 diverifikasi terhadap R1-S4;
  - validasi di PostgreSQL 16 lokal yang terisolasi dengan fixture sementara, tanpa file test repository.

  Perubahan repository tetap terbatas pada dua file dokumentasi. Perbaikan masuk sebagai commit baru di branch
  PR #685, tanpa amend atau force-push.
- **Masih terbuka (saat G1):** keputusan Owner OD-2 sampai OD-10 (§8). Penerimaan risiko A6 hanya dapat diberikan di G5
  sebagai keputusan tersendiri.
- **G2–G3.** PR-C1 #685 di-merge sebagai `a6b32c16`, PR-C2 #686 sebagai `71cc096b`. `.sprint/current.yml` tetap CC1.
- **G4-1.** Owner mengizinkan audit G4 read-only: baseline dan DEVFLOW; delapan cek VPS; paket R6-A, R1-S1…S5 dan D-2
  yang dijalankan Owner sebagai role login aplikasi dalam transaksi read-only; S-4; audit read-only blocker lain.
- **G4-2.** G4 ditutup sebagai **AUDIT COMPLETE — PILOT BLOCKERS REMAIN OPEN** (§12):
  - R-1 PASS;
  - eksposur R6-01 terbukti;
  - D-2 BLOCKED.
  Audit lengkap tidak berarti blocker lulus.
- **G5-1.** **OD-4 = Path B**, remediasi sebelum cutover produksi:
  - Path A tidak dipilih, dan tidak ada penerimaan risiko lewat A6;
  - OD-2 tidak berlaku;
  - pemisahan hak akses database selesai sebelum G12/G13;
  - R6-01 tidak CLOSED sebelum R6-D terverifikasi;
  - R-1 dijalankan ulang setelah R6-D dan harus PASS;
  - UAT G10 di database TESTING boleh direncanakan terpisah, tetapi tetap tunduk pada blocker dan izin G10.
- **G5-2.** Pemilihan Path B disetujui. Penutupan formal G5 menunggu OD-5 (PENDING OWNER CLASSIFICATION) dan merge
  catatan ini. OD-3 tidak diperlukan. Belum ada izin mengubah privilege database.
- **G5-3.** Hanya persiapan yang diizinkan:
  - catatan ini sebagai patch lokal;
  - rekonsiliasi OD-5;
  - desain R6-B, R6-C dan R6-D;
  - urutan remediasi;
  - pemeriksaan lokal dan DEVFLOW;
  - paket PR.
  Commit, push, PR, merge, implementasi G6, perubahan produksi, deploy, cutover, dan aktivasi pilot masing-masing butuh
  izin baru.
- **Pilot tetap NO-GO.**

**Persetujuan Owner 2026-11-23 atas arah desain R6-B (§10 G6-DQ1…G6-DQ7):**

| Butir | Keputusan |
|---|---|
| D-Q1 | Runtime memakai `pg_read_all_stats` dan `pg_read_all_settings`, bukan `pg_monitor` |
| D-Q2 | Role read-only ditunda |
| D-Q3 | Kelas privilege runtime dideklarasikan eksplisit per tabel (migrasi atau helper), fail-closed |
| D-Q4 | Rule 248 lapis 1 wajib sebelum R6-D |
| D-Q5 | Pencabutan `TEMP` dari `PUBLIC` ditunda sampai review risiko |
| D-Q6 | Pemilik database hasil restore adalah superuser berwenang, bukan runtime |
| D-Q7 | Restore drill lewat operator berprivilege, tanpa CREATEDB untuk runtime |

**Tindak lanjut review Owner 2026-11-23 (§10 G6-RV1 sampai G6-OD5):**
- **#693: CONDITIONAL GO.** Syaratnya:
  - temuan §13.3 diberi label historis;
  - REM-1 dinyatakan MERGED, NOT DEPLOYED dengan bukti #696 dan CI `45131035559`;
  - header, §9, §9.1 dan §13.9 konsisten;
  - D-Q1 sampai D-Q7 tetap;
  - tanpa perubahan field manifest atau CC1 yang diparkir;
  - commit tambahan tanpa amend atau force-push;
  - DEVFLOW dan CI pada head terbaru.

  Merge tetap menunggu perintah eksplisit Owner.
- **#695:** koreksi keamanan diterima sebagai baseline code review. Merge ditunda sampai urutan rilis selesai. Restore
  drill tidak dijalankan di VPS produksi.
- **Detail D-Q** (jawaban Owner di sesi, dikukuhkan dengan dua keputusan tertulis Owner, 2026-11-23):

  | Butir | Keputusan | Implementasi |
  |---|---|---|
  | D-Q3-a | Registry eksplisit kelas privilege runtime untuk 186 tabel historis tanpa mengubah migrasi lama; CI membandingkannya dengan skema PostgreSQL 16 dan gagal bila ada tabel tak terklasifikasi atau tidak cocok | OPEN (R6-B2) |
  | D-Q3-b | Migrasi baru wajib mendeklarasikan kelasnya; migrasi tidak menjalankan GRANT; GRANT hanya lewat provisioning R6-B3 yang terotorisasi (dry-run, verifikasi, rollback) | OPEN (R6-B2, R6-B3) |
  | D-Q4-a | R6-B5 memasang trigger penjaga kolom `trx_inventory_movements`: hanya UPDATE `inventory_batch_id` dan `updated_at` untuk operasi sah; UPDATE kolom lain, DELETE dan TRUNCATE ditolak; uji PostgreSQL 16 positif dan negatif | OPEN (R6-B5) |
  | D-Q4-b | Rilis pertama SPN4 tetap `schema_change: false`; sebelum R6-B5 dirilis, PR manifest terpisah menetapkan `schema_change: true`; CI, migrasi aditif, kompatibilitas, backup dan rollback diverifikasi dulu; perubahan manifest bukan izin migrasi produksi | Saat rilis R6-B5 |
  | D-Q6-a | Role pemilik objek dump tidak ada → restore berhenti (fail-closed) dan melaporkan role yang hilang; tidak membuat role, tidak mengabaikan ownership, tidak memberi ownership ke runtime; role diperbaiki operator berwenang sebelum restore diulang | OPEN |
  | D-Q6-b | Tanpa pengecualian, termasuk dump pra-R6; tidak ada flag `--legacy-pre-r6-owner`; DB hasil restore milik superuser berwenang; dump legacy lewat prosedur terpisah operator dengan validasi ownership dan privilege akhir; runtime tidak pernah diberi CREATEDB, CREATE schema atau ownership | — |

- **CG-11:** arah desain = disamarkan secara default dengan reveal terkendali. Implementasi di halaman consent butuh
  kontrak dan tes tersendiri. Sampai saat itu CG-11 OPEN.
- **OD-5:** tetap PENDING sampai register privat dapat direkonsiliasi.
- **Batas (G6-AUTH):** validasi PostgreSQL 16 33/33 adalah bukti pengujian, bukan izin produksi. Tidak ada izin deploy,
  migrasi produksi, perubahan privilege produksi, R6-D, GO tag, BK-2 atau aktivasi pilot SPN4.
- **Rilis-1 (G6-R1):** Owner menyetujui deploy SPN4 pertama sebagai **rilis runtime antara tanpa GO tag**, dengan syarat
  otorisasi rilis formal. ID rilis `dms-spn4-runtime-r1-20261123`; cakupan R6-B1, REM-2/R2-6, REM-1 dan manifest switch.
  B5 tiba tanpa efek dan bukan deploy sprint tersendiri. Bukan migrasi R6-B5. Sprint tetap OPEN, `go_tag` manifest
  dipertahankan (§13.12).
- **Rilis-1 dideploy (G6-R1-DSP, -SIG, -SUB, -DEP; 24 Nov 2026):**
  - Owner mengotorisasi tiap langkah secara terpisah: dispatch CI, tanda tangan saja, pengiriman approval, lalu deploy.
  - Deploy `20261124-021647` terverifikasi (`DEPLOY RUNNER OK`) pada `4eec017c`.
  - **CLOSED (24 Nov 2026):** bukti dump pra-deploy VERIFIED.
  - Dua cek fungsional produksi tetap **NOT RUN**, dengan pengecualian Owner G6-R1-EXC-MG dan G6-R1-EXC-NIK (bukti
    pengganti CI PASS).
  - R6-B1, REM-2/R2-6 dan REM-1 **RELEASED** (§13.13).
  - Tanpa GO tag; sprint tetap OPEN; Full Suite DEFERRED; pilot tetap NO-GO.
- **Rilis-2 (R6-B5) — DRAFT (§13.14):**
  - Manifest #701 dan implementasi #702 sudah di-merge, **belum dideploy**.
  - ID rilis `dms-spn4-r6b5-r2-20261124` (keputusan Owner G6-R2-ID).
  - Draft memuat ruang lingkup, bukti CI dan PostgreSQL 16, risiko, matriks pemulihan migrasi parsial F0–F6 dan
    query pack read-only R2-3 (Annex B).
  - Deploy tetap ditahan; setiap gerbang butuh keputusan Owner tersendiri.
- **Rilis-2 (R6-B5) — DIDEPLOY, TERVERIFIKASI, CLOSED (§13.15):**
  - Deploy `20261124-164148` pada 25 Nov 2026 00:41 WITA; runtime produksi `7f58a3af`.
  - R2-1, R2-2, R2-3, R2-4, R2-6, R2-7 dan R2-8 PASS. R2-5 NOT EXERCISED, karena tidak terjadi kegagalan migrasi.
  - Full Suite DEFERRED, bukan PASS. WATCH governance tercatat.
  - 16 guard aktif di 8 tabel, 2 fungsi guard, dan 2 FK RESTRICT tervalidasi; migrasi R6-B5 tercatat.
  - Tanpa GO tag; sprint tetap OPEN; R6-01 OPEN; R6-D tidak diotorisasi; pilot, G12 dan G13 tetap NO-GO.

---

## 12. G4 audit record and G5 decision (2026-11-22)

**G4 = AUDIT COMPLETE — PILOT BLOCKERS REMAIN OPEN. G5 = Path B selected; formal closure pending OD-5. Pilot: NO-GO.**

### 12.1 Evidence and custody

**Owner-run packs.** The owner ran three packs through `php artisan db` as the application login role, each in a
read-only transaction ended by `ROLLBACK`:
- the R6-A pack (`docs/architecture/database-least-privilege-r6.md` §9, verbatim, SHA-256 `837ed7dc…`);
- R1-S1…R1-S5 (Annex A, verbatim, `98d26c26…`);
- a D-2 aggregate pack (D2-0…D2-7: counts and non-patient metadata only, `b2fdc758…`).

Result sets: **12/12, 6/6 and 9/9**. Two incidents did not affect the result:
- an initial syntax error ran outside any transaction and executed nothing;
- a redundant `BEGIN` inside the already-open read-only transaction was a no-op.

**Identity: PASS.** `current_user` = `session_user` in both Q1 and R1-S1. The owner also checked that
`database.default` = `pgsql` and that the configured username equals `session_user`.

**S-4: PASS.** The owner ran `rbac:front-office-branch-pin-audit --strict` as the runtime user:
- device flag OFF;
- armed 1 of the maximum 4 (the SPN4 admin account only);
- every `agree` ok;
- exit 0.

Account names are not recorded.

**Custody.**
- The reconciliation used the owner's terminal transcript supplied in the conversation. No capture file existed at the
  named path when checked.
- The owner archives the original. The assistant recreated no evidence.
- The per-query reconciliation stays outside this public repository, because the output names production roles.

### 12.2 R-1 = PASS

- R1-S3 verdict: **PASS**, `actors=5 reachable=4 max_depth=2`. Checks C1–C4 `ok`. No E or H finding, which means no
  CRITICAL and no HIGH finding.
- R1-S4: no membership in any sensitive role.
- R1-S5: no server-file capability, and no EXECUTE on the seven server-file functions.
- Login-role attributes are all false; `is_superuser` is off.

**Observation (LOW, not an R-1 class finding).** The runtime role is a member of `pg_monitor` and `pg_read_all_stats`,
which the application's monitoring uses. R6-B decides how this is handled. Rule 245's target is no membership.

### 12.3 R6-01 exposure verified

- The runtime login role owns:
  - 186 of 186 tables and all 177 sequences in `public`;
  - the schema `public`, with CREATE;
  - the three trigger functions.
- The database itself is owned by the superuser role.
- **38 of 38 sensitive tables** have no RLS, and the runtime holds UPDATE, DELETE and TRUNCATE on all of them,
  including `sys_audit_logs`.
- The six guard triggers on three tables are enabled. Their functions:
  - are owned by the runtime;
  - are not `SECURITY DEFINER`;
  - have no pinned `search_path`.

  The runtime can therefore disable or replace them.
- Default privileges defined by the superuser role grant the runtime full DML on objects that role creates.
- Only two non-predefined roles exist: the runtime login role and the superuser. There is no staff database login.
  PostgreSQL listens on loopback only.
- **Blast radius: the whole database, every branch.**

R6-01 needs structural remediation under rules 245 and 248, which are DESIGN LOCKED and not implemented.

### 12.4 D-2 BLOCKED

**SPN4 (id 5, active, RME-enabled) facts:**
- 1,523 patients: **L 1,519** (one committed batch; branch, MRN and batch markers consistent), **U 4**, **A 0**.
- T cannot be proven: no recorded test-id list exists (rule 221 §6).
- A is 0 because creating a patient records no actor.
- Integrity:
  - MRN: none missing, non-canonical or duplicated;
  - **84** NIK values are not 16 digits (not treated as errors automatically);
  - **one** possible duplicate group by name and date of birth;
  - 0 records predate the 2026-09-24 estate reset (rule 165);
  - 20 legacy RME archives, consistent with Program 8 D5 condition 1.

**Handling:**
- The four U patients stay fully protected and PENDING VERIFICATION.
- No record is changed, deleted, merged or marked.
- No record is declared ELIGIBLE.

**D-2 PASS needs:**
- OD-7: the disposition of the four U patients and a test-data policy;
- verification of L: C-1, the duplicate group, and a review of the 84 NIK values;
- an owner-approved ELIGIBLE list;
- D-1 (the §0.8 plan).

### 12.5 Path B plan (designed, not implemented)

| Stage | Content | Authorisation | Exit evidence |
|---|---|---|---|
| R6-B (repository) | The pieces of the rule 245 target. Connection: `pgsql_migrate` with an explicit `SET ROLE` to the owner role. Inventory: a runtime class for every table (R6-T02), declared by migrations (rule 245 #6). Deploy runner: a privilege step behind a host switch. Check: a read-only `foundation:db-privilege-check`. CI: a PostgreSQL 16 role-separation job (SQLSTATE 42501 negative tests, row locks, runtime smoke) selected in both critical variants. Audit: rule 248 layer-1 guards for the audit and event tables. A provisioning runbook | G6 per PR. The CI job is a workflow change: it needs its own owner approval, a governed update of the pinned workflow states (`CiTriggerRemovalGovernanceTest`), the GitHub `workflow` scope (currently revoked), and PR CI that runs the critical tests. The deploy-runner change is a runtime release (O-2, AT-7) and needs a manifest-switch decision (§9) | CI green on the candidate SHA with the role-separation job run; `foundation:db-privilege-check` exits 0 in CI |
| R6-C (rehearsal) | **C1:** schema-only provisioning with synthetic data on an isolated PostgreSQL 16, with no patient data. **C2:** a full-data restore rehearsal, only on an explicit owner order, using the documented off-server procedure (isolated workstation, no network) and the safeguards set by the owner | Owner, per stage | Restore integrity; provisioning; negative tests; migration as the migrator; backup and restore of the new layout; rollback; measured durations |
| R6-D (production, must be completed and verified before G12/G13) | A maintenance window after a verified dump, run by the administrator with the owner's sudo password (rule 235). Provisioning, ownership transfer, grants per class, verification. An explicit rollback (R6 §8); a code rollback does not undo role changes | Owner (production change). R6-B released under contract 3 first | `foundation:db-privilege-check` exits 0 on production. The owner re-runs R6-A and R1-S: the runtime owns 0 objects; UPDATE, DELETE and TRUNCATE on append-only tables are 0; triggers enabled; R1-S3 PASS |

**R6-B, R6-C and R6-D each need their own implementation authorisation. This record authorises none of them.**

**Prerequisites found during design (repository evidence, reproduced on a local PostgreSQL 16 fixture):**
- Two foreign-key lookups read `information_schema.constraint_column_usage`. That view shows only tables owned by a
  role the session is acting as.
  - **Patient merge.** After the split, the unregistered foreign-key guard of patient merge would find no foreign key
    and **fail open** (`PatientMergeOwnershipRepository`).
  - **DQ1.** The DQ1 constraint check would report every foreign key as missing. The deploy runs it with
    `--fail-on=error`, so it would stop deploys (`Dq1AuditService`).
  - Both lookups move to `pg_catalog` and are released **before** R6-D.
  - Each fix has positive and negative tests on PostgreSQL 16, run both as the owner and as a non-owner runtime role.
    The tests prove three things:
    - an existing foreign key is found;
    - a missing foreign key is reported;
    - a merge that meets an unregistered foreign key is refused.
  - **Neither fix is implemented yet.**
- Two tables planned as append-only are row-locked and partly updated by the runtime: `trx_inventory_movements`
  (batch backfill) and `trx_rme_amendments` (decision status). Their class is APPEND_ONLY_WITH_COLUMNS.
- The application's monitoring needs the privileges of `pg_read_all_stats` and `pg_read_all_settings`. The handling
  is an R6-B design decision.
- One staging restore-drill script expects the runtime role to hold CREATEDB, which conflicts with rule 245 #5.
- The database identity of the host-only BK-2 package step cannot be determined from the repository. It is verified
  on the host by reading its unit and script only, without running BK-2 (rule 232), before R6-D.

The detailed designs, the rehearsal plan and the runbook are prepared outside the repository for review. The
provisioning runbook lands with its implementation (R6 §8), and production role names stay out of this public
repository.

### 12.6 OD-5: PENDING OWNER CLASSIFICATION

The repository holds only the ids of the private register, not its contents, and no repository text ties any of them
to SPN4.

- **R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4** have no public definition. They are not guessed, and each needs an owner
  classification (touches SPN4: yes/no). RA-4 is listed both as decided and as open, and needs reconciliation.
- **RA-3 and RA-5** have public one-line definitions. Both are host-level; RA-5 (restore-drill evidence) relates to
  R6-C.
- **D3-NEW-3/5** are deferred with a cross-branch trigger. A single-branch pilot does not trigger them, unless it is
  used across branches.
- **OPS-SAT and P8R-S4b** are among the seven undefined ids of Sprint 0 but missing from the §3 list. The owner
  confirms whether they belong to OD-5.

Every item classified "touches SPN4" becomes an additional blocker.

### 12.7 Status

| Blocker | Status |
|---|---|
| R-1 | PASS |
| R-2 | PATH B SELECTED, REMEDIATION OPEN |
| D-1 | NOT RUN |
| D-2 | BLOCKED |
| L-1 | PASS (narrow) / WATCH |
| S-1 | PASS locally on PostgreSQL 16; critical-gate selection missing (CG-19) |
| S-2 | FAIL: KTP prune timer installed but disabled; NIK in session flash and in unscrubbed query-exception logs |
| S-3 | PASS locally on PostgreSQL 16 |
| S-4 | PASS |
| C-1 | OPEN (D5 SPN4; REM-5) |
| A-1 | OPEN (OD-6, OD-8; D4 BLOCKED_EXTERNAL) |
| O-1 | PASS (baseline) |
| O-2 | N/A (no runtime change yet) |

| Criterion | Status |
|---|---|
| AT-1 | PASS |
| AT-2 | BLOCKED until remediation |
| AT-3 | PASS (narrow) / WATCH |
| AT-4 | NOT MET (CG-19 selection) |
| AT-5 | FAIL (S-2) |
| AT-6 | PASS (Full Suite DEFERRED) |
| AT-7 | NOT RUN |
| AT-8 | PASS (observation: host restart pending) |
| AO-1 … AO-7 | NOT RUN |

Local test runs without the environment file are recorded as WARN, not as a clean PASS.

### 12.8 Remediation items (open; each needs G6 or an owner operation)

- **REM-1 (CG-19).** Select the branch-context and patient-data-completeness suites in both critical variants.
  This is a workflow change (see R6-B above).
- **REM-2 (NIK protection).**
  - Add the NIK fields to `dontFlash`.
  - Scrub bindings from non-unique query exceptions before logging.
  - Add regression tests.
  - The consent screen is not changed before the owner decides the CG-11 interpretation.
- **REM-3 (OPS-KTP).** Dry run, then activation of the installed timer, then monitoring. This is an owner operation.
- **REM-4 (provenance).** The §0.8 plan and the OD-7 decisions. Optionally, a creation-actor audit for future
  patients.
- **REM-5 (D5 SPN4).** A governance decision on whether SPN4 may run `branch-drain` and `branch-complete` on its own
  confirmation, while the wave-level `complete` still needs all four branches. Closure is not run here.

**Retained:**
- B5-RESIDUAL-1 OPEN;
- FIN-01/FIN-02 per the RME-LAB-FEE-APPROVAL-1 register;
- D4 as a dependency when a trusted Android device is needed;
- dedicated non-operational UAT devices;
- maker ≠ checker;
- LAB-fee stays disabled.

### 12.9 Decision boundary

- **The pilot stays NO-GO.** G12 (cutover) and G13 (pilot activation) are not authorised.
- Gate 1 RME-LAB-FEE-APPROVAL-1 stays NO-GO. BK-2 is SKIPPED.
- No production database role, ownership or privilege changes until R6-D is separately authorised.

**Final status (2026-11-22):**

| Item | Status |
|---|---|
| G4 | AUDIT COMPLETE — PILOT BLOCKERS REMAIN OPEN |
| G5 | PATH B SELECTED (formal closure pending OD-5) |
| R6-01 | REMEDIATION OPEN |
| D-2 | BLOCKED |
| G12 / G13 | NOT AUTHORIZED |
| Gate 1 RME-LAB-FEE-APPROVAL-1 | NO-GO |
| BK-2 | SKIPPED |

---

## 13. G6 local preparation record (2026-11-22)

**G6: LOCAL PREPARATION ONLY** (as recorded on 2026-11-22; the status after the merges of #689, #690 and #696 is in §13.9). The G4 and G5 decisions stand as recorded in §10 (G4-1, G4-2, G5-1 to G5-3) and
§12: G4 AUDIT COMPLETE, G5 Path B selected with formal closure pending OD-5. No Design Lock decision (DL-1 to DL-7)
is changed here.

The owner's directive of 2026-11-22 authorised local implementation and tests of R6-B1 and REM-2, and the
preparation (design, proposal, diff) of REM-1, R6-B, R6-C and R6-D. Each change lives on its own branch, cut from the
base at `72bf77219f27a5221948336351179e0ee12ac25a`. The owner authorised the publication of this record alone on
2026-11-23. It authorises no other publication, no merge, no deploy and no production change; each needs its own
owner decision (§13.8).

**R6-B1 and REM-2 are implemented and tested locally only. Their code is not in the base, not released and not
deployed. REM-1 needs a governance decision. R6-D is not authorised. G12 and G13 are not authorised. The pilot stays
NO-GO.**

**No production change of any kind happens without a separate, explicit owner authorisation.** Not authorised and
not done:
- any production role, privilege, ownership or schema change, or production migration;
- a deploy, a rollback, a VPS configuration change or a restart;
- KTP timer activation, a BK-2 run, a manifest switch, a GO tag, G12, G13 or LAB-fee;
- a change to the consent page before the owner decides the CG-11 interpretation.

### 13.1 R6-B1 — foreign-key introspection that does not depend on table ownership (merged in #689 as `1e985d8e`, not deployed)

**Root cause.** PostgreSQL filters the `information_schema` constraint views by the current role:
- `constraint_column_usage` shows a row only when `pg_has_role(<owner of the referenced table>, 'USAGE')`;
- `table_constraints` and `key_column_usage` need ownership or a privilege other than SELECT.

After the rule 245 split the runtime owns nothing, so those views return nothing to it. The `pg_catalog` tables
(`pg_constraint`, `pg_class`, `pg_namespace`, `pg_attribute`) are readable by every role and are not filtered.

**Change.** One read-only helper, `App\Support\Database\ForeignKeyCatalog`, reads `pg_catalog`. It matches
constraints by OID, never by name, and resolves both tables in the connection's current schema.
- **Patient merge guard** (`PatientMergeOwnershipRepository`). It reads the catalog. A patient foreign key from
  another schema is reported schema-qualified, so it can never match the registry and always blocks. A catalog that
  shows no patient foreign key at all is itself reported, so the merge is refused (fail closed). The blocker code is
  unchanged (`OWNERSHIP_REGISTRY_INCOMPLETE`).
- **DQ1-CONSTRAINT-001** (`Dq1AuditService`). It reads the catalog. This also closes a second defect: the old query
  had no schema filter, so a same-named table in another schema could satisfy it.
- The SQLite paths are unchanged.

**Evidence: disposable local PostgreSQL 16 cluster, synthetic schema from the migrations, no rows, separate LOGIN
roles.** The owner role ran the migrations (186 tables, 177 sequences). The runtime role owns nothing and holds DML
and sequence grants.

| State | Login | Before (DQ1 / merge guard) | After (DQ1 / merge guard) |
|---|---|---|---|
| Clean schema | owner | PASS, exit 0 / none | PASS, exit 0 / none |
| Clean schema | runtime | **FAIL, 6 keys "missing", exit 1** (false FAIL; every deploy stops) / none | PASS, exit 0 / none |
| Unregistered patient FK added | owner | PASS / reported | PASS / reported |
| Unregistered patient FK added | runtime | FAIL / **none: merge allowed (fail open)** | PASS / **reported: merge refused** |
| Critical FK dropped | owner | FAIL, 1 missing, exit 1 | FAIL, 1 missing, exit 1 |
| Critical FK dropped | runtime | FAIL, 6 missing | **FAIL, exactly the dropped key, exit 1** |

**Negative privilege tests as the runtime login.** Each was refused with SQLSTATE `42501`: dropping a constraint,
disabling triggers, `TRUNCATE`, `CREATE TABLE`, `SET ROLE` to the owner, and reading a table without a grant. A role
with no table privilege still reads the same 33 patient foreign keys from `pg_catalog`, and 0 rows from
`constraint_column_usage`.

**Tests.** Two new suites:
- `tests/Feature/PatientMerge/PatientMergeForeignKeyCatalogRoleSeparationTest.php` (7 tests);
- `tests/Feature/DataQuality/Dq1ForeignKeyCatalogRoleSeparationTest.php` (5 tests).

They are selected by the existing `PatientMerge` and `Dq1` tokens in both variants and declared in
`critical_gate_mandatory_suites` (CG-19). The non-owner role is created inside the test transaction and rolled back
with it. A test identity that is neither a superuser nor CREATEROLE gets a visible skip, never a pass.

| Run | Result |
|---|---|
| New suites against the unchanged code | 7 failed: they detect both defects |
| New suites, superuser identity (as in the active CI variant) | 12 passed |
| New suites, CREATEROLE non-superuser identity | 12 passed |
| New suites, plain identity | 5 passed, 7 skipped (visible) |
| Regression (all PatientMerge and DataQuality suites and the other callers), PostgreSQL 16 | 205 tests, 0 failed, 0 skipped |
| Same regression, SQLite | 205 tests, 0 failed, 15 skipped (PostgreSQL-only) |

All local results are WARN, not a clean PASS, because the worktree has no environment file.

### 13.2 REM-2 — the NIK never reaches the session or the application log (merged in #690 as `295b3d5e`, not deployed)

**REM-2a, session flash.** The audit followed the input names from forms, requests, controllers and scripts.

**Correction (2026-11-23, R2-6).** The first pass searched for `ktp` and `nik` names and was therefore not
exhaustive. It missed `consenter_identity_number`, the KTP/SIM number of a guardian on the consent form, which was
refilled with `old()` and so flashed into the session on a refusal. The owner approved the fix on PR #690. A later
input that carries an identity number must be added the same way; the list is the audited set, not a proof that no
other path exists. R2-6 changes only the session flash: the REM-2b log redaction below is unchanged and keeps its safe
diagnostic fields.

| Input | Where | Handling |
|---|---|---|
| `ktp_number` | Master Data, new and edit | Added to `dontFlash` |
| `new_patient.ktp_number` | Visit registration, new patient | Added to `dontFlash` |
| `image_base64` | KTP scan upload (a picture of the card) | Added to `dontFlash` |
| `owner_approval_reference`, `patient_request_reference` | Refused exactly when they look like a NIK | Added to `dontFlash`. The refund-request controller re-flashes input explicitly, which bypasses `dontFlash`, so it now excludes both itself |
| `ktp_scan_token`, `ktp_ocr_*` | Scan reference and OCR flags; no NIK | Still flashed, so an uploaded scan stays attached |
| OCR `lines` and `fields` | The OCR endpoint answers JSON (no flash). Other forms refill these generic names | Not added: that would break other forms. Residual, recorded |
| `consenter_identity_number` (R2-6) | Consent form, guardian's KTP/SIM | Added to `dontFlash`. Both refusal paths (request rules and the consent service) reach the exception handler; the consent controller re-flashes nothing itself |

After a refusal the NIK field is empty (on edit it shows the stored value) and is typed again. Every other field is
still flashed.

**REM-2b, query values in the log.** Laravel's query exception message carries every binding verbatim. PostgreSQL's
DETAIL line echoes the row (`Failing row contains (…)`, `Key (…)=(…)`). Several services log `$e->getMessage()`
themselves.

Every writing log channel now carries one tap (`App\Support\Logging\RedactDatabaseErrors`). A stack channel merges
its members' processors, so it is covered too. The processor rewrites only records that carry a database error. It
keeps the diagnosis:
- exception class, SQLSTATE and driver code, connection name;
- the first driver line, with identifiers kept and values redacted;
- the SQL template with placeholders, and the binding count (never a value);
- the file:line trace without arguments.

It never catches, rethrows or changes an exception, so HTTP answers and rollbacks behave as before. The record keeps
its level, so the ERROR count the monitoring reads does not change. If redaction itself fails, the record is replaced
by a fixed line rather than written raw (fail closed).

**Not changed:**
- historic logs;
- the consent page's display, layout, signature flow and clinical rules (pending the CG-11 decision). Only its session
  flash changed, under R2-6;
- exception text that a failed queued job would store (the queue worker is inactive).

These are residuals, recorded.

**Tests.** Four new suites (23 tests):
- `tests/Feature/Patient/PatientKtpNeverFlashedTest.php` (7 tests);
- `tests/Feature/Patient/PatientKtpDatabaseErrorLogRedactionTest.php` (10 tests; they read the log file Monolog
  actually wrote);
- `tests/Feature/RmeRefundRequest/RmeRefundRequestNikNeverFlashedTest.php` (3 tests);
- R2-6: `tests/Feature/RME/RmeVisitConsentGuardianIdentityNeverFlashedTest.php` (3 tests, the real consent route).

They are selected by the existing `PatientKtp`, `RmeRefundRequest` and `RmeVisitConsent` tokens and declared in
`critical_gate_mandatory_suites`. The identity numbers are synthetic.

| Run | Result |
|---|---|
| New suites against the unchanged wiring | 17 failed; the 3 control tests pass both ways |
| New suites, PostgreSQL 16 / SQLite | 20 passed / 19 passed and 1 skipped (PostgreSQL-only) |
| Regression (Patient, visit registration, refund request, invoice correction, Observability, monitoring), PostgreSQL 16 | 1677 tests, 0 failed, 0 skipped |
| Same regression, SQLite | 1 failed: `LegacyPatientStagedVerificationCancelTest` (500-row batch). It fails identically, twice, on a worktree without these changes, so it is pre-existing and SQLite-only (CG-21). It passes on PostgreSQL 16 |
| R2-6 tests against the code before the fix | Three fail: RVGI-01 (refused by the request rules), RVGI-02 (refused by the consent service) and the updated KF-01 (`dontFlash` registration). The third new test, RVGI-03 (a valid guardian consent still stores the number), is a control and passes both before and after |
| R2-6 tests after the fix | All pass: RVGI-01 to RVGI-03 and KF-01 to KF-07 |
| R2-6, PostgreSQL 16 regression including every consent suite and the merged R6-B1 suites | 1990 tests, 0 failed, 0 skipped |
| CI on the reviewed head `e3c05437`, run 45085855176 | Profile `runtime_app`; the critical tests ran: 11863 passed, 0 failed, 0 skipped. All 23 REM-2 tests and both R6-B1 suites passed. The Full Suite was DEFERRED |

### 13.3 REM-1 — CG-19 critical selection (merged in #696 as `d628e0cb`, not deployed)

**Current state: MERGED, NOT DEPLOYED.**
- REM-1 merged in #696 as `d628e0cb`. Its governance amendment of `CiTriggerRemovalGovernanceTest` merged first, in
  #692 as `13cc32e5`.
- On the base, the workflow file is in the pinned state `82150b8b…` (table below).
- CI run `45131035559` (event `pull_request`, head `35dffabbf76aa308c7b181260a19510bc77d4619`) concluded success:
  - classifier profile `ci_workflow`, critical tests required;
  - the critical regression step ran: 11930 passed, 0 failed;
  - its filter contained the new tokens, and the five newly selected suites passed in it.
- The Full Suite was DEFERRED by the active policy (`authorised: false`). It is not a pass.
- The change reaches the host with the first SPN4 release (§9.1) and closes with the sprint.

The rest of this section is the **historical record of the finding and the proposal, before the REM-1 merge** (G6
local preparation, 2026-11-22). It no longer describes the base.

**Historical finding (before the REM-1 merge).** At that time, no token of either critical variant matched these
suites:
- `BranchContextTest`;
- `BranchContextFallbackTest`;
- `FixClinicOpsWorkingBranchContextTest`;
- `PatientDataCompletenessAuditTest`.

`LabIntegrationTest` ran only in the selective `Lab` job, which runs only when lab paths change. That suite carries
the branch onto lab case candidates.

**Change as proposed then, and applied by #696** (two lines of `.github/workflows/foundation-evidence-gates.yml`,
plus `config/ci_runner.php`):
- Insert `BranchContext|PatientDataCompleteness|LabIntegration` before the last token of BOTH critical filters, so
  the filter keeps ending in `InventoryClinicCalendarBoundary`, as the parallelism contract requires.
- Declare the five suites as mandatory.
- Five files (53 tests) are newly selected; the other `BranchContext` suites already ran through existing tokens.
- No trigger, job, permission, secret, concurrency or classifier change.

**Governance.** The change edits the critical-gate filter, so it is **not** one of the CG-16 CI trigger classes. It
closes as a normal change released with this sprint: merge, deploy, verification and the sprint GO tag. It never
closes on its own.

Before the amendment, `CiTriggerRemovalGovernanceTest` pinned the workflow to two SHA-256 states and rejected every
other edit. The owner-approved amendment (#692) had to:
- pin two further states;
- define the exact token insertion;
- keep the B5 rollback available on the selected file;
- reject removing the tokens again (CG-17).

| State | SHA-256 |
|---|---|
| B5 pre-removal (pinned) | `978e90c028fe6530b6f4eaf422760e1a1f913ab9400dfb24a70a9310c215d3d4` |
| B5 post-removal (pinned) | `4cd0c3dbe7c374e30f05659f89e7c22a2f197005da3d0f92f423124622b98cd0` |
| **REM-1 selected** (pinned since #692; the base state since #696) | `82150b8b56ec96a23fc9406ea0d406bd4c4e5612a1ea14569429475bd76c555a` |
| **REM-1 selected, B5 rollback target** (pinned since #692) | `4335c4dc7db9b4d1053b377a3e766b42e17a0ad9d23df81263eee2265a68bdfb` |

At preparation time, every CI suite passed with the change applied, except the 17 CTRG tests, which failed by design
until the amendment landed. Pushing a workflow file needs the GitHub `workflow` scope, which was revoked then. The
owner granted it temporarily to publish #696; the assistant never widens a token. The R6-B role-separation CI job
(R6-B7) will need a later amendment of the same kind.

### 13.4 R6-B, R6-C and R6-D — design changes found during G6

- **N-9 (new). A disaster restore would undo the split at the database level.**
  - `scripts/restore_postgres.sh` makes the restored database owned by the application login role when no `--owner`
    is given, and `scripts/restore-rehearsal.sh` does the same.
  - A database owner can create schemas. On the local fixture, the runtime role then succeeded in `CREATE SCHEMA`;
    with a superuser-owned database it is refused with `42501`.
  - R6-B6 must keep the database owner at the superuser and fail closed when the owner role is missing. A dump
    replays `OWNER TO` the owner role.
- **Dumps keep working after the split.** The pre-deploy dump, the rollback dump and the backup timer run as the
  application login role. On the fixture, a non-owner runtime with SELECT and sequence grants produced a full dump.
  A superuser restore into a superuser-owned database left 0 objects owned by the runtime.
- **The restore drill without CREATEDB.** `scripts/rollout-restore-drill.sh` runs `createdb`, `dropdb` and the
  replay as the application login role. It moves to the superuser path that `restore-rehearsal.sh` already uses, and
  it refuses to run when the runtime holds CREATEDB. The runtime never receives CREATEDB.
- **Column grants for row-locked append-only tables.**
  - `trx_inventory_movements` needs UPDATE on `inventory_batch_id` and `updated_at`.
  - `trx_rme_amendments` needs UPDATE on its decision columns only. Its trigger already refuses changes to the
    identity columns and to a decided row.
  - On the fixture, the row locks and the allowed column updates succeeded, and any other column was refused with
    `42501`.
- **Monitoring.** The application needs only the privileges of `pg_read_all_stats` and `pg_read_all_settings`, not
  all of `pg_monitor` (D-Q1). Resetting `pg_stat_statements` needs a separate guard (R6-B3), not a grant.
- **BK-2.** The repository contains no BK-2 packaging script, so its database identity stays NOT VERIFIED. It is
  checked on the host by reading only, without running BK-2, before R6-D.
- **Synthetic pre-rehearsal (not the formal R6-C).** The fixture reproduced the production baseline: the runtime owns
  the schema, 186 tables, 177 sequences and 3 trigger functions. Then:
  - one provisioning transaction (70 ms on an empty schema) moved everything to the owner role;
  - every negative test returned `42501`;
  - both R6-B1 checks passed as the runtime;
  - a migrator with an explicit `SET ROLE` created a table owned by the owner role, and without `SET ROLE` it was
    refused;
  - one rollback transaction (65 ms) restored the baseline.

  These durations come from an empty schema. They are not a measurement of the production window; R6-C measures it.

**R6-B2 to R6-B8 and their dependencies.** Each is design only and needs its own G6 authorisation.

| PR | Content | Depends on |
|---|---|---|
| R6-B2 | Migration connection `pgsql_migrate` with an explicit `SET ROLE` to the owner role; class-declaration helper; runtime class inventory of every table (including the column grants above) | D-Q3; R6-B1 released |
| R6-B3 | `foundation:db-privilege-apply` (dry run by default, `--print-sql`) and the read-only `foundation:db-privilege-check`, plus a new check that the database owner is the superuser and the runtime has no CREATE on the database (N-9); guard for the `pg_stat_statements` reset | R6-B2 |
| R6-B4 | Deploy runner: host switch for the migrator; apply, then check; a failed check stops the deploy before smoke | R6-B3; a runtime release (manifest switch, §9) |
| R6-B5 | Rule 248 layer 1 (trigger and model guards), including a guard trigger for `trx_inventory_movements` | D-Q4 |
| R6-B6 | Restore tooling: N-9 (restored database owned by the superuser, fail closed without the owner role), N-5 (restore drill on the superuser path, refusing a runtime with CREATEDB). **Finding A-1 (HIGH, audit 2026-11-25): remediated in #695, never risk-accepted** (owner directive "DMS G6 — POST RELEASE-2 SECURITY REMEDIATION AND PR PREPARATION"). Moving the drill to the superuser path made it replay a runtime-writable dump, unverified, as the superuser. A synthetic dump proved it: a `CREATE ROLE` was applied and a psql `\!` ran, while the evidence said GO. The drill now takes the rule 235 path: a trusted digest (`--deploy-id` or `--expected-sha256`, no default backup); source checks; one root-only staged copy for the preflight, the gate and the replay; and a replay in psql restricted mode. An independent review then found that digest-matching dumps carry schema code the runtime can plant (CHECK, domain, generated column), which COPY runs as the superuser. A synthetic test proved that too. A load guard now refuses such code before any row is loaded. A second independent review found more paths: the schema section (aggregate INITCOND, partition bound), JSON constructors through a cast, foreign-key validation through an implicit cast, and a table name with a newline. Each was proven on the previous commit and is now refused: the guard runs before every pg_dump object and at the end, and refuses user-defined casts. A third independent review found that a CHECK which pg_dump splits out (a row-type or domain-type loop) is validated after the data, before the guard can see it. It was proven on the previous commit; such dumps are now refused before anything is created. The guard remains a deny-list, and each of the three reviews found a new path, so the structural fix (non-superuser restore login, or a throwaway cluster) is an owner decision (rule 235 §5). Owner directive "G6 PR #699 AND #695 PUBLICATION" (2026-11-25): psql, createdb and dropdb run only from one pinned, verified directory, by absolute path and never through PATH. psql must be PostgreSQL 16 at 16.15 or later; otherwise the drill stops before it reads a backup. See rule 235 §5 for the residual risks. R6-B6-F1 stays OPEN (separate) | D-Q6 (N-9 remedy), D-Q7 (drill path) |
| R6-B7 | PostgreSQL 16 role-separation CI job, selected in both critical variants | A second `CiTriggerRemovalGovernanceTest` amendment of the REM-1 kind; the GitHub `workflow` scope (owner) |
| R6-B8 | Provisioning runbook in the repository (placeholders only), rule index, sprint record | R6-B2 to R6-B7 |

**R6-C plan (not run).**
- R6-C1: provisioning with the released SQL on an isolated PostgreSQL 16 with synthetic data only. It covers
  restore and integrity of the new layout, negative tests (`42501`), runtime smoke, migration through the migrator,
  backup and restore, rollback, audit protection, branch isolation (the REM-1 suites), the N-9 and restore-drill
  fixes, and monitoring. Durations are measured.
- R6-C2: a full-data restore rehearsal, only on an explicit, specific owner order, with the owner's safeguards. No
  copy of production data is made for it.
- Entry to R6-D: every R6-C scenario PASS, the provisioning SQL checksum recorded, and measured durations replacing
  estimates.

**R6-D plan (not authorised).**
- A maintenance window after a fresh, verified dump.
- The BK-2 package identity verified on the host by reading only.
- Provisioning in one transaction from the released SQL, checked against the R6-C checksum.
- Verification: privilege check, runtime and migrator checks, DQ1 as the runtime, smoke, and the owner re-running the
  R-1 and R6-A query packs.
- An explicit privilege rollback in one transaction. A code rollback does not undo ownership or privilege changes, and
  a code rollback to before R6-B1 is not allowed while the split is active.
- Stop conditions at every step.
- R6-01 is closed only by an owner decision after acceptance.

Production role names, host details and the full runbook stay outside this public repository until the provisioning
runbook lands with its implementation (R6 §8).

### 13.5 Validation (local, per branch)

| Check | R6-B1 | REM-2 |
|---|---|---|
| `devflow:base-ref-check` | PASS (remote base; stale local ref is informational) | PASS |
| `sprint:manifest-check`, current and with the SPN4 manifest | GO / GO | GO / GO |
| `sprint:scope-audit --strict` with the SPN4 manifest | GO | GO |
| `foundation:devflow-check --strict` | GO | GO |
| `foundation:shared-service-audit --strict` | GO | GO |
| `deploy:forbidden-command-check` | PASS | PASS |
| `git diff --check`, Pint | PASS | PASS |
| `sprint:test-plan` with the SPN4 manifest | Escalates to the Full Suite: **DEFERRED** (CG-18), not run | Same |
| CI | NOT RUN (nothing published) | NOT RUN |

The SPN4 manifest declares `runtime_change: false`. R6-B1 and REM-2 are runtime changes, so releasing them needs the
manifest-switch decision of §9 (OD-B3-1 precedent).

### 13.6 Status after G6 local preparation

*Historical (G6 local preparation, 2026-11-22). The current status is §13.9.*

| Item | Status |
|---|---|
| G4 | AUDIT COMPLETE |
| G5 | PATH B SELECTED, OD-5 PENDING |
| R6-01 | REMEDIATION OPEN |
| G6 | LOCAL PREPARATION ONLY (R6-B1 and REM-2 implemented and tested locally, not released; REM-1 prepared, needs a governance decision; R6-B/C/D designs updated) |
| R6-D | NOT AUTHORIZED |
| D-2 | BLOCKED |
| G12 / G13 | NOT AUTHORIZED |
| Pilot | NO-GO |
| Gate 1 RME-LAB-FEE-APPROVAL-1 | NO-GO |
| BK-2 | SKIPPED |

**Open blockers.** G6 changes no blocker status; local evidence is not a release.

| Blocker | Status | Path |
|---|---|---|
| OD-5 | PENDING OWNER CLASSIFICATION (§12.6) | Owner answers per register id |
| R-2 / R6-01 | REMEDIATION OPEN | R6-B1 release, R6-B2 to R6-B8, R6-C, R6-D |
| D-1 | NOT RUN | §3 |
| D-2 | BLOCKED | OD-7, U disposition |
| S-1 | PASS locally; critical-gate selection missing | REM-1 |
| S-2 | FAIL | REM-2 (local only, not released); REM-3 (KTP prune timer, owner operation) |
| C-1 | OPEN | REM-5 (D5 SPN4) |
| A-1 | OPEN | OD-6, OD-8; D4 when a trusted Android device is needed |
| AT-2 / AT-4 / AT-5 / AT-7 | BLOCKED / NOT MET / FAIL / NOT RUN | R6-D / REM-1 / S-2 / runtime release |
| AO-1 to AO-7 | NOT RUN | Owner acceptance |
| OD-6 to OD-10 | OPEN | Owner |

### 13.7 Pull request plan

*Historical (G6 local preparation, 2026-11-22). The current status is §13.9.*

Each pull request needs its own owner decision to publish and to merge.

| PR | Content | Depends on |
|---|---|---|
| PR-G6-GOV | This record | — |
| PR-R6-B1 | §13.1 | PR-G6-GOV |
| PR-REM2 | §13.2 | PR-G6-GOV |
| PR-REM1-GOV | The `CiTriggerRemovalGovernanceTest` amendment (the two proposed states) and the GO-tag register note | PR-G6-GOV (the SHA-256 values above) |
| PR-REM1 | The workflow and `config/ci_runner.php` change; pushed by the owner (workflow scope) | PR-REM1-GOV |

R6-B1, REM-2 and REM-1 are released together with the SPN4 manifest switch, in one deploy, under the sprint GO tag.
R6-B1 must be released before R6-D.

*Superseded on the GO tag (2026-11-23, §10 G6-R1):* that one deploy is the interim runtime release of §13.12, and it
creates **no** GO tag. The sprint GO tag comes only with the sprint's final closure (CG-16, §9.1). The rest of this
plan is historical.

### 13.8 Owner decisions requested

1. Publication of PR-G6-GOV, then of PR-R6-B1 and PR-REM2 (each separately; merge separately).
2. REM-1 governance:
   - approve the two additional pinned states and the CTRG amendment;
   - choose how the workflow branch is pushed (by the owner, or a temporary re-grant of the `workflow` scope);
   - confirm that REM-1 closes with the SPN4 release, not as a CG-16 class.
3. Manifest switch for the runtime release (§9).
4. OD-5 classification (§12.6), unchanged and still pending.
5. The CG-11 interpretation of the consent page, which REM-2 deliberately left unchanged.
6. The R6-B design questions:
   - D-Q1: the runtime's monitoring membership (recommended: only the privileges of `pg_read_all_stats` and
     `pg_read_all_settings`, instead of `pg_monitor`);
   - D-Q2: whether to create the read-only role now (recommended: later, when it has a consumer);
   - D-Q3: how a migration declares a table's runtime class;
   - D-Q4: rule 248 layer 1 before R6-D (recommended: yes);
   - D-Q5: revoking TEMP from PUBLIC (recommended: after the pilot);
   - D-Q6: the N-9 remedy (owner of a restored database);
   - D-Q7: the restore-drill operator and path.

### 13.9 Status after the merges of #689, #690 and #696 (2026-11-23)

| Item | Status |
|---|---|
| R6-B1 (§13.1) | **MERGED, NOT DEPLOYED** (#689, `1e985d8e`) |
| REM-2 and R2-6 (§13.2) | **MERGED, NOT DEPLOYED** (#690, `295b3d5e`) |
| REM-1 (§13.3) | **MERGED, NOT DEPLOYED** (#696, `d628e0cb`; governance amendment #692, `13cc32e5`). CI run `45131035559`: the five suites now selected passed in the critical gate. The Full Suite is DEFERRED by the active policy, not passed. REM-1 is **not** recorded as released until release-1 is deployed and verified and its closure evidence is recorded (§13.12) |
| Release-1 `dms-spn4-runtime-r1-20261123` (§13.12) | Approved as an interim runtime release, **no GO tag**; pending formal release authorisation. Not dispatched, not signed, not deployed. **Superseded on 2026-11-24 by §13.13: released and closed** |
| SPN4 sprint | **OPEN**. No sprint GO is declared; `go_tag: dms-spn4-ready-pilot-1-go` stays declared for the final closure |
| R6-01 | REMEDIATION OPEN |
| G5 | PATH B SELECTED, OD-5 PENDING |
| R6-D | NOT AUTHORIZED |
| D-2 | BLOCKED |
| G12 / G13 | NOT AUTHORIZED |
| Pilot | NO-GO |
| Gate 1 RME-LAB-FEE-APPROVAL-1 | NO-GO |
| BK-2 | SKIPPED |

**A merge is not a release.** Releasing R6-B1, REM-2 and REM-1 needs:
- the manifest switch of §9.1 (before it, the parked SPN4 manifest declared `runtime_change: false` and
  `deploy_required: false`);
- its own release decision.

R6-B1 must be released before R6-D. No production change has been made.

### 13.10 Reconciliation of the D-Q decisions with the R6-B design (2026-11-23)

The owner approved D-Q1 to D-Q7 on 2026-11-23 (§10 G6-DQ1 to G6-DQ7). These are the owner's decisions, not the
assistant's. Where the design proposed something more specific than the approved direction, the extra detail is
**OPEN**. It is not treated as approved.

| Item | Consistency with the design | OPEN details (need an owner answer or are settled in the implementing PR's review) |
|---|---|---|
| D-Q1 | Matches the recommended option. Local evidence: every artisan check the deploy runs gives the same result as an owner login and as a non-owner runtime holding only these two roles | — |
| D-Q2 | Matches | — |
| D-Q3 | Matches the principle of the recommended option: explicit, per table, fail closed | **D-Q3-a (decided, §10 G6-DQ3a):** the 186 existing tables, whose migrations cannot be edited (CG-09), are declared through a generated inventory registry checked against the live schema in CI. **D-Q3-b (decided, G6-DQ3b):** a declaration records the class only; grants come from provisioning (AIR-DBS-04). Implementation evidence: OPEN (R6-B2, R6-B3) |
| D-Q4 | Matches rule 248 layer 1: triggers refusing UPDATE, DELETE and TRUNCATE on `sys_audit_logs`, the financial and approval `*_events` tables and `trx_lab_order_status_logs`; model guards; foreign keys that restrict on delete | **D-Q4-a (decided, G6-DQ4a):** R6-B5 also adds the column guard trigger on `trx_inventory_movements`: only `inventory_batch_id` and `updated_at` may change, for a validated legitimate operation. **D-Q4-b (decided, G6-DQ4b):** R6-B5 is released through a separate manifest pull request that sets `schema_change: true`. Implementation evidence: OPEN (R6-B5) |
| D-Q5 | The design said "after the pilot"; the decision says "until a risk review" | — (the decision's wording applies) |
| D-Q6 | Matches: the restored database is owned by the superuser | **D-Q6-a (decided, G6-DQ6a):** a missing owner role stops the restore and is reported; it is never created, and ownership is never ignored or given to the runtime. **D-Q6-b (decided, G6-DQ6b):** no pre-R6 exception flag; legacy dumps go through a separate operator-controlled procedure. Implementation evidence for D-Q6-a: OPEN |
| D-Q7 | Matches: privileged operator; all database operations through the superuser path; the drill refuses to run when the runtime holds CREATEDB | — |

CG-11 now has a design direction (§10 G6-CG11): masked by default, with a controlled reveal. Its consent-page
implementation needs its own contract and tests, so CG-11 stays OPEN and the consent page is unchanged. OD-5 stays
PENDING (G6-OD5).

### 13.11 Review follow-up of 2026-11-23: restore drill evaluation and separate work

**#695 restore drill cleanup** (accepted as the code-review baseline, merge deferred; G6-RV1).
- The drill drops only a database this run proved it created: `createdb` succeeded in this run, and the database
  still has the oid read right after creation.
- A name collision, a failed `createdb`, a changed oid or a failed drop never drops another database, and fails the
  run.
- Validated on a local, isolated PostgreSQL 16 cluster (socket only, synthetic data, removed afterwards; never the
  VPS): 33 of 33 checks pass.
- This validation is test evidence, not a production authorisation (§10 G6-AUTH).
- The previous script fails 17 of the 33 checks. It dropped a colliding database, and dropped the database of a
  concurrent run with the same name. It passed a failed drop with GO evidence.

**TOCTOU between the oid check and `dropdb`: residual risk LOW.**
- PostgreSQL cannot make the check and the drop atomic: `DROP DATABASE` cannot run inside a transaction block or a
  function.
- Swapping the database inside the millisecond window needs two things:
  - dropping the drill database, which the superuser owns;
  - creating a database with the same name.

  The runtime can do neither (verified: `must be owner of database`, `permission denied to create database`), and the
  drill refuses to run when the runtime holds CREATEDB. Only a superuser can do both, and a superuser can drop any
  database directly.
- Concurrent drills with the same name stamp: one proceeds, the other fails on the name without dropping anything.
- Optional hardening, each a separate owner decision:
  - H-1: a host `flock` mutex that serialises drills;
  - H-2: the oid check and `DROP DATABASE` in one psql session;
  - H-3: a random per-run name suffix, subject to the 63-byte limit.

**Separate work item R6-B6-F1 (OPEN, not in #695):** `scripts/restore-rehearsal.sh`.
- Its cleanup runs `dropdb --if-exists … || true`. A failed drop is swallowed, so a rehearsal can pass while its
  scratch database is left behind.
- It drops by name with no oid check.
- Collision and a failed `createdb` are safe there, because its EXIT trap is installed only after `createdb`
  succeeds.
- Proposed fix: the same proof-of-creation and fail-on-drop pattern as the drill, with stub and PostgreSQL 16 tests.
- It waits for the release order and its own owner decision.

### 13.12 Release-1: interim runtime release without a GO tag (owner decision G6-R1, 2026-11-23)

**Decision.**
- The owner approves the first SPN4 deployment as an interim runtime release that creates **no GO tag** (§10 G6-R1).
- The approval is subject to the formal release authorisation of runbook §6g: the owner's written approval of the
  full SHA and the owner's signature.
- This record authorises no dispatch, no signature, no submission and no deploy.

| Item | Record |
|---|---|
| Release ID | `dms-spn4-runtime-r1-20261123`. Confirmed unused before the signature (gate 2) |
| Release type | Interim runtime release of an OPEN sprint. No sprint GO is declared |
| Scope | R6-B1 (#689, `1e985d8e`); REM-2/R2-6 (#690, `295b3d5e`); REM-1 (#696, `d628e0cb`; governance #692, `13cc32e5`); the SPN4 manifest switch (#693, `27d342cb`); and the documentation commits merged since the last production release, this record included |
| Reaches the host inertly | B5 CI-CD-PR-ONLY (#682, #683): CI workflow and its governance test only. B5 was closed without a deploy or a tag (#684). It is not a separate sprint deployment, and this is not a multi-sprint release |
| Schema | None. The active manifest declares `schema_change: false`, and no migration is in the range |
| Excluded | R6-B5 (rule 248 migration; released later with its own `schema_change: true` manifest PR, G6-DQ4b); R6-B2; #695 (R6-B6 and D-Q6-a); R6-B3, R6-B4, R6-B7, R6-B8; R6-C; R6-D; every production role, ownership or privilege change; Gate 1 RME-LAB-FEE-APPROVAL-1; G12, G13; pilot activation; BK-2 |
| GO tag | Not created. `.sprint/current.yml` keeps `go_tag: dms-spn4-ready-pilot-1-go`, which the signing helper and the release guard read as the sprint's declared tag. Naming it there creates nothing (CG-16) |
| Code, manifest, scripts, rules | Unchanged by this record |

**Dependency gates, in order.** Each gate after the first needs its own authorisation.

1. This record is merged.
2. **Final SHA.**
   - Fetch the base. The release candidate is its new tip: the merge of this record, not `330861de`, which must not be
     dispatched.
   - Prove on that SHA that the manifest is unchanged, the range carries no migration, and the scope is as above.
   - Confirm that the release ID is unused.
3. **Base freeze.** From the dispatch until `verify`, nothing merges into the base.
4. **Dispatch.** One `workflow_dispatch` with default inputs while the base tip is the SHA. The run must be
   `completed`/`success` with `headSha` equal to it, and the critical tests must actually run. A DEFERRED Full Suite is
   recorded as DEFERRED.
5. **Owner approval.** The owner's written approval of the full SHA, then the owner's signature with
   `--release-id dms-spn4-runtime-r1-20261123 --go-tag dms-spn4-ready-pilot-1-go` (owner only).
6. **Target and trust.** `production-target-check --operator --remote` → `VERIFIED`; `trust-check` → `READY`.
7. **Approval.** `submit-approval`, then `check-approval` → `GO`.
8. **Deploy.** `start`, `follow`, `verify <deploy-id>` → `DEPLOY RUNNER OK`, outside 02:25–02:40 WITA. The runner's
   pre-deploy dump is mandatory; BK-2 is not a gate.
9. **Post-deploy verification** (read-only):
   - smoke on the canonical URL;
   - no new ERROR lines with query values (REM-2b);
   - the DQ1 check and the merge guard behave as before (R6-B1);
   - no NIK in a session flash (REM-2/R2-6).
10. **Closure record (CG-22).** Release ID, approval ID, record and signature digests, the CI run ID and the deploy ID.
    Only then are R6-B1, REM-2/R2-6 and REM-1 recorded as released. REM-1 is not marked fully released before this.

### 13.13 Release-1 deployment and closure record (2026-11-24) — CLOSED

**Status: CLOSED.** The deploy is verified by its identifier, and the pre-deploy dump is verified. Two gate-9 checks
were not run in production; the owner accepted CI evidence on the release SHA in their place (G6-R1-EXC-MG,
G6-R1-EXC-NIK). Those checks stay recorded as NOT RUN. R6-B1, REM-2/R2-6 and REM-1 are **RELEASED**.
Times are UTC, with WITA (UTC+8) in brackets.

| Item | Record |
|---|---|
| Release ID | `dms-spn4-runtime-r1-20261123` |
| Release SHA | `4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a` (squash merge of #698; tree `5c79e56c890ad86f494553c29181eae7648f1143`) |
| Candidate checks | Base tip = SHA; one parent `330861de`; sprint doc the only change against it; manifest `schema_change: false`, `runtime_change: true`, `ledger_impact: false`, `deploy_required: true`, `go_tag: dms-spn4-ready-pilot-1-go`; no migration, dependency, route, seeder, script or deploy change since the last production release `f31b554b`; no role, privilege or ownership SQL; exactly the runtime files of R6-B1, REM-2/R2-6 and REM-1 |
| Freeze | Held from the dispatch through `verify`: the base tip stayed the SHA; #699 and #695 open and unmerged; the R6-B5 manifest local; no tag |
| CI | `workflow_dispatch` run `45277338107` on the SHA, attempt 1, 2026-11-23 22:12 → 23:24 (06:12 → 07:24, 24 Nov), `completed`/`success`. Critical tests **11930 passed (61900 assertions), 0 failed**. Required jobs (critical gate, quality gate, gate classifier) succeeded and the critical step ran. **Full Suite: DEFERRED** by the active policy (skipped, not authorised); it is not a pass |
| Pre-signature gate | Read-only: target, release guard trust, least-privilege sudo policy, no privilege drift, baseline posture. Verdict PRE-SIGNATURE GO (G6-R1-SIG) |
| Owner approval and signature | Approval ID `817c8eeb2254b26cdc0872196106f950`, contract 3, scope `production-deploy`, approver `owner`, approved 2026-11-24 01:59:24 (09:59:24), valid until 2026-11-26 01:59:24 (09:59:24) |
| Record SHA-256 | `664040822d92db5035c1f80e6a836b76f94616928e51d16f91714957fb4c729f` |
| Signature SHA-256 | `e5260c1d0c28dd52ff406243913174cb96e3b5f56e30eb53f0fc5aa0447e67a8` |
| Signature check | Verified on the workstation with the owner's public key before submission, and by the production guard against its installed trust anchor |
| Submission | 2026-11-24 02:05:35 (10:05:35): `RELEASE APPROVAL SUBMITTED` for the approval ID above |
| Approval check | 2026-11-24 02:05:46 (10:05:46): `RELEASE APPROVAL CHECK: GO` (read-only). Again GO at 02:11 before `start` |
| Deploy | ID **`20261124-021647`**: `start` 02:16:47, runner finished 02:18:41 (10:16:47 → 10:18:41), outside 02:25–02:40 WITA. Guard `RELEASE APPROVAL: GO`; runner `final exit=0` |
| `verify 20261124-021647` | `DEPLOY_APPROVED_TARGET_MATCH=YES (4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a)`, `DEPLOY RUNNER OK`; the run names contract 3, the approval ID and CI run `45277338107` |
| Production HEAD | Before `f31b554be922d1c147d23e7e00523b2d23e4a413`, after `4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a`; no tracked file modified |
| In-deploy gates | Runtime isolation 107 GO / 0 FAIL; role landing smoke GO; privilege separation enforced; `data-quality:dq1-audit --fail-on=error` passed after the checkout |
| Deploy-time warning | `FLAG-RISKY-NOT-ENABLED` (WATCH): the same pre-existing warning as the Program 8C and 8D deploys; this release changes no flag configuration |

**Post-deploy verification (gate 9), 2026-11-24 02:19 (10:19), read-only.**

| Check | Result | Evidence |
|---|---|---|
| Canonical URL smoke | **PASS** | `/login`, `/up`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/…` → 403; an unknown path → 404; `/rme/visits` and `/settings/patients` → 302 to login |
| Application ERROR lines | **PASS** | 166 before and 166 after the deploy (+0), so no new ERROR line with query values (REM-2b) |
| Privilege drift, sudo policy | **PASS** | `PRIVSEP_DRIFT=NONE`; `SUDO_POLICY=LEAST_PRIVILEGE`, 0 violations |
| Units and timers | **PASS** | Unchanged from before the deploy: queue worker, KTP prune timer and pilot snapshot timer stay inactive as the owner set them; the backup timers stay active |
| DQ1 (R6-B1) | **PASS** | `data-quality:dq1-audit --fail-on=error` ran inside the deploy on the new code; the deploy is fail-closed (`set -euo pipefail`) and verified. CI: `Dq1ForeignKeyCatalogRoleSeparationTest` 5/5 |
| Merge guard (R6-B1) | **MERGE GUARD PRODUCTION TEST: NOT RUN.** CI SUBSTITUTE EVIDENCE: PASS (owner exception G6-R1-EXC-MG) | Evaluated only inside a patient-merge case; creating one writes production data. CI on the SHA: `PatientMergeForeignKeyCatalogRoleSeparationTest` 7/7. Production code identity: HEAD = SHA, no tracked change |
| NIK never flashed (REM-2/R2-6) | **NIK SESSION FLASH PRODUCTION TEST: NOT RUN.** CI SUBSTITUTE EVIDENCE: PASS (owner exception G6-R1-EXC-NIK) | Needs an authenticated submission (session and audit writes). CI on the SHA: `PatientKtpNeverFlashedTest` 7/7, `RmeVisitConsentGuardianIdentityNeverFlashedTest` 3/3, `RmeRefundRequestNikNeverFlashedTest` 3/3, `PatientKtpDatabaseErrorLogRedactionTest` 10/10 |
| Pre-deploy dump | **VERIFIED** | At deploy time, by the fail-closed order: dump → non-empty → the runtime copy's digest equals root's → only then the checkout. Read-only by the owner on 2026-11-24 02:50:36 (10:50:36): the digest in the deploy log, `e6424471c51b705b1b6f28bbd84cc775c8dc5f3b7cda1e158c200cc5c6ef9f7e`, equals the file's; the file is present, 34783021 bytes, mode 640, owned by the runtime identity, modified 02:16:54, between `start` and the checkout. The dump was not read and nothing was restored |

**Not done in release-1:** GO tag; any migration (the range has none); any role, ownership or privilege change; merge of
#699, #695 or the R6-B5 manifest; R6-D; G12; G13; pilot activation; LAB-fee; rollback.

**Residual risk and pilot blockers (unchanged, not accepted by this release):** R6-01 REMEDIATION OPEN; R6-D NOT
AUTHORIZED; Full Suite DEFERRED; CG-11 OPEN; D-2 BLOCKED; OD-5 PENDING; G12 and G13 not authorised; Gate 1 LAB-fee
NO-GO; **pilot NO-GO**.

**Gate reconciliation (§13.12).**

| Gate | Result |
|---|---|
| 1 Record merged | PASS: #698 |
| 2 Final SHA | PASS: the candidate checks above; the release ID was unused before the signature |
| 3 Base freeze | PASS: held from the dispatch through `verify` |
| 4 Dispatch | PASS: run `45277338107`, critical tests 11930 passed, 0 failed. The Full Suite is DEFERRED, not passed |
| 5 Owner approval and signature | PASS: G6-R1-SIG; approval `817c8eeb2254b26cdc0872196106f950` |
| 6 Target and trust | PASS: production target verified for both release identities; release guard trust READY |
| 7 Approval | PASS: submitted, then `RELEASE APPROVAL CHECK: GO` |
| 8 Deploy | PASS: `20261124-021647`, `DEPLOY RUNNER OK`; pre-deploy dump VERIFIED |
| 9 Post-deploy verification | **MET UNDER OWNER EXCEPTIONS.** Smoke, ERROR lines and DQ1 PASS. Merge guard and NIK flash NOT RUN in production; CI substitute evidence PASS (G6-R1-EXC-MG, G6-R1-EXC-NIK) |
| 10 Closure record | This section |

**Closure (2026-11-24).**
- R6-B1 (#689), REM-2/R2-6 (#690) and REM-1 (#696) are **RELEASED** in release-1.
- Release-1 `dms-spn4-runtime-r1-20261123` is **CLOSED**. No GO tag is created. The manifest keeps
  `go_tag: dms-spn4-ready-pilot-1-go` for the final closure of the sprint.
- The SPN4 sprint stays **OPEN**, the Full Suite stays **DEFERRED**, and the pilot stays **NO-GO**.
- The release freeze ends when this record is merged (owner decision).
- The next merges follow §13.12, each with its own authorisation: the `schema_change: true` manifest PR, then R6-B5,
  then #695, then #699.

### 13.14 Release-2 (R6-B5): interim schema-changing release — DRAFT record (CG-22, gate R2-1)

**Status: DRAFT. MERGED, NOT DEPLOYED. DEPLOYMENT ON HOLD.** This was the status when the section was written and
reviewed (gate R2-1). It is kept as written. **Superseded on 2026-11-25 by §13.15: deployed, verified and closed.**
- This record authorises nothing: no dispatch, signature, submission, deploy, production query or migration.
- Each gate below needs its own owner decision.
- Release-2 is not recorded as deployed, verified, closed or GO.

| Item | Record |
|---|---|
| Release ID | **`dms-spn4-r6b5-r2-20261124`** (owner decision G6-R2-ID, 2026-11-24). Not used in the repository, tags or GitHub releases when it was set; the host approval store is checked at submission |
| Release type | Interim schema-changing runtime release of the OPEN sprint. No sprint GO; no GO tag |
| Candidate SHA | **PENDING FINAL DOCS MERGE**: the base tip once this record is merged |
| Production baseline | Release-1, CLOSED (§13.13): `4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a`, deploy `20261124-021647` verified |
| Manifest | `.sprint/current.yml` from #701: `schema_change: true`, `ledger_impact: true`, `go_tag: dms-spn4-ready-pilot-1-go` |

**Scope: everything after the production baseline, none of it live yet.**

| PR | Change | Runtime effect |
|---|---|---|
| #700 (`3d29de27`) | Release-1 closure record (this document) | None |
| #701 (`03fc5ae9`) | Manifest: `schema_change` and `ledger_impact` set to `true` (owner decision G6-DQ4b) | None; DEVFLOW consistency only |
| #702 (`24566919`) | R6-B5, rule 248 layer 1 (owner decision G6-DQ4a), 27 files | **Yes**: one additive migration, three model guards, test fixtures, CI selection, rules |
| This record | CG-22 record of release-2 | None |

Range totals from `4eec017c` to `24566919`: 29 files.
- No dependency, script, deploy, route, workflow, bootstrap, public or resource change.
- No role, privilege or ownership SQL.

What #702 does:
- **Migration `2026_11_23_100001_enforce_rule_248_append_only_layer_one`**, PostgreSQL only, no row changed:
  - **Append-only guard.** `dms_append_only_guard` refuses UPDATE, DELETE and TRUNCATE on seven tables:
    - `sys_audit_logs`, `trx_lab_order_status_logs`;
    - `trx_rme_billing_dispute_events`, `trx_rme_invoice_correction_events`;
    - `trx_rme_overpayment_obligation_events`, `trx_rme_refund_request_events`, `trx_rme_refund_settlement_events`.
  - **Ledger guard.** `dms_inventory_ledger_guard` on `trx_inventory_movements` allows only the first batch link
    (NULL → a batch of the same product and branch) and `updated_at`. It refuses DELETE and TRUNCATE.
  - **Foreign keys.** `sys_audit_logs.performed_by` (was SET NULL) and `trx_lab_order_status_logs.lab_order_id` (was
    CASCADE) become **RESTRICT**.
  - Two functions with a pinned `search_path`, not SECURITY DEFINER. 16 triggers.
- **Models:** `AuditLog` and `LabOrderStatusLog` refuse update and delete. `InventoryMovement` refuses delete and any
  change beyond the first batch link.
- **CI:** the new mandatory suite `FoundationGovernanceAuditAppendOnlyTest` is selected in both critical variants
  (CG-19).
- **Fixtures:** 18 existing test files are adapted, with no assertion weakened:
  - 16 clean up through `Tests\Support\Database\AppendOnlyOwnerWrite`;
  - 2 write the row in its final shape when they create it.
- **Rules:** rule 248 "current state" and inventory AIR-INV-23.

**Why the manifest flags are true:**
- `schema_change: true`: the release carries one additive migration.
- `ledger_impact: true`: the migration guards the inventory ledger (CG-10). Stock stays derived from movements only.

**CI evidence (pull requests; the release CI is the later dispatch, gate R2-6).**

| PR | Run | Result |
|---|---|---|
| #700 | `45316413887` | success, `docs_only` (critical skipped by design) |
| #701 | `45319390161` | success; critical **11930 passed** |
| #702 head `abe1896c` | `45336521129` | **failure**: 2 tests of `RmeClinicalEvidenceWriteLockConcurrencyTest`. A writer-connection cleanup deleted a user still referenced by `sys_audit_logs` (RESTRICT, 23503). Test fixture only |
| #702 head `66da4994` (fixture fix) | `45352209945` | **success**; critical **11951 passed (62066 assertions), 0 failed** |

Further detail of run `45352209945`:
- Selective module gate: Inventory 728 passed, 1 skipped; Lab 1609 passed.
- Governance audit: no error. One baseline WATCH, `RELEASE-SAFETY-EVIDENCE-CHAIN` for the "local" profile, also present
  in the release-1 dispatch run.
- **Full Suite: DEFERRED** by the active policy (CG-18). It is not a pass.

**Local PostgreSQL 16 evidence** (private, socket-only clusters; synthetic data; removed after use):

| Check | Result |
|---|---|
| R6-B5 suite | 21/21 |
| Migration harness (1,000,000 audit rows, 200,000 status logs, 50,000 movements) | 16/16 (detail below) |
| FK swap lock probes | `NOT VALID` swap 0.7 ms, then `VALIDATE` 109 ms under SHARE UPDATE EXCLUSIVE: 4/4 probes (SELECT and INSERT on `sys_audit_logs` and `users`, 500 ms) not blocked. The single-transaction variant blocked 4/4 |
| Regression over every class touching the guarded tables or models | 7290 passed, 0 failed |
| Every test file that writes over a separate committed connection | 31 files, 389 passed, 0 failed |
| Old base code against a database with the R6-B5 triggers | 0 refusals from an application path (detail below) |
| Recovery matrix and R2-3 pack, as a non-superuser role that owns the tables | First pack: 15/15. Hardened pack (Annex B): **25/25** (detail below) |

Migration harness:
- **Fail-fast:** under a 20 s reader of `users`, the run fails after 5.26 s. It is not recorded and changes no row.
- **Retry:** succeeds in 0.34 s.
- **Data:** `up()` and `down()` change no row (count and md5 over 8 tables). A repeated `up()` is a no-op.
- **Guards:** they refuse with SQLSTATE P0001; the two allowed ledger changes succeed.
- **Foreign keys:** RESTRICT is enforced (23503).
- **Privilege:** no GRANT, role, ownership or trigger-disable statement.

Old base code against a database with the triggers:
- **144** refusals come from old test fixtures. None passes through an `app/` frame.
- **55** were an artefact of the local tree export: `/.github` is `export-ignore`. They pass once it is restored.
- **2** depend on an unmigrated database. They pass on the migrated one.

Recovery matrix and R2-3 pack: run as a non-superuser role that owns every table, as production does while R6-01 is
open. That role migrates R6-B5. The pack prints no role name and carries no write or DDL statement. Each partial
state F0–F6 is recognised, and its retry (or no-op) ends at the released state.

Hardening of the pack (before merge): every catalog check is limited to this database and to `current_schema()`. Each
of the sixteen guards is checked individually (R2Q10t), and the expected distribution is counted per table. The
hardened pack was tested against the first pack:

| Test | First pack | Hardened pack |
|---|---|---|
| Same-named guards and functions in another schema; target not yet released | False PASS (16 guards counted) | Exact pre-release state |
| Same-named keys and migration row in another schema too | Stopped with an error | Exact pre-release state |
| Target released, other schema still present | — | Exact released state |
| One guard missing and one extra elsewhere, total still 16 | False PASS (16) | 7 tables, 15 guards, 1 unexpected |
| A guard calling the other guard function | False PASS (16) | Caught (15 guards) |
| A disabled guard | — | Caught (15 guards) |
| An AFTER trigger in place of BEFORE | False PASS (16) | Caught (15 guards) |
| A guard calling a same-named function of another schema | False PASS (16) | Caught (15 guards) |
| A lock on a same-named table in another schema or database | Counted | Not counted; only the target lock shows |

**Risks.**

| Risk | Assessment | Mitigation |
|---|---|---|
| Lock contention during `migrate --force` (no maintenance mode) | Each step waits at most 5 s for its lock, then fails | Deploy when R2-3 shows no long transaction; a failure is fail-fast and leaves a safe partial state (below) |
| `VALIDATE` duration on production volumes | Production sizes not yet measured | R2-3 measures them; VALIDATE does not block DML |
| Foreign keys now RESTRICT | Hard delete of a user with audit rows, or of a lab order with status logs, is refused | The application deletes neither. `User` and `LabOrder` use soft deletes; maintenance scripts must account for it |
| Ledger guard | A hard delete of a user, batch or supplier referenced by movements is refused, through SET NULL | No application path does it: suppliers are deactivated, and batches have no delete path |
| Code rollback leaves the schema | Triggers and keys stay | Old code is compatible (0 application-path refusals) |

**Backup and restore escalation.**
- The deploy's own pre-deploy dump is mandatory and fail-closed: dump → non-empty → digest match → only then the
  checkout.
- After the deploy, the owner records its digest and metadata read-only, as in §13.13.
- BK-2 is not a gate.
- R6-B5 changes no row, and none of the tested failure states F0–F6 below needs a data restore.
- That does not rule one out: an unforeseen state could still call for a restore.
- Any data restore is a separate, explicitly approved owner operation: never automatic, never over newer clinical data.

**Partial migration recovery (gate R2-5).**

`up()` runs these steps, each in its own transaction under `lock_timeout 5s`:
1. S1: functions and 16 guards.
2. S2: swap of the audit FK `NOT VALID`.
3. S3: `VALIDATE` of the audit FK.
4. S4: swap of the status-log FK `NOT VALID`.
5. S5: `VALIDATE` of the status-log FK.
6. Laravel records the migration.

The deploy script runs migrate **after** the backup, checkout, composer and npm build, and **before** the cache
rebuild and the service restart. A failed migrate aborts the deploy with the new code on disk and the services not
restarted.

State signature: `guard_triggers_ok` (R2Q10, second field) | audit FK `on_delete/validated` | status-log FK |
migration recorded. All four come from `current_schema()` only. A state with 16 guards also shows
`guard_tables_ok = 8` and `unexpected_guard_triggers = 0`.

| State | Failed at | Signature | Code compatibility |
|---|---|---|---|
| F0 | S1 | `0` \| `n/true` \| `c/true` \| `0` | Unchanged schema: old and new code fine |
| F1 | S2 | `16` \| `n/true` \| `c/true` \| `0` | Old and new code fine (guards only) |
| F2 | S3 | `16` \| `r/false` \| `c/true` \| `0` | Fine: `NOT VALID` RESTRICT is enforced for new operations; old rows were valid under the old key |
| F3 | S4 | `16` \| `r/true` \| `c/true` \| `0` | Fine |
| F4 | S5 | `16` \| `r/true` \| `r/false` \| `0` | Fine |
| F5 | recording | `16` \| `r/true` \| `r/true` \| `0` | Fine |
| F6 | a later deploy step | `16` \| `r/true` \| `r/true` \| `1` | Fine; the deploy is still NOT GO |

What to do in any failed state:
1. **Read the state** with R2-3 (R2Q4, R2Q5, R2Q6, R2Q10t, R2Q10), read-only.
   - If it does not match one row above exactly, **STOP and escalate**. Retry nothing and roll back nothing.
2. **Retry is never automatic.** It needs a separate owner decision, based on the evidence of the failed run, and
   only when all of the following hold:
   - `verify <deploy-id>` reports the failure;
   - the release guard still answers `check-approval` → `GO`. A failed run spends nothing, so the approval can be
     retried inside its window;
   - the base has not moved past the candidate;
   - R2-3 shows the lock condition cleared.

   Then run `start` again. `up()` resumes: it replaces the guards, skips a key already RESTRICT and re-validates.
   Locally every state F0–F5 converged. **Idempotence alone is not a reason to retry.**
3. **Code rollback** is `rollback-vps.sh` to `4eec017c`: the administrator's break-glass step, on an owner decision.
   - It moves code only, and the schema state stays.
   - The old code is compatible with every state above.
4. **Schema rollback** (`down()`, through `migrate:rollback`) is **not authorised in production** without an explicit
   owner decision. `migrate:reset`, `migrate:fresh` and destructive SQL stay forbidden (CG-09).
   - `down()` restores SET NULL and CASCADE and drops the guards without changing a row. That was tested locally only.
5. **A new approval** is needed after any **completed** deploy (the release is spent), for example a re-deploy after a
   code rollback. It goes under a new release ID.

**Gate R2-3: read-only production readiness (owner-run, separate authorisation).**
- The pack is in Annex B. It runs through `php artisan db` as the application login role, in one `BEGIN READ ONLY` …
  `ROLLBACK`, with short timeouts. It prints catalog facts, estimates, aggregates and booleans only.
- **Required before the release:**
  - R2Q2 `can_create_in_schema = t`;
  - R2Q3 all ten tables `owned_by_login_role = t`. While R6-01 is open the runtime role owns the tables; without both,
    S1 fails (F0);
  - R2Q4 pre-release keys `n/t` and `c/t`;
  - R2Q5 no guard and no function;
  - R2Q6 `r6b5_recorded = 0` with `migrations_in_schema = t`;
  - R2Q10 `0|0|0|0|n/true|c/true|0|t`.
- **Recorded for the decision:**
  - R2Q1 timeouts;
  - R2Q3 sizes;
  - R2Q7–R2Q9 sessions, long transactions, locks and blocked sessions. If `sees_all_sessions = f`, R2Q7 is partial.
- The deploy time is chosen when no long transaction or blocking is seen, outside 02:25–02:40 WITA.

**Post-deploy verification (gate R2-8).**
- `verify <deploy-id>` → `DEPLOY_APPROVED_TARGET_MATCH=YES` and `DEPLOY RUNNER OK`.
- R2Q10 → `8|16|0|2|r/true|r/true|1|t`, and all sixteen R2Q10t rows `t|t|t|t`. In `current_schema()`:
  - each of the eight tables has both of its guards;
  - each guard calls the expected function of that schema, is enabled and has the expected type;
  - no other trigger calls a guard function;
  - both functions are plpgsql, not SECURITY DEFINER, with `search_path` pinned;
  - both keys are RESTRICT and validated, with both ends in that schema;
  - the migration is recorded.
- `drift-check` → NONE; `sudo-policy-check` → LEAST_PRIVILEGE.
- `posture` → HEAD = candidate, ERROR lines against the pre-deploy baseline.
- Smoke on the canonical URL.
- Owner read-only dump digest and metadata.
- Then the closure record.

**Remaining gates, in order, each with its own owner decision:**
1. R2-1: this record. The release ID is set (G6-R2-ID) and publication for review is approved. The text approval and
   the merge are still owner decisions.
2. R2-2: exact-SHA and scope audit of the merged candidate.
3. R2-3: read-only production pack.
4. R2-6: one `workflow_dispatch` on the candidate. The base freezes from the dispatch until `verify`.
5. R2-7: the owner's written approval and signature (`--go-tag dms-spn4-ready-pilot-1-go` binds the manifest and
   creates no tag), then `submit-approval` and `check-approval`, then the deploy authorisation.
6. R2-8: post-deploy verification and the closure record.

**Boundaries (unchanged):**
- No GO tag.
- R6-01 OPEN. R6-D NOT AUTHORIZED. CG-11 OPEN. D-2 BLOCKED. OD-5 PENDING.
- G12 and G13 NO-GO. Gate 1 LAB-fee NO-GO and LAB-fee disabled. Pilot NO-GO.
- Full Suite DEFERRED. #695 and #699 stay open and outside release-2.
- **No deploy from the base** until release-2 is authorised.

### 13.15 Release-2 deployment and closure record (2026-11-25) — CLOSED

**Status: CLOSED.**
- Release-2 `dms-spn4-r6b5-r2-20261124` (R6-B5) is deployed and verified.
- This closes release-2 only. **It is not the sprint GO decision.** The SPN4 sprint stays **OPEN**, and **no GO tag is
  created**.
- Times are UTC, with WITA (UTC+8) in brackets.

| Item | Record |
|---|---|
| Release ID | `dms-spn4-r6b5-r2-20261124` |
| **Deployed runtime SHA** | **`7f58a3afe11ac6beeaff26b0028646831f4b9793`** (tree `e7203723b608067e95d0d9f70da6fee7544b27a1`): the squash merge of #703. The commit that adds this closure record is documentation only, is not deployed, and does not change the runtime |
| Production before / after | `4eec017c7cbbc74a30e29fa0bf1536ee9f7a795a` (release-1) → `7f58a3afe11ac6beeaff26b0028646831f4b9793` |
| Scope | #700, #701, #702 and #703 (29 files; one additive migration, `2026_11_23_100001_enforce_rule_248_append_only_layer_one`) |
| CI | `workflow_dispatch` run `45415743102` on the exact SHA, attempt 1, `completed`/`success`. Critical tests **11951 passed (62066 assertions), 0 failed**. Selective module gate (Inventory, Lab, UI/UIX) passed. NSF-9, NSF-10, Pint and Android succeeded. **Full Suite: DEFERRED** (skipped, not authorised); not a pass |
| Governance | 39 GO, 1 WATCH (`RELEASE-SAFETY-EVIDENCE-CHAIN`, "local" profile, optional evidence; the same baseline WATCH as earlier runs), 0 error |
| Approval | Contract 3, ID `619c2992064faf571cff4a9e19046950`. Approved by the owner 2026-11-24 16:22:12 (2026-11-25 00:22:12); valid until 2026-11-26 16:22:12 (2026-11-27 00:22:12). Spent by this deploy |
| Record / signature SHA-256 | `f549b47ac51c39e235b12d57348c3e04e6883ee36d42e5c4748ddbc9e3d80b2f` / `733d1c2cda6b14bc6f19fbcc09187052922d10ffaad7c71d108651ac2862ccba` |
| Submission and check | Submitted 2026-11-24 16:28:33 (00:28:33); `check-approval` → `GO` at 16:28:46, and again before `start` at 16:41 |
| Deploy | ID **`20261124-164148`**: `start` 16:41:48, runner finished 16:43:24 (2026-11-25 00:41:48 → 00:43:24), outside 02:25–02:40 WITA. One start; no retry |
| `verify 20261124-164148` | `DEPLOY_APPROVED_TARGET_MATCH=YES (7f58a3afe11ac6beeaff26b0028646831f4b9793)`, `DEPLOY RUNNER OK`, `final exit=0` |

**Gates.**

| Gate | Result | Evidence |
|---|---|---|
| R2-1 | PASS | The record (§13.14, Annex B), merged in #703 |
| R2-2 | PASS | Exact-SHA audit, 48/48. Scope = #700–#703; the code tested by #702 CI equals the candidate's |
| R2-3 | PASS | Owner-run Annex B pack before the release, run twice (the second shortly before `start`). See below |
| R2-4 | PASS | Pre-deploy dump verified by digest. See below |
| R2-5 | Not exercised | The procedure stood ready; no failure occurred, so no partial state F0–F6 arose |
| R2-6 | PASS | CI `45415743102` |
| R2-7 | PASS | Contract 3 approval, signed by the owner, submitted, `check-approval` GO |
| R2-8 | PASS | Post-deploy verification. See below |

**R2-3: production readiness, read-only, owner-run.**
- Both runs had full session visibility (`sees_all_sessions = t`) and every hard check met.
- R2Q10 was `0|0|0|0|n/true|c/true|0|t`: no guard, both keys in their old form, R6-B5 not recorded.
- The login role owned all ten tables and could create in its schema.
- 237 migrations were recorded, the same as the files of release-1.
- Tables were small: about 1,670 audit rows, 56 movements, the rest empty or near empty.
- R2Q7–R2Q9 showed no other session, no lock on the ten tables and no blocked session (2026-11-24 16:34, about seven
  minutes before `start`).

**R2-8: post-deploy verification, 2026-11-24 16:43–16:49 UTC.**

| Check | Result |
|---|---|
| Production HEAD / tracked files | `7f58a3afe11ac6beeaff26b0028646831f4b9793` / `TRACKED_MODIFIED=0` |
| Canonical URL smoke | **PASS**: `/login`, `/up`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/…` → 403; an unknown path → 404; `/rme/visits` and `/settings/patients` → 302 to login |
| Application ERROR lines | 166 before and 166 after (+0) |
| Privilege drift / sudo policy | `PRIVSEP_DRIFT=NONE` / `SUDO_POLICY=LEAST_PRIVILEGE`, 0 violations |
| Units and timers | Unchanged: queue worker, KTP prune and pilot snapshot stay inactive as the owner set them; the backup timers stay active |
| Database (owner-run Annex B POST, read-only) | **`R2Q10|8|16|0|2|r/true|r/true|1|t`**, with all sixteen R2Q10t rows `t|t|t|t` |
| Migration | R6-B5 recorded (batch 96); 238 migrations in total |

The database row means:
- 8 guarded tables;
- 16 guards, each enabled, calling its expected function in the same schema and of the expected type;
- no unexpected guard trigger;
- 2 guard functions: plpgsql, not SECURITY DEFINER, `search_path` pinned;
- both keys RESTRICT and validated, with both ends in the same schema.

**R2-4: pre-deploy dump, verified by digest.**
- The owner ran a read-only check at 2026-11-24 16:49:07 UTC.
- The digest in the deploy log equals the file's: `5a1770ecbbb92aea9a119f149a1fb52c8d53bb11204de1f514d188fe7cdb645c`
  (`DIGEST_MATCH=YES`).
- The file is present: 34,851,458 bytes, mode 640, owned by the runtime identity, modified 2026-11-24 16:41:55, after
  `start` and before the checkout.
- To compute the SHA-256, the owner's check read the file's bytes. Its clinical content was neither displayed nor
  inspected.
- Nothing was restored. **No restore test was performed, and none is claimed.**

**Not done in release-2:**
- no GO tag; no second deploy; no other migration; no rollback or restore;
- no role, ownership or privilege change (R6-D);
- no merge of #695 or #699; no G12, G13, pilot or LAB-fee activation.

**Residual risk and status (unchanged by release-2):**
- R6-01 OPEN; R6-D NOT AUTHORIZED; CG-11 OPEN; D-2 BLOCKED; OD-5 PENDING.
- G12 NO-GO; G13 NO-GO; Gate 1 LAB-fee NO-GO; **pilot NO-GO**.
- Full Suite DEFERRED; the governance WATCH stays recorded.
- New in production:
  - the append-only and ledger guards refuse UPDATE, DELETE and TRUNCATE on the eight tables;
  - a hard delete of a user, lab order, batch or supplier that has history is now refused. The application performs
    none.
- A code rollback would leave the guards and keys in place, by design; the release-1 code is compatible with them.

**Evidence outside this repository.** The owner's local evidence packs 42 to 45 hold:
- the R2-2 verifier output;
- the R2-3 runs and their evaluations;
- the signature and submission records;
- the deploy, follow, verify and post-deploy outputs;
- the CI logs.

These packs stay restricted to the owner's workstation, and their full contents are not audited by this record.
This closure record itself contains no credential, private key, raw dump or clinical data.

**After this record:**
- Merging it ends the release-2 base freeze (owner decision).
- The remaining SPN4 work follows its own decisions: #695, #699, R6-B2, R6-B3/B4/B7/B8, R6-C, R6-D, and the
  conditions of G12, G13 and the pilot.

---

## Annex A — Supplementary query pack R1-S (read-only; owner-run as the application login role)

Run with `php artisan db` as the application login role. Follow the owner's read-only psql protocol: `\pset` on its own
line, then check `BEGIN READ ONLY` / `ROLLBACK`.

**R1-S3 is authoritative.** It emits `finding` rows (class codes), `check` rows (C1–C4) and one `verdict` row
(CRITICAL, HIGH, PASS or BLOCKED). R1-S1, R1-S2, R1-S4 and R1-S5 are evidence and cross-checks. A cross-check that shows
a capability R1-S3 did not report makes the result BLOCKED.

```sql
BEGIN READ ONLY;

-- R1-S1 session identity and effective superuser status
SELECT current_user, session_user, current_setting('is_superuser') AS is_superuser,
       current_setting('server_version') AS pg;

-- R1-S2 login-role attributes
SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb, rolreplication, rolinherit, rolcanlogin
FROM pg_roles WHERE rolname = current_user;

-- R1-S3 effective-capability report (authoritative). No depth limit; fail-closed consistency checks.
WITH RECURSIVE
me AS (SELECT oid AS id FROM pg_roles WHERE rolname = current_user),
e AS (SELECT member, roleid, inherit_option, set_option, admin_option FROM pg_auth_members),
-- transitive closures over every role; a path never repeats a role (PostgreSQL forbids circular membership,
-- and the guard keeps the recursion finite even on an inconsistent catalog)
memc(a, b, path) AS (
  SELECT member, roleid, ARRAY[member, roleid] FROM e
  UNION ALL
  SELECT c.a, e.roleid, c.path || e.roleid FROM memc c JOIN e ON e.member = c.b
  WHERE NOT e.roleid = ANY (c.path)
),
setc(a, b, path) AS (
  SELECT member, roleid, ARRAY[member, roleid] FROM e WHERE set_option
  UNION ALL
  SELECT c.a, e.roleid, c.path || e.roleid FROM setc c JOIN e ON e.member = c.b
  WHERE e.set_option AND NOT e.roleid = ANY (c.path)
),
inhc(a, b, path) AS (
  SELECT member, roleid, ARRAY[member, roleid] FROM e WHERE inherit_option
  UNION ALL
  SELECT c.a, e.roleid, c.path || e.roleid FROM inhc c JOIN e ON e.member = c.b
  WHERE e.inherit_option AND NOT e.roleid = ANY (c.path)
),
-- effective ADMIN: held by the actor itself, or by a role the actor inherits privileges from
adm(x, y) AS (
  SELECT member, roleid FROM e WHERE admin_option
  UNION
  SELECT c.a, e.roleid FROM inhc c JOIN e ON e.member = c.b WHERE e.admin_option
),
-- actors: every role whose identity the login role can assume. Itself; every role it can SET ROLE to; and every
-- non-SUPERUSER role it can grant to itself WITH SET through an effective ADMIN (plus that role's SET reach),
-- repeated to a fixed point. PostgreSQL 16 lets only superusers grant SUPERUSER roles.
actors(id, via) AS (
  SELECT id, 'self'::text FROM me
  UNION
  SELECT s.b, 'set_role' FROM setc s JOIN me ON s.a = me.id
  UNION
  SELECT n.id, 'admin_self_grant'
  FROM actors a
  JOIN adm ON adm.x = a.id
  JOIN pg_roles t ON t.oid = adm.y AND NOT t.rolsuper
  CROSS JOIN LATERAL (SELECT adm.y AS id UNION SELECT s.b FROM setc s WHERE s.a = adm.y) n
),
act AS (SELECT id, string_agg(DISTINCT via, '+' ORDER BY via) AS via FROM actors GROUP BY id),
dangerous(rn, cls) AS (VALUES
  ('pg_execute_server_program', 'E3'), ('pg_write_server_files', 'E3'), ('pg_read_server_files', 'H3'),
  ('pg_read_all_data', 'H4'), ('pg_write_all_data', 'H4'), ('pg_signal_backend', 'H4'),
  ('pg_checkpoint', 'H4'), ('pg_create_subscription', 'H4')),
funcs(fn, cls) AS (VALUES
  ('lo_export(oid,text)', 'E3'), ('pg_read_file(text)', 'H3'), ('pg_read_file(text,bigint,bigint,boolean)', 'H3'),
  ('pg_read_binary_file(text)', 'H3'), ('pg_ls_dir(text)', 'H3'), ('pg_stat_file(text)', 'H3'),
  ('lo_import(text)', 'H3')),
findings(code, detail) AS (
  SELECT 'E1', 'session is_superuser = on' WHERE current_setting('is_superuser') = 'on'
  UNION ALL
  SELECT CASE WHEN r.oid = (SELECT id FROM me) THEN 'E1' ELSE 'E2' END,
         r.rolname || ' (' || a.via || ') has SUPERUSER or BYPASSRLS'
  FROM act a JOIN pg_roles r ON r.oid = a.id WHERE r.rolsuper OR r.rolbypassrls
  UNION ALL
  SELECT CASE WHEN r.oid = (SELECT id FROM me) THEN 'H1' ELSE 'H2' END,
         r.rolname || ' (' || a.via || ') has CREATEROLE, CREATEDB or REPLICATION'
  FROM act a JOIN pg_roles r ON r.oid = a.id WHERE r.rolcreaterole OR r.rolcreatedb OR r.rolreplication
  UNION ALL
  SELECT d.cls, r.rolname || ' (' || a.via || ') has the privileges of ' || d.rn
  FROM act a JOIN pg_roles r ON r.oid = a.id JOIN dangerous d ON pg_has_role(a.id, d.rn, 'USAGE')
  UNION ALL
  SELECT f.cls, r.rolname || ' (' || a.via || ') can EXECUTE ' || f.fn
  FROM act a JOIN pg_roles r ON r.oid = a.id JOIN funcs f ON has_function_privilege(a.id, f.fn, 'EXECUTE')
  UNION ALL
  SELECT 'H5', ra.rolname || ' (' || a.via || ') holds an effective ADMIN OPTION on ' || rt.rolname
  FROM act a JOIN adm ON adm.x = a.id JOIN pg_roles ra ON ra.oid = a.id JOIN pg_roles rt ON rt.oid = adm.y
  UNION ALL
  SELECT DISTINCT 'H6', 'member of ' || r.rolname || ' (SUPERUSER or BYPASSRLS) without a usable SET or ADMIN path'
  FROM memc c JOIN me ON c.a = me.id JOIN pg_roles r ON r.oid = c.b
  WHERE (r.rolsuper OR r.rolbypassrls) AND NOT EXISTS (SELECT 1 FROM act WHERE act.id = c.b)
),
-- fail-closed: the recursive closures must equal PostgreSQL's own role resolution, or the verdict is BLOCKED.
-- For a SUPERUSER login role pg_has_role is true for every role, so C1-C3 do not apply; E1 already makes it CRITICAL.
su AS (SELECT r.rolsuper AS is_su FROM pg_roles r JOIN me ON r.oid = me.id),
checks(name, ok) AS (
  SELECT 'C1 member reach = pg_has_role MEMBER', (SELECT is_su FROM su) OR
         ARRAY(SELECT DISTINCT c.b FROM memc c JOIN me ON c.a = me.id ORDER BY 1)
         = ARRAY(SELECT r.oid FROM pg_roles r, me
                 WHERE r.oid <> me.id AND pg_has_role(me.id, r.oid, 'MEMBER') ORDER BY 1)
  UNION ALL
  SELECT 'C2 SET reach = pg_has_role SET', (SELECT is_su FROM su) OR
         ARRAY(SELECT DISTINCT c.b FROM setc c JOIN me ON c.a = me.id ORDER BY 1)
         = ARRAY(SELECT r.oid FROM pg_roles r, me
                 WHERE r.oid <> me.id AND pg_has_role(me.id, r.oid, 'SET') ORDER BY 1)
  UNION ALL
  SELECT 'C3 inherit reach = pg_has_role USAGE', (SELECT is_su FROM su) OR
         ARRAY(SELECT DISTINCT c.b FROM inhc c JOIN me ON c.a = me.id ORDER BY 1)
         = ARRAY(SELECT r.oid FROM pg_roles r, me
                 WHERE r.oid <> me.id AND pg_has_role(me.id, r.oid, 'USAGE') ORDER BY 1)
  UNION ALL
  SELECT 'C4 login role resolved', EXISTS (SELECT 1 FROM me)
)
SELECT 'finding' AS section, code AS result, detail FROM findings
UNION ALL
SELECT 'check', CASE WHEN ok THEN 'ok' ELSE 'FAILED' END, name FROM checks
UNION ALL
SELECT 'verdict',
       CASE WHEN EXISTS (SELECT 1 FROM checks WHERE ok IS NOT TRUE) THEN 'BLOCKED'
            WHEN EXISTS (SELECT 1 FROM findings WHERE code LIKE 'E%') THEN 'CRITICAL'
            WHEN EXISTS (SELECT 1 FROM findings WHERE code LIKE 'H%') THEN 'HIGH'
            ELSE 'PASS' END,
       'actors=' || (SELECT count(*) FROM act)
       || ' reachable=' || (SELECT count(DISTINCT c.b) FROM memc c JOIN me ON c.a = me.id)
       || ' max_depth=' || coalesce((SELECT max(cardinality(c.path)) - 1 FROM memc c JOIN me ON c.a = me.id), 0)
ORDER BY 1 DESC, 2, 3;

-- R1-S4 cross-check: PostgreSQL's own resolution FROM THE LOGIN ROLE towards sensitive roles. Not authoritative: it
-- does not see SET-then-INHERIT paths or self-grants through ADMIN OPTION. R1-S3 C1-C3 compare against it.
SELECT r.rolname,
       pg_has_role(current_user, r.oid, 'MEMBER') AS is_member,
       pg_has_role(current_user, r.oid, 'USAGE')  AS inherits_privileges,
       pg_has_role(current_user, r.oid, 'SET')    AS can_set_role
FROM pg_roles r
WHERE r.rolname <> current_user
  AND (r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolreplication OR r.rolcreatedb
       OR r.rolname IN ('pg_execute_server_program', 'pg_write_server_files', 'pg_read_server_files',
                        'pg_read_all_data', 'pg_write_all_data', 'pg_signal_backend', 'pg_checkpoint',
                        'pg_create_subscription'))
  AND pg_has_role(current_user, r.oid, 'MEMBER')
ORDER BY 1;

-- R1-S5 cross-check: server-file capability OF THE LOGIN ROLE ITSELF. Not authoritative for other actors; R1-S3
-- evaluates the same roles and functions for every actor.
SELECT 'pg_read_server_files' AS capability,
       pg_has_role(current_user, 'pg_read_server_files', 'USAGE') AS inherited,
       pg_has_role(current_user, 'pg_read_server_files', 'SET')   AS via_set_role
UNION ALL
SELECT 'pg_write_server_files',
       pg_has_role(current_user, 'pg_write_server_files', 'USAGE'),
       pg_has_role(current_user, 'pg_write_server_files', 'SET')
UNION ALL
SELECT 'pg_execute_server_program',
       pg_has_role(current_user, 'pg_execute_server_program', 'USAGE'),
       pg_has_role(current_user, 'pg_execute_server_program', 'SET');
SELECT f.fn, f.kind, has_function_privilege(current_user, f.fn, 'EXECUTE') AS can_execute
FROM (VALUES ('pg_read_file(text)', 'read'),
             ('pg_read_file(text,bigint,bigint,boolean)', 'read'),
             ('pg_read_binary_file(text)', 'read'),
             ('pg_ls_dir(text)', 'read'),
             ('pg_stat_file(text)', 'read'),
             ('lo_import(text)', 'read'),
             ('lo_export(oid,text)', 'write')) AS f(fn, kind)
ORDER BY f.kind, f.fn;

ROLLBACK;
```

**Reading the result:**

| R1-S3 output | Meaning |
|---|---|
| `verdict` = `BLOCKED` | A check failed. No conclusion; investigate and run again |
| `verdict` = `CRITICAL` | At least one `finding` with an E code |
| `verdict` = `HIGH` | Only H findings |
| `verdict` = `PASS` | No finding, and every check is `ok` |
| `finding` codes | E1–E3 and H1–H6 as in §3 R-1; the detail names the actor and how it is reached (`self`, `set_role`, `admin_self_grant`) |
| `check` C1–C3 | The recursive membership, `SET` and `INHERIT` closures equal `pg_has_role` `MEMBER`, `SET` and `USAGE`. For a `SUPERUSER` login role they do not apply, because E1 already makes it CRITICAL |
| `check` C4 | The login role was resolved |
| no `verdict` row, or a psql error | BLOCKED |

**Validation (local, isolated; never production):** a throwaway PostgreSQL 16.15 cluster (loopback only), with one
login role per fixture scenario. Every scenario was run read-only (`BEGIN READ ONLY … ROLLBACK`).

| Fixture | Expected | R1-S3 | Pack before this revision |
|---|---|---|---|
| clean baseline | PASS | PASS | PASS |
| `SET` chain, depth 13, to a SUPERUSER role | CRITICAL | CRITICAL | CRITICAL |
| `INHERIT` chain, depth 13, to `pg_execute_server_program` | CRITICAL | CRITICAL | CRITICAL |
| `SET` false, then a SUPERUSER role | HIGH | HIGH (H6) | HIGH |
| `SET` true chain to a SUPERUSER role | CRITICAL | CRITICAL | CRITICAL |
| `SET` true, then `SET` false, to a BYPASSRLS role | HIGH | HIGH (H6) | HIGH |
| `ADMIN` via `INHERIT` on a role holding `pg_write_server_files` | CRITICAL | CRITICAL (E3, H5) | HIGH (under-classified) |
| `ADMIN` via `SET` on a BYPASSRLS role | CRITICAL | CRITICAL (E2, H5) | CRITICAL |
| `ADMIN` with neither `INHERIT` nor `SET`, on a BYPASSRLS role | HIGH | HIGH (H6) | CRITICAL (over-classified) |
| `ADMIN` via `INHERIT` on a SUPERUSER role (not grantable) | HIGH | HIGH (H5, H6) | CRITICAL (over-classified) |
| `ADMIN` at depth 13 on a BYPASSRLS role | CRITICAL | CRITICAL (E2, H5) | HIGH (under-classified) |
| two paths to one BYPASSRLS role, one with `SET` | CRITICAL | CRITICAL (E2) | CRITICAL |
| two paths to one BYPASSRLS role, none usable | HIGH | HIGH (H6) | HIGH |
| SUPERUSER login role | CRITICAL | CRITICAL (E1) | CRITICAL |
| BYPASSRLS login role | CRITICAL | CRITICAL (E1) | CRITICAL |
| `SET` then `INHERIT` to `pg_write_server_files` | CRITICAL | CRITICAL (E3) | **PASS (false PASS)** |
| `pg_read_file` `EXECUTE` on a `SET`-only actor | HIGH | HIGH (H3) | **PASS (false PASS)** |
| `lo_export` `EXECUTE` inherited | CRITICAL | CRITICAL (E3) | CRITICAL |
| depth 13, `SET` then `INHERIT`, to `pg_read_server_files` | HIGH | HIGH (H3) | **PASS (false PASS)** |
| `ADMIN` via `INHERIT` on a CREATEROLE role | HIGH | HIGH (H2, H5) | HIGH |
| `ADMIN` at depth 13 on a role holding `pg_write_server_files` | CRITICAL | CRITICAL (E3, H5) | **PASS (false PASS)** |
| `SET` chain, depth 41, to a BYPASSRLS role | CRITICAL | CRITICAL (E2) | CRITICAL |
| depth 41, `SET` then `INHERIT`, to `pg_execute_server_program` | CRITICAL | CRITICAL (E3) | **PASS (false PASS)** |

**Result:**
- R1-S3 matched the expected class in **23 of 23** fixtures, with every check `ok` and **no false PASS**. The deepest
  fixtures (depth 41) ran in about 35 ms.
- The pack before this revision was wrong in 9 of 23: 5 false PASS, 2 under-classified, 2 over-classified.
- Fail-closed was also shown: a forced failing check gives `verdict` = BLOCKED, and an unknown predefined role makes
  the query error out, so no verdict row appears and the result is BLOCKED.

---

## Annex B — Release-2 readiness pack R2-3 (read-only; owner-run as the application login role)

Run with `php artisan db` as the application login role, under the owner's read-only psql protocol (`\pset` on its
own line; check that `BEGIN` and `ROLLBACK` are both shown). Section PRE runs before the release, section POST after
`verify <deploy-id>`. Share only the `R2Q…` rows: they hold catalog facts, estimates, aggregates and booleans, and no
row data, role name or query text.

Every catalog lookup is limited to this database and to `current_schema()`. A same-named object in another schema or
database can therefore neither satisfy nor hide a check. R2Q10t checks each of the sixteen guards individually.

Validated locally on PostgreSQL 16 as a non-superuser role that owns the tables: 25/25, including the two-schema,
two-database and wrong-distribution tests and the F0–F6 matrix (§13.14).

```sql
-- Release-2 (R6-B5) — R2-3 read-only production readiness pack.
-- Owner-run only, as the application login role, through `php artisan db`, under the owner's psql protocol.
-- Read-only: one READ ONLY transaction, short timeouts, ROLLBACK at the end. It prints catalog facts,
-- estimates, aggregates and booleans only: no row data, no role or account name, no query text.
-- Every catalog lookup is limited to this database and to current_schema(), so a same-named object in another
-- schema or database can neither satisfy nor hide a check.
-- Section PRE runs before the release; section POST runs after `verify <deploy-id>` (same protocol).
\pset pager off
\pset null '(null)'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '10s';
SET LOCAL lock_timeout = '1s';

-- ===== PRE =====

-- R2Q1 server and default timeouts (the migration sets its own lock_timeout of 5s per step)
SELECT 'R2Q1' AS q,
       current_setting('server_version_num') AS server_version_num,
       (SELECT reset_val FROM pg_settings WHERE name = 'lock_timeout') AS default_lock_timeout,
       (SELECT reset_val FROM pg_settings WHERE name = 'statement_timeout') AS default_statement_timeout,
       (SELECT reset_val FROM pg_settings WHERE name = 'idle_in_transaction_session_timeout') AS default_idle_xact_timeout,
       current_setting('max_locks_per_transaction') AS max_locks_per_transaction;

-- R2Q2 posture of the login role (booleans and counts; no names)
SELECT 'R2Q2' AS q,
       r.rolsuper, r.rolcreaterole, r.rolcreatedb, r.rolbypassrls, r.rolreplication,
       has_schema_privilege(current_user, current_schema(), 'CREATE') AS can_create_in_schema,
       (SELECT count(*) FROM pg_auth_members m WHERE m.member = r.oid) AS member_of_roles,
       pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') AS sees_all_sessions
  FROM pg_roles r
 WHERE r.rolname = current_user;

-- R2Q3 the ten tables: owned by the login role, estimated rows, size (no count(*) scan)
SELECT 'R2Q3' AS q, c.relname,
       pg_get_userbyid(c.relowner) = current_user AS owned_by_login_role,
       c.reltuples::bigint AS est_rows,
       s.n_live_tup AS stat_live_rows,
       pg_size_pretty(pg_total_relation_size(c.oid)) AS total_size,
       pg_total_relation_size(c.oid) AS total_bytes,
       greatest(s.last_analyze, s.last_autoanalyze) AS last_analyzed
  FROM pg_class c
  JOIN pg_namespace n ON n.oid = c.relnamespace
  LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
 WHERE n.nspname = current_schema()
   AND c.relname IN ('sys_audit_logs', 'trx_lab_order_status_logs', 'trx_rme_billing_dispute_events',
                     'trx_rme_invoice_correction_events', 'trx_rme_overpayment_obligation_events',
                     'trx_rme_refund_request_events', 'trx_rme_refund_settlement_events',
                     'trx_inventory_movements', 'users', 'trx_lab_orders')
 ORDER BY c.relname;

-- R2Q4 foreign keys of the eight guarded tables (both ends in current_schema): delete action and validation state
--      (before R6-B5: sys_audit_logs_performed_by_foreign = n/true, trx_lab_order_status_logs_lab_order_id_foreign = c/true)
SELECT 'R2Q4' AS q, c.relname AS on_table, co.conname, rc.relname AS references_table,
       rn.nspname = current_schema() AS references_same_schema,
       co.confdeltype::text AS on_delete, co.convalidated
  FROM pg_constraint co
  JOIN pg_class c ON c.oid = co.conrelid
  JOIN pg_namespace n ON n.oid = c.relnamespace
  JOIN pg_class rc ON rc.oid = co.confrelid
  JOIN pg_namespace rn ON rn.oid = rc.relnamespace
 WHERE co.contype = 'f' AND n.nspname = current_schema()
   AND c.relname IN ('sys_audit_logs', 'trx_lab_order_status_logs', 'trx_rme_billing_dispute_events',
                     'trx_rme_invoice_correction_events', 'trx_rme_overpayment_obligation_events',
                     'trx_rme_refund_request_events', 'trx_rme_refund_settlement_events', 'trx_inventory_movements')
 ORDER BY 2, 3;

-- R2Q5 user triggers on the eight tables in current_schema, with the function each calls (before R6-B5: none)
SELECT 'R2Q5' AS q, c.relname, t.tgname, pn.nspname = current_schema() AS function_same_schema, p.proname,
       t.tgenabled::text AS enabled, t.tgtype::int AS tgtype
  FROM pg_trigger t
  JOIN pg_class c ON c.oid = t.tgrelid
  JOIN pg_namespace n ON n.oid = c.relnamespace
  JOIN pg_proc p ON p.oid = t.tgfoid
  JOIN pg_namespace pn ON pn.oid = p.pronamespace
 WHERE NOT t.tgisinternal AND n.nspname = current_schema()
   AND c.relname IN ('sys_audit_logs', 'trx_lab_order_status_logs', 'trx_rme_billing_dispute_events',
                     'trx_rme_invoice_correction_events', 'trx_rme_overpayment_obligation_events',
                     'trx_rme_refund_request_events', 'trx_rme_refund_settlement_events', 'trx_inventory_movements')
 ORDER BY 2, 3;
SELECT 'R2Q5f' AS q, p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ';'), '-') AS config,
       pg_get_userbyid(p.proowner) = current_user AS owned_by_login_role
  FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
 WHERE n.nspname = current_schema() AND p.proname IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')
 ORDER BY 2;

-- R2Q6 migration record (before R6-B5: r6b5_recorded = 0). migrations_in_schema = t proves the unqualified name
--      resolves to current_schema() (the first schema of the search path that exists).
SELECT 'R2Q6' AS q,
       count(*) FILTER (WHERE migration = '2026_11_23_100001_enforce_rule_248_append_only_layer_one') AS r6b5_recorded,
       max(batch) AS max_batch, count(*) AS migrations_total,
       to_regclass(quote_ident(current_schema()) || '.migrations') IS NOT NULL AS migrations_in_schema
  FROM migrations;

-- R2Q7 sessions and long transactions in this database (aggregates; no user, address or query text).
--      Without pg_read_all_stats (R2Q2 sees_all_sessions = false) other roles' sessions are under-reported.
SELECT 'R2Q7' AS q, coalesce(state, '(hidden)') AS state, count(*) AS sessions,
       count(*) FILTER (WHERE xact_start < now() - interval '5 seconds') AS xact_over_5s,
       count(*) FILTER (WHERE xact_start < now() - interval '60 seconds') AS xact_over_60s,
       coalesce(max(extract(epoch FROM now() - xact_start))::int, 0) AS oldest_xact_seconds
  FROM pg_stat_activity
 WHERE datname = current_database() AND pid <> pg_backend_pid()
 GROUP BY 2 ORDER BY 2;

-- R2Q8 locks other sessions hold or wait for on the ten tables, in THIS database and current_schema only
--      (pg_locks spans every database; a relation OID only means something inside its own database)
SELECT 'R2Q8' AS q, c.relname, l.mode, l.granted, count(*) AS locks
  FROM pg_locks l
  JOIN pg_class c ON c.oid = l.relation
  JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE l.pid <> pg_backend_pid()
   AND l.database = (SELECT d.oid FROM pg_database d WHERE d.datname = current_database())
   AND n.nspname = current_schema()
   AND c.relname IN ('sys_audit_logs', 'trx_lab_order_status_logs', 'trx_rme_billing_dispute_events',
                     'trx_rme_invoice_correction_events', 'trx_rme_overpayment_obligation_events',
                     'trx_rme_refund_request_events', 'trx_rme_refund_settlement_events',
                     'trx_inventory_movements', 'users', 'trx_lab_orders')
 GROUP BY 2, 3, 4 ORDER BY 2, 3, 4;

-- R2Q9 sessions blocked by another session right now
SELECT 'R2Q9' AS q, count(*) AS blocked_sessions
  FROM pg_stat_activity
 WHERE datname = current_database() AND cardinality(pg_blocking_pids(pid)) > 0;

-- ===== POST (after `verify <deploy-id>`; same file, same protocol) =====

-- R2Q10t the sixteen expected guards, one row each, in current_schema. A guard is ok only when it exists on its
--        own table, calls the expected function of current_schema(), is enabled (O) and has the expected type:
--        27 = BEFORE UPDATE OR DELETE FOR EACH ROW, 34 = BEFORE TRUNCATE FOR EACH STATEMENT.
WITH expected(tbl, trg, fn, ttype) AS (VALUES
  ('sys_audit_logs', 'sys_audit_logs_append_only', 'dms_append_only_guard', 27),
  ('sys_audit_logs', 'sys_audit_logs_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_lab_order_status_logs', 'trx_lab_order_status_logs_append_only', 'dms_append_only_guard', 27),
  ('trx_lab_order_status_logs', 'trx_lab_order_status_logs_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_billing_dispute_events', 'trx_rme_billing_dispute_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_billing_dispute_events', 'trx_rme_billing_dispute_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_invoice_correction_events', 'trx_rme_invoice_correction_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_invoice_correction_events', 'trx_rme_invoice_correction_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_overpayment_obligation_events', 'trx_rme_overpayment_obligation_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_overpayment_obligation_events', 'trx_rme_overpayment_obligation_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_refund_request_events', 'trx_rme_refund_request_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_refund_request_events', 'trx_rme_refund_request_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_refund_settlement_events', 'trx_rme_refund_settlement_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_refund_settlement_events', 'trx_rme_refund_settlement_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_inventory_movements', 'trx_inventory_movements_ledger_guard', 'dms_inventory_ledger_guard', 27),
  ('trx_inventory_movements', 'trx_inventory_movements_ledger_guard_trunc', 'dms_inventory_ledger_guard', 34)),
actual AS (
  SELECT c.relname AS tbl, t.tgname AS trg, p.proname AS fn, pn.nspname = current_schema() AS fn_same_schema,
         t.tgenabled::text AS enabled, t.tgtype::int AS ttype
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_proc p ON p.oid = t.tgfoid
    JOIN pg_namespace pn ON pn.oid = p.pronamespace
   WHERE NOT t.tgisinternal AND n.nspname = current_schema())
SELECT 'R2Q10t' AS q, e.tbl, e.trg,
       a.trg IS NOT NULL AS present,
       coalesce(a.fn = e.fn AND a.fn_same_schema, false) AS function_ok,
       coalesce(a.enabled = 'O', false) AS enabled_ok,
       coalesce(a.ttype = e.ttype, false) AS type_ok
  FROM expected e LEFT JOIN actual a ON a.tbl = e.tbl AND a.trg = e.trg
 ORDER BY e.tbl, e.trg;

-- R2Q10 the release-2 end state in one row, all in current_schema. Expected after the release:
--      R2Q10|8|16|0|2|r/true|r/true|1|t
--        guard_tables_ok = 8 (each of the eight tables has both of its guards ok),
--        guard_triggers_ok = 16, unexpected_guard_triggers = 0 (no other trigger calls a guard function),
--        functions_ok = 2 (plpgsql, RETURNS trigger, not SECURITY DEFINER, search_path pinned),
--        both keys RESTRICT and validated with both ends in current_schema, migration recorded,
--        migrations table in current_schema.
--      Before the release: R2Q10|0|0|0|0|n/true|c/true|0|t
WITH expected(tbl, trg, fn, ttype) AS (VALUES
  ('sys_audit_logs', 'sys_audit_logs_append_only', 'dms_append_only_guard', 27),
  ('sys_audit_logs', 'sys_audit_logs_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_lab_order_status_logs', 'trx_lab_order_status_logs_append_only', 'dms_append_only_guard', 27),
  ('trx_lab_order_status_logs', 'trx_lab_order_status_logs_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_billing_dispute_events', 'trx_rme_billing_dispute_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_billing_dispute_events', 'trx_rme_billing_dispute_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_invoice_correction_events', 'trx_rme_invoice_correction_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_invoice_correction_events', 'trx_rme_invoice_correction_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_overpayment_obligation_events', 'trx_rme_overpayment_obligation_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_overpayment_obligation_events', 'trx_rme_overpayment_obligation_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_refund_request_events', 'trx_rme_refund_request_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_refund_request_events', 'trx_rme_refund_request_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_rme_refund_settlement_events', 'trx_rme_refund_settlement_events_append_only', 'dms_append_only_guard', 27),
  ('trx_rme_refund_settlement_events', 'trx_rme_refund_settlement_events_append_only_trunc', 'dms_append_only_guard', 34),
  ('trx_inventory_movements', 'trx_inventory_movements_ledger_guard', 'dms_inventory_ledger_guard', 27),
  ('trx_inventory_movements', 'trx_inventory_movements_ledger_guard_trunc', 'dms_inventory_ledger_guard', 34)),
actual AS (
  SELECT c.relname AS tbl, t.tgname AS trg, p.proname AS fn, pn.nspname = current_schema() AS fn_same_schema,
         t.tgenabled::text AS enabled, t.tgtype::int AS ttype
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_proc p ON p.oid = t.tgfoid
    JOIN pg_namespace pn ON pn.oid = p.pronamespace
   WHERE NOT t.tgisinternal AND n.nspname = current_schema()),
checked AS (
  SELECT e.tbl, coalesce(a.fn = e.fn AND a.fn_same_schema AND a.enabled = 'O' AND a.ttype = e.ttype, false) AS ok
    FROM expected e LEFT JOIN actual a ON a.tbl = e.tbl AND a.trg = e.trg),
fk(conname, tbl, ref) AS (VALUES
  ('sys_audit_logs_performed_by_foreign', 'sys_audit_logs', 'users'),
  ('trx_lab_order_status_logs_lab_order_id_foreign', 'trx_lab_order_status_logs', 'trx_lab_orders')),
fk_state AS (
  SELECT f.conname, string_agg(co.confdeltype::text || '/' || co.convalidated::text, ',') AS state
    FROM fk f
    LEFT JOIN pg_namespace n ON n.nspname = current_schema()
    LEFT JOIN pg_class c ON c.relname = f.tbl AND c.relnamespace = n.oid
    LEFT JOIN pg_constraint co ON co.conrelid = c.oid AND co.conname = f.conname AND co.contype = 'f'
    LEFT JOIN pg_class rc ON rc.oid = co.confrelid
   WHERE co.oid IS NULL OR (rc.relname = f.ref AND rc.relnamespace = n.oid)
   GROUP BY f.conname)
SELECT 'R2Q10' AS q,
       (SELECT count(*) FROM (SELECT tbl FROM checked GROUP BY tbl HAVING count(*) = 2 AND bool_and(ok)) x) AS guard_tables_ok,
       (SELECT count(*) FROM checked WHERE ok) AS guard_triggers_ok,
       (SELECT count(*) FROM actual a
         WHERE a.fn IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')
           AND NOT EXISTS (SELECT 1 FROM expected e WHERE e.tbl = a.tbl AND e.trg = a.trg)) AS unexpected_guard_triggers,
       (SELECT count(*) FROM pg_proc p
          JOIN pg_namespace n ON n.oid = p.pronamespace
          JOIN pg_language l ON l.oid = p.prolang
         WHERE n.nspname = current_schema()
           AND p.proname IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')
           AND l.lanname = 'plpgsql' AND p.prorettype = 'trigger'::regtype AND NOT p.prosecdef
           AND 'search_path=pg_catalog, pg_temp' = ANY (p.proconfig)) AS functions_ok,
       coalesce((SELECT state FROM fk_state WHERE conname = 'sys_audit_logs_performed_by_foreign'), '(missing)') AS audit_fk,
       coalesce((SELECT state FROM fk_state WHERE conname = 'trx_lab_order_status_logs_lab_order_id_foreign'), '(missing)') AS status_log_fk,
       (SELECT count(*) FROM migrations WHERE migration = '2026_11_23_100001_enforce_rule_248_append_only_layer_one') AS r6b5_recorded,
       to_regclass(quote_ident(current_schema()) || '.migrations') IS NOT NULL AS migrations_in_schema;

ROLLBACK;
```
