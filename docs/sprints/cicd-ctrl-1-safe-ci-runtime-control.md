# CICD-CTRL-1 — Safe CI Runtime Control & Required-Gate Optimization

**Branch:** `feature/cicd-ctrl-1-safe-ci-runtime-control`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Type:** CI/CD control sprint (not a product feature sprint).

## Problem

Every pull request runs the full Foundation Evidence Gates chain
(`quality → critical (~21m) → release_safety → nsf10`) sequentially, so even a
docs-only change waits ~28–46 minutes. The NSF-R011 critical regression step is
the bottleneck.

## Goal

Make normal PRs run fast enough **without** letting any risky change bypass the
required quality, security, governance, smoke, or release-safety gates.

## Safety model — DEFAULT-STRONG

The core rule is: **the default must be safe.**

- If a change set cannot be classified safely → `unknown_high_risk` → everything runs.
- If there is any uncertainty (no diff, unreachable base, unknown path) → stronger gate.
- Optimization is applied **only** when safety is proven.
- The **only** profile allowed to skip the expensive critical Pest step is
  `docs_only` (every changed file is Markdown documentation).
- Security / governance / release-safety / release-evidence / smoke gates
  **always run** — the classifier can never skip them.

## Gate profiles (`scripts/ci/resolve-gates.sh`)

Strongest first. The strongest present category wins.

| Profile | Trigger (examples) | Critical tests |
|---|---|---|
| `unknown_high_risk` | any unclassified path, empty/failed diff | **run** |
| `ci_workflow` | `.github/workflows/*`, `scripts/*` | **run** (+ all module tests) |
| `dependency_or_build` | `composer.*`, `package*.json`, lockfiles, `vite/tailwind/phpunit/pint` config | **run** (+ all module tests) |
| `permissions_security` | policies, middleware, permission/role seeders, BranchContext | **run** (+ permission tests) |
| `runtime_app` | `app/*`, `routes/*`, `database/*`, `config/*`, `bootstrap/*`, `tests/*`, any `*.php` | **run** |
| `ui_only` | `resources/css|js/*`, `resources/*`, `docs/ui/*` | **run** (+ UI tests) |
| `docs_only` | Markdown docs only (`docs/**/*.md`, `*.md`, `.cursor/rules/*`) | **SKIP** |

The script emits parseable `key=value` output:
`gate_profile`, `run_critical_tests`, `run_ui_tests`, `run_permission_tests`,
`run_inventory_tests`, `run_rme_tests`, `run_lab_tests`, `run_build`,
`run_full_suite`, `changed_file_count`, `classification_reason`. A human-readable
summary (changed files, categories, profile, gates, safety statement) goes to
stderr. With `--github-output` the keys are appended to `$GITHUB_OUTPUT`.

Local usage:

```bash
scripts/ci/resolve-gates.sh --base origin/<base> --head HEAD
git diff --name-only A B | scripts/ci/resolve-gates.sh --changed-files-stdin
scripts/ci/resolve-gates.sh --changed-files-file /tmp/files.txt --json
```

## CI tiers (Foundation Evidence Gates workflow)

1. **Always-on** — `quality_gate` (build + Pint + diff), `release_safety_gate`
   (NSF-9: roadmap, feature flags, cache/queue/idempotency/security/ENT-5..16 +
   release-safety + smoke), `nsf10_release_evidence_gate` (evidence capture +
   check). Never gated by the classifier.
2. **Critical Test Gate** — `critical_test_gate`. The migration + governance
   audit steps always run; only the expensive `php artisan test --filter=...`
   step is skipped for a proven `docs_only` change (a skip note is recorded as
   evidence instead).
3. **Selective Module Gate** — `selective_module_gate` (new, parallel). Adds
   Inventory / Lab / UI / Permission suites for the modules the classifier
   detected. These are **not** in the critical filter, so this is *additive*
   coverage — PRs now run the relevant module tests that previously only ran on
   push-to-base. It never reduces the critical gate.
4. **Release Safety Gate** — always-on (see tier 1).
5. **Full Suite Gate** — `full_suite_gate`. Preserved as a fallback: it runs on
   `schedule` (weekly), manual `workflow_dispatch`, and push-to-base. For a
   docs-only push it skips the heavy step (the weekly run still covers it).
6. **Anti-abuse fallback** — workflow/script/dependency/unknown changes force
   the strongest profile and all module tests; an empty/failed diff falls back
   to `unknown_high_risk`; `paths-ignore`/blanket path filtering is forbidden;
   gate selection is decided by the audited classifier, not opaque path globs.

## What always runs / runs selectively / forces stronger gates

- **Always runs:** quality (build/Pint/diff), governance audit, NSF-9
  release-safety + smoke, NSF-10 release evidence, foundation/security/roadmap
  checks.
- **Runs selectively:** the expensive critical Pest regression (skipped only
  for docs_only) and the additive module suites (run for the changed module).
- **Forces stronger gates:** workflow, script, dependency/lockfile, test,
  migration, route, policy, permission, config, and any unknown path.

## Full-suite fallback / manual / scheduled behaviour

The full Pest suite is **not deleted**. It stays available via weekly
`schedule`, manual `workflow_dispatch` (`run_full_suite`), and push-to-base.
It should be run before any GO tag / release, and it is the required fallback
for high-risk changes.

## Governance protection

- `config/ci_runtime_control.php` — the safe gate-control contract
  (profiles, `skip_critical_profiles = [docs_only]`, `default_profile =
  unknown_high_risk`, always-on jobs, required/forbidden workflow markers).
- `App\Support\Cicd\CiRuntimeControlScanner` — read-only posture checks of the
  classifier script and workflow.
- `App\Services\Foundation\CiRuntimeControlGovernanceService` — publishes
  **CICDCTRL-R001..R011** and re-verifies the ENT-10 enterprise CI/CD gate stays
  GO (runtime optimization can never ship on a broken enterprise gate).
- `php artisan foundation:ci-runtime-control-check [--json] [--strict]` —
  read-only governance command. Informational; not wired into the blocking
  combined decision.

## Tests

`tests/Feature/Cicd/SafeCiRuntimeControlTest.php` (14) drives the real
classifier with synthetic change sets — docs-only, app, route, migration,
config, test, permission/policy/middleware, workflow, dependency, UI, inventory,
lab, unknown path, mixed docs+code, empty — and asserts high-risk change sets
never select a weak gate, plus the scanner/governance/command postures.

## Confirmations

- No safety, security, release-safety, smoke, or governance gate weakened.
- No existing workflow removed; full-suite gate preserved.
- No app runtime behaviour change.
- No route / policy / permission / query / data behaviour change.
- No schema / migration change.
- No paid CI SaaS; no heavy dependency added.

## Next recommended sprint

**Inventory Sprint 68.45** (unless the repository roadmap/config names a more
authoritative next sprint).
