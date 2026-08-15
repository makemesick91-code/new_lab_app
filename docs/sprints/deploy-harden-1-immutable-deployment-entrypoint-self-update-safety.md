# DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint & Self-Update Safety

**Branch:** `feature/deploy-harden-1-immutable-deployment-entrypoint-self-update-safety`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Manifest:** `.sprint/current.yml` (`INFRA_RELEASE`)
**Governance:** `foundation:deployment-entrypoint-check`, rules **DH1-R001..R012**
**Rule mirror:** `.cursor/rules/92-immutable-deployment-entrypoint.mdc`
**Runbook:** `docs/runbooks/vps-deploy-rollback-runbook.md`

No migration. No permission change. No route. No application runtime change.

---

## 1. Root cause

`scripts/deploy-vps.sh` was executed straight out of the working tree while,
roughly halfway through its own body, it ran:

```
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"
```

bash does not slurp a script. It reads it **incrementally** and keeps a byte
offset into the open file. Rewriting those bytes underneath a running
interpreter is undefined behaviour: the shell can resume mid-token at a stale
offset and execute a line no author ever wrote.

The same class of defect existed **one indirection removed**. After the pull, the
deploy invoked:

```
bash scripts/harden-secret-permissions.sh ...   # INFRA-SEC-ENV-1 gate
bash scripts/verify-runtime-isolation.sh ...    # INFRA-SEC-RUNTIME-1 gate
```

Both were re-read out of the tree the deploy had just rewritten. The two most
security-critical gates in the release chain were reading post-mutation bytes.

`scripts/rollback-vps.sh` had the same exposure. It already staged the isolation
verifier into a `mktemp` directory before its checkout — a partial mitigation
that protected the verifier but left the rollback script **itself** mutable while
it ran, and left `harden-secret-permissions.sh` being re-read from the tree.

Two recent releases had to be rescued with a manual pre-pull. That workaround
worked; it is not an architecture.

## 2. The invariant

```
RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE
```

Once a deployment starts executing its deployment program, that program is
immutable for the duration of the run.

## 3. New execution model

```
operator (ON srv1730088 only)
  └─ bash scripts/deploy-vps-runner.sh start        # detached, SSH-safe, logs + status
       └─ scripts/deploy-vps.sh                     # hands over before mutating
            └─ scripts/deploy-immutable-exec.sh     # THE TRUSTED BOOTSTRAP
                 ├─ flock /run/daengtisiams-deploy/deploy.lock   (exclusive, role-scoped)
                 ├─ git fetch + PIN TARGET_SHA                   (frozen; origin may advance)
                 ├─ git archive TARGET_SHA -- scripts deploy     (from the OBJECT, not the tree)
                 ├─ overlay live deploy/runtime-identity.conf
                 ├─ trust check: owner == deploy uid, mode 0700, no symlink
                 └─ run  <snapshot>/scripts/deploy-vps.sh        (immutable payload)
                      ├─ harden secrets            (from snapshot)
                      ├─ pg_dump backup            BEFORE migrate
                      ├─ git merge --ff-only TARGET_SHA + HEAD verify
                      ├─ migrate --force, gates, cache rebuild, ownership
                      ├─ secret verify + runtime isolation  (from snapshot)
                      ├─ restart, smoke
                      └─ HEAD == TARGET_SHA  →  DEPLOY OK
                 └─ trap: purge snapshot, release lock, PRESERVE exit code
```

**Why not `exec` into the snapshot?** The bootstrap deliberately runs the
deployment program as a child: that keeps the lock descriptor open for the whole
critical section and keeps the `EXIT` trap alive, so the snapshot is always
cleaned up and the child's real exit code is propagated.

**Pre-mutation bootstrap chain.** The runner → deploy script → bootstrap chain is
still read from the working tree. That is safe and intentional: nothing has
mutated the tree at that point, and once the snapshot exists no working-tree file
is read again. Everything at or after the first mutation runs from the snapshot.

## 4. Execution closure

Everything the deployment executes across the repository mutation is inside the
snapshot:

| Member | Source |
| --- | --- |
| `scripts/deploy-vps.sh` | `git archive TARGET_SHA` |
| `scripts/rollback-vps.sh` | `git archive` (HEAD for rollback) |
| `scripts/harden-secret-permissions.sh` | `git archive` |
| `scripts/verify-runtime-isolation.sh` | `git archive` |
| `deploy/` (incl. systemd units the verifier reads) | `git archive` |
| `deploy/runtime-identity.conf` | **live host file, overlaid after export** |

The identity authority is overlaid from the live host on purpose: rolling the
**code** back must never roll the **runtime identity** back onto the shared
co-tenant account (INFRA-SEC-RUNTIME-1).

No deployment script sources a mutable helper. The contract scanner fails if
`bash scripts/harden-secret-permissions.sh` or
`bash scripts/verify-runtime-isolation.sh` reappears in either entrypoint.

## 5. Target pinning

`git pull --ff-only origin <branch>` resolved the target **during** the run, so a
branch that advanced mid-deploy silently retargeted it. Now:

- the bootstrap fetches and freezes `TARGET_SHA` before the critical section;
- the deploy fast-forwards the branch to that **exact commit**
  (`git merge --ff-only "$TARGET_SHA"`);
- HEAD is compared to `TARGET_SHA` immediately after the checkout **and** again
  as the last gate before `DEPLOY OK`;
- rollback resolves the operator's tag to one exact commit before mutating.

A remote commit that lands after the pin belongs to the **next** deployment.

## 6. Concurrency

`flock -n` on `/run/daengtisiams-deploy/<role>.lock`, held on an open descriptor
for the whole critical section.

- Second deploy → exit **75**, `BUSY`, **no** backup, migration or checkout.
- Released automatically on success, failure, signal **and SIGKILL** — a crashed
  deploy cannot leave a stuck lock.
- The advisory lock is the authority. A lock file on disk, or a stale
  human-readable status file, is **not** and never blocks a deployment.
- Deploy and rollback take separate role-scoped locks.

The lock and the snapshot live under `/run` (fallback `/var/lock`), never under
`storage/` or `bootstrap/cache` — those are writable by the DaengtisiaMS runtime
user and were historically readable by the co-tenant, so a deployment payload
living there could be replaced by the process the deploy is meant to constrain.

## 7. Cleanup and failure paths

- `trap cleanup EXIT` plus `TERM`/`INT` handlers.
- `purge_snapshot` runs `rm -rf` as root, so it is guarded: non-empty path, path
  must equal this run's own snapshot exactly, must be a real directory, must not
  be a symlink.
- Cleanup **never** rewrites the exit code — a failed deploy stays failed.
- Deployment logs under `storage/logs/deploy-runner/` are audit evidence and are
  retained; execution snapshots are disposable and always removed.

## 8. Preserved, not weakened

- **INFRA-SEC-ENV-1** — secret hardening + fail-closed verify still run on every
  deploy and rollback; DB dumps still `chmod 0640`.
- **INFRA-SEC-RUNTIME-1** — dedicated runtime identity, `--require-host`
  isolation gate, private-path re-restriction, no `www-data` fallback.
- Backup **before** migration, absolute. Backup failure aborts.
- `migrate --force` only; no destructive database command anywhere.
- ENT-8 cache ordering, ENT-10 CI/CD gate, ENT-11 deploy/rollback contract.
- A dirty tracked checkout **fails closed** — never resolved with a hard reset;
  production work is never discarded to make a deploy pass.

## 9. Governance

- `config/deployment_entrypoint.php` — all contract literals (config-not-code).
- `App\Support\Deploy\DeploymentEntrypointScanner` — read-only.
- `App\Services\Foundation\DeploymentEntrypointGovernanceService` — **DH1-R001..R012**,
  published as the informational `deployment_entrypoint_governance` section
  (not wired into the blocking combined decision; the blocking enforcement is the
  deploy script's own fail-closed bootstrap plus the pre-deploy gate).
- `php artisan foundation:deployment-entrypoint-check [--json] [--strict]`.
- Registered in `release_safety.required_pre_deploy_gates`, the CI workflow, the
  CI evidence script, the deploy script, and `foundation_governance` CI registry.
- Optional evidence artifact `deployment-entrypoint-check.json` (ci + vps).

## 10. Tests → CI gate

| Test | CI gate |
| --- | --- |
| `tests/Feature/Deploy/DeployHardenImmutableEntrypointTest.php` (20) | NSF-R011 Critical Test Gate (`DeployHarden` filter token) |
| `tests/Feature/Deploy/DeployHardenConcurrencyLockTest.php` (8) | NSF-R011 Critical Test Gate (`DeployHarden` filter token) |
| `foundation:deployment-entrypoint-check` | NSF-9 release-safety gate + deploy gate phase |

The `DeployHarden` token was added to **both** critical-filter occurrences
(GitHub-hosted and self-hosted variants) so the suite can never exist only
locally.

Behavioural tests execute the **real** `scripts/deploy-immutable-exec.sh` against
a synthetic git repository with its own lock directory. They prove: a repository
rewrite mid-run cannot change the executing program; a pinned target beats a
newer commit; the snapshot is trust-verified and removed; a failing run keeps its
exit code; a second deploy fails closed; the lock survives success, failure and
SIGKILL; a stale lock file does not block. No production deployment is ever
started to test concurrency.

## 11. Transitional bootstrap (this release only)

A host running the pre-DEPLOY-HARDEN-1 launcher has no bootstrap in its checkout,
and the new deploy script **fails closed** rather than falling back to the unsafe
path. For that single release the target's own deployment payload is extracted to
a root-controlled path outside the checkout and run from there (see the runbook).
It mutates nothing in the working tree and hands over to the same immutable
bootstrap. Afterwards the canonical in-tree command is the permanent path.

`TRANSITIONAL_BOOTSTRAP=YES` for the DEPLOY-HARDEN-1 release itself, `NO` for
every deployment after it.
