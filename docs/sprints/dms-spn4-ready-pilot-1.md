---
kb_status: current
kb_verified_against: 6f790a7e
kb_updated: 2026-11-22
---

# DMS-SPN4-READY-PILOT-1 — Fast-Track Ready Pilot SPN4: Design Lock

| Field | Value |
|---|---|
| Sprint id | `DMS-SPN4-READY-PILOT-1` |
| Status | **DESIGN LOCKED (G1).** The owner approved this text on 2026-11-22 (decisions DL-1 to DL-7 below). G1 counts as formally recorded only after this document is merged into the base and verified. No implementation is authorised by this document |
| Base at drafting | `6f790a7ed3f275ea32c14704464822864313e84a` |
| Type, module | `SECURITY_FIX`, `Rme`. Parked manifest `.sprint/dms-spn4-ready-pilot-1.yml` (§9) comes in PR-C2, which is not yet authorised |
| Foundation-First categories | SECURITY FIX · DEPLOYMENT / OPERATIONS · BACKUP / RESTORE / MONITORING / CI-CD GATES · FOUNDATION DOCUMENTATION |
| Gate 1 RME-LAB-FEE-APPROVAL-1 | **NO-GO**. The seven Gate 1 blockers stay open, and LAB-fee stays disabled |
| `.sprint/current.yml` | Stays **DMS-RME-COMPREHENSIVE-CLOSURE-1** and is not changed |
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

## 9. Manifest and GO tag

- `.sprint/current.yml` stays CC1.
- The SPN4 manifest is **parked** at `.sprint/dms-spn4-ready-pilot-1.yml` (separate draft, PR-C2), and DEVFLOW runs
  with `--manifest`.
- Docs, rules and tests close without a deploy and without a tag (CG-16).
- A runtime release needs its own manifest-switch decision (OD-B3-1 precedent) and the `go_tag`
  `dms-spn4-ready-pilot-1-go`. No multi-sprint exception is used.

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
- **Masih terbuka:** keputusan Owner OD-2 sampai OD-10 (§8). Penerimaan risiko A6 hanya dapat diberikan di G5 sebagai
  keputusan tersendiri.

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
