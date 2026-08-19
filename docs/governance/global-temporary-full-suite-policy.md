# GLOBAL TEMPORARY FULL-SUITE POLICY

| | |
|---|---|
| Canonical name | `GLOBAL TEMPORARY FULL-SUITE POLICY` |
| Status | **ACTIVE** |
| Nature | **TEMPORARY** — expires only per §10 |
| Authority | Explicit user decision (project owner) |
| Effective from | 2026-08-19 |
| Scope | **GLOBAL across the current corrective/fix series** |
| Canonical rule mirror | `.cursor/rules/107-global-temporary-full-suite-policy.mdc` |
| Supersedes | Nothing. It **defers** a gate; it removes none. |

> **One sentence:** while a series of corrective fixes is still in flight, the
> repository-wide Full Suite is **deferred to a single authoritative run on the
> frozen final integrated SHA** — every other gate on every individual fix stays
> exactly as mandatory as it was.

---

## 1. Why (recorded accurately)

There are still multiple corrective/fix sprints planned. Running the entire
repository Full Suite after each individual fix would repeat an expensive
*integrated* gate while the integrated candidate is still changing — each run
validates a SHA that the next fix immediately invalidates.

The project owner therefore explicitly chose to **defer** the Full Suite until
all planned fixes are complete. Quality is maintained on every single fix
through targeted tests, dependency-aware regression, the required non-Full-Suite
CI gates, security and architecture review, deployment smoke, and rules
synchronisation.

The correct statement is **"the Full Suite is deferred."**
It is **never** correct to say "the Full Suite is unnecessary." It remains the
final integrated gate.

---

## 2. Primary rule

While this policy is ACTIVE:

```
FULL SUITE MUST NOT BE RUN ON INDIVIDUAL FIX SPRINTS
```

```
FIX-A → FIX-B → FIX-C → … → FIX-N     (no Full Suite on any of them)
                  ↓
        ALL FIXES COMPLETE
                  ↓
        FINAL INTEGRATED SHA FROZEN
                  ↓
   ONE AUTHORITATIVE CONSOLIDATED FULL SUITE
```

"Run" means **deliberately invoked**: a `workflow_dispatch` with
`run_full_suite=true`, a local whole-repository `php artisan test`, or treating a
fix as unfinished until a Full Suite result exists. See §6 for the two
*structural* triggers that are not deliberate invocations.

---

## 3. What each individual fix sprint still MUST do

Deferring the Full Suite lowers **nothing** else. Every fix sprint still
requires, in full:

- targeted tests for the changed behaviour;
- **dependency-aware cumulative regression** (§4);
- security review and architecture review;
- `vendor/bin/pint` and `git diff --check`;
- relevant permission / access-control / module suites;
- **all required non-Full-Suite CI gates green** — NSF-R012 Quality,
  CICD-CTRL Gate Classifier, NSF-R011 Critical Test Gate, CICD-CTRL Selective
  Module Gate, NSF-9 Release Safety & Automated Smoke, NSF-10 Release Evidence;
- merge verification, deployment verification, production smoke;
- rules synchronisation and cleanup.

> **Forbidden inference:** "the Full Suite is skipped, therefore other tests may
> be skipped too." That reasoning is expressly prohibited. This policy narrows
> exactly one gate and nothing else.

---

## 4. Cumulative, dependency-aware regression

As fixes accumulate, each later fix must consider the foundations laid by the
earlier ones:

```
FIX-1 → tests(FIX-1)
FIX-2 → tests(FIX-2) + regression relevant to FIX-1
FIX-3 → tests(FIX-3) + regression relevant to FIX-1/FIX-2
```

Regression is **risk-based and dependency-based**, not "progressively larger for
its own sake". Resolve the relevant prior surface with the real tools:

```bash
php artisan sprint:test-plan          # focused + regression + escalation from the diff
graphify query "<subject>"            # dependency / call-graph impact
graphify path "<A>" "<B>"
```

Running the whole repository on every fix is precisely what this policy exists
to avoid; running *too little* is a separate failure this policy does not excuse.

---

## 5. The Full Suite must not be triggered accidentally

Before every push / PR / dispatch on a fix sprint:

1. Resolve the gate profile with the real classifier — do not guess:
   ```bash
   scripts/ci/resolve-gates.sh --base origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report --head HEAD
   ```
2. Confirm you are not about to dispatch the workflow with `run_full_suite=true`.
3. Use the official CI path that matches the change **truthfully**.

**Never:**

- add `paths-ignore` or blanket path filters (already blocked by
  `foundation:ci-runtime-control-check`);
- widen `skip_critical_profiles` beyond `docs_only`;
- edit CI **merely** to hide a failure or to force a skip;
- mark a job as skipped when it in fact executed;
- fabricate a classification the diff does not support.

**Do not manufacture a change** (e.g. reverting a real code edit into docs) just
to obtain a weaker CI profile. The profile must follow the change, never the
reverse.

### 5.1 The local trigger inside DEVFLOW itself

> **DANGER — the most likely accidental trigger.** `php artisan sprint:test
> --all-required` (and `--regression`) appends a `pest-full-required` step that
> runs **bare `php artisan test` — the entire repository** — whenever
> `escalate_full_suite` is true. Escalation is **fail-closed**: a changed file
> that matches an escalation category *or no category at all* sets it, and a
> sprint manifest matches nothing. **While this policy is ACTIVE, do not run
> `sprint:test --all-required` or `--regression`.** Use instead:
>
> ```bash
> php artisan sprint:test-plan            # read-only; shows the plan + escalation
> php artisan sprint:test --focused       # focused filters only, never escalates
> vendor/bin/pint --dirty --test          # covered by --all-required, run it directly
> git diff --check                        # same
> php artisan test --filter='<targeted regression>'
> ```

This matters because `docs/engineering/sprint-runtime-template.md` otherwise
instructs every sprint to run `sprint:test --all-required` before release. Under
this policy that instruction is **superseded** by the sequence above.

---

## 6. The two structural triggers (documented, not hidden)

`full_suite_gate` in `.github/workflows/foundation-evidence-gates.yml` fires on
exactly three events. This is verified behaviour, not an assumption:

> **SUPERSEDED BY CI-TEMP-FULL-SUITE-SCHEDULE-GATE (2026-08-19).** The table below
> records the behaviour *before* that sprint. Both automatic triggers are now
> **deferred in CI**, not merely documented. §6.1 and §6.2 below carry the current
> handling; the historical text is kept because the `gh run cancel` precedent it
> describes remains valid evidence for runs that predate the gate.

| Event | Fired? (before) | Now (policy ACTIVE) |
|---|---|---|
| `pull_request` | **No** — not in the job's `if` condition | Unchanged. Structurally guaranteed zero; a fix sprint's own PR validation is always `FULL_SUITE_EXECUTION_COUNT = 0`. |
| `push` to the base branch | **Yes** | **Deferred.** `full_suite_authorized=false`, reason `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`. A squash-merge *is* a push to base, so this closed the largest structural gap. |
| `schedule` (weekly `0 2 * * 0` UTC) | **Yes** | **Deferred.** Same reason code. The run still happens and every other gate still executes; only the Full Suite job is skipped. |
| `workflow_dispatch` + `run_full_suite=true` | Yes | **Deferred** unless `full_suite_policy_override=true` is also set. Both inputs together are the authorised consolidated-closure path (§9). |

A further mechanical fact worth knowing: `.sprint/current.yml` matches no
classifier pattern and therefore resolves to `unknown_high_risk`. Since every
real sprint updates its manifest, **every real fix merge resolves to
`run_critical_tests=true`** — so the post-merge Full Suite *step* is enabled, not
skipped. Only a genuinely docs-only change set resolves to `docs_only` and skips
the step.

### 6.0 Governance-only changes deliberately do not touch the manifest

A governance/docs/rules-only change (this policy itself is the worked example)
**must not** update `.sprint/current.yml`. That file matches no classifier
pattern, so adding it flips an otherwise clean `docs_only` change set to
`unknown_high_risk` — enabling the very Full Suite step the change exists to
defer. A governance-only change is also not a sprint, so it needs no manifest.
Verify before pushing:

```bash
git diff --cached --name-only | bash scripts/ci/resolve-gates.sh --changed-files-stdin
```

This is **not** the §5 prohibition on manufacturing a weaker profile: the change
set is genuinely docs-only, and the classification follows the change.

### 6.1 Handling the post-merge push-to-base run

> **Now structurally prevented — CI-TEMP-FULL-SUITE-SCHEDULE-GATE (2026-08-19).**
> A post-merge push to base no longer starts the Full Suite while this policy is
> ACTIVE, so the manual `gh run cancel` handling below is a **historical
> precedent and a fallback**, not the primary control. It still governs any run
> that predates the gate, and its hard limit — never cancel a suite that has
> already begun executing tests — remains in force.

Precedent set by FIX-LEGACY-RME-ROUTINE-OPS-1 (run `32205563992`). The permitted
handling is an **official `gh run cancel`**, and only under all of these
conditions:

- the pre-merge candidate run was **green on a tree identical to the squash
  merge** — cancelling also cancels the other gates in that run, so their result
  must already exist elsewhere;
- the cancel happens **before the Full Suite job begins executing tests**;
- the run ID and the zero-duration proof (`startedAt == completedAt`) are
  recorded in the sprint evidence;
- **no CI file is touched.**

> **Hard limit — the abuse this rule exists to prevent:** cancelling a Full Suite
> that has **already begun executing tests**, in order to make the count look
> like zero, is **forbidden**. If that happens it is an execution: record it,
> report the partial result, and do not claim "never executed". The claim "not
> executed" is only ever valid with the zero-duration proof above.

### 6.2 Handling the weekly scheduled run

The weekly scheduled Full Suite runs on the base branch tip at whatever moment
the cron fires. It is:

- **not** attributable to any sprint, and never counted against one;
- **not** the authoritative consolidated Full Suite (§9) — it does not run on a
  frozen, agreed final SHA;
- **never** citable as a fix sprint's Full Suite pass;
- **not** to be cancelled for count management — cancelling a scheduled run to
  protect a number is exactly the abuse §6.1 forbids.

Treat its result as an informational baseline signal only.

> **DONE — CI-TEMP-FULL-SUITE-SCHEDULE-GATE (2026-08-19).** The option this
> paragraph reserved has been exercised under explicit user authorisation. Both
> automatic triggers are now gated in CI on a fail-closed policy decision, so a
> scheduled run no longer executes the Full Suite while this policy is ACTIVE.
> Both triggers are **retained** in the workflow — the gate is deferred, never
> deleted — so `full_suite_fallback_triggers` still holds and
> `foundation:ci-runtime-control-check` stays GO.
>
> Canonical state: `.github/ci-policy/full-suite-policy.json` ·
> resolver: `scripts/ci/resolve-full-suite-policy.sh` ·
> sprint: `docs/sprints/ci-temp-full-suite-schedule-gate.md`.
>
> A scheduled run is therefore no longer an "informational baseline signal" for
> the Full Suite — the suite does not run. Every other gate in that run still
> does, and the deferral reason is published by the always-run `classify` job.

---

## 7. Truthful evidence

Every sprint shipped under this policy records, at job/step granularity where CI
exposes it:

```
FULL_SUITE_STATUS = SKIPPED_BY_GLOBAL_TEMPORARY_POLICY
FULL_SUITE_EXECUTION_COUNT = 0
GLOBAL_TEMPORARY_FULL_SUITE_POLICY_ACTIVE = YES
```

`FULL_SUITE_EXECUTION_COUNT` counts Full Suite executions **attributable to that
sprint's own CI**. Structural runs (§6.2) belong to no sprint.

Absolutely prohibited:

- writing `FULL SUITE PASS` when it did not run;
- equating **job skipped** with **tests passed**;
- equating **job cancelled** with **tests passed**;
- equating a **`success` Full Suite job** with **tests passed** — see §7.1; on a
  docs-only change the job is green precisely *because* the suite was skipped;
- reporting a count of zero that the evidence does not support.

If an accidental deliberate Full Suite genuinely runs: **record it** — run ID,
SHA, event, result — report the policy breach, and continue only after an
explicit governance assessment. Never manipulate history to preserve a number.

---

### 7.1 Read the STEP, never the job — a `success` job can mean nothing ran

**Verified on real runs, and the single most dangerous misreading available.**
On a docs-only push to base the `NSF-R011 Full Suite Gate` **job reports
`success` while the suite never executed** — the classifier resolves
`docs_only`, the `Run full Pest suite` step is `skipped`, and a
`Note skipped full suite (docs-only change)` step writes the evidence file
instead. Nothing failed, so the job is green.

Evidence, run `32211741144` (commit `1311dba`, `event=push` to base):

| Level | Name | Conclusion |
|---|---|---|
| job | `NSF-R011 Full Suite Gate` | **success** |
| step | `Run full Pest suite` | **skipped** |
| step | `Note skipped full suite (docs-only change)` | success |

So the rule is not merely "job skipped ≠ tests passed". It is:

> **Job `success` ≠ tests passed.** A green Full Suite job proves only that
> nothing *errored*. Only the `Run full Pest suite` **step** proves the suite
> ran.

Always verify at step level before recording anything:

```bash
gh run view <run-id> --json jobs \
  --jq '.jobs[]|select(.name=="NSF-R011 Full Suite Gate")|.steps[]
        |select(.name|test("full Pest suite"))|"\(.conclusion)\t\(.name)"'
```

- step `skipped` → `FULL_SUITE_EXECUTION_COUNT = 0`. Never "PASS".
- step `success` → it genuinely ran; record the result, and if it ran on an
  individual fix while this policy was ACTIVE, report it under §7 as a breach.

The same applies to the run-level `conclusion=success` shown by
`gh run list` — it is an aggregate over jobs and says nothing about whether the
suite executed.

---

## 8. Per-fix closing posture and GO tags

A fix whose implementation scope is complete closes as:

```
IMPLEMENTED
TARGETED TESTS PASS
RELEVANT REGRESSION PASS
REQUIRED NON-FULL-SUITE CI PASS
MERGED
DEPLOYED
PRODUCTION SMOKE PASS
RULES SYNCHRONIZED
CLEANUP PASS
FULL SUITE SKIPPED BY GLOBAL TEMPORARY POLICY
WATCH — PENDING CONSOLIDATED FULL SUITE
```

Such a sprint is **not** failed, unfinished, or defective. Distinguish:

- **implementation closure** — reached; and
- **final integrated verification closure** — deferred to §9.

**GO tags.** Default while ACTIVE: **no final engineering GO tag** for any fix
whose existing governance requires a Full Suite before GO. Never create
`<fix-name>-go` on a pretended Full Suite pass. Where a sprint type canonically
never required a Full Suite for GO, its historical governance is not rewritten —
but any fix being accumulated toward the consolidated closure ends at
`WATCH — PENDING CONSOLIDATED FULL SUITE` with its GO tag deferred.

---

## 9. Consolidated final closure

Begins **only** when the user explicitly states that all fixes are finished, or
explicitly authorises the final Full Suite. It is **not** started automatically.

```
ALL FIXES COMPLETE
  → inventory every WATCH fix sprint
  → verify each implementation is merged and deployed
  → resolve outstanding blockers
  → FREEZE the exact final integrated runtime SHA
  → confirm no pending code revisions
  → baseline re-verification
  → ONE AUTHORITATIVE FULL SUITE   (expected failures = 0)
  → final production regression smoke
  → rules / governance audit
  → final GO decision
  → GO tags / closure evidence
```

### Failure handling

Expected baseline is **failures = 0**. On failure:

```
FULL SUITE FAIL → NO FINAL GO
  → record the exact failure set (do NOT immediately rerun)
  → diagnose with targeted tests
  → implement the corrective fix
  → targeted + regression validation
  → merge / deploy
  → resolve with the user when the next authoritative Full Suite runs
```

**Do not loop the Full Suite repeatedly while debugging.** Repeated integrated
runs are the cost this policy exists to avoid, and they mask flakes rather than
diagnose them.

---

## 10. Retirement

The policy ends only when **one** of the following is true:

1. the user explicitly revokes or changes it; **or**
2. all corrective fixes are complete, the final integrated SHA is frozen, the
   consolidated Full Suite has run, and final closure is finished.

One finished fix does **not** end it.

On retirement: mark this document `RETIRED after consolidated Full Suite
closure`, keep it and rule `107` in place as historical evidence — **never
delete them** — and restore whatever Full Suite cadence governance is current at
that time.

---

## 11. What this policy does NOT change

Stated explicitly so no future reader can over-read it.

**Deployment** — unchanged. Deployment runs **on the VPS**:

```bash
cd /var/www/asia-dental-lab-v2
bash scripts/deploy-vps-runner.sh start
```

Launcher invocation is not completion; require real `exit=0` and `DEPLOY OK`.
Backup-before-migrate, `migrate --force` only, never `migrate:fresh` / `db:wipe`.

**Production safety** — unchanged. Production smoke stays mandatory whenever a
fix changes runtime: health checks over `https://daengtisia.online` (never the
bare IP), VPS HEAD verification, migration/state checks, RBAC checks where
relevant, Laravel log review, rollback readiness.

**Legacy RME safety foundations** — unchanged: fail closed; RM-derived branch
authority; historical archive read-only semantics; no fake clinical production
data; maker ≠ checker/publisher; creator ≠ approver where SOD is enabled;
published history readable with migration OFF; admission EMPTY at rest;
migration capability OFF at rest.

**All other controls** — CICD-CTRL-1 default-strong classification, branch
isolation, ledger-only inventory, RBAC 3-layer, PII masking, additive-only
migrations, release safety and evidence gates: unchanged.

This policy changes **test scheduling only.** It changes no clinical, security,
release-safety, or deployment control.

---

## 12. Current inventory of deferred fixes

| Sprint | Posture | Full Suite | GO tag |
|---|---|---|---|
| `FIX-LEGACY-RME-ROUTINE-OPS-1` | WATCH — pending consolidated Full Suite | skipped, execution count 0 | **none** — `fix-legacy-rme-routine-ops-1-go` deliberately not created |

Runtime authority for that fix: merge / VPS runtime SHA **`acbf5e3`**. The
documentation commit **`1311dba`** is evidence-only and undeployed.

Per explicit user decision, **no `FIX-LEGACY-RME-ROUTINE-OPS-1A` Full-Suite
closure sprint is to be created now** — its closure is folded into the single
consolidated closure of §9.

Append a row to this table for every subsequent fix shipped under this policy.
