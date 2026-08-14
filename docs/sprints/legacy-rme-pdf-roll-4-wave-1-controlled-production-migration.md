# LEGACY-RME-PDF-ROLL-4-WAVE-1 — Controlled Production Migration Wave-1

**Status: `RE-ATTEMPT — BOTH BLOCKERS CLEARED; WAVE ENABLEMENT SHIPPED, EXECUTION PENDING.`**

The first attempt (recorded below, unchanged) correctly ended WATCH on two
blockers. Both are now cleared, and this commit ships the second one's fix:

| Blocker | First attempt | Now |
|---|---|---|
| 1 — no genuine LDK2 candidate | no document existed | **CLEARED** — owner supplied `RM Landak.pdf`; full preflight passed (§7) |
| 2 — no separation-of-duties operator | only Super Admin held any migration permission | **CLEARED** — owner-approved least-privilege grant (§8) |

**This commit changes RBAC seeding and tests only.** It does not register a
wave, does not admit a branch, does not enable the migration capability and does
not import anything. At the moment it merges, production is still at the safe
resting state: capability OFF, admission EMPTY, approval EMPTY, wave config
EMPTY, zero operational wave rows. Wave execution evidence lands in a separate
follow-up commit, and no GO tag exists until that execution actually succeeds.

| | |
|---|---|
| Wave reference | `LEGACY-RME-PDF-ROLL-4-WAVE-1` |
| Approval reference | `ROLL-4-WAVE-1-OWNER-APPROVAL-2026-08-14` |
| Approved branch set | `LDK2` (Cabang Landak) — exactly one, no other branch |
| Accepted-import ceiling | 5 |
| Accepted imports actually made | **0** |
| Runtime authority | `88e880b49faf6cd099a4d4fd60200d30097eed17` (ROLL-4 GO) |
| Determination | **WATCH** |

---

## 1. Why this is WATCH and not GO

Two independent blockers. Either alone forbids a GO; both are factual, verified
on production, and neither can be cleared from this side without the owner.

### Blocker 1 — no genuine LDK2 candidate document exists

A whole-filesystem sweep of the production host returns **five PDFs in total**,
and every one of them is an already-migrated source file inside the private
legacy store:

```
find / -xdev -iname '*.pdf' -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -not -path '/proc/*' -not -path '/sys/*' -not -path '/usr/*' -not -path '/snap/*'
```

| Import | Branch | Patient | Import status | Record | Record status |
|---|---|---|---|---|---|
| 1 | TKM1 | 36 | PUBLISHED | 1 | VOID (corrected) |
| 2 | TKM1 | 36 | PUBLISHED | 2 | PUBLISHED |
| 3 | ATG3 | 38 | PUBLISHED | 5 | PUBLISHED |
| **4** | **LDK2** | **37** | **PUBLISHED** | **4** | **PUBLISHED** |
| 5 | SUN4 | 39 | PUBLISHED | 3 | PUBLISHED |

There is no pending, staged or un-migrated document: nothing in `/tmp`, nothing
in any home directory, nothing outside the private store. Object storage is
`disabled_ready` (no bucket, no endpoint), so there is no remote source either.

**The single genuine LDK2 legacy document — patient 37 — was already migrated and
published during ROLL-3's Wave-1.** LDK2 has no second archive document staged
for this wave.

Manufacturing a patient, a PDF, an RM or an archive to reach the quota is
explicitly forbidden. A wave with zero genuine documents cannot satisfy the GO
criterion "at least 1 genuine LDK2 import accepted", so the honest outcome is
WATCH.

### Blocker 2 — no separation-of-duties-compliant migration operator

Effective production grants for the legacy migration permission family:

| Permission | Roles holding it |
|---|---|
| `create_legacy_rme_imports` | Super Admin only |
| `review_legacy_rme_imports` | Super Admin only |
| `publish_legacy_rme_imports` | Super Admin only |
| `void_legacy_rme_imports` | Super Admin only |
| `view_legacy_rme_imports` | Super Admin only |
| `view_legacy_rme_archive` | Super Admin, Doctor |
| `approve_legacy_rme_migration_wave` | **none** |
| `manage_legacy_rme_migration_operations` | **none** |
| `view_legacy_rme_migration_operations` | **none** |

ROLL-4 deliberately separates `approve_legacy_rme_migration_wave` from
`manage_legacy_rme_migration_operations` so a wave's creator cannot approve it.
On this deployment **no role holds either**, so the only account able to register,
approve, activate and operate a wave is the single Super Admin (user 1, via the
global `Gate::before` bypass) — which collapses that separation entirely.

The natural least-privileged operator exists but is not provisioned: **Yuni FO
(user 7, Admin Klinik) is the only branch-pinned user and is pinned to LDK2**
(`users.branch_id = 2`), yet holds no legacy permission at all.

Provisioning an operator is a permanent production RBAC change. It was not made,
because with Blocker 1 in force it would grant migration rights for zero
migration value.

---

## 2. What WAS verified — real production evidence

All of the following is read-only, gathered with the capability OFF, and changed
nothing.

### 2.1 Immutable authorities intact

| Authority | SHA | Object type |
|---|---|---|
| ROLL-3 | `6d4850f4140a98d816e1d7d35678d948be65805b` | commit |
| HISTORY-1 | `7963fec76d8ad16bfb7c1887b5b0c98d10460fc0` | commit |
| HISTORY-1A | `781b43e6053cd2ab7593a70a78528e531e92c6c7` | commit |
| HISTORY-1B | `c947b94dc819bc9ec535b1288420ea06542026b3` | commit |
| ROLL-4 | `88e880b49faf6cd099a4d4fd60200d30097eed17` | commit |

`legacy-rme-pdf-roll-4-production-migration-operations-scale-up-go` → tag object
`f426405c9935d525d802d3872691da986318c862`, peels to `88e880b4…`. No tag was
moved, recreated or deleted.

### 2.2 Production baseline = documented safe resting state

```
HOST   srv1730088
HEAD   88e880b49faf6cd099a4d4fd60200d30097eed17
TAG    legacy-rme-pdf-roll-4-production-migration-operations-scale-up-go
DIRTY  0
```

Runtime (config-cached, read through the app, not the environment file):

```
FEATURE_FLAG_EFFECTIVE  false
ADMITTED                []
WAVE                    ''
APPROVAL REFERENCE      (none recorded)
APPROVED BRANCH SET     (none recorded)
```

`legacy-rme:migration-status` → wave `(none registered)`, operations layer
`enforced`, accepting new documents `no`, **`Findings: none`**.

`legacy-rme:rollout-readiness` → every check GO, including
`migration_operations_layer`: *"The operations layer is enforced and no branch is
admitted, so no wave is required."*

Operational tables confirmed empty: `ops_rme_legacy_wave_branches` 0,
`ops_rme_legacy_wave_operators` 0, `ops_rme_legacy_migration_quotas` 0.

### 2.3 Doctor clinical read survives migration OFF (HISTORY-1A/1B)

Executed against the **existing genuine published LDK2 record #4** (patient 37)
through the canonical `LegacyRmePatientHistoryService`, with the capability OFF:

| Actor | Relationship to patient 37 | Published legacy records | Verdict |
|---|---|---|---|
| drg Nisa (user 9, doctor 17) | treating doctor | 1 → record #4 | **READ PASS** |
| drg Ramadhan (user 12, doctor 18) | Doctor, not treating | 0 | **NEGATIVE PASS** |
| Super Admin (user 1) | intake/read capability | 1 | pass |
| Dhea (user 8, Kasir) | no legacy read permission | 0 | **DENIED, as designed** |

This is the ROLL-4 non-negotiable #11 proven on real data: **no migration state
removes authorized PUBLISHED clinical read**, and **branch alone never authorizes
a doctor** — drg Ramadhan is a genuine LDK2-side doctor and still sees nothing,
because `DoctorPatientScope` finds no treating relationship.

### 2.4 Queue, capacity and storage

| Signal | Value |
|---|---|
| Worker service | `daengtisiams-queue-worker.service` **active**, `User=www-data` |
| Producer queue | `legacy-rme-documents` (`LEGACY_RME_QUEUE`) |
| Failed jobs | none |
| Pending render jobs | 0 |
| Disk free (`/`) | 89 G free of 96 G (8 % used) |
| Legacy private store | 12 154 795 bytes (MEASURED) |
| Storage per migrated document | 5 documents · 2 519 870 bytes source · avg 503 974 bytes/doc (MEASURED) |
| Memory | 7 940 MB total, 7 151 MB available (informational, not gate-enforced) |

### 2.5 Native side-effect baseline (captured, unchanged)

`trx_clinic_visits` 27 · `trx_medical_records` 27 · `trx_rme_invoices` 19 ·
`trx_rme_payments` 27 · `trx_lab_orders` 13 · `trx_lab_case_candidates` 8 ·
`trx_satusehat_candidates` 1.

Task-created delta on every one of these: **0** — no import was accepted, so no
native, billing, lab or SATUSEHAT artifact could be created. Legacy staging,
final, page and operational-wave tables are likewise unchanged (delta 0).

---

## 3. Governance gap this workstream found

The ROLL-4 runbook's §1 *Plan a wave* covers branch set, approval reference,
quota and operators — but it does not require anyone to confirm, **before** the
approval is recorded and the config authority is edited, that:

1. genuine un-migrated candidate documents actually exist for the approved
   branch set; and
2. an operator holding `create_legacy_rme_imports` exists **and** an approver
   holding `approve_legacy_rme_migration_wave` exists as a *different* account.

Both were assumed. Both were false here. Discovering that only after
`FEATURE_RME_LEGACY_PDF_ARCHIVE=true` and `LEGACY_RME_ADMITTED_BRANCH_CODES=LDK2`
had been applied would have opened a write capability on live clinical data for a
wave that could never accept a document.

The runbook now carries a **§1a Candidate and operator preflight** step that
must pass before §2 records the approval. See
`docs/runbooks/legacy-rme-migration-operations-runbook.md`.

---

## 4. Durable rules established

1. `ROLL-4-WAVE-1` is limited to **LDK2**. TKM1, ATG3 and SUN4 are not approved.
2. The approval reference is `ROLL-4-WAVE-1-OWNER-APPROVAL-2026-08-14` and
   authorizes **only** this wave and this branch set.
3. That approval never authorizes Wave-2 or any other branch. Every future
   production wave requires a fresh, exact owner approval.
4. The Wave-1 accepted-import ceiling is **5** — a ceiling, never a target.
5. Fewer than five genuine documents is an acceptable wave. **Zero genuine
   documents is WATCH, never GO.** Documents are never fabricated to fill a
   quota, and quota counters are never manipulated to simulate exhaustion.
6. **A wave must not be opened before its candidate documents and its operators
   are both confirmed to exist.** Capability and admission are opened last, not
   first.
7. A migration operator requires an existing `create_legacy_rme_imports` grant
   **plus** an explicit, unrevoked assignment for that exact branch.
8. Creator and approver must be different accounts. A deployment where
   `approve_legacy_rme_migration_wave` and
   `manage_legacy_rme_migration_operations` are held by no role cannot satisfy
   separation of duties, and Super Admin's `Gate::before` bypass does not
   substitute for it.
9. The branch that governs ingestion is always **RM-derived** — never a request,
   session or `BranchContext` value.
10. PUBLISHED records stay immutable; correction is **VOID + fresh import**.
11. Global migration OFF blocks every legacy mutation but **never** hides an
    authorized PUBLISHED clinical read. Verified here on record #4.
12. A doctor reaches a legacy archive only through a real treating relationship.
    Same branch alone is not authorization. Verified here.
13. Legacy migration creates **zero** native RME / billing / lab / SATUSEHAT
    artifacts.
14. Wave completion requires reconciliation with `unexplained = 0` and
    `quota_drift = 0`. An empty queue is never completion evidence.
15. `ROLL-4-WAVE-1` reaching WATCH does not authorize Wave-2, and does not
    reopen or move any ROLL-3 / HISTORY-1 / HISTORY-1A / HISTORY-1B / ROLL-4 GO
    tag.
16. The safe resting state — capability OFF, admission EMPTY, approval EMPTY,
    wave NONE — is where production sits between waves and where any wave
    closure returns it.

---

## 5. What had to happen before Wave-1 could be re-attempted

1. **Owner supplies genuine LDK2 archive PDFs** (1–5 documents) with, for each:
   canonical RM whose branch code resolves to LDK2, correct patient resolution,
   a readable source PDF, and human-verifiable clinical dates (earliest →
   `selected_rme_date`, latest → `latest_rme_date`). OCR is never the date
   authority. → **DONE**, one document. See §7.
2. **Owner confirms the intake operator** — Yuni FO (user 7) is the natural
   least-privileged candidate, already pinned to LDK2 — and a **separate**
   approver account. Both grants go through the canonical seeder/permission
   workflow, never direct SQL. → **DONE**. See §8.
3. Re-run this preflight, then follow the runbook from §1a. → **DONE**, §7.

---

## 7. Candidate preflight — RE-ATTEMPT (2026-08-14)

Owner supplied exactly one document. It was NOT assumed eligible; every gate was
re-run against production through the canonical services, never a re-derivation.

| Gate | Evidence | Result |
|---|---|---|
| File present + valid | `RM Landak.pdf`, 302,655 bytes, `%PDF-1.4`, sha256 `f3cb5eb6…b6f0` | PASS |
| Page count | `pdfinfo` → `Pages: 1` (matches owner) | PASS |
| Raw RM | `22681`, owner-confirmed | PASS |
| Prior visual reading `39681` | probed production: **0 patients match** | correctly REJECTED |
| Patient resolution | exactly **one** patient, id 40 | PASS |
| Canonical RM | **`DG-LDK2-2024-22681`** — read from `mst_patients`, never synthesized | PASS |
| RM-derived branch | `LegacyRmeBranchResolver` → `LDK2` / Cabang Landak / id 2 | PASS |
| Duplicate | `LegacyRmeDuplicateDetectionService` → not blocked; 0 imports/records for patient 40; 0 rows anywhere with this sha256 | PASS |
| Native reference | `PatientEarliestNativeRmeDateResolver` → `null`, zero clinic visits → `NO_NATIVE_REFERENCE` | PASS (valid) |
| Date rule | `LegacyRmeDateRuleService::evaluate(2026-08-13, 2026-08-13)` → passed | PASS |

**Two findings worth recording rather than smoothing over.**

*The canonical RM year is 2024, not 2026.* Composing `DG-LDK2-2026-22681` from
"Landak + 2026 + 22681" would have produced a patient that does not exist. The
RM's year segment is the patient's registration year, not the document's
clinical year, which is exactly why the rule is that canonical RM comes from
patient data and is never synthesized.

*The date margin is one day, and the clock is UTC.* `clinical_timezone` resolves
to `UTC` on production, not WITA. The document is dated 2026-08-13 and "today"
is 2026-08-14, so `latest < today` holds — but it would NOT have held if
evaluated before ~08:00 WITA on 2026-08-13+1, when UTC was still on the previous
calendar day. Publish revalidates the date, so this is a live constraint, not a
one-off: a legacy document dated *yesterday* sits at the very edge of what the
"an archive is historical" rule permits.

*One data-quality note, non-blocking.* The document's TTL reads `27-07-2006`
while `mst_patients.date_of_birth` holds `2007-07-27`; the document's own age
annotation ("20") agrees with 2006. Day and month match exactly and the RM,
name and branch all resolve to a single patient, so identity is not ambiguous.
The date rule only requires `birth_date <= selected_rme_date`, which holds
either way. Recorded for master-data correction, not treated as a mismatch.

---

## 8. Blocker 2 — how separation of duties was actually created

ROLL-4 shipped the *mechanism* (`approve` split from `manage`, plus a
server-side approver-is-not-creator check) but shipped it switched off, with its
own note saying to enable it "once two staffed accounts exist". Those accounts
did not exist: **all five legacy records already in production were imported AND
published by the same user id 1**, because no operational role held any legacy
migration permission at all.

The owner confirmed user 7 and user 11 are two different staffed people and
approved extending the existing roles, using only already-defined permissions:

| Role | Granted | Deliberately withheld | Duty |
|---|---|---|---|
| Admin Klinik (user 7, pinned LDK2) | `view_legacy_rme_imports`, `create_legacy_rme_imports` | review, publish, void, all operations, approve | **maker** — files the document |
| Supervisor RME (user 11) | `view` + `review` + `publish` imports, `view_legacy_rme_migration_operations`, `approve_legacy_rme_migration_wave` | `create`, `manage_legacy_rme_migration_operations` | **checker** — certifies, publishes, approves the wave |
| Owner | `view_legacy_rme_migration_operations` | everything else | read-only oversight |
| Super Admin | unchanged | — | wave creator/manager |

Every withheld permission is load-bearing. The checker is denied `create` so it
cannot be a maker-checker pair of one; it is denied `manage` so it cannot create
the wave it then approves — with `LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true`,
`LegacyRmeWaveGovernanceService::approve()` rejects a creator signing their own
wave server-side.

**A consequence recorded deliberately, not discovered later.**
`LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS` is `review`/`publish`/`void` —
holding any one widens the holder to *every* RME-enabled branch. So granting the
checker `review` + `publish` also grants a cross-branch archive read. That is
correct for an RME-wide supervisor (the role already has cross-branch RME
reporting) but it is a real widening, and it is asserted in
`LegacyRmeWaveSeparationOfDutiesTest` so nobody has to rediscover it. The maker
holds none of those three and therefore stays pinned to LDK2 by `BranchContext`
— the property that stops a branch operator reading another branch's archive.

---

## 6. Scope boundaries

Not started, not authorized by this workstream: Wave-2; TKM1 / ATG3 / SUN4
migration; global rollout; bulk or unattended import; OCR as clinical-date
authority; conversion of legacy archives into native RME; billing, lab or
SATUSEHAT generation; RBAC redesign; infrastructure change.
