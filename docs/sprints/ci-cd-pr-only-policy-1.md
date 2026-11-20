# CI-CD-PR-ONLY-POLICY-1 — phase B: release guard accepts verified manual-dispatch CI (rule 236 §5)

- Type: SECURITY_FIX · Module: Deploy · Manifest: `.sprint/ci-cd-pr-only-policy-1.yml` (dedicated; `.sprint/current.yml`
  still belongs to DMS-RME-COMPREHENSIVE-CLOSURE-1 and is untouched)
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3a231c28`
- Planned GO tag: `ci-cd-pr-only-policy-1-phase-b-go` (only after merge, deploy and production verification, CG-16)
- Rule: `.cursor/rules/236-direct-signed-release-approval.mdc` §5 (amendment); runbook
  `docs/runbooks/production-host-identity.md` §6g

## State

| State | Status |
|---|---|
| IMPLEMENTED IN BRANCH | yes — `security/ci-release-guard-dispatch-evidence-1` |
| MERGED | **no** — needs a separate owner merge authorization |
| DEPLOYED | **no** — needs a release under the currently installed guard (push evidence) |
| VERIFIED IN PRODUCTION | **no** — first `check-approval` GO on a dispatch run (runbook §6g step 0) |

Gate 1 (RME-LAB-FEE-APPROVAL-1) stays **NO-GO**. BK-2 not invoked. No production access in this phase.

## Owner decisions (CG-22)

1. 2026-11-21 — target policy: **CI runs automatically only for pull requests**; manual CI stays available through
   `workflow_dispatch`; deployment stays manual on the production host.
2. 2026-11-21 — after the audit showed that removing the base `push` trigger would deny every contract-3 release, the
   owner authorised **"RELEASE GUARD COMPATIBILITY PR ONLY"**: accept push **or** manual dispatch evidence for the
   exact release commit; do **not** remove the `push` or `schedule` triggers yet; no merge, deploy or GO tag.

## Release guard findings (before this change)

Every place that assumed a push run:

| Where | Assumption |
|---|---|
| `scripts/lib/release-approval.sh` `dms_release_approval_ci_verify` | query `actions/runs?head_sha=…&event=push&branch=…` |
| same, evaluator `runs` mode | `r.get("event") == "push"`; deny text "no push run of …" |
| `deploy/production-host.conf` | "the latest push run of this workflow" |
| rule 236 §2.3, §4; runbook §6g step 1 | "post-merge push CI", "push run" |
| `DeployHardenDirectApprovalTest` | fixtures `event => push`; the base workflow keeps `push: branches: [base]` |

Kept unchanged on purpose: exact SHA, branch, workflow path, repository and fork checks; latest-run rule;
`filter=latest` jobs; required jobs; required and forbidden steps; rate-limit budget (2 requests, reserve); approval
signature, window, scope, spent release and approval ids; contract-2 path (never asks GitHub).

## Design

- `DMS_PRODUCTION_CI_EVENTS="push workflow_dispatch"` (authority) lists the run events that count. Strict parse:
  only `push` / `workflow_dispatch`, single spaces, no duplicate. **Absent = push only**, so an older authority and
  the host's installed guard behave exactly as before. One event keeps GitHub's event filter (push: the original URL,
  byte for byte); two events drop it and the evaluator filters every run.
- **Exact SHA binding for dispatch.** GitHub's dispatch API takes a branch or tag name, never a commit, and runs on
  that ref's tip; the run's `head_sha` is the tip at dispatch time. The guard keeps `head_sha == --approved-sha`, so a
  dispatch after the branch moved on proves the newer commit and is not evidence for the release. The base branch
  membership of the commit is still proven by the guard's own git ancestry checks.
- **Latest accepted run decides** across both events (a newer failed/running push or dispatch denies).
- **No evaluation runs.** `DMS_PRODUCTION_CI_EVALUATION_STEP="NSF-R011 Critical Test Gate:Start resource sampler
  (CI-PERF-04 evaluation)"`: a job that took this step (present, not skipped) denies — rule 212 §3. Mandatory whenever
  `workflow_dispatch` is listed; its job must be a required job; pinned in `config/deployment_entrypoint.php`, and a
  test proves the step exists in both critical-gate variants and runs only on a labelled evaluation.
- Dispatch inputs are not visible in the run API, so the guard judges the run by its jobs and steps: `runner_mode`
  runs an equivalent gate (CICD-CTRL-3); a dispatch has no event base, so the classifier resolves the strongest
  profile and the critical tests always run; the Full Suite inputs only add work.

## Changed files

| File | Change |
|---|---|
| `scripts/lib/release-approval.sh` | `dms_release_approval_ci_events`; `ci_verify` args 8–9 (events, evaluation step); evaluator accepts listed events, reports the run's event, refuses an evaluation step |
| `deploy/production-host.conf` | `DMS_PRODUCTION_CI_EVENTS`, `DMS_PRODUCTION_CI_EVALUATION_STEP` |
| `config/deployment_entrypoint.php` | both keys required and pinned; five new guard markers |
| `tests/Feature/Deploy/DeployHardenDirectApprovalTest.php` | 10 new tests, 71 cases; helper defaults keep every existing case on the legacy (push-only) authority |
| `.cursor/rules/236-direct-signed-release-approval.mdc` | §2.3 wording, new §5 |
| `docs/runbooks/production-host-identity.md` | §6g: dispatch evidence procedure, activation and production verification |
| `.claude/rules/deploy-infra.md` | rule 236 index line |
| `.sprint/ci-cd-pr-only-policy-1.yml`, this record | DEVFLOW manifest and sprint record |

Not changed: `.github/workflows/*` (push and schedule triggers stay), application code, migrations, permissions,
flags, the runner, the immutable bootstrap, the evidence format (`approval_ci_run_id` already names the run; its
event is in the guard's PASS line in the deploy log).

## Tests (local, 2026-11-21, isolated worktree, no network, stubbed `curl`)

| Scope | Result |
|---|---|
| `tests/Feature/Deploy` + `tests/Feature/Cicd` + `DevflowAiInstructionSystemTest` + `DevflowSprintToolingTest` (one process) | 1443 tests, 10095 assertions, 0 failed, 0 skipped, exit 0 — **WARN**: every test carries the known workstation warning (the environment file is absent in a worktree; 1443/1443 identical messages, none other) |
| `DeployHardenDirectApprovalTest` | 258 cases (187 existing unchanged + 71 new), 0 failed |
| Mutation check (guard weakened, then restored byte-identical) | dropping the SHA match → 3 failed; accepting any event → 7 failed; disabling the evaluation check → 4 failed; dropping the evaluation-step requirement → 3 failed |
| `bash -n` guard; embedded Python parsed; `php -l` changed PHP | PASS |
| ShellCheck | **NOT RUN** (not installed on the workstation) |
| Full Suite | **DEFERRED** (CG-18), not passed; `sprint:test-plan` escalation recorded as deferred |

The PR's own CI run is the authoritative gate result; it is recorded in the PR.

## Activation and production verification (after a separately authorised release)

1. Merge (owner authorization). The post-merge **push** run of the merge commit must be green: the guard installed on
   the host today accepts push runs only and verifies this release.
2. Release per runbook §6g with the owner's signed approval; deploy; `verify <deploy-id>` → `DEPLOY RUNNER OK`.
   Note: the signing helper takes the GO tag from the target commit's `.sprint/current.yml`, which still names
   DMS-RME-COMPREHENSIVE-CLOSURE-1. The owner decides how this release is tagged (its own manifest switch in a later
   commit, or a CG-16 multi-sprint exception); this PR does not decide it.
3. Production verification: the first release whose newest accepted run is a dispatch → `check-approval` GO with
   `PASS: required CI — workflow_dispatch run <id>`; record the run id here. Until then: DEPLOYED ≠ VERIFIED.

## Future removal of the automatic push trigger (not in this phase)

Only after step 3 above, and by a new owner decision: a PR removes `push` (and `schedule`) from
`foundation-evidence-gates.yml`, updates `DeployHardenDirectApprovalTest`'s trigger assertion and rule 107. From then
on every release is dispatched on the base branch while its tip is the release commit (runbook §6g step 1).

## Rollback

- Before deploy: revert the squash commit through a PR. Nothing on the host changed.
- After deploy: roll back by full SHA (`scripts/rollback-vps.sh <sha>`, rule 231) to the previous release; its guard
  and authority accept push runs only, which every release still has while the `push` trigger exists. No data step.
- Narrow alternative (a reviewed release): set `DMS_PRODUCTION_CI_EVENTS="push"` in the authority; the evaluation
  step stays harmless.

## Residual risks

- Anyone with write access can dispatch the workflow. A dispatch is evidence only; it can deny (a newer failing run)
  but never approve. Actor allowlisting was not added (it would publish account names in a public repository).
- A dispatch on a tag named exactly like the base branch would report that name as `head_branch`. Its `head_sha` must
  still be the release commit, so it tests exactly the release's code and workflow; the evidence quality is the same.
- The run list is read from GitHub's first page (50 newest runs of the commit on the branch). If none of those is an
  accepted run the guard denies (fail closed); it never reads an older page.
- A dispatch and a push run on the base branch share one concurrency group (`cancel-in-progress`): one can cancel
  the other. The newest decides, so a cancelled newest run must be re-run.
