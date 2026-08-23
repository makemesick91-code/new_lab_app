# MONITORING-UNDATED-SEVERITY-ESCALATION-1

**Status:** COMPLETE — under-severity closed.
**Base authority:** `09a02e3875654ea686a02d434f0b318229258958` (`restore-drill-timestamp-faithfulness-1-go`).
**Root cause classification:** truncated severity ladder — a decision defect, not a display defect.

---

## 1. The question this sprint had to answer

> Given that we know an ERROR-like event exists but cannot trust its timestamp,
> how much operational confidence is justified, and how must severity change as
> that adverse evidence grows?

The residual carried into this sprint was stated as *"`undated_error_like_count`
cannot currently escalate beyond WATCH."* That is a description of behaviour, not a
verdict. The objective was never to make a large number trigger FIX because it looks
alarming — it was to establish, from DaengtisiaMS's own monitoring semantics, what
unageable ERROR evidence is worth, and then make the classifier say that.

## 2. What the canonical contract already said

| Source | What it settles | What it does **not** settle |
|---|---|---|
| Rule 113 R2 | An unageable ERROR may never read as OK. "Absence of evidence is never evidence of absence." | Anything above the OK/WATCH boundary |
| Rule 113 R4 | Unparseable noise **escalates, it never masks**; the parsed fresh count drives severity | Whether undated evidence carries weight of its own |
| Rule 115 R9 | `undated_error_like_count > 0` forces `freshnessUndetermined` ⇒ WATCH, and OK is provably unreachable | Whether WATCH is a floor or a ceiling |
| Rule 113 R3 | A false WATCH is a defect too; escalation must not fire on a healthy log | — |

Every one of those statements describes a **floor**. None of them states a ceiling,
and no doc, rule, test, or code comment anywhere in the repository asserts that
undated evidence should be capped at WATCH or that its magnitude should be ignored.

The WATCH ceiling was therefore **not a deliberate design decision**. It was the
unwritten remainder of a ladder that was implemented one rung deep.

## 3. Reproduced before anything was changed

Driven directly against the unmodified classifier at the base SHA:

```
fresh=0  undated=1     -> WATCH
fresh=0  undated=21    -> WATCH
fresh=0  undated=101   -> WATCH
fresh=0  undated=150   -> WATCH
fresh=0  undated=1000  -> WATCH
```

Magnitude was ignored entirely. The consequence, stated as the asymmetry it
produces:

```
150 error events, timestamps VALID   -> FIX
150 error events, timestamps CORRUPT -> WATCH
```

Two severity levels of operational confidence, bought by nothing but corruption in
a date field. And in mixed evidence:

```
fresh=15  undated=100  -> WATCH      (up to 115 in-window error events)
fresh=20  undated=1    -> WATCH      (21 events; 21 fresh alone is INVESTIGATE)
```

`UNDER_SEVERITY_REPRODUCED = true`. `FALSE_GREEN_REPRODUCED = false` — OK was
already unreachable, exactly as rule 115 R9 promised. The defect was never a false
green; it was false *calm*.

## 4. The measured premise

The correction rests on one fact that was measured rather than assumed, by running
the real analyzer over synthetic tails:

| n | valid dates | corrupt dates |
|---:|---|---|
| 1 | `fresh=1, undated=0` | `fresh=0, undated=1` |
| 20 | `fresh=20, undated=0` | `fresh=0, undated=20` |
| 21 | `fresh=21, undated=0` | `fresh=0, undated=21` |
| 150 | `fresh=150, undated=0` | `fresh=0, undated=150` |

`undated_error_like_count` and `fresh_error_like_count` are produced by the **same
event-grouping pass** and carry the **same unit** — one error-like log event, 1:1 at
every magnitude tested. A double-count probe confirms an undated event lands in that
bucket and no other (stack-trace continuations are absorbed, not counted as orphans).

The two buckets differ in whether the monitor can say *when* the error happened.
They do not differ in whether an error happened.

**Two axes, kept separate:** error-existence confidence is *equal* between the
buckets; timestamp confidence is *absent* in one. The defect was collapsing the
second into the first — letting unknown timing discount known error evidence.

## 5. The contract, and why the alternatives were rejected

Since the monitor cannot rule out that an unageable event falls inside the window,
the fail-closed reading is that it does. The canonical in-window ladder is therefore
the ladder that applies, and the two buckets are summed onto it:

```
errorEvidenceCount = fresh_error_like_count + undated_error_like_count

  0        -> OK        (only when coverage is complete and freshness determinable)
  1 .. 20  -> WATCH
 21 .. 100 -> INVESTIGATE
   > 100   -> FIX
```

This is not a newly invented contract. The ladder's **first rung was already applied
to undated evidence** — `undated > 0 ⇒ WATCH` is numerically identical to
`fresh >= 1 ⇒ WATCH`. This sprint supplies the remaining rungs.

| Design | Verdict | Why |
|---|---|---|
| **A — independent classifiers, worst-of** | Rejected | Understates mixed evidence: `fresh=15, undated=15` is up to 30 in-window events reported as WATCH. That is rule 113 R4's exact failure mode relocated to another boundary. |
| **B — combined count on the canonical ladder** | **Adopted** | Same unit, fail-closed, monotonic, and it can only ever raise — so R4 holds by construction. |
| **C — separate, higher undated thresholds** | Rejected | Would discount evidence the monitor has no basis to discount. That is the false operational confidence this sprint exists to remove. |

**Deliberately not done:** `criticalFreshCount` stays fresh-only. The analyzer tests
`CRITICAL_PATTERN` only on events it could age, so there is no measured critical count
for the undated bucket; inventing one would assert severity never observed. Volume
escalation covers the case.

## 6. Decision matrix — measured, before and after

Form chosen per the Dataviz form heuristic: severity is an **ordinal status**
(OK < WATCH < INVESTIGATE < FIX), which is the reserved status palette, and the
skill's rule for reserved status colors is that they *ship with a label, never colour
alone*. With ~16 named cases carrying meaning the heuristic's answer is a **table**,
not a chart ("more than ~7 classes that all carry meaning → a table"). No decorative
graph was forced, and no categorical palette was shipped, so the palette validator
does not apply here.

| Case | Before | After | |
|---|---|---|---|
| FRESH=0 UNDATED=0 | OK | OK | — |
| FRESH=LOW (5) UNDATED=0 | WATCH | WATCH | — |
| FRESH=HIGH (150) UNDATED=0 | FIX | FIX | — |
| FRESH=0 UNDATED=1 | WATCH | WATCH | — |
| FRESH=0 UNDATED=20 (bound−1) | WATCH | WATCH | — |
| FRESH=0 UNDATED=21 (bound) | WATCH | **INVESTIGATE** | escalated |
| FRESH=0 UNDATED=101 (bound+1) | WATCH | **FIX** | escalated |
| FRESH=0 UNDATED=150 (large) | WATCH | **FIX** | escalated |
| FRESH=LOW UNDATED=LOW (5/5) | WATCH | WATCH | — |
| FRESH=LOW UNDATED=HIGH (15/100) | WATCH | **FIX** | escalated |
| FRESH=HIGH UNDATED=LOW (150/1) | FIX | FIX | — |
| FRESH=HIGH UNDATED=HIGH (150/150) | FIX | FIX | — |
| COVERAGE_INCOMPLETE + UNDATED=0 | WATCH | WATCH | — |
| COVERAGE_INCOMPLETE + UNDATED=150 | WATCH | **FIX** | escalated |
| ORPHAN>20, parse=partial, UNDATED=0 | WATCH | WATCH | — |
| R4 pin: 150 fresh / crit 20 / orphan 21 | FIX | FIX | — |
| MISSING / UNREADABLE / UNSUPPORTED source | WATCH | WATCH | — (fails closed before classification) |

**Every single change is an escalation. Nothing anywhere is downgraded.** Monotonicity
is additionally proven exhaustively over a 9×9 count grid on both axes, not at
hand-picked points.

## 7. Security — escalation adds no new alert-DoS primitive

To increment `undated_error_like_count` a line must clear three gates: match the
well-formed Laravel header shape `[YYYY-MM-DD HH:MM:SS…]`, **fail** the faithfulness
round-trip, and match `ERROR_PATTERN`. Measured:

| Line | undated | note |
|---|---|---|
| well-formed header + corrupt date + `ERROR` | 1 | counted |
| well-formed header + corrupt date + `INFO` | 0 | not an incident |
| plain text containing `ERROR` | 0 | orphan, not undated |
| malformed bracket + `ERROR` | 0 | orphan, not undated |

So the undated bucket demands **more** structure than a fresh event, not less. Anyone
able to forge N corrupt-dated ERROR headers can forge N well-formed ones more easily,
and the well-formed path **already reached FIX before this sprint**. Escalation
therefore introduces no cheaper route to a severe verdict — pinned by a test asserting
the undated verdict is never worse than the valid-dated one at the same volume.

`CLASSIFIER PRIMITIVE CONFIRMED` (synthetic log content drives the classifier, by
design). `ATTACKER-CONTROLLED PRODUCTION PATH NOT ESTABLISHED` — no such input path
was demonstrated, and none is claimed.

## 8. Lifecycle — this cannot become a permanent un-clearable alarm

An undated event is unageable by definition, so it cannot age out of the window. It
clears when the line leaves the **physically scanned tail** — by log rotation, or by
the source being replaced — exactly as any other tail-resident evidence does. No new
permanent state is introduced, and the count is not persisted anywhere.

**Consciously accepted — the persistence asymmetry.** A fresh event ages out of the
window on its own, so a fresh-driven alert self-clears. An undated event has no usable
age, so it stays counted until rotation evicts it from the scanned tail. Escalation
therefore converts what was a self-clearing WATCH into a **persistent FIX** for as long
as the corrupt lines remain in the tail. That is accepted deliberately: the alternative
is discounting error evidence because the monitor cannot date it, which is the exact
false confidence this sprint removes. It is bounded (rotation clears it), nothing is
persisted, and it cannot fire on a healthy log.

The residual risk worth naming: rule 115 R2 warns that a parser regression which
mislabels valid timestamps would produce a WATCH storm. After this sprint such a
regression would produce a **FIX** storm, and — by the asymmetry above — one that does
not self-clear. That raises the cost of breaking R2. It argues for keeping the parser
faithfulness test intact, which is pinned here, not for discounting the evidence.

Same reasoning applies to the pre-existing log-injection class documented by
MONITORING-LOG-COVERAGE-ANCHOR-INJECTION-1: an actor who could forge headers could
already reach FIX by the valid-dated route, but a forged *undated* burst now reaches FIX
and does not age out. No new threshold weakness — 101 events are still required — but
the durability difference is real and is accepted on the same fail-closed grounds.

## 9. Downstream impact

| Consumer | Effect |
|---|---|
| `pilot:performance-snapshot` JSON / weekly evidence record | `logs.status` and `overall_status` now reflect undated volume. Intended effect. |
| `PilotPerformanceSnapshotCommand::resolveExitCode` | Returns SUCCESS unless `--fail-on-watch` is passed. With that flag the code moves 1 → 2 or 1 → 3 for undated-heavy logs. |
| Deploy gates / CI gates / release-safety | **None.** No repo script, deploy step, or CI job invokes the command. Escalation cannot block a deploy. |
| MON-1 `FoundationMonitoringStatusService::applicationLogSignal` | **Unchanged, deliberately.** It counts `.ERROR:` tail *lines* with no timestamp or ageing concept, so it has no undated blind spot — a corrupt-dated ERROR line already counts there. The two signals answer different questions and were not merged. |
| `SlowQueryAuditService` / `SlowQueryAuditCommand` | Use `worst()` and `classifySqlRuntimeMs`; untouched. |

## 10. What was NOT changed

The timestamp parser and its faithfulness round-trip; `MonitoringLogScanCoverage`
and physical byte-offset coverage authority; `MonitoringLogSourceResolver`
(single/daily/stack, fail-closed on missing/unreadable/unsupported); the fresh
thresholds; the critical-fresh escalation; the lookback window; log retention; the
2 MiB scan budget; `overall_status = worst(...)`; MON-1; and the analyzer's metric
schema. Severity was never reached by loosening a parser, shortening a window,
editing a log, or relaxing a gate.

## 10b. Independent adversarial review

An independent reviewer extracted the real pre-fix class at the base SHA (renamed, not
reimplemented) and ran a differential harness against the candidate:

| Grid | Cases | False greens | Downgrades | Reason drift |
|---|---:|---:|---:|---:|
| Production domain (non-negative ints) | 216,000 | **0** | **0** | **0** |
| Dense monotonicity sweep (fresh 0–130 × undated 0–130 × flags) | 3,294,912 | — | **0 violations** | — |

No CRITICAL and no HIGH findings. Over non-negative inputs `errorEvidenceCount === 0`
is *strictly narrower* than the old `freshCount === 0` gate, so the OK path became
harder to reach, not easier. Three lower-severity findings were raised and two were
fixed in this sprint:

- **Fixed (reason accuracy).** A mix such as `fresh=5, undated=1` reported "freshness is
  unknown", hiding events positively confirmed inside the window behind a corruption
  story — and "some carry unparseable timestamps" under-stated the all-undated case.
  The reason now has three branches (fresh-only / all-undated / mixed), each a static
  literal, with the fresh-only wording preserved byte-identically.
- **Fixed (input robustness).** Summing two variables lost the accidental immunity the
  single-variable zero test had, so a cancelling pair (`fresh=1, undated=-1`) would have
  read as "no evidence" → OK. Each term is now floored at zero independently. Not
  reachable from the analyzer — its counters only increment from zero — but a latent
  footgun the old shape did not have.
- **Accepted, documented (§8).** The persistence asymmetry: escalation turns a
  self-clearing WATCH into a FIX that clears only on rotation.

## 11. Files

| File | Change |
|---|---|
| `app/Services/Monitoring/PilotPerformanceSnapshotClassifier.php` | `classifyFreshLogErrors` — combined error-evidence count on the canonical ladder; driver-naming reasons |
| `tests/Unit/Services/Monitoring/PilotPerformanceSnapshotUndatedSeverityTest.php` | New — 22 tests, contract + boundaries + mixed evidence + exhaustive monotonicity + negative controls + preserved foundations |
| `.cursor/rules/118-monitoring-undated-severity-escalation.mdc` | New durable rule |
| `.cursor/rules/115-monitoring-log-timestamp-authority.mdc` | R9 superseded **in place**, old reasoning retained |
| `CLAUDE.md`, `.sprint/current.yml`, this document | Governance |

## 12. Evidence

- Reproducer before fix: `6 failed, 11 passed` — the 11 passing are genuine negative
  controls and preserved foundations, not tautologies.
- After fix: `22 passed (1747 assertions)`.
- **Mutation control:** restoring the pre-fix ceiling (`errorEvidenceCount = freshCount`)
  fails **10 of 22**. Mutation removed and green re-verified.
- Monitoring regression `PilotPerformanceSnapshot|RestoreDrill|MonitoringLog`:
  **243 passed, 0 failed**. `KNOWN_MONITORING_FAILURES = 0` holds.
- Full Suite: **not run.** Rule 107 is ACTIVE.
  `FULL_SUITE_EXECUTION_COUNT=0`, `FULL_SUITE_STATUS=DEFERRED_BY_GLOBAL_TEMPORARY_POLICY`.
