# DMS-RELEASE-APPROVAL-DIRECT-1 — direct signed release approval (contract 3)

**Date:** 2026-11-16 (WITA). **Type:** security / release engineering (Foundation-First Sprint Lock: allowed category).
**Status:** MERGED (#643 → `312337b4`) — owner-approved for production under contract 2; signature, approval PR, deploy,
operator re-provisioning and the contract-3 production test pending (§10, §11).

## 1. Owner request (CG-22)

Remove the approval-only branch, worktree, commit, PR, Critical Gate and merge that every release needs today, while
keeping every guarantee: the owner's written approval and cryptographic signature, exact SHA and tree binding,
repository and host identity, the trusted public-key anchor, expiry, single use, required CI on the exact target SHA,
the deploy trust-check, the pre-deploy database dump, read-only smoke, owner-authorized rollback, GO only after
production verification, and audit evidence. The migration release itself uses the existing contract 2 workflow; the
new contract activates only after that release is production-verified. BK-2 is never run.

## 2. Discovery: why contract 2 needs a Git commit

`scripts/lib/release-approval.sh` (`dms_release_approval_verify_inner`), run by `deploy-vps-runner.sh` before launch
and again by `deploy-immutable-exec.sh` inside the release lock:

| Step | Needs Git | Security purpose | Contract 3 |
|---|---|---|---|
| Fetch the base branch into a private ref | yes | read the record from an object root controls | kept (still needed for 9/10) |
| Record + `.sig` read from the fetched tip | **yes** | no writable local file can stand in for the approval | replaced: record + sig from the root-only approval store, submitted through the operator entrypoint and verified there |
| Contract, repository, host, host key, branch, SHA, tree, go_tag fields | no | binding | kept, + `DMS_APPROVAL_ID`, `DMS_APPROVAL_SCOPE` |
| SSH signature against the host trust anchor, principal, namespace | no | authentication | kept |
| Window ≤ 72 h, not future-dated, not expired (host clock) | no | freshness | kept |
| Evidence doc on the branch quoting the SHA | **yes** | written owner approval reachable from the branch | replaced: the signed record IS the written approval; the closure doc records its digests afterwards |
| SHA merged on the branch; only approvals/docs after it | partly | the approval describes the branch | kept (no approval commit needed for it) |
| Forward-only (HEAD and local branch ancestors of the target) | no | no silent downgrade | kept |
| Release spent once (spend record, completed runner records) | no | replay | kept, + approval ID spent once |
| Required CI | **implicitly** (approval PR's own CI) | the deployed commit passed CI | **explicit**: the host queries GitHub for the push run of the pinned workflow on the exact SHA |

Runner evidence (`scripts/lib/deploy-runner-evidence.sh`, contract 4) binds `approval_blob` /
`approval_signature_blob` to git objects; contract 3 evidence binds the store files by SHA-256 instead.

## 3. Threat model (attacker → asset)

| # | Attacker / capability | Threat | Control |
|---|---|---|---|
| A1 | Holder of the operator key | submit a forged or altered approval | only a signature by an anchor principal verifies; the operator never writes the store directly (root writes it after verification) |
| A2 | Holder of the operator key | replay an old valid approval (after a rollback) | release id spent once; approval ID spent once; window ≤ 72 h on host time |
| A3 | Holder of the operator key | replace a pending approval with another (signed) one | one active approval per release id: a second unspent, unexpired approval for the same release is refused; nothing is overwritten |
| A4 | Malicious bundle | path traversal, symlink/hardlink, oversized or binary input, ambiguous parsing | stdin only (no paths), ≤ 8 KiB, strict canonical format (fixed key order, ASCII, LF), root writes with `set -C` into a root-only directory it created, atomic rename |
| A5 | Compromised runtime user | tamper with stored approvals | store under `/var/lib/daengtisiams-deploy/approvals` (root 0700, outside the checkout) |
| A6 | Someone with base-branch write | deploy a commit whose CI failed / never ran | host verifies the push run of `.github/workflows/foundation-evidence-gates.yml` for exactly the target SHA, repository and branch, `conclusion=success`, required jobs succeeded; network or parse failure = DENIED |
| A7 | Clock games | future-dated or expired approval | host time; skew allowance unchanged |
| A8 | Root on the host | anything | inside the trust boundary (unchanged; CG-07) |

## 4. Contract 3

**Canonical record** — exactly these 14 lines, in this order, `KEY=VALUE`, printable ASCII, LF endings, final LF,
nothing else (no comments, no blank lines), ≤ 4096 bytes:

```
DMS_APPROVAL_CONTRACT=3
DMS_APPROVAL_ID=<32 lowercase hex, random per approval>
DMS_APPROVAL_RELEASE_ID=<release id>
DMS_APPROVAL_SCOPE=production-deploy
DMS_APPROVAL_REPOSITORY=<owner/repo>
DMS_APPROVAL_HOST=<hostname>
DMS_APPROVAL_HOST_KEY_SHA256=SHA256:<43>
DMS_APPROVAL_BASE_BRANCH=<branch>
DMS_APPROVAL_TARGET_SHA=<40 hex>
DMS_APPROVAL_TARGET_TREE=<40 hex>
DMS_APPROVAL_GO_TAG=<…-go>
DMS_APPROVAL_APPROVED_BY=<principal>
DMS_APPROVAL_APPROVED_AT=<YYYY-MM-DDTHH:MM:SSZ>
DMS_APPROVAL_NOT_AFTER=<YYYY-MM-DDTHH:MM:SSZ>
```

**Signature** — `ssh-keygen -Y sign -n dms-release-approval` over the record bytes (unchanged namespace).

**Bundle** (transport) — the record bytes followed immediately by the armored signature
(`-----BEGIN SSH SIGNATURE-----` … `-----END SSH SIGNATURE-----` + LF). Split at the BEGIN line; nothing before the
record, nothing after the END line.

**Submission** — `ssh daengtisiams-biznet-deploy submit-approval --release-id <id> < bundle` → operator entrypoint →
sudo (new regex) → `scripts/release-approval-submit.sh` (root): read stdin (≤ 8 KiB), validate canonically, verify
signature (anchor, principal, namespace), host, host key, repository, branch, scope, window (not expired now),
release id matches argument, release unspent, approval ID unspent, no other active approval for the release; then
store atomically: `approvals/<release-id>/<approval-id>.approval`, `.sig`, `.receipt` (root 0400; receipt = host,
verified_at host time, signer key, record sha256, sig sha256, submitted_by = SUDO_USER). Prints
`RELEASE APPROVAL SUBMITTED: <approval-id>`. Never launches anything.

**Deploy** — unchanged command (`start --release-id <id> --approved-sha <sha>`). The guard looks in the store first:
exactly one stored, unexpired, unspent approval for the release whose TARGET_SHA = `--approved-sha` → contract 3
verification (all of §2's kept steps + CI); no stored approval → contract 2 (git) path unchanged; a stored approval
that does not match → DENIED (never falls back). The bootstrap re-verifies inside the lock. The runner record gains
`approval_contract`, `approval_id`, `approval_record_sha256`, `approval_signature_sha256`, `approval_store_path`;
`verify` checks them against the store. The spend step also writes `spent/approval-<approval-id>.spent`.

**Owner signing helper** (`scripts/release-approval-sign.sh`, owner-run on the workstation, no git commit, no push, no
deploy): takes release id, target SHA, go tag, window hours (≤ 72); reads tree/branch/repository/host/host key from
the local repository and `deploy/production-host.conf`; generates the approval ID; shows the record and the window in
WITA; asks for YES; signs with the owner's key (file form, passphrase on the terminal); verifies; writes the bundle to
`~/DaengtisiaMS-release-approvals/<release-id>/<approval-id>.bundle` (0600) and prints its SHA-256.

**Activation boundary** — contract 3 is accepted only when `DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS` in the host
authority lists `3` (this release adds the key with `2 3`); contract 2 stays accepted (historical records stay
verifiable; old signed records are never edited).

**Operator interface change** — `submit-approval --release-id <id>` joins the entrypoint allowlist and
`dms_sudo_operator_expressions` (`^submit-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+$`). The installed
entrypoint and the sudoers drop-in change only by re-provisioning (`provision-deploy-identities.sh --operator`),
which since rule 235 is an administrator step needing the owner's password: after the DIRECT-1 deploy the owner runs
it once; `sudo-policy-check` reports the entrypoint `STALE` until then.

## 5. Implementation (files)

On `fix/dms-release-approval-direct-1`, rebased onto `ccbc313b` (after Phase 2's release; r3's
`dms_operator_link_inside` kept in the entrypoint, no conflict); every observed value (app dir, branch, host, host key, clock,
deploy uid, store, release lock) is a parameter, so all of it is tested without root, a server or GitHub.

| File | Change |
|---|---|
| `scripts/lib/release-approval.sh` | Contract 3 beside contract 2. `dms_release_approval_verify_inner` gains step 2b (`dms_release_approval_contracts`, `dms_release_approval_direct_select`: the store first; one active stored approval for the requested SHA → contract 3; no stored approval → contract 2; anything else DENIED, never a fallback), steps 5–8 dispatch to `dms_release_approval_direct_record` (contract 3) or `dms_release_approval_git_record` (contract 2 steps 5–8, moved verbatim), step 11 also checks the approval id (`dms_release_approval_id_state`) and step 12 the required CI (`dms_release_approval_ci_verify`, `dms_release_approval_github_get`, `dms_release_approval_ci_program`: the latest push run, the required jobs, and the required step `Run critical regression tests` actually run with success in the critical job — a docs-only run that took `Note skipped critical regression tests (docs-only change)` is DENIED). Pure parts: `dms_release_approval_parse_direct` (canonical 14-line record), `dms_release_approval_render_direct`, `dms_release_approval_split_bundle`, `dms_release_approval_window`, `dms_release_approval_signature_file`, `dms_release_approval_store_file`, `dms_release_approval_store_scan` (enumerates with `find`, never pathname expansion, so `set -f` cannot make a store look empty; states active / expired / spent / withdrawn). Root's submission: `dms_release_approval_submit` (+ `_inner`, `_store`): refused while the release lock is held (and holds it while storing), under `flock` on the release directory clears only an interrupted writer's leftovers (`dms_release_approval_store_clean_stale`: `.<id>.<ext>.tmp`, and the `.sig`/`.receipt` of a set whose record was never renamed into place). Administrator's revocation: `dms_release_approval_withdraw`. `dms_release_approval_record_spent` also writes `spent/approval-<id>.spent`. The runner-record scans of `dms_release_approval_spent` and `dms_release_approval_id_state` run with `set +f` locally. New results: `DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT`, `_ID`, `_RECORD_SHA256`, `_SIGNATURE_SHA256`, `_STORE_PATH`, `_VERIFIED_CI_RUN_ID`. Second review: `dms_release_approval_ci_budget` asks `GET /rate_limit` first (free; same curl hardening) and denies when `resources.core.remaining` < 2 + `DMS_RELEASE_APPROVAL_CI_RESERVE` (set by the library to 0, never from the environment; a failed or unparsable answer denies), naming remaining/limit and the reset in UTC; HTTP 403/429 from any call says it may be the rate limit; step 4's fetch adds `--refmap= --no-write-fetch-head --no-auto-maintenance` (no `refs/remotes/origin/*` update, no `FETCH_HEAD`, no gc as root). |
| `scripts/release-approval-submit.sh` (new) | Root wrapper for `submit-approval --release-id <id>` (bundle on stdin): exactly those two words, root only when executed, library and authority read only after they and their directories are proven uid-owned and not group/other-writable; passes the host's release lock (`/run/daengtisiams-deploy/release.lock`, the bootstrap's choice of root); `SUDO_USER` names the submitter in the receipt only. Never deploys. |
| `scripts/release-approval-withdraw.sh` (new) | Administrator-only (root through the administrator's password sudo; NOT in the operator entrypoint or its sudo rule): `--release-id <id> --approval-id <32-hex>` spends the approval id (`spent/approval-<id>.spent`, `withdrawn=yes`) and stores `<store>/<release>/<id>.withdrawn` (0400: `withdrawn_at`, `withdrawn_by`). The approval can never deploy and no longer blocks a corrected submission; nothing is deleted. |
| `scripts/release-approval-sign.sh` (new) | Owner helper (workstation): binds the facts of the target commit's own tree (`deploy/production-host.conf`, `.sprint/current.yml`), requires the commit on `origin/<base>`, random approval id, ≤ 72 h window (decimal `--hours`, `08` = 8) shown in UTC and WITA, YES read from the controlling terminal only (no terminal or a piped stdin → refused), `env -u SSH_AUTH_SOCK SSH_ASKPASS_REQUIRE=never ssh-keygen -Y sign` over the record file (no agent: the passphrase is asked on the terminal), verifies with the `.pub`, writes `~/DaengtisiaMS-release-approvals/<release>/<id>.bundle` (0600, dirs 0700), prints digests and the submit command. No commit, push, deploy or network. |
| `scripts/deploy-operator-entrypoint.sh`, `scripts/lib/sudo-policy.sh` | Two actions in the allowlist and in `dms_sudo_operator_expressions`: `submit-approval --release-id <id>` (`^submit-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+$`; the dispatcher passes `--release-id <id>`, stdin and, for this action only, the validated `SUDO_USER`) and `check-approval --release-id <id> --approved-sha <40-hex>` (`^check-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+ --approved-sha [0-9a-f]{40}$`; the runner's read-only guard run). `DMS_OPERATOR_TOOLS` gains `ssh-keygen python3 curl flock` (the guard's signature check and contract 3's CI query and store lock), each proven root-owned and not writable before root runs anything (all four PASS on the host, read-only). Takes effect on the host only after re-provisioning (`provision-deploy-identities.sh --operator`). |
| `scripts/deploy-vps-runner.sh` | New action `check-approval --release-id <id> --approved-sha <sha>`: the release guard only, read-only (no run claim, record, spend or launch), printing the same verdict `start` would reach. It takes an exclusive non-blocking lock on `check-approval.lock` and a shared non-blocking lock on the bootstrap's `release.lock` (same root: `DMS_DEPLOY_LOCK_DIR`, else `/run/daengtisiams-deploy`), answering `RELEASE APPROVAL CHECK: BUSY` (exit 75) when either is held, and releases both after the guard; it sets the CI reserve to 4, and its EXIT/TERM/INT/HUP/PIPE trap removes its own `refs/dms-release-approval/<pid>-*` (no other EXIT trap on that path). Record gains `approval_contract`; contract 3 records `approval_id`, `approval_record_sha256`, `approval_signature_sha256`, `approval_store_path`, `approval_ci_run_id` instead of the git-object fields. `trust-check` reports `APPROVAL_CONTRACTS=` and `APPROVAL_STORE=OK|ABSENT|UNTRUSTED|NOT-DECLARED` (UNTRUSTED, or NOT-DECLARED while contract 3 is accepted, is NOT READY). |
| `scripts/deploy-immutable-exec.sh` | Contract 3 logs `DEPLOY_APPROVAL_CONTRACT=3`, `_ID`, `_RECORD_SHA256`, `_SIGNATURE_SHA256`, `_STORE_PATH`, `_CI_RUN_ID` (contract 2 output unchanged) and spends the approval id. |
| `scripts/lib/deploy-runner-evidence.sh` | `verify` of an `approval_contract=3` record: fields complete and in the authority's store (`dms_runner_assert_direct_fields`), the log names the same id/digests/signer/CI run and no contract-2 record (`dms_runner_assert_direct_log`), the store files re-hashed (0400, one link, trusted 0700 directory; `dms_runner_assert_direct_store`). Records without `approval_contract` (or `=2`) verify exactly as before. Evidence contract stays 4. |
| `deploy/production-host.conf` | `DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS="2 3"`, `DMS_PRODUCTION_RELEASE_APPROVAL_STORE=/var/lib/daengtisiams-deploy/approvals`, `DMS_PRODUCTION_CI_WORKFLOW`, `DMS_PRODUCTION_CI_REQUIRED_JOBS` (`;`-separated), `DMS_PRODUCTION_CI_REQUIRED_STEP` and `DMS_PRODUCTION_CI_FORBIDDEN_STEP` (`<job>:<step>`). |
| `config/deployment_entrypoint.php` | Contract-3 markers (guard, bootstrap, runner, evidence), the six authority keys required, the store and workflow pinned. |
| `config/ci_runner.php` | `DeployHardenDirectApprovalTest.php` in `critical_gate_mandatory_suites`. |
| `tests/Feature/Deploy/DeployHardenDirectApprovalTest.php` (new) | 187 cases (171 before the second review): canonical parse matrix and renderer, submission (signature, bindings, window, replay, one active approval, hostile bundles, untrusted store, the release lock), deploy guard (no fallback, contract 2 untouched, activation key, store integrity, noglob), withdrawal, interrupted submissions, required CI (stubbed `curl`, incl. docs-only and the critical step), a real contract-3 deploy + `verify` round trip and tampering, trust-check, the signing helper (in a pseudo-terminal; refused without one), the root wrappers, the entrypoint/sudo expression, and `check-approval` (state byte-identical after a check, the rate-limit budget and reserve, `/rate_limit` failures, 403/429, BUSY on either lock, the locks held during the guard, its private ref removed after SIGTERM). |
| `tests/Feature/Deploy/DeployHardenLeastPrivilegeTest.php` | Pinned strings that legitimately changed: the fourth sudo expression, `submit-approval` in the allowed/denied request lists and the program map, `SUDO_USER` in the entrypoint's environment allowlist. |

Deviations from §2–§4: the required-CI response is evaluated by a fixed `python3 -I -S` program (stdlib `json`)
instead of `jq` (not installed on the workstation, so a `jq` gate could not be tested here); the result variable is
`DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT` because `DMS_RELEASE_APPROVAL_CONTRACT` is already the contract-2 constant
pinned by `config/deployment_entrypoint.php`. Rules, runbooks and CLAUDE.md are not changed by this implementation.

## 6. Operations

- **The store is evidence.** Never prune, move, chmod or edit `/var/lib/daengtisiams-deploy/approvals` (or the
  `spent/` records beside the runner evidence): `verify` of a contract-3 run re-hashes the stored record and signature
  and refuses (`TAMPERED`) a run whose store files changed or vanished. Spent, expired and withdrawn approvals stay.
- **Withdrawing an approval** (wrong commit, wrong tag, changed mind) is an administrator action:
  `sudo bash scripts/release-approval-withdraw.sh --release-id <id> --approval-id <id>` on the host. The approval can
  never deploy afterwards; a corrected approval for the same release can then be submitted. The operator key cannot
  withdraw (it is not in the entrypoint).
- **An interrupted submission** (`.<id>.*.tmp` leftovers, or a `.sig`/`.receipt` without its record) makes the
  deploy refuse the release, with a message saying so. Re-run `submit-approval` for the release: under the release
  directory's lock it removes exactly those leftovers before storing. Anything else unexpected in the store is never
  removed automatically; an administrator inspects it.
- **CI of the target.** The base branch's push runs use `cancel-in-progress`: a push right after the release commit
  (a docs commit, for instance) can cancel the release commit's push run, and contract 3 then denies. Before
  submitting, check that the push run of `.github/workflows/foundation-evidence-gates.yml` for that exact SHA
  concluded success; re-run it if it was cancelled. A docs-only commit never satisfies the gate: its critical job
  skips the test step, and the host requires that step to have run.
- **Contract selection happens twice.** The runner (before launch) and the bootstrap (inside the release lock) each
  look in the store. A submission landing between the two makes them choose differently, and `verify` then refuses
  the run (`UNVERIFIED`/`MISMATCHED`) — fail-closed: deploy again under a new release id. A submission is refused
  while a deploy or rollback holds the release lock, and holds that lock while it stores, so it can never land inside
  a deploy's critical section; the window before the bootstrap takes the lock remains.
- **Host prerequisites for contract 3:** `python3`, `curl` and `flock` on the root PATH, HTTPS egress to
  `api.github.com`, and a public repository (the CI query is unauthenticated, 60 requests per hour; a deploy makes 4,
  plus 2 free `/rate_limit` reads). `trust-check` reports the store (`APPROVAL_STORE=`).
- **`check-approval` is serialized.** One check at a time, never while a deploy, rollback or submission holds the
  release lock (`RELEASE APPROVAL CHECK: BUSY`, exit 75: run it again afterwards); a deploy or submission started
  during a check is refused the same way. A check denies when fewer than 6 API requests are left (its 2 plus a
  deploy's 4) and names the reset time in UTC; `start` needs 2.

## 7. Tests and quality gates (local, WSL2, PHP 8.3.6)

| Gate | Result |
|---|---|
| `DeployHardenDirectApprovalTest` (new) and the deploy/doc/guard suites (`DeployHarden|ProductionHostIdentityGuard|ProductionShellCommandGuard|SecretFilePermissionHardening|DevflowAiInstructionSystem|ReleaseEvidence|KnowledgeBase|CursorRule|DocStatus|AiKnowledge`), after the second review's fixes | **905 passed** (7104 assertions), 0 failed (890 after the rebase, before them); `DeployHardenDirectApprovalTest` alone 187 passed |
| Required-CI evaluator against real GitHub data (workstation, read-only) | `75ac3578…` (Phase 2's release): **PASS** — push run `43773683512` completed/success, the three required jobs succeeded, `Run critical regression tests` ran; `ccbc313b…` (a docs-only approval merge, its push run still running): **DENIED** (`in_progress`, not completed/success) |
| Host prerequisites (read-only) | `python3` 3.12.3, `curl`, `flock` on the root PATH; `api.github.com` reachable (HTTP 200) |
| ShellCheck / Pint | after the second review: ShellCheck 0.9.0 `-x` on `scripts/lib/release-approval.sh` and `scripts/deploy-vps-runner.sh` — 0 new findings against the WIP commit (2 and 3 pre-existing); `bash -n` of every changed script passes; Pint passed |
| Full Suite | **DEFERRED** (CG-18) — not passed |

## 8. Independent review

- **First review** (contract-3 library, submit, sign, store, CI gate): 10 items, all fixed before the PR — 1 MEDIUM
  (a docs-only push run satisfied the CI gate: the gate now requires the critical job's `Run critical regression
  tests` step to have run and refuses the docs-only skip step), withdrawal (`release-approval-withdraw.sh`),
  interrupted submissions, a glob-free store scan, signing only from a terminal with ssh-agent excluded, `--hours`
  parsing, the CI run id in the record and `verify`, `trust-check` store states, `curl -q` and a failed receipt write
  denying, and the operations section (§6). The test file grew from 144 to 171 cases.
- **Second review** (after the rebase: `check-approval`, the `submit-approval` dispatch, the trust-tool list,
  allowlist ↔ sudo expressions ↔ tests): 5 items — 1 MEDIUM, 2 LOW, 2 INFO; the first four fixed before the PR, the
  fifth recorded (§9). The test file grew from 171 to 187 cases.
  1. **MEDIUM — `check-approval` could exhaust the unauthenticated API budget** (60/hour per host; 2 calls per
     guard run, 4 per deploy), so a burst of checks made the next deploy deny for up to an hour. Fixed: the guard
     first reads `GET /rate_limit` (not counted) and denies, naming the remaining count, the limit and the reset in
     UTC, when fewer than 2 + the reserve remain; the reserve is 0 for `start` and the bootstrap and 4 for
     `check-approval`; a failed or unparsable `/rate_limit` denies. HTTP 403/429 from any call now says it may be
     the rate limit. Checks are serialized by an exclusive non-blocking lock on `check-approval.lock` beside the
     release lock (`BUSY`, exit 75).
  2. **LOW — the guard's fetch left state:** it updated `refs/remotes/origin/<branch>` opportunistically, rewrote
     `FETCH_HEAD`, could spawn `gc --auto` as root, and a signal skipped the removal of its private ref. Verified
     first that nothing in the deploy, rollback, runner, bootstrap or signing chain reads `FETCH_HEAD` or relies on
     that fetch moving `refs/remotes/origin/*` (the signing helper reads `origin/<base>` from the owner's own fetch
     on the workstation; rollback fetches tags into its own private ref). Fixed: `--refmap= --no-write-fetch-head
     --no-auto-maintenance`; the library still installs no trap (the bootstrap's `cleanup` already removes
     `refs/dms-release-approval/$$-*`); `check-approval` traps EXIT/TERM/INT/HUP/PIPE to remove its own.
  3. **LOW — `check-approval` took no release-lock probe**, so it could run inside a deploy's critical section (a
     starting bootstrap that met its fetch's ref `.lock` would refuse with `stale git lock`). Fixed: a shared
     non-blocking lock on the bootstrap's release lock for the duration of the guard (`BUSY` while a deploy, rollback
     or submission holds it; a deploy started meanwhile answers `BUSY`).
  4. **INFO — no behavioural proof that `check-approval` is read-only.** Added: after a real contract-3 deploy, a
     check of a second stored approval leaves every ref (loose and packed), `FETCH_HEAD`, the evidence directory,
     the spent records and the store byte-identical and no private ref behind; tests for the budget with and
     without the reserve, `/rate_limit` failures, 403/429, BUSY on either lock, both locks held mid-guard, and the
     private ref removed after SIGTERM. Mutation-checked: without the fetch flags or the trap, the new tests fail.
  5. **INFO — interpreter trees are not walked:** recorded in §9 (no code change).

## 9. Residual risks

- The CI query is unauthenticated (60 requests per hour per host address); a deploy makes 4. `check-approval` keeps 4
  in reserve and runs one at a time, so the operator's checks can no longer spend a deploy's budget; other clients
  behind the same address still can, and `start` and the bootstrap each need only 2, so a deploy begun with 2 or 3
  left denies at the bootstrap (inside the lock, before any snapshot or backup). Every case fails closed.
- The operator entrypoint tree-checks Node's installation, but not python3's standard library or git's
  `git-remote-https` helper. A full walk of the standard library would always refuse on Debian, whose
  `/usr/lib/python3.12/sitecustomize.py` links into `/etc` (outside the tree); the guard runs `python3 -I -S`, which
  never imports it. Both are root-owned distribution files; tampering with them already needs root.
- Contract selection happens twice (runner, then bootstrap): a submission between the two makes `verify` refuse the run
  (§6). Fail closed.
- Contract 3 trusts GitHub's API for the CI verdict of the exact SHA (the same trust the approval PR's own checks had).

## 10. Release states

| State | Evidence |
|---|---|
| Implemented, locally verified | §5, §7 |
| Reviewed | §8 |
| PR / CI / merge / post-merge CI | PR #643 run `43800039265`: Critical Test Gate **10,416 passed**, 0 failed (`DeployHardenDirectApprovalTest` ran); squash merge pinned to `5dd5ced7` → **`312337b4f8bf993595ff315c78e90a12820c361c`** (tree `ef2a896b1ece12248870c0a0c1c801738d9808be` = tested head tree); post-merge push run `43807868750` **success**, Critical Test Gate **10,416 passed**; Full Suite skipped = DEFERRED (CG-18) |
| Release approval (contract 2, owner-signed, approval PR) — the migration release | owner approval 2026-11-16T06:27:41Z (§11.1); signature and approval PR pending |
| Deploy through the operator (contract 2) | pending |
| Operator re-provisioned (owner's password) → `submit-approval`, `check-approval` active | pending |
| Production test of contract 3 (owner-signed test approval, `submit-approval`, `check-approval`, withdraw; no second deploy) | pending |
| GO tag `dms-release-approval-direct-1-go` | pending |

## 11. Owner decisions (CG-22)

### 11.1 Release approval of the exact SHA (2026-11-16T06:27:41Z)

After PR #643 merged as `312337b4…` and its post-merge CI run `43807868750` was green (Critical Gate 10,416 passed),
and a read-only preflight through the operator (both targets `VERIFIED`, HEAD `75ac3578` clean,
`RELEASE GUARD TRUST: READY`, `PRIVSEP_DRIFT=NONE`, `SUDO_POLICY=LEAST_PRIVILEGE`, locks idle, 166 ERROR lines), the
owner answered **"Approve 312337b4"** to: deploying **`312337b4f8bf993595ff315c78e90a12820c361c`** (tree
`ef2a896b1ece12248870c0a0c1c801738d9808be`; PR #643) to production on Biznet `DaengtisiaMS` as release
`dms-release-approval-direct-1` — the last contract-2 approval PR — in this order: deploy through the operator under
contract 2 (pre-deploy dump mandatory; BK-2 skipped); verify; the owner runs runbook §6g step 0 (re-provisions the
operator with the administrator's password); the owner signs a contract-3 test approval, the assistant runs
`submit-approval` and `check-approval`, then the owner withdraws it (no second deploy); GO tag
`dms-release-approval-direct-1-go` only after all of that. Rollback only on the owner's explicit go-ahead, by full SHA
`75ac3578808a8dd8c720bf38e98a86685183eb66`, code only. 48-hour window.

Record: `deploy/release-approvals/dms-release-approval-direct-1.approval` (contract 2, window 2026-11-16T06:27:41Z → 2026-11-18T06:27:41Z).
