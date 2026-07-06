# ENT-13 — Load Test 5 Cabang Baseline Governance (Durable Lock)

Status: **LOCKED**. Governance section: `load_test_baseline_governance`.
Readiness command: `foundation:load-test-baseline-check`.
Scope source: `config/foundation_roadmap.php` ENT-13 entry
(*Load Test 5 Cabang Baseline*, category `performance`, depends on ENT-2 / ENT-4 / ENT-12).

ENT-13 locks a **non-production** 5-branch baseline load test harness (dataset +
scenario runner) and its readiness gate on top of the ENT-2 database performance
contract, the ENT-4 Redis cache policy, and the ENT-12 backup/DR automation. It
is read-only governance plus a guarded, in-process baseline runner — it never
load-tests the production VPS pilot database.

## Baseline shape

- **Branches:** 5 (`load_test_baseline.branch_count`).
- **Concurrent user mix:** 25 clinic + 2 lab + 2 inventory users.
- **Dataset:** 60,000–70,000 patients, produced by the existing non-production
  `stress:seed-foundation` / `stress:seed-patients` / `stress:seed-rme-history`
  harness only.
- **Scenario pages:** RME patient queue, cashier / invoice, owner KPI dashboard,
  inventory stock, reports / receivable, RM lookup.
- **Latency targets:** p50 200ms, p95 300ms (the documented `200-300ms` band).
- **Bottleneck taxonomy:** `db`, `cache`, `queue`, `php`, `network`, `frontend`,
  `storage`.

## Runtime components

- **Harness:** `scripts/load-test-baseline.sh` — fail-fast (`set -euo pipefail`),
  aborts unless the environment is non-production ("must not run against
  production"), invokes the runner, writes a non-sensitive evidence pack under
  `storage/app/load-test`, and carries no destructive database command.
- **Runner:** `loadtest:baseline-run` — guarded artisan command that only runs in
  `local` / `stress` / `testing`, times one representative bounded read-side
  query per scenario, classifies anything slower than the p95 target against the
  bottleneck taxonomy, and emits `load-test-baseline-check` evidence (per-scenario
  timings + counts + category labels — never PII).
- **Readiness gate:** `foundation:load-test-baseline-check` (`--json`, `--strict`,
  `--fail-on-warning`) — read-only; validates harness, runner, dataset,
  scenarios, taxonomy, latency targets, evidence profiles, and the pre-deploy
  gate, and re-verifies ENT-5..12 GO.

## Rules

- **ENT13-LT001** — The baseline load test runs on a non-production environment only.
- **ENT13-LT002** — The load test is driven by the canonical harness script (`scripts/load-test-baseline.sh`).
- **ENT13-LT003** — The 5-branch baseline dataset comes from the `stress:seed-*` harness.
- **ENT13-LT004** — The baseline covers the required scenario pages.
- **ENT13-LT005** — Latency targets are the documented 200–300ms band.
- **ENT13-LT006** — Bottlenecks are classified against a fixed taxonomy.
- **ENT13-LT007** — The baseline evidence pack is non-sensitive.
- **ENT13-LT008** — The baseline builds on the ENT-2 / ENT-4 performance foundations.
- **ENT13-LT009** — Load-test readiness evidence (`load-test-baseline-check.json`) is required per release profile.
- **ENT13-LT010** — Load-test readiness is a mandatory pre-deploy gate.
- **ENT13-LT011** — Load-test governance builds on and preserves the ENT-5..12 foundations.
- **ENT13-LT012** — New load-test scenarios register here with tests first.

## Evidence + gates

- Evidence artifact `load-test-baseline-check.json` is required in the `ci` and
  `vps` release-evidence profiles and captured by `scripts/deploy-vps.sh`,
  `scripts/ci/foundation-evidence-gates.sh`, and the CI workflow.
- `foundation:load-test-baseline-check` is a release-safety pre-deploy gate and a
  CI-evidence gate (`config/foundation_governance.php` → `ENT-13`).
- The gate is informational in `architecture:foundation-governance-summary`
  (section `load_test_baseline_governance`) and is not wired into the blocking
  combined decision; ENT-5..12 sections are unchanged.

## Safety

- Load testing against the production VPS pilot database is out of scope.
- The harness and runner abort on production/pilot; secrets are never printed and
  never written to evidence. The runner is read-only (no seed, no write, no
  schema change). Destructive-command literals live in
  `config/load_test_baseline.php`, not in the harness or app source.
- Next roadmap sprint after ENT-13: **ENT-14 — Load Test Scale Projection**.
