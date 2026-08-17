# LEGACY-RME-STEADY-STATE-OPS-1 — Operationalization, SOP, Monitoring & Multi-Branch Routine Migration

**Branch:** `feature/legacy-rme-steady-state-ops-1-operationalization-multi-branch-routine-migration`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `126ea7617407247738db1d1217fdcab83a1e30e4` (CICD-BASELINE-REVERIFY-1)
**Type:** MODULE_SPRINT · **Module:** LegacyRme
**GO tag:** `legacy-rme-steady-state-ops-1-operationalization-multi-branch-routine-migration-go`

---

## 1. What this sprint is, and what it deliberately is not

Legacy RME migration had every control it needed and no operating model. This
sprint converts it from *an engineering wave per batch* into *routine production
operations*.

**No control changed.** Capability, admission, capacity backpressure, quota,
operator assignment, pause/drain, reconciliation, completion sign-off,
source-RM binding, the clinical date rules and separation of duties all behave
exactly as they did on the base commit. Nothing was widened, relaxed or
bypassed. A reader auditing that claim only has to check that this sprint adds no
mutating code path — it adds none.

**Not delivered, on purpose:**

| Not done | Why |
|---|---|
| A new lifecycle / state machine / table for "routine batches" | It would be a second, weaker path to the same clinical writes. A routine batch **is** the existing ROLL-4 wave record. |
| Concurrent batches | Unsupported by `LegacyRmeWaveBindingService`, and no demonstrated need. Adding it would be a material scope expansion. |
| Wave-3 | `ROLL-4-WAVE-3` remains **SKIPPED / NOT REQUIRED**. No record, no approval, no quota, no tag. It is now also *refused* as a declared batch code. |
| Flipping `require_separate_approver` on | Documented, deliberately accepted risk: only one staffed governance account exists. Turning it on would break the operations this sprint makes routine. Reported as a WARNING instead. |
| A migration UI surface | CLI + docs only. No route, no view, no permission — nothing new to authorize or attack. |
| A live production batch | No genuine owner-approved candidate set existed during this sprint. See §7. |

---

## 2. The gap analysis that shaped the scope

Inventory of what already existed on the base commit:

| Capability | Already shipped as |
|---|---|
| Batch lifecycle (register/approve/activate/pause/resume/drain/cancel/complete, + per-branch) | `legacy-rme:wave-admin`, dry-run by default |
| Import lifecycle recovery | `legacy-rme:import-admin {cancel,review,publish,retry}` |
| Operations monitoring | `legacy-rme:migration-status --json --strict` |
| Batch/branch monitoring | `legacy-rme:wave-status --json` |
| Deployment readiness (18 checks) | `legacy-rme:rollout-readiness` |
| Source-RM binding diagnosis | `legacy-rme:source-rm-binding-check` |
| Patient-master integrity | `legacy-rme:patient-resolution-audit` |
| Quota, reconciliation, completion invariants | `legacy_rme_operations.php` + services |
| Capacity backpressure | `legacy_rme_rollout.capacity` |
| SOD (separate publisher) | `SeparatePublisherGuard`, production invariant |
| Operations SOP | `legacy-rme-migration-operations-runbook.md` (509 lines) |

**So most of the requested capability already existed.** Three genuine gaps
remained, and only these were built:

1. **Backup freshness was never gated.** The 18-check readiness gate did not ask
   whether a restore point existed. The runbook said to take one; nothing verified
   it. A batch mutating clinical staging state without a verified backup is the one
   failure this programme cannot walk back.
2. **Resting state was prose, not an assertion.** Runbook §0 described it; a human
   had to read three reports to confirm it.
3. **No per-branch readiness view.** Admission config was reported; "is *this*
   branch ready to migrate right now, and if not why" was not.

Plus the operating model itself: what a routine batch *is*, how big it may be,
what multi-branch actually supports, and one pre-flight instead of four reports
correlated by eye.

---

## 3. What was added

### Config — `config/legacy_rme_steady_state.php`

The steady-state operating contract. Read-only by construction: every value is
consumed by a *reporting* service and by documentation. Deleting the file would
not admit a single document that is not admissible today.

Declares: routine-batch required properties · naming scheme · retired codes ·
sizing envelope · resting-state assertion set · multi-branch classification ·
backup freshness window · stop-the-line codes · severity model · governance
(routine batch ≠ GO tag).

### Service — `LegacyRmeSteadyStateOpsService`

A read-only aggregator. It **composes** the existing services and adds nothing
that could admit a document:

- `LegacyRmeRolloutReadinessService` → deployment decision, inherited verbatim
- `SeparatePublisherGuard::enabled()` → SOD posture, **through the guard, never
  its config key** (SOD-1 gives that switch exactly one home, and an
  architecture test enforces it — see §6)
- `LegacyRmeBranchAdmissionService` → admission vs approval
- `LegacyRmeWaveBindingService` → batch binding
- `LegacyRmeMigrationReconciliationService` → ledger balance
- `LegacyRmeMigrationQuotaService` → sizing
- `LegacyRmeIngestionCapacityService` → queue headroom
- `FoundationMonitoringStatusService` (MON-1 / ENT-12) → **backup freshness**
- `ClinicalClock` → the clinic's own calendar
- `BranchService::listRmeEnabled()` → branch matrix

Eleven guarded checks. A check that throws becomes `UNKNOWN`, and `UNKNOWN`
blocks exactly like `FAIL`.

### Command — `legacy-rme:ops-readiness`

```
--json  --strict  --fail-on-warning  --skip-monitoring  --branch=CODE
```

| Exit | When |
|---|---|
| `0` | GO, or WATCH without `--strict` |
| `1` | NO_GO — always |
| `1` | WATCH under `--strict` / `--fail-on-warning` |

### Documentation

- `docs/runbooks/legacy-rme-steady-state-operations-runbook.md` — the SOP
- `docs/runbooks/legacy-rme-routine-batch-operator-checklist.md` — one page
- `docs/evidence/legacy-rme/routine-batch-evidence-template.md` — PII-free record
- `.cursor/rules/103-legacy-rme-steady-state-operations.mdc` — AI mirror

Decisions are recorded here rather than in a separate ADR, deliberately: a second
document restating the same rules is how two sources of truth drift apart.

### Visual reference

A PII-free explanatory page covering the batch lifecycle, multi-branch
concurrency, the branch readiness matrix, the severity vocabulary, the
maker/checker control and the incident flow. Published outside the repository as
an artifact and referenced from the runbook. **Explanatory only** — the
authoritative evidence is the application's own command output.

---

## 4. Decisions worth recording

**Backup freshness reuses the MON-1 signal rather than re-probing.** A second
implementation of "is the backup fresh?" would be free to disagree with the
first, and an operator would have no way to know which was right.

**Monitoring signals are collected by default.** The first implementation gated
them behind `--include-monitoring`, which meant the *default* invocation could
never return GO. A pre-flight whose default answer is "I did not check the one
thing that cannot be walked back" is a pre-flight nobody trusts. `--skip-monitoring`
now opts out explicitly, and the decision honestly degrades to NO_GO when it does.

**"Not established" is a different finding from "stale".** Both block. Only an
actually missing or stale backup is a stop-the-line condition. Reporting *"I did
not look"* as a failed control would train operators to ignore the loudest signal
the report has.

**The batch naming pattern is advisory and deliberately not asserted.** A check
on it would fire permanently against the legitimate historical codes `WAVE-1`
and `WAVE-2R`, and a batch is not unsafe because it is badly named. The config
comment says so, so the contract matches the behaviour.

**`WAVE-3` *is* asserted.** Declaring a retired identity is not a cosmetic slip:
it means work is running under a governance identity that was explicitly closed
and whose approval record does not exist. There are no legitimate historical rows
to false-positive against, because the wave was never run.

**A closed branch is `NOT_READY` with zero blockers.** Deliberately closed is the
correct answer, not a fault — there is nothing to remediate. Conflating the two
would make the matrix unreadable at exactly the moment it matters.

**Sizing numbers are derived, not invented.** `default_daily = 25` is the
ingestion queue's own single-worker pending ceiling — beyond it uploads are
already refused by backpressure, so a larger allowance cannot make documents flow
faster. `max_daily = 100` is 4× that, bounded by human review throughput.
**Measured throughput is NOT YET AVAILABLE**: production evidence is Wave-1 (1
document) and Wave-2R (4 documents), five in total, far too small to fit a rate
to. These are bounded defaults chosen for recoverability and explicitly revisable.

**Human separation of duties is attested, never asserted.** The application
enforces *account* separation. Two different humans behind those accounts is a
governance control it cannot observe, and the report says so in a field
(`human_separation_verifiable_by_application: false`) rather than implying it
verified something it did not.

---

## 5. Multi-branch — the honest classification

| Scope | Mode | Authority |
|---|---|---|
| Across batches | **SEQUENTIAL** | `LegacyRmeWaveBindingService` resolves the operative batch from one declared code |
| Within one batch | **CONCURRENT, BRANCH-ISOLATED** | `ops_rme_legacy_wave_branches` carries per-branch status, quota, operators and transitions |

`MULTI_BRANCH_MODE = CONCURRENT_BRANCH_ISOLATED_WITHIN_ONE_ACTIVE_BATCH`.

This is a reading of the code, not an aspiration. Claiming concurrent *batches*
would be claiming a capability the binding service does not have.

---

## 6. A latent architectural guard this sprint tripped, and honoured

The first implementation read `config('legacy_rme_operations.require_separate_publisher')`
directly. `LegacyRmeSeparationOfDutiesTest` failed:

> *it gives the enablement switch exactly one home in the application*

The test scans `app/` for that literal and asserts `SeparatePublisherGuard` is the
only file containing it — because a second reader is *deciding for itself* whether
the rule applies, which is how two surfaces drift apart.

The fix was to ask `SeparatePublisherGuard::enabled()` and to rename the report's
output key to `separate_publisher_enforced`. **The guard was right and the new code
was wrong**; the invariant is now stronger for having been tested against.

---

## 7. Live batch status — reported honestly

```
LIVE_ROUTINE_BATCH = NOT_EXERCISED
REASON             = NO GENUINE OWNER-AUTHORIZED CANDIDATE SET DURING THIS SPRINT
```

No patient, document, source hash, approval or batch was fabricated to
manufacture a green line. Verification is by automated tests, read-only
production checks and safe operational drills. A live batch requires fresh owner
approval, real documents, real human source-RM and date confirmation, a real
maker and a genuinely distinct checker — absent any of those, it is not run.

---

## 8. Verification

| Suite | Result |
|---|---|
| `LegacyRmeSteadyStateOpsTest` (new) | **30 passed** |
| `tests/Feature/LegacyRme` | **801 passed, 5 skipped, 0 failed** (2212 assertions) |
| `AccessControl` + `Cicd` + `Deploy` + `Foundation` | **714 passed, 4 skipped, 0 failed** (3759 assertions) |
| `sprint:manifest-check` / `sprint:scope-audit --strict` | GO / GO (1 module) |
| `pint --dirty` · `git diff --check` | clean · clean |
| 8 governance gates | all GO |

The new suite is mostly about **refusals**, because the risk this sprint
introduces is not a missing gate — every gate already existed — but *a report that
lies*, since an operator will now act on one line instead of four. So a stale
backup, an unapproved branch, a disabled SOD invariant, a lapsed window, a retired
batch code and an unevaluated check must each be able, alone, to stop a batch.

It also asserts the report is **read-only** (migration state byte-identical
before and after) and that the approval reference is **never echoed** into output.

---

## 9. Deploy

**No migration. No seed. No permission. No route.**

```bash
# On the VPS only.
cd /var/www/asia-dental-lab-v2
bash scripts/deploy-vps-runner.sh start
```

Post-deploy verification (all read-only):

```bash
php artisan legacy-rme:ops-readiness --json
php artisan legacy-rme:rollout-readiness
php artisan legacy-rme:migration-status
```

Expected at rest: capability **OFF**, admission **EMPTY**, active batch **NONE**,
`Resting state: AT_REST`.

---

## 10. Shipped — GO evidence

```
BASE_SHA            126ea7617407247738db1d1217fdcab83a1e30e4  (CICD-BASELINE-REVERIFY-1)
FINAL_CANDIDATE_SHA 5b0960998ae3cf2fead332e254fb702f6da72224
PR                  #307
PR_CI_RUN_ID        32044939547   success (SHA-exact on the candidate)
MERGE_SHA           c5e9fe6f50072474b234c2719de853576cd3cd17
FULL_SUITE_RUN_ID   32050063749   success — FAILURES=0, 29472 assertions
RUNTIME_DEPLOYED    c5e9fe6f50072474b234c2719de853576cd3cd17
VPS_HEAD            c5e9fe6f50072474b234c2719de853576cd3cd17
GO_TAG              legacy-rme-steady-state-ops-1-operationalization-multi-branch-routine-migration-go
TAG_OBJECT_SHA      ebbed6d7f77ed96ac0647f27ade6b0988f897548  (local == remote)
TAG_PEELED          c5e9fe6f50072474b234c2719de853576cd3cd17  (== MERGE_SHA == VPS_HEAD)
```

**CI gates (both runs):** Classifier · NSF-R012 Quality · NSF-R011 Critical ·
CICD-CTRL Selective Module · NSF-9 Release Safety + Smoke · NSF-10 Release
Evidence — all `success`. Exactly one Critical Test Gate variant ran and the
other was `skipped`, which is the CICD-CTRL-1 routing working as designed.
The Full Suite Gate is `skipped` on PRs by trigger policy and ran post-merge.

**Full Suite totals read `6586 warnings, 1 risky, 13 passed (29472 assertions)`.**
That is the documented warning-downgrade phenomenon whose true cause
CICD-BASELINE-REVERIFY-1 explicitly left unidentified — **not** skips: the tests
executed and asserted, and **zero failed**. The expected-failure baseline of 0
holds. This sprint does not invent a replacement explanation.

### Deploy — `srv1730088:/var/www/asia-dental-lab-v2`

Run from the VPS via `bash scripts/deploy-vps-runner.sh start` (never locally).

```
exit=0 · DEPLOY OK · "Nothing to migrate." (no migration in this sprint)
LOCK_ACQUIRED=YES   TARGET_PINNED=c5e9fe6f5007…   SNAPSHOT_TRUSTED=PASS
DEPLOY_HEAD_TARGET_MATCH=YES   DEPLOY_SNAPSHOT_CLEANED=YES   immutable-exec rc=0
Automated smoke (NSF-9): 7 checks, 7 passed, 0 warnings, 0 errors — GO
```

### Production verification (read-only)

| Check | Result |
|---|---|
| `legacy-rme:ops-readiness` | **11/11 GO · `AT_REST` · Ready for a routine batch: YES · exit 0** |
| `--strict` | exit 0 |
| `--skip-monitoring` | exit **1** — fail-closed proven on live production |
| `--json` | 8 979 bytes · 0 long digit runs · 0 KTP/NIK/RM tokens |
| `legacy-rme:rollout-readiness` / `legacy-rme:migration-status` | exit 0 / exit 0 — unchanged |
| Branch matrix | ATG3 · LDK2 · SUN4 · TKM1 all `NOT_READY` with **zero blockers** (deliberately closed); MAIN excluded |
| Resting posture | capability **OFF** · admission **`[]`** · active batch **NONE** |
| Health | `/login` `/health/live` `/health/ready` `/health/lb` = 200 |
| Services | nginx · php8.3-fpm · queue worker all active; jobs 0 pending / 0 failed |
| Security | env `640 root:daengtisiams` · runtime identity `daengtisiams` · `foundation:security-compliance-check` 9/9 GO |
| New application errors | 0 |

`backup_freshness` reports GO on production because the deploy itself took and
verified a backup — which is exactly the intended coupling.

### Side effects — attributable, not naïve counting

Clinics are operational, so a global row-count diff would prove nothing. Rows
**created within the deploy window** (6 h):

```
stg_rme_legacy_imports 0 · trx_rme_legacy_records 0 · trx_clinic_visits 0
trx_medical_records 0 · trx_rme_invoices 0 · trx_rme_payments 0
trx_lab_orders 0 · trx_satusehat_candidates 0
```

### What this GO does not authorize

- It is **not** permission to open a batch. `Ready for a routine batch: YES`
  means *safe to start*, while the deployment remains at rest.
- No routine batch was run: `LIVE_ROUTINE_BATCH = NOT_EXERCISED`.
- `ROLL-4-WAVE-3` stays SKIPPED / NOT REQUIRED.
- The 25/100 sizing envelope is still a bounded default, not measured throughput.
