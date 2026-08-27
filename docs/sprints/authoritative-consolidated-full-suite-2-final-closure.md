# AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-2

**Final stabilization programme closure.**

`FULL_SUITE_2_STATUS = PENDING_AUTHORIZED_EXECUTION` at the time this tree was
frozen. That is deliberate and is not updated later. See §3.

---

## 1. What this sprint is

It carries the closure contract into the repository **before** the authoritative
Full Suite runs, so that the tree the suite tests is the same tree that is
merged, deployed, and pointed at by the final GO tag.

The ordering is the entire substance of the sprint. A closure that runs the
suite first and then commits its own evidence has not tested the tree it ships:
the commit that records the PASS is, by construction, absent from the tree that
passed. So this candidate contains only governance text, is frozen before the
trigger, and never changes afterwards.

```
RUNTIME_APPLICATION_FILES_CHANGED = 0
TEST_SEMANTICS_CHANGED            = false
CI_SEMANTICS_CHANGED              = false
```

### Authority

| | |
|---|---|
| Readiness tag | `final-pre-full-suite-readiness-check-1-go` |
| Readiness SHA | `e9d172ce639ace8a7ac599925f97c7956daaffa7` |
| Readiness / candidate-parent tree | `18972f0e8358cdde4be0783e1b23c9c418248887` |
| Base branch at freeze | the same commit — base, readiness tag and production HEAD coincided |

---

## 2. The one thing that must not be done

**The temporary Full-Suite policy stays `ACTIVE` through this sprint.**

`.github/ci-policy/full-suite-policy.json` names the consolidated closure as the
occasion for retiring the policy, and it is tempting to retire it here. That
would be a defect, not a completion.

With the policy `RETIRED`, `scripts/ci/resolve-full-suite-policy.sh` resolves a
`push` to base as `POLICY_RETIRED_PUSH_TO_BASE` → `full_suite_authorized=true`.
Merging this candidate is exactly such a push. Retiring the policy inside the
closure candidate would therefore launch a **second, unauthorised Full Suite the
moment the closure merges** — against a standing rule of one attempt per explicit
authorisation.

With the policy left `ACTIVE`, the merge push resolves
`TEMPORARY_FULL_SUITE_POLICY_ACTIVE` → `full_suite_authorized=false`, and the
gate is skipped. Retirement is a separate governance act with its own
authorisation, performed after closure.

---

## 3. Why this document does not record the result

The Full Suite result is **not** written here, and `full_suite_status` in
`.sprint/current.yml` stays `PENDING_AUTHORIZED_EXECUTION`.

Any post-PASS commit — even a one-line "run 123 passed" — changes the tree. The
tested tree and the shipped tree would then differ, and the recorded evidence
would be evidence for a tree that was never tested. The run therefore attests
itself through artefacts that live outside the tree:

- the annotated GO tag `authoritative-consolidated-full-suite-2-final-program-closure-go`,
- the GitHub Actions run and job, retained with their logs.

A durable rule discovered only after PASS is a NO-GO plus a new authorisation,
never a quiet amendment.

---

## 4. Full Suite #1 — historical, preserved, not reused

| | |
|---|---|
| Run | `32700184849`, job `97362090553` |
| Candidate | `e7b8dde460f3678f2a97b376e83aea66c168c173` — unmerged, undeployed |
| Result | 1 failed, 1 risky, 5 skipped, 7372 passed, 33823 assertions |
| Classification | test-contract defect (PDF line wrap), not a product defect |

Its authorisation was consumed by that failure. Five correctives shipped
afterwards, each in its own sprint with its own GO tag:
`FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1`, `FIX-PDF-TEMPFILE-LEAK-1`,
`FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1`,
`FIX-TEST-TEMPFILE-SIBLING-LEAKS-1`, `FIX-CI-GATE-WORKDIR-TEMPFILE-LEAK-1`,
then `FINAL-PRE-FULL-SUITE-READINESS-CHECK-1`.

That run is never re-run, and its evidence is never altered.

---

## 5. Authorisation and execution contract

```
FULL_SUITE_2_EXPLICITLY_AUTHORIZED = true      # explicit user instruction
FULL_SUITE_2_MAX_ATTEMPTS          = 1
```

The authorisation is consumed at **trigger**, not at pass. Canonical mechanism,
verified against the workflow source rather than assumed:

- `workflow_dispatch` on `.github/workflows/foundation-evidence-gates.yml`
- `run_full_suite = true` **and** `full_suite_policy_override = true`
  (while the policy is ACTIVE, the first alone resolves
  `TEMPORARY_FULL_SUITE_POLICY_ACTIVE_OVERRIDE_REQUIRED`)
- `full_suite_gate` additionally requires one critical-gate variant to have
  genuinely succeeded — a skipped sibling never satisfies it.

Terminal non-success — FAIL, TIMEOUT, CANCELLED, PARTIAL, runner loss, wrong
SHA, misroute, platform outage — ends the attempt. No automatic retry, no
merge, no deploy, no tag.

---

## 6. Residual ledger — reconciled

Carried from `docs/sprints/final-pre-full-suite-readiness-check-1.md` and
re-reconciled against this candidate. Dispositions are unchanged: this sprint
changes no runtime behaviour, so nothing in the ledger could have moved on its
own, and nothing was reclassified to make closure easier.

```
TOTAL_RESIDUAL_ITEMS = 26

CLOSED           = 5    (R-01, R-03, R-04, R-21, R-22)
ACCEPTED_RISK    = 13   (R-02, R-05, R-06, R-07, R-12, R-14, R-15, R-17,
                         R-18, R-20, R-23, R-24, R-26)
BLOCKED_EXTERNAL = 3    (R-09, R-10, R-16)
DEFERRED         = 4    (R-08, R-11, R-13, R-25)
SUPERSEDED       = 1    (R-19)
NOT_APPLICABLE   = 0
REAL_DEFECT      = 0
```

Two dispositions are worth restating because closure pressure argues against
both:

- **R-08 restore drill / R-06 monitoring `laravel_log`.** Production may
  truthfully report WATCH. A WATCH is operational evidence about the
  environment, not a code defect, and it is neither relabelled nor
  manufactured away.
- **R-09, R-10, R-16 external blocks.** SATUSEHAT sandbox credentials and Meta
  WhatsApp credentials are outside the repository. They stay BLOCKED_EXTERNAL;
  proximity to closure does not promote them to defects, nor demote a defect
  to reach GO.

`EXTERNAL_ITEMS_MISCLASSIFIED_AS_REAL_DEFECT = 0`.

---

## 7. Pre-suite blocker counters

Every counter must be zero before the authorisation is spent. Measured values
are recorded in §8.

```
REAL_DEFECT_BLOCKERS                  = 0
KNOWN_TEST_FLAKES                     = 0
KNOWN_ACTIVE_TEST_RESOURCE_LEAK_FAMILIES = 0
UNKNOWN_CICD_TEMP_ALLOCATORS          = 0
CRITICAL_SECURITY_BLOCKERS            = 0
HIGH_SECURITY_BLOCKERS                = 0
KNOWN_MONITORING_FAILURES             = 0
PRIVACY_BLOCKERS                      = 0
CLINICAL_CORRECTNESS_BLOCKERS         = 0
DATA_LOSS_BLOCKERS                    = 0
TEST_DETERMINISM_BLOCKERS             = 0
TEST_RESOURCE_LEAK_BLOCKERS           = 0
CI_GOVERNANCE_BLOCKERS                = 0
```

---

## 8. Pre-suite measurement

Recorded in the closure report accompanying this sprint. The measurements that
gate the trigger are: the targeted temporary-resource guards (R-22 ownership,
sibling families, CI workdir), the PDF receipt contiguity corrective, the lab
day-boundary determinism corrective, the clinical workflow foundation, clinical
storage privacy, Legacy RME at rest, the restore-drill contract, the monitoring
baseline, the `Feature/Architecture` suite, and the required non-Full CI gates
against the exact frozen candidate.

---

## 9. Durable foundations pinned by this sprint

Mirrored for assistants in `.cursor/rules/126-authoritative-full-suite-and-final-closure.mdc`.

1. One Full Suite attempt per explicit authorisation; consumed at trigger.
2. No automatic rerun after fail, timeout, cancel, partial or runner failure.
3. The tested tree is immutable from the moment the attempt starts.
4. candidate tree == tested tree == merged tree == deployed tree == GO-tag tree.
5. A squash merge may change the SHA; it may never change the tree.
6. No post-Full-Suite evidence commit.
7. The GO tag and the CI run attest the PASS without mutating the tree.
8. Base movement that prevents an identical-tree merge is a closure blocker.
9. The temporary Full-Suite policy is never retired inside the closure candidate.
10. Prefix census is diagnostic; the allocation API is the ownership authority.
11. Unknown-prefix non-canonical temporary allocation fails closed.
12. Terminating a test wrapper does not prove its children terminated.
13. Shared-evidence suites run serially unless isolation is proven.
14. Monitoring and restore-drill WATCH states stay truthful.
15. Production diagnostics never use an interactive REPL.
16. Clinical evidence never returns to public storage.
17. Readiness GO is not Full Suite authorisation.
18. Full Suite PASS alone is not closure: identical-tree merge, VPS deploy with
    exit 0, production verification and the GO tag are all required.

---

## 10. Closure conditions

Closure is declared only when every one of these holds, each from real evidence:

- authoritative Full Suite #2 concluded `success` with zero test failures;
- the run's head SHA and tree equal the frozen candidate's;
- the merge produced the identical tree;
- the deploy ran **on the VPS**, reached exit 0, and left the VPS tree equal to
  the tested tree;
- canonical-domain health, clinical storage privacy, Legacy RME at rest and the
  restore-drill contract all verified;
- no new application or tooling errors in production;
- zero critical and zero high security findings;
- the annotated GO tag points at that same SHA and tree.

Anything short of all of these is NO-GO, and the candidate is neither merged
nor deployed.
