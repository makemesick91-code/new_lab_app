# ENT-14 — Load Test Scale Projection Governance (Durable Lock)

Status: **LOCKED** — governance/config/docs/tests only. Analysis-only. No
production topology change, no migration, no route, no permission, no queue
worker, no destructive command, KTP/NIK never rendered/exported/logged.

Scope source: `config/foundation_roadmap.php` ENT-14 entry (title *Load Test
Scale Projection*, category `performance`, `depends_on: [ENT-13]`,
`related_shipped_foundations: [REPLICA-1, LB-1]`, `may_touch_production_infra:
false`, `requires_design_first: true`).

Readiness gate: `foundation:load-test-scale-projection-check`
(`--json`, `--strict`/`--fail-on-warning`). Governance section:
`load_test_scale_projection_governance` (informational; not wired into the
blocking `combinedDecision`).

## 1. What ENT-14 adds

ENT-14 extrapolates the **measured** ENT-13 5-branch baseline
(`config/load_test_baseline.php`, 25 clinic + 2 lab + 2 inventory users, 60k–70k
patients, p50 200ms / p95 300ms) into a **modeled** capacity projection across
scale tiers, tying each higher tier to the shipped **LB-1** (horizontal scale)
and **REPLICA-1** (read routing) readiness foundations.

- Config registry: `config/load_test_scale_projection.php` (tiers, baseline
  source, bottleneck taxonomy, forbidden destructive patterns, harness
  expectations, evidence + pre-deploy-gate requirements).
- Read-only scanner: `App\Support\LoadTest\LoadTestScaleProjectionScanner`.
- Governance service: `App\Services\Foundation\LoadTestScaleProjectionGovernanceService`.
- Readiness command: `foundation:load-test-scale-projection-check`.
- Projection runner: `loadtest:scale-projection-run` (`--dry-run`, `--json`,
  `--write-evidence`) — guarded, non-production, read-only, no DB write.
- Harness script: `scripts/load-test-scale-projection.sh` (fail-fast,
  non-production guarded, invokes the runner, writes evidence into
  `storage/app/load-test`).
- Evidence artifact: `load-test-scale-projection-check.json` (required in the
  `ci` + `vps` release-evidence profiles; pre-deploy gate in
  `config/release_safety.php`).

## 2. Projection tiers (modeled)

| Tier | Cabang | Scale factor | Scale-out expectation |
| ---- | ------ | ------------ | --------------------- |
| Baseline | 5 | 1.0x | single-node baseline (ENT-13 measured) |
| Pertumbuhan | 10 | 2.0x | vertical headroom + cache (ENT-4) |
| Target | 20 | 4.0x | LB-1 horizontal scale + REPLICA-1 read routing recommended |
| Nasional | 50 | 10.0x | LB-1 multi-node + REPLICA-1 read routing required |

The runner produces, per tier, modeled concurrent users, modeled patient volume,
a naive single-node p95 (baseline p95 × scale factor) and a with-scale-out p95
(held near the baseline band once LB-1/REPLICA-1 absorb per-node load). Every
value is labeled `modeled`/`estimated`; the pack carries a disclaimer that the
numbers are extrapolations, not measured production benchmarks. The evidence pack
separates **baseline inputs**, **model inputs**, **projections**, **risks**, and
**next actions**.

## 3. Rules — ENT14-SP001..ENT14-SP012

- **ENT14-SP001** — The scale projection is analysis-only; it never activates
  replica read routing / multi-node traffic and never changes production
  topology; harness + runner abort on production/pilot.
- **ENT14-SP002** — The projection is driven by the canonical harness script
  `scripts/load-test-scale-projection.sh` → `loadtest:scale-projection-run`.
- **ENT14-SP003** — Projection is anchored on the ENT-13 measured baseline; the
  lowest tier matches the baseline branch count.
- **ENT14-SP004** — The projection covers the required scale tiers (baseline,
  20-branch target, national) with monotonic-increasing branch counts.
- **ENT14-SP005** — Projected numbers are modeled/estimated, never a measured
  production claim.
- **ENT14-SP006** — Higher tiers tie to the shipped LB-1 / REPLICA-1 foundations
  as the mitigation path.
- **ENT14-SP007** — Bottlenecks are classified against
  db/cache/queue/php/network/frontend/storage.
- **ENT14-SP008** — The projection evidence pack is non-sensitive (never a
  secret, credential, environment value, or KTP/NIK-shaped value).
- **ENT14-SP009** — Projection readiness evidence
  (`load-test-scale-projection-check.json`) is required per `ci`/`vps` release
  profile alongside the ENT-11..13 siblings.
- **ENT14-SP010** — Projection readiness is a mandatory pre-deploy gate
  (`foundation:load-test-scale-projection-check`).
- **ENT14-SP011** — Projection governance re-verifies and preserves the
  ENT-5..13 foundations; they must stay GO for ENT-14 to be GO.
- **ENT14-SP012** — New tiers/mitigations register here with tests first and must
  pass `foundation:load-test-scale-projection-check` before shipping.

## 4. Safety posture

- No migration, no route, no permission, no queue worker, no schema change.
- The runner is read-only (no seed, no write, no DB query) and guarded to the
  non-production environments in `config/load_test_scale_projection.php`.
- All destructive/production-guard literals live in config, never inline in the
  harness or app source (config-not-code, mirrors ENT-9..13).
- Load testing / projection against the production VPS pilot database is out of
  scope; the projection is modeled analysis only.

## 5. Related documents

- ENT-13 baseline: `docs/architecture/load-test-5-cabang-baseline-governance.md`.
- LB-1 readiness: `docs/architecture/load-balancer-pilot-readiness.md`.
- Freeze rules: `docs/architecture/enterprise-foundation-freeze-rules.md`
  (Section 8 references this durable lock).
- Cursor mirror: `.cursor/rules/63-load-test-scale-projection.mdc`.
