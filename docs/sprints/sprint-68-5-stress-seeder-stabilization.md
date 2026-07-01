# Sprint 68.5 — Stress Seeder Stabilization

## Scope
- Local/stress tooling only (console command hardening).
- No production/pilot execution.
- No VPS deploy, no migration, no business-logic change.

## Problem
- `stress:seed-patients` failed at `insertOrIgnore()` with
  `number of parameters must be between 0 and 65535` — the insert batch could
  exceed the PostgreSQL bound-parameter limit.
- The command guarded on `APP_ENV=stress` only, so it could not run (or be
  tested) in `local`/`testing`.
- An old log referenced an undefined `stress:seed-rme-history`; that command now
  exists (`StressSeedRmeHistoryCommand`), so no new command was required.

## Changes
- `app/Console/Commands/StressSeedPatientsCommand.php`
  - **Parameter-limit safe chunking:** the effective chunk size is derived from
    the real column count of a patient row and a conservative parameter budget:
    `safeRows = floor(60000 / columnCount)` (19 columns → 3157 rows,
    `3157 × 19 = 59 983` params, safely under 65 535).
    `effectiveChunk = min(requestedChunk, safeRows)`.
  - **`--chunk-size` option** added (preferred). The legacy `--chunk` is kept as
    a deprecated alias with a warning; `--target` and `--branch-code` unchanged.
    Oversized requests are **clamped with a warning**, not failed.
  - **Environment guard** broadened to `local`, `stress`, `testing`; blocked in
    `production`/`pilot` with a clear message and non-zero exit.
  - **Idempotent** reruns preserved via `insertOrIgnore` on unique RM/KTP.
  - **Progress + safety output:** columns per row, safe/effective chunk, params
    per chunk, and attempted/inserted/skipped counts.
  - **`--dry-run`** validates the plan and prints the effective chunk size
    without writing any rows.
  - Synthetic-only data (fabricated KTP/phone/email); no real PII, values not
    logged individually.

## Safety Rules
- Allowed environments: `local`, `stress`, `testing`.
- Blocked environments: `production`, `pilot` (fail-fast, exit code 1).
- Max parameter safety: budget `60000` (< PostgreSQL `65535`).
- Effective chunk-size behavior: `min(requested, floor(60000 / columns))`, clamped with warning.

## Verification
- Commands:
  - `php artisan stress:seed-patients --target=10 --chunk-size=3` → 10 inserted.
  - Rerun → "Target already reached" (idempotent).
  - `php artisan stress:seed-patients --target=10 --chunk-size=999999 --dry-run`
    → clamps to 3157, params 59983, no rows written.
- Tests: `php artisan test --filter=StressSeedPatientsCommandTest` → 6 passed
  (env guard blocks pilot/production, chunk clamp, small seed, idempotent rerun,
  dry-run, missing-branch failure).
- `./vendor/bin/pint --dirty` clean; `git diff --check` clean.
- Small smoke seed result: 10 synthetic patients created then cleaned up; local
  dev DB left unchanged.

## Deferred
- `stress:seed-rme-history` already exists; no work required this sprint.
- Large-scale (hundreds of thousands / millions of rows) stress runs are
  deferred to a future sprint (see recommended Sprint 68.6).

## Final Status
- DONE / COMMITTED / PUSHED / PR CREATED / MERGED / GO-TAGGED.
