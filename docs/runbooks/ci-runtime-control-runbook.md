# CI Runtime Control Runbook (CICD-CTRL-1)

**Purpose:** operate and audit the safe CI runtime control that decides which
Foundation Evidence Gates run per change set, without weakening any required
gate.

**Owner:** whoever ships to `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`.
**Review cadence:** whenever the CI workflow, the classifier, or the gate
contract changes.

## When to use

- A PR looks like it ran too few / too many gates and you want to know why.
- You changed the classifier or workflow and must confirm the safety contract.
- You need to force the full suite before a GO tag / release.

## Safe commands

Read-only, non-destructive:

```bash
# Governance posture (must be GO before merge / deploy).
php artisan foundation:ci-runtime-control-check --strict
php artisan foundation:ci-runtime-control-check --json

# Preview the gate decision locally for the current branch vs base.
scripts/ci/resolve-gates.sh --base origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report --head HEAD

# Preview from an explicit file list (one path per line).
scripts/ci/resolve-gates.sh --changed-files-file /tmp/files.txt --json

# Enterprise gate must also stay GO (runtime control depends on it).
php artisan foundation:cicd-enterprise-gate-check --strict
```

## How the CI job reads it

The `classify` job runs first and exposes outputs
(`gate_profile`, `run_critical_tests`, module flags, `run_full_suite`).
Downstream jobs consume `needs.classify.outputs.*`:

- `quality_gate`, `release_safety_gate`, `nsf10_release_evidence_gate` — always run.
- `critical_test_gate` — the migration + governance audit steps always run;
  the expensive Pest filter step is skipped only when
  `run_critical_tests == 'false'` (docs-only).
- `selective_module_gate` — additive module suites for the changed module.
- `full_suite_gate` — schedule / manual dispatch / push-to-base fallback.

## Forcing the full suite

- Manual: run the **Foundation Evidence Gates** workflow via *Run workflow*
  with `run_full_suite = true`.
- Automatic: it runs weekly (Sunday 02:00 UTC) and on push-to-base.
- Always run it before creating a GO tag.

## Forbidden / unsafe changes (blocked by governance)

- Do NOT add `paths-ignore` or blanket path filters to the workflow — gate
  selection must be the audited classifier, not opaque path globs.
- Do NOT widen `skip_critical_profiles` beyond `docs_only` without a reviewed
  sprint and new tests; `foundation:ci-runtime-control-check` fails otherwise.
- Do NOT gate the always-on security / governance / release-safety / evidence
  jobs on the classifier.
- Do NOT make CI green by skipping failures or hiding them.
- Do NOT dispatch the workflow with `run_full_suite=true` for an individual fix
  while the `GLOBAL TEMPORARY FULL-SUITE POLICY` is ACTIVE (see below).
- Do NOT manufacture a fake docs-only change set to obtain a weaker profile; the
  profile must follow the change, never the reverse.
- Never run destructive database rebuild/wipe/reset operations in CI or deploy;
  migrations stay additive and use `migrate --force` only.


## GLOBAL TEMPORARY FULL-SUITE POLICY (ACTIVE)

Canonical: `docs/governance/global-temporary-full-suite-policy.md` · rule `.cursor/rules/107-global-temporary-full-suite-policy.mdc`.

While ACTIVE, an individual fix must not run the Full Suite. Mechanics that
matter here — `full_suite_gate` fires on exactly three events:

| Event | Fires | Notes |
|---|---|---|
| `pull_request` | **no** | a fix sprint's own PR is structurally count 0 |
| `push` to base | **yes** | a squash-merge is such a push |
| `schedule` (weekly) | **yes** | calendar-driven; attributable to no sprint |
| `workflow_dispatch` + `run_full_suite=true` | yes | deliberate — forbidden while ACTIVE |

`.sprint/current.yml` matches no classifier pattern → `unknown_high_risk`, so
every real sprint enables the post-merge Full Suite step; only a genuinely
docs-only change set resolves to `docs_only` and skips it.

Preview before pushing — never guess:

```bash
scripts/ci/resolve-gates.sh --base origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report --head HEAD
```

**Post-merge run.** An official `gh run cancel` is permitted **only** when the
pre-merge candidate was green on an identical tree, the cancel lands before the
Full Suite job begins executing tests, the run ID and `startedAt == completedAt`
zero-duration proof are recorded, and no CI file is touched. Cancelling a Full
Suite that has **already begun executing**, to report a zero count, is
forbidden — that is an execution; record it and report the partial result.

**Reading the evidence.** A `success` Full Suite **job** does **not** mean the
suite ran: on a docs-only change the job is green precisely because
`Run full Pest suite` was **skipped**. Always check the step:

```bash
gh run view <run-id> --json jobs \
  --jq '.jobs[]|select(.name=="NSF-R011 Full Suite Gate")|.steps[]
        |select(.name|test("full Pest suite"))|"\(.conclusion)\t\(.name)"'
```

**Scheduled run.** Never cancel it for count management. It is an informational
baseline only and can never be cited as a fix sprint's Full Suite pass.

Narrowing the `push`/`schedule` triggers would be a CI change requiring separate
explicit user authorisation, and `full_suite_fallback_triggers` in
`config/ci_runtime_control.php` requires at least one of `schedule` /
`workflow_dispatch` to survive or `foundation:ci-runtime-control-check` fails.
## Troubleshooting

- **Classifier returned `unknown_high_risk` unexpectedly:** the base ref was
  unreachable or the diff was empty. That is the safe fallback (everything
  runs); check the `classify` job log `classification_reason`.
- **A code PR skipped critical tests:** it should never happen. Any non-Markdown
  file forces `run_critical_tests=true`. Inspect the `classify` step summary and
  file `foundation:ci-runtime-control-check` output; treat as a defect.
- **Governance command not GO:** read the non-passing checks it prints; the most
  common cause is a workflow edit that dropped a required marker or an always-on
  job, or an ENT-10 enterprise-gate regression.

## Evidence / rollback

- Evidence: `classify` job summary, `selective_module_gate` artifact, and the
  existing NSF gate artifacts.
- Rollback: revert the CICD-CTRL-1 changes (classifier script, workflow edits,
  config/command/scanner/service). This only affects CI gate selection; no app
  runtime, route, schema, or data behaviour is involved.

---

## Full Suite failure baseline (CICD-BASELINE-REVERIFY-1)

```
EXPECTED_FULL_SUITE_FAILURE_BASELINE = 0
LEGACY_9_FAILURE_BASELINE            = RETIRED
```

**Any Full Suite failure is a real regression.** There is no allowance to
subtract it against, and no code path that could subtract one. The historical
"9 pre-existing failures" residual from CICD-CTRL-3 was closed by CICD-FIX-6
(`fe36f06`) and retired per-failure by CICD-BASELINE-REVERIFY-1. Do not cite it.

### When the Full Suite goes red

1. **Read the summary line, not the warning count.** `Tests: N failed, M
   warnings …` — only `failed` matters. A large `warnings` count is the absent
   Vite manifest downgrading *passed* → *warning*; those tests executed and
   asserted. Warnings never mask failures.
2. **Get the failing identities from the log**, not from a summary:
   ```bash
   gh api repos/<owner>/<repo>/actions/jobs/<job_id>/logs \
     | grep -aE '^\s*(FAILED|⨯)'
   ```
3. **Classify before fixing.** Environment fault (wrong checkout, runner outage,
   orphaned Pest workers, missing runtime binary such as Poppler) or application
   defect? Environment faults are never catalogued as baseline debt.
4. **Check determinism before blaming a commit.** If no commit in the range
   touches the failing test or its module, suspect a flake. The known class is a
   faker-generated value compared against Blade-escaped output — see rule 92.
5. **Fix it, do not absorb it.** Never widen a filter, skip a test, delete a
   test, or open a new "expected failures" list to get back to green.

### Running the Full Suite deliberately

It does **not** run on pull requests. Trigger it on an exact SHA with:

```bash
gh workflow run foundation-evidence-gates.yml \
  --ref <branch> -f run_full_suite=true
```

It also runs on every push to the base branch, and weekly on Sunday 02:00 UTC
(10:00 WITA). It always runs on `ubuntu-latest` against `postgres:16` — it is
never routed to the self-hosted runner. Expect roughly 2.5–4 hours.

### If a baseline ever has to be re-opened

It must name exact test identities and failure signatures, the authoritative run
id and SHA, an owner, and a revalidation date — and it must never be encoded as
a machine-readable allowance that a red gate could be subtracted against. Rule
92 governs; `tests/Feature/Cicd/FullSuiteBaselineContractTest.php` enforces.

## Temporary Full Suite gating (CI-TEMP-FULL-SUITE-SCHEDULE-GATE)

While the GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE, the weekly `schedule` and
the post-merge `push` to base are **deferred in CI** — they no longer execute the
NSF-R011 Full Suite. Nothing else about CI changes: every other gate still runs on
those events.

**Check the current state**

```bash
cat .github/ci-policy/full-suite-policy.json            # the one canonical status
bash scripts/ci/resolve-full-suite-policy.sh --event schedule
php artisan foundation:ci-runtime-control-check --strict
```

**Read why a Full Suite did not run.** The always-on classifier job publishes it:

```bash
gh run view <run-id> --json jobs \
  --jq '.jobs[]|select(.name=="CICD-CTRL Gate Classifier")|.steps[].name'
# and in that job's log:  full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE
```

**Run the authorised consolidated Full Suite** (only when the user has authorised
the final closure). Both inputs are required — `run_full_suite` alone is deferred:

```bash
gh workflow run foundation-evidence-gates.yml \
  -f run_full_suite=true \
  -f full_suite_policy_override=true
```

**Never** infer a pass from a green job — read the step:

```bash
gh run view <run-id> --json jobs \
  --jq '.jobs[]|select(.name=="NSF-R011 Full Suite Gate")|.steps[]
        |select(.name|test("full Pest suite"))|"\(.conclusion)\t\(.name)"'
```

**Restoring the automatic cadence** means setting `status` to `RETIRED` in the
canonical JSON. That is a governance act reserved for the consolidated closure —
never a side effect of another sprint.

---

## Diagnosing a Critical Gate warning (CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1)

The Critical Gate declares how many warnings it expects — currently **zero**, in
`config/ci_runner.php` → `critical_gate_warning_contract`. Anything above that is
**unexplained** and fails the gate.

**A recurring warning is never a baseline until it is attributed.** Name the
emitting component, the exact resource it reads, and its effect on the exit code
before deciding anything.

Pest truncates warning text to terminal width, so CI logs alone will not give you
a full path. Recover it locally instead — boot the application under an error
handler that prints the message and a backtrace, and read the caller off frame 1.

Once you have the caller, classify the resource honestly:

| Finding | Correct response |
|---|---|
| Absent **by design**, framework treats it as optional | Provision the resource in a form that means "nothing here" — e.g. an empty file. The read then succeeds and the state is explicit |
| Absent but genuinely **required** | Fix the producer, its ordering, or the job dependency. Never downgrade a mandatory input failure to a warning |
| Emitted by a **dependency** | Fix configuration, the call contract, or the version. Never patch `vendor/`, never blanket-suppress |

**Never** close a warning with the suppression operator, `error_reporting(0)`,
stderr filtering, a broad PHPUnit suppression toggle, or a `grep -v`. The guard in
`tests/Feature/Cicd/CriticalGateWarningContractTest.php` scans every workflow,
every `scripts/ci/*.sh` and `phpunit.xml` for exactly those shapes.

**Never** raise `expected_warning_count` to absorb a new warning, and never add a
warning-text allowlist — an expected condition belongs at its causal boundary.

Verify the contract locally against any captured gate log:

```bash
php artisan ci:assert-critical-gate-warning-contract \
  --log=storage/ci-evidence/nsf-r011-critical-tests.log --json
```

It fails closed when the evidence is missing, unreadable, empty, carries no
summary, or reports zero tests — a gate whose evidence cannot be read is never
reported as clean. The test exit status keeps strict precedence in the workflow,
so this assertion can never turn a red gate green.

Note the Critical Gate does **not** require frontend build artifacts
(`tests/TestCase.php` calls `withoutVite()`); do not add a build step to chase a
missing file without proving the consumer first.
