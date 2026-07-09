# ROLL-5-1A — Staging Restore Drill Evidence & Stage-1 GO Clearance

Branch: `feature/roll-5-1a-staging-restore-drill-evidence-stage-1-go-clearance`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: `roll-5-1-five-branch-controlled-production-rollout-readiness-go`
GO tag: `roll-5-1a-staging-restore-drill-evidence-stage-1-go-clearance-go`

## Purpose

Clear the single most important WATCH item from ROLL-5-1 (restore-drill evidence)
so **Stage-1** rollout readiness can move toward GO — safely, without faking GO if
no real staging restore was performed, and **without rebuilding** ROLL-5-1, MON-1,
NSF-9/NSF-10 evidence, the deploy runner, or the ENT-12 rehearsal harness.

Gap map: `docs/sprints/roll-5-1a-existing-restore-drill-gap-map.md`.

## What shipped (Scopes A–H)

- **A — Evidence format.** Canonical versioned JSON at
  `storage/app/readiness/restore-drills/latest.json` (schema_version 1) +
  `docs/evidence/rollout/restore-drill-template.md`. **No DB migration.**
  Config: `config/rollout_readiness.php` `restore_drill` block (schema version,
  canonical path, verification keys, forbidden environments, safe target markers,
  drill script) and canonical-only `paths.restore_drill_evidence`.
- **B — `App\Services\Foundation\RestoreDrillEvidenceService`.** Read-only parser:
  locate → secret/PII scan → schema-validate → `production_overwrite === false`
  (else UNSAFE FAIL) → non-production environment → decision/verification →
  source-backup size/existence → staleness. Returns GO/WATCH/FAIL + sanitized,
  whitelisted details + remediation. Missing ⇒ WATCH; unsafe ⇒ FAIL. Masks all
  free text; never echoes a raw secret/KTP/NIK.
- **C — `php artisan rollout:restore-drill-evidence`** (`--json`, `--strict`,
  `--fail-on-warning`, `--path=`, `--create-template`). Validates evidence only —
  **never restores**. `--create-template` writes a blank NON-GO placeholder.
- **D — Runbook + drill script.** `docs/runbooks/roll-5-backup-restore-drill-runbook.md`
  now has execution-ready Option 1/2/3 steps + a required pre-restore safety
  checklist. New fail-fast `scripts/rollout-restore-drill.sh` restores the latest
  verified backup into a **disposable** `<db>_restore_drill_<stamp>` database
  (guarded ≠ production, must contain a safe marker), runs read-only verification
  (table count + bounded COUNTs, no patient rows), drops **only** the disposable
  DB, and writes the canonical evidence. Never `migrate:fresh`/`db:wipe`; never
  over production; password never echoed into evidence.
- **E — ROLL-5 integration + Stage-1 clearance.** `FiveBranchRolloutReadinessService`
  delegates `restoreDrillSignal()` to the evidence service, and gains a pure
  `decideStage(baseStatus, branchStatus)` + a base-readiness computation so each
  stage's status = worst(shared base readiness, that stage's branch-count gate).
  **Stage-1 clears to GO independently of Stage-3** once restore evidence + base
  categories are GO with ≥1 RME branch; Stage-3 stays WATCH until 5 branches are
  RME-enabled. Stage entries expose `branch_status` + `base_status`.
- **F — UI.** `/foundation/rollout/five-branch-readiness` gains a restore-drill
  evidence card (status, file, environment, target, backup, `production_overwrite`,
  age, verification badges, remediation) + a Stage-1 clearance panel that shows
  later stages still WATCH. Read-only, sanitized, never runs a restore.
- **G — Docs/rules/memory.** This doc, gap map, runbook, evidence template, CLAUDE.md
  entry, cursor rule `.cursor/rules/70-roll-5-1a-restore-drill-evidence.mdc`, memory.
- **H — Tests.** `RestoreDrillEvidenceServiceTest` (10), `RolloutRestoreDrillEvidenceCommandTest`
  (5), `RolloutStageOneClearanceTest` (6), `RolloutRestoreDrillUiTest` (5); one
  existing ROLL-5 stage test repinned to `branch_status`.

## Durable rules

1. Restore-drill evidence must be **GO** before declaring Stage-1 GO.
2. A restore drill **never** targets the production DB (`production_overwrite=false`;
   disposable/staging target only; guarded ≠ production, must contain a safe marker).
3. Evidence is sanitized — no secrets/`.env`/tokens/DB password/KTP/NIK/raw rows/dumps.
4. **Missing** restore evidence is **WATCH**, never a fake GO.
5. **Unsafe** restore evidence (production overwrite, failed restore, invalid schema,
   leaked secret/PII) is **FAIL**.
6. **Stage-1 readiness is independent of Stage-3's 5-branch count.**
7. **Stage-3 remains WATCH** until 5 branches are RME-enabled.
8. The readiness UI is read-only and **cannot** perform a restore.
9. `rollout:restore-drill-evidence` **validates** evidence; it never performs a
   destructive restore. The disposable drill is a separate operator-run script.

## Not duplicated

ROLL-5-1, MON-1, NSF-9/NSF-10 evidence, CICD-CTRL-1, deploy/rollback runner
(ENT-11), backup + ENT-12 DR rehearsal harness, health/audit commands.

## Migration / permission / policy

None. No migration, no new permission, no policy change, no route added
(reuses `foundation.rollout.five-branch-readiness` + `view_developer_console`).
