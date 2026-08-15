# DEVFLOW Canonical Base Resolution — Runbook

**Sprint:** DEVFLOW-FIX-BASE-REF-1
**Applies to:** every DEVFLOW governance/scoping tool (`sprint:*`, the CI gate
classifier, security review, Graphify interpretation)
**Status:** durable foundation — do not weaken without a dedicated sprint

---

## 1. The one rule

> The canonical comparison authority is an **exact commit SHA**.
> A base **branch name** is discovery authority only.

A bare branch name handed to git resolves to the **local** `refs/heads/<branch>`.
That ref may be stale, ahead, or diverged, and nothing in the output used to say
so — which is how a wrong diff silently produced a wrong governance conclusion.

## 2. Authority order

```
1. explicit verified exact BASE_SHA   (CI event payload / operator input)
2. origin/<canonical-base-branch>     (after a controlled fetch)
3. FAIL CLOSED
```

There is **no** step 4. The resolver never falls back to:

`refs/heads/<branch>` · `main` · `master` · `HEAD` · `HEAD~1` · latest tag

A network/fetch/auth failure with no authoritative SHA is an **error**, never a
governance PASS computed against stale local data.

## 3. Inspecting the authority

```bash
php artisan devflow:base-ref-check
php artisan devflow:base-ref-check --json
php artisan devflow:base-ref-check --strict          # non-zero if unresolved
php artisan devflow:base-ref-check --base-sha <40-hex>
```

Output contract (stable — evidence and CI both key off these names):

```
BASE_SOURCE=remote_tracking_ref | explicit_sha | github_pr_event | unavailable
BASE_BRANCH=feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
BASE_SHA=<40-hex exact commit>
HEAD_SHA=<40-hex exact commit>
MERGE_BASE_SHA=<40-hex>        # when a merge base exists
LOCAL_BASE_SHA=<40-hex>        # diagnostic only — NEVER an authority
LOCAL_BASE_STALE=YES|NO        # informational; the remote still wins
```

`sprint:manifest-check` and `sprint:scope-audit` print the same block before
their verdict, and include `base_authority` in `--json`.

## 4. What "LOCAL_BASE_STALE=YES" means

Nothing is broken. It reports that your local `refs/heads/<base>` differs from
the canonical remote. The tool already used the **remote** SHA. You may tidy up
with `git fetch origin <base>`, but you do **not** have to: staleness of the
local ref cannot change any governance result.

## 5. When resolution fails

| Failure code | Meaning | Fix |
|---|---|---|
| `BASE_AUTHORITY_UNAVAILABLE` | fetch failed / remote-tracking ref absent | `git fetch origin <base>`, or pass `--base-sha` |
| `BASE_SHA_INVALID` | a revision expression or option posed as an exact SHA | pass a full 40-hex commit id |
| `BASE_OBJECT_MISSING` | valid id, object not present and not fetchable | fetch the commit, or use a reachable base |
| `BASE_OBJECT_NOT_A_COMMIT` | id resolves to a tree/blob/unpeeled tag | pass a commit id |
| `BASE_BRANCH_INVALID` | unsafe ref name (leading dash, `..`, `~`, `^`, `:`, space, `@{`) | correct the configured base branch |
| `BASE_REMOTE_AMBIGUOUS` | configured remote missing/blank | set `devflow.base_resolution.remote` |
| `BASE_WRONG_REPOSITORY` | the path is not the repo root it claims | run the tool inside the worktree you mean to audit |

Every failure is fail-closed: the command exits non-zero and the dependent
verdict is **NO-GO**, never a pass against an empty change set.

## 6. Offline work

Set `DEVFLOW_BASE_FETCH_ENABLED=false` to skip the fetch. Resolution then uses
the remote-tracking ref that is already on disk, and still **fails closed** if it
is absent. This is not a local-branch fallback.

## 7. Worktrees

Every tool resolves `REPO_ROOT`, `CURRENT_HEAD`, `BASE_BRANCH`, `BASE_SHA` from
the repository it is pointed at. Running `sprint:scope-audit` in a task worktree
compares that worktree — the primary checkout's branch is irrelevant, even when
it sits on something unrelated. A path that is not its own repository root fails
with `BASE_WRONG_REPOSITORY` rather than analysing a different checkout.

```bash
cd /home/fikri/Projects/<task-worktree>
php artisan devflow:base-ref-check     # repository root must be THIS worktree
```

## 8. CI semantics

Pull-request runs classify against `github.event.pull_request.base.sha` — the
**immutable** base for that run. `origin/<base_ref>` is a moving branch: if the
base advances mid-run, a name-based comparison silently changes. A later run
legitimately resolves a newer base.

The classifier job checks out with `fetch-depth: 0`, and defensively fetches the
exact base object. If the base still cannot be verified, `resolve-gates.sh` falls
back to `unknown_high_risk` — the **strongest** gate, never a weaker one.

The workflow publishes `BASE_SOURCE` / `BASE_BRANCH` / `BASE_SHA` / `HEAD_SHA`
to the step summary and as job outputs.

## 9. Ref safety

Ref values are validated before they reach git:

* exact SHA — 40 or 64 hex, nothing else (no abbreviations, no expressions)
* ref name — no leading dash, no whitespace, no `..` `~` `^` `:` `?` `*` `[` `\` `@{`

They are then passed as **argument-array operands**, never interpolated into a
shell string. `git diff` calls use a trailing `--` to separate paths.

> `git rev-parse` is the exception: there `--` marks the start of *paths*, so
> `rev-parse -- <rev>` would ask about a *file* named `<rev>` and always fail.
> Validation, not `--`, is what blocks option injection at that call site.

## 10. Diagnosing a suspicious finding

Before trusting any diff-derived finding — a scope audit, a security review, a
Graphify delta — confirm the context it was produced from:

```bash
git rev-parse --show-toplevel          # which checkout?
git rev-parse HEAD                     # which HEAD?
php artisan devflow:base-ref-check     # which BASE_SHA, from which source?
```

If the tool cannot be pointed at the exact worktree, its findings are **not**
authority — review the actual feature diff directly:

```bash
git diff --stat <BASE_SHA>...HEAD
```

## 11. Governance

`foundation:devflow-check` includes the `DEVFLOW-BASE-AUTHORITY` check, which
fails when the canonical remote is not explicitly configured, the exact-SHA
pattern would accept a revision expression, the ref-name pattern would accept an
option, or the forbidden-fallback declaration is missing.

Rules **DEVFLOW-R011 … DEVFLOW-R020** are published into the informational
`devflow_governance` section of `architecture:foundation-governance-summary`.

## 12. Related

* `docs/sprints/devflow-fix-base-ref-1-canonical-remote-base-resolution.md`
* `docs/runbooks/ci-runtime-control-runbook.md` (CICD-CTRL-1 gate profiles)
* `.cursor/rules/92-devflow-canonical-base-resolution.mdc`
