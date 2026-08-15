# DEVFLOW-FIX-BASE-REF-1 — Canonical Remote Base Resolution

**Branch:** `feature/devflow-fix-base-ref-1-canonical-remote-base-resolution`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Base SHA at start:** `b8038c567360b323bc56a185cd518ed1c4b41a28`
**Type:** `RUNTIME_FIX` (governance/devflow tooling only)
**Runbook:** `docs/runbooks/devflow-canonical-base-resolution-runbook.md`
**Rule mirror:** `.cursor/rules/92-devflow-canonical-base-resolution.mdc`

---

## 1. Root cause

Governance tooling diffed against a bare **branch name**:

```php
// app/Support/Devflow/GitChangeInspector.php (before)
$base = $baseRef ?? (string) config('devflow.manifest.required_base_branch', 'HEAD');
$diff = $this->runGit(['diff', '--name-only', "{$base}...HEAD"]);
```

Git resolves a bare name to the **local** `refs/heads/<branch>`. That ref is
whatever the developer's checkout last fetched — frequently stale.

This was reproduced live at sprint start, not inferred:

```
LOCAL_BASE_SHA  = a3d1723e8df41165fb89956ed0b5c0ece7c0fd00   (3 commits behind)
REMOTE_BASE_SHA = b8038c567360b323bc56a185cd518ed1c4b41a28   (canonical)
```

Consequences already observed across earlier workstreams:

* scope audit computed over unrelated base commits → inflated module count
* manifest-vs-diff contradiction checks evaluated against the wrong file set
* security review fed a ~14.7 MB diff from an unrelated primary checkout
* Graphify pre-state taken from a different branch
* false "candidate regression" and false "pre-existing failure" conclusions

Three failure paths, one class of defect:

| Path | Mechanism |
|---|---|
| stale local | bare branch name → `refs/heads/<base>`, silently behind |
| wrong checkout | tool analysed the primary checkout, not the task worktree |
| moving base | CI compared against `origin/<base_ref>`, which advances mid-run |

## 2. Canonical authority model

```
CANONICAL_REMOTE            = origin              (explicit; never auto-selected)
CANONICAL_BASE_BRANCH       = config('devflow.manifest.required_base_branch')
EXACT_SHA_PRIORITY          = 1  explicit verified exact SHA
REMOTE_PRIORITY             = 2  origin/<branch> after controlled fetch
LOCAL_FALLBACK              = NONE
REMOTE_FAILURE_BEHAVIOR     = FAIL_CLOSED
```

Branch name = **discovery** authority. Exact SHA = **comparison** authority.

## 3. Consumer inventory

| Consumer | Old behaviour | New base authority | Range semantics | Status |
|---|---|---|---|---|
| `GitChangeInspector::changedFiles()` | bare branch name → local ref | `CanonicalBaseRefResolver` (pinned SHA) | `merge-base(BASE_SHA, HEAD)` | **FIXED** |
| `ResolvesSprintContext` (shared trait) | passed `null` → same defect | resolves once, pins, exposes metadata | inherited | **FIXED** |
| `sprint:manifest-check` | diffed vs stale local; empty set passed silently | pinned SHA; unresolved ⇒ **NO-GO** | merge-base | **FIXED** |
| `sprint:scope-audit` | same | pinned SHA; unresolved ⇒ **NO-GO** | merge-base | **FIXED** |
| `sprint:audit-plan` / `test-plan` / `test` / `prepare` | same | pinned SHA (+ `--base-sha` / `--base-branch`) | merge-base | **FIXED** |
| `scripts/ci/resolve-gates.sh` | `--base` unvalidated; two-dot fallback | validated, pinned to exact commit; publishes authority | merge-base, two-dot fallback | **FIXED** |
| CI `classify` job | `origin/${{ github.base_ref }}` (moving) + `--depth=1` | `github.event.pull_request.base.sha` (immutable) | merge-base | **FIXED** |
| `SprintManifestValidator` | compares base branch **name** only | unchanged — name comparison is correct here | n/a | **ALREADY SAFE** |
| `SprintReleaseChecker` / `SprintEvidenceGenerator` | branch/HEAD/tag only, no diff | unchanged | n/a | **NOT BASE-DEPENDENT** |
| `CicdEnterpriseGateScanner` | static text scan of workflow | unchanged | n/a | **NOT BASE-DEPENDENT** |
| `config/foundation_governance.php` `base_branch` | metadata | unchanged | n/a | **NOT BASE-DEPENDENT** |
| Security review / Graphify wrappers | not source-controlled here | context verification documented in runbook §10 | n/a | **FOLLOW-UP (documented)** |

## 4. Delivered

**New**

* `app/Support/Devflow/CanonicalBaseRef.php` — immutable pinned result + stable
  `BASE_SOURCE` / `BASE_BRANCH` / `BASE_SHA` / `HEAD_SHA` output contract.
* `app/Support/Devflow/CanonicalBaseRefResolver.php` — the single resolver.
* `app/Console/Commands/DevflowBaseRefCheckCommand.php` — `devflow:base-ref-check`.
* `config/devflow.php` → `base_resolution` block (remote, fetch, patterns,
  declared forbidden fallbacks).
* `docs/runbooks/devflow-canonical-base-resolution-runbook.md`.
* `.cursor/rules/92-devflow-canonical-base-resolution.mdc`.
* `tests/Feature/Foundation/DevflowCanonicalBaseRefTest.php` (38).
* `tests/Feature/Cicd/CiClassifierBaseAuthorityTest.php` (22).

**Changed**

* `GitChangeInspector` — consumes the resolver; returns `base_ref` metadata;
  an unresolvable base yields **unresolved**, never an empty change set.
* `ResolvesSprintContext` — one pinned resolver per command; `--base-sha` /
  `--base-branch`; `reportBaseAuthority()`.
* `sprint:manifest-check`, `sprint:scope-audit` — fail closed on an unresolved
  base; print/serialize the authority.
* `sprint:audit-plan`, `sprint:prepare`, `sprint:test`, `sprint:test-plan` —
  base options (existing `--base` on `test-plan` preserved).
* `scripts/ci/resolve-gates.sh` — `--base-sha`, ref validation, exact-commit
  pinning, `base_source` / `base_sha` / `head_sha` in kv + JSON + stderr summary.
* `.github/workflows/foundation-evidence-gates.yml` — immutable PR base SHA,
  base-authority evidence step, job outputs, `Devflow` added to both critical
  filter variants.
* `DevflowScanner::baseResolutionPosture()` + `DEVFLOW-BASE-AUTHORITY` check.
* `DevflowGovernanceService` — rules **DEVFLOW-R011..R020**.
* `AppServiceProvider` — resolver binding (per-resolution, never shared).

## 5. Regression matrix

| Case | Expected | Result |
|---|---|---|
| local base stale (A) vs remote (B) | resolve **B** | PASS |
| local base ahead of remote | resolve **remote** | PASS |
| local base diverged | resolve **remote** | PASS |
| explicit exact SHA supplied | resolve **that SHA** | PASS |
| remote unavailable, no exact SHA | **FAIL CLOSED** | PASS |
| invalid SHA (`HEAD`, `HEAD~1`, `--help`, abbreviated, shell metachars) | reject | PASS (11 variants) |
| missing object (valid syntax) | fail closed | PASS |
| non-commit object (tree) | fail closed | PASS |
| unsafe branch name (`--upload-pack=…`, `..`, `~`, `^`, `:`, space, `@{`) | reject | PASS (8 variants) |
| configured remote absent | fail closed | PASS |
| multiple remotes | never auto-select | PASS |
| remote moves mid-run | pinned SHA unchanged | PASS |
| wrong primary checkout | audit the pointed-at worktree only | PASS |
| path is not a repo root | `BASE_WRONG_REPOSITORY` | PASS |
| stale base would widen the diff | narrow, correct file set | PASS |
| unresolved base | `resolved=false`, not empty | PASS |
| CI: ref as git option (7 hostile values) | strongest gate, no injection | PASS |
| CI: docs_only invariant preserved | only docs_only skips critical | PASS |
| CI: same pinned base ⇒ same classification | deterministic | PASS |

## 6. Scope discipline

**No** production business-logic change. Untouched: clinical rules, Legacy RME
semantics, billing, Lab, SATUSEHAT, doctor scope, branch isolation, inventory
ledger, deploy/rollback/backup contracts. No migration, no route, no permission,
no seeder. Clinical timezone stays `Asia/Makassar`. Legacy migration capability
stays **OFF**, admission **EMPTY**, active wave **NONE**.

Historical GO tags (`ROLL-4-WAVE-1`, `INFRA-SEC-ENV-1`, `INFRA-SEC-RUNTIME-1`,
`DEPLOY-HARDEN-1`, `LEGACY-RME-DATE-TZ-1`) remain immutable and unmodified.

## 7. Two pre-existing fail-opens found while proving the fix

Both were surfaced by running the new tooling, not by reading, and both live in
the same `changedFiles()` function this sprint owns — so both are fixed here.

**(a) Modified tracked paths were truncated by one character.**
`lines()` trimmed each porcelain line *before* `substr($line, 3)`. Porcelain
emits a leading status column (`" M path"`), so trimming shifted every modified
tracked path left by one:

```
app/Policies/ExamplePolicy.php   ->  pp/Policies/ExamplePolicy.php
database/migrations/2026_...php  ->  atabase/migrations/2026_...php
config/devflow.php               ->  onfig/devflow.php
```

`pp/Policies/…` does not match `#^app/Policies/#`, so
`security_impact=false` and `schema_change=false` contradictions **never fired**
for a modified (as opposed to newly added) policy or migration file.

**(b) Untracked files in a brand-new directory were collapsed.**
Default `git status --porcelain` reports `?? newdir/` instead of the files
inside it. A new module folder full of `.js` or migration files could therefore
never match any extension- or filename-based contradiction pattern.

Fix: parse NUL-terminated porcelain (`-z`) with `-uall` and
`core.quotePath=false`, slicing at the exact offset without pre-trimming; both
sides of a rename are recorded. Regression tests cover modified, staged,
untracked, renamed, and space-containing paths.

## 8. Notable gotcha

`git rev-parse --verify -- <rev>` is **wrong**: for `rev-parse`, `--` marks the
start of *paths*, so it asks about a file named `<rev>` and always fails. The
first classifier implementation carried this bug and every happy-path case fell
through to `unknown_high_risk` — caught by the new tests, not by reading. Option
injection at that call site is blocked by **validation**, not by `--`.
`git diff` does correctly take a trailing `--`.

## 9. Verification

```bash
php artisan devflow:base-ref-check --strict
php artisan foundation:devflow-check --strict
php artisan sprint:manifest-check
php artisan sprint:scope-audit
php artisan test --filter='DevflowCanonicalBaseRef|CiClassifierBaseAuthority'
```
