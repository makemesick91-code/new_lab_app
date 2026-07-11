# DEVFLOW-1 — Safe Sprint Acceleration, Reusable Foundations & Release Automation

- Type: FOUNDATION_SPRINT
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: `hotfix-lab-v2-notification-destination-routing-go` @ `925a713`
- GO tag: `devflow-1-safe-sprint-acceleration-release-automation-go`

## Objective

Cut sprint/fix turnaround ~30–50% without weakening security, branch
isolation, ledger correctness, workflow state machines, required tests, release
safety, backups, deploy evidence, or smoke — and without fake GO/hidden
failures. Future prompts shrink to id/type/module/objective/acceptance-criteria;
everything else is inherited from canonical templates + a per-sprint manifest.

## What shipped (all runnable + tested)

- **Permanent templates**: `docs/engineering/{sprint-runtime,hotfix-runtime,foundation-sprint}-template.md`, `release-definition-of-done.md`, `devflow-quick-start.md`.
- **Sprint classification**: `config/sprint_profiles.php` (10 types → audit level, CI profile, test/deploy/migration/rollback/evidence requirements).
- **Sprint manifest**: `.sprint/current.yml` + `.sprint/example.yml`; schema in `config/devflow.php`; value object `App\Support\Devflow\SprintManifest`; validator `SprintManifestValidator` (schema + type + manifest-vs-diff consistency).
- **Focused regression matrix**: `config/sprint_regression_matrix.php` (paths → category → focused tests + related closure + CI escalation).
- **Shared foundation registry**: `config/shared_foundations.php` + `SharedFoundationScanner` + `foundation:shared-service-audit`.
- **Services**: `GitChangeInspector`, `SprintTestPlanner`, `SprintAuditPlanner`, `SprintScopeAuditor`, `SprintReleaseChecker`, `SprintEvidenceGenerator`, `DevflowScanner`, `DevflowGovernanceService`.
- **Commands**: `sprint:new`, `sprint:prepare`, `sprint:manifest-check`, `sprint:audit-plan`, `sprint:test-plan`, `sprint:test`, `sprint:scope-audit`, `sprint:release-check`, `sprint:evidence`, `foundation:devflow-check`, `foundation:shared-service-audit`.
- **Release automation**: `scripts/sprint-release.sh` (thin orchestration over `deploy-vps-runner.sh` + `rollback-vps.sh`; dry-run default, `--apply` gate, release lock, GO tag only after deploy + smoke).
- **Governance wiring**: informational `devflow_governance` section in `architecture:foundation-governance-summary` (NOT in combinedDecision); `devflow-check.json` + `shared-service-audit.json` as optional evidence artifacts captured by CI (`scripts/ci/foundation-evidence-gates.sh`) + VPS (`scripts/deploy-vps.sh`).

## Safety invariants preserved

- CICD-CTRL-1: `skip_critical_profiles=[docs_only]`, default `unknown_high_risk` (asserted by `DevflowScanner::cicdInvariantPosture`).
- No destructive DB reset / force-push in the release wrapper (config-declared forbidden markers, scanned).
- Fail-closed everywhere: unresolved diff / unmatched file / unknown type → full required suite.
- GO tag never created before deploy + smoke; `sprint:release-check` creates nothing.

## Tests

`tests/Feature/Foundation/DevflowSprintToolingTest.php` — 31 passed / 51 assertions
(manifest validation, diff contradictions, test-plan mapping + escalation, scope
audit, audit plan, evidence redaction, shared-service audit, devflow governance,
command exit codes).
