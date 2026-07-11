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

## 5. Release (Definition of Done)

See `docs/engineering/release-definition-of-done.md`. Summary: green required
CI → PR merged → backup → deploy (`scripts/sprint-release.sh --apply`) → smoke →
**then** annotated GO tag → exact-match local/remote/VPS → evidence
(`sprint:evidence`). A GO tag is **never** created before deploy + smoke.

## 6. Governance & knowledge

Update `CLAUDE.md`, the matching `.cursor/rules/*.mdc`, and `docs/sprints/`.
Governance rule/doc text must not contain the environment-file literal (use
"the environment example file"). Run `graphify update .` after code changes.
