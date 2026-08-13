# LEGACY-RME-PDF-HISTORY-1B — Doctor Clinical Branch Context & Production Read UAT

**Branch:** `feature/legacy-rme-pdf-history-1b-doctor-clinical-branch-context-production-read-uat`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline:** HISTORY-1A GO `781b43e6053cd2ab7593a70a78528e531e92c6c7`

Additive on top of HISTORY-1A. ROLL-3, HISTORY-1 and HISTORY-1A GO tags are
immutable and untouched.

---

## 1. The problem

HISTORY-1A proved that an already-PUBLISHED legacy archive stays readable while
the migration capability is OFF and no branch is admitted. It could not prove
the **treating-doctor** path on production, because no real doctor account had a
usable branch context:

```
user#9 (Doctor)
  treats patient#37                     = YES
  patient#37 has a PUBLISHED legacy RME = YES  (record #4, origin LDK2)
  DoctorPatientScope                    = PASS
  legacy branch scope                   = []      ← read denied
```

### Root cause (measured on production, not inferred)

`LegacyRmeWorkspaceScope::branchIdsFor()` resolved a non-governance reader
through `BranchContext::forUser()`, whose priority is:

1. active RME online context → 2. `users.branch_id` → 3. user `branches()`
   relation → 4. **MAIN**.

Every real doctor on the pilot carries `users.branch_id = NULL`, so with no live
online session the answer was **MAIN (branch 4)**. MAIN is deliberately not
RME-enabled, so `in_array(4, rmeEnabledIds([3,2,5,1]))` was false and the scope
collapsed to `[]`.

Two distinct defects:

- **The denial was accidental.** It depended on MAIN's module flags rather than
  on anything about the doctor. Had MAIN ever been RME-enabled, a doctor would
  have been scoped to a branch they have no clinical relationship with.
- **The model was wrong.** Doctors in this system are multi-branch. Doctor #17
  practises at `[TKM1, LDK2, ATG3]`; a single operator-style "current working
  branch" cannot express that, so a doctor standing in one of their branches
  could not read a patient's archive that originated in another of their own.

---

## 2. The canonical model

`mst_doctor_branches` (Sprint 66.1.1) is already the system's source of truth —
its model relation is documented as *"allowed RME practice branches (source of
truth for online context)"*, and `UserOnlineContextService::startDoctorSession`
refuses any branch outside it (*"Cabang yang dipilih tidak termasuk Cabang
Praktik yang Diizinkan"*). The online branch is only **today's selection** out of
that set.

```
DOCTOR_CONTEXT_SOURCE   = mst_doctor_branches (Cabang Praktik yang Diizinkan)
SINGLE_OR_MULTI_BRANCH  = MULTI
RESOLUTION_FORMULA      = doctor practice branches ∩ active RME-enabled branches
NULL_BEHAVIOR           = FAIL CLOSED (empty set ⇒ deny everything)
INACTIVE_BRANCH         = dropped from the set
```

New service `App\Modules\Doctor\Services\DoctorClinicalBranchResolver`:

- `appliesTo()` reuses `DoctorPatientScopeService::shouldApplyDoctorScope()`
  verbatim, so "who counts as a doctor" can never drift between the two.
- `branchIdsFor()` returns the intersection, and `[]` for: no doctor master
  link, an inactive master, no practice branch, or no RME-enabled branch.
- Deterministic and **session-independent** — the same answer from HTTP, from
  the queue, and from an artisan probe.

`LegacyRmeWorkspaceScope::branchIdsFor()` now routes doctor-scoped actors
through it. Governance-tier holders (all RME branches) and non-doctor operators
(their `BranchContext` branch) are **unchanged**.

---

## 3. Why this is not a security relaxation

| Gate | Before | After |
| --- | --- | --- |
| Authentication | required | required |
| Named read permission | required | required |
| Branch scope | 1 branch (or MAIN → `[]`) | assigned practice branches ∩ RME-enabled |
| Treating relationship | required | required |
| PUBLISHED status | required | required |
| Private-disk policy | required | required |

Branch membership has **never** been authorization on its own and still is not.
A doctor's legacy reach stays strictly **narrower** than their native reach:
native access is the clinical relationship across the RME branch set, while the
archive additionally requires the origin branch to be one they practise in.

Three cases tighten:

- an **unlinked** Doctor-role user previously got a branch and now gets `[]`;
- an **inactive** doctor master now resolves to `[]`;
- a **stale online context** on a revoked practice branch grants nothing.

---

## 4. Authorization matrix

| Case | Treating | Origin branch in practice set | Result |
| --- | --- | --- | --- |
| A | yes | yes | **ALLOW** |
| B | yes | no practice branch at all | DENY (404) |
| C | yes | not in set | DENY (404) |
| D | no | yes | DENY (403) |
| E | no doctor master link | — | DENY |
| F | inactive doctor master | — | DENY |
| G | yes | branch deactivated / RME off | DENY |
| H | yes | yes, but record VOID / not PUBLISHED | hidden |
| I | yes (patient A) | yes | DENY on patient B's record |
| J | yes | yes, permission removed | DENY |

---

## 5. Scope

**Included:** the canonical resolver, the legacy branch-scope wiring, the test
matrix, fixture correction, rules/doc synchronization, production UAT.

**Not included:** ROLL-4, any migration rollout, branch admission changes, OCR,
billing/Lab/SATUSEHAT, DEVFLOW base-ref resolver.

- **No migration.** `mst_doctor_branches` already exists and is populated
  (12 rows on the pilot).
- **No new route, no new permission, no new policy, no relaxed policy.**
- **No production data mutation required** — the pilot's practice assignments
  were already correct; only the resolution logic was wrong.

---

## 6. Fixture correction (not a weakening)

`DoctorFactory` seeds the practice pivot from the doctor's own `branch_id`.
Existing legacy doctor fixtures created the master **without** a branch, so the
doctor's practice branch was a random unrelated branch and the tests passed only
because `users.branch_id` happened to be the authority.

Those fixtures now state the branch on the **doctor master**, which is what they
always meant. Every assertion — including the same-branch-non-treating **403** —
is preserved verbatim; the suite returned to exactly its baseline
(483 passed / 5 skipped / 1308 assertions).

---

## 7. Evidence

See the final closure report for CI, deployment, production Doctor UAT, negative
UAT, side-effect proof and tag verification.
