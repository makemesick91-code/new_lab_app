# ROLL-5-1A — Existing Restore-Drill Gap Map (mandatory pre-implementation review)

Sprint: **ROLL-5-1A — Staging Restore Drill Evidence & Stage-1 GO Clearance**
Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline tag: `roll-5-1-five-branch-controlled-production-rollout-readiness-go`

This sprint clears the single most important WATCH item in ROLL-5-1 (restore-drill
evidence) so **Stage-1** rollout readiness can move toward GO — without rebuilding
ROLL-5-1, MON-1, NSF-10 evidence, or the deploy/rollback runner.

## 1. Existing ROLL-5-1 readiness command behavior

- Command `rollout:five-branch-readiness` → `App\Services\Foundation\FiveBranchRolloutReadinessService::collect()`.
- Read-only; aggregates 12 signals into one `GO|WATCH|FAIL|UNKNOWN` decision.
- Flags: `--json`, `--strict` (fail only on unsafe FAIL), `--fail-on-warning`,
  `--include-audits` (CLI-only), `--capacity-smoke` (CLI-only), `--stage=1|2|3`.
- Report shape: `decision`, `unsafe`, `stage`, `summary` counts, `reasons`,
  `categories`, `stages` (3 entries), `monitoring_decision`, `signals`.
- **Existing `restoreDrillSignal()`** already reads candidate evidence paths from
  `config('rollout_readiness.paths.restore_drill_evidence')`, but does **only a
  file-presence + mtime staleness check** — no schema validation, no production-
  overwrite safety check, no secret/PII scan. No evidence file exists yet → the
  signal is **WATCH** ("belum ada bukti uji restore"). This is the gap.
- **Existing `stageReadiness()`** computes each stage's status purely from the RME
  branch count vs `branch_target` (1 → 3 → 5). Stage-1 is therefore already GO on
  branch count with ≥1 branch, but the overall readiness decision stays WATCH
  because the restore-drill signal is WATCH. Stage entries carry no base-readiness
  gating and no restore-evidence linkage yet.

## 2. Existing MON-1 monitoring command behavior

- `foundation:monitoring-observability-check` → `FoundationMonitoringStatusService::collect()`.
- ROLL-5-1 **reuses** MON-1 for app-health / storage / deploy_backup / governance
  evidence / audit signals (`safeMonitoringCollect()` + `pickMonitoringSignals()`),
  never re-implementing health probes or deploy evidence. MON-1 is untouched here.

## 3. Existing deploy backup evidence

- `scripts/deploy-vps.sh` / `scripts/deploy-vps-runner.sh` / `scripts/backup-vps.sh`
  (ENT-11/ENT-12) take a `pg_dump` into `storage/app/backups/deploy/*.sql` before
  every deploy, verified by `foundation:backup-verify`. ROLL-5-1's `deploy_backup`
  (via MON-1) already keys off this. Not duplicated.

## 4. Existing backup/restore runbooks

- `docs/runbooks/roll-5-backup-restore-drill-runbook.md` — describes: backup file
  location, staging/test-only restore target, "production DB never overwritten",
  a verification checklist, RPO/RTO draft, and an evidence template. It currently
  **defers execution to the ENT-12 `scripts/restore-rehearsal.sh` harness** and has
  no ROLL-5-1A canonical evidence contract.
- `docs/runbooks/roll-5-controlled-rollout-runbook.md` — stage progression runbook.

## 5. Existing restore rehearsal / ENT-11/ENT-12 evidence

- ENT-12 `scripts/restore-rehearsal.sh` restores the latest verified backup into a
  **scratch** DB (`${DB_DATABASE}_dr_rehearsal_${STAMP}`, aborts if equal to the
  production DB), verifies table count, drops **only** the scratch DB, and writes
  `restore-rehearsal.json` (ENT-12 schema: `backup_file`, `backup_size_bytes`,
  `table_count`, RTO/RPO). Production is never overwritten; `scripts/restore_postgres.sh`
  is the separate, explicit, human-only production restore step.
- The ENT-12 `restore-rehearsal.json` schema is **DR-oriented and different** from
  the ROLL-5-1A evidence contract (no `production_overwrite`, no per-check
  verification map, no `restore_target` type). ROLL-5-1A will NOT re-parse it as
  ROLL-5 evidence (avoids a false FAIL on a legacy DR file).

## 6. Already solved — must NOT be duplicated

- Consolidated readiness decision (ROLL-5-1) — reuse `collect()`, add one signal.
- Monitoring/health/backup/governance/audit signals (MON-1, ENT-8, ENT-12, NSF-10).
- Scratch-DB restore mechanics + production-safety guard (ENT-12 `restore-rehearsal.sh`).
- Deploy/rollback automation + backup-before-migrate (ENT-11/ENT-12 scripts + runner).
- Read-only Super-Admin UI + `view_developer_console` gate (ROLL-5-1). No new permission.

## 7. Exact restore-drill evidence gap that remains

1. No **canonical, versioned, schema-validated** restore-drill evidence format.
2. No **evidence parser/validator** that fails safe on: missing schema fields,
   `production_overwrite=true`, a failed restore decision, stale evidence, or a
   leaked secret/KTP/NIK pattern — the current signal is presence+mtime only.
3. No **CLI validator** to inspect/print/template the evidence deterministically.
4. Stage-1 readiness is **coupled to the overall WATCH** — there is no explicit
   Stage-1 GO-clearance computation that is independent of Stage-3's 5-branch count.
5. No **execution-ready disposable-DB drill** that emits evidence in the ROLL-5-1A
   contract (the ENT-12 harness emits a different, DR schema).

## 8. How ROLL-5-1A clears it safely

- **Scope A** — canonical evidence JSON at `storage/app/readiness/restore-drills/latest.json`
  + `docs/evidence/rollout/restore-drill-template.md`. No DB migration (versioned JSON).
- **Scope B** — read-only `App\Services\Foundation\RestoreDrillEvidenceService`:
  locate → schema-validate → source-backup existence/size → assert
  `production_overwrite=false` → decision → staleness → secret/PII scan; returns
  GO/WATCH/FAIL + sanitized details + remediation. Missing → WATCH; unsafe → FAIL.
- **Scope C** — `rollout:restore-drill-evidence` (`--json`, `--strict`,
  `--fail-on-warning`, `--path=`, `--create-template`). Validates only — never
  restores. `--create-template` writes a non-GO blank template.
- **Scope D** — extend `roll-5-backup-restore-drill-runbook.md` with execution-ready
  Option 2 (disposable `..._restore_drill_YYYYMMDD` DB) steps + a new fail-fast
  `scripts/rollout-restore-drill.sh` that guards production, restores into a
  disposable DB, verifies read-only counts, drops the scratch DB, and writes the
  ROLL-5-1A evidence JSON. Never `migrate:fresh`/`db:wipe`; never over production.
- **Scope E** — `FiveBranchRolloutReadinessService` delegates `restoreDrillSignal()`
  to the new service and gains a **pure `decideStage()`** so Stage-1 clears to GO
  when all Stage-1 required categories (incl. restore evidence) are GO — independent
  of Stage-3's 5-branch count. Stage-3 stays WATCH until 5 branches are RME-enabled.
- **Scope F** — UI gains a restore-drill evidence card + a Stage-1 clearance panel.
  Read-only, sanitized, never runs a restore.
- **Scope G/H** — durable rules (CLAUDE.md, sprint doc, runbooks, cursor rule, memory)
  + 4 test files covering GO/WATCH/FAIL, unsafe production overwrite, secret/KTP
  rejection, staleness, stage-1 independence, and UI access/sanitization.

**Not rebuilt:** ROLL-5-1, MON-1, NSF-9/NSF-10 evidence, CICD-CTRL-1, the deploy
runner, the ENT-12 rehearsal harness, or any health/audit command.
