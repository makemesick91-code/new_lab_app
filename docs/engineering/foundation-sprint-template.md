# DaengtisiaMS — Foundation Sprint Template

Inherits everything in `sprint-runtime-template.md`. A foundation sprint changes
cross-module, governance, CI/CD, security, or shared-foundation surface area.

## Foundation rules (type = FOUNDATION_SPRINT / INFRA_RELEASE / SECURITY_FIX / MIGRATION_HEAVY)

- **Audit level 3 (Foundation)**: inspect cross-module callers, the CI workflow
  + classifier, deploy/rollback/backup scripts, architecture governance, the
  shared-foundation registry, and release-evidence/safety config.
- **Full required gates**: these types escalate to the full required suite
  (NSF-9 / NSF-10 / NSF-R011 / NSF-R012) via `sprint:test-plan`. Never weaken a
  required gate; never widen CICD-CTRL-1 `skip_critical_profiles` beyond
  `[docs_only]`; never change the default classifier profile from
  `unknown_high_risk`.
- **Docs + rules mandatory**: durable governance doc under `docs/architecture/`
  or `docs/engineering/`, a `.cursor/rules/*.mdc` mirror, a `docs/sprints/`
  entry, and a `CLAUDE.md` evidence block.
- **Governance section**: a new rule set is published into
  `architecture:foundation-governance-summary` as an informational section
  (not wired into the blocking `combinedDecision`), following the
  `App\Services\Foundation\*GovernanceService` + `App\Support\*\Scanner` +
  `config/*.php` pattern.
- **Release evidence**: if the sprint adds a governance command, wire its
  `<name>-check.json` into the CI + VPS release-evidence profiles and the
  pre-deploy gate, and capture it in `scripts/deploy-vps.sh`.
- **Migrations** (MIGRATION_HEAVY): additive only; declare `schema_change=true`;
  never `migrate:fresh`/`db:wipe`.

## Audit levels

| Level | Name | Inspect |
| --- | --- | --- |
| 1 | Scoped | changed call-site, route, policy, direct deps, existing tests |
| 2 | Module | module services, repository + interface, routes, schema usage, integrations |
| 3 | Foundation | cross-module callers, CI + classifier, deploy/rollback/backup, architecture governance, shared registry, release evidence/safety |
