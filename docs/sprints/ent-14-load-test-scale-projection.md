# ENT-14 — Load Test Scale Projection

Branch: `feature/ent-14-load-test-scale-projection`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Category: `performance` · Depends: ENT-13 · Related shipped: REPLICA-1, LB-1

## Summary

Implementation-heavy, **analysis-only** foundation sprint. Extrapolates the
measured ENT-13 5-branch baseline into a **modeled** capacity projection across
scale tiers (5 / 10 / 20 / 50 cabang), tying the 20-branch target and national
tiers to the shipped LB-1 (horizontal scale) and REPLICA-1 (read routing)
readiness foundations. No production topology change, no migration, no route, no
permission, no queue worker, no destructive command, KTP/NIK never exposed.

## What shipped

- `config/load_test_scale_projection.php` — projection registry (tiers, baseline
  source, bottleneck taxonomy, forbidden destructive patterns, harness
  expectations, evidence + pre-deploy-gate requirements).
- `app/Support/LoadTest/LoadTestScaleProjectionScanner.php` — read-only scanner
  (harness, runner, tiers, baseline linkage, bottleneck taxonomy, LB-1/REPLICA-1
  linkage, evidence profiles, release-safety).
- `app/Services/Foundation/LoadTestScaleProjectionGovernanceService.php` —
  publishes ENT14-SP001..SP012 into `architecture:foundation-governance-summary`
  as `load_test_scale_projection_governance` (informational; not in the blocking
  combined decision) and re-verifies ENT-5..13 GO.
- `app/Console/Commands/FoundationLoadTestScaleProjectionCheckCommand.php` —
  `foundation:load-test-scale-projection-check` (`--json`, `--strict`,
  `--fail-on-warning`).
- `app/Console/Commands/LoadTestScaleProjectionRunCommand.php` —
  `loadtest:scale-projection-run` (`--dry-run`, `--json`, `--write-evidence`),
  guarded non-production, read-only, modeled projection.
- `scripts/load-test-scale-projection.sh` — fail-fast, non-production guarded
  harness invoking the runner, writing evidence into `storage/app/load-test`.
- Docs: `docs/architecture/load-test-scale-projection-governance.md`, this file;
  Cursor mirror `.cursor/rules/63-load-test-scale-projection.mdc`.

## Integration

- Release evidence: `load-test-scale-projection-check.json` added to the `ci` +
  `vps` required artifacts (`config/release_evidence.php`) and to the
  `ReleaseEvidenceService` job map.
- Release safety: `foundation:load-test-scale-projection-check` added to the
  pre-deploy gates (`config/release_safety.php`).
- CI-gate registry: `ENT-14` entry in `config/foundation_governance.php`.
- Deploy + CI: gate + JSON capture added to `scripts/deploy-vps.sh`,
  `scripts/ci/foundation-evidence-gates.sh`, and
  `.github/workflows/foundation-evidence-gates.yml` (after the ENT-13 gate,
  ENT-8 cache-order hardening preserved — ENT-14 adds no route).
- Roadmap: ENT-14 → `completed` with `governance_section`/`readiness_command`/
  `policy_doc`/`go_tag`; ENT-13 gains `deploy_evidence_commit`;
  `next_recommended_sprint` → **ENT-15**.

## Guardrails

- Analysis-only: never activates replica read routing / multi-node traffic;
  never changes production topology.
- Every projected value is labeled `modeled`/`estimated`; evidence pack separates
  baseline inputs, model inputs, projections, risks, and next actions.
- Runner + harness abort on production/pilot; read-only, no DB write.
- Load testing / projection against the production VPS pilot DB is out of scope.

## Tests

- `tests/Feature/Architecture/Ent14LoadTestScaleProjectionTest.php`.
- `tests/Feature/Console/LoadTestScaleProjectionRunCommandTest.php`.
- Sibling next-sprint pins updated from ENT-14 → ENT-15.
