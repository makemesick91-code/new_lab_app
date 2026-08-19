# DaengtisiaMS — Sprint Runtime Template (canonical)

This is the **permanent, inherited contract** for every DaengtisiaMS sprint.
A prompt only needs to supply: **id, type, module, objective, acceptance
criteria, impacted modules**. Everything below is inherited automatically and
must not be repeated in the prompt.

Tooling that enforces this template:
`sprint:new` → `sprint:prepare` → `sprint:manifest-check` → `sprint:audit-plan`
→ `sprint:test-plan` → `sprint:test` → `sprint:scope-audit` →
`sprint:release-check` → `scripts/sprint-release.sh` → `sprint:evidence`.

## 1. Non-negotiable contract (inherited by all types)

- **Base branch**: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`. **Never target `main`/`master`.**
- **Architecture**: `HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model`. Controllers thin; business logic in services; no logic in Blade; no React/Vue.
- **Branch isolation**: resolve the active branch with `BranchContext` (canonical `App\Modules\Branch\Services\BranchContext`). **Never trust a request `branch_id`.**
- **Ledger-only inventory**: stock is derived from `trx_inventory_movements`. No mutable stock column, ever.
- **RBAC**: 3-layer (route middleware + policy + controller `authorize`). Sidebar is never a security boundary. Audits use **effective** permissions (`can()`/`getAllPermissions()`), never direct-only `whereHas`.
- **Privacy**: KTP/NIK/scans/raw notes never rendered, exported, or logged. Mask with the canonical `SensitiveValueMasker`.
- **Migrations**: additive only. **Never** `migrate:fresh` or `db:wipe` on VPS. Backup before every migrate.
- **Shared foundations**: reuse the canonical services in `config/shared_foundations.php`; do not duplicate (`foundation:shared-service-audit`).

## 2. Manifest (source of truth)

Every sprint carries a manifest (`.sprint/current.yml`, schema in
`config/devflow.php`). It declares `type`, `module`, `base_branch`, and the
impact flags (`runtime_change`, `schema_change`, `frontend_change`,
`security_impact`, `branch_isolation_impact`, `ledger_impact`,
`deploy_required`). CI and tooling enforce that these flags match reality — a
`schema_change=false` manifest with a changed migration FAILS.

## 3. Audit depth (by type)

Read `config/sprint_profiles.php`. `sprint:audit-plan` prints the exact level +
checklist. Do not audit the whole repository on a scoped fix.

## 4. Tests

`sprint:test-plan` derives the focused + regression + escalation plan from the
git diff and `config/sprint_regression_matrix.php`. Run `sprint:test
--all-required` before release. **Never** hide skipped tests. Impact rules:
inventory ⇒ ledger tests; auth/branch/permission ⇒ security tests; schema ⇒
migration + release-safety tests; frontend ⇒ build + view compile.

### Full Suite — check the policy first

**Before planning tests, ask: is the `GLOBAL TEMPORARY FULL-SUITE POLICY`
ACTIVE?** (`docs/governance/global-temporary-full-suite-policy.md`, mirrored in `.cursor/rules/107-global-temporary-full-suite-policy.mdc`.)

If **YES** — **do not run the Full Suite for this sprint.** Follow:
targeted + dependency-aware cumulative regression → required non-Full-Suite CI →
merge → deploy → smoke → rules → cleanup → close at
`WATCH — PENDING CONSOLIDATED FULL SUITE`. The Full Suite is *deferred* to one
authoritative run on the frozen final integrated SHA; every other gate stays
mandatory. Never dispatch `run_full_suite=true`, and never claim a pass you did
not obtain.

If **NO** — the normal cadence applies.

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

So while the policy is ACTIVE, the "Run `sprint:test --all-required` before
release" instruction above is **superseded** by the focused sequence.

## 5. Release (Definition of Done)

See `docs/engineering/release-definition-of-done.md`. Summary: green required
CI → PR merged → backup → deploy (`scripts/sprint-release.sh --apply`) → smoke →
**then** annotated GO tag → exact-match local/remote/VPS → evidence
(`sprint:evidence`). A GO tag is **never** created before deploy + smoke.

## 6. Governance & knowledge

Update `CLAUDE.md`, the matching `.cursor/rules/*.mdc`, and `docs/sprints/`.
Governance rule/doc text must not contain the environment-file literal (use
"the environment example file"). Run `graphify update .` after code changes.

Every final sprint report must state, truthfully:

```
FULL SUITE STATUS
FULL SUITE EXECUTION COUNT
GLOBAL TEMPORARY POLICY ACTIVE = YES/NO
```

**Job skipped ≠ tests passed. Job cancelled ≠ tests passed.**
