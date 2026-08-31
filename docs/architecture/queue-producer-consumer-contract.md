# Dedicated Queue Producer ↔ Consumer Contract

**Sprint:** BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1
**Rules:** ENT5-Q009, ENT5-Q010
**Command:** `php artisan foundation:queue-retry-failed-job-check`
**Enforced by:** `tests/Feature/Queue/QueueProducerConsumerParityTest.php` (declared in
`config/ci_runner.critical_gate_mandatory_suites`)

---

## Why this exists

A queue the application dispatches to, that no worker consumes, is the quietest
failure this system can produce.

The job row sits with `attempts = 0` and `reserved_at = NULL`. Nothing lands in
`failed_jobs`. No exception is thrown, no error is logged, no alert fires. The
domain record stays at `QUEUED`. To an operator, to a dashboard, and to every
health check we own, that is indistinguishable from *work still in progress*.

It has happened twice.

| | Queue | Outcome |
|---|---|---|
| LEGACY-RME-PDF-ROLL-2 | `legacy-rme-documents` | First pilot upload stalled. Fixed, and guarded — but the guard was scoped to the Legacy RME readiness service. |
| LEGACY-ODONTOGRAM (FIX-04b) | `legacy-odontogram-documents` | Shipped its own dedicated queue, inherited none of that protection, and stalled a real clinical document the same way. |

The second incident is the important one. The first fix was correct and it was
tested, and it still did not prevent the recurrence, because it protected *one
module* rather than *the property*. Adding `legacy-odontogram-documents` to a
worker without generalising the guard would have set up the third.

## The contract

> Any queue name DaengtisiaMS can dispatch to must have a verified consumer
> before the producing feature may reach GO.

Formally, three set relations, all enforced:

```
PRODUCED          ⊆ ALLOWED_QUEUE_NAMES     every produced queue is declared
PRODUCED(active)  ⊆ WORKER_CONSUMED         every active produced queue is drained
WORKER_CONSUMED   ⊆ ALLOWED_QUEUE_NAMES     the worker takes nothing undeclared
```

`ALLOWED_QUEUE_NAMES` is `config/queue_governance.php` →
`ent5_retry_failed_job.allowed_queue_names`.
`WORKER_CONSUMED` is the `--queue` list of the `ExecStart` directive in
`deploy/systemd/daengtisiams-queue-worker.service`.

**Reporting the queue names is not enough.** ENT-5 already listed
`allowed_queue_names` before this sprint, and both incidents happened anyway,
because the list never said whether anything *consumed* them. A violation is a
build failure, not a note in a payload.

## Two properties that make it hard to fool

**1. Queue names are resolved at runtime, not grepped.**
Every dedicated queue here is env-overridable
(`LEGACY_ODONTOGRAM_QUEUE`, `LEGACY_RME_QUEUE`, `SATUSEHAT_QUEUE`). A scanner
matching source literals would assert the default while production ran a
different name. The registry names the **config key** that decides each queue,
and the check reads it the way the runtime does.

**2. The registry is checked for completeness against the source tree.**
This is what makes the contract generic rather than a list of the queues someone
remembered. Any file under the scanned paths that routes work to a named queue
must be covered by a registry entry. A future module either registers — and is
then parity-checked — or fails `ENT5-Q009-PRODUCER-REGISTRY-COMPLETE` outright.
It cannot add a dedicated queue and stay invisible.

The scan patterns live in config, never inline in the scanner's own source, so
the scanner does not match itself. This is the same discipline
`unsafe_command_scan` already uses.

## Suspending a consumer requirement

An entry may name a config flag in `consumer_required_when`. While that flag is
falsy the producer cannot dispatch, so demanding a worker for it would be noise.

`satusehat` is the only current case: SATUSEHAT-2 is WATCH, no credentials
exist, and the submission service is fail-closed.

This is **not an exemption list**. The suspension is a live read of the same flag
the runtime checks. Enable the feature and the consumer requirement returns by
itself, and the gate fails until a worker consumes the queue. Nobody can park a
queue here to get past the check.

The queue is still required to be in `ALLOWED_QUEUE_NAMES`. Suspension covers the
*consumer* relation only, so the set of names the application can dispatch to
stays complete and honest.

## Reading the worker unit

`WORKER_CONSUMED` is parsed from the `ExecStart` directive specifically, never
from the file text:

- comment lines are dropped, as systemd drops them;
- a trailing backslash continues the directive onto the next line;
- names are split on `,` and compared **exactly** — `legacy-odontogram-documents-archive`
  does not satisfy a requirement for `legacy-odontogram-documents`.

A unit that merely *mentions* a queue in a comment does not consume it, and a
check that accepted the mention would certify a pipeline that is still stalled.

## Tracked vs installed unit

CI can only ever verify the **tracked** unit, so that is what the contract is
enforced against.

The **installed** unit (`/etc/systemd/system/…`) is what systemd actually runs.
Drift between the two is reported as a **warning**, never a failure, and is a
production-only signal:

> The deploy is forbidden from installing or starting a worker (ENT-5
> `no_long_running_worker_started_by_deploy`). Activation is a separate operator
> step that must happen *after* a successful deploy. A hard failure on installed
> drift would abort the very deploy that has to precede the activation — a
> deadlock. `foundation:queue-retry-failed-job-check` exits non-zero on FAIL even
> without `--strict`, and `scripts/deploy-vps.sh` runs it ungated, so this
> distinction is load-bearing, not cosmetic.

A unit-changing deploy therefore reports `WATCH` until the operator runs the
worker activation runbook, and returns to `GO` afterwards. That WATCH is
**correct**: production genuinely is in a drifted state during that window.
Suppressing it would be the "reports but does not enforce" weakness this sprint
exists to remove.

## Queue order is priority

Laravel drains `--queue` left to right. The two document-rendering queues stay at
the tail so a multi-minute rasterization never delays interactive work on
`default`:

```
default, reports, notifications, maintenance, legacy-rme-documents, legacy-odontogram-documents
```

New dedicated queues are **appended** for the same reason. The single-worker
posture (ENT-5, one process, `--max-time=3600`) is unchanged.

## ENT5-Q010 — directive placement

Production carried `StartLimitIntervalSec=0` under `[Service]`. systemd only
honours it in `[Unit]`, logged `Unknown key name 'StartLimitIntervalSec' in
section 'Service', ignoring`, and applied its own 5-starts-in-10s default.

The file said one thing and the running service did another. `systemctl show`
reported `StartLimitIntervalUSec=10s` against a file that asked for `0`, and
nothing compared them. ENT5-Q010 now pins the section for every directive in
`unit_directive_sections`.

## A third copy of the same fact

`enterprise_foundation_runtime_hardening.queue_worker.approved_queues` also
names the worker's queues. It had drifted: four names, while the unit had
consumed five since ROLL-2. Nothing compared them, so it had quietly become
decoration.

It is now held to the unit's `ExecStart` list and must match it exactly. Three
copies of one fact is two too many; the least this contract can do is refuse to
let them disagree.

## When a job is stuck at QUEUED

`QUEUED` is **not** a failure state and must never be "fixed" by hand.

If the domain status is `QUEUED`, the backend job is pending, `attempts = 0` and
`reserved_at` is `NULL`, then the job was never reserved and the investigation
target is the **consumer path** — not the record.

Do not `UPDATE` the domain status. Do not retry, redispatch, clone, or re-upload.
Restore the consumer and let the existing job drain naturally; a job that was
never attempted is fully recoverable. Retrying instead would hide which of the
two things was actually broken.

## Adding a dedicated queue

1. Add the name to `ent5_retry_failed_job.allowed_queue_names`.
2. Register the producing job class(es) and their config key under
   `producer_consumer_contract.produced_queues`.
3. Append the name to the worker unit's `--queue` list, and to
   `queue_worker.approved_queues`.
4. Run `php artisan foundation:queue-retry-failed-job-check --strict`.
5. After deploying, follow `docs/runbooks/queue-worker-activation-runbook.md` to
   install the unit, `daemon-reload`, and restart — the deploy will not do it.

Skipping step 3 fails the build. That is the whole point.
