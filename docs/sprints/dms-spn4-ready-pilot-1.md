---
kb_status: current
kb_verified_against: 6f790a7e
kb_updated: 2026-11-22
---

# DMS-SPN4-READY-PILOT-1 — Fast-Track Ready Pilot SPN4: Design Lock

| Field | Value |
|---|---|
| Sprint id | `DMS-SPN4-READY-PILOT-1` |
| Status | **DESIGN LOCKED (G1).** The owner approved this text on 2026-11-22 (decisions DL-1 to DL-6 below). G1 counts as formally recorded only after this document is merged into the base and verified. No implementation is authorised by this document |
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

**PostgreSQL semantics the classification relies on:**
- *Role attributes* (`SUPERUSER`, `BYPASSRLS`, `CREATEROLE`, `CREATEDB`, `REPLICATION`) are never inherited through
  membership. They become effective only on the role itself, or after `SET ROLE` to a role that holds them.
- *Privileges*, including those of predefined roles such as `pg_read_server_files`, are effective either through
  inheritance (`pg_has_role(…, 'USAGE')`) or through `SET ROLE` (`pg_has_role(…, 'SET')`, PostgreSQL 16).
- An `ADMIN OPTION` lets the holder grant the role onward.

**What is audited:**
1. the login role's attributes;
2. **recursive** membership, with `admin_option`, `inherit_option` and `set_option` at each level;
3. **`SET ROLE`** reach;
4. effective privileges, including the session's `is_superuser`;
5. **server-file capability**: the predefined roles **`pg_read_server_files`**, `pg_write_server_files` and
   `pg_execute_server_program`, and `EXECUTE` on server-file functions;
6. object ownership, which feeds R-2.

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
| **CRITICAL** | **E1** login role `rolsuper` or `rolbypassrls`, or `is_superuser = on`. **E2** can `SET ROLE` (at any depth) to a role with `rolsuper` or `rolbypassrls`, or holds `ADMIN OPTION` on such a role. **E3** effective (`USAGE` or `SET`) member of `pg_execute_server_program` or `pg_write_server_files`, or `EXECUTE` on a server-file **write** function (`lo_export(oid,text)`) | **Pilot NO-GO. Path B (R-2) is mandatory**; Path A is not available |
| **HIGH** | **H1** login role `rolcreaterole`, `rolreplication` or `rolcreatedb`. **H2** can `SET ROLE` to a role with one of those attributes. **H3** effective (`USAGE` or `SET`) member of **`pg_read_server_files`**, or `EXECUTE` on a server-file **read** function (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `pg_stat_file`, `lo_import`), meaning it can read files as the database server's OS user. **H4** effective member of `pg_read_all_data`, `pg_write_all_data`, `pg_signal_backend`, `pg_checkpoint` or `pg_create_subscription`. **H5** `ADMIN OPTION` on any role. **H6** member of a `rolsuper`/`rolbypassrls` role with neither `SET` nor `ADMIN` (attribute not effective, but an anomaly) | Must enter the R-2 assessment. Path A only if each HIGH finding is revoked through a planned change, or covered by a tested compensating control (owner decision OD-3) |
| **PASS** | None of the above | Continue to R-2. R6-01 stays open |
| **BLOCKED** | Any query of R6-A or R1-S not run; incomplete output; an error; or output that cannot be matched to the login role the application uses | No pilot and no partial conclusion. Run again |

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
| **AT-1** | R-1 PASS on complete original output (R6-A + R1-S1…S5) |
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
- **Masih terbuka:** keputusan Owner OD-2 sampai OD-10 (§8). Penerimaan risiko A6 hanya dapat diberikan di G5 sebagai
  keputusan tersendiri.

---

## Annex A — Supplementary query pack R1-S (read-only; owner-run as the application login role)

Run with `php artisan db`. Follow the owner's read-only psql protocol: `\pset` on its own line, then check
`BEGIN READ ONLY` / `ROLLBACK`. Syntax is validated on PostgreSQL 16.15 (local, read-only). R1-S4 and R1-S5 are
authoritative for effective capability; R1-S3 shows the paths.

```sql
BEGIN READ ONLY;

-- R1-S1 session identity and effective superuser status
SELECT current_user, session_user, current_setting('is_superuser') AS is_superuser,
       current_setting('server_version') AS pg;

-- R1-S2 login-role attributes
SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb, rolreplication, rolinherit, rolcanlogin
FROM pg_roles WHERE rolname = current_user;

-- R1-S3 recursive membership: every reachable role, how it is reached, and its attributes
WITH RECURSIVE reach(roleid, path, via_inherit, via_set, via_admin, depth) AS (
  SELECT m.roleid, ARRAY[m.roleid], m.inherit_option, m.set_option, m.admin_option, 1
  FROM pg_auth_members m
  WHERE m.member = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  UNION ALL
  SELECT m.roleid, r.path || m.roleid, r.via_inherit AND m.inherit_option, r.via_set AND m.set_option,
         m.admin_option, r.depth + 1
  FROM pg_auth_members m JOIN reach r ON m.member = r.roleid
  WHERE NOT m.roleid = ANY (r.path) AND r.depth < 10
)
SELECT g.rolname, g.rolsuper, g.rolbypassrls, g.rolcreaterole, g.rolreplication, g.rolcreatedb,
       min(r.depth) AS depth, bool_or(r.via_inherit) AS inherits, bool_or(r.via_set) AS can_set_role,
       bool_or(r.via_admin) AS has_admin_option
FROM reach r JOIN pg_roles g ON g.oid = r.roleid
GROUP BY g.rolname, g.rolsuper, g.rolbypassrls, g.rolcreaterole, g.rolreplication, g.rolcreatedb
ORDER BY depth, g.rolname;

-- R1-S4 effective capability towards sensitive roles (PostgreSQL 16: MEMBER, USAGE, SET)
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

-- R1-S5 server-file capability: predefined file roles and server-side file functions
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

**Mapping to the classes:**

| Query | Finding | Class |
|---|---|---|
| R1-S1 | `is_superuser = on` | E1 |
| R1-S2 | `rolsuper` or `rolbypassrls` | E1 |
| R1-S2 | `rolcreaterole`, `rolcreatedb` or `rolreplication` | H1 |
| R1-S4 | `can_set_role = t` on a `rolsuper`/`rolbypassrls` role | E2 |
| R1-S4 | `can_set_role = t` on a role with `rolcreaterole`, `rolcreatedb` or `rolreplication` | H2 |
| R1-S4 | `is_member = t` on a `rolsuper`/`rolbypassrls` role, with `can_set_role = f` and no `ADMIN` | H6 |
| R1-S4 / R1-S5 | `inherits_privileges` or `can_set_role` = t on `pg_execute_server_program` or `pg_write_server_files`; `lo_export` executable | E3 |
| R1-S4 / R1-S5 | `inherits_privileges` or `can_set_role` = t on `pg_read_server_files`; a read function executable | H3 |
| R1-S4 | the same, on another predefined role | H4 |
| R1-S3 | `has_admin_option = t` on a `rolsuper`/`rolbypassrls` role | E2 |
| R1-S3 | `has_admin_option = t` on any other role | H5 |

If every query is complete and none of these findings appears, R-1 is PASS.
