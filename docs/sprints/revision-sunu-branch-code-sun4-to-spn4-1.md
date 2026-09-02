# REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1

Cabang Sunu's canonical branch code is revised from `SUN4` to **`SPN4`**.

`SUN4` becomes a deprecated historical alias: still recognised on input, never
emitted again.

- Branch: `revision/sunu-branch-code-sun4-to-spn4-1`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Rule mirror: `.cursor/rules/136-sunu-branch-code-canonical.mdc`
- Shared mechanics: `.cursor/rules/92-telkomas-branch-code-canonical.mdc` (registry-wide)
- Precedent: `docs/sprints/revision-telkomas-branch-code-tkm1-to-tlk1-1.md`

---

## 1. This is a DRIFT REPAIR, not a clean rename

The single most important finding of this sprint is that **production and the
repository did not agree**, and had not agreed for some time.

| | Cabang Sunu branch code |
| --- | --- |
| Repository / seeder / fixtures | `SUN4` |
| Production `mst_branches` id 5 | **`SPN4`** — already renamed, by hand |

`DRIFT_PRESENT = true`. The branch row had been renamed in production while the
application still declared the old code. That half-applied state was not
cosmetic; it had produced two live defects:

**Defect 1 — a patient unreachable from her own archive.** Patient id 39 still
held `DG-SUN4-2026-564`. The branch code encoded in a Nomor RM is how Legacy RME
and Legacy Odontogram derive which branch a document belongs to. `SUN4` named no
row in `mst_branches` any more, so that derivation failed closed and the
patient's legacy archive could not be reached.

**Defect 2 — an approved branch locked out of its own wave.** The ACTIVE
rollout wave (id 5) still listed `SUN4` in `approved_branch_codes`, and carried a
`SUN4` branch row and a `SUN4` operator row, while the branch master answered
`SPN4`. Admission compares the RM-derived branch code against that allowlist, so
Cabang Sunu was refused admission to the wave it had been approved for.

Cabang Telkomas was found in **precisely this state** one revision earlier. That
is no longer a coincidence to note in passing — it is the reason the mechanics
below are registry-wide and the reason a branch rename must always be planned as
possible drift repair.

---

## 2. The decision

```text
SUNU_OLD_CODE       = SUN4
SUNU_CANONICAL_CODE = SPN4

SUN4_CANONICAL = false
SPN4_CANONICAL = true

SUN4_ACCEPTED_AS_HISTORICAL_ALIAS = true
SUN4_GENERATED_FOR_NEW_DATA       = false
```

```text
INPUT SPN4     → Cabang Sunu, canonical_code = SPN4
INPUT SUN4     → deprecated historical alias → Cabang Sunu, canonical_code = SPN4
INPUT UNKNOWN  → FAIL CLOSED
```

---

## 3. Occurrence classification

Nothing was blind-replaced. Every occurrence was classified before any file was
touched, and the categories that survive are listed with the reason they do.

| Occurrence | File / table | Category | Changed? | Reason |
| --- | --- | --- | --- | --- |
| `HISTORICAL_ALIASES` map | `app/Modules/Branch/Support/BranchCodeAlias.php` | L — alias compatibility | **added** | The one place the mapping is declared |
| Canonical registry key | `database/seeders/RmeBranchSeeder.php` | A — active canonical config | **changed** | Sunu now registered under `SPN4` |
| `if ($code !== 'SUN4')` | `database/seeders/RmeBranchSeeder.php:86` | A — active canonical logic | **changed** | Live defect: the registry key became `SPN4`, so this literal would have made the restore/re-enable block dead code |
| Branch master row | `mst_branches.code` (prod id 5) | B — branch master | already `SPN4` | Renamed by hand before this sprint; migration is a no-op here and says so |
| Patient Nomor RM | `mst_patients.medical_record_number` | D — mutable production data | **migrated** | 1 row: `DG-SUN4-2026-564` → `DG-SPN4-2026-564` |
| Live wave allowlist | `ops_rme_legacy_migration_waves.approved_branch_codes` (wave 5, ACTIVE) | A — active canonical config | **migrated** | Stale spelling was locking Sunu out |
| Live wave branch row | `ops_rme_legacy_wave_branches` (wave 5) | A — active canonical config | **migrated** | Compared against RM-derived code |
| Live wave operator row | `ops_rme_legacy_wave_operators` (wave 5) | A — active canonical config | **migrated** | Same comparison |
| Terminal waves 1–4 | same tables | G — historical governance | **preserved** | A COMPLETED/CANCELLED wave records an approval that was granted and closed; it authorizes nothing further. Wave 4 still names Telkomas' deprecated code, correctly |
| `sys_audit_logs` | 2 rows | F — historical audit | **preserved** | The log states what was true when written. An audit trail edited to look tidy is false |
| `stg_legacy_patient_imports` | 2 rows | F — historical evidence | **preserved** | Records what a past import generated; its committed patient is the live row, and that one was migrated |
| Published legacy RME / odontogram evidence | `trx_rme_legacy_records`, `trx_odontogram_legacy_records` | E — immutable clinical evidence | **preserved** (0 rows held `SUN4` anyway) | The paper document really does say what it says |
| `trx_clinic_visits.visit_number` | — | E/F — issued identifier | **preserved** (0 rows) | Printed on paper already issued; nothing derives a branch from it |
| Queued job payloads | `jobs` | H — serialized work | **not touched** (0 pending) | Compatibility is handled by alias resolution after deserialization, never by editing `jobs.payload` |
| Sprint name `RME-BRANCH-SUN4` | `routes/web.php`, `BranchContext`, `UserOnlineContextService`, `RmeSmokeTestSeeder`, `tests/Pest.php`, rule 73, `CLAUDE.md` | J — rule / documentation | **preserved** | This is a historical SPRINT IDENTIFIER, not a branch code. Renaming it would falsify the record and break every cross-reference |
| Active fixtures | 9 test files | I — test / fixture | **changed** | An active fixture must not create a branch under a deprecated code |
| Alias / migration fixtures | `SunuBranchCode*Test`, Telkomas stale-env test | I — compatibility fixture | **kept `SUN4` deliberately** | `SUN4` is the subject under test there |

---

## 4. Collision checks

Both were run read-only against production before any write, and re-run
immediately before deploy.

```text
SPN4_BRANCH_COLLISION_COUNT = 0    (SPN4 is held only by branch id 5, which IS Cabang Sunu)
RM_TRANSFORM_COLLISION_COUNT = 0   (DG-SPN4-2026-564 did not exist)
SUNU_BRANCH_ID = 5                 (uniquely identified)
```

Soft deletes were audited explicitly rather than assumed: `mst_branches` uses
`deleted_at`, and the only soft-deleted row is the unrelated `SYN4A` SATUSEHAT
rehearsal branch. The patient migration selects and collision-checks
`withTrashed()` because the Nomor RM unique index spans soft-deleted rows — a
restored patient must not resurface holding a code that names no branch.

---

## 5. What changed

**`BranchCodeAlias`** gains `SUNU_CANONICAL = 'SPN4'`, `SUNU_HISTORICAL = 'SUN4'`
and one row in `HISTORICAL_ALIASES`. That is the entire behavioural change: the
class is already the single chokepoint every consumer reads, so one map entry
propagated to all of them without a new conditional anywhere.

The consumers that inherited it automatically:

- `PatientMedicalRecordNumberService` — `branchCodeFrom()`, `canonicalizeBranchCode()`, `equivalentNumbers()`
- `LegacyRmeBranchAdmissionService` — wave admission, both sides canonicalized
- `config/legacy_rme_rollout.php` — env-declared allowlists
- `RmeBranchSeeder` — duplicate-branch prevention

**RM lookup surfaces (`RM_LOOKUP_SURFACES_FOUND = 5`).** All five reach
`equivalentNumbers()` → `BranchCodeAlias::equivalentCodes()`, so old-card
compatibility was inherited rather than re-implemented, and all five are pinned:

1. `LegacyOdontogramPatientRepository` — legacy odontogram intake
2. `LegacyRmePatientResolutionAuditService` — legacy RME identity binding
3. `PatientRepository::paginate` — patient directory
4. `PatientRepository::searchSelectable` — selectable combobox
5. `CrossBranchPatientLookupService` — New Visit global lookup

**Migration** `2026_09_02_100001_revise_sunu_branch_code_sun4_to_spn4.php`:
collision-checked, transactional, fail-closed, idempotent, deliberately
irreversible. Targets are enumerated — `mst_branches.code`,
`mst_patients.medical_record_number`, and live rollout rows only. Nomor RM values
go through the parser/composer, never a string replacement, so only the
branch-code segment can change and `DG-LDK2-2026-SUN4` (a sequence that merely
contains the token) is left alone.

`ROLLBACK_STRATEGY`: restore from the pre-deploy backup, not `down()`. Production
has issued `SPN4` since before this shipped, so nothing in the data distinguishes
"SPN4 because migrated" from "SPN4 because created that way"; a reversal would
silently file new patients under a branch code that no longer exists.

**Why the migration is self-contained rather than sharing a helper with the
Telkomas one.** Both migrations run on every fresh database, so a shared class is
tempting. It was not taken: the domain logic they both need is ALREADY
centralised (`BranchCodeAlias`, `PatientMedicalRecordNumberService`,
`LegacyRmeWaveStatus`), and what remains is orchestration naming its own targets.
A migration that states its targets explicitly is auditable years later; one that
delegates them to a class that has since evolved is not.

**CI selection.** Both suites are declared in
`ci_runner.critical_gate_mandatory_suites` AND a `SunuBranchCode` token was added
to **both** critical-gate filter variants. The registered-but-unselected state was
caught by `CriticalGateSuiteCoverageTest` failing — declaring a suite is not the
same as the gate running it.

---

## 6. Explicitly NOT changed

```text
IMMUTABLE_CLINICAL_FILE_CONTENT_REWRITTEN = false
AUDIT_HISTORY_REWRITTEN                   = false
HISTORICAL_VISIT_IDENTIFIERS_REWRITTEN    = false
HISTORICAL_GOVERNANCE_REWRITTEN           = false
QUEUE_PAYLOAD_MANUAL_REWRITE              = false
REQUEST_BRANCH_AUTHORITY                  = false
```

No clinical PDF or image was opened or rewritten to remove a literal `SUN4`. An
archived document that says `DG-SUN4-…` remains historically truthful;
reachability comes from alias-aware resolution, not from editing evidence.

---

## 7. Verification

- `SunuBranchCodeAliasTest` — alias policy, RM generation/transform, RM-derived
  branch resolution, old-card lookup across all five surfaces, seeder identity,
  rollout admission including the production shape (stale allowlist).
- `SunuBranchCodeMigrationTest` — in-place rename, the production half-migrated
  shape, soft-deleted patients, both fail-closed collisions, idempotency, visit
  numbers preserved, no clinical/financial side effects, live-vs-terminal wave
  scoping, audit trail untouched.

Both re-run the real migration file against a table put back into the
pre-migration state, so they exercise the artifact rather than a
re-implementation, and prove idempotency as a side effect.
