# ROLL-5 — Backup & Restore Drill Runbook

Part of **ROLL-5-1 — Five Branch Controlled Production Rollout Readiness** and
**ROLL-5-1A — Staging Restore Drill Evidence & Stage-1 GO Clearance**.

> ⚠️ **Production DB must NEVER be overwritten by a restore drill.** A restore
> drill is performed **only** against a staging/test/disposable database.
> `migrate:fresh`, `db:wipe`, `schema:drop`, and `migrate:reset` are forbidden on
> any environment. `production_overwrite` in the evidence MUST be exactly `false`.

The ROLL-5 readiness command detects and **validates** restore-drill evidence:

```bash
php artisan rollout:restore-drill-evidence            # validate evidence (GO/WATCH/FAIL)
php artisan rollout:restore-drill-evidence --json
php artisan rollout:restore-drill-evidence --strict   # exit non-zero on FAIL
php artisan rollout:five-branch-readiness --json | jq '.signals[] | select(.key=="restore_drill_evidence")'
```

If no evidence is found, the `restore_drill_evidence` signal is **WATCH** (never a
fake GO). Missing/stale/incomplete = WATCH; unsafe (production overwrite, failed
restore, invalid schema, leaked secret/PII) = **FAIL**. It must be **GO** before
declaring Stage-1 GO.

---

## 1. Backup file location

- Automated deploy backups: `storage/app/backups/deploy/*.sql` (ENT-12, written by `scripts/backup-vps.sh` / `scripts/deploy-vps-runner.sh`).
- Manual backup: `bash scripts/backup-vps.sh` (fail-fast, `pg_dump`, retention prune).

## 2. Restore target — staging / test / disposable ONLY

Two supported safe options. Prefer **Option 2** (disposable DB) when a staging
host with existing DB credentials is available and production is untouched.

**Option 1 — dedicated staging VPS/database:** restore the latest production
backup into the staging database only, verify app boot + health/read-only routes
+ migrations, and record the ROLL-5-1A evidence JSON.

**Option 2 — disposable local/staging PostgreSQL DB (preferred):** run the
fail-fast drill helper — it creates a disposable DB named
`<db>_restore_drill_YYYYMMDD-HHMMSS`, restores the latest verified backup there,
runs read-only verification (table count + bounded COUNTs — **no patient rows**),
drops **only** the disposable DB, and writes the canonical evidence:

```bash
bash scripts/rollout-restore-drill.sh                 # uses latest deploy backup
php artisan rollout:restore-drill-evidence --strict   # validate the written evidence
```

**Option 3 — no safe restore target available:** do **not** fake GO. Leave the
`restore_drill_evidence` signal at WATCH and complete the action checklist below
when a staging/disposable target becomes available.

- The ENT-12 rehearsal harness `scripts/restore-rehearsal.sh` (DR rehearsal,
  different `restore-rehearsal.json` schema) remains available for ENT-12 DR
  readiness — it is **not** ROLL-5-1A evidence and is not parsed as such.
- The production restore helper `scripts/restore_postgres.sh <backup>` is a
  **separate, explicit, human-run** step — never invoked by a drill.

## 3. Required safety checks before ANY restore attempt

1. Print current production DB name (`echo "$DB_DATABASE"`).
2. Print target restore DB name.
3. Assert target DB name **is not equal** to the production DB name → else STOP.
4. Assert target name contains `restore_drill`, `staging`, or `test` → else STOP.
5. Assert the production host/DB is **not** overwritten (drill restores into the
   disposable target only).
6. Assert the backup file exists and is non-zero.
7. Restore command summary must **hide the password** (use `PGPASSWORD` env, never
   echo it into logs/evidence).
8. Use `createdb`/`dropdb` for the disposable target **only**, never production.
9. If any check fails → **STOP** (the drill script aborts with `set -euo pipefail`).

## 4. Production DB never overwritten

- No drill step targets the production database.
- No drill step calls `scripts/restore_postgres.sh`.
- No drill step runs `migrate:fresh`, `db:wipe`, `schema:drop`, or `migrate:reset`.

## 5. Verification checklist (on the staging/disposable restore)

- [ ] DB connectivity to the restored disposable DB.
- [ ] Migration table present / migrations consistent — no pending destructive drift.
- [ ] App/artisan boots (`php artisan --version`) — **no patient PII printed**.
- [ ] Health endpoints resolve: `/health/live` 200, `/health/ready` 200.
- [ ] Read-only sample counts only (branches / users / patients count — **no names, no KTP/NIK**).
- [ ] No KTP/NIK/raw patient notes/secrets exposed in any drill output or evidence file.

## 6. RPO / RTO draft (5-branch controlled rollout)

| Objective | Draft target (5 branches) | Notes |
|---|---|---|
| **RPO** (Recovery Point Objective) | ≤ 24 h | Daily automated backup before each deploy; increase frequency per branch load. |
| **RTO** (Recovery Time Objective) | ≤ 4 h | Single VPS pilot restore from latest `pg_dump`; revisit for HA/national scale. |

> These are **controlled-rollout drafts**, not an HA/DR certification. National
> scale RPO/RTO requires a separate scale-validation sprint.

## 7. Canonical evidence (ROLL-5-1A)

Evidence is written to `storage/app/readiness/restore-drills/latest.json`
(schema in `docs/evidence/rollout/restore-drill-template.md`). Create a blank
non-GO template with `php artisan rollout:restore-drill-evidence --create-template`.

```json
{
  "schema_version": 1,
  "drill_id": "roll-5-1a-YYYYMMDD-HHMMSS",
  "environment": "staging",
  "source_backup_path": "storage/app/backups/deploy/pre_auto_deploy_YYYYMMDD-HHMMSS.sql",
  "source_backup_size_bytes": 0,
  "restore_target": "daengtisiams_restore_drill_YYYYMMDD-HHMMSS",
  "production_overwrite": false,
  "started_at": "YYYY-MM-DDTHH:MM:SSZ",
  "completed_at": "YYYY-MM-DDTHH:MM:SSZ",
  "duration_seconds": 0,
  "operator": "ops",
  "commands_summary": ["sanitized command summary only — no secrets"],
  "verification": {
    "db_connectivity": "GO",
    "migration_consistency": "GO",
    "app_boot": "GO",
    "health_routes": "GO",
    "sample_readonly_queries": "GO",
    "pii_redaction_confirmed": true
  },
  "decision": "GO",
  "notes": ["no secrets, no raw PII, disposable DB dropped after evidence captured"]
}
```

After a successful drill, re-run and confirm Stage-1 clearance:

```bash
php artisan rollout:restore-drill-evidence --strict
php artisan rollout:five-branch-readiness --stage=1
```

### 7.1 Timestamp contract (RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1)

`completed_at` is the ONLY field the freshness verdict is derived from, and it has
exactly one legal form — the one `scripts/rollout-restore-drill.sh` writes with
`date -u +%Y-%m-%dT%H:%M:%SZ`:

```
YYYY-MM-DDTHH:MM:SSZ        UTC, second precision, literal trailing Z
```

The validator parses it format-exactly and requires an exact round-trip, so a
timestamp is accepted only when it faithfully identifies the instant it claims.
Anything the parser would have to change to make legal is rejected rather than
silently becoming a plausible date. Rejected examples: `2026-02-30T10:00:00Z`
(invalid calendar date), `2026-00-15T10:00:00Z` (month zero — rolls *backward*),
`2026-08-00T10:00:00Z` (day zero), `2026-08-20T25:00:00Z` (out-of-range hour),
`2025-01-01T00:00:00Z +2 years` (relative modifier), `yesterday`, `now`, a bare
epoch integer, an offset such as `+08:00`, and any surrounding whitespace.

`--json` reports the trust state as `timestamp_status`:

| `timestamp_status` | `age_hours` | Verdict | What the operator does |
|---|---|---|---|
| `valid` | number | `GO` if `age_hours <= 720`, else `WATCH` (`evidence_stale`) | nothing / re-run when stale |
| `missing` | `null` | `WATCH` (`evidence_timestamp_missing`) | re-run the drill so `completed_at` is written |
| `unparseable` | `null` | `WATCH` (`evidence_timestamp_unparseable`) | fix the producer/hand-edited evidence and re-run |
| `future` | `null` | `WATCH` (`evidence_timestamp_future`) | check host clock/NTP, then re-run |

**Malformed evidence is UNKNOWN, not OLD.** `evidence_stale` means "the drill
expired, run it again"; `evidence_timestamp_*` means "this evidence's own timestamp
cannot be trusted, so its age is unknown". Do not treat them as the same signal.

A drill cannot complete in the future, so a future-dated `completed_at` is never
"fresh". Only ordinary clock jitter is tolerated, bounded by
`ROLLOUT_RESTORE_DRILL_FUTURE_SKEW_MINUTES` (default 5).

**Never hand-edit `completed_at` to clear a WATCH.** The freshness verdict is
evidence, not a target — the only way to make it GO is to run a real drill.

and confirm `restore_drill_evidence` is now **GO** and Stage-1 clears (independent
of the Stage-3 five-branch count, which stays WATCH until 5 branches are RME-enabled).

### 7.2 Read-state contract (RESTORE-DRILL-EVIDENCE-READ-STATE-1)

`php artisan rollout:restore-drill-evidence` now tells you **at which stage** the
evidence stopped being trustworthy. Before this contract, any failure to obtain
the bytes — a missing permission, an I/O error — was reported as "JSON tidak
dapat diurai", which sent operators to edit a document whose format was fine.

Read the `issues` code and the `read_state` detail, not the prose:

| `issues` code | `read_state` | What actually happened | What to fix |
|---|---|---|---|
| `evidence_absent` | `absent` | No evidence file at any candidate path | Run the drill (§2–§5), then re-validate |
| `evidence_empty` | `empty` | The file exists but holds 0 bytes | Re-run the drill; the previous run wrote nothing |
| `evidence_unreadable` | `unreadable` | The file is there; the app runtime may not read it | Fix ownership/permissions so the runtime identity can read it. **Do not edit the file's contents** |
| `evidence_read_failed` | `read_failed` | The read was attempted and failed | Check storage/mount health; the file may be changing while it is read |
| `invalid_json` | `ok` | Bytes were read and the decoder rejected them | Fix the JSON syntax against the evidence template |
| `evidence_not_an_object` | `ok` | Valid JSON, but not an evidence object | Replace with a real evidence payload, not a bare value |
| `missing_<field>` | `ok` | Valid evidence object, required field absent | Complete the field |
| `evidence_timestamp_*` | `ok` | `completed_at` is not a faithful canonical UTC literal (§7.1) | Rewrite as `YYYY-MM-DDTHH:MM:SSZ` |
| `evidence_stale` | `ok` | Trusted evidence, older than the freshness window | Re-run the drill |

Every one of these is non-GO. The distinction is the remediation, not the
verdict.

**Never do this to reproduce a state on production:** do not `chmod` the
canonical evidence file, do not truncate or corrupt it, and do not run a restore
to see what the validator says. The validator only ever reads. Exercise states
with a throwaway file and `--path`, which leaves the canonical evidence
untouched.
