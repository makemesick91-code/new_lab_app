# Backup Restore Rehearsal Plan

## Purpose

This document defines the plan for a future non-production backup restore rehearsal during the pilot WATCH stabilization period. It converts backlog item `S26-BL-003` (P1, Backup Restore) into a concrete, safe rehearsal plan.

## Current Status

```text
Pilot Decision: WATCH
Restore Execution Status: Not executed in Sprint 26.3
Source backlog item: S26-BL-003 (P1, non-production only)
```

## Goals

- Verify backup usability before full GO.
- Confirm restore process is understood.
- Reduce operational risk from untested backups.
- Create evidence for future GO/WATCH/NO-GO review.
- Prevent accidental production database modification.

## Safety Rules

| Rule | Requirement |
| --- | --- |
| Production protection | Never restore into production database |
| Environment check | Confirm target is non-production before future execution |
| Database name check | Use clearly named restore database only |
| Approval | Require explicit owner/IT approval before future execution |
| Evidence | Capture all future restore evidence |
| Stop condition | Stop if any environment value is unclear |

## Production Identifiers — For Avoidance Only

These values are recorded so they can be recognized and avoided. They must never be used as a restore target.

| Field | Value | Use |
| --- | --- | --- |
| Production DB name | `asia_dental_lab_pilot` | Must not be restore target |
| Production DB host | `127.0.0.1` (on VPS) | Reference only |
| Production DB port | `5432` | Reference only |
| Production DB user | `dental_pilot` | Reference only |
| Production VPS IP | `145.79.13.224` | Reference only, do not deploy |
| Production app path | `/var/www/asia-dental-lab-v2` | Reference only, do not modify |

## Future Rehearsal Prerequisites

Before any real restore rehearsal:

| Check | Required |
| --- | --- |
| Backup file exists | Yes |
| Backup file timestamp is acceptable | Yes |
| Backup file size is non-zero | Yes |
| Non-production database target is approved | Yes |
| Production database name is known and avoided | Yes |
| Restore command reviewed | Yes |
| Rollback/cleanup plan prepared | Yes |
| Evidence template ready | Yes |

## Recommended Future Rehearsal Target

| Field | Value |
| --- | --- |
| Target environment | Local or isolated non-production |
| Target database | `asia_dental_lab_restore_rehearsal` |
| Production database | `asia_dental_lab_pilot` — must not be used |
| Production VPS path | `/var/www/asia-dental-lab-v2` reference only |
| Production VPS IP | `145.79.13.224` reference only |
| Backup format | PostgreSQL custom format `.dump` (Sprint 25.7 baseline) |
| Execution approval | Required before future execution |

## Future Rehearsal Flow

| Step | Activity | Owner | Evidence |
| --- | --- | --- | --- |
| 1 | Confirm backup file | IT/Admin | Backup metadata |
| 2 | Confirm non-production target | IT/Admin | Environment checklist |
| 3 | Review restore command | IT/Admin | Command review note |
| 4 | Execute restore in future approved phase | IT/Admin | Restore log |
| 5 | Run basic sanity checks | IT/Admin | Sanity notes |
| 6 | Record result | IT/Admin | Evidence template |
| 7 | Escalate failures | IT/Admin/Owner | Backlog/escalation item |

## Backup File Checks

| Check Item | PASS | WATCH | FAIL | N/A | Notes |
| --- | --- | --- | --- | --- | --- |
| Backup file exists |  |  |  |  |  |
| Backup file has expected timestamp |  |  |  |  |  |
| Backup file size is non-zero |  |  |  |  |  |
| Backup file location is known |  |  |  |  |  |
| Backup ownership/permission is readable |  |  |  |  |  |
| Backup SHA256 matches recorded checksum |  |  |  |  |  |
| `pg_restore -l` lists archive contents |  |  |  |  |  |
| Backup source is documented |  |  |  |  |  |

## Result Classification

| Result | Meaning |
| --- | --- |
| PASS | Backup and future rehearsal plan are acceptable |
| WATCH | Minor issue exists and needs repeated validation |
| FAIL | Backup readiness is not acceptable |
| N/A | Not applicable or not yet available |

## Acceptance Criteria

Backup restore readiness is acceptable when:

- Backup file is present.
- Backup file has acceptable timestamp.
- Backup file is non-zero size.
- Restore target is confirmed non-production.
- Restore runbook is available.
- Evidence template is ready.
- No production database is touched.
- Future restore execution approval is clearly required.

## Escalation Triggers

Escalate if:

- Backup file is missing.
- Backup file is stale.
- Backup file is unreadable.
- Backup target is unclear.
- Restore command could affect production.
- Required approval is missing.
- Future restore rehearsal fails.

Escalate using the escalation template in `docs/pilot_support_runbook.md`.

## Final Plan Status

```text
Plan Status: READY / WATCH / BLOCKED
```

## Notes

Write rehearsal planning notes here.
