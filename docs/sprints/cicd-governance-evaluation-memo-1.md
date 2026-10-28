# CICD-GOVERNANCE-EVALUATION-MEMO-1

**Status:** implemented and verified locally; not merged, not deployed.
**Type:** `FOUNDATION_SPRINT`. **Base:** `f9134aac` (the deployed production authority).
Post-audit roadmap, priority 4 (CI/CD optimisation). No migration, no route, no permission.
No gate rule, decision, threshold or required check changes.

## 1. Profile

The NSF-R011 Critical Test Gate on the base branch takes about 6,000 s, and its test step
alone is 5,603 s (run `40491341757`). The slowest tests in it are the ones that render the
foundation governance summary: each one takes about 100 s.

The same cost appears outside the test step, in the release-safety commands NSF-9 runs:

| NSF-9 step (run `40491341757`) | time |
|---|---|
| ENT-14 load test scale projection check | 51 s |
| ENT-15 enterprise documentation check | 100 s |
| ENT-16 enterprise foundation closure check | 100 s |

The time grows with the gate's position in the sequence, not with how much the gate reads.

## 2. Cause

The enterprise gates ENT-5 to ENT-16 re-verify their predecessors: ENT-6 asks ENT-5 for
its decision, ENT-7 asks ENT-5 and ENT-6, and so on up to ENT-16. Each gate receives
fresh predecessor instances, so every level re-runs the entire chain beneath it, and the
work doubles at each level.

Counting constructions through the container during one `architecture:foundation-governance-summary`:

- ENT-5 is built 2,080 times.
- 12,592 gate objects are built in total.

## 3. Change

`App\Support\Foundation\GovernanceEvaluation` is a container singleton:

- `remember(key, compute)` caches a result for the lifetime of the **outermost** evaluation.
- The cache is emptied when that evaluation returns, including when it throws.
- `within(fn)` makes one caller's whole run a single evaluation. The governance summary uses it.

Each of the twelve gates keeps its body unchanged: the body moves to a private `evaluate()`,
and `collect()` reads through the memo. The key is the class, the method and a hash of the
arguments, so ENT-16's optional pre-computed ENT-15 report is a different key from a bare
call.

Why reusing a result is safe:

- One evaluation never changes configuration, the database or the files the gates read.
  They are read-only checks.
- A gate asked twice inside one evaluation would compute the same answer again.
- Nothing survives an evaluation. Two calls separated by a configuration change, which is
  what tests do, always see a fresh result.

Kill switch: `foundation_governance.evaluation_memo` (default `true`). It is deliberately
not read from the environment, so a host cannot turn a gate's caching on or off. With the
switch off, every call recomputes and the result is unchanged.

## 4. Verification

`tests/Feature/Foundation/FoundationGovernanceEvaluationMemoTest.php` (19) is declared
mandatory and selected by the existing `FoundationGovernance` token. It checks:

- a value is computed once per evaluation and again in the next one;
- no result outlives a configuration change;
- nothing is left behind after an exception;
- the kill switch recomputes every time;
- each of the twelve gates reads through the memo (dataset);
- a gate gives the identical result with the switch on and off;
- a gate called with a different argument in the same evaluation is never answered from the
  cache (ENT-16 with an empty ENT-15 report versus a bare call);
- one governance summary computes each gate exactly once (12 misses), and the memo is
  empty afterwards.

Mutation controls, all five detected (sources restored byte-identical afterwards):

| Mutation | Result |
|---|---|
| memo survives the outermost evaluation | 1 failed |
| governance summary not run as one evaluation | 1 failed |
| ENT-8 bypasses the memo | 2 failed |
| kill switch ignored | 1 failed |
| key ignores the arguments | 1 failed (survived until the argument test was added) |

### Benchmark (local, canonical `f9134aac` against this branch, same scratch database)

| Measurement | canonical | this branch | result |
|---|---:|---:|---|
| `foundation:enterprise-closure-check --json` | 60.4 s | 0.6 s | byte-identical JSON, GO |
| `foundation:enterprise-documentation-check --json` | 63.9 s | 0.6 s | byte-identical JSON, GO |
| `foundation:load-test-scale-projection-check --json` | 32.9 s | 0.6 s | byte-identical JSON, GO |
| `architecture:foundation-governance-summary --json` | 103.3 s | 0.67 s | identical except `generated_at` |
| `FoundationGovernanceSummaryCommandTest` (11) | 1,043 s | 4.7 s | 11 passed both sides |
| `Ent16EnterpriseFoundationClosureTest` (17) | 517 s | 3.8 s | 17 passed both sides |
| `Lb1GovernanceIntegrationTest` (7) | 308 s | 1.2 s | 7 passed both sides |

The three test classes take 1,868 s on canonical and 9.6 s on this branch.

Regression on this branch covers `tests/Feature/Architecture`, `Foundation`, `Cicd`,
`Dq31AmbiguousBatchRepairTest` and `NsfGovernanceCheckCommandTest`: 1,242 tests, 10,312 assertions,
0 failures, 4 skipped (PostgreSQL and Redis guards that CI runs), in 260 s. The audit measured
`tests/Feature/Architecture` alone at 2 h 28 min before this change.

The expected effect on the critical gate is an estimate, not a measurement: the audit attributed
14–21% of the critical test step to this recomputation, and the governance-audit and NSF-9/NSF-10
command steps shrink as well. The real figure is the first CI run after merge.

## 5. Evaluated, not changed

- **Dependency caching.** Measured on the same run: a cached `composer install` takes 3 s and an
  uncached one 8–9 s. Only NSF-9, NSF-10 and the Full Suite were uncached, so the saving is about
  8 s per pull-request run. It is in a separate pull request,
  `CICD-COMPOSER-CACHE-RELEASE-GATES-1`, mainly for resilience against Packagist outages.
- **Parallel testing.** Not enabled. More than thirty governance and evidence test files write to
  fixed shared paths (`storage/release-evidence/latest`, `storage/release-evidence/local`), so
  running them concurrently on one runner would race on the same files. The audit's
  parallel-testing readiness review rated matrix sharding across separate jobs ready, and
  in-runner paratest ready only after those writers are isolated.
- **Next step.** This change removes most of the cost that sharding was meant to spread. Re-measure
  the critical gate after this merges before deciding whether sharding is still worth its
  governance cost; it needs a contract that the union of shard filters equals the canonical
  filter.

## 6. Deploy and rollback

The change is in PHP only and goes out with a normal deploy. To roll back, set
`foundation_governance.evaluation_memo` to `false` (source change, redeploy), or
`git revert`. Either way the gates return to recomputing their chains, with identical
decisions.
