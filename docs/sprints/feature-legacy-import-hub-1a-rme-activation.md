# FEATURE-LEGACY-IMPORT-HUB-1A — Legacy RME activation

**Type:** MODULE_SPRINT · **Module:** LegacyImport / LegacyRme
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Parent:** FEATURE-LEGACY-IMPORT-HUB-1 (`feature-legacy-import-hub-1-go` @ `637b5a4`)
**GO tag:** `feature-legacy-import-hub-1a-rme-activation-go`

---

## 1. The gap this closes

FEATURE-LEGACY-IMPORT-HUB-1 shipped three legacy importers under one hub. Two of
them — Legacy Patient and Legacy Odontogram — were end-to-end usable the moment
it deployed. Legacy RME was not, and the release notes said so only obliquely:

```
capability          rme.legacy_pdf_archive = ON
branch admission    LEGACY_RME_ADMITTED_BRANCH_CODES = (empty)
wave approval       LEGACY_RME_ADMISSION_APPROVAL_REFERENCE = (empty)
declared wave       LEGACY_RME_WAVE = (empty)
active ROLL-4 wave  none — all four historical waves terminal
```

Every legacy RME upload on production was therefore refused server-side, for an
entire release, while the hub page reported the capability as **Aktif**.

That is not a documentation problem. It is the difference between four distinct
states that the system had been treating as one:

| State | Meaning | Can a document be accepted? |
|---|---|---|
| `capability ON` | the feature flag is on | **no** |
| `+ branch admission active` | ROLL-3 admits specific branch codes under an owner approval | **no** |
| `+ wave active` | a registered ROLL-4 wave is ACTIVE and bound to that approval | only for an assigned operator |
| `= end-to-end usable` | all of the above, with an operator assigned and quota left | **yes** |

**Capability ON is not activation. Activation is not usability.** Conflating them
is what let production sit shut while reporting itself open.

---

## 2. What changed in code

Exactly one behavioural change, and it is report-only.

### `LegacyRmeActivationStateService` (new)

A read-only composition of the services that already decide each gate:

| Gate | Authority consulted |
|---|---|
| capability | `LegacyRmeFeatureGuard::migrationEnabled()` |
| admission | `LegacyRmeBranchAdmissionService` — including `decideForBranchCode()` for the per-branch verdict |
| wave | `LegacyRmeWaveBindingService` (declared vs registered vs bound) |
| operations | `LegacyRmeOperationsGateService::enforced()` |

It re-implements **nothing**. The per-branch verdict is taken by calling the real
admission gate rather than re-reading its allowlist, so the report cannot drift
from the decision the upload path takes — a property one of the tests asserts by
direct comparison rather than by a hard-coded expectation.

It returns a stable machine `blocker` code and never operator-facing prose:

```
CAPABILITY_OFF · NO_BRANCH_ADMITTED · APPROVAL_MISSING · APPROVAL_INCOMPLETE
WAVE_NOT_DECLARED · WAVE_NOT_REGISTERED · WAVE_NOT_ACTIVE
WAVE_BINDING_MISMATCH · WAVE_UNREADABLE
```

Blocker order mirrors the runtime chain, so an operator is always sent to the
first control that is actually refusing, not the last one checked.

### `LegacyImportHubService`

- `has_additional_gates` keeps its meaning: a property of the **type** — does
  anything beyond the flag and the route govern it. Fixed for the type's life.
- `additional_gates` is new: what those gates **say right now**, or `null` for a
  type that has none.
- `status` gains `belum_dibuka` — capability on, route registered, operator
  permitted, but the archive's own gates are shut so no upload can be accepted.

The gate state is evaluated **once per page**, not per card and never per branch
row, so adding a branch cannot turn the status page into an N+1.

### The hub view

The permanent footnote *"Halaman ini tidak dapat memastikan keduanya"* ("this
page cannot verify either of them") is gone. The page can verify them, and now
does — naming the specific blocker and, where it helps, the branches involved.

### What did **not** change

No gate was weakened, widened, reordered or bypassed. No migration, no route, no
permission, no policy, no middleware. Admission remains config/deploy-time
authority; the wave row remains the operational mirror; ROLL-4 still only
narrows; the branch is still derived from the Nomor RM and never read from a
request; the ceiling stays 100 per branch per clinical day; published archives
stay immutable and corrections stay VOID + replacement; legacy RME still creates
no visit, invoice, payment, odontogram, lab or SATUSEHAT row.

---

## 3. The production activation

Governance decision recorded by the project owner for this sprint:

```
approval reference   ROLL-4-WAVE-4-OWNER-APPROVAL-2026-08-28
wave code            WAVE-4
admitted branches    TKM1, LDK2, ATG3, SUN4
per-branch daily     100
```

`WAVE-4` continues the ROLL-4 lineage: Wave-1 COMPLETED, Wave-2 CANCELLED,
Wave-2R COMPLETED, Wave-3 formally SKIPPED (`docs/sprints/legacy-rme-pdf-roll-4-wave-3-skipped.md`).

### Branch scope

| Branch | Active | RME-enabled | Admitted | Reason |
|---|---|---|---|---|
| TKM1 | yes | yes | **yes** | eligible clinic branch |
| LDK2 | yes | yes | **yes** | eligible clinic branch |
| ATG3 | yes | yes | **yes** | eligible clinic branch |
| SUN4 | yes | yes | **yes** | eligible clinic branch |
| MAIN | yes | **no** | no | not RME-enabled, and permanently forbidden — a fallback branch may never host a clinical migration |
| SYN4A | **no** | no | no | inactive, soft-deleted synthetic rehearsal branch |

### Operator staffing — stated plainly

Production has exactly **one** branch-bound clinic operator: Yuni FO (user 7,
Admin Klinik, LDK2). TKM1, ATG3 and SUN4 have no Admin Klinik account.

The owner's decision for this wave is that Super Admin operates the three
unstaffed branches. This is recorded, not inferred:

| Branch | Assigned migration operator |
|---|---|
| LDK2 | Yuni FO (Admin Klinik) |
| TKM1 | Super Admin |
| ATG3 | Super Admin |
| SUN4 | Super Admin |

Separation of duties is unaffected and still enforced per document: the
publisher must differ from the maker (`LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`),
so Supervisor RME publishes what Super Admin stages. Account separation is what
the application can verify; **human** separation is a staffing control the
application cannot see and never claims to.

**Standing follow-up (not a blocker for this sprint):** give TKM1, ATG3 and SUN4
their own Admin Klinik operator accounts and reassign, so clinic archives are
migrated by clinic staff rather than by the governance account.

### Activation does not migrate anything

Activating the wave imports no patient data. It opens the gate; a human still
uploads each document, a reviewer still reviews it and a separate publisher still
publishes it. Nothing scans storage, and nothing ingests on its own.

---

## 4. Evidence

| Check | Result |
|---|---|
| `LegacyImportHubOperationalStateTest` | 17 passed |
| `LegacyRmeActivationContractTest` | 8 passed |
| `tests/Feature/LegacyImportHub` + `tests/Feature/LegacyRme` | 996 passed, 9 skipped, 0 failed |
| Mutation | 16 attempted, 15 killed, 1 equivalent, **0 real survivors** |
| `sprint:manifest-check` / `sprint:scope-audit` | GO |
| `foundation:devflow-check` / `foundation:shared-service-audit` | GO |
| `pint --dirty`, `git diff --check` | clean |

### Mutation detail

The first mutation run reverted with `git checkout --`, which cannot restore an
**untracked** file and silently reverted uncommitted work in a tracked one. Its
per-mutation verdicts were therefore contaminated and are discarded. The harness
was rewritten to revert by file copy and re-run from a verified-green baseline.

That re-run surfaced two survivors, and only one of them was a real gap:

- **M9 — admission allowlist bypassed: SURVIVED, then closed.** Every existing
  test kept the admitted set and the approved set identical, which let the
  approval-coverage check mask a broken allowlist. The uncovered case is the
  staged rollout an owner actually performs: approve the whole wave up front,
  admit its branches one at a time. A branch that is *approved but not yet
  admitted* must still be refused. Test added; M9 now KILLED.
- **M10 — MAIN forbidden-list check removed: SURVIVED, equivalent mutant.** MAIN
  is guarded twice — the explicit check in `decide()` and a filter inside
  `admittedBranchCodes()` — so removing one changes no observable behaviour.
  Removing **both** is KILLED, which proves the protection is genuinely covered
  rather than untested.

### Two defects found by self-review, not by the tests

Both were in this sprint's own new code, and both are now pinned:

- **The hub disclosed gate state to an actor who may not view the capability.**
  The two branches of the new gate paragraph disagreed — the closed one was
  guarded by permission status, the open one was not. Fixed in the payload
  rather than the template, so the page and its data cannot drift apart again.
- **A failure to evaluate the gates was reported as an absence of gates.** The
  hub's `catch` returned `null`, `null` means "this type has no extra gates",
  and `status()` therefore fell through to **`aktif`** — restoring the exact lie
  this sprint removes, via an exception. The catch now returns
  `LegacyRmeActivationStateService::unavailable()` (`open = false`,
  blocker `STATE_UNAVAILABLE`), with the same key shape as a real evaluation so
  no consumer has to guard for a missing key. Reverting that one line to `null`
  fails the new test, which is the proof it is load-bearing.

---

## 5. Rollback

Closing the gate is config, not code, and takes effect on the next config cache
rebuild:

```
LEGACY_RME_ADMITTED_BRANCH_CODES=
LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES=
LEGACY_RME_ADMISSION_APPROVAL_REFERENCE=
LEGACY_RME_WAVE=
```

That is a NORMAL DRAIN: new intake stops, already-staged and reviewed evidence
finishes its lifecycle, and published archives stay readable. To stop publish as
well, switch the capability off — that is the EMERGENCY STOP, with a different
blast radius. Neither deletes a document.

The hub reports the closed state as `belum_dibuka` with blocker
`NO_BRANCH_ADMITTED`, which is the honest resting state rather than a fault.
