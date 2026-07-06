# ENT-13 — Load Test 5 Cabang Baseline

Branch: `feature/ent-13-load-test-5-cabang-baseline`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (never `main`).
Scope source: `config/foundation_roadmap.php` ENT-13 entry (category `performance`,
depends ENT-2 / ENT-4 / ENT-12; `requires_design_first: true`;
`may_touch_production_infra: false`).

## What shipped

Implementation-heavy, non-production performance foundation:

- **`config/load_test_baseline.php`** — declares the 5-branch baseline shape (25
  clinic + 2 lab + 2 inventory users, 60k–70k patients), the 200–300ms latency
  band, the six scenario pages, the seven-category bottleneck taxonomy, the
  allowed/forbidden environments, the destructive-command patterns
  (config-not-code), the evidence artifact, and the pre-deploy gate.
- **`app/Support/LoadTest/LoadTestBaselineScanner.php`** — read-only scanner:
  harness script posture, runner registration, dataset band, scenario coverage,
  bottleneck taxonomy, latency/user-mix objectives, evidence profiles, and the
  release-safety pre-deploy gate.
- **`app/Services/Foundation/LoadTestBaselineGovernanceService.php`** — publishes
  ENT13-LT001..LT012 and re-verifies ENT-5..12 GO; informational
  `load_test_baseline_governance` section (not in the blocking combined decision).
- **`app/Console/Commands/FoundationLoadTestBaselineCheckCommand.php`** —
  `foundation:load-test-baseline-check` (`--json`, `--strict`, `--fail-on-warning`).
- **`app/Console/Commands/LoadTestBaselineRunCommand.php`** — `loadtest:baseline-run`,
  a guarded (non-production-only) read-only runner that times representative
  read-side queries per scenario and writes a non-sensitive evidence pack.
- **`scripts/load-test-baseline.sh`** — fail-fast, non-production-guarded harness
  that invokes the runner and writes evidence under `storage/app/load-test`.

## Integration / preservation

- Release-evidence `ci` + `vps` profiles require `load-test-baseline-check.json`.
- Release-safety pre-deploy gate + CI-evidence gate (`ENT-13`) + deploy script +
  CI workflow + CI evidence-gates script all run `foundation:load-test-baseline-check`.
- `architecture:foundation-governance-summary` gains the
  `load_test_baseline_governance` section; ENT-5..12 sections unchanged.
- Roadmap: ENT-12 gains `deploy_evidence_commit`; ENT-13 → `completed` with
  `go_tag` `ent-13-load-test-5-cabang-baseline-go`; next recommended sprint →
  **ENT-14**.
- ENT-5 queue-retry, ENT-6 idempotency/outbox, ENT-7 developer-console, ENT-8
  health-check, ENT-9 security-compliance, ENT-10 CI/CD gate, ENT-11
  deploy/rollback, and ENT-12 backup/DR governance all remain mandatory and GO.

## Safety

No migration, no route, no permission, no queue worker enabled, no destructive DB
command, KTP/NIK never exposed. Load testing against the production VPS pilot
database is out of scope; the harness and runner abort on production/pilot.
