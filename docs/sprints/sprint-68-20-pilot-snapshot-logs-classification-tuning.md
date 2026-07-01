# Sprint 68.20 — Pilot Snapshot Logs Classification Tuning

## Executive Summary
- Tuned Logs classification for `pilot:performance-snapshot`.
- Historical log entries no longer make overall status WATCH by themselves.
- Fresh errors within `--since` still affect status.
- Output now separates fresh and historical log counts.
- JSON/Markdown/Console remain privacy-safe.
- Deploy required and completed after GO tag.
- VPS verification result documented below.

## Problem
Sprint 68.19 weekly evidence on VPS/pilot showed overall **WATCH** caused only by `error_like_count = 66` from historical tail of `laravel.log`. App, Database, Resources, and HTTP were all OK. Operators could misread WATCH as an active performance regression when the noise was stale log history.

## Deploy Decision
| Item | Decision |
|---|---|
| Deploy needed | Yes |
| Reason | Command/classifier code changed |
| Migration needed | No |
| DB write needed | No |
| Cron/alert/dashboard | No |

## Implemented Changes
| Area | Change |
|---|---|
| Logs classifier | New `PilotPerformanceSnapshotLogAnalyzer` parses timestamps; fresh vs historical counts; `classifyFreshLogErrors()` in classifier |
| `--since` | Validates duration (1h–7d); drives log freshness cutoff; invalid exits 10 |
| JSON output | `fresh_error_like_count`, `historical_tail_error_like_count`, `lookback_window`, `timestamp_parse_status`, `critical_fresh_count` |
| Markdown output | Logs row includes `fresh=`, `historical_tail=`, `lookback=` |
| Console output | Fresh/historical counts + reason; no raw log lines |
| fail-on-watch | Exit 0 when only historical logs; exit 1+ when fresh or other sections WATCH+ |
| Tests | 29 tests — freshness, thresholds, invalid since, JSON/Markdown, fail-on-watch, privacy |

## New Logs Classification Rule
- **Fresh errors** (within `--since` lookback) affect OK/WATCH/INVESTIGATE/FIX:
  - 0 fresh → OK
  - 1–20 fresh → WATCH
  - 21–100 fresh → INVESTIGATE
  - >100 fresh → FIX
  - Critical fresh (CRITICAL/emergency/fatal) ≥3 → at least INVESTIGATE; ≥10 → at least FIX
- **Historical tail count** is informational only; adds warning note but does not escalate status.
- **Unparseable** error-like lines without timestamps → safe WATCH when high count / parse failed.
- **Invalid `--since`** rejected at command level with exit 10.

## Local Verification
| Check | Result |
|---|---|
| Pint | passed |
| Tests | 29 passed (110 assertions) |
| Console output | fresh/historical/reason shown |
| JSON validation | JSON_OK |
| Markdown output | fresh/historical/lookback in Logs row |
| fail-on-watch historical-only | exit 0 (via unit test) |
| fail-on-watch fresh WATCH | exit 1 (via unit test) |
| graphify | updated |
| diff check | clean (code files) |

## VPS Deploy Verification
| Check | Result |
|---|---|
| Pre-deploy HEAD/tag | (filled post-deploy) |
| Backup env | (filled post-deploy) |
| Backup DB | (filled post-deploy) |
| Post-deploy HEAD/tag | (filled post-deploy) |
| Composer install | (filled post-deploy) |
| Migration status | no new migration |
| Cache rebuild | (filled post-deploy) |
| Services | (filled post-deploy) |
| Command available | (filled post-deploy) |
| Console snapshot | (filled post-deploy) |
| JSON validation | (filled post-deploy) |
| Markdown snapshot | (filled post-deploy) |
| fail-on-watch | (filled post-deploy) |
| HTTP basic | (filled post-deploy) |

## Final VPS Snapshot After Tuning
| Section | Status | Notes |
|---|---|---|
| App | | |
| Database | | |
| Resources | | |
| HTTP | | |
| Logs | | |
| Overall | | |

## Logs Metrics
| Metric | Value |
|---|---:|
| lookback_window | |
| fresh_error_like_count | |
| historical_tail_error_like_count | |
| critical_fresh_count | |
| unparseable_error_like_count | |
| timestamp_parse_status | |

## Comparison With Sprint 68.19
| Area | Sprint 68.19 | Sprint 68.20 |
|---|---|---|
| Logs | WATCH from historical count 66 | OK when fresh=0, historical shown informational |
| Overall | WATCH | OK when App/DB/Resources/HTTP OK |
| App/DB/Resources/HTTP | OK | OK |
| Q5/Q6 | sub-ms | sub-ms (expected unchanged) |

## Safety Confirmation
- No migration.
- No destructive DB command.
- No DB write by command.
- No cron/alert/dashboard.
- No PII/raw logs/secrets committed.
- Deploy done after GO tag.

## What Was Not Implemented
- No cron/systemd.
- No alert.
- No dashboard UI.
- No monitoring DB table.
- No raw log archive.
- No authenticated HTTP benchmark.

## Recommended Next Sprint
Primary:
Sprint 68.21 — Pilot Performance Snapshot Weekly Evidence Review After Logs Tuning

Alternative:
Sprint 68.21 — Pilot Snapshot Scheduling Plan

## Final Status
PENDING DEPLOY — implementation complete locally
