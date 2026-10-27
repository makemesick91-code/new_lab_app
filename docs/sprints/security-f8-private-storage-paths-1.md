# SECURITY-F8-PRIVATE-STORAGE-PATHS-1 — private storage paths declared and never widened

Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `19a2ed1c`.
Type: SECURITY_FIX. No migration, no route, no permission, no application code change.
Durable rule: `.cursor/rules/209-private-storage-paths-declared-and-never-widened.mdc`.

## Problem

`scripts/deploy-vps.sh` and `scripts/rollback-vps.sh` normalize the storage tree to directories `2775` and files
`0664`, then strip "other" permissions from the paths in `DMS_PRIVATE_PATHS`
(`deploy/runtime-identity.conf`). The list predates three private disks — `clinical_evidence`,
`legacy_odontogram_private`, `legacy_mass_upload_private` — and did not include the clinical-evidence migration
manifests or two directories holding old database dumps. Every deploy therefore left them readable at the filesystem
level by other local accounts. HTTP access was never possible (`/storage/` is denied by nginx and these disks are
`serve => false`). The isolation verifier checks the same list, so it reported GO.

A second, independent defect: the deploy sources the runtime identity file from the live host copy before
`git merge --ff-only`. A release that adds a path to the list only took effect from the following deploy, and the
deploy shipping it widened the path itself.

The host exposure was contained separately before this change (owner gate G6, permission bits only, recorded with a
rollback manifest). This change makes the containment survive every later deploy.

## Change

| File | Change |
|---|---|
| `deploy/runtime-identity.conf` | `DMS_PRIVATE_PATHS` adds `storage/app/clinical-evidence-private`, `storage/app/legacy-odontogram-private`, `storage/app/legacy-mass-upload-private`, `storage/app/clinical-evidence-migration`, `storage/app/deploy-backups`, `storage/backups` |
| `scripts/lib/private-paths.sh` (new) | `dms_private_path_is_valid`, `dms_private_paths_union` (first-seen order, no duplicates, fails closed on an invalid entry, no glob expansion) |
| `scripts/deploy-vps.sh` | prunes every private path from the widening; `restrict_private_paths` gives the owner `u+rwX` then strips `o-rwx`; after the fast-forward merges the target commit's list with the live list; passes the merged list to the verifier |
| `scripts/rollback-vps.sh` | same pruning and owner/other handling |
| `scripts/verify-runtime-isolation.sh` | `--additional-private-paths LIST` (union; only adds checks; invalid entry is a FAIL) |
| `config/deployment_entrypoint.php` | the new library is an execution-closure member |
| `config/ci_runner.php` | the new suite is a mandatory critical-gate suite |

## Tests

New `tests/Feature/Deploy/RuntimeIdentityIsolationPrivatePathsTest.php` (20 tests):

- every private local disk in `config/filesystems.php` and every dump/manifest directory is listed; the public disk is not;
- the union helper: order, de-duplication, eight invalid shapes rejected, no glob expansion;
- deploy order: the merge happens after `git merge --ff-only` and before the first normalization, and the verifier
  receives the merged list;
- the target release's new path is adopted in the same deploy, using a throwaway git repository;
- the real normalization code extracted from both scripts runs against a fixture: private trees are not widened, keep
  no "other" bit, and the owner keeps read/write.

8 of the 20 fail against the unchanged scripts and configuration; all 20 pass after the change.
`RuntimeIdentityIsolationTest` gains two verifier tests for `--additional-private-paths`.

Selected by the existing `RuntimeIdentityIsolation` critical-gate token in both runner variants (verified with
`pest --list-tests` against each filter: 19 cases each at the time of measurement) and declared in
`critical_gate_mandatory_suites`.

## Deployment and verification

Merge and deployment need a separate owner GO. After deployment:

1. The deploy log shows `Private storage paths (live + target): …` with the new paths, and the isolation gate lists
   each as private.
2. `runuser -u www-data -- test -r storage/app/clinical-evidence-private` fails; the same test as `daengtisiams`
   succeeds.
3. Health endpoints return 200 over the canonical URL.

## Rollback

Rolling back to an older tag keeps the paths private, because rollback reads the identity file from the live host copy.
A git revert is different: the deploy that lands it still restricts the paths, but later deploys widen them again, so a
revert must be followed by re-containment.

## Not in scope

- Environment-file copies outside the hardening script's backup patterns are re-set to 0640 (runtime group only) by
  every deploy; moving them out of the application tree is a separate, owner-gated quarantine step.
- `scripts/provision-runtime-identity.sh` (one-time host provisioning) still widens before restricting.
- The Full Suite is deferred under the global temporary policy; it was not run.
