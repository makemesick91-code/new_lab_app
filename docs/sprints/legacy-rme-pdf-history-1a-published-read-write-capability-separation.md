# LEGACY-RME-PDF-HISTORY-1A — Published Legacy Clinical Read Availability & Read/Write Capability Separation

**Type:** corrective, additive · **Module:** LegacyRme
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Builds on:** LEGACY-RME-PDF-HISTORY-1 (GO `7963fec`), LEGACY-RME-PDF-ROLL-3 (GO `6d4850f`) — both immutable and untouched.

---

## 1. The problem

HISTORY-1 shipped the unified patient-centric clinical history and proved that an
empty branch-admission set still let an authorized reader see already-published
evidence. But one coupling survived: the master feature flag
`rme.legacy_pdf_archive` also gated **reading**.

So the production posture the owner actually wants —

```
new legacy ingestion  = OFF
branch admission      = EMPTY
```

— also made every published archive invisible. A doctor could not read a
patient's legitimate archived RME at the next visit unless the owner re-opened
the whole migration capability. Restoring the read by flipping the flag to
`true` would have re-enabled upload/publish as a side effect, which is exactly
what must not happen.

## 2. The owner decision

> Existing PUBLISHED Legacy RME must remain readable by an authorized doctor
> even while legacy migration/import capability is OFF.

Canonical separation:

```
MIGRATION / INGESTION / WRITE        ≠        PUBLISHED CLINICAL READ
```

## 3. What changed

Deliberately **no** new feature flag. Adding a second broad switch would have
recreated the same hazard in mirror image (a `false` that silently hides
clinical evidence). Published read is instead governed by what already governed
it correctly: the record's state plus canonical authorization.

| File | Change |
|---|---|
| `app/Modules/LegacyRme/Support/LegacyRmeFeatureGuard.php` | Canonical `migrationEnabled()` / `assertMigrationEnabled()`; `enabled()` / `assertEnabled()` retained as aliases for the readiness reporting surface. Docblock states the flag governs migration/write ONLY and names the read-containment mechanisms. |
| `app/Modules/LegacyRme/Controllers/LegacyRmeRecordController.php` | `show`, `source`, `page`, `print`, `export` no longer consult the capability. `void` (the one mutation here) keeps it, via the renamed `assertMigrationCapabilityEnabled()`. |
| `app/Modules/LegacyRme/Services/LegacyRmePatientHistoryService.php` | Dropped the capability check and the now-unused guard dependency. |
| `app/Modules/LegacyRme/Controllers/LegacyRmeImportController.php` | Gate renamed to `migrationEnabled()`; docblock records that the whole migration workspace (staging reads included) is correctly gated. |
| `app/Modules/LegacyRme/Requests/PublishLegacyRmeImportRequest.php`, `Services/LegacyRmeImportService.php`, `Services/LegacyRmeBranchAdmissionService.php` | Explicit `migrationEnabled()` / `assertMigrationEnabled()` at each mutation boundary. |
| `app/Console/Commands/LegacyRmeWaveStatusCommand.php` | Reports `migration_capability_enabled` **and** `published_clinical_read_available` side by side, so "migration OFF" is never read as "the archive is gone". `capability_enabled` kept for existing tooling. |
| `app/Modules/LegacyRme/Services/LegacyRmeRolloutReadinessService.php` | `effective_state` message now says *migration capability*, and says published archives stay readable. |
| `config/feature_flags.php`, `config/legacy_rme.php` | Corrected the documentation that described the flag as a master switch over read; documented the real read-containment mechanisms. |
| `resources/views/rme/visits/partials/patient-rme-clinical-history.blade.php` | Comment corrected — a legacy row appears on PUBLISHED + authorized, independently of the migration capability. |

**No migration. No new route. No new permission. No policy relaxed.**

## 4. Capability matrix

| Capability | Migration ON | Migration OFF |
|---|---|---|
| New upload | allowed (subject to admission) | **DENY** |
| Processing / retry / cancel | allowed | **DENY** |
| Review / publish | allowed | **DENY** |
| Void | allowed | **DENY** |
| Branch admission | evaluated | **DENY** (`FEATURE_DISABLED`) |
| Migration workspace (`legacy-imports.*`) | allowed | **DENY (404)** |
| PUBLISHED history row | allowed if authorized | **ALLOW if authorized** |
| PUBLISHED viewer / source / page | allowed if authorized | **ALLOW if authorized** |
| PUBLISHED print / export | allowed if authorized | **ALLOW if authorized** |
| Unauthorized access | DENY | DENY |

## 5. Read is still fully bounded

Read availability is **not** public availability. Every published read passes:

- record is `PUBLISHED` (staged / failed / cancelled never appear; `VOID` is
  excluded from active history and stops streaming its bytes)
- a named read permission (`LegacyRmeRecordPolicy::READ_PERMISSIONS`)
- server-resolved branch scope — never a request `branch_id` / `patient_id`
- for a Doctor, a real treating relationship (`DoctorPatientScopeService`);
  **same branch alone never authorizes a legacy read**
- the private disk, reachable only through policy-gated stream actions

Canonical status codes (unchanged by this sprint, inherited from 1C/1D/ROLL-2):

- **404** — outside your branch scope (cross-branch anti-enumeration)
- **403** — inside your branch but not authorized (treating-doctor gate)

## 6. Emergency semantics

Turning the migration flag off is an **emergency stop for MUTATIONS**. It does
not delete evidence and no longer hides it from authorized clinical readers.

To contain a **read** incident use the mechanisms that exist:

- revoke `view_legacy_rme_archive` / `view_legacy_rme_imports`; and/or
- **VOID** the record — bytes stop streaming immediately, the row stays auditable.

There is no separate read kill switch, and one must not be invented as a side
effect of the migration flag.

## 7. Tests

New: `tests/Feature/LegacyRme/LegacyRmePublishedReadCapabilitySeparationTest.php`
(33 tests) — the whole matrix runs with the migration capability OFF:

headline read (authorized reader; admission empty) · every mutation refused
(upload, retry, cancel, review, publish, void, workspace, branch admission) ·
evidence untouched · unauthorized negatives (no-permission, guest, cross-branch
404, same-branch non-treating doctor 403, direct-id attack) · treating doctor
allowed · VOID excluded but preserved · non-published states never shown ·
native-only unchanged · native+legacy merged newest-first · legacy-only patient ·
multi-date range vs single date · print/export authorization · zero clinical
side effects · guard contract · wave-status separation.

Four existing tests asserted the old coupling and were inverted (they were the
defect, expressed as tests):

- `LegacyRmePatientHistoryTest` — "returns nothing while the feature flag is off"
- `LegacyRmeClinicalReadPrintTest` — "answers 404 for print and export …"
- `LegacyRmeRecordViewerTest` — "hides the whole published viewer …"
- `LegacyRmePatientCentricHistoryTest` — "hides the archive everywhere …"

Mutation-side flag-off tests (review/publish, void, admission, upload
validation, rollout readiness) were left untouched and still pass.

## 8. Scope boundary

HISTORY-1A authorizes **no** rollout. Production stays:

```
FEATURE_RME_LEGACY_PDF_ARCHIVE = false
admitted branches              = []
```

Not included: ROLL-4, migration scale-up, a new wave, branch admission, bulk
import, OCR, automatic import, legacy→native conversion, clinical-history
redesign, DEVFLOW base-ref resolver work.
