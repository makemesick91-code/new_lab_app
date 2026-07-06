# ENT-15 — Enterprise Documentation & Runbook

Branch: `feature/ent-15-enterprise-documentation-runbook`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Scope source: `config/foundation_roadmap.php` ENT-15 (category `documentation`,
depends ENT-14).

## Summary

Consolidates every prior ENT foundation output into a governed, testable
enterprise runbook set and enforces it with a readiness gate — **not docs-only**.

- New `config/enterprise_documentation.php` (mandatory runbook registry, required
  sections, required topics, forbidden destructive patterns config-not-code,
  required foundation command references, evidence + pre-deploy gate).
- New read-only `App\Support\Documentation\EnterpriseDocumentationScanner`
  (registry, files, sections, forbidden-command declarations, destructive-command
  safety, sensitive-content, foundation linkage, summary command, evidence
  profiles, release-safety).
- New `App\Services\Foundation\EnterpriseDocumentationGovernanceService`
  (ENT15-DOC001..DOC012) published into `architecture:foundation-governance-summary`
  as `enterprise_documentation_governance` (informational; re-verifies ENT-5..14).
- New commands: `php artisan foundation:enterprise-documentation-check`
  (`--json`, `--strict`/`--fail-on-warning`) and read-only
  `php artisan docs:enterprise-runbook-summary --json`.
- New runbooks under `docs/runbooks/`: enterprise operations, VPS deploy/rollback,
  backup/DR/restore rehearsal, release evidence/smoke, performance/load test.
- Evidence artifact `enterprise-documentation-check.json` required in the ci/vps
  release-evidence profiles and as a release-safety pre-deploy gate; captured by
  the deploy script and CI gates.
- Roadmap: ENT-15 → completed; `next_recommended_sprint` → ENT-16.

## Safety

No migration, route, permission, queue worker, or business workflow change. Docs
never contain secrets, credentials, the environment file, or unmasked KTP/NIK.
Destructive commands appear only inside runbook forbidden/warning sections.

## Preserved foundations

ENT-5 queue-retry, ENT-6 idempotency/outbox, ENT-7 developer console, ENT-8
health-check, ENT-9 security compliance, ENT-10 CI/CD gate, ENT-11 deploy/rollback,
ENT-12 backup/DR, ENT-13 load-test baseline, ENT-14 scale projection — all still GO
and re-verified by the ENT-15 gate.

## Tests

- `tests/Feature/Architecture/Ent15EnterpriseDocumentationRunbookTest.php`
- `tests/Feature/Console/EnterpriseRunbookSummaryCommandTest.php`
- Sibling next-sprint pins updated ENT-15 → ENT-16.
