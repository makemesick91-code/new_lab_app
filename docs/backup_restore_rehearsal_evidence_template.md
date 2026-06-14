# Backup Restore Rehearsal Evidence Template

## Purpose

This template captures evidence for a future non-production backup restore rehearsal.

## Rehearsal Metadata

| Field | Value |
|---|---|
| Rehearsal Date |  |
| Operator / Reviewer |  |
| Environment |  |
| Hostname |  |
| Target Database |  |
| Backup File Name |  |
| Backup File Path |  |
| Backup Timestamp |  |
| Backup Size |  |
| Related Runbook | `docs/non_production_restore_runbook.md` |
| Related Plan | `docs/backup_restore_rehearsal_plan.md` |

## Safety Confirmation

| Safety Check | PASS | FAIL | Notes |
|---|---|---|---|
| Target is non-production |  |  |  |
| Production database (`asia_dental_lab_pilot`) is not used |  |  |  |
| Production VPS path is not modified |  |  |  |
| Restore command was reviewed before execution |  |  |  |
| Approval was obtained before execution |  |  |  |
| Evidence capture is ready |  |  |  |

## Backup File Verification

| Check | Result | Notes |
|---|---|---|
| Backup exists | PASS/WATCH/FAIL/N/A |  |
| Backup timestamp acceptable | PASS/WATCH/FAIL/N/A |  |
| Backup size non-zero | PASS/WATCH/FAIL/N/A |  |
| Backup readable | PASS/WATCH/FAIL/N/A |  |
| Backup SHA256 matches | PASS/WATCH/FAIL/N/A |  |
| `pg_restore -l` lists contents | PASS/WATCH/FAIL/N/A |  |
| Backup source documented | PASS/WATCH/FAIL/N/A |  |

## Restore Execution Evidence

| Step | Result | Evidence / Notes |
|---|---|---|
| Environment confirmed | PASS/WATCH/FAIL/BLOCKED |  |
| Target database confirmed | PASS/WATCH/FAIL/BLOCKED |  |
| Restore command reviewed | PASS/WATCH/FAIL/BLOCKED |  |
| Restore executed | PASS/WATCH/FAIL/BLOCKED |  |
| Restore completed | PASS/WATCH/FAIL/BLOCKED |  |
| Sanity checks completed | PASS/WATCH/FAIL/BLOCKED |  |
| Cleanup completed if approved | PASS/WATCH/FAIL/BLOCKED/N/A |  |

## Sanity Check Evidence

| Check | Expected | Actual | Result | Notes |
|---|---|---|---|---|
| Tables visible | Tables listed |  | PASS/WATCH/FAIL |  |
| Table count reasonable | Non-zero |  | PASS/WATCH/FAIL |  |
| Application can be configured safely if needed | Non-production only |  | PASS/WATCH/FAIL/N/A |  |
| No production config changed | Yes |  | PASS/WATCH/FAIL |  |

## Issue Capture

| Issue ID | Issue | Severity | Owner | Next Action | Status |
|---|---|---|---|---|---|
|  |  | Low/Medium/High/Critical |  |  | Open/Watching/Closed |

## Final Result

```text
Final Result: PASS / WATCH / FAIL / BLOCKED / N/A
```

## Sign-Off

| Role | Name | Date | Sign-Off |
|---|---|---|---|
| IT/Admin |  |  | Yes/No |
| Owner/Management |  |  | Yes/No |

## Notes

Write restore rehearsal notes here.
