# ROLL-5-1A — Staging Restore-Drill Evidence Template

Canonical, versioned evidence proving a **safe** staging/disposable restore drill
was performed. The readiness parser (`RestoreDrillEvidenceService`) validates this
file at `storage/app/readiness/restore-drills/latest.json`.

> ⚠️ A restore drill **NEVER** targets the production database. `production_overwrite`
> MUST be exactly `false`. `environment` must NOT be production/pilot/live.
> The evidence must contain **no secrets, no `.env` values, no DB password, no
> tokens, no KTP/NIK, no raw patient rows, and no raw dumps** — only sanitized
> counts and metadata.

## Canonical schema (schema_version 1)

```json
{
  "schema_version": 1,
  "drill_id": "roll-5-1a-YYYYMMDD-HHMMSS",
  "environment": "staging",
  "source_backup_path": "storage/app/backups/deploy/pre_auto_deploy_YYYYMMDD-HHMMSS.sql",
  "source_backup_size_bytes": 0,
  "restore_target": "daengtisiams_restore_drill_YYYYMMDD",
  "production_overwrite": false,
  "started_at": "2026-07-10T00:00:00Z",
  "completed_at": "2026-07-10T00:03:00Z",
  "duration_seconds": 0,
  "operator": "ops",
  "commands_summary": [
    "createdb <disposable_target>",
    "psql -d <disposable_target> -f <backup> (password hidden)",
    "read-only count verification",
    "dropdb <disposable_target>"
  ],
  "verification": {
    "db_connectivity": "GO",
    "migration_consistency": "GO",
    "app_boot": "GO",
    "health_routes": "GO",
    "sample_readonly_queries": "GO",
    "pii_redaction_confirmed": true
  },
  "decision": "GO",
  "notes": [
    "no secrets, no raw PII, disposable DB dropped after evidence captured"
  ]
}
```

## Field rules

| Field | Rule |
| --- | --- |
| `schema_version` | Must equal `1`. |
| `drill_id` | Non-empty identifier. |
| `environment` | Must NOT be `production`/`pilot`/`live`/`prod`. |
| `source_backup_path` | Non-empty. Basename only is rendered; full path never shown in UI. |
| `source_backup_size_bytes` | Must be `> 0`. |
| `restore_target` | Disposable/staging name — should contain `restore_drill`/`staging`/`test`/`rehearsal`/`scratch`. |
| `production_overwrite` | **Must be exactly `false`.** `true`/missing ⇒ UNSAFE FAIL. |
| `verification.*` | Each of `db_connectivity`/`migration_consistency`/`app_boot`/`health_routes`/`sample_readonly_queries` is `GO|WATCH|FAIL|UNKNOWN`. Any `FAIL` ⇒ evidence FAIL. |
| `verification.pii_redaction_confirmed` | Should be `true`. |
| `decision` | `GO|WATCH|FAIL`. `FAIL` ⇒ FAIL. |

## Parser decision mapping

- **GO** — recent (not stale), valid schema, `production_overwrite=false`, safe
  environment, `decision=GO`, no `FAIL` verification, no secret/PII leak.
- **WATCH** — evidence missing, stale, incomplete, `decision=WATCH`, or a
  referenced local backup is unverifiable / zero-size. Never blocks GO of unrelated
  categories; must be resolved before declaring Stage GO.
- **FAIL** — `production_overwrite` not exactly `false`, production-like
  `environment`, invalid schema, `decision=FAIL`, a `FAIL` verification sub-check,
  or a leaked secret/KTP/NIK pattern in the payload.

## How to produce this evidence safely

Run the fail-fast disposable-DB drill on a **staging/test** host only:

```bash
bash scripts/rollout-restore-drill.sh                 # uses latest deploy backup
php artisan rollout:restore-drill-evidence --strict   # validate the written evidence
```

A blank, non-GO template can be created with:

```bash
php artisan rollout:restore-drill-evidence --create-template
```

See `docs/runbooks/roll-5-backup-restore-drill-runbook.md` for the full procedure.
