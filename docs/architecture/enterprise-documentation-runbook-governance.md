# ENT-15 — Enterprise Documentation & Runbook Governance (Durable Lock)

Scope source: `config/foundation_roadmap.php` ENT-15 entry (title *Enterprise
Documentation & Runbook*, category `documentation`, depends ENT-14). This document
is the durable lock for the enterprise runbook set. It is enforced — not
docs-only — by `App\Support\Documentation\EnterpriseDocumentationScanner`,
`App\Services\Foundation\EnterpriseDocumentationGovernanceService`, and the
command `php artisan foundation:enterprise-documentation-check` (with `--strict`).
The governance section published into `architecture:foundation-governance-summary`
is `enterprise_documentation_governance` (informational; not wired into the
blocking combined decision).

## What this locks

ENT-15 consolidates every prior ENT foundation output into a governed, testable
runbook set under `docs/runbooks/`, right before the ENT-16 closure GO/NO-GO. The
mandatory runbooks (declared in `config/enterprise_documentation.php`):

| Runbook | Topics |
| --- | --- |
| `docs/runbooks/enterprise-operations-runbook.md` | operations, developer_console, queue_outbox, health |
| `docs/runbooks/vps-deploy-rollback-runbook.md` | deploy, rollback |
| `docs/runbooks/backup-dr-restore-rehearsal-runbook.md` | backup_dr, restore_rehearsal |
| `docs/runbooks/release-evidence-smoke-runbook.md` | release_evidence, smoke, security_pii |
| `docs/runbooks/performance-load-test-runbook.md` | load_test_baseline, scale_projection |

A read-only summary is available via `php artisan docs:enterprise-runbook-summary
--json`.

## Rules

- **ENT15-DOC001** — The enterprise runbook set is a governed, mandatory registry;
  every required topic maps to at least one runbook.
- **ENT15-DOC002** — Every mandatory runbook file exists (missing = hard FAIL).
- **ENT15-DOC003** — Runbooks are actionable: purpose, when to use, prerequisites,
  safe commands, forbidden commands, evidence, rollback/fallback, troubleshooting,
  smoke verification, security notes, owner/reviewer, review cadence.
- **ENT15-DOC004** — Runbooks document the destructive commands as forbidden and
  reference their required foundation readiness commands.
- **ENT15-DOC005** — A destructive literal may appear only inside a
  forbidden/warning section; the destructive literals live in config, so the
  scanner source carries none inline (config-not-code).
- **ENT15-DOC006** — Documentation is non-sensitive: no secret/credential/
  environment value and no KTP/NIK-shaped value; every doc passes the
  release-evidence forbidden-pattern/regex scan.
- **ENT15-DOC007** — The runbook set links every ENT-5..15 foundation readiness
  command.
- **ENT15-DOC008** — A read-only `docs:enterprise-runbook-summary` command is
  registered (documentation is testable governance, not a docs-only drop).
- **ENT15-DOC009** — Documentation readiness evidence
  (`enterprise-documentation-check.json`) is required in the ci and vps
  release-evidence profiles alongside the ENT-12..14 siblings; the pack is
  non-sensitive.
- **ENT15-DOC010** — Documentation readiness is a mandatory pre-deploy gate
  (`foundation:enterprise-documentation-check`) in release-safety, the deploy
  script, and CI.
- **ENT15-DOC011** — The gate re-verifies and preserves ENT-5..14 governance;
  those strict checks must stay GO for ENT-15 to be GO.
- **ENT15-DOC012** — New runbooks/sections/topics/command references register in
  `config/enterprise_documentation.php` with tests first and must pass
  `foundation:enterprise-documentation-check` before shipping.

## Safety posture

- Docs never contain secrets, credentials, the environment file, or unmasked
  KTP/NIK.
- Forbidden operational commands (documented as forbidden in every runbook):
  `migrate:fresh`, `db:wipe`, `schema:drop`, `migrate:reset`, raw production
  restore, automatic production restore during deploy, production restore
  rehearsal, and high-volume load test on pilot/production.
- Generated evidence artifacts are non-sensitive and are not committed to git.
- The queue worker stays not-enabled by design; restore rehearsal is manual and
  non-production only; the scale projection is modeled/estimated.

## Related documents

- `docs/architecture/enterprise-foundation-freeze-rules.md`
- `docs/runbooks/*` (the mandatory runbook set)
- `docs/sprints/ent-15-enterprise-documentation-runbook.md`
