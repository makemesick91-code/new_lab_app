# LEGACY-RME-PDF-ROLL-2 — Controlled Pilot Enablement & Operational Readiness

**Branch:** `feature/legacy-rme-pdf-roll-2-controlled-pilot-enablement`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `61806ff`
**Prerequisites:** LEGACY-RME-PDF-1D GO (`61806ffe34eabaf823525813d5ae56a483b97ffa`), LEGACY-RME-ROLL-1 GO (`9e90c7bb2bf59fc4ddd966df6dfcc8b52b9c8e4b`)
**GO tag:** `legacy-rme-pdf-roll-2-controlled-pilot-enablement-go`
**Runbook:** `docs/runbooks/legacy-rme-pdf-rollout-runbook.md`
**Rule mirror:** `.cursor/rules/94-legacy-rme-controlled-rollout.mdc`

---

## 0. STATUS — PAUSED before the clinical pilot

The pre-pilot ROLL-2 work is merged, deployed and smoke-verified, and the ON/OFF
rollback mechanism is proven. The **clinical pilot itself has not run**: the
feature is OFF in production and no ROLL-2 GO tag exists.

**Blocked pending LEGACY-RME-PDF-FIX-ROLL2-1.** Preparing the real pilot document
for RM `DG-TKM1-2024-9985` exposed three domain defects that would have made the
pilot either impossible or wrong: a patient with no native RME was refused, a
multi-date document had no defined representative date (and its later dates were
never checked against the native bound), and the archive's branch was operator
input rather than derived from the patient's own Nomor RM.

ROLL-2 may resume only from a base containing the corrective's GO tag
`legacy-rme-pdf-fix-roll2-1-eligibility-multidate-rm-branch-go`. See
`docs/sprints/legacy-rme-pdf-fix-roll2-1-eligibility-multidate-rm-branch.md`.

Post-corrective pilot expectations:

| | |
|---|---|
| RM | `DG-TKM1-2024-9985` |
| Resolved branch | `TKM1` / Cabang Telkomas (derived, not chosen) |
| Native RME | none — **valid** for legacy migration |
| PDF clinical dates | 28-01-2024, 31-08-2024 |
| `selected_rme_date` | **28-01-2024** (earliest) |
| `latest_rme_date` | 31-08-2024 |

A corrective GO **unblocks** ROLL-2; it is not itself a pilot GO. Enabling the
flag and uploading the document remain separate, user-controlled steps.

---

## 1. Objective

1A–1D delivered the legacy RME archive runtime; ROLL-1 made its feature flag's
runtime override survive `config:cache`. None of them answered the operational
question: **may this deployment switch the archive ON, and can it be switched
back OFF?**

ROLL-2 makes that question machine-answerable and fails closed on it. The
archive remains OFF by default; enablement becomes an explicit decision taken
against a green readiness report rather than a side effect of a deploy.

## 2. Non-goals

- No new clinical capability. The import → review → publish → VOID lifecycle is
  exactly what 1A–1D shipped.
- No schema change, no migration, no new permission, no new route.
- No automatic enablement. The gate reports; it never flips the switch.
- No authorization to widen the rollout beyond a single approved pilot scope.

## 3. What shipped

| Artifact | Purpose |
|---|---|
| `config/legacy_rme_rollout.php` | The rollout contract: required tables, permissions, routes, binaries, queue posture, storage posture, rollback contract and the pilot-scope approval gate |
| `App\Modules\LegacyRme\Services\LegacyRmeRolloutReadinessService` | Read-only, per-check-guarded readiness engine returning GO / WATCH / NO_GO |
| `App\Modules\LegacyRme\Support\LegacyRmeRolloutCheck` | Immutable finding value object; context is PHI-free by contract |
| `php artisan legacy-rme:rollout-readiness` | Operator gate — `--json`, `--strict`, `--expect=off\|on` |
| `docs/runbooks/legacy-rme-pdf-rollout-runbook.md` | Enablement, pilot, correction and rollback procedure |
| `.cursor/rules/94-legacy-rme-controlled-rollout.mdc` | Durable rules |
| `tests/Feature/LegacyRme/LegacyRmeRolloutReadinessTest.php` | 22 tests |

### Metadata correction

The flag's `review_target` still read `LEGACY-RME-PDF-1C` after 1D shipped, and
its description never mentioned VOID, the doctor viewer or export. Both are
corrected. This is the same drift class that already required a follow-up PR
during 1C, so the gate now checks it: `flag_metadata_current` compares the
registry's `review_target` against `legacy_rme_rollout.delivered_sprint` and
reports WATCH on divergence. The rot is now caught by a command instead of by a
reader.

## 4. The readiness checks

| Check | Blocks on |
|---|---|
| `feature_flag_registered` | Flag absent, or contract auditing a different key than the runtime reads |
| `runtime_override_capture` | ROLL-1 regression — a declared override key not captured at config-build time is ignored under a cached config, breaking enablement *and* rollback |
| `effective_state` | Guard/registry disagreement, or a mismatch against `--expect` |
| `flag_metadata_current` | Governance record stale relative to the delivered runtime (WATCH) |
| `schema_ready` | Any of the four archive tables missing |
| `permissions_registered` | Any of the six named permissions missing |
| `routes_registered` | Any of the 17 legacy routes unregistered (usually a stale route cache) |
| `private_disk_configured` | Disk undefined, publicly exposable, non-private or framework-served |
| `private_disk_writable` | Non-destructive write/read/delete probe fails |
| `poppler_available` | `pdfinfo` / `pdftoppm` absent — imports would stall in `PROCESSING` |
| `queue_contract` | A worker-expecting environment on a `sync` connection, which would render inline in the upload request |
| `rollback_contract` | No documented rollback action, or the runbook is missing |
| `pilot_scope_approved` | **No approved pilot scope** — the decisive gate |

`FAIL` and `UNKNOWN` both yield `NO_GO`. An unevaluated check is never treated
as ready.

## 5. Pilot scope approval

Recorded as environment settings, never inferred:

- `LEGACY_RME_PILOT_APPROVED`
- `LEGACY_RME_PILOT_APPROVAL_REFERENCE` (non-PHI governance reference)
- `LEGACY_RME_PILOT_BRANCH_CODE` (single branch; `MAIN` is refused; the branch
  must be active and RME-enabled on this deployment)

Deliberately **not** stored: patient identifier, document path, any name.
Patient ownership and the historical-date rule are enforced per import by the 1A
date-rule service and revalidated by the 1C publish — a per-patient decision
belongs there, not in config.

## 6. Architecture

Command (thin) → Service → existing shared foundations. The readiness service
reuses `FeatureFlagService`, `LegacyRmeFeatureGuard` and `BranchService` rather
than re-deriving flag resolution or the RME branch set. No repository or model
was added; nothing is written except the self-cleaning storage probe.

## 7. Safety properties (test-pinned)

- Fails closed on unapproved, incomplete, `MAIN`, and non-RME pilot scope.
- Refuses a publicly exposable or undefined archive disk.
- Blocks a `sync` queue in a worker-expecting environment.
- Blocks an uncaptured runtime override (ROLL-1 regression guard).
- Proves the flag state in both directions via `--expect`.
- Storage probe leaves nothing behind.
- Report contains no patient identifier, no KTP/NIK-shaped 16-digit run.
- `UNKNOWN` blocks; WATCH exits zero by default and non-zero under `--strict`.

## 8. Validation

```
tests/Feature/LegacyRme/LegacyRmeRolloutReadinessTest.php   22 passed
```

## 9. Deploy notes

No migration and no seeding are required — ROLL-2 adds no schema, permission or
route. After deploy, verify on the VPS as the runtime user:

```bash
runuser -u www-data -- php artisan legacy-rme:rollout-readiness --expect=off --strict
```

The feature stays OFF. Enablement follows the runbook only, and only against an
approved pilot scope.

## 10. What ROLL-2 GO does not authorize

All-branch rollout, unrestricted uploads, bulk or background historical
migration, permanent production enablement, bypassing manual review, converting
the archive into native RME, SATUSEHAT submission of legacy data, automated
VOID, or deleting prior evidence. Widening is a separate approved stage.