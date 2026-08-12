# LEGACY-RME-PDF-ROLL-3 — Multi-Branch Controlled Migration Rollout

**Branch:** `feature/legacy-rme-pdf-roll-3-multi-branch-controlled-migration`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Immutable prerequisite:** `legacy-rme-pdf-roll-2-controlled-pilot-enablement-go` @ `3e9a06d92f6e4c9a3cc22ace46e0a759a4b75e77`

> ROLL-2's GO tag is **not** reopened, moved or re-cut by this sprint.

---

## 1. Prerequisites (verified from the repository, not from memory)

| Sprint | GO tag | Status |
|---|---|---|
| LEGACY-RME-PDF-1A | `legacy-rme-pdf-1a-schema-permission-date-rules-go` | present |
| LEGACY-RME-PDF-1B | `legacy-rme-pdf-1b-private-upload-rendering-go` | present |
| LEGACY-RME-PDF-1C | `legacy-rme-pdf-1c-controlled-publish-patient-history-go` | present |
| LEGACY-RME-PDF-1D | `legacy-rme-pdf-1d-void-clinical-read-print-go` | present |
| LEGACY-RME-PDF-ROLL-1 | `legacy-rme-pdf-roll-1-feature-flag-runtime-readiness-go` | present |
| LEGACY-RME-PDF-FIX-ROLL2-1 | `legacy-rme-pdf-fix-roll2-1-eligibility-multidate-rm-branch-go` | present |
| LEGACY-RME-PDF-ROLL-2 | `legacy-rme-pdf-roll-2-controlled-pilot-enablement-go` | `tag` object, peels to `3e9a06d9…`, ancestor of base |

---

## 2. Business goal

Extend the **single** proven controlled pilot into a **branch-by-branch** migration
capability that is observable, reversible and impossible to widen by accident.

## 3. Non-goals

ROLL-3 is explicitly **not**:

- enabling Legacy import for every branch,
- permanent global capability ON,
- bulk or unattended migration,
- automated PDF discovery, OCR-derived dates, or auto-publish,
- any change to native RME, billing, consent, odontogram, lab or SATUSEHAT flows.

---

## 4. The defect ROLL-3 closes

ROLL-2 declared its pilot branch in `config/legacy_rme_rollout.php` as
`pilot_scope.branch_code` — a **single scalar string**.

A repository-wide search (`app/`, `routes/`, `tests/`) found that key read by
**exactly one** consumer: `LegacyRmeRolloutReadinessService`, which is a *report*.

`LegacyRmeImportService::createFromUpload()` enforced only
`LegacyRmeFeatureGuard::assertEnabled()` — the **global** capability — plus the
RM-derived branch resolution from FIX-ROLL2-1.

**Consequence:** with the capability ON, *every* active RME-enabled branch could
import. The single-branch pilot held because the operator chose not to touch the
others. A rollout that depends on discipline is not a controlled rollout.

---

## 5. Architecture — capability and admission are separate concerns

```
CAPABILITY   rme.legacy_pdf_archive        → is the archive runtime on at all?
ADMISSION    legacy_rme_rollout.admission  → may THIS branch start new work?
```

Both must pass. Capability ON with an empty allowlist migrates nothing.

### What is checked

Always the branch **derived from the patient's Nomor RM** (FIX-ROLL2-1):

```
DG-TKM1-2024-9985 → TKM1 → Cabang Telkomas → admitted?
```

`LegacyRmeBranchAdmissionService::decide()` accepts a resolved
`LegacyRmeBranchResolution` and nothing else. No request body, query string,
session, `BranchContext` selection or operator branch can influence it.

### Decision codes (stable; callers branch on the code, never the message)

| Code | Meaning |
|---|---|
| `ADMITTED` | cleared to start new migration work |
| `FEATURE_DISABLED` | global capability off — nothing, anywhere |
| `NO_BRANCH_ADMITTED` | enforcement on, allowlist empty (fail-closed default) |
| `BRANCH_NOT_ADMITTED` | resolved, but not in this wave |
| `BRANCH_FORBIDDEN` | MAIN — never a clinical migration branch |
| `BRANCH_UNRESOLVED` | no branch derivable from the Nomor RM |

### The wave's own approval (corrective, found at the Wave-1 checkpoint)

An admitted set is only authorized when the **current wave** has an approval
record that covers it. Two config values, both captured at config-build time:

| Key | Env | Meaning |
|---|---|---|
| `admission.approval_reference` | `LEGACY_RME_ADMISSION_APPROVAL_REFERENCE` | non-PHI governance reference for this wave |
| `admission.approved_branch_codes` | `LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES` | the exact branch set that approval covers |

Rule: a non-empty admitted set requires a non-blank reference **and** every
admitted branch must appear in the approved set. Otherwise the branch is refused
at runtime with `WAVE_NOT_APPROVED`, and `branch_admission` FAILs the gate.

**Why the scope binding, not just a non-empty string.** A reference alone proves
only that *some* approval exists. Adding a fourth branch to a three-branch wave
would silently inherit the older, narrower decision. Recording the approved
scope makes that drift fail closed: the new branch is refused while the genuinely
approved ones keep working.

**What this fixed.** At the Wave-1 checkpoint, production carried ROLL-2's
approval (`ROLL-2-OWNER-APPROVAL-2026-08-11`, branch `TKM1`) while Wave-1 was
about to admit `ATG3`/`LDK2`/`SUN4`. Enforcement would have been correct, but the
readiness gate would have printed **GO** beside an approval record naming a
branch that was not in the wave — the same "config nobody enforces" defect ROLL-3
exists to remove, one level up. ROLL-2's `pilot_scope` is left **untouched** as
historical evidence and is never consulted by the admission gate.

### Storage — config/env allowlist (ROLL-1 capture pattern)

`env()` is read **only** while `config/legacy_rme_rollout.php` is built, so the
value bakes into the cached array and survives `config:cache`. No service reads
the environment at runtime — a test asserts the service source contains no
`env(` call.

The list is normalized **once**, at config-build time, into canonical upper-case
tokens. Matching is **exact-token**:

| Admitted | Candidate | Result |
|---|---|---|
| `TKM1` | `TKM1` | admitted |
| `TKM` | `TKM1` | **denied** |
| `TKM1` | `TKM` | **denied** |
| `TKM1` | `TKM1-EXTRA` | **denied** |
| `TKM1-EXTRA` | `TKM1` | **denied** |
| `TKM1` | `tkm1` | admitted (case normalized, not loosened) |

A substring or prefix must never widen a clinical rollout.

---

## 6. Two separate operational controls

These are **not** interchangeable, and the runbooks keep them apart.

### NORMAL DRAIN — routine wave rollback

Remove a branch code from `LEGACY_RME_ADMITTED_BRANCH_CODES`, rebuild the config
cache.

- **Stops:** new uploads for that branch; retry re-queues for that branch.
- **Does not stop:** an already staged, human-reviewed import from being
  published. Publish still runs every canonical revalidation — permission,
  patient, RM-derived branch, date range, native boundary, state machine.
- **Never** deletes or corrupts evidence, and never touches a queued job.

Rationale: stranding reviewed clinical evidence in staging indefinitely is a
worse outcome than letting an already-admitted document finish its lifecycle.

### EMERGENCY STOP — incident containment

Set the capability flag off (`FEATURE_RME_LEGACY_PDF_ARCHIVE=false`), rebuild the
config cache.

- Withdraws **everything**, publish included; the operator surface 404s.
- The admission decision reports `FEATURE_DISABLED`, not a wave code, so an
  operator can tell an incident stop from a routine rollback.

**Draining a branch is not an incident-containment mechanism and must never be
used as one.**

---

## 7. Ingestion capacity (backpressure)

Multi-branch migration multiplies render load onto one worker. Admission also
closes when the pipeline is saturated:

| Threshold | Default | Code |
|---|---|---|
| pending jobs on the render queue | 25 | `CAPACITY_QUEUE_DEPTH` |
| age of the oldest pending job | 1800 s | `CAPACITY_QUEUE_STALLED` |
| free space on the private disk | 2 GiB | `CAPACITY_DISK_LOW` |

Set a threshold to `0` to disable that probe.

- Throttles **new intake only**. Nothing in flight is cancelled, re-prioritised
  or re-queued — a saturated pipeline is a reason to stop adding work, never a
  reason to operate on live clinical jobs.
- Reopens by itself as the queue drains; there is no latch to clear by hand.
- An unmeasurable probe (non-database queue driver, unreadable disk) reports
  `null` rather than faking a depth of zero.

---

## 8. Queue / worker contract (inherited, unchanged)

Producer `legacy-rme-documents` = approved ENT-5 registry = the **installed**
systemd unit the readiness gate reads. `failed_jobs = 0` alone is still not
proof of a healthy pipeline. ROLL-3 adds no queue and renames nothing.

---

## 9. Operator SOP

1. Confirm the branch is admitted — `php artisan legacy-rme:wave-status`.
2. Search the patient by Nomor RM.
3. Confirm the branch the screen resolved from the RM (there is no branch picker).
4. Review the historical PDF by eye; determine the **earliest** and **latest**
   clinical dates written on it.
5. Upload; wait for `QUEUED → PROCESSING → READY_FOR_REVIEW`.
6. Review every rendered page against the document.
7. Publish. Verify it appears in the patient's history under the right branch.
8. Correction is **VOID + fresh import** — never an in-place edit.
9. Never hand-edit a queue row or an import status.

### Human date limitation (unchanged, deliberate)

There is **no OCR and no automated clinical-date verification**. The operator is
responsible for declaring the dates visible on the document. ROLL-3 improves the
guidance around that decision; it does not automate it, and no part of this
sprint should be read as claiming it does.

---

## 10. Security posture

- Admission is server-side; the UI panel is presentation only and `store()`
  re-decides.
- A submitted `origin_branch_id` that disagrees with the RM-derived branch is
  rejected (FIX-ROLL2-1), and admission is evaluated on the derived value.
- Super Admin's `Gate::before` grants permissions; it does **not** bypass the
  RM-derived branch or the admission domain invariant.
- Doctor access remains `DoctorPatientScope`-limited; ROLL-3 widens no read scope.
- Audit payloads stay structure-only. Two new PII-free events:
  `LEGACY_RME_IMPORT_ADMISSION_REJECTED`, `LEGACY_RME_INGESTION_THROTTLED`.
  New allow-listed keys: `wave`, `pending_jobs`.

---

## 11. Readiness gate additions

`legacy-rme:rollout-readiness` gains two checks:

- **`branch_admission`** — FAIL if enforcement is off on a deployment that runs
  real migrations, if a forbidden branch is declared, or if an admitted code is
  not an active RME-enabled branch. WATCH if the capability is on with nothing
  admitted. GO for the closed pre-wave state.
- **`ingestion_capacity`** — WATCH if backpressure is disabled, if every
  threshold is off, or if the pipeline is currently saturated. GO otherwise.
  Saturation is deliberately not a deploy blocker: it is transient.

---

## 12. Files

**New**

- `app/Modules/LegacyRme/Services/LegacyRmeBranchAdmissionService.php`
- `app/Modules/LegacyRme/Services/LegacyRmeIngestionCapacityService.php`
- `app/Modules/LegacyRme/Support/LegacyRmeAdmissionDecision.php`
- `app/Modules/LegacyRme/Support/LegacyRmeIngestionCapacity.php`
- `app/Console/Commands/LegacyRmeWaveStatusCommand.php`
- `tests/Feature/LegacyRme/LegacyRmeBranchAdmissionTest.php`
- `tests/Feature/LegacyRme/LegacyRmeIngestionCapacityTest.php`
- `tests/Feature/LegacyRme/LegacyRmeMultiBranchRolloutTest.php`
- `.cursor/rules/96-legacy-rme-multi-branch-controlled-rollout.mdc`
- this document

**Modified**

- `config/legacy_rme_rollout.php` — `admission` + `capacity` blocks, config-build normalization
- `app/Modules/LegacyRme/Services/LegacyRmeImportService.php` — ingestion + retry gates
- `app/Modules/LegacyRme/Services/LegacyRmeRolloutReadinessService.php` — 2 checks
- `app/Modules/LegacyRme/Support/LegacyRmeAuditEvent.php` — 2 events, 2 keys
- `app/Modules/LegacyRme/Controllers/LegacyRmeImportController.php` — admission on create
- `resources/views/settings/rme/legacy-imports/create.blade.php` — admission panel
- `tests/Pest.php` — `legacyRmeAdmitBranch()`, `legacyRmeAdmittedBranches()`
- `docs/runbooks/legacy-rme-pdf-rollout-runbook.md`

**No migration. No new route. No new permission. No schema change.**

---

## 13. Local evidence

| Gate | Result |
|---|---|
| `tests/Feature/LegacyRme` (SQLite) | **416 passed**, 2 skipped, 1144 assertions |
| `tests/Feature/LegacyRme` (**PostgreSQL 16.14**, pinned container) | **416 passed**, 2 skipped |
| Poppler integration (real `pdfinfo`/`pdftoppm` 26.01.0) | 5 passed |
| Rollout readiness (30 pre-existing + 2 new checks) | 30 passed |
| `legacy-rme:wave-status --json` on PostgreSQL | correct closed state |
| Pint | clean |

Test-fixture note: `legacyRmeBranch()` now also admits the branch it creates, so
admission enforcement stays **ON for the whole suite**. Disabling the gate in
`phpunit.xml` was rejected: it would leave every ingestion test silently
bypassing the gate, which is how ROLL-2 shipped an advisory pilot scope.

---

## 14. What ROLL-3 GO does and does not authorize

**Does:** the server-side capability to run a controlled, reversible,
branch-by-branch migration wave, with the admitted set named explicitly.

**Does not:** all branches; permanent global capability ON; bulk or unattended
migration; automated discovery; OCR date authority; auto-publish; conversion to
native RME; billing; SATUSEHAT submission; bypass of human document review,
branch admission, or `DoctorPatientScope`.

The final production state after the controlled wave is **capability OFF with no
branch admitted**, unless the owner separately and explicitly authorizes
sustained operation.
