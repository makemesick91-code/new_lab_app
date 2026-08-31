# BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1

**Type:** RUNTIME_FIX · PRODUCTION_QUEUE_PIPELINE · GOVERNANCE_GAP
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline:** `revision-legacy-odontogram-native-optional-1-go` @ `7364a2f`
**Contract:** `docs/architecture/queue-producer-consumer-contract.md`
**Rule mirror:** `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc`
**No migration. No permission change. No schema change.**

---

## The defect

A legacy odontogram PDF was uploaded to the production pilot on 2026-08-31.
`ProcessLegacyOdontogramPdfImport` was dispatched to
`legacy-odontogram-documents`. The production worker consumed:

```
default, reports, notifications, maintenance, legacy-rme-documents
```

Not that one. So the job was never reserved.

| Evidence | Value |
|---|---|
| `jobs.id` | 17 |
| `jobs.queue` | `legacy-odontogram-documents` |
| `jobs.attempts` | 0 |
| `jobs.reserved_at` | NULL |
| `failed_jobs` | 0 rows |
| `stg_odontogram_legacy_imports.id=1` | `QUEUED` |

No exception. No log line. No failed job. Nothing to alert on. The import sat at
`QUEUED`, which to every operator and dashboard we own is indistinguishable from
*work still in progress*.

```
PRODUCER_QUEUE  !=  WORKER_CONSUMED_QUEUE
```

## Why the one-line fix was not the deliverable

This was the **second** occurrence.

LEGACY-RME-PDF-ROLL-2 hit the identical shape on `legacy-rme-documents`, stalled
the first pilot upload, and answered with a `queue_contract` check inside
`LegacyRmeRolloutReadinessService`. That check is correct and tested — and
scoped to one module. Legacy Odontogram shipped its own dedicated queue months
later and inherited none of it.

Adding `legacy-odontogram-documents` to the worker and stopping there would have
set up the third occurrence. So the durable deliverable is **ENT5-Q009**, a
module-agnostic producer ↔ consumer contract that fails CI whenever *any*
produced queue has no consumer.

## What changed

**Functional fix**

| File | Change |
|---|---|
| `deploy/systemd/daengtisiams-queue-worker.service` | `--queue` gains `legacy-odontogram-documents` (appended — order is priority; renders stay at the tail so they never delay `default`) |
| `deploy/systemd/daengtisiams-queue-worker.service` | `StartLimitIntervalSec=0` moved `[Service]` → `[Unit]` |
| `config/queue_governance.php` | `allowed_queue_names` gains `legacy-odontogram-documents` and `satusehat` |
| `config/enterprise_foundation_runtime_hardening.php` | `approved_queues` corrected to match the unit |

The producer was **not** changed. `legacy-odontogram-documents` is an
intentional isolation boundary so a multi-minute rasterization never blocks
interactive work; repointing it at `default` to avoid touching the worker would
have traded a visible stall for an invisible latency regression.

**Governance protection**

| File | Purpose |
|---|---|
| `app/Support/Queue/QueueProducerConsumerContractScanner.php` | new — resolves produced queues at runtime, parses the unit's `ExecStart`, checks registry completeness and directive placement |
| `app/Support/Queue/QueueRetryFailedJobReadinessService.php` | ENT5-Q009 (parity + registry completeness + installed drift) and ENT5-Q010 (directive sections) |
| `app/Services/Foundation/QueueRetryFailedJobGovernanceService.php` | publishes rules ENT5-Q009, ENT5-Q010 |
| `config/queue_governance.php` | `producer_consumer_contract` registry |
| `tests/Feature/Queue/QueueProducerConsumerParityTest.php` | new — 19 tests |
| `config/ci_runner.php` | declares the suite mandatory for the critical gate |
| `.github/workflows/foundation-evidence-gates.yml` | `QueueProducerConsumerParity` token added to **both** critical variants |

## TDD evidence

Guard written first, against the unfixed tree:

```
Checks: 13 | Passed: 11 | Errors: 2 | Decision: FAIL

ENT5-Q009-PRODUCER-CONSUMER-PARITY:
  produced queue "legacy-odontogram-documents" is not declared in the ENT-5 allowed queue names;
  produced queue "satusehat" is not declared in the ENT-5 allowed queue names;
  produced queue "legacy-odontogram-documents" has no consumer: it is absent from the worker unit --queue list;
  the worker unit consumes "legacy-rme-documents" but it is missing from the approved worker queue list
ENT5-Q010-WORKER-UNIT-DIRECTIVE-SECTIONS:
  StartLimitIntervalSec is declared in [Service] but systemd only honours it in [Unit]; it is being silently ignored
```

After the fix: `Checks: 13 | Passed: 13 | Errors: 0 | Decision: GO`, `--strict` exit 0.

`PRE_FIX_CONTRACT_TEST=FAIL` → `POST_FIX_CONTRACT_TEST=PASS`.

## Two findings nobody asked about

The guard was written to catch one queue. Run once, it caught three more
problems:

1. **`satusehat` was produced but never declared.** Three SATUSEHAT jobs resolve
   `config('satusehat.queue')`. The name appeared in no allowlist. Harmless today
   only because SATUSEHAT is fail-closed and disabled — but the moment it is
   enabled it would have been the same stall, a third time. It is now declared,
   with its *consumer* requirement suspended dynamically against
   `satusehat.enabled` rather than by a static exemption. Enable SATUSEHAT and
   the requirement returns by itself.

2. **`approved_queues` had become decoration.** The POST-ENT runtime-hardening
   list named four queues while the unit had consumed five since ROLL-2. It was
   only ever compared *upward* to the ENT-5 allowlist, never *sideways* to the
   unit it claimed to describe — a third copy of the same fact, free to drift. It
   is now held to the unit's `ExecStart` list exactly.

3. **`StartLimitIntervalSec` was being ignored in production.** `systemctl show`
   reported `StartLimitIntervalUSec=10s` against a file that asked for `0`.
   Confirmed on the host before changing anything.

## Design decisions worth recording

**Runtime resolution, not grep.** Every dedicated queue here is env-overridable.
A literal scan would assert the default while production ran another name. The
registry names the *config key*; the check reads it the way the runtime does.

**Registry completeness.** Any source file that routes work to a named queue must
be covered by a registry entry, or `ENT5-Q009-PRODUCER-REGISTRY-COMPLETE` fails.
This is what makes the contract generic rather than a list of remembered queues:
a future module either registers (and is parity-checked) or fails outright.

**`ExecStart`-anchored parsing.** Comments are dropped, continuations joined,
names compared exactly. A unit that *mentions* a queue in a comment does not
consume it, and a check that accepted the mention would certify a stalled
pipeline. `x-archive` never satisfies `x`.

**Installed drift is an operational observation, not a check — corrected after
production said so.** It shipped as a `warn()`, and the first deploy of this
sprint aborted because of it: ENT-5 went `WATCH`, ENT-8/9/10/11 each re-verify
ENT-5's decision and inherited it, and `scripts/deploy-vps.sh` asserts ENT-11 is
`GO`. The deploy could not finish because the unit was not installed, and the
unit could not be installed until the deploy finished — a permanent deadlock for
every future unit-changing sprint.

The earlier reasoning verified that the *commands* exit 0 without `--strict`, and
missed the deploy script's explicit `GO` assertion. Drift is now reported in the
payload and printed under `Installed worker unit (operational note — does not
affect the decision)`. The hard half is unchanged: ENT5-Q009 still FAILS when the
**tracked** unit is missing a produced queue. CI can only ever verify the tracked
unit; the installed unit is the operator's step.

## Recovery of the stalled document

No retry. No redispatch. No manual status update. The consumer was restored and
the existing job drained on its own — an unattempted job is fully recoverable,
and retrying would have destroyed the evidence of which side was broken.

## Not in scope

Recorded, deliberately not fixed: `patientVisitOptions`, the Lab Order phone
leak, patient search throttle, `pg_trgm` optimisation, `ProductionSeeder`. None
blocked this work.

The SSH-waiter-is-not-a-deploy-verdict rule was already canonical in
`docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md`; it is referenced,
not duplicated.
