# LEGACY-RME-PDF-ROLL-4-WAVE-1 — Controlled Production Migration Wave-1

**Status: `COMPLETE — WAVE EXECUTED, RECONCILED, ROLLED BACK. 1 GENUINE DOCUMENT PUBLISHED.`**

The first attempt (recorded below, unchanged) correctly ended WATCH on two
blockers. Both were cleared, the wave ran end to end on production, and the
capability was returned to OFF afterwards.

| Blocker | First attempt | Resolution |
|---|---|---|
| 1 — no genuine LDK2 candidate | no document existed | **CLEARED** — owner supplied `RM Landak.pdf`; full preflight passed (§7) |
| 2 — no separation-of-duties operator | only Super Admin held any migration permission | **CLEARED** — owner-approved least-privilege grant (§8) |

| | |
|---|---|
| Accepted / published | **1 / 1** (ceiling 5, remaining 4) |
| Maker → checker | user 7 (Admin Klinik, LDK2) → user 11 (Supervisor RME) — **distinct** |
| Reconciliation | unexplained **0**, quota drift **0** |
| Native side effects | **zero** on every clinical, billing, lab and SATUSEHAT table |
| Final production state | capability OFF, admission EMPTY, wave NONE |

**A note on the commit that carries the RBAC change.** The squash merge of
PR #290 landed as `17d5ccf` but kept the PR's *original* subject —
"WATCH: Wave-1 not executed (no genuine LDK2 candidate, no separation-of-duties
operator)". That subject is stale: the diff it carries is the maker/checker RBAC
split described in §8, not a WATCH determination. The title correction was
rejected by a GraphQL `projects (classic)` deprecation fault in the `gh` client,
and rewriting a pushed commit on the shared base branch is forbidden, so the
record is corrected here instead of by a force-push.

| | |
|---|---|
| Wave reference | `LEGACY-RME-PDF-ROLL-4-WAVE-1` |
| Approval reference | `ROLL-4-WAVE-1-OWNER-APPROVAL-2026-08-14` |
| Approved branch set | `LDK2` (Cabang Landak) — exactly one, no other branch |
| Accepted-import ceiling | 5 |
| Accepted imports actually made | **1** (published; 4 of the ceiling unused) |
| Runtime authority | `17d5ccfeec18cdf93ca1393520dd1442f837f814` |
| Determination | **GO** |

---

## 1. Why the FIRST attempt was WATCH and not GO

*Historical record, preserved unedited.* Both blockers below were true when
written; §7 and §8 record how each was cleared, and §9 records the execution
that followed. Nothing in this section was rewritten to match the outcome.

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

---

## 9. Wave-1 execution — real production evidence (2026-08-14)

Runtime authority `17d5ccfeec18cdf93ca1393520dd1442f837f814`, deployed on
`srv1730088` (`exit=0`, `DEPLOY OK`, smoke 7/7 GO). Every step below ran through
the canonical service or CLI; no row was inserted by hand and no status was
mutated directly.

### 9.1 Order of operations

Approval scope was bound in config *before* the wave was registered, because
`createWave()` reads the deployment's approved branch codes and refuses anything
outside them. Admission and the capability were opened last, after the wave was
already ACTIVE and the operator assigned — so at no point was a branch open to
ingestion without a wave to govern it.

```
config binding → register(1) → approve(11) → activate(1) → assign(7@LDK2)
              → open admission → capability ON → import → publish
```

### 9.2 Separation of duties, proven by refusal

The wave was created by user 1 — Super Admin, who holds every permission through
the application's single global `Gate::before` bypass. Approving it as the same
user was **refused**:

```
ERROR  wave: Gelombang migrasi harus disetujui oleh pengguna yang berbeda dari pembuatnya.
```

This is the result that justifies the sprint. A permission bypass grants
abilities; it cannot satisfy a rule about *identity*, because that rule lives in
`LegacyRmeWaveGovernanceService::approve()` rather than in the permission table.
The wave then moved DRAFT → APPROVED under user 11, and ACTIVE under user 1.

### 9.3 Gate decisions (server-side, read-only probes)

| Actor @ branch | Cleared | Code |
|---|---|---|
| operator 7 @ **LDK2** | yes | `CLEARED` |
| operator 7 @ TKM1 / ATG3 / SUN4 | no | `BRANCH_NOT_ENROLLED` |
| Kasir 8 @ LDK2 | no | `OPERATOR_NOT_ASSIGNED` |
| approver 11 @ LDK2 | no | `OPERATOR_NOT_ASSIGNED` |
| operator 7 @ LDK2 while PAUSED | no | `WAVE_PAUSED` |
| operator 7 @ LDK2 while DRAINING | no | `WAVE_DRAINING` |

The three non-approved branches are refused for the *right* reason — not
enrolled in this wave — rather than by an unrelated failure. The checker being
refused ingestion is not a defect: separation holds on the ingestion path too,
so the account that publishes a document cannot also file one.

### 9.4 Import lifecycle

| Field | Value |
|---|---|
| Import id / uuid | 6 / `8c9d6eb1-26d6-4d1a-8d9e-14d4cbef0b3d` |
| Patient / canonical RM | 40 / `DG-LDK2-2024-22681` |
| Origin branch | 2 — **derived** from the RM (`null` was submitted) |
| Dates | `selected` 2026-08-13 = `latest` 2026-08-13 (single date) |
| Pages | 1 |
| sha256 | `f3cb5eb6…b6f0` — identical to the owner's file |
| Lifecycle | QUEUED → PROCESSING → READY_FOR_REVIEW → REVIEWED → PUBLISHED |
| Queue | depth 0 → 1 → 0; rendered in under 15 s; 0 failed jobs |
| `imported_by` / `published_by` | **7 / 11** |

`imported_by ≠ published_by` is a first for this system. The five records that
predate Wave-1 all carry `1 / 1`.

### 9.5 Human review

Reviewed before publication, against the rendered page rather than any extracted
text — there is no OCR anywhere in this path and none was used. The document's
own printed header reads **"Cabang Landak"**, which independently corroborates
the branch the server derived from the patient's Nomor RM. Publication was
impossible before review: `publish` was denied to the checker while the import
sat at READY_FOR_REVIEW, and denied to the operator at every stage.

### 9.6 Doctor read — both directions

| Doctor | LDK2 in practice set | Treating? | Wave-1 record 6 | Pre-existing record 4 |
|---|---|---|---|---|
| 9 | yes | patient 40 **no**, patient 37 **yes** | denied, absent from history | allowed, present in history |
| 12 | yes | neither | denied | denied |
| Kasir 8 | — | — | denied | denied |

**The authorized read could not be demonstrated on RM 22681 itself, and was not
faked.** Patient 40 has no native visit, so no doctor has ever treated them and
`DoctorPatientScopeService` correctly denies every doctor. Manufacturing a visit
to turn that green is precisely what this workstream forbids. The positive case
is therefore shown on the pre-existing LDK2 record 4, where a real treating
relationship exists — and RM 22681 becomes the *stronger* negative: a doctor who
practises at LDK2, holds `view_legacy_rme_archive`, and still sees nothing,
because same-branch alone has never been sufficient.

### 9.7 Reconciliation, rollback and side effects

```
accepted 1 = published 1 + cancelled 0 + failed 0 + in-flight 0
unexplained 0 · quota drift 0 · quota 1 used / 5 · findings none
```

Wave closed DRAINING → COMPLETED (branch LDK2 completed first). Production was
then returned to its resting state: capability OFF, admitted branches EMPTY,
approval reference EMPTY, wave config EMPTY, `rollout-readiness --expect=off
--strict` → GO.

Native tables, before → after, all unchanged: `trx_clinic_visits` 27→27,
`trx_medical_records` 27→27, `trx_rme_invoices` 19→19, `trx_rme_payments` 27→27,
`trx_lab_orders` 13→13, `trx_lab_case_candidates` 8→8,
`trx_satusehat_candidates` 1→1.

Intended deltas only: legacy imports 5→6, import pages 7→8, records 5→6, record
pages 7→8, ops wave/branch/operator/quota rows 0→1 each, legacy audit events
68→83.

### 9.8 Storage — MEASURED, not estimated

| | |
|---|---|
| Source PDF | 302,655 bytes |
| Rendered page PNG (1783×2500) | 881,970 bytes |
| Thumbnail PNG (229×320) | 33,012 bytes |
| **Total for one 1-page document** | **1,217,637 bytes** |
| Legacy private store | 13,372,432 bytes total · disk 89 GB free |

A one-page scan costs roughly **4×** its source size once rendered — the render,
not the PDF, dominates. Worth carrying into any capacity estimate for a larger
wave.

### 9.9 Read survives the capability being OFF

With `FEATURE_RME_LEGACY_PDF_ARCHIVE=false` confirmed at runtime: the published
Wave-1 record is still readable by its authorized governance reader, and the
treating doctor still reads record 4. Non-treating doctors and the cashier still
see nothing. Migration capability and published clinical read remain independent,
exactly as HISTORY-1A/1B specify.

Health after rollback: `/login`, `/health/live`, `/health/ready`, `/health/lb`
all 200; env pilot; debug OFF; maintenance OFF; queue worker active; no failed
jobs; no new Laravel errors.

### 9.10 Honest limits of this wave

- **One document, not five.** The ceiling was 5 and exactly one genuine
  candidate existed. Quota exhaustion was therefore *not* exercised live
  (`LIVE_EXHAUSTION_TESTED=NO`); it stays covered by the ROLL-4 automated quota
  tests. No document was duplicated to fill the quota.
- **Wave-1 completion means this pilot is complete**, not that Cabang Landak's
  historical archive is migrated. Other LDK2 documents remain un-migrated.
- **`clinical_timezone` is UTC on production, not WITA.** This document is dated
  one day before "today", so it passes `latest < today` by a single day and would
  have failed a few hours earlier. Publish revalidates the date, so the margin is
  live, not a one-off.
- **A master-data discrepancy is recorded, not corrected here.** The document
  shows a birth year of 2006 (and an age of 20 consistent with it); the patient
  master holds 2007. Identity is unambiguous and the date rule is unaffected.
- **Wave-1 GO does not authorize Wave-2**, another branch, or a wider set.
