# LEGACY-RME-PDF-ROLL-4 — Production Migration Operations & Scale-Up

## 1. Prerequisites (verified from the repository, not from memory)

| Workstream | GO SHA | What it established |
|---|---|---|
| ROLL-3 | `6d4850f4140a98d816e1d7d35678d948be65805b` | Branch admission, wave-specific approval, DRAIN, emergency stop |
| HISTORY-1 | `7963fec76d8ad16bfb7c1887b5b0c98d10460fc0` | Native + published legacy → patient-centric history |
| HISTORY-1A | `781b43e6053cd2ab7593a70a78528e531e92c6c7` | Migration capability ≠ published clinical read |
| HISTORY-1B | `c947b94dc819bc9ec535b1288420ea06542026b3` | Doctor legacy read scoped by practice branches |

All four are ancestors of the base branch. No historical tag was moved, recreated
or reopened.

## 2. The question ROLL-4 answers

ROLL-3 answered **"may this BRANCH migrate?"**. Running a real multi-branch
migration raises four more, none of which it could answer:

- **WHO** may migrate this branch? A permission says "may migrate"; it cannot say
  "may migrate *this clinic*".
- **HOW MUCH** may they migrate today? There was no ceiling at all.
- **Can we stop** without stranding accepted work? Only the whole-capability
  switch and the branch allowlist existed.
- **Did anything go missing?** There was no way to state what a wave was
  answerable for, so completion could only be inferred from an empty queue — and
  ROLL-2 already proved an empty queue means both "finished" and "never started".

## 3. The composition rule — this is the whole safety argument

```
ROLL-4 CAN ONLY NARROW. NEVER WIDEN.
```

Ingestion evaluates, in this exact order:

```
capability flag            (1B/ROLL-1)
→ date rules               (1A)
→ RM-derived branch        (FIX-ROLL2-1)
→ ROLL-3 admission         (unchanged)
→ ROLL-3 capacity          (unchanged)
→ ROLL-4 operations gate   (NEW — may only refuse)
→ file validation + storage
→ transaction: quota reserve + staging row
→ after commit: dispatch
```

ROLL-4 is consulted **only after** ROLL-3 has already admitted, and there is no
code path in which it turns a refusal into an acceptance. An auditor asking
whether ROLL-4 weakened the rollout has exactly one property to verify, and it
holds by construction. It is pinned by
`it cannot rescue a branch that ROLL-3 refuses`.

**Evidence that the layer is genuinely additive:** all 503 pre-existing LegacyRme
tests pass with ROLL-4 enforced, with **zero existing test files edited**.

## 4. The layer is REQUIRED, not opt-in

With the capability ON and at least one branch admitted, a matching **ACTIVE**
wave record must exist or ingestion is refused with `WAVE_NOT_REGISTERED`.

Making it opt-in would repeat the exact defect ROLL-3 was written to remove: a
control that applies only when the operator remembers to apply it is not a
control, it is a convention.

## 5. Two records of one decision, and why they are compared

| | Owner | Nature |
|---|---|---|
| `legacy_rme_rollout.admission` (config) | **AUTHORITY** | Deploy-time; changed on the server, outside the app's write path |
| `ops_rme_legacy_migration_waves` (row) | **OPERATIONAL MIRROR** | Written through the app; never treated as authority |

The wave row mirrors the wave label, the approval reference and the exact
approved branch set. When the two disagree, **neither is assumed correct** and
ingestion stops with `WAVE_BINDING_MISMATCH` until a human reconciles them.

This is ROLL-3's scope-binding fix applied one level up. Preferring one side
silently would just pick a winner and hide the drift — which is the failure mode,
not the remedy.

## 6. Decision codes (stable; callers branch on the code, never the message)

`CLEARED` · `NOT_ENFORCED` · `WAVE_NOT_DECLARED` · `WAVE_NOT_REGISTERED` ·
`WAVE_NOT_ACTIVE` · `WAVE_PAUSED` · `WAVE_DRAINING` · `WAVE_CLOSED` ·
`WAVE_BINDING_MISMATCH` · `BRANCH_NOT_ENROLLED` · `BRANCH_NOT_ACTIVE` ·
`BRANCH_PAUSED` · `BRANCH_DRAINING` · `BRANCH_CLOSED` · `OPERATOR_NOT_ASSIGNED` ·
`QUOTA_BRANCH_EXHAUSTED` · `QUOTA_WAVE_EXHAUSTED`

## 7. Operator assignment — and its one documented exemption

An **intake operator** (holding `create_legacy_rme_imports` and nothing more)
must carry an explicit, unrevoked assignment for that exact branch in that exact
wave. Revocation is soft, so who could touch a clinical archive — and when that
stopped — stays reconstructable.

**Exemption:** a holder of `manage_legacy_rme_migration_operations` is treated as
assigned. This is not a bypass: they can call `assignOperator()` for themselves,
for any enrolled branch, with no further approval, so the set of branches they
can ingest into is **identical** with or without the check. Requiring the
ceremony would remove a step, not a capability — and ceremony that changes
nothing trains operators to click through it.

Every other gate still applies to them, pinned by
`it still refuses a wave governor when the wave itself is paused`.

## 8. Quota

| Property | Decision |
|---|---|
| Counting point | **Accepted into staging** — the same transaction that writes the row |
| Rollback | Reservation shares that transaction, so an aborted insert releases its quota with no compensating write |
| Retry | **Not charged again.** The document was already accepted and counted; render load is ROLL-3's capacity gate |
| Concurrency | A counter row is written first (`insertOrIgnore`) so `FOR UPDATE` can serialise the decision |
| Deadlocks | One ordered `ORDER BY branch_id ... FOR UPDATE` covers both ceilings, so concurrent requests queue rather than cycle |
| `NULL` vs `0` | `NULL` = no ceiling declared; `0` = a ceiling that admits nothing. Never collapsed |

Counting staging rows instead would be derivable but unlockable: two uploads
racing for the last slot both read N−1 and both insert, because the row that
should block the second one is the one neither has written yet.

The ledger is a second copy of a derivable fact, so it can drift — reconciliation
reports `quota_drift` and a non-zero value blocks branch completion.

## 9. Reconciliation — completion is a balance, not an empty queue

```
accepted = published + cancelled + failed_unresolved + in_flight
```

`unexplained` is the remainder. It is zero by construction, because those four
buckets partition the 1A status vocabulary — which is exactly why it is computed
and asserted rather than assumed. If a tenth status is added and this file is not
updated, `unexplained` goes non-zero and blocks sign-off instead of a document
quietly falling out of the count.

`quota_drift` is the second, independent check, derived from a different table.
Two counts from two tables both required to agree is much harder to satisfy by
accident than one count agreeing with itself.

**Attribution is recorded, not inferred.** `stg_rme_legacy_imports.migration_wave_id`
is written at acceptance. Guessing from a branch plus a date window is wrong
exactly when it matters: a wave spanning midnight, two waves touching one branch,
a document uploaded on the day a branch was drained.

Branch completion requires: zero in-flight, zero unresolved failures, zero
unexplained, zero quota drift, `DRAINING` status, and a sign-off note. The
reconciliation is recomputed **under the lock** and frozen onto the row — trusting
a number the operator saw on a dashboard minutes ago would let a document accepted
in between be signed away.

Wave completion requires every enrolled branch to be `COMPLETED` or explicitly
`CANCELLED`. A branch that simply never finished blocks the wave.

## 10. Operational controls, and how they differ

| Control | Effect on new intake | Effect on accepted work | Reversible |
|---|---|---|---|
| Branch pause | Stops that branch | Preserved | Yes |
| Wave pause | Stops every branch | Preserved | Yes |
| Branch/wave DRAIN | Stops | Preserved; publish still runs full revalidation | **No** — leads to completion |
| ROLL-3 admission removal | Stops that branch | Preserved | Yes (config + cache rebuild) |
| **Capability OFF** | Stops **everything**, publish included | Preserved | Yes |

`PAUSED` and `DRAINING` behave identically at runtime, and that is deliberate:
they differ in what they permit **next**. Merging them would force an operator to
express "we are stopping for good" by choosing a state that invites someone to
resume it.

**Capability OFF never removes authorized PUBLISHED clinical read** (HISTORY-1A),
and no ROLL-4 state does either — pinned by three tests covering paused, drained
and closed, plus the negative case that a non-treating doctor stays denied
throughout.

## 11. Monitoring — a fabricated zero is the most dangerous thing a panel can say

Every probe is guarded and reports `null` / "not measurable" when it cannot be
evaluated, never 0. A fabricated zero reads as "healthy, nothing pending", which
is precisely the ROLL-2 failure.

`planned_document_count` is nullable and stays nullable: there is no way to derive
the size of a paper archive from the database, so a total is either something a
human counted or it is unknown. `completion_percent` is `null` without it.

Stale `PROCESSING` rows are **surfaced, never mutated** — a stalled worker and a
slow 200-page render look identical from here, and rewriting a clinical status
from a clock is how evidence quietly becomes wrong.

Storage is **measured** (`size_bytes`, `page_count`, real disk free via the ROLL-3
probe), not estimated. Disk free delegates to the capacity probe rather than
stat-ing again, so the dashboard cannot read "plenty of room" beside a gate
refusing uploads for low disk.

## 12. Security posture

- Every gate is server-side; the UI is presentation and the controller re-decides.
- The branch checked is always the **RM-derived** code (FIX-ROLL2-1). No request
  body, query string, session or BranchContext selection can influence it.
- IDOR: a `{branch}`/`{assignment}` id is only operated on when it genuinely
  belongs to the `{wave}` in the same URL — enforced in the **service** as well,
  so the CLI is covered.
- The CLI authorizes `--actor` through the **same policy** as the browser, and
  `approve` requires its own ability, so separation of duties survives over SSH.
- Wave codes are validated to a canonical token pattern.
- Audit payloads stay structure-only. New PII-free events:
  `LEGACY_RME_IMPORT_OPERATIONS_REJECTED`, `LEGACY_RME_WAVE_REGISTERED`,
  `…_TRANSITIONED`, `…_BRANCH_TRANSITIONED`, `…_BRANCH_COMPLETED`,
  `…_OPERATOR_ASSIGNED`, `…_OPERATOR_REVOKED`, `…_QUOTA_CHANGED`. New allow-listed
  keys: `operator_user_id`, `daily_quota`, `reason_length`. Free-text reasons are
  recorded by LENGTH only, as 1D does for `void_reason_length`.
- Doctor access is unchanged; ROLL-4 widens no read scope.

**Assessed and accepted risk.** With `require_separate_approver` off (the pilot
default), one holder of manage + approve can create, approve and activate a wave
alone. Accepted because the wave still cannot admit a branch by itself —
admission remains config plus the owner's approval reference, a deploy-time
change outside the app's write path. A lone operator can shape a wave but cannot
open a clinic the owner did not already approve. The rule is enforced
server-side when switched on.

## 13. Files

**New (30):** 5 migrations, 4 models, 4 factories, 4 support vocabularies
(`LegacyRmeWaveStatus`, `LegacyRmeWaveBranchStatus`, `LegacyRmeOperationsDecision`,
`LegacyRmeReconciliation`), 6 services (`WaveBinding`, `OperationsGate`,
`MigrationQuota`, `WaveGovernance`, `MigrationReconciliation`,
`MigrationOperations`), 1 policy, 4 FormRequests, 1 controller, 2 views,
2 commands, `config/legacy_rme_operations.php`, 3 test files, this document, the
runbook and the AI mirror rule.

**Modified (9):** `LegacyRmeImportService` (operations gate + quota reservation +
wave attribution), `LegacyRmeImport` (`migration_wave_id` fillable),
`LegacyRmeAuditEvent` (8 events, 3 keys), `LegacyRmeRolloutReadinessService`
(1 check), `RepositoryServiceProvider`, `PermissionSeeder` (3 permissions),
`PermissionGroupingService`, `routes/web.php` (9 routes), `tests/Pest.php`
(3 helpers).

**5 additive migrations. 3 new permissions. 9 new routes. No destructive change.**

## 14. Local evidence

| Gate | Result |
|---|---|
| ROLL-4 suites (gate / reconciliation / access) | **67 passed**, 129 assertions |
| `tests/Feature/LegacyRme` (full) | **570 passed**, 5 skipped, 1501 assertions |
| Permission / access-control / doctor-scope regression | **434 passed**, 1865 assertions |
| `pint --test` (repo-wide) | passed |
| `git diff --check` | clean |
| `npm run build` | passed |
| `view:cache` | compiles |
| `config:cache` round-trip | ROLL-1 capture rule holds |
| `foundation:security-compliance-check` | GO (9/9) |
| `foundation:ci-runtime-control-check --strict` | GO |
| `architecture:ui-governance-check --strict` | GO |
| `foundation:roadmap-check --strict` | GO, next `MON-1`, not stale |

**Two real defects were found by these tests and fixed, not worked around:**

1. `migration_wave_id` was missing from `LegacyRmeImport::$fillable`, so mass
   assignment silently dropped it and every reconciliation would have measured
   zero accepted documents.
2. The Pest wave fixture force-reset the wave to `ACTIVE` on every call, which
   would have made any pause/drain assertion vacuously pass.

## 15. What ROLL-4 GO does and does not authorize

**Does:** the operational control plane exists, is enforced, and is proven to
narrow rather than widen.

**Does NOT:** authorize any branch, enable migration globally or permanently,
approve a wave, permit bulk unattended import, automatic ingestion, auto-publish,
OCR as clinical-date authority, or widen any doctor's read scope.

Every production migration wave still requires an explicit, fresh owner approval
covering that exact branch set. Historical approvals never authorize a new wave.
