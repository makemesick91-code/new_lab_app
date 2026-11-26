# Mandatory progress reporting (rule 251) — PERMANENT

**Status:** ACTIVE, permanent. **Authority:** owner directive "PERMANENT PROJECT RULE — MANDATORY PROGRESS REPORTING",
2026-11-26, accepted with this structure by the owner decision "OWNER DECISION — RULE 251 MANDATORY PROGRESS
REPORTING", 2026-11-27 (§8). **Durable mirror:** `.cursor/rules/251-mandatory-progress-reporting.mdc`. **Always-loaded summary:** CG-21
in `CLAUDE.md`. Where they differ, this document governs. Changing it needs an owner decision (CG-22).

The rule adds a reporting duty and nothing else. It changes no gate, test, deployment step or acceptance criterion.

## 1. When a report is required

A cumulative project progress report ends the completion report of every sprint, sub-sprint, phase, workstream,
milestone and acceptance gate, in every DaengtisiaMS activity. It is required wherever that completion is reported:

- the completion message of the session, in a new or parallel session, after context compaction, or at a handover;
- the closure section of the sprint doc (`sprint-doc-writer` skill);
- the summary of the pull request that completes the unit;
- the evidence-pack section that records the completion;
- every project roadmap update.

A unit that closes as NO-GO, BLOCKED or partly done still reports. "Completed" means the point where the unit is
reported, not success.

## 2. The twelve indicators and their registers

Each indicator is counted against one canonical register. The indicators are counted separately and are never merged
into one percentage unless the owner approves a roll-up (indicator 12).

| # | Indicator | Counts as completed | Canonical register (denominator) | Register state (2026-11-27) |
|---|---|---|---|---|
| 1 | Current sprint completion | A declared scope item or acceptance criterion of the reported unit, verified | The unit's own design lock, owner directive or brief. The report names the unit and the section | Per unit |
| 2 | Engineering completion, **SPN4 engineering** | An engineering item merged and verified on its exact SHA (CI with its tests run, CG-17) | The SPN4 engineering set: the R-2 Path B scope of the Design Lock §13.4, 14 items (evidence pack 57 §37.1 set 1, corrected by §39.0). It is never reported as total DaengtisiaMS engineering completion | CONFIRMED (OD-PR-1) |
| 3 | Internal Memo implementation | The requirement is IMPLEMENTED in code with pinned tests. ACCEPTED BY DESIGN counts only with the owner's recorded confirmation | `docs/sprints/dms-rme-comprehensive-closure-1-memo-traceability.md`: 20 requirements (M1.1…M6.2, C4) | REGISTERED; reconciled at the production SHA `417d788c` (traceability §6), accepted by the owner 2026-11-27 |
| 4 | Internal Memo production deployment, **code present in production** | An implemented requirement whose commit is in the production SHA, proven by `verify <deploy-id>` → `DEPLOY RUNNER OK`. A feature behind an OFF flag counts here | The same 20 | As 3 |
| 4a | Internal Memo **active production** (supplementary) | A deployed requirement that is also enabled in production. A feature behind an OFF flag or an inactive template does not count | The same 20 | As 3. Flag states come from the latest release record unless re-verified on the host, and the report says which |
| 5 | Internal Memo user acceptance | The requirement's UAT item is PASS with evidence (owner-run; NOT RUN is never PASS) | The same 20 | As 3 |
| 6 | Internal Memo overall closure | Deployed and UAT PASS, or an auditable owner disposition recorded under CG-22 | The same 20 | As 3 |
| 7 | Fast-Track completion, **original Fast-Track plan** | A plan task DONE with verified evidence | The seven-day plan tasks of the Design Lock §6 (`docs/sprints/dms-spn4-ready-pilot-1.md`): 21 applicable tasks, with T5.2 and T7.3 excluded as N/A under Path B (evidence pack 57 §37.2.1) | CONFIRMED (OD-PR-1) |
| 8 | SPN4 pilot acceptance | An acceptance criterion is PASS with evidence | `docs/sprints/dms-spn4-ready-pilot-1.md` §4: AT-1…AT-8 and AO-1…AO-7 (15) | REGISTERED (Design Lock, gate G1) |
| 9 | Pilot blocker resolution | A blocker meets its PASS column with evidence | The same doc, §3: R-1, R-2, D-1, D-2, L-1, S-1…S-4, C-1, A-1, O-1, O-2 (13), plus every item the owner marks under OD-5 | REGISTERED; OD-5 may enlarge it |
| 10 | Production operational readiness (full operational readiness) | — | None approved | Verdict NO-GO; NOT MEASURABLE as a percentage until the owner approves its denominator (OD-PR-1) |
| 11 | Business continuity and disaster recovery readiness, **full-readiness recovery requirements** | A recovery requirement met on production or on a compliant recovery environment | The 8 recovery requirements of the fast-track directive §7: RR-1, RR-2, RR-3, RR-6, RR-7, RR-10, R6-B12, RR-11 (evidence pack 57 §37.8). It is never reported as overall disaster-recovery engineering completion | CONFIRMED (OD-PR-1) |
| 12 | Overall project completion | — | The canonical project completion register, in preparation (OD-PR-2) | NOT MEASURABLE until the owner approves its denominator |

Notes:

- In indicator 9, A-1 is the §3 doctor-access blocker, not the R6-B6 finding A-1 of rule 250.
- Evidence packs live in the owner's private tree. A report cites one by pack number and section, never by a host
  path (the repository is public).
- Every row keeps its register's exact scope label (in bold above). A scoped figure is never presented as a wider
  completion.

## 3. Calculation rules

1. **Percentage:** completed ÷ total applicable × 100, to one decimal place. Change = current − previous, in
   percentage points (pp).
2. **Every row states** the previous percentage, the current percentage, the change, completed / total, remaining
   (total − completed) and its evidence.
3. **Completed means verified**, by one of:
   - a record in the repository or an evidence pack;
   - a CI run on the exact SHA whose tests actually ran (CG-17);
   - an owner-reported result, recorded with its time in WITA;
   - a measurement.
4. **Never completed:** BLOCKED, DEFERRED, PARTIAL, PREPARED, NOT RUN, NOT AUTHORIZED, an unverified claim or an
   estimate. A PARTIAL item counts 0, never a fraction.
5. **Synthetic validation is not acceptance.** A drill on synthetic data, a test identity or a CI fixture is
   engineering or validation evidence. It never satisfies a criterion that requires production, a real device or user
   acceptance.
6. **The denominator keeps BLOCKED and DEFERRED items.** An item leaves it only as NOT APPLICABLE with a stated
   reason, or by a cited owner decision.
7. **When the approved denominator changes** (an OD-5 addition, an erratum or an owner decision), state the reason
   and restate the previous figure on the new denominator, for example "previous 7/13 (53.8 %), restated 7/15
   (46.7 %)". The change is computed against the restated figure.
8. **NOT MEASURABLE** is reported when there is no register, the register is not reconciled, or the evidence is
   missing. Say what is missing. Never fill the cell with an estimate, and write "—" for the change.
9. **Management estimates** appear only in a separate section titled "Management estimates (not progress)". Each is
   marked *est.* and none appears in the table.
10. **Previous** is the figure in the latest recorded report under this rule, cited by its location (sprint doc
    section, evidence-pack section or PR). The first report writes `NO BASELINE` and "—" for the change.
11. **A wrong earlier figure is corrected as an ERRATUM** that names the cause. The precedent is evidence pack 57
    §39.0, which corrected engineering 10/14 to 7/14.

## 4. Report format

At the end of every completion report, list the twelve indicators in the order of §2, with the supplementary row 4a
directly after row 4:

| Progress indicator | Previous | Current | Change (pp) | Completed / Total | Remaining | Verdict |
|---|---|---|---|---|---|---|
| 1. Current sprint completion | | | | | | |
| … | | | | | | |
| 12. Overall project completion | | | | | | |

- **Verdict** is the indicator's governing state: COMPLETE, IN PROGRESS, WATCH, BLOCKED, NO-GO or NOT MEASURABLE.
- **Evidence:** a list keyed by indicator number follows the table, with the register, the items counted and the
  source of each.

**Summary** (always all nine items; write "none" where that is the answer):

- Completed work.
- Remaining work.
- Critical blockers.
- Next sprint priorities.
- Required owner actions.
- Additional expenditure (IDR; measured, or *est.* if estimated).
- Production deployment status (production SHA and the last verified deploy id).
- Deadline feasibility (§5 status).
- Earliest defensible completion date, or UNKNOWN while dependencies remain unresolved.

## 5. Deadline tracking

**Current planning target: 6 December 2026, 23:59 WITA** (owner, 2026-11-26). Reports compare verified progress against
this target, even where an earlier document names another one (the fast-track directive's 30 November 2026). Only an
owner decision changes the target, and the change is recorded here.

| Status | Working definition |
|---|---|
| COMPLETED | Every mandatory item for the target is verified complete |
| ON TRACK | Every remaining critical-path item has an evidence-based date before the target, and no dependency is unresolved |
| AT RISK | The target holds only if no estimate slips, or it depends on a pending owner decision that has a date |
| BEHIND SCHEDULE | The earliest evidence-based completion falls after the target |
| BLOCKED | A mandatory critical-path item depends on something with no date: an owner decision, budget, an external party or hardware |

After every completed unit, update the critical path: the ordered chain of remaining mandatory items to the target,
each with its dependency and its owner. A completion date is given only when the actual dependencies and evidence
support it. Otherwise the date is UNKNOWN, and an estimate is marked *est.*, never a promise. An acceptance criterion is
never changed to improve the deadline status.

## 6. Acceptance governance

A percentage never overrides a gate. An unresolved mandatory security, privacy, financial-integrity, operational or
disaster-recovery gate keeps its NO-GO at any percentage, 99 % included. GO comes only from the gate's own criteria and
the owner's explicit decision (CG-16, CG-22), never from a number reaching a target.

## 7. Persistence

- **Where the rule is recorded:**
  - this document;
  - `.cursor/rules/251-mandatory-progress-reporting.mdc`;
  - CG-21 in `CLAUDE.md`;
  - the canonical-rules row in `.claude/rules/foundation-governance.md`;
  - the sprint doc format of the `sprint-doc-writer` skill.
- **Every report is recorded** in the sprint doc or the evidence pack. A later session can then read the previous
  figures, whether it is new, parallel, resumed after compaction or taking over. A report that exists only in chat is
  not a baseline.
- **Parallel sessions apply the same rule.** Each register keeps a single owner, as set by the owner's parallel-work
  ownership register.
- **No duplicate rule:**
  - this rule extends CG-21 ("report measured numbers, never estimates dressed as facts") and replaces nothing;
  - the counting method of evidence pack 57 §37.1 is consistent with it and is now governed by it.
- **Authorisation is unchanged.** A commit, push, merge or deployment, documentation included, still needs its own
  authorisation (a merge needs the owner's approval for each PR).

## 8. Owner decisions (2026-11-27)

| Id | Decision | Effect |
|---|---|---|
| Acceptance | Rule 251 and this documentation structure are accepted. The rule extends CG-21 (no CG-25), and the 200-line `CLAUDE.md` limit stays | This document, the durable rule, the indexes, the sprint-record format and the enforcement test |
| OD-PR-1 | The registers of indicators 2, 7, 8, 9 and 11 are confirmed with their exact scope, and so are the reference baselines of §9 | Full operational readiness (indicator 10) stays NO-GO and NOT MEASURABLE until its denominator is approved |
| OD-PR-2 | Preparation of a canonical project completion register is approved, as documentation and evidence reconciliation only | The proposed domain structure is a proposal, not a denominator. Indicator 12 stays NOT MEASURABLE until the owner approves the register's denominator |
| Memo | Reconciliation of the 20-requirement memo register is prioritised: releases 3 and 4 and every later approved change | Done and accepted on 2026-11-27 (next row). The audit authorises no implementation or deployment, and it preserves the LAB_FEE approval dependencies |
| Memo acceptance (2026-11-27, decision "RULE 251 MERGE AND NEXT CRITICAL PATH") | The reconciliation (`docs/sprints/dms-rme-comprehensive-closure-1-memo-traceability.md` §6) is accepted at its documented evidence level. Production deployment stays "code present in production", and active production is added as the supplementary indicator 4a | The flag states are those of the release 4 record, not re-verified on the host. ERRATUM before merge: M2.1 and M2.2 are partial (two rule 243 preconditions for ON open), so implementation and deployment are 13/20, not 15/20, and the rise from 2/20 is +55.0 pp of actual progress. The owner's acknowledgement of the corrected figures is pending |

**Each register entry of the project completion register** records:
- a stable requirement id;
- its canonical source, approved scope and acceptance criteria;
- its dependencies;
- its current status and evidence;
- its mandatory-gate classification;
- the deadline that applies to it.

A requirement shared by several workstreams appears once. Blocked and deferred requirements stay in the scope.

## 9. Reference baselines (OD-PR-1)

The first formal report under this rule writes `NO BASELINE` as its previous figure. The verified figures of evidence
pack 57 are kept as reference baselines and are cited in the evidence lines.

| Indicator | Scope label | Reference baseline | Source |
|---|---|---|---|
| 2 | SPN4 engineering | 7/14 (50.0 %) | pack 57 §39.0 |
| 7 | Original Fast-Track plan | 12/21 (57.1 %) | pack 57 §37.2.1 |
| 8 | SPN4 pilot acceptance | 6/15 (40.0 %) | pack 57 §37.5 |
| 9 | Pilot blockers resolved | 7/13 (53.8 %) | pack 57 §37.5 |
| 11 | Full-readiness recovery requirements | 0/8 (0.0 %) | pack 57 §37.8 |
| 3 | Internal Memo implementation | 13/20 (65.0 %); first presented as 15/20, corrected by ERRATUM | traceability §6 |
| 4 | Internal Memo production deployment (code present) | 13/20 (65.0 %); first presented as 15/20, corrected by ERRATUM | traceability §6 |
| 4a | Internal Memo active production (supplementary) | 12/20 (60.0 %) | traceability §6; flags per the release 4 record |
| 5 | Internal Memo user acceptance | 3/20 (15.0 %) | traceability §6 |
| 6 | Internal Memo overall closure | 3/20 (15.0 %) | traceability §6 |

- **The SPN4 engineering figure** moved from 10/14 to 7/14 by an ERRATUM (pack 57 §39.0). That move is a correction
  of the count, not a regression, and is never reported as lost progress.
- **The memo implementation figure** rose from 2/20 at the release-1 audit (`cab3a4c1`) to 13/20. That is actual
  progress of +55.0 percentage points, releases 2–4. The first presentation said 15/20 (+65.0 pp); the ERRATUM in
  traceability §6 corrects it. The correction is not a regression.
- **In the rule 251 table,** the memo rows' first measurable value is not an increase over a period. A row whose
  previous report said NOT MEASURABLE writes "—" for its change.
