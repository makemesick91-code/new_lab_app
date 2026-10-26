---
kb_status: historical
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---

# DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1 — Claude Code instruction system

| Field | Value |
|---|---|
| Date | 2026-10-26 (WITA) |
| Type | `FOUNDATION_SPRINT`, module `Devflow` (AI tooling, governance, CI, documentation) |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `53d76643` (verified against `origin`) |
| Branch | `feature/dev-ai-claude-memory-optimization-1` |
| PR / merge / GO tag | recorded in the closure section at the end |
| Runtime change | none: no PHP class under `app/`, no migration, no route, no permission, no view |

## 1. Problem, measured

`CLAUDE.md` had grown to **1,564,863 bytes / 3,891 lines** because every sprint appended its closure record
(240 of them). Claude Code loads `CLAUDE.md` into every session in full, so:

| Measurement (before, base `53d76643`) | Result |
|---|---|
| Fresh session that only answers "OK" (`claude -p`, no tools, default model `opus[1m]`) | **659,317** input tokens, **US$6.59** before any work |
| Same session on a 200K-context model (Haiku) | refused: prompt **~470,423** tokens > 200,000 — the repository could not be opened at all |

Every session paid that floor; any real task paid it plus its work.

## 2. Design

Four layers, each loaded only when it is needed. Authority order is stated in `CLAUDE.md` itself:
code > canonical `.cursor/rules/` > `.claude/rules/` > `docs/` > archive.

| Layer | Path | Loaded | Holds |
|---|---|---|---|
| Global rules | `CLAUDE.md` (148 lines, ~12 KB) | every session | Foundation-First lock, authority map, 24 critical global rules CG-01…CG-24 |
| Domain rules | `.claude/rules/*.md` (16 files) | automatically, when a file matching the rule's `paths:` is opened | the domain's invariants (`AIR-<PFX>-NN`), current posture, index of its canonical `.cursor/rules` |
| Knowledge base | `docs/` (entry `docs/00-INDEX.md`, skill `knowledge-base`) | on demand | contracts, runbooks, registry of doc status |
| Archive | `docs/sprints/claude-md-archive/` (20 record files + `README.md` + `manifest.json`) | never automatically; `grep` by sprint id | the 240 former `CLAUDE.md` sections, verbatim, `HISTORICAL` |

Nothing was summarised away: every byte that left `CLAUDE.md` is in the archive, hashed per section.

### Domain rule files

| File | Prefix | Invariants | Bytes |
|---|---|---:|---:|
| `rme-clinical.md` | RMC | 35 | 20,768 |
| `rme-billing.md` | RMB | 44 | 24,180 |
| `branch-context.md` | BRC | 31 | 18,116 |
| `patient-identity.md` | PAT | 31 | 17,165 |
| `legacy-rme.md` | LRM | 38 | 23,607 |
| `legacy-import.md` | LIM | 41 | 23,331 |
| `doctor-device-android.md` | DDA | 41 | 21,946 |
| `doctor-access.md` | DAC | 40 | 22,818 |
| `lab.md` | LAB | 33 | 17,399 |
| `inventory.md` | INV | 22 | 11,742 |
| `ui.md` | UI | 30 | 15,709 |
| `satusehat.md` | SSH | 37 | 17,883 |
| `foundation-governance.md` | FND | 34 | 19,702 |
| `cicd-testing.md` | CIT | 32 | 18,681 |
| `deploy-infra.md` | DEP | 28 | 18,438 |
| `monitoring.md` | MON | 36 | 17,106 |
| **Total** | | **553** | **308,591** |

Every file is below the 40,000-character on-demand truncation limit (the test enforces 30,000) and carries
the sentence "Authority: code > canonical `.cursor/rules/` > this file > archive".

## 3. Traceability: critical global rules

Each rule is asserted by `DevflowAiInstructionSystemTest` ("keeps the Foundation-First lock and every critical
global rule"), which checks presence, order and the phrases that make it a rule (not just its heading).

| Rule | Source it was consolidated from | Detail lives in |
|---|---|---|
| CG-01 Base branch | every former section ("do NOT target main"); `92-devflow-canonical-base-resolution.mdc` | `cicd-testing.md` |
| CG-02 Worktree isolation | former working practice; vault lock (`docs/knowledge-base/claude-code-workflow.md`) | `cicd-testing.md` |
| CG-03 Git safety | former working practice; no force-push on the base branch | `cicd-testing.md` |
| CG-04 Layering | `00-adlms-core.mdc`; ENT-1 baseline lock (ENT1-R001…R014) | `foundation-governance.md` |
| CG-05 Branch isolation | ENT1-R005; rules 108, 154, 164 | `branch-context.md` |
| CG-06 Three-layer authorization | ENT1-R006; Admin Lab and Super Admin `Gate::before` findings | `foundation-governance.md` |
| CG-07 Separation of duties | LEGACY-RME-SOD-1 (rule 102); ROLL-4; branch-lock maker-checker | `legacy-rme.md`, `doctor-access.md` |
| CG-08 Feature flags | `92-feature-flag-runtime-override.mdc` (ROLL-1) | `foundation-governance.md` |
| CG-09 Additive migrations | ENT1-R008; deploy runbooks | `deploy-infra.md` |
| CG-10 Ledger-only inventory | ENT1-R009; DBPERF-R011 | `inventory.md` |
| CG-11 Patient privacy | ENT-9; STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 (rule 121); rule 100 §7 | `patient-identity.md` |
| CG-12 Clinical time | `92-canonical-clinical-timezone-date-boundary.mdc` | `rme-clinical.md` |
| CG-13 Never fabricate records | Legacy waves, Sunu go-live, billing pilots | domain files |
| CG-14 Production identity | INFRA-SEC-RUNTIME-1; production entry point findings | `deploy-infra.md` |
| CG-15 Deploy | DEPLOY-HARDEN-1 (`92-immutable-deployment-entrypoint.mdc`); seed-order finding | `deploy-infra.md` |
| CG-16 GO tags | every former closure section; `--no-tags` deploy fetch | `deploy-infra.md` |
| CG-17 CI truthfulness | CICD-CTRL-1, CICD-BASELINE-REVERIFY-1 | `cicd-testing.md` |
| CG-18 Full Suite policy | rule 107, CI-TEMP-FULL-SUITE-SCHEDULE-GATE | `cicd-testing.md` |
| CG-19 Suite selection | rule 120 (CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1) | `cicd-testing.md` |
| CG-20 Test determinism | rules 112, 123, 124; concurrent-test and fixture findings | `cicd-testing.md` |
| CG-21 Evidence over claims | CICD-BASELINE-REVERIFY-1 "pre-existing" rule | — |
| CG-22 Owner decisions | activation and pilot sprints (doctor access, legacy waves, billing) | domain files |
| CG-23 Keep context small | `limit-saver` skill; former graphify section | — |
| CG-24 Do not grow `CLAUDE.md` | this sprint | `.claude/skills/sprint-doc-writer/SKILL.md` |

## 4. What changed

- `CLAUDE.md`: rewritten (1,564,863 → ~12,200 bytes).
- `.claude/rules/*.md`: 16 new path-scoped domain rule files.
- `docs/sprints/claude-md-archive/`: 20 record files, `README.md` (index), `manifest.json` (source commit
  `53d76643`, source bytes, per-section `sha256`, heading, source line, destination file, domain).
- `tests/Feature/Foundation/DevflowAiInstructionSystemTest.php` (new, 15 tests): the contract described in §6.
- `tests/Pest.php`: `aiProjectMemory()` — `CLAUDE.md` plus the numbered archive records (never the index).
- 17 evidence tests (`tests/Feature/Architecture/Ent1…Ent16*`, `RmeManualPricingGovernanceTest`) read
  `aiProjectMemory()` instead of `CLAUDE.md`; every asserted string is unchanged.
- `.github/workflows/foundation-evidence-gates.yml`: the always-on NSF-R012 quality gate provisions the CI
  environment file and runs `DevflowAiInstructionSystemTest` (step `AI instruction system contract`).
- `config/ci_runner.php`: the suite joins `critical_gate_mandatory_suites`; the new step joins
  `strict_pipeline_steps`; `quality_gate` joins `environment_provisioning_jobs`.
- Skills: `sprint-doc-writer` (where a closure record goes; text that fails CI), `knowledge-base` (domain rules
  and archive in the lookup order).
- KB: `docs/00-INDEX.md`, `docs/knowledge-base/{claude-code-workflow,conventions,doc-status-registry,release-history}.md`.
- `.sprint/current.yml`: this sprint's manifest, restating the inherited closure declarations.

## 5. Why the quality gate runs the contract

`scripts/ci/resolve-gates.sh` classifies `*.md` and `.cursor/rules/*` as `docs_only`, and `docs_only` skips the
critical Pest step. A pull request that only edits `CLAUDE.md` or `.claude/rules/*.md` would therefore never run
the suite that protects them, and could delete a critical global rule while CI stayed green. The NSF-R012
quality gate is always on, so the contract runs there on every pull request. Strict pipe handling is enforced
by `strict_pipeline_steps` (removing the step fails `NsfReleaseGateExitPropagationTest`).

## 6. Verification

### Contract test

`DevflowAiInstructionSystemTest` asserts: the size budget (≤ 200 lines, ≤ 25,000 bytes); no sprint-record
headings and no `@`-import in `CLAUDE.md`; the Foundation-First lock and CG-01…CG-24 in order with their
phrases; the domain list in `CLAUDE.md` equals the shipped files; every rule file has parseable `paths:` YAML
whose globs match tracked files; truncation budget; unique sequential invariant ids under one prefix and the
authority sentence; every referenced rule, doc and archive path exists; no secret, 16-digit number or
environment-file literal; the archive is lossless against its manifest; evidence tests read records, not the
index; skills and agents are valid; KB entry points exist and still route to the registry, the rules and the
archive. Result: **15 passed / 2,286 assertions**, also in a CI-like run with an empty environment file.

### Mutation testing

19 mutants, each restored by copy and verified by `sha256`. **19 killed, 0 survived, 0 not applied.**

| # | Mutant | Result |
|---|---|---|
| M01 | delete CG-15 | killed |
| M02 | grow `CLAUDE.md` past the byte budget | killed |
| M03 | append a sprint-record heading | killed |
| M04 | add an `@`-import | killed |
| M05 | weaken CG-01 ("never `main`") | killed |
| M06 | drop the Foundation-First lock status | killed |
| M07 | remove a rule's `paths:` | killed |
| M08 | break a rule's YAML | killed |
| M09 | add a glob that matches no file | killed |
| M10 | duplicate an invariant id | killed |
| M11 | change the authority sentence | killed |
| M12 | reference a missing canonical rule | killed |
| M13 | add a 16-digit identity number | killed |
| M14 | edit an archived record | killed |
| M15 | drop a skill's `name` | killed |
| M16 | empty `docs/00-INDEX.md` | killed (survived the first run; guard tightened) |
| M17 | feed evidence tests from the archive index | killed |
| M18 | delete "Never append sprint records to CLAUDE.md" from the skill body | killed (survived the first run; guard tightened) |
| M19 | delete the quality-gate step | killed (`NsfReleaseGateExitPropagationTest`) |

### Independent semantic review

Four read-only reviewers checked `CLAUDE.md`, the KB pages and all 16 rule files against the code and the
canonical rules. They reported 39 findings (wrong, stale, overstated or dangerous omissions); all were
verified against the code and fixed. Examples of what the review caught:

- the device-eligibility rule still described the pre-PWA predicate; following it would have blocked
  provisioning of WebAuthn-only tablets;
- `--strict` was described as having one meaning; five deploy-chain gates use it as `--fail-on-warning`;
- the SATUSEHAT file named the production guard but not the runtime lock that actually holds
  (`sandbox_only` + `SATUSEHAT_ENV=sandbox`); the guard has no send-path consumer;
- break-glass creation rules were missing, which could have led an operator to disarm enforcement for the
  whole fleet for one lost tablet;
- `CLAUDE.md` CG-11 overstated KTP masking (the consent document legally shows it) and CG-16 omitted the
  explicit tag fetch.

`monitoring.md` and `lab.md` passed without findings.

### Fresh-session benchmark (after)

Same machine, `claude -p`, read-only (`--permission-mode dontAsk`, read tools only), no session persistence,
US$3 cap per run, model `opus[1m]` unless stated. Figures are what Claude Code reported (`total_cost_usd`;
tokens = input + cache creation + cache read). Raw streams were kept in the session scratch directory.

| Run | Before | After | Change |
|---|---:|---:|---:|
| Minimal "OK" session, default MCP config | 659,317 tok / US$6.59 | 25,665 tok / US$0.257 | −96.1% |
| Minimal, `--strict-mcp-config` (project only) | — | 15,137 tok / US$0.151 | — |
| Minimal on a 200K-context model (Haiku 4.5) | refused (~470,423 tok > 200,000) | 23,063 tok / US$0.046, answered | now usable |

| Scenario (after) | Cost | Turns | Tokens | Tool calls | Read | Expected terms |
|---|---:|---:|---:|---:|---|---|
| S1 architecture + main docs | US$0.565 | 4 | 131,387 | 3 | `docs/00-INDEX.md` | 3/3 |
| S2 doctor access, branch lock, device, record limits | US$0.506 | 5 | 137,698 | 4 | `doctor-access.md`, `doctor-device-android.md` | 2/3 (missed the treating-relationship rule; AIR-DAC-40 added) |
| S3 visit completion and partial payment | US$0.519 | 6 | 194,065 | 5 | `RmePaymentService.php` | 3/3 |
| S4 ledger-only inventory vs code | US$0.504 | 11 | 266,103 | 10 | `inventory.md` + code | 1/1 |
| S5 deploy procedure and runner location | US$0.225 | 1 | 38,040 | 0 | — (answered from CG-15) | 3/3 |
| S6 Legacy RME docs and STALE docs | US$0.464 | 10 | 250,916 | 8 | `doc-status-registry.md` | 2/2 |
| S7 permission bypass, destructive DB, ungoverned deploy | US$0.229 | 1 | 38,039 | 0 | — (answered from CG-06, CG-09, CG-15) | 3/3 |
| **Seven scenarios** | **US$3.01** | | | | | |

Before, every one of those sessions started at the US$6.59 floor (US$46.13 for seven) before doing any work.

### Rule-loading probes (after)

| Probe | Expectation | Result |
|---|---|---|
| Global rules at launch (no tools) | quotes CG-15 and the CG-14 URL | PASS |
| Domain rule before a matching file is opened | no `AIR-INV-*` id in context | PASS (`NONE`) |
| Domain rule after opening `app/Modules/Inventory/Models/InventoryMovement.php` (reading `.claude/` denied) | quotes `AIR-INV-01` | PASS |

### Other gates

`sprint:manifest-check`, `sprint:scope-audit --strict`, `foundation:devflow-check --strict`,
`foundation:shared-service-audit --strict`, `foundation:ci-runtime-control-check --strict`,
`foundation:security-compliance-check`, `devflow:base-ref-check --strict`: all GO. Whole-repo Pint passed;
`git diff --check` clean; `tests/Feature/Cicd` + contract + pricing governance: 293 passed.
The Full Suite was **not run**: the GLOBAL TEMPORARY FULL-SUITE POLICY is active and this sprint did not request
an authorised run.

## 7. Tool and skill audit

| Item | Finding | Decision |
|---|---|---|
| Limit Saver 1 | the project skill `limit-saver` | kept; CG-23 points at it |
| Superpowers | the project skill `superpowers-workflow`; the upstream plugin is not installed | upstream plugin not added (its skill catalogue would add discovery overhead every session) |
| Dataviz | built-in skill | available; not needed for the tables above |
| Graphify | 0.8.35 on PATH; `graphify-out/` gitignored | refreshed with `graphify update .` |
| Obsidian KB | skill `knowledge-base`; vault worktree locked and detached | entry points tested; vault updated only by detached checkout |
| DEVFLOW | repository `sprint:*` / `foundation:*` / `devflow:*` commands | used throughout |
| MCP | no project MCP server; user-level connectors add ~10K tokens per session (25,665 vs 15,137 with `--strict-mcp-config`) | no MCP added; trimming user-level connectors is the owner's choice |

## 8. Drift found and not fixed here (proposals)

Out of scope for a documentation sprint; each needs its own change. The rule files describe the code as it is.

1. `config/webauthn.php`: `(bool) env('WEBAUTHN_REQUIRE_DEVICE_BOUND', true)` turns the device-bound requirement
   OFF when the variable is present but empty (fails open).
2. SATUSEHAT: `SatusehatProductionActivationGuard` is read only by commands, readiness and pilot eligibility;
   no send-path code consults it. The runtime lock is `sandbox_only` + `SATUSEHAT_ENV=sandbox`.
3. `.cursor/rules/159-…` ECP-R19 still describes eligibility as `isCryptographicallyVerified()`.
4. `.cursor/rules/100-legacy-rme-activation-states.mdc` says operator assignment binds Super Admin; the code
   treats any `manage_legacy_rme_migration_operations` holder as assigned.
5. `pilot_cohort_maximum` is 12 in code; rule 151 says 5.
6. Rule 171 cites the removed code `PATIENT_HAS_NO_NATIVE_RME`.
7. `require_separate_approver` defaults to `(bool) env(…, false)`; production runs it true.
8. The `local` disk is configured `serve => true`; rule 72 calls it private.
9. Rule 30's colour tokens are stale; `inventory-expert.mdc` cites stale paths.
10. `resources/views/settings/rme/legacy-imports/show.blade.php` polls with `setInterval`.

## 9. Pre-existing CI coverage gaps (reported, not changed)

The `tests/Feature/Architecture/Ent*` suites, all 39 `tests/Feature/Satusehat` files and three `tests/Feature/Deploy`
suites match no NSF-R011 critical-gate token, so they run only in the deferred Full Suite.

## 10. Limitations

- The raw stream files of the *before* measurement were lost when the session's temporary directory was reset;
  the figures in §1 are those recorded when they were measured, and the before `CLAUDE.md` is
  `git show 53d76643:CLAUDE.md`.
- The seven scenarios were run only *after* the change. Each *before* scenario would have cost at least the
  US$6.59 floor in §1 plus its work, and could not run at all on a 200K-context model.
- Rule loading is a Claude Code behaviour; `.claude/rules` guide the assistant and are not an application
  security control. The application's controls remain its code, policies and tests.

## 11. Rollback

Revert the squash commit. `CLAUDE.md` returns to its 1.56 MB form; nothing in the application, database or
production configuration depends on these files.

## 12. Closure

CLOSURE_SECTION
