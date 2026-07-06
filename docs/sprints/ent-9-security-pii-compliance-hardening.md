# ENT-9 — Security & PII Compliance Hardening

Branch: `feature/ent-9-security-pii-compliance-hardening`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Scope source: `config/foundation_roadmap.php` → `approved_sequence` ENT-9 entry
(title *Security & PII Compliance Hardening*, category `security`, depends
ENT-1, related NSF-10).

## What shipped

Read-only, config-driven security/PII compliance governance surface — no
migration, no runtime driver change, no route added, no permission change, no
business workflow change, no full KTP/NIK rendered/exported/logged.

- `config/security_compliance.php` — PII field registry, masking-helper
  registry, Blade view-scan patterns + exclusions, export-gating expectation,
  audit table + branch-isolation context. All regex literals live here.
- `App\Support\Security\SecurityComplianceScanner` — read-only posture scanner
  (masking helpers, Blade view scan, export gating via the Route facade, audit +
  branch-isolation presence).
- `App\Services\Foundation\SecurityComplianceGovernanceService` — publishes
  ENT9-SEC001..ENT9-SEC012, integrates ENT-5/6/7/8 decisions, informational
  `security_compliance_governance` section (not in the blocking combined
  decision).
- `php artisan foundation:security-compliance-check` (`--json`, `--strict`,
  `--fail-on-warning`).
- Wired into `architecture:foundation-governance-summary`.
- Evidence artifact `security-compliance-check.json` added to CI + VPS
  release-evidence profiles, `ReleaseEvidenceService` job map, `release_safety`
  pre-deploy gates, `foundation_governance` QUEUE-1 gate, the CI workflow +
  `scripts/ci/foundation-evidence-gates.sh`, and `scripts/deploy-vps.sh`
  (after the ENT-8 route/config cache-clear ordering, which is preserved).
- Roadmap: ENT-9 → `completed` with `governance_section` /`readiness_command`/
  `policy_doc`/`go_tag`; ENT-8 gains `deploy_evidence_commit`;
  `next_recommended_sprint` → **ENT-10**.
- Docs: `docs/architecture/security-pii-compliance-hardening-governance.md`,
  this sprint doc, freeze-rules durable lock, Cursor mirror
  `.cursor/rules/58-security-pii-compliance-hardening.mdc`.

## Smoke command

```
php artisan foundation:security-compliance-check --strict
```

Expected: Decision GO — masking ok, view scan clean, all export routes gated,
audit table + BranchContext present, ENT-5/6/7/8 governance GO.

## Preservation

ENT-5/ENT-6/ENT-7/ENT-8 strict governance remain mandatory and are re-verified
inside the ENT-9 check. Full-payment-only, consent, room, and doctor→cashier
completion gates unchanged. No gate weakened.
