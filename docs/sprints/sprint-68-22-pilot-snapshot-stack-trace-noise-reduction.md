# Sprint 68.22 — Pilot Snapshot Stack Trace Noise Reduction

## Executive Summary
- Tuned `pilot:performance-snapshot` log analyzer to group stack trace continuation lines under parent timestamped Laravel log events.
- Historical stack traces no longer become orphan unparseable noise when a parent timestamp exists.
- Fresh error events still affect Logs/Overall status; stack trace continuation lines are informational metrics attached to those events.
- Orphan unparseable lines remain a safe fallback when no parent timestamped event exists.
- JSON/Markdown/Console output exposes grouped stack trace metrics without raw log lines.
- Deploy required after merge + GO tag.

## Problem
Sprint 68.21 weekly evidence on VPS showed:
- Overall WATCH caused by 51 `unparseable_error_like_count` lines.
- `fresh_error_like_count = 0`, `historical_tail_error_like_count = 15`.
- App/DB/Resources/HTTP were OK.
- The 51 lines were Laravel stack trace continuation lines (e.g. `#0`, `Stack trace:`, `{main}`) without their own timestamps.
- Operators saw WATCH even though no fresh errors existed.

## Deploy Decision
| Item | Decision |
|---|---|
| Deploy needed | Yes |
| Reason | Log analyzer/classifier code changed |
| Migration needed | No |
| DB write needed | No |
| Cron/alert/dashboard | No |

## Implemented Changes
| Area | Change |
|---|---|
| Stack trace grouping | Event-based parsing: timestamped header owns following continuation lines until next header |
| Fresh vs historical event classification | Parent timestamp determines freshness; one error event per header |
| Orphan unparseable fallback | Timestamp-less lines without parent remain `orphan_unparseable_error_like_count` |
| JSON output | Added `fresh_stack_trace_line_count`, `historical_stack_trace_line_count`, `orphan_unparseable_error_like_count`, `attached_unparseable_line_count`, `log_grouping_status`, `latest_historical_error_at` |
| Markdown output | Grouped summary: `fresh`, `historical`, `historical_stack_lines`, `orphan_unparseable`, `lookback` |
| Console output | Fresh/historical event counts, stack trace line counts, orphan count, reason |
| fail-on-watch | Exit 0 when only historical attached stack traces and overall OK |
| Tests | LogAnalyzer unit tests + classifier/command test updates |

## New Logs Grouping Rule
- Timestamped Laravel log header starts a new event.
- Following non-header lines attach as continuation until the next timestamped header.
- Stack trace continuation patterns: `#N`, `Stack trace:`, `{main}`, `thrown in`, indented `#` lines.
- Parent timestamp decides fresh vs historical for attached stack traces.
- Historical attached stack traces are informational only.
- Fresh attached stack traces support fresh event evidence but do not inflate event counts.
- Orphan timestamp-less error-like/stack lines without parent remain unparseable fallback.
- No raw log lines in command output.

## Local Verification
| Check | Result |
|---|---|
| Pint | PASS |
| Tests | 30 passed (`--filter=PilotPerformanceSnapshot`) |
| Console output | PASS — grouped metrics shown |
| JSON validation | PASS — `JSON_OK` |
| Markdown output | PASS — grouped summary |
| invalid since | PASS — exit 10 |
| fail-on-watch historical-only | PASS |
| fail-on-watch fresh WATCH | PASS |
| graphify | PASS |
| diff check | Pending at commit |

## VPS Deploy Verification
| Check | Result |
|---|---|
| Pre-deploy HEAD/tag | Pending |
| Backup env | Pending |
| Backup DB | Pending |
| Post-deploy HEAD/tag | Pending |
| Composer install | Pending |
| Migration status | No new migration expected |
| Cache rebuild | Pending |
| Services | Pending |
| Command available | Pending |
| Console snapshot | Pending |
| JSON validation | Pending |
| Markdown snapshot | Pending |
| fail-on-watch | Pending |
| HTTP basic | Pending |

## Final VPS Snapshot After Stack Trace Noise Reduction
| Section | Status | Notes |
|---|---|---|
| App | Pending | |
| Database | Pending | |
| Resources | Pending | |
| HTTP | Pending | |
| Logs | Pending | |
| Overall | Pending | |

## Logs Metrics
| Metric | Sprint 68.21 | Sprint 68.22 |
|---|---:|---:|
| lookback_window | 24h | Pending |
| fresh_error_like_count | 0 | Pending |
| historical_tail_error_like_count | 15 | Pending |
| critical_fresh_count | 0 | Pending |
| unparseable_error_like_count | 51 | Pending |
| fresh_stack_trace_line_count | N/A | Pending |
| historical_stack_trace_line_count | N/A | Pending |
| orphan_unparseable_error_like_count | N/A | Pending |
| attached_unparseable_line_count | N/A | Pending |
| timestamp_parse_status | partial | Pending |
| log_grouping_status | N/A | Pending |

## Comparison With Sprint 68.21
| Area | Sprint 68.21 | Sprint 68.22 |
|---|---|---|
| Logs status | WATCH from unparseable fallback | Pending |
| Overall | WATCH | Pending |
| Fresh errors | 0 | Pending |
| Historical events | 15 | Pending |
| Unparseable lines | 51 | Pending |
| Stack traces grouped | No | Yes |
| App/DB/Resources/HTTP | OK | Pending |

## Performance Metrics
| Metric | Sprint 68.21 | Sprint 68.22 | Decision |
|---|---:|---:|---|
| DB size | 17.95 MB | Pending | |
| Patients | 32 | Pending | |
| Visits | 26 | Pending | |
| Invoices | 17 | Pending | |
| Payments | 25 | Pending | |
| Q5 | ~0.025–0.030 ms | Pending | |
| Q6 | ~0.048–0.067 ms | Pending | |
| HTTP `/` | 302 | Pending | |
| HTTP `/login` | 200 | Pending | |
| Disk free | 91.71 GB | Pending | |
| RAM available | ~7.1 GB | Pending | |

## Final Decision
Pending VPS verification.

## Safety Confirmation
- No migration.
- No destructive DB command.
- No DB write by command.
- No cron/alert/dashboard.
- No PII/raw logs/secrets committed.
- Deploy after GO tag only.

## What Was Not Implemented
- No cron/systemd.
- No alert.
- No dashboard UI.
- No monitoring DB table.
- No raw log archive.
- No authenticated HTTP benchmark.

## Recommended Next Sprint
Primary: Sprint 68.23 — Pilot Performance Snapshot Weekly Evidence Review After Stack Trace Noise Reduction

## Final Status
IN PROGRESS — pending commit, PR, merge, GO tag, deploy, verification
