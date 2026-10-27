# SECURITY-F8C-ENV-COPY-BACKUP-CLASSIFICATION-1

**Type:** security fix (deploy) · **Base:** `043a4ae2`
**No migration, no route, no permission, no application code change.**

## 1. Finding

`scripts/harden-secret-permissions.sh` (INFRA-SEC-ENV-1) runs on every deploy and
sorts environment files at the application root into two classes:

- runtime files (`0640`, readable by the runtime group);
- backups (`0600`, root only).

It recognised backups only by prefix: `.env.bak*`, `.env.backup*`, `.env.save*`,
`.env.old*`, `.env.orig*`, `*.bak`.

Five copies on the production host are named after the occasion first, for
example `.env.wave1-backup-20260913-100607`. They matched no prefix, were
classified as runtime files, and were set to `0640` on every deploy.

Observed on 2026-10-27: containment had set them to `0600`; the SECURITY-F8
deploy the same day reset all five to `0640`.

Exposure was limited. No other account could read them, and the runtime group
already reads the live file. But they are stale copies of every secret, and a
copy that outlives a credential rotation keeps the old value readable by
whatever runs as the runtime.

The INFRA-SEC-ENV-1 suite (`SecretFilePermissionHardeningTest`) matched no
critical-gate filter token. It ran in no required CI gate.

## 2. Change

- The classifier treats any `.env.*` name containing `backup` as a backup. The
  framework only ever loads `.env`, so no runtime file is affected.
- `SecretFilePermissionHardening` is added to both critical-gate filter variants,
  and the suite is declared in `critical_gate_mandatory_suites`.
- The runbook table and rule 100 list the new pattern.

## 3. Tests

`SecretFilePermissionHardeningTest`, 27 passed. Two new cases:

- `apply` sets `.env.wave1-backup-…`, `.env.wave2-irwan-backup-…` and
  `.env.pre-deploy-backup` to `0600` and leaves `.env` at `0640`;
- `verify` rejects a backup-named copy left at `0640`.

Both fail against the unfixed script. `pest --list-tests` with the critical
filter now selects all 27 cases (0 before).
