# ENT-10 — CI/CD Enterprise Gate

Branch: `feature/ent-10-cicd-enterprise-gate`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Scope source: `config/foundation_roadmap.php` → `approved_sequence` ENT-10 entry
(title *CI/CD Enterprise Gate*, category `release_safety`, depends ENT-1 /
NSF-9 / NSF-10).

## What shipped

Read-only, config-driven CI/CD enterprise-gate governance — no migration, no
runtime driver change, no route added, no permission change, no business
workflow change, no queue worker enabled, no external CI/CD platform, no secret
added.

- `config/cicd_enterprise_gate.php` — the enterprise-gate contract (gate files,
  required foundation + deploy-evidence commands, migration-safety expectations,
  destructive-command patterns, ENT-8 cache-order markers, CI workflow
  expectations, evidence-artifact + pre-deploy-gate requirements). All literals
  live here.
- `App\Support\Cicd\CicdEnterpriseGateScanner` — read-only posture scanner
  (deploy script, CI workflow/script, release-evidence profiles, release-safety
  pre-deploy gate).
- `App\Services\Foundation\CicdEnterpriseGateGovernanceService` — publishes
  ENT10-CICD001..ENT10-CICD012, integrates ENT-5/6/7/8/9 decisions, informational
  `cicd_enterprise_gate_governance` section (not in the blocking combined
  decision).
- `php artisan foundation:cicd-enterprise-gate-check` (`--json`, `--strict`,
  `--fail-on-warning`).
- Wired into `architecture:foundation-governance-summary`.
- Evidence artifact `cicd-enterprise-gate-check.json` added to CI + VPS
  release-evidence profiles, `ReleaseEvidenceService` job map, `release_safety`
  pre-deploy gates, `foundation_governance` CI-gate registry (`ENT-10` gate),
  the CI workflow + `scripts/ci/foundation-evidence-gates.sh`, and
  `scripts/deploy-vps.sh` (after the ENT-8 route/config cache-clear ordering,
  which is preserved). The deploy + CI chain now also runs the ENT-5
  `foundation:queue-retry-failed-job-check`.
- Roadmap: ENT-10 → `completed` with `governance_section` / `readiness_command` /
  `policy_doc` / `go_tag`; ENT-9 gains `deploy_evidence_commit`;
  `next_recommended_sprint` → **ENT-11**.
- Docs: `docs/architecture/cicd-enterprise-gate-governance.md`, this sprint doc,
  freeze-rules durable lock reference, Cursor mirror
  `.cursor/rules/59-cicd-enterprise-gate.mdc`, CLAUDE.md evidence section.

## Smoke command

```
php artisan foundation:cicd-enterprise-gate-check --strict
```

Expected on the default repo state: `Decision: GO`, deploy script safe, CI
ok, evidence profiles ok, pre-deploy gate ok, ENT-5..9 all GO, exit 0.

## Preserved foundations

ENT-5 queue/retry, ENT-6 idempotency/outbox, ENT-7 developer console, ENT-8
health-check, ENT-9 security/PII compliance remain mandatory and GO. Full
KTP/NIK stays server-side only; no destructive DB command can enter the
deploy/CI scripts; the ENT-8 cache-order hardening is preserved; full-payment
and SOAP-hidden RME rules unchanged.
