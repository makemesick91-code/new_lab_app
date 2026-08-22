# RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1

**Branch:** `feature/restore-drill-timestamp-faithfulness-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `a51e536552f29dad8fbfaf96d6c395eca2b111c8`
**Previous GO tag:** `monitoring-log-coverage-anchor-injection-1-go` (peels to the same SHA; VPS production HEAD matched)
**Type:** RUNTIME_FIX (operational evidence correctness)

> **Objective.** Restore Drill evidence must never obtain a trustworthy age from an
> untrustworthy timestamp. The objective was *not* "replace `strtotime()` because it
> looks old" — the residual was audited, reproduced, and only then fixed.

---

## 1. Outcome

`ROOT_CAUSE_CLASSIFICATION = DECISION DEFECT (false-fresh), plus a reporting defect`
`CODE_CHANGE_REQUIRED = true`

`RestoreDrillEvidenceService::ageHours()` could turn an untrustworthy `completed_at`
into a confident, recent-looking age, and that age directly decides whether the
restore drill counts as current. **13 distinct literals that are not valid evidence
produced a `GO` verdict before this sprint.**

The consumer chain is real, not cosmetic:

```
completed_at
  → RestoreDrillEvidenceService::ageHours()
  → $stale = age > restore_drill_stale_hours (720h)
  → GO | WATCH  + issue 'evidence_stale'
  → FiveBranchRolloutReadinessService::restoreDrillSignal()
  → baseReadinessStatus() → decideStage()
  → ROLL-5 Stage-1 / Stage-2 / Stage-3 clearance
  → rollout:restore-drill-evidence --strict exit code
  → /foundation/rollout/five-branch-readiness ("Usia bukti")
```

---

## 2. The canonical timestamp contract (discovered, not guessed)

Every producer and every document agrees on exactly **one** format. No format was
invented, and nothing was narrowed before the grammar was established:

| Source | Evidence |
|---|---|
| `scripts/rollout-restore-drill.sh:119` | `COMPLETED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"` |
| `docs/runbooks/roll-5-backup-restore-drill-runbook.md:117` | `"completed_at": "YYYY-MM-DDTHH:MM:SSZ"` |
| `docs/evidence/rollout/restore-drill-template.md:25` | `"completed_at": "2026-07-10T00:03:00Z"` |
| All 4 existing test fixtures | `gmdate('Y-m-d\TH:i:s\Z')` |

```
CANONICAL_TIMESTAMP_FORMAT   = Y-m-d\TH:i:s\Z   (UTC, second precision, Zulu)
CANONICAL_TIMEZONE_RULE      = UTC, declared in-band by the trailing `Z`
OPTIONAL_OFFSET_SUPPORTED    = no  (no producer emits one)
FRACTIONAL_SECONDS_SUPPORTED = no  (no producer emits them)
UNIX_EPOCH_SUPPORTED         = no  (no producer emits it)
AGE_CLOCK_AUTHORITY          = operational instant (UTC), NOT ClinicalClock
FRESHNESS_THRESHOLD          = rollout_readiness.thresholds.restore_drill_stale_hours (720h / 30d)
BOUNDARY_SEMANTICS           = age_hours <= threshold is FRESH; strictly greater is STALE
```

**Historical-compatibility risk: none.** Both canonical evidence paths on production
(`storage/app/readiness/restore-drills/latest.json`,
`storage/release-evidence/latest/restore-drill.json`) are **ABSENT** — no restore drill
has been run on the pilot, so there is no historical evidence for a stricter parser to
invalidate. This was verified read-only before the parser was narrowed.

---

## 3. What was actually wrong — three compounding defects

**D1 — Negative age clamped to zero (the strongest amplifier).**
`max(0.0, (time() - $ts) / 3600)` meant *any* instant in the future collapsed to
`0.0` hours — the freshest value representable. A drill dated 2030 reported
"0.0 jam lalu".

**D2 — `strtotime()` permissiveness.** Invalid calendar dates normalise (Feb 30 →
Mar 2 forward; month-zero → previous December **backward**), out-of-range fields roll
over, trailing junk is ignored, and *relative modifiers are executed* — so
`"2025-01-01T00:00:00Z +2 years"` (a genuinely 20-month-old drill) resolved into the
future and, via D1, became maximally fresh.

**D3 — `filemtime()` fallback on an unparseable timestamp.** When parsing failed the
age fell back to the evidence file's mtime. A malformed evidence file written today
therefore read as a drill run today. mtime is an unrelated filesystem fact that any
`cp`, `rsync`, or deploy resets — it is not evidence of when a drill ran.

All three fed the same `stale` decision, and a `null` age is **not** stale
(`$stale = $ageHours !== null && $ageHours > $staleHours`), so an unageable timestamp
would otherwise have fallen straight through into `GO`. That trap is now closed
explicitly.

---

## 4. Before / after decision matrix

Frozen reference instant `2026-08-23T12:00:00Z`; threshold 720h; evidence file always
freshly written on disk, so any "recent" verdict must come from the timestamp.

| Literal | Value | BEFORE age | BEFORE | AFTER age | AFTER trust | AFTER |
|---|---|---:|---|---:|---|---|
| canonical recent | `2026-08-20T12:00:00Z` | 72.0 | GO | 72.0 | valid | GO |
| canonical stale | `2025-01-01T00:00:00Z` | 14388.0 | WATCH | 14388.0 | valid | WATCH |
| exact boundary 720h | `2026-07-24T12:00:00Z` | 720.0 | GO | 720.0 | valid | GO |
| just inside 719h | `2026-07-24T13:00:00Z` | 719.0 | GO | 719.0 | valid | GO |
| just outside 721h | `2026-07-24T11:00:00Z` | 721.0 | WATCH | 721.0 | valid | WATCH |
| valid leap day | `2024-02-29T10:30:00Z` | 21745.5 | WATCH | 21745.5 | valid | WATCH |
| future within skew (+2m) | `2026-08-23T12:02:00Z` | 0.0 | GO | 0.0 | valid | GO |
| invalid Feb 30 | `2026-02-30T10:00:00Z` | 4178.0 | WATCH | — | unparseable | WATCH |
| non-leap Feb 29 | `2026-02-29T10:00:00Z` | 4202.0 | WATCH | — | unparseable | WATCH |
| month zero (rolls **back**) | `2026-00-15T10:00:00Z` | 6026.0 | WATCH | — | unparseable | WATCH |
| bare `-1 year` | `-1 year` | 8772.7 | WATCH | — | unparseable | WATCH |
| trailing garbage | `2025-01-01T00:00:00Z XYZ` | 14388.0 | WATCH | — | unparseable | WATCH |
| **day zero** | `2026-08-00T10:00:00Z` | 554.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **month 13** | `2026-13-01T10:00:00Z` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **day 32** | `2026-08-32T10:00:00Z` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **hour 25** | `2026-08-20T25:00:00Z` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **minute 61** | `2026-08-20T10:61:00Z` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **relative `+2 years` on a stale literal** | `2025-01-01T00:00:00Z +2 years` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **`yesterday`** | `yesterday` | 60.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **`now`** | `now` | 12.7 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **future far** | `2030-01-01T00:00:00Z` | 0.0 | **GO** | — | future | **WATCH** ← false-fresh closed |
| **future near (+24h)** | `2026-08-24T12:00:00Z` | 0.0 | **GO** | — | future | **WATCH** ← false-fresh closed |
| **empty** | *(empty)* | 0.0 | **GO** | — | missing | **WATCH** ← false-fresh closed |
| **whitespace only** | `   ` | 12.7 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |
| **garbage word** | `not-a-date` | 0.0 | **GO** | — | unparseable | **WATCH** ← false-fresh closed |

**13 false-fresh paths closed. Every legitimate canonical literal keeps its exact age
and its exact verdict** — the fix removes fabricated freshness without rejecting a
single real evidence format.

`FALSE_EXPIRED`: also reproduced (month-zero rolled *backward* to Dec 2025; bare
`-1 year` resolved a year back), producing a confident but fabricated
`evidence_stale`. After the fix these report `unparseable` — malformed evidence is
**unknown**, not **old**. The distinction matters: "re-run the drill, it expired" and
"this evidence's timestamp cannot be trusted" are different operator actions.

---

## 5. The fix

**New — `App\Support\Foundation\RestoreDrillTimestampParser`.** Parses with the exact
canonical format in UTC (`!` prefix so no unparsed field can inherit "now") and then
requires an **exact round-trip**: the re-rendered instant must reproduce the input
byte-for-byte. Anything the parser had to normalise, roll over, or ignore to make
legal fails the round-trip. Because the canonical form declares UTC in-band, the
round-trip compares like with like — it never re-displays the instant in another zone,
so timezone normalisation can never be mistaken for corruption.

**Changed — `RestoreDrillEvidenceService::ageHours()` → `evidenceAge()`**, returning
`[trust_state, ?age]` with three explicitly unageable states — `missing`,
`unparseable`, `future` — each of which yields a `null` age **and** an explicit
`evidence_timestamp_<state>` issue that forces `WATCH`. The `filemtime` fallback is
gone. The remaining `max(0.0, …)` clamp is now safe: it only absorbs sub-tolerance
jitter, because anything meaningfully future was already rejected.

**New config —** `rollout_readiness.thresholds.restore_drill_future_skew_minutes`
(default 5, env `ROLLOUT_RESTORE_DRILL_FUTURE_SKEW_MINUTES`). This is a declared,
documented tolerance for ordinary clock jitter between the host that ran the drill and
the host reading the evidence (evidence has a second candidate path and may be
transported) — **not** a licence to accept future-dated evidence. Beyond it, the
timestamp is rejected.

**Operator visibility —** `timestamp_status` is added to the sanitized details and to
the `rollout:restore-drill-evidence --json` key list. It is an enum of four fixed
values, so it carries no PII and cannot leak evidence content.

### Why this is NOT a copy of the Monitoring fix

Monitoring's log analyzer must accept **several real formats** (Laravel
`Y-m-d H:i:s`, Monolog ISO-8601 with offsets, fractional seconds), so it correctly
uses parse-then-verify-the-digits. Restore Drill has **one** producer and **one**
documented format, so its correct contract is format-exact + round-trip. Merging them
into a `UniversalTimestampParser` would force one domain to accept input its own
producer never emits. **Two domains, two grammars, two parsers — deliberately.**

---

## 6. Scope boundaries

- `started_at` is **not** consumed by any decision and was deliberately left alone
  (smallest correct fix).
- ClinicalClock is untouched. Restore Drill freshness is operational *instant*
  arithmetic; it is not a clinical business date, and the two domains stay separate.
- Untrusted timestamps degrade to `WATCH`, never `FAIL`. `FAIL`/`unsafe` remains
  reserved for genuinely dangerous evidence (production overwrite, production-like
  environment, leaked secret/PII, a failed drill). An unparseable timestamp is
  incomplete evidence, not a safety breach.
- No restore was performed anywhere. This is a parser sprint; it does not authorise
  restoring over a live database.

---

## 7. Verification

```
NEW_TIMESTAMP_TESTS   = 56 passed / 114 assertions
MUTATION_CONTROL      = 28 of 56 FAIL when the fix is reverted to strtotime+filemtime+clamp
MUTATION_RESIDUE      = 0
```

Every test freezes time via `Carbon::setTestNow()` — no assertion depends on the wall
clock, the current month, or a nearby calendar rollover. Day, month, year, and
leap-day rollovers are each pinned to a continuous 2.0h age.

### CI critical-gate coverage (required by this sprint, not opportunistic)

The new suite matched **no** token in the `NSF-R011 Critical Test Gate` filter, so its
protection would never have run in CI — a safety fix whose regression tests are
invisible to CI is not protected. One token, `RestoreDrill`, was appended to **both**
runner variants (GitHub-hosted and self-hosted, which must stay identical per
CICD-CTRL-3). It selects 76 tests: the 56 new ones plus the existing
`RestoreDrillEvidenceServiceTest`, `RolloutRestoreDrillEvidenceCommandTest`,
`RolloutRestoreDrillUiTest`, and `RolloutStageOneClearanceTest` family — all green.

This follows the LEGACY-RME-PDF-1A precedent (`|LegacyRme|PatientEarliestNativeRmeDateResolver`).
The separate, **pre-existing** `MonitoringLogSourceResilienceTest` token gap is
deliberately NOT touched here — it is unrelated to Restore Drill and remains its own
governance item.

---

## 8. Verification results

| Gate | Result |
|---|---|
| New timestamp suite | 56 passed / 114 assertions |
| Mutation control | 28 of 56 fail without the fix; 0 residue |
| `--filter=RestoreDrill` (the new CI token) | 76 passed / 162 assertions |
| `tests/Feature/Foundation/` (consumer chain + family) | 466 passed, 4 skipped / 2387 assertions, **0 failures** |
| `--filter='Monitoring\|PilotPerformanceSnapshot'` | 215 passed, **0 failures** — `KNOWN_MONITORING_FAILURES=0` preserved |
| `pint --dirty` / `pint --test` (whole repo) | passed |
| `git diff --check` | clean |
| `sprint:manifest-check` | GO |
| `foundation:devflow-check --strict` | GO |
| `foundation:shared-service-audit --strict` | GO (10 total, 0 errors, 0 warnings) |
| `foundation:ci-runtime-control-check --strict` | GO (6/6, ENT-10 gate GO) |
| Workflow YAML | valid; both runner variants identical |

## 9. Security review

`CRITICAL = 0`, `HIGH = 0`, `MEDIUM_UNRESOLVED = 0`.

- **Attacker path — honestly scoped.** The parser primitive is confirmed, but no HTTP
  or user-facing writer touches the evidence file: it is written by
  `scripts/rollout-restore-drill.sh` or an operator with filesystem access, and the UI
  only reads. **Parser primitive CONFIRMED; attacker-controlled production path NOT
  established.** No inflated threat claim is made.
- **Resource exhaustion:** the parser contains no regex, no shell, and no filesystem
  access at all — there is no backtracking surface, and the removed `filemtime()` call
  means the change *reduces* filesystem interaction.
- **Config hardening:** `max(0, (int) config(...))` means a negative or non-numeric
  skew config collapses to zero tolerance (strictest), never widening acceptance.
- **PII:** `timestamp_status` is one of four fixed constants; the remediation string
  interpolates only that enum, never the evidence literal. `completed_at` in details
  remains masked. No new leak surface.
- **Evidence integrity:** the durable property — an untrusted timestamp cannot produce
  trusted freshness — is now enforced and pinned by tests in both directions.

`FULL_SUITE_EXECUTION_COUNT = 0` — deferred by the global temporary Full-Suite policy.
