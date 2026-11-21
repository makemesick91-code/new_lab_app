# CI-CD-PR-ONLY-POLICY-1 — phase B: release guard accepts verified manual-dispatch CI (rule 236 §5)

- Type: SECURITY_FIX · Module: Deploy · Manifest: `.sprint/ci-cd-pr-only-policy-1.yml` again since owner decision 16
  (2026-11-21, content unchanged); from owner decision OD-B3-1 until then `.sprint/current.yml`, while the
  DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest was parked verbatim at `.sprint/dms-rme-comprehensive-closure-1.yml`
  ("Manifest switch" below). That manifest is `.sprint/current.yml` again.
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3a231c28` (code, #673);
  manifest switch @ `452011a8`
- Planned GO tag: `ci-cd-pr-only-policy-1-phase-b-go` (only after merge, deploy and production verification, CG-16)
- Rule: `.cursor/rules/236-direct-signed-release-approval.mdc` §5 (amendment); runbook
  `docs/runbooks/production-host-identity.md` §6g

## State

| State | Status |
|---|---|
| IMPLEMENTED IN BRANCH | yes — code: `security/ci-release-guard-dispatch-evidence-1`; manifest switch: `governance/ci-cd-pr-only-policy-1-phase-b-current-manifest` |
| MERGED | code: **yes** — #673 squash-merged as `452011a8`; its post-merge push run 44823046028 is green. Manifest switch: **yes** — #674 squash-merged as `f31b554be922d1c147d23e7e00523b2d23e4a413` (tree identical to the tested head); its post-merge push run 44862172974 is green |
| DEPLOYED | **yes** — 2026-11-21, deploy `20261121-095125` of `f31b554be922d1c147d23e7e00523b2d23e4a413` (release id `ci-cd-pr-only-policy-1-phase-b`, approval contract 3, push run 44862172974), `DEPLOY RUNNER OK` |
| RELEASED GO | **yes** — annotated tag `ci-cd-pr-only-policy-1-phase-b-go` (object `6c391e1cc7b8fdf33a33ba37bfd941e7d52ab367`) on `f31b554b…` |
| VERIFIED IN PRODUCTION | **yes** — 2026-11-21 (phase B4, decision 13): the installed guard answered `check-approval` GO with `PASS: required CI — workflow_dispatch run 44898680653` for `ca86734e80846744ea2cdecc6f4aa9c5acb172d2`; test approval `49b95a0323c6effb9477423d86057b13` withdrawn, then DENIED; no deploy. See "Phase B4 result" |

Gate 1 (RME-LAB-FEE-APPROVAL-1) stays **NO-GO**. BK-2 not invoked. Production changes in this phase: the one
authorised deploy (decision 10); see "Release and GO tag (phase B)". Phase B4 added only the test approval's
stored record, its withdrawal marker and its spent marker in the host's approval store: no code, configuration,
service, timer or database change.

## Owner decisions (CG-22)

1. 2026-11-21 — target policy: **CI runs automatically only for pull requests**; manual CI stays available through
   `workflow_dispatch`; deployment stays manual on the production host.
2. 2026-11-21 — after the audit showed that removing the base `push` trigger would deny every contract-3 release, the
   owner authorised **"RELEASE GUARD COMPATIBILITY PR ONLY"**: accept push **or** manual dispatch evidence for the
   exact release commit; do **not** remove the `push` or `schedule` triggers yet; no merge, deploy or GO tag.
3. 2026-11-21 — **OD-B3-1, option A**: the phase-B release carries its own GO tag. `.sprint/current.yml` names this
   sprint; the DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest is parked verbatim and restored later by owner decision.
   Authorised: one governance PR (new worktree and branch, manifests, governance docs, related tests, isolated local
   tests, commit, push, PR) that stops at review; merging needs a separate owner approval. Not authorised: VPS access,
   production changes, BK-2, GO tags, approval signatures, deployment, CI trigger changes. Gate 1 of
   RME-LAB-FEE-APPROVAL-1 stays NO-GO; the Ready Pilot SPN4 target is unchanged.
4. 2026-11-21 — **P-1, read-only production audit**: the assistant may run read-only checks on the verified Biznet
   host only (target verification, trust-check, sudo-policy-check, posture, drift-check, deploy status and historical
   verify, service and lock state, backup timer state without sudo, HTTP GET of the health and login endpoints). No
   sudo, no change to files, configuration, services, timers, database or deployment; no Hostinger; no BK-2.
5. 2026-11-21 — **O1, migration status**: the owner ran `php artisan migrate:status` as the runtime user in the
   owner's own terminal (read-only; the assistant did not use sudo). Owner-reported result: 237 migrations, 237 Ran,
   0 Pending, last `2026_11_18_300001_add_unassigned_reason_to_trx_rme_patient_doctor_assignments_table`.
6. 2026-11-21 — **P-3, release identity and activation plan approved**:
   - target `f31b554be922d1c147d23e7e00523b2d23e4a413`, release id `ci-cd-pr-only-policy-1-phase-b`, planned GO tag
     `ci-cd-pr-only-policy-1-phase-b-go`, the verified Biznet production host, type SECURITY_FIX / Deploy; none of
     these may change without a new owner approval;
   - base freeze until the guard is active in production and the phase B4 dispatch proof is recorded; PR #672 is not
     merged during the freeze;
   - conditions: no unexpected pending migration; release guard, trust anchor and host identity valid; every deploy
     lock idle; production drift NONE; the required CI keeps satisfying the guard;
   - signed approval valid for 24 hours from signing (P-4); no deploy start between 02:25 and 02:40 WITA; an expired
     approval needs a new one;
   - deploy only manually through the Biznet procedure (runner on the VPS, never a workstation or GitHub Actions);
   - rollback only on an explicit owner decision, to the previous release `988c1a9a` after its full SHA and
     eligibility are re-verified; code only, never an automatic database restore;
   - read-only smoke after the authorised deploy: `/login`, `/health/live`, `/health/ready`, `/health/lb`, private
     storage protection, services, queue and scheduler, backup timers, deploy posture, production drift; no change to
     patient data or clinic transactions;
   - GO tag only after the deploy completed, `DEPLOY RUNNER OK` is verified, the production SHA matches the target,
     the smoke passed, no new drift, and the owner approved the tag;
   - BK-2 out of scope (not run, changed, rescheduled or triggered);
   - authorised now: a dedicated signing worktree at the target and read-only checks of the signer. Not authorised:
     running the signer, the owner's passphrase, creating or submitting a signed approval, production
     `check-approval`, deploy, rollback, GO tag, CI trigger removal, production or database changes.
7. 2026-11-21 — **P-4, signing: PREPARED / NOT YET AUTHORIZED.** No traceable explicit owner authorisation of the
   signing is recorded. Signing needs an explicit owner authorisation, recorded in this document after this record is
   merged.
   - Prepared under decision 6: a dedicated signing worktree detached at the target, and read-only checks of the signer
     and its prerequisites (see "Release readiness (phase B3)").
   - Planned parameters, for the owner to authorise or change: release id `ci-cd-pr-only-policy-1-phase-b`, target
     `f31b554b…`, `--go-tag ci-cd-pr-only-policy-1-phase-b-go --hours 24`, signed by the owner in the owner's own
     terminal from that worktree.
   - Constraints proposed for that authorisation: the assistant may give instructions and check the resulting metadata.
     It may not run the signer, see the passphrase or the private key, copy key material, submit the approval, run
     production `check-approval`, deploy, tag, roll back or touch BK-2. A mismatch of SHA, branch, host identity, trust
     anchor or validity window stops the process.
   - P-5 (submit, check, deploy) needs its own approval.
   - **Status: NOT SIGNED.** The signer has not been run and no approval bundle exists.
8. 2026-11-21 — **Option A, CG-22 record**: decisions 4–7 are recorded through one docs-only pull request that changes
   only this file (new worktree and branch from the verified remote base, local documentation and DEVFLOW checks,
   commit, push, pull request, review of its CI). Merging it needs a separate owner approval. Base freeze, the release
   target `f31b554b…` and every prohibition of decision 6 stay in force. Signing is not authorised (decision 7).
9. 2026-11-21 — **P-4, signing of the phase-B release approval: AUTHORISED** by the owner. This explicit decision
   supersedes the status recorded in decision 7.
   - Identity: target `f31b554be922d1c147d23e7e00523b2d23e4a413`, release id `ci-cd-pr-only-policy-1-phase-b`, planned
     GO tag `ci-cd-pr-only-policy-1-phase-b-go`, signed approval valid for 24 hours from signing, the verified Biznet
     production host. None of these may change without a new owner approval.
   - CG-22: this decision is recorded through a docs-only pull request that changes only this file. Merging it needs a
     separate owner approval, and the record must be on the base branch before the signer runs.
   - Before signing, the assistant re-verifies and stops on any mismatch:
     1. the target SHA is still valid;
     2. only documentation the release guard allows follows the target on the base;
     3. the target's CI still satisfies the release guard;
     4. the signing worktree is detached at the target and clean;
     5. its signer, library, authority and manifest match the target;
     6. the GO tag does not exist;
     7. the release id is unused, as far as read-only evidence shows;
     8. the production trust anchor is valid;
     9. production posture shows nothing that blocks the release;
     10. no process or operation is left over.
   - Signing: only after every prerequisite passes and this record is merged. The assistant gives the exact
     instructions; the owner runs the signer in the owner's own terminal and checks every record field before
     confirming. The assistant does not run the signer, enter or ask for the passphrase, read or copy the private key,
     keep secret material in any report, or change the signed approval.
   - After signing, the assistant checks only non-secret metadata: release id, target SHA, approval id, digests, GO tag,
     expiry. It does not submit the approval or run production `check-approval`. P-5 needs a separate approval.
   - Not authorised: deploy, rollback, GO tag, CI workflow change, push or schedule trigger removal, application or
     database change, service or timer change, BK-2.
   - **Status when this record was written: NOT SIGNED.** The signer has not been run and no approval bundle exists.
10. 2026-11-21 — **P-5, activation of the phase-B release guard through one manual Biznet deploy: APPROVED,
    conditionally.** The owner signed the approval under decision 9 (see "Signed approval (P-4 result)").
    - Identity: target `f31b554be922d1c147d23e7e00523b2d23e4a413`, release id `ci-cd-pr-only-policy-1-phase-b`, planned
      GO tag `ci-cd-pr-only-policy-1-phase-b-go`, CI evidence push run 44862172974, approval contract 3, signed
      approval valid until 2026-11-22 17:10:10 WITA. None of these may change without a new owner decision.
    - CG-22 option (a): this decision is recorded through a docs-only pull request; merging it needs a separate owner
      approval. No submission or deploy before this record is on the base branch.
    - Authorised, once this record is merged and every prerequisite is re-verified, one manual deploy sequence for
      this release id, through the operator entrypoint on the verified Biznet host:
      1. production target verification;
      2. trust-check;
      3. sudo-policy-check;
      4. production posture;
      5. drift-check;
      6. base freeze and CI evidence;
      7. submit the signed approval;
      8. check-approval;
      9. start;
      10. follow;
      11. verify;
      12. read-only smoke;
      13. posture and drift after the deploy.
      `start` only if `check-approval` says GO. Any unexpected change stops the sequence and returns it to the owner.
    - Bounds: only the verified Biznet host, only the operator entrypoint, the runner only on the VPS, never Hostinger,
      no parallel deploy, no second start after a dropped SSH session (follow the same deploy), no start between 02:25
      and 02:40 WITA, and the approval must still be valid whenever an operation needs it.
    - Backup and migrations: the runner's own pre-deploy backup must run and be verified (it is not BK-2). BK-2 is not
      run or triggered, and no backup timer changes. Migrations must still be 237 Ran / 0 Pending before the deploy;
      any unexpected pending migration stops it. No `migrate:fresh`, `migrate:reset` or manual migration outside the
      runner.
    - Acceptance:
      1. `DEPLOY RUNNER OK`;
      2. `DEPLOY_APPROVED_TARGET_MATCH=YES`;
      3. contract 3 and the approval id match;
      4. the CI run id matches;
      5. production HEAD equals the target;
      6. deploy locks back to idle;
      7. `PRIVSEP_DRIFT=NONE`;
      8. the health endpoints and login pass;
      9. private storage stays protected;
      10. application ERROR lines do not rise above the 166 baseline;
      11. services and timers match the baseline;
      12. no new pending migration;
      13. no unexpected change to the queue, the scheduler or system permissions.
    - Stop on: host identity mismatch; trust-check failure; `check-approval` DENIED; an expired approval; a spent
      release id; CI evidence not accepted; a GitHub API rate limit that blocks verification; a lock HELD or BUSY; a
      pre-deploy backup that does not verify; `verify` not OK; production HEAD not the target; new drift; rising ERROR
      lines; any database or service change outside the scope. No workaround and no disabled safeguard.
    - Rollback is not authorised by P-5. If the deploy fails or does not meet the acceptance, stop and report. A
      rollback needs an explicit owner approval based on the real state and a verified rollback target.
    - Not authorised by P-5: GO tag, push or schedule trigger removal, disabling GitHub workflows, phase B4, phase B5,
      RME/LAB changes, BK-2, configuration or database changes outside this release. After the deploy and smoke, a
      full report and a stop at P-6 (GO tag).
    - **Status when this record was written: signed, NOT SUBMITTED, NOT DEPLOYED.**
11. 2026-11-21 — **P-6, GO tag: APPROVED** by the owner on the P-5 result (deploy `20261121-095125`,
    `DEPLOY RUNNER OK`, production SHA = target, contract 3, push run 44862172974, smoke passed, drift NONE, 13/13
    acceptance). One annotated tag `ci-cd-pr-only-policy-1-phase-b-go` exactly on
    `f31b554be922d1c147d23e7e00523b2d23e4a413`, after fetching references and tags, with the tag absent locally and
    remotely and the target's tree and manifest re-verified. Push only that tag. Never move, delete, re-create or
    force-push it; no other branch or commit, no DMS-RME-COMPREHENSIVE-CLOSURE-1 tag. Not authorised: redeploy,
    rollback, a new approval, phase B4, trigger or workflow changes, VPS or database changes, BK-2.
12. 2026-11-21 — **Closure record and phase B4 audit**: one docs-only pull request that records the P-5 and P-6
    evidence in this file and states that phase B is RELEASED GO while the B4 dispatch proof is open; it stops at
    review, and merging needs a separate owner approval. A read-only B4 readiness audit and technical plan. Not
    authorised: `workflow_dispatch`, a new signed approval, production `submit-approval` or `check-approval`,
    redeploy, rollback, GO tag changes, trigger or workflow changes, VPS or database changes, BK-2, restoring the
    DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest, removing worktrees without a named list.
13. 2026-11-21 — **Phase B4, production verification of dispatch evidence: APPROVED.** It proves that the deployed
    guard accepts a valid `workflow_dispatch` run of the exact base commit. It is a release-approval test, not a new
    deploy.
    - CG-22: this decision is recorded through a docs-only pull request before the test. Merging it needs a separate
      owner approval; the base tip after that merge is the B4 target.
    - Base freeze, from this record on the base until B4 ends: no merge or push to the base, PR #672 waits, and no
      documentation change while the dispatch runs. If the base moves, the test stops and the target is re-evaluated.
    - Dispatch: once every prerequisite passes, one normal `workflow_dispatch` of Foundation Evidence Gates on the base
      branch at the frozen tip. Required: the official workflow; the base branch; head SHA equal to the B4 target; no
      CI-PERF-04 evaluation input; the critical regression tests run; the required jobs succeed; completed/success;
      the newest run the guard accepts. A failed or cancelled dispatch, or a changed SHA, stops the test.
    - Test approval: release id `ci-cd-pr-only-policy-1-b4-dispatch-test`, target the frozen and verified tip, GO tag
      `ci-cd-pr-only-policy-1-phase-b-go`, valid for 2 hours. The owner runs the signer in the owner's own terminal,
      only after the dispatch succeeded and every signer prerequisite passes. The assistant never runs the signer,
      reads the private key or asks for the passphrase.
    - After the test approval is verified, the assistant submits it through the operator and runs `check-approval`.
      The result must be GO, with `workflow_dispatch` evidence naming the correct run id. `start`, `follow` and any
      deploy are forbidden; the test approval is never used to release code.
    - Withdraw immediately after the GO. The owner runs the steps that need the administrator's password. Then the
      withdrawal is verified, `check-approval` is run again and must be DENIED, and the production posture is checked
      for no change. If the withdrawal fails, stop and report the still-active approval to the owner.
    - Negative tests: no live CI-PERF-04 evaluation run; the "evaluation run is refused" path rests on the existing
      repository tests. The mandatory production negative is `check-approval` after the withdrawal: DENIED.
    - Acceptance:
      1. the dispatch succeeded on the correct target SHA;
      2. the critical regression tests ran;
      3. the guard accepted the dispatch evidence;
      4. `check-approval` GO;
      5. no deploy;
      6. the test approval withdrawn;
      7. the check after the withdrawal DENIED;
      8. production HEAD unchanged;
      9. deploy locks idle;
      10. production drift NONE;
      11. services and timers unchanged;
      12. no BK-2 run or trigger;
      13. every piece of evidence recorded for CG-22.
    - Not authorised: a new deploy, rollback, creating or changing a GO tag, push or schedule trigger removal,
      disabling GitHub workflows, application code changes, database or production configuration changes, BK-2,
      restoring the DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest, removing worktrees without a separate approval. After B4,
      a report and a stop before phase B5.
    - **Status when this record was written: NOT STARTED** (no dispatch, no test approval).
    - Outcome (2026-11-21): **COMPLETED**, acceptance 13 of 13; see "Phase B4 result".
14. 2026-11-21 — **Phase B4 closure record**: after the owner withdrew the test approval, the assistant re-runs
    `check-approval` (must be DENIED), verifies production HEAD `f31b554b…`, drift NONE, every lock idle and no
    configuration or database change, keeps the base freeze until that verification ends, reports, and prepares one
    docs-only pull request recording phase B4 (CG-22); merging it needs a separate owner approval. A phase B5 plan
    (removing the automatic `push` and `schedule` triggers) is prepared but not implemented: B5 needs its own owner
    decision. Not authorised: deploy, rollback, GO tag changes, workflow changes, BK-2.
15. 2026-11-21 — **Phase B5 design: APPROVED as design and planning only, not as implementation.**
    - **B5-1 option A**: remove the automatic `push` and `schedule` triggers from `foundation-evidence-gates.yml`,
      amending rule 107 and CICDCTRL-R004/R012 so that the Full Suite fallback is the manual `workflow_dispatch`.
      The global temporary Full Suite policy stays ACTIVE.
    - **B5-2 option A**: the production authority stays unchanged (`DMS_PRODUCTION_CI_EVENTS="push workflow_dispatch"`).
    - **B5-3 option A**: B5 takes effect when it merges, with no deploy of its own, and rides the next release; the GO
      tag follows that release.
    - **Order**: merge #680, then restore the DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest, then B5.
    - Before any B5 change, CG-16 is re-audited against `CLAUDE.md`, `docs/governance/go-tag-exceptions.md` and both
      manifests (decision 16). No GO tag exception may be made on an assumption.
    - Not authorised: changes to workflows, triggers, CI configuration or B5 tests.
16. 2026-11-21 — **Restore of the DMS-RME-COMPREHENSIVE-CLOSURE-1 manifest: APPROVED, limited scope.**
    - In scope:
      - one worktree and branch cut from the verified base tip (`693becff`);
      - `.sprint/dms-rme-comprehensive-closure-1.yml` moved back to `.sprint/current.yml` with an identical content and
        blob;
      - the phase-B manifest moved to `.sprint/ci-cd-pr-only-policy-1.yml` unchanged;
      - decisions 15 and 16 recorded here (CG-22);
      - DEVFLOW and isolated local tests;
      - commit, push, pull request, CI verification, then a stop for the merge decision.
    - The base freeze stays and PR #672 is not merged.
    - B5: no workflow, trigger, CI configuration or test change. The CG-16 audit is reported, with a recommendation and
      the governance changes it needs, for the next owner decision.
    - Not authorised: merge, deploy, VPS or database changes, rollback, GO tag, BK-2, worktree cleanup.

## Phase B4 result (production verification, 2026-11-21)

Decision 13, through the deploy operator on the verified Biznet host. Times in WITA (UTC+8), UTC in brackets.

| Step | Time | Result |
|---|---|---|
| Base frozen, target recorded | 19:14 (11:14Z) | #679 squash-merged as `ca86734e80846744ea2cdecc6f4aa9c5acb172d2` (tree `1be49c92…`, identical to the reviewed head); post-merge push run 44898175731 green (`docs_only`) |
| Pre-checks | 19:15 | target VERIFIED; `RELEASE GUARD TRUST: READY`; `SUDO_POLICY=LEAST_PRIVILEGE`; posture HEAD `f31b554b…`, tracked 0, locks idle, ERROR 166; `PRIVSEP_DRIFT=NONE`; a default dispatch resolves `evaluation=false`; DEVFLOW GO (`sprint:release-check` NO-GO only on the GO tag collision, expected after phase B was tagged) |
| `workflow_dispatch` | 19:21–20:56 (11:21:01–12:56:30Z) | run **44898680653**, attempt 1, base branch, head `ca86734e…`, `completed`/`success`; classifier `unknown_high_risk` (no event base), `run_critical_tests=true`; `Run critical regression tests` **11804 passed (61107 assertions)**, sequential, 1 worker; `Start resource sampler (CI-PERF-04 evaluation)` skipped; NSF-R011 Critical (hosted), NSF-R012 Quality and CICD-CTRL Classifier succeeded; the self-hosted twin skipped; Full Suite gate skipped (DEFERRED under rule 107, not passed). The newest run of the commit |
| Installed-guard replay | after the run | guard and authority of `f31b554b` against the GitHub API: `PASS: required CI — workflow_dispatch run 44898680653` |
| Owner signature | 23:03:28 (15:03:28Z) | release id `ci-cd-pr-only-policy-1-b4-dispatch-test`, approval `49b95a0323c6effb9477423d86057b13`, target `ca86734e…`, GO tag `ci-cd-pr-only-policy-1-phase-b-go`, valid 2 hours (until 01:03:28 WITA); signed by the owner in the owner's own terminal |
| Bundle verification | 23:05 | bundle SHA-256 `75a34928a7f3b8be8d951c47b58f6d976a8fe15518a6718cd81dd2e3e94bde0e`, record SHA-256 `6c130b6a8d58835d65f613c9f3cb57ed68afc7ba789e909006012362c7c3a9ab`, signature SHA-256 `ed06055e607a9d999136e04d5f2c535e43efcff4b5c545e9798106c855d51aa5`; good owner signature; installed library parses it; 15 of 15 record checks against the authority and the target commit |
| Pre-submit checks | 23:05–23:06 | target VERIFIED; trust READY; sudo policy, posture and drift identical to 19:15; base tip still `ca86734e…`, no queued run |
| `submit-approval` | 23:06:40 | `RELEASE APPROVAL SUBMITTED: 49b95a03…`; record and signature SHA-256 on the host equal the local verification |
| `check-approval` | 23:06:47 | `RELEASE APPROVAL CHECK: GO — read-only: nothing was claimed, recorded or launched`: host and host key, origin, signature against the anchor, only docs after the target, release and approval unspent, GitHub budget 60 of 60, **`PASS: required CI — workflow_dispatch run 44898680653`**, window, contract 3, GO tag `ci-cd-pr-only-policy-1-phase-b-go`. No `start`, no `follow` |
| Withdrawal (owner) | between 23:06:47 and 23:12:11 | the owner, as the administrator, ran `scripts/release-approval-withdraw.sh` on the host: `RELEASE APPROVAL WITHDRAWN`, exit 0 |
| `check-approval` after the withdrawal | 23:12:11 | `DENIED — … has stored approvals but none is active (49b95a03… withdrawn …)`; `DEPLOYMENT=DENIED`; refused before launch, no log, record or process; exit 77 |
| After the test | 23:12–23:16 | posture identical to 19:15 (HEAD `f31b554b…`, tracked 0, deploy, release and rollback locks idle, ERROR 166); `PRIVSEP_DRIFT=NONE`; sudo policy and trust unchanged; no failed unit; `php8.3-fpm` and nginx start times unchanged since the phase-B deploy; timers unchanged; migration files identical to the phase-B deploy (237 files, same name and content digests); last deploy still `20261121-095125`; smoke `/login`, `/health/live`, `/health/ready`, `/health/lb` 200, `/storage/` 403; base tip still `ca86734e…`; GO tag unchanged |

Acceptance of decision 13: 13 of 13 met.

1. The dispatch succeeded on the target SHA.
2. The critical tests ran.
3. The guard accepted the dispatch evidence.
4. `check-approval` was GO.
5. Nothing was deployed.
6. The test approval was withdrawn.
7. The check after the withdrawal was DENIED.
8. Production HEAD is unchanged.
9. The locks are idle.
10. Drift is NONE.
11. Services and timers are unchanged.
12. BK-2 was neither run nor triggered.
13. All of this is recorded here.

The approval `49b95a03…` is spent by its withdrawal; the test approval was never used to release code, and the
release id `ci-cd-pr-only-policy-1-b4-dispatch-test` is never to be reused for a real release. Lifting the base
freeze (PR #672 and later merges) is left to the owner.

## Release and GO tag (phase B)

The one deploy authorised by decision 10, through the deploy operator on the verified Biznet host, 2026-11-21 (WITA):

| Step | Time | Result |
|---|---|---|
| Migrations (owner, own terminal) | before 17:50 | `migrate:status`: 237 Ran, 0 Pending, last `2026_11_18_300001_…`, last batch 95 |
| Pre-checks | 17:50 | target VERIFIED; `RELEASE GUARD TRUST: READY` (one anchor key, the owner's); `SUDO_POLICY=LEAST_PRIVILEGE`; posture: HEAD `988c1a9a`, tracked 0, locks idle, ERROR 166; `PRIVSEP_DRIFT=NONE`; only this file follows the target; installed-guard CI replay PASS; approval valid |
| `submit-approval` | 17:50:46 | `RELEASE APPROVAL SUBMITTED: afda8f3fd490be6206e93b82d3f71c2f`; record and signature SHA-256 on the host equal the local verification |
| `check-approval` | 17:50:58 | `RELEASE APPROVAL CHECK: GO` — host and host key, origin, signature against the anchor, only docs after the target, release and approval id unspent, GitHub budget, `PASS: required CI — push run 44862172974`, window, contract 3, GO tag `ci-cd-pr-only-policy-1-phase-b-go`; no DENY or BUSY |
| `start` | 17:51:20 | deploy id `20261121-095125` (one start; the runner on the VPS) |
| `follow` / `verify 20261121-095125` | 17:51–17:53 | verified pre-deploy dump (fail-fast), `Nothing to migrate`, governance gates GO, `final exit=0`; `DEPLOY RUNNER OK`, `DEPLOY_APPROVED_TARGET_MATCH=YES`, contract 3, approval `afda8f3f…`, record SHA-256 `e445d9bc…`, CI run 44862172974 |
| Smoke (canonical URL) | 17:53 | `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; `/storage/` 403 |
| After the deploy | 17:54 | HEAD `f31b554b…` (the only posture change), tracked 0, locks idle, ERROR lines 166 (baseline), `PRIVSEP_DRIFT=NONE`, sudo policy unchanged, trust READY, no failed unit, `php8.3-fpm` restarted by the deploy, nginx not restarted, timers unchanged, queue worker unchanged (inactive, disabled), migration files identical to O1 |
| GO tag (decision 11) | 18:10 | `ci-cd-pr-only-policy-1-phase-b-go`, annotated (object `6c391e1cc7b8fdf33a33ba37bfd941e7d52ab367`), target `f31b554b…`, pushed alone without force; the remote ref peels to the target; base tip and production HEAD unchanged |

Acceptance of decision 10: 13 of 13 met. The release id `ci-cd-pr-only-policy-1-phase-b` and approval
`afda8f3f…` are spent: a redeploy needs a new release id and a new signature. The tag was created from a checkout at
the approved commit following runbook §6b step 8 and the tagging rules of `scripts/sprint-release.sh`; the wrapper
itself was not run, because its tagging path also runs a deploy from the local checkout (CG-15). The host's
authority now lists `DMS_PRODUCTION_CI_EVENTS="push workflow_dispatch"` (HEAD is the target, tracked
modifications 0).

## Phase B4 readiness (read-only audit, 2026-11-21)

Phase B4 proves in production that the guard accepts dispatch evidence (runbook §6g step 0). Findings:

- **Dispatch is possible**: the repository's default branch is the base branch, and the base has 15 earlier
  `workflow_dispatch` runs of this workflow.
- **A dispatch runs the critical tests**: a dispatch has no event base, so the classifier resolves `unknown_high_risk`.
  Run 43968947617 (dispatch, `c705f048`) shows `base_sha=none`, `run_critical_tests=true`, the critical step run and
  the CI-PERF-04 evaluation step skipped.
- **The installed guard accepts real dispatch evidence**: the guard and authority of `f31b554b`, replayed against
  the GitHub API for `c705f048`, print `PASS: required CI — workflow_dispatch run 43968947617`; the previous push-only
  guard denies the same commit, whose push run was docs-only.
- **A docs-only tip needs the dispatch**: for the current tip, whose only run is a `docs_only` push run, the installed
  guard denies ("took the step Note skipped critical regression tests"). The B4 dispatch must therefore run on the
  tip it proves, and be its newest accepted run.
- **Target and GO tag**: a dispatch runs on the branch tip, so the B4 test approval targets that tip. Production HEAD
  `f31b554b…` is its ancestor, and its `.sprint/current.yml` still declares `ci-cd-pr-only-policy-1-phase-b-go`.
  The guard binds the record to the manifest's `go_tag` and does not check whether the tag exists. This is the rule
  236 precedent (`dms-release-approval-direct-1-c3-test`: signed, submitted, checked GO, withdrawn). The signer is
  unchanged after the target.
- **No historical CI-PERF-04 evaluation dispatch exists** on the base; the "evaluation run is refused" path is
  covered by `DeployHardenDirectApprovalTest`.
- **Base freeze**: no merge to the base from the dispatch until the B4 `check-approval`. A new push would move the tip
  and cancel the running dispatch (shared concurrency group).
- **Rollback after PR-only CI (phase B5)**: the rollback floor is this release. An older target reinstalls the
  push-only guard, which no later contract-3 release could pass without push runs. Recovery would be contract 2 or a
  reviewed PR that restores the `push` trigger. `scripts/rollback-vps.sh` needs neither an approval nor CI.

## Release readiness (phase B3, 2026-11-21)

Read-only evidence gathered before any signature. Nothing on the host was changed (posture identical before and after
every check).

| Check | Result |
|---|---|
| Post-merge CI of the target | push run 44862172974, attempt 1, success; critical regression tests ran: 11804 passed (61107 assertions); not a CI-PERF-04 evaluation run; Full Suite DEFERRED (CG-18), not passed |
| Installed guard vs this run | the guard library and authority at `988c1a9a` (the running release; tracked modifications 0; push only), taken from git and replayed locally against the GitHub API: `PASS: required CI — push run 44862172974`; the phase-B guard: same |
| GO tag binding | `.sprint/current.yml` of the target declares `ci-cd-pr-only-policy-1-phase-b-go` (guard and signer pipeline); tag absent locally and on the remote |
| Production identity and trust | target verification VERIFIED (administrator and operator aliases); `RELEASE GUARD TRUST: READY`, approval contracts `2 3`, one anchor key (the owner's); `SUDO_POLICY=LEAST_PRIVILEGE` |
| Production state | HEAD `988c1a9a`, tracked modifications 0, deploy/release/rollback locks idle, `PRIVSEP_DRIFT=NONE`, application log ERROR lines at the 166 baseline; last deploy `20261118-114249` `DEPLOY RUNNER OK` |
| Migrations | host `database/migrations`: 237 files, names and contents identical to `988c1a9a` and `f31b554b`; owner's `migrate:status`: 237 Ran, 0 Pending (decision 5); the release adds none |
| Backups | database backup and BK-2 package timers active, last run successful; BK-2 not invoked |
| Health | `/login`, `/health/live`, `/health/ready`, `/health/lb` return 200 on the canonical URL |
| DEVFLOW | `devflow:base-ref-check`, `sprint:manifest-check`, `sprint:scope-audit --strict` (release delta), `sprint:release-check --strict` (CI evidence: run 44862172974), `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict`: GO |
| Signer prerequisites (P-4 preparation; not an authorisation) | signing worktree detached at the target, clean, signer/library/authority/manifest blobs identical to the target; the owner's public key fingerprint equals the production trust anchor |

### Signed approval (P-4 result)

The owner ran the signer in the owner's own terminal (decision 9). The approval was verified read-only on 2026-11-21
between 09:11 and 09:16 UTC; no private key, passphrase or full bundle was read into any report.

| Item | Value / result |
|---|---|
| Approval id | `afda8f3fd490be6206e93b82d3f71c2f` |
| Record SHA-256 | `e445d9bcc0347326fd427b9b8a7ce6bc542ebcd6d28b14362f58827c1ff406f0` |
| Bundle SHA-256 | `4f876b01f4cbf0aebe341fba1f474e4d86d500ee853af730427f16df9dd27494` |
| Window | `2026-11-21T09:10:10Z` → `2026-11-22T09:10:10Z` (24 h; until 2026-11-22 17:10:10 WITA) |
| Split and parse | canonical with the library of the target and with the installed library (`988c1a9a`, used by the host for `submit-approval`) |
| Signature | good for the owner's public key in namespace `dms-release-approval`; that key is the single production trust anchor key |
| Record vs installed guard | release id, approval id, contract 3, scope, target, repository, host, pinned host key, base branch, tree, GO tag (= the target's manifest), approver `owner`, 24 h window: all match |
| Bundle integrity | SHA-256 and modification time identical at the start and end of the verification |
| Spend state of the release id on the host | provable only by `check-approval` (P-5); read-only evidence shows no earlier use |

### Effect of a docs-only record on the release and on phase B4

Audited before this record was committed (decision 8):

- While the pull request is open the base branch does not move. Its CI runs as `pull_request` on its own concurrency
  group and branch, so it neither cancels base runs nor counts for the guard (branch and commit differ).
- After a merge, the release target stays `f31b554b…`. Guard step 9 accepts it, because only `docs/**/*.md` follows
  the target (the installed guard's own rule, replayed on this file's path). Step 12 asks for runs of exactly
  `f31b554b…`, so the docs commit's own push run, classified `docs_only`, which skips the critical tests, is never
  read for this release. The signer accepts any target that is an ancestor of `origin/<base>`. The deploy
  fast-forwards to the approved SHA and refuses any other final HEAD (`scripts/deploy-vps.sh`), so production gets
  `f31b554b…`, not the docs tip.
- Phase B4: a dispatch always runs on the branch tip, so after a merge the dispatch proof (runbook §6g step 0) runs on
  the docs tip, not on `f31b554b…`. A dispatch has no event base, so the classifier resolves the strongest profile
  (`unknown_high_risk`) and the critical tests run (verified locally with `scripts/ci/resolve-gates.sh`). The dispatch
  is newer than the docs commit's push run and is therefore the run the guard reads. The B4 test approval names that
  tip as its target. Production HEAD (`f31b554b…` once phase B is deployed) is its ancestor, and its
  `.sprint/current.yml` still declares `ci-cd-pr-only-policy-1-phase-b-go`. B4 proves that dispatch evidence is
  accepted; it never deploys, and the release target does not change.

## Manifest switch (OD-B3-1)

### Why the release needs it

Code binds the GO tag of a release to the release commit's own `.sprint/current.yml` (read from the git object, never
from a working tree):

| Consumer (at `452011a8`) | Effect of a mismatch |
|---|---|
| `scripts/release-approval-sign.sh` l.123–125 | refuses to sign `--go-tag` ≠ the commit's `go_tag` |
| `scripts/lib/release-approval.sh` contract 2 l.517–520, contract 3 l.1180–1183 (same check in the installed guard) | denies the record |
| `scripts/sprint-release.sh` l.150–154 | refuses to create any other tag |
| CG-16, `docs/governance/go-tag-exceptions.md` | the GO tag is the `go_tag` in `.sprint/current.yml` |

At `452011a8` that file declares `dms-rme-comprehensive-closure-1-go`, which may not be created before that sprint
closes (`docs/sprints/dms-rme-comprehensive-closure-1.md` §9.7). The multi-sprint exception does not apply: the release
carries one sprint's runtime change, an infrastructure release is not exempt for being infrastructure, and the
exception would supersede the other sprint's tag.

### What this change does

- `.sprint/current.yml` is the phase-B manifest (moved from `.sprint/ci-cd-pr-only-policy-1.yml`): header rewritten,
  impact flags and `go_tag` unchanged, `inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1` added (AIR-FND-29).
- `.sprint/dms-rme-comprehensive-closure-1.yml` is the former `.sprint/current.yml`, byte-identical (blob `d1c4af5e`),
  its `go_tag` unchanged. That sprint stays OPEN; its record and status are not touched. `sprint:new` writes named
  manifests to `.sprint/<id>.yml`, so the parked file follows the existing convention.
- `DevflowSprintToolingTest`: every manifest in `.sprint/` (the example excepted) validates; the GO tag the release
  guard reads with its own `sed` pipeline equals the YAML `go_tag`; no two manifests declare one tag.

Not changed: the guard, the authority, the signer, the runner, the workflows (`push` and `schedule` stay), application
code, other sprints' records, tags.

### Consumers of `.sprint/current.yml`

| Consumer | After the switch |
|---|---|
| `config/devflow.php` `manifest.default_path`, `ResolvesSprintContext` | every `sprint:*` command without `--manifest` reads phase B; the parked manifest needs `--manifest=.sprint/dms-rme-comprehensive-closure-1.yml` |
| `scripts/ci/foundation-evidence-gates.sh` l.164 (NSF-9) | `sprint:manifest-check --no-diff-check` on phase B: GO (local) |
| guard, signer, `scripts/sprint-release.sh` | bind `ci-cd-pr-only-policy-1-phase-b-go` on the merge commit of this change |
| `scripts/ci/resolve-gates.sh` | `.sprint/*.yml` matches no pattern → `unknown_high_risk`: the merge commit's push run runs the critical tests |
| `DevflowSprintToolingTest` l.222/227, `DevflowAiInstructionSystemTest` l.251 | pass; the phase-B tag is no superseded tag |
| `LegacyRmeProgramClosureContractTest` l.146 | needs `LEGACY-RME-PROGRAM-CLOSURE-1`: carried forward |
| `tests/Pest.php` l.2569 and the `DeployHarden*` tests | build their own fixture repositories; unaffected |

`docs/governance/global-temporary-full-suite-policy.md` §6.0 keeps a docs- or rules-only change off the manifest so it
stays `docs_only`. This change is not one: it fixes the release identity of a runtime sprint, and the guard needs a
push run of the release commit in which the critical tests ran (its forbidden step is the docs-only skip). The Full
Suite stays deferred on that push run (policy ACTIVE).

### Release target and base freeze

- The release target is the squash-merge commit of this change, not `452011a8`. The owner signs
  `--target-sha <merge commit> --go-tag ci-cd-pr-only-policy-1-phase-b-go` only after that commit's push run is green
  with the critical tests run and the CI-PERF-04 evaluation step skipped.
- From that merge until the deploy is verified and the dispatch proof (runbook §6g step 0) is recorded, only
  `docs/**/*.md` and `deploy/release-approvals/*` may land on the base: the guard refuses a target followed by anything
  else (step 9), and a dispatch runs on the branch tip. Open PR #672 touches `.claude`, `.cursor`, `CLAUDE.md` and
  `tests`, so it waits.

### Restoring the parked manifest

- When: after the phase-B release is deployed, verified and GO-tagged and its dispatch proof is recorded, and before
  any further DMS-RME-COMPREHENSIVE-CLOSURE-1 merge or release. Owner decision.
- How: one PR moves `.sprint/dms-rme-comprehensive-closure-1.yml` back to `.sprint/current.yml` (prove the blob is
  unchanged) and parks this manifest at `.sprint/ci-cd-pr-only-policy-1.yml`.
- Effect: a non-docs change with its own push run; the next DMS-RME-COMPREHENSIVE-CLOSURE-1 release targets a commit at
  or after it, and its record names `dms-rme-comprehensive-closure-1-go` as releases 1–4 did. The phase-B GO tag stays
  bound to its own commit. A restore before the phase-B deploy would make the release target undeployable (step 9).
- If it is forgotten, the signer refuses that sprint's tag (fail closed); nothing passes silently.
- **Restored (decision 16, 2026-11-21)** by the pull request that records decision 16. The pull request is cut from
  `693becff` and contains two `git mv` operations with no content change:

  | Path after the restore | Blob | `go_tag` |
  |---|---|---|
  | `.sprint/current.yml` | `d1c4af5e`, the same blob as `.sprint/current.yml` at `452011a8` (before the switch) | `dms-rme-comprehensive-closure-1-go` |
  | `.sprint/ci-cd-pr-only-policy-1.yml` | `d3e8e039`, the phase-B manifest | `ci-cd-pr-only-policy-1-phase-b-go` (created on `f31b554b`) |

  `sprint:*` commands without `--manifest` read DMS-RME-COMPREHENSIVE-CLOSURE-1 again. Work on this sprint passes
  `--manifest=.sprint/ci-cd-pr-only-policy-1.yml`. No deploy is needed: the host reads `.sprint/current.yml` only
  from a release commit's git object, at release time.

## Release guard findings (before this change)

Every place that assumed a push run:

| Where | Assumption |
|---|---|
| `scripts/lib/release-approval.sh` `dms_release_approval_ci_verify` | query `actions/runs?head_sha=…&event=push&branch=…` |
| same, evaluator `runs` mode | `r.get("event") == "push"`; deny text "no push run of …" |
| `deploy/production-host.conf` | "the latest push run of this workflow" |
| rule 236 §2.3, §4; runbook §6g step 1 | "post-merge push CI", "push run" |
| `DeployHardenDirectApprovalTest` | fixtures `event => push`; the base workflow keeps `push: branches: [base]` |

Kept unchanged on purpose: exact SHA, branch, workflow path, repository and fork checks; latest-run rule;
`filter=latest` jobs; required jobs; required and forbidden steps; rate-limit budget (2 requests, reserve); approval
signature, window, scope, spent release and approval ids; contract-2 path (never asks GitHub).

## Design

- `DMS_PRODUCTION_CI_EVENTS="push workflow_dispatch"` (authority) lists the run events that count. Strict parse:
  only `push` / `workflow_dispatch`, single spaces, no duplicate. **Absent = push only**, so an older authority and
  the host's installed guard behave exactly as before. One event keeps GitHub's event filter (push: the original URL,
  byte for byte); two events drop it and the evaluator filters every run.
- **Exact SHA binding for dispatch.** GitHub's dispatch API takes a branch or tag name, never a commit, and runs on
  that ref's tip; the run's `head_sha` is the tip at dispatch time. The guard keeps `head_sha == --approved-sha`, so a
  dispatch after the branch moved on proves the newer commit and is not evidence for the release. The base branch
  membership of the commit is still proven by the guard's own git ancestry checks.
- **Latest accepted run decides** across both events (a newer failed/running push or dispatch denies).
- **No evaluation runs.** `DMS_PRODUCTION_CI_EVALUATION_STEP="NSF-R011 Critical Test Gate:Start resource sampler
  (CI-PERF-04 evaluation)"`: a job that took this step (present, not skipped) denies — rule 212 §3. Mandatory whenever
  `workflow_dispatch` is listed; its job must be a required job; pinned in `config/deployment_entrypoint.php`, and a
  test proves the step exists in both critical-gate variants and runs only on a labelled evaluation.
- Dispatch inputs are not visible in the run API, so the guard judges the run by its jobs and steps: `runner_mode`
  runs an equivalent gate (CICD-CTRL-3); a dispatch has no event base, so the classifier resolves the strongest
  profile and the critical tests always run; the Full Suite inputs only add work.

## Changed files

| File | Change |
|---|---|
| `scripts/lib/release-approval.sh` | `dms_release_approval_ci_events`; `ci_verify` args 8–9 (events, evaluation step); evaluator accepts listed events, reports the run's event, refuses an evaluation step |
| `deploy/production-host.conf` | `DMS_PRODUCTION_CI_EVENTS`, `DMS_PRODUCTION_CI_EVALUATION_STEP` |
| `config/deployment_entrypoint.php` | both keys required and pinned; five new guard markers |
| `tests/Feature/Deploy/DeployHardenDirectApprovalTest.php` | 10 new tests, 71 cases; helper defaults keep every existing case on the legacy (push-only) authority |
| `.cursor/rules/236-direct-signed-release-approval.mdc` | §2.3 wording, new §5 |
| `docs/runbooks/production-host-identity.md` | §6g: dispatch evidence procedure, activation and production verification |
| `.claude/rules/deploy-infra.md` | rule 236 index line |
| `.sprint/ci-cd-pr-only-policy-1.yml`, this record | DEVFLOW manifest (moved to `.sprint/current.yml` by OD-B3-1) and sprint record |

Not changed: `.github/workflows/*` (push and schedule triggers stay), application code, migrations, permissions,
flags, the runner, the immutable bootstrap, the evidence format (`approval_ci_run_id` already names the run; its
event is in the guard's PASS line in the deploy log).

## Tests (local, 2026-11-21, isolated worktree, no network, stubbed `curl`)

| Scope | Result |
|---|---|
| `tests/Feature/Deploy` + `tests/Feature/Cicd` + `DevflowAiInstructionSystemTest` + `DevflowSprintToolingTest` (one process) | 1443 tests, 10095 assertions, 0 failed, 0 skipped, exit 0 — **WARN**: every test carries the known workstation warning (the environment file is absent in a worktree; 1443/1443 identical messages, none other) |
| `DeployHardenDirectApprovalTest` | 258 cases (187 existing unchanged + 71 new), 0 failed |
| Mutation check (guard weakened, then restored byte-identical) | dropping the SHA match → 3 failed; accepting any event → 7 failed; disabling the evaluation check → 4 failed; dropping the evaluation-step requirement → 3 failed |
| `bash -n` guard; embedded Python parsed; `php -l` changed PHP | PASS |
| ShellCheck | **NOT RUN** (not installed on the workstation) |
| Full Suite | **DEFERRED** (CG-18), not passed; `sprint:test-plan` escalation recorded as deferred |

The PR's own CI run is the authoritative gate result; it is recorded in the PR.

Manifest switch (OD-B3-1), local, 2026-11-21, isolated worktree at the base `452011a8`:

| Scope | Result |
|---|---|
| `tests/Feature/Foundation` + `tests/Feature/Deploy` + `tests/Feature/Cicd` + `LegacyRmeProgramClosureContractTest` (one process) | 1941 tests, 12845 assertions, 0 failed, 0 skipped, exit 0 — **WARN**: the same workstation warning on every test (the environment file is absent in a worktree), none other |
| New manifest test, mutation check (temporary extra manifest, then removed) | quoted `go_tag`, inline comment, duplicate tag, base `main` → each fails; a valid distinct manifest passes |
| `sprint:manifest-check` (with diff), `sprint:scope-audit --strict`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict` | GO / GO / GO / 0 errors |
| Classifier on the change set | `unknown_high_risk`, `run_critical_tests=true` |
| GO tag the guard pipeline reads | `452011a8` → `dms-rme-comprehensive-closure-1-go`; this change → `ci-cd-pr-only-policy-1-phase-b-go`; parked manifest → `dms-rme-comprehensive-closure-1-go` (blob unchanged) |
| Pint (changed test) | passed |
| Full Suite | **DEFERRED** (CG-18); `sprint:test-plan` escalates (5 unmatched files) — recorded as deferred, not passed |

An earlier run of the same set with `APP_ENV=local` exported failed 5 tests that assert production-like behaviour;
unsetting it (the setting CI uses) passes them. Environmental, not caused by this change.

## Activation and production verification (after a separately authorised release)

1. Merge (owner authorization). Done for the code (#673 → `452011a8`, push run 44823046028 green). The manifest switch
   merges under its own owner approval; the post-merge **push** run of that merge commit must be green: the guard
   installed on the host today accepts push runs only and verifies this release.
2. Release per runbook §6g with the owner's signed approval; deploy; `verify <deploy-id>` → `DEPLOY RUNNER OK`.
   Decided by OD-B3-1: the target is the manifest-switch merge commit, signed with
   `--go-tag ci-cd-pr-only-policy-1-phase-b-go` ("Manifest switch" above).
3. Production verification: the first release whose newest accepted run is a dispatch → `check-approval` GO with
   `PASS: required CI — workflow_dispatch run <id>`; record the run id here. Until then: DEPLOYED ≠ VERIFIED.
   **Done 2026-11-21 (phase B4):** run 44898680653 on `ca86734e…`, `check-approval` GO with a withdrawn test approval
   (no deploy); see "Phase B4 result".

## Future removal of the automatic push trigger (not in this phase)

Step 3 above was met on 2026-11-21 (phase B4). The removal still needs its own owner decision (phase B5).

Only after step 3 above, and by a new owner decision: a PR removes `push` (and `schedule`) from
`foundation-evidence-gates.yml`, updates `DeployHardenDirectApprovalTest`'s trigger assertion and rule 107. From then
on every release is dispatched on the base branch while its tip is the release commit (runbook §6g step 1).

Rollback once the push trigger is gone:

- `scripts/rollback-vps.sh` asks neither an approval nor GitHub, so a code rollback stays possible (owner break-glass,
  full SHA, an ancestor of HEAD, pre-rollback dump, never a data restore).
- Rollback floor: the phase-B release. An older target reinstalls an authority without `DMS_PRODUCTION_CI_EVENTS`,
  whose guard counts push runs only; with no push runs, no later contract-3 release passes step 12.
- Recovery if that happens: contract 2 (approval through a merged PR) never asks GitHub for CI
  (`scripts/lib/release-approval.sh` l.409–429: step 12 is contract 3 only), so a forward fix can still be released;
  or a reviewed PR re-adds the `push` trigger, and its own merge commit gets the push run the older guard accepts.
  Either path needs an owner decision.
- The PR that removes the triggers records the floor in rule 236 §5 and re-checks that a dispatch-capable release is
  installed and GO-tagged.

## Rollback

- Before deploy: revert the squash commit through a PR. Nothing on the host changed. Reverting the manifest switch
  alone restores `.sprint/current.yml` to DMS-RME-COMPREHENSIVE-CLOSURE-1 and leaves phase B untaggable again.
- After deploy: roll back by full SHA (`scripts/rollback-vps.sh <sha>`, rule 231) to the previous release; its guard
  and authority accept push runs only, which every release still has while the `push` trigger exists. No data step.
- Narrow alternative (a reviewed release): set `DMS_PRODUCTION_CI_EVENTS="push"` in the authority; the evaluation
  step stays harmless.

## Residual risks

- Anyone with write access can dispatch the workflow. A dispatch is evidence only; it can deny (a newer failing run)
  but never approve. Actor allowlisting was not added (it would publish account names in a public repository).
- A dispatch on a tag named exactly like the base branch would report that name as `head_branch`. Its `head_sha` must
  still be the release commit, so it tests exactly the release's code and workflow; the evidence quality is the same.
- The run list is read from GitHub's first page (50 newest runs of the commit on the branch). If none of those is an
  accepted run the guard denies (fail closed); it never reads an older page.
- A dispatch and a push run on the base branch share one concurrency group (`cancel-in-progress`): one can cancel
  the other. The newest decides, so a cancelled newest run must be re-run.
