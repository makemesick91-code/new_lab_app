# CI-TEMP-FULL-SUITE-SCHEDULE-GATE

**Temporary scheduled Full-Suite suppression under the global consolidated Full-Suite policy.**

| | |
|---|---|
| Type | INFRA_RELEASE — CI / governance sprint, not an application feature sprint |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base authority | `917e4c3` (verified from `origin`, not assumed) |
| Canonical policy | `docs/governance/global-temporary-full-suite-policy.md` |
| Rule mirror | `.cursor/rules/107-global-temporary-full-suite-policy.mdc` |
| Policy status after this sprint | **ACTIVE — unchanged. This sprint does not retire it.** |

---

## 1. Problem

PRs #317 (`51b736a`) and #318 (`917e4c3`) established the GLOBAL TEMPORARY
FULL-SUITE POLICY. Both were **documentation, rules and governance prose only** —
by design, so that enabling the policy could not itself fire the gate it governs.

The consequence: **nothing in CI enforced the policy.** The `full_suite_gate` job
condition was untouched, so while the policy was ACTIVE the Full Suite could still
be executed automatically.

Two automatic paths reached it, not one:

| Path | Status before | Note |
|---|---|---|
| weekly `schedule` (`0 2 * * 0` UTC) | **fired** | the briefed gap; calendar-driven, attributable to no sprint |
| `push` to the base branch | **fired** | **a squash-merge IS such a push — every fix merge ran it** |

The second path is the larger one and is easy to miss. Policy §6.1 mitigated it
only *after the fact*, with a narrowly-conditioned `gh run cancel` precedent —
a manual, easily-mis-executed control standing in for a structural one.

### Authority to make this change

Policy §6.2 already anticipated exactly this work:

> *"the push-to-base and/or `schedule` triggers could be narrowed for the duration
> of this policy … it must not be done unilaterally"* — **requires separate explicit
> user authorisation.**

This sprint **is** that authorisation. The change is pre-sanctioned by the policy
document, not a unilateral CI edit.

---

## 2. Before → after

Both automatic paths are now **deferred**; the authorised consolidated run remains
one deliberate action away.

| Event | Before | After (policy ACTIVE) | Reason code |
|---|---|---|---|
| `pull_request` | never ran | never runs | `FULL_SUITE_NOT_ENABLED_FOR_EVENT` |
| `push` → base | **ran** | **deferred** | `TEMPORARY_FULL_SUITE_POLICY_ACTIVE` |
| `schedule` (weekly) | **ran** | **deferred** | `TEMPORARY_FULL_SUITE_POLICY_ACTIVE` |
| `workflow_dispatch`, `run_full_suite=true`, no override | ran | **deferred** | `TEMPORARY_FULL_SUITE_POLICY_ACTIVE_OVERRIDE_REQUIRED` |
| `workflow_dispatch`, `run_full_suite=true` **+ override** | ran | **authorised** | `AUTHORISED_CONSOLIDATED_FULL_SUITE` |
| policy state unresolvable | n/a | **deferred (fail closed)** | `POLICY_STATE_UNRESOLVED_FAIL_CLOSED` |

Once the policy is flipped to `RETIRED`, the previous cadence returns **exactly** —
schedule, push-to-base and manual dispatch all authorised again, and `pull_request`
still never runs the suite.

---

## 3. Design

### Chosen: Option A — retain the triggers, gate the job

Both `schedule` and `workflow_dispatch` stay in the workflow. Only *when* the gate
may execute changed.

Rejected alternatives:

- **Option B — delete the `schedule` trigger.** Would satisfy
  `full_suite_fallback_triggers` (dispatch survives), but it removes a trigger
  rather than deferring a run, is less reversible, and destroys the transparent
  "this was deliberately deferred" evidence trail the policy values.
- **Option C — a scheduled policy-report-only job.** Redundant: with the gate
  deferred, a scheduled run *already* executes every other gate and reports the
  policy decision.

### One canonical state — no scattered boolean

`.github/ci-policy/full-suite-policy.json` holds the status **once**. Both layers
read that same file and neither carries a duplicate:

```
.github/ci-policy/full-suite-policy.json      <- the only place status lives
        |                          |
   bash resolver             PHP governance
scripts/ci/resolve-           CiRuntimeControlScanner
  full-suite-policy.sh         ::temporaryFullSuitePolicyPosture()
        |                          |
   classify job outputs      foundation:ci-runtime-control-check
        |
   full_suite_gate `if:`
```

`config/ci_runtime_control.php` declares only the **contract** (allowed statuses,
deferred events, required reason codes, resolver markers) — never the state itself.
A test asserts the config has no `status`/`active` key, so the duplication this is
meant to prevent cannot be reintroduced silently.

### Fail closed

Missing file, unreadable file, unknown status, or **more than one** status token →
`UNRESOLVED` → treated as ACTIVE → Full Suite **not** authorised.

Failing closed here can only *defer a run*; it can never hide a failure. That is
the safe direction, and it is why the resolver restricts its match to the literal
tokens `ACTIVE|RETIRED` — a corrupted value can never be read as `RETIRED`.

### Defence in depth in the job condition

The original event conditions are **retained** and ANDed with the new
authorisation check:

```yaml
needs.classify.outputs.full_suite_authorized == 'true' &&
(github.event_name == 'schedule' || … )
```

An AND can only *narrow*. Even a bug in the resolver could never widen the gate
beyond its pre-policy cadence.

### The reason is recorded on every run

The decision is resolved in `classify` — a job that always runs, on GitHub-hosted
infrastructure. So a run whose Full Suite job is skipped still carries a
machine-readable record of *why*:

```
temporary_full_suite_policy_active=true
full_suite_authorized=false
full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE
```

This is deliberately more specific than "not needed": a future operator must be
able to tell **deferred** from **unnecessary**.

---

## 4. Full Suite entry-point inventory

Every path found by a repository-wide search, and its posture:

| Entry point | Trigger | Can run Full Suite? | Gated by policy? | Safe while ACTIVE? |
|---|---|---|---|---|
| `.github/workflows/foundation-evidence-gates.yml` → `full_suite_gate` | schedule / push / dispatch | **yes** | **yes (this sprint)** | yes |
| same workflow → `critical_test_gate` (+ self-hosted variant) | PR / push / etc. | no — filtered subset | n/a | yes |
| same workflow → `selective_module_gate` | classifier | no — module filters | n/a | yes |
| `scripts/ci/foundation-evidence-gates.sh` → `run_full_suite()` | only when `RUN_FULL_SUITE=true` | yes | not set by any workflow job | yes — never invoked by CI |
| `php artisan sprint:test --all-required` | local, manual | **yes** (fail-closed escalation) | superseded by policy §5.1 | yes — policy already forbids it |
| `scripts/test.sh`, `scripts/check.sh`, `scripts/sprint-finish-check.sh` | local, manual | yes | developer-invoked only | yes — not wired to CI |
| `.github/workflows/deploy-vps.yml` | dispatch | no | n/a | yes |
| `.github/workflows/cicd-ctrl-3-db-guard-evidence.yml` | dispatch | no | n/a | yes |

Only the first row needed a change. The rest are documented so the inventory is
complete rather than convenient.

> **Note on `run_full_suite=required`.** The classifier emits this advisory output
> for high-risk profiles, but **no workflow job consumes it** — `full_suite_gate`
> keys off the event conditions and, now, the policy decision. It is advisory only,
> and this sprint does not change that.

---

## 5. Evidence semantics preserved

Policy §7.1 — **a green Full Suite job does not mean the suite ran** — is
unchanged and reinforced. Both the `Run full Pest suite` step and the
`Note skipped full suite` step remain, and a test asserts they do.

Under this sprint the job is **skipped entirely** on schedule/push, which is
unambiguous at job level; the *reason* lives in the always-run `classify` job.
Read the step, never the job:

```bash
gh run view <run-id> --json jobs \
  --jq '.jobs[]|select(.name=="NSF-R011 Full Suite Gate")|.steps[]
        |select(.name|test("full Pest suite"))|"\(.conclusion)\t\(.name)"'
```

---

## 6. Tests

`tests/Feature/Cicd/TemporaryFullSuiteScheduleGateTest.php` — **25 tests, 158
assertions.** They compose the two real artefacts rather than paraphrasing them:
the real resolver script produces `full_suite_authorized`, which is fed into an
evaluator of the **real workflow `if:` expression**.

Simulating the decision is the point: proving the gate *cannot* fire must not
require firing it.

Coverage: the full decision matrix (schedule / push / PR / dispatch × override);
fail-closed across five malformed-state variants plus a missing file; retirement
restoring the cadence exactly; the workflow wiring; the override input defaulting
off; the always-on classifier publishing the reason; triggers and job preserved;
step-level evidence semantics; governance GO with rules R012–R014; a decoy
workflow being **detected** as unenforced; the resolver being read-only; and the
CICD-CTRL-1 safety invariant left untouched.

The suite is wired into the CI critical filter via the existing `Cicd` selection.

---

## 7. Security & governance review

| Question | Finding |
|---|---|
| Can `schedule` still reach the Full Suite another way? | No. It is the only scheduled workflow with a Full Suite job; the shell fallback is never invoked by CI. |
| Can `workflow_dispatch` bypass the policy accidentally? | No. Two independent boolean inputs, both defaulting to `false`. |
| Can someone claim PASS from a green job? | No — §7.1 semantics preserved and asserted by test. |
| Can CI and the docs drift apart? | No. One canonical state file read by both layers; a test forbids a duplicate boolean in config. |
| Can suppression disable normal CI? | No. Only `full_suite_gate` is affected; a test asserts the three always-on jobs survive and `paths-ignore` is absent. |
| Can the final consolidated run still happen? | Yes — asserted by an explicit test. |
| Can a docs-only change silently retire the policy? | No. Retirement requires editing the canonical JSON state; a test pins it to `ACTIVE`. |
| Does anything fail **open**? | No. Every unresolved path resolves to deferred. |
| Was any failure hidden? | No. This sprint defers a run; it changes no test, no expectation, and no baseline. |
| Can retirement sneak through as a docs-only change? | No — and this was verified, not assumed. `.github/ci-policy/full-suite-policy.json` classifies as `ci_workflow` (high risk), so editing the status always runs the full critical gate. It can never resolve to `docs_only`. |
| Is there another scheduled path to the suite? | No. `foundation-evidence-gates.yml` is the only workflow with a `schedule:` trigger, and `RUN_FULL_SUITE` — the env flag guarding `run_full_suite()` in `scripts/ci/foundation-evidence-gates.sh` — is set by no workflow; CI only ever invokes that script `--critical-only`. |
| Can an env default enable the suite? | No. The resolver reads **no** environment variable for policy state; the status comes only from the canonical file. |

**Finding raised and fixed during review — shell injection surface (LOW).** The
first implementation interpolated `${{ github.ref }}` directly into the resolver
step's `run:` block. A ref is attacker-influenceable text, and a branch name
containing a single quote could terminate the quoting and join the command. This
also deviated from the convention the sibling runner-routing step already used.

Fixed by passing every context value through an `env:` block and referencing it as
`"$EVENT_REF"`, matching the established pattern. A regression test now asserts the
step declares those env keys and that `run:` contains no `github.*` / `inputs.*`
interpolation.

**Privacy / safety:** no migration, permission, role, route, schema or clinical
path touched. No secret added. No patient data involved.

---

## 8. Rollback

```bash
git revert <merge-sha>          # restores the previous full_suite_gate condition
```

Rollback is mechanically simple, but **it is not risk-free**: reverting while the
policy is still ACTIVE re-enables the weekly and post-merge Full Suite, restoring
exactly the gap this sprint closed. That is a governance regression, not a neutral
undo.

Prefer the narrower control when the intent is only to run the suite once:
**dispatch with both inputs set** — no revert required.

The `full_suite_fallback_triggers` invariant and
`foundation:ci-runtime-control-check` remain satisfied in both directions.

---

## 9. Retirement

Flip `status` to `RETIRED` in `.github/ci-policy/full-suite-policy.json`. That is
the **only** supported way to restore the automatic cadence, and it is a deliberate
governance act reserved for the consolidated closure (policy §10) — never a side
effect of another sprint.

---

## 10. Posture

```
GLOBAL TEMPORARY FULL-SUITE POLICY        = ACTIVE (unchanged)
FULL_SUITE_STATUS                         = SKIPPED_BY_GLOBAL_TEMPORARY_POLICY
FULL_SUITE_EXECUTION_COUNT (this sprint)  = 0
```

This sprint's GO tag attests to the **gating mechanism**. It does **not** mean the
consolidated Full Suite passed, that the policy is retired, or that any deferred
fix sprint may now be tagged.
