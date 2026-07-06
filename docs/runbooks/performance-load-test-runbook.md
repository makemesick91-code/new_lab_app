# Performance & Load Test Runbook (ENT-15)

Durable governance: `docs/architecture/enterprise-documentation-runbook-governance.md`.
Verified by: `php artisan foundation:enterprise-documentation-check --strict`.

## Purpose

Run the 5-branch load-test baseline (ENT-13) and the modeled scale projection
(ENT-14) safely in a non-production environment, and verify their readiness gates.
Covers the **load_test_baseline and scale_projection** topics.

## When to Use

- Establishing or refreshing the 5-branch performance baseline (non-production).
- Producing a modeled 5/10/20/50-cabang capacity projection.
- Verifying performance governance is GO before a release.

## Prerequisites

- A **non-production** environment (`local`, `stress`, or `testing`). The pilot and
  production are explicitly out of scope for load testing.
- The stress dataset harness available for the baseline (60k–70k patients,
  25 clinic + 2 lab + 2 inventory users, 5 branches).

## Safe Commands

Readiness gates (safe anywhere, read-only):

```
php artisan foundation:load-test-baseline-check
php artisan foundation:load-test-scale-projection-check
```

Baseline + projection runs (non-production only, guarded):

```
bash scripts/load-test-baseline.sh
php artisan loadtest:baseline-run --dry-run
bash scripts/load-test-scale-projection.sh
php artisan loadtest:scale-projection-run --dry-run
```

- Both harness scripts are fail-fast and abort on production/pilot ("must not run
  against production"). On the pilot VPS, the dry-run correctly refuses.
- Baseline targets p50 200ms / p95 300ms. Projection numbers are **modeled /
  estimated** — never a measured production benchmark — and separate
  baseline_inputs, model_inputs, projections, risks, and next_actions.

## Forbidden Commands

Never run these, and never load-test the pilot/production:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `php artisan schema:drop`
- `php artisan migrate:reset`
- Any high-volume load test against the pilot or production database, and any run
  that activates replica read routing or multi-node traffic as part of a projection.

## Evidence

- Non-sensitive evidence under `storage/app/load-test/` and the gate artifacts
  `load-test-baseline-check.json` / `load-test-scale-projection-check.json`.
- Evidence is never PII and is not committed to git.

## Rollback / Fallback

- Load tests run only in a disposable non-production environment; there is nothing
  in production to roll back.
- If a baseline environment is corrupted, reseed the non-production dataset.

## Troubleshooting

- Harness refuses to run → confirm the environment is non-production; the guard is
  working as designed (this is expected on the pilot VPS).
- A projection tier looks off → tiers are monotonic 5/10/20/50 and the lowest tier
  must match the ENT-13 baseline branch count.

## Smoke Verification

- `php artisan foundation:load-test-baseline-check --strict` → GO.
- `php artisan foundation:load-test-scale-projection-check --strict` → GO.
- On the pilot, `loadtest:scale-projection-run --dry-run` refuses (non-production
  guard active).

## Security / PII Notes

- No KTP/NIK, credentials, or secrets appear in any load-test evidence artifact.
- Modeled projection numbers are labeled as estimates, not real benchmarks.

## Owner / Reviewer

- Owner: Performance / DevOps lead. Reviewer: Enterprise Foundation governance owner.

## Review Cadence

- Review each performance sprint (ENT-13/ENT-14) and at least quarterly.
