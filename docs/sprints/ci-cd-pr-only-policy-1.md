# CI-CD-PR-ONLY-POLICY-1 — phase B: release guard accepts verified manual-dispatch CI (rule 236 §5)

- Type: SECURITY_FIX · Module: Deploy · Manifest: `.sprint/current.yml` since owner decision OD-B3-1 (2026-11-21);
  before that the dedicated `.sprint/ci-cd-pr-only-policy-1.yml`. The DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest is
  parked verbatim at `.sprint/dms-rme-comprehensive-closure-1.yml` ("Manifest switch" below).
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3a231c28` (code, #673);
  manifest switch @ `452011a8`
- Planned GO tag: `ci-cd-pr-only-policy-1-phase-b-go` (only after merge, deploy and production verification, CG-16)
- Rule: `.cursor/rules/236-direct-signed-release-approval.mdc` §5 (amendment); runbook
  `docs/runbooks/production-host-identity.md` §6g

## State

| State | Status |
|---|---|
| IMPLEMENTED IN BRANCH | yes — code: `security/ci-release-guard-dispatch-evidence-1`; manifest switch: `governance/ci-cd-pr-only-policy-1-phase-b-current-manifest` |
| MERGED | code: **yes** — #673 squash-merged as `452011a8`; its post-merge push run 44823046028 is green. Manifest switch: **no** — PR stops at review; merging needs a separate owner approval |
| DEPLOYED | **no** — the release target is the manifest-switch merge commit, released under the installed guard (push evidence) |
| VERIFIED IN PRODUCTION | **no** — first `check-approval` GO on a dispatch run (runbook §6g step 0) |

Gate 1 (RME-LAB-FEE-APPROVAL-1) stays **NO-GO**. BK-2 not invoked. No production access in this phase.

## Owner decisions (CG-22)

1. 2026-11-21 — target policy: **CI runs automatically only for pull requests**; manual CI stays available through
   `workflow_dispatch`; deployment stays manual on the production host.
2. 2026-11-21 — after the audit showed that removing the base `push` trigger would deny every contract-3 release, the
   owner authorised **"RELEASE GUARD COMPATIBILITY PR ONLY"**: accept push **or** manual dispatch evidence for the
   exact release commit; do **not** remove the `push` or `schedule` triggers yet; no merge, deploy or GO tag.
3. 2026-11-21 — **OD-B3-1, option A**: the phase-B release carries its own GO tag. `.sprint/current.yml` names this
   sprint; the DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest is parked verbatim and restored later by owner decision.
   Authorised: one governance PR (new worktree and branch, manifests, governance docs, related tests, isolated local
   tests, commit, push, PR) that stops at review; merging needs a separate owner approval. Not authorised: VPS access,
   production changes, BK-2, GO tags, approval signatures, deployment, CI trigger changes. Gate 1 of
   RME-LAB-FEE-APPROVAL-1 stays NO-GO; the Ready Pilot SPN4 target is unchanged.

## Manifest switch (OD-B3-1)

### Why the release needs it

Code binds the GO tag of a release to the release commit's own `.sprint/current.yml` (read from the git object, never
from a working tree):

| Consumer (at `452011a8`) | Effect of a mismatch |
|---|---|
| `scripts/release-approval-sign.sh` l.123–125 | refuses to sign `--go-tag` ≠ the commit's `go_tag` |
| `scripts/lib/release-approval.sh` contract 2 l.517–520, contract 3 l.1180–1183 (same check in the installed guard) | denies the record |
| `scripts/sprint-release.sh` l.150–154 | refuses to create any other tag |
| CG-16, `docs/governance/go-tag-exceptions.md` | the GO tag is the `go_tag` in `.sprint/current.yml` |

At `452011a8` that file declares `dms-rme-comprehensive-closure-1-go`, which may not be created before that sprint
closes (`docs/sprints/dms-rme-comprehensive-closure-1.md` §9.7). The multi-sprint exception does not apply: the release
carries one sprint's runtime change, an infrastructure release is not exempt for being infrastructure, and the
exception would supersede the other sprint's tag.

### What this change does

- `.sprint/current.yml` is the phase-B manifest (moved from `.sprint/ci-cd-pr-only-policy-1.yml`): header rewritten,
  impact flags and `go_tag` unchanged, `inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1` added (AIR-FND-29).
- `.sprint/dms-rme-comprehensive-closure-1.yml` is the former `.sprint/current.yml`, byte-identical (blob `d1c4af5e`),
  its `go_tag` unchanged. That sprint stays OPEN; its record and status are not touched. `sprint:new` writes named
  manifests to `.sprint/<id>.yml`, so the parked file follows the existing convention.
- `DevflowSprintToolingTest`: every manifest in `.sprint/` (the example excepted) validates; the GO tag the release
  guard reads with its own `sed` pipeline equals the YAML `go_tag`; no two manifests declare one tag.

Not changed: the guard, the authority, the signer, the runner, the workflows (`push` and `schedule` stay), application
code, other sprints' records, tags.

### Consumers of `.sprint/current.yml`

| Consumer | After the switch |
|---|---|
| `config/devflow.php` `manifest.default_path`, `ResolvesSprintContext` | every `sprint:*` command without `--manifest` reads phase B; the parked manifest needs `--manifest=.sprint/dms-rme-comprehensive-closure-1.yml` |
| `scripts/ci/foundation-evidence-gates.sh` l.164 (NSF-9) | `sprint:manifest-check --no-diff-check` on phase B: GO (local) |
| guard, signer, `scripts/sprint-release.sh` | bind `ci-cd-pr-only-policy-1-phase-b-go` on the merge commit of this change |
| `scripts/ci/resolve-gates.sh` | `.sprint/*.yml` matches no pattern → `unknown_high_risk`: the merge commit's push run runs the critical tests |
| `DevflowSprintToolingTest` l.222/227, `DevflowAiInstructionSystemTest` l.251 | pass; the phase-B tag is no superseded tag |
| `LegacyRmeProgramClosureContractTest` l.146 | needs `LEGACY-RME-PROGRAM-CLOSURE-1`: carried forward |
| `tests/Pest.php` l.2569 and the `DeployHarden*` tests | build their own fixture repositories; unaffected |

`docs/governance/global-temporary-full-suite-policy.md` §6.0 keeps a docs- or rules-only change off the manifest so it
stays `docs_only`. This change is not one: it fixes the release identity of a runtime sprint, and the guard needs a
push run of the release commit in which the critical tests ran (its forbidden step is the docs-only skip). The Full
Suite stays deferred on that push run (policy ACTIVE).

### Release target and base freeze

- The release target is the squash-merge commit of this change, not `452011a8`. The owner signs
  `--target-sha <merge commit> --go-tag ci-cd-pr-only-policy-1-phase-b-go` only after that commit's push run is green
  with the critical tests run and the CI-PERF-04 evaluation step skipped.
- From that merge until the deploy is verified and the dispatch proof (runbook §6g step 0) is recorded, only
  `docs/**/*.md` and `deploy/release-approvals/*` may land on the base: the guard refuses a target followed by anything
  else (step 9), and a dispatch runs on the branch tip. Open PR #672 touches `.claude`, `.cursor`, `CLAUDE.md` and
  `tests`, so it waits.

### Restoring the parked manifest

- When: after the phase-B release is deployed, verified and GO-tagged and its dispatch proof is recorded, and before
  any further DMS-RME-COMPREHENSIVE-CLOSURE-1 merge or release. Owner decision.
- How: one PR moves `.sprint/dms-rme-comprehensive-closure-1.yml` back to `.sprint/current.yml` (prove the blob is
  unchanged) and parks this manifest at `.sprint/ci-cd-pr-only-policy-1.yml`.
- Effect: a non-docs change with its own push run; the next DMS-RME-COMPREHENSIVE-CLOSURE-1 release targets a commit at
  or after it, and its record names `dms-rme-comprehensive-closure-1-go` as releases 1–4 did. The phase-B GO tag stays
  bound to its own commit. A restore before the phase-B deploy would make the release target undeployable (step 9).
- If it is forgotten, the signer refuses that sprint's tag (fail closed); nothing passes silently.

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
| `.sprint/ci-cd-pr-only-policy-1.yml`, this record | DEVFLOW manifest (moved to `.sprint/current.yml` by OD-B3-1) and sprint record |

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

Manifest switch (OD-B3-1), local, 2026-11-21, isolated worktree at the base `452011a8`:

| Scope | Result |
|---|---|
| `tests/Feature/Foundation` + `tests/Feature/Deploy` + `tests/Feature/Cicd` + `LegacyRmeProgramClosureContractTest` (one process) | 1941 tests, 12845 assertions, 0 failed, 0 skipped, exit 0 — **WARN**: the same workstation warning on every test (the environment file is absent in a worktree), none other |
| New manifest test, mutation check (temporary extra manifest, then removed) | quoted `go_tag`, inline comment, duplicate tag, base `main` → each fails; a valid distinct manifest passes |
| `sprint:manifest-check` (with diff), `sprint:scope-audit --strict`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict` | GO / GO / GO / 0 errors |
| Classifier on the change set | `unknown_high_risk`, `run_critical_tests=true` |
| GO tag the guard pipeline reads | `452011a8` → `dms-rme-comprehensive-closure-1-go`; this change → `ci-cd-pr-only-policy-1-phase-b-go`; parked manifest → `dms-rme-comprehensive-closure-1-go` (blob unchanged) |
| Pint (changed test) | passed |
| Full Suite | **DEFERRED** (CG-18); `sprint:test-plan` escalates (5 unmatched files) — recorded as deferred, not passed |

An earlier run of the same set with `APP_ENV=local` exported failed 5 tests that assert production-like behaviour;
unsetting it (the setting CI uses) passes them. Environmental, not caused by this change.

## Activation and production verification (after a separately authorised release)

1. Merge (owner authorization). Done for the code (#673 → `452011a8`, push run 44823046028 green). The manifest switch
   merges under its own owner approval; the post-merge **push** run of that merge commit must be green: the guard
   installed on the host today accepts push runs only and verifies this release.
2. Release per runbook §6g with the owner's signed approval; deploy; `verify <deploy-id>` → `DEPLOY RUNNER OK`.
   Decided by OD-B3-1: the target is the manifest-switch merge commit, signed with
   `--go-tag ci-cd-pr-only-policy-1-phase-b-go` ("Manifest switch" above).
3. Production verification: the first release whose newest accepted run is a dispatch → `check-approval` GO with
   `PASS: required CI — workflow_dispatch run <id>`; record the run id here. Until then: DEPLOYED ≠ VERIFIED.

## Future removal of the automatic push trigger (not in this phase)

Only after step 3 above, and by a new owner decision: a PR removes `push` (and `schedule`) from
`foundation-evidence-gates.yml`, updates `DeployHardenDirectApprovalTest`'s trigger assertion and rule 107. From then
on every release is dispatched on the base branch while its tip is the release commit (runbook §6g step 1).

Rollback once the push trigger is gone:

- `scripts/rollback-vps.sh` asks neither an approval nor GitHub, so a code rollback stays possible (owner break-glass,
  full SHA, an ancestor of HEAD, pre-rollback dump, never a data restore).
- Rollback floor: the phase-B release. An older target reinstalls an authority without `DMS_PRODUCTION_CI_EVENTS`,
  whose guard counts push runs only; with no push runs, no later contract-3 release passes step 12.
- Recovery if that happens: contract 2 (approval through a merged PR) never asks GitHub for CI
  (`scripts/lib/release-approval.sh` l.409–429: step 12 is contract 3 only), so a forward fix can still be released;
  or a reviewed PR re-adds the `push` trigger, and its own merge commit gets the push run the older guard accepts.
  Either path needs an owner decision.
- The PR that removes the triggers records the floor in rule 236 §5 and re-checks that a dispatch-capable release is
  installed and GO-tagged.

## Rollback

- Before deploy: revert the squash commit through a PR. Nothing on the host changed. Reverting the manifest switch
  alone restores `.sprint/current.yml` to DMS-RME-COMPREHENSIVE-CLOSURE-1 and leaves phase B untaggable again.
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
