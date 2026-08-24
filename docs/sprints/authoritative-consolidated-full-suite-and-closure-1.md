# AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-AND-CLOSURE-1

**Type:** Governance / programme closure
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Predecessor authority:** `final-stabilization-residual-audit-1-go` @ `e1675586ae39d4ee9b7d677ac3c90bdde4b75ffb`
**Durable rule mirror:** `.cursor/rules/122-authoritative-consolidated-full-suite-closure.mdc`
**Contract test:** `tests/Feature/Cicd/AuthoritativeConsolidatedFullSuiteClosureContractTest.php`

---

## 1. What this sprint is

The final gate of the DaengtisiaMS stabilization programme.

`FINAL-STABILIZATION-RESIDUAL-AUDIT-1` reconciled all 21 residuals against
production and certified `READY_FOR_AUTHORITATIVE_CONSOLIDATED_FULL_SUITE=true`
with `FULL_SUITE_EXECUTION_COUNT=0`. Under the `GLOBAL TEMPORARY FULL-SUITE
POLICY` (rule `107`, ACTIVE since 2026-08-19) the repository-wide suite has been
deferred to exactly one authoritative run on a frozen final SHA.

This sprint performs that run and, only on a genuine PASS, closes the programme.

**It is not** an implementation sprint, and it is not a licence to debug the
Full Suite. A failed run is NO-GO plus a named corrective sprint.

## 2. Authorisation

The user explicitly authorised `AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-AND-CLOSURE-1`,
permitting **exactly one** consolidated Full Suite execution.

```
FULL_SUITE_POLICY_OVERRIDE      = EXPLICIT_USER_AUTHORIZATION_FOR_FINAL_CLOSURE
AUTHORIZED_FULL_SUITE_MAX_EXECUTIONS = 1
```

The authorisation does **not** permit an automatic rerun, repeated Full Suite
debugging, a Full Suite on more than one SHA, production data mutation, external
WhatsApp/SATUSEHAT activation, an object-storage cutover, or unrelated feature
work.

The mechanism used is the policy's own single authorised manual path:
`workflow_dispatch` with **both** `run_full_suite=true` and
`full_suite_policy_override=true`, which the resolver reports as
`AUTHORISED_CONSOLIDATED_FULL_SUITE`. No other path was used, and no automatic
path was re-enabled.

## 3. What this sprint changes

Governance text only. `RUNTIME_BEHAVIOR_CHANGE=false`, verified rather than
asserted: the change set contains **zero** files under `app/`, `config/`,
`routes/`, `database/`, `resources/` or `scripts/`, and zero executable
statements.

| File | Why |
| --- | --- |
| `.sprint/current.yml` | Closure manifest. Also load-bearing — see §4. |
| `docs/sprints/authoritative-consolidated-full-suite-and-closure-1.md` | This record. |
| `.cursor/rules/122-authoritative-consolidated-full-suite-closure.mdc` | Durable rules `CLOSE-R01`–`CLOSE-R06`. |
| `tests/Feature/Cicd/AuthoritativeConsolidatedFullSuiteClosureContractTest.php` | Pins those rules so they cannot drift. |
| `CLAUDE.md` | Programme closure record. |

Graphify was rebuilt from a clean worktree at the authority SHA — 28,442 nodes /
40,830 edges / 3,025 communities — and all fourteen closure domains (Monitoring,
Restore Drill, CI/CD, Storage, Clinical Evidence, Odontogram, Legacy RME,
Consent, Lab Workflow, Inventory, RME Payment, Permissions, Branch Context,
Deploy) are mapped. The candidate's blast radius over that graph is empty.

## 4. Why the manifest is part of the change set

This is deliberate and load-bearing, not housekeeping.

The Full Suite **step** is guarded by `if: run_critical_tests != 'false'`. A
change set consisting only of Markdown resolves to the classifier's `docs_only`
profile, which sets that output to `false`. The job would then report success
having executed **no tests** — a false green that consumes a one-shot
authorisation and proves nothing.

`.sprint/current.yml` matches no classifier pattern, so including it resolves
the change set to `unknown_high_risk` and therefore `run_critical_tests=true`.
The manifest is what makes the authoritative run actually run. This is pinned as
`CLOSE-R06`.

## 5. Why the temporary Full-Suite policy stays ACTIVE here

Retiring rule `107` inside this candidate was considered and rejected for two
independent reasons:

1. **Truth.** The policy's §10 retires it only once closure is *finished*. At
   candidate freeze the Full Suite has not yet run, so a `RETIRED` marker would
   be false at the moment it was committed.
2. **Safety.** `RETIRED` re-authorises the automatic `push`-to-base path. Merging
   this very PR would then immediately fire a **second, unauthorised** Full
   Suite — breaking the one-run authorisation mechanically, not just on paper.

Retirement is therefore a distinct post-closure governance act requiring the
user's explicit decision. Recorded as `CLOSE-R05`. The policy document and rule
`107` are kept permanently as historical evidence either way.

## 6. Residual ledger carried forward

Frozen input from `docs/sprints/final-stabilization-residual-audit-1.md`,
re-verified against the canonical document rather than restated from memory:

| Classification | Count |
| --- | --- |
| CLOSED | 4 |
| ACCEPTED_RISK | 9 |
| BLOCKED_EXTERNAL | 3 |
| DEFERRED | 3 |
| SUPERSEDED | 1 |
| NOT_APPLICABLE | 1 |
| **REAL_DEFECT** | **0** |
| **TOTAL** | **21** |

```
REAL_DEFECT=0
BLOCKING_RESIDUALS=0
```

`R-13` ("repository-wide Full Suite deferred") is the residual this sprint
exists to discharge. Its classification is resolved by the authoritative run
itself, not by assertion.

## 7. The authoritative result

The result of the one authorised consolidated Full Suite is carried by the
annotated GO tag `authoritative-consolidated-full-suite-and-closure-1-go`.

That tag is created **only** on a genuine PASS, only against the tree the suite
actually tested, and only after that same tree is merged, deployed and verified
in production. **The existence of the tag is the result.** If the tag is absent,
the run did not pass and the programme is not closed — no other reading is
available, and no document in this repository may assert closure without it.

## 8. Closure rules

Stated in full in the durable mirror; summarised here.

- **CLOSE-R01** — what "closed" means: predecessor GO, one passed authorised Full
  Suite, tested tree == merged tree == deployed tree, production verification,
  no blocking residual, GO tag.
- **CLOSE-R02** — a failed authoritative Full Suite is never automatically
  rerun; root-cause with targeted tests, then a corrective sprint; a new
  complete-suite execution needs new explicit authorisation.
- **CLOSE-R03** — tree identity, not commit identity: a squash merge may change
  the SHA, never the tree. After PASS the candidate is immutable until deployed.
- **CLOSE-R04** — what closure does **not** claim (see §9).
- **CLOSE-R05** — retiring the temporary Full-Suite policy is a separate,
  post-closure, explicitly-authorised act.
- **CLOSE-R06** — a dispatched Full Suite must be proven to have executed.

## 9. What closure does not claim

Programme closure means the stabilization programme is closed. It does **not**
mean the roadmap is finished. These retain their audited classifications and
remain OFF:

- **WhatsApp / Meta prescription delivery** — `BLOCKED_EXTERNAL`, awaiting
  credentials and an approved template.
- **SATUSEHAT external submission / production activation** — `BLOCKED_EXTERNAL`.
  Its GO tag is deliberately **absent**; integration stays DISABLED and
  production stays BLOCKED.
- **Object-storage production cutover** — `DEFERRED`. Production authority
  remains the private, policy-gated local disk.
- **The 20 historical dangling storage references** — `ACCEPTED_RISK`,
  classified and deliberately not deleted; the read path aborts 404 after
  authorisation.

Two truthful WATCH states also survive closure and are not defects:

- production `laravel_log` may remain WATCH while prior-session tooling debris
  ages out of the window (0 application errors);
- restore-drill evidence is absent on production (`read_state=absent`,
  `unsafe=false`), because clearing it needs a disposable staging database that
  no sprint so far has been authorised to create. Evidence is never manufactured
  to turn that WATCH green.
