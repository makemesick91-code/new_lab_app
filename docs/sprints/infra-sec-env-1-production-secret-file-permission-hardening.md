# INFRA-SEC-ENV-1 — Production Secret File Permission Hardening

**Type:** SECURITY_FIX · **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Scope:** deployment automation + governance + tests + docs. No application runtime,
schema, route, permission, or clinical behaviour change.

## 1. Finding

On the production host `srv1730088`:

```
stat -c '%U %G %a %n' /var/www/asia-dental-lab-v2/.env
root root 644 /var/www/asia-dental-lab-v2/.env
```

The application secret file was **world-readable** on a host that runs more than
one workload. Every local account could read the application key, database
credentials, and integration secrets.

## 2. Root cause

**No deploy step had ever asserted a mode on it.**

`scripts/deploy-vps.sh` hardened `storage/` and `bootstrap/cache` (ownership,
`2775`/`0664`, fail-closed writable gates) but never touched the environment file.
No script in the repository ever ran `chmod` or `chown` against it — a repo-wide
search for `chmod`/`chown`/`umask`/`install -m` confirms the only permission work
targets `storage`/`bootstrap/cache`.

So the file kept whatever mode it was born with: it was created once by hand under
the default `umask 022`, which yields `0644`, and nothing ever corrected it. This
is an **absence of an invariant**, not an active regression — which is exactly why
no existing gate caught it: every gate checked *writability for the runtime user*,
none checked *unreadability for everyone else*.

## 3. Production identity model (measured, not assumed)

| Role | Account |
| --- | --- |
| Deploy / `deploy-vps.sh` | `root` |
| PHP-FPM pool `www` (PHP 8.3, DaengtisiaMS) | `www-data:www-data` |
| Queue worker `daengtisiams-queue-worker.service` | `www-data:www-data` |
| nginx workers | `www-data` |
| **PHP-FPM pool `aish-pos` (PHP 8.5, co-tenant)** | **`www-data:www-data`** |
| Other accounts | `postgres` (uid 109, `/bin/bash`); **no human/UID≥1000 accounts exist** |

`getent group www-data` → `www-data:x:33:` (no supplementary members).
No ACL on the file (`ls -l` shows no trailing `+`; `getfacl` is not installed on
the host, so the Unix mode is the whole access story).

## 4. Why the target mode is 0640, not 0600

Determined from the real runtime rather than picked by preference.

`scripts/deploy-vps.sh` rebuilds the Laravel caches **as the PHP-FPM runtime user**
(`as_runtime php artisan config:cache`) — a deliberate part of
FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS, which fixed a post-login 500 caused by
root-owned Laravel cache files.

`config:cache` clears the cached config, then re-boots the framework to read the
environment file. Laravel's `LoadEnvironmentVariables` calls phpdotenv's
`safeLoad()`, and `safeLoad()` **catches `InvalidPathException` and returns an
empty environment** — an unreadable file is indistinguishable from a missing one.

Therefore at `0600 root:root` the deploy would **silently** write a config cache
containing an empty application key and empty database credentials. Not a loud
failure — a silent, total outage.

The runtime group genuinely requires read access, so:

```
.env                    root:www-data  0640
.env.bak* / .env.backup*  root:*       0600   (never read by the runtime)
.env.example            unchanged             (tracked, holds no secret)
```

`0640` removes read for `other`, which removes `postgres` and every other
non-`www-data` account on the host.

## 4b. Second finding — world-readable database dumps

Auditing for "other secret files produced by the same deployment path" found a
larger exposure than the environment file:

```
storage/app/backups/**    175 files, all mode 0664 (world-readable)
runuser -u postgres -- test -r <dump>   ->  READABLE
```

These are `pg_dump` output — the **entire clinical database**: patients, national
identity numbers, medical records, invoices, payments. Same root cause: `pg_dump
> file` writes under the deploy user's `umask 022`, and
`normalize_runtime_ownership` then sets every storage file to `0664`. Nothing
ever asserted that a database dump is secret-bearing.

In scope because it is *directly caused by the same deployment path*.

**Fix is deliberately `other`-bits-only** — `0664`/`0644` → `0640`, owner and
group untouched:

- `root` (backup verify, restore, restore-rehearsal, evidence capture) keeps
  access — unchanged.
- the runtime user (ENT-7 developer-console backup listing) keeps access — the
  files stay `www-data:www-data` and the directory stays `2775`.
- every unrelated local account loses access.

No consumer loses a permission it currently uses, so this cannot break the
ENT-11 storage contract or the ENT-12 backup/DR chain. The deploy and rollback
scripts additionally `chmod 0640 "$BACKUP"` immediately after `pg_dump`, closing
the window between creation and the end-of-deploy sweep.

## 5. Residual risk — stated, not hidden

**The co-tenant application `aish-pos` runs under the same `www-data` account.**

A file mode cannot separate two workloads that share a UID. `0640 root:www-data`
therefore closes every *other* local account but does **not** close same-UID read
by the co-tenant.

Closing that requires giving DaengtisiaMS a dedicated runtime account (its own
FPM pool user + queue worker `User=` + ownership of `storage`/`bootstrap/cache`),
which is a separate production-infrastructure change with its own outage risk —
and is explicitly outside this sprint's scope (§71 excludes OS-wide permission
redesign and co-tenant application redesign). It is recorded here as a follow-up
rather than quietly claimed as delivered.

Note also that `scripts/deploy-vps.sh` auto-detects `RUNTIME_USER` by grepping
*all* FPM pool files and taking `head -1`; because both pools currently declare
`www-data`, that is correct today, but it would need to be made pool-specific
before any dedicated-account migration.

## 6. What shipped

| File | Change |
| --- | --- |
| `scripts/harden-secret-permissions.sh` | **new** — idempotent, fail-closed `apply`/`verify` helper |
| `scripts/deploy-vps.sh` | hardens early (before sourcing) + re-asserts and verifies late, fail-closed; `chmod 0640` on the dump right after `pg_dump` |
| `scripts/rollback-vps.sh` | re-asserts the invariant so a rollback cannot restore an unsafe mode; same immediate dump `chmod` |
| `config/deployment_rollback.php` | `harden-secret-permissions.sh` added to the ENT-11 required markers for **both** deploy and rollback |
| `.gitignore` | `.env.*` with `!.env.example` — every variant/backup is uncommittable |
| `tests/Feature/Deploy/SecretFilePermissionHardeningTest.php` | **new** — 25 tests |
| `docs/runbooks/production-secret-file-permissions-runbook.md` | **new** |

### Helper safety contract

Never prints file contents. Never creates, truncates, or edits a secret file.
Never runs a recursive `chmod`/`chown` over the application tree. Fails closed on
a symlinked secret file (the link target's mode is what really governs access).
Detects named-user/named-group read ACLs when `getfacl` is available. `chown` only
when running as root, so the same helper is testable unprivileged in CI.

### Durable enforcement

The helper being *called* is a required marker in `config/deployment_rollback.php`.
`php artisan foundation:deployment-rollback-check` runs **in CI and inside every
deploy**, so deleting the call fails the build and the deploy. That is the
mechanism that stops a future deploy from silently regressing to `0644` — not the
one-time `chmod`.

## 7. Tests

`tests/Feature/Deploy/SecretFilePermissionHardeningTest.php` — 25 tests. The
functional ones execute the real script against synthetic fixtures
(`APP_KEY=fake-test-value`) in a private `0700` temp dir; no production value
appears anywhere.

| Case | Expected |
| --- | --- |
| `0640` / `0600` | accepted |
| `0644`, `0666`, `0660`, `0664`, `0604`, `0646` | rejected, exit 1 |
| `apply` on `0644` | converges to `0640`, idempotent |
| backup copies | forced to `0600` |
| `.env.example` | untouched |
| unexpected owner / unexpected runtime group | rejected |
| symlinked secret file | fails closed |
| missing file | reported, **no replacement created** |
| helper output | never contains a fixture secret value |
| deploy script | hardens *before* it sources the file; fails closed |
| rollback script | re-asserts the invariant |
| ENT-11 config | requires the marker in both script contracts |
| `.gitignore` | covers every variant except the example |
| database dumps `0664`/`0644` | tightened to `0640`, owner/group untouched |
| world-readable dump | rejected by `verify` |
| dump already `0600` | left alone |
| deploy + rollback | `chmod` the dump immediately after `pg_dump` |

## 8. Results

```
tests/Feature/Deploy                                  38 passed (109 assertions)
Ent11DeploymentRollbackAutomationTest                 12 passed (115 assertions)
Ent10 / Ent12 / Ent15 / SafeCiRuntimeControl          54 passed (519 assertions)
pint --dirty --test                                   passed
git diff --check                                      clean
bash -n (3 scripts) + shellcheck -S warning           clean
CICD-CTRL-1 classifier                                unknown_high_risk (all gates run)
```

## 9. Production verification

Filled in from the real deploy — see §10.

## 10. Deploy evidence

Recorded after the merged release was deployed on the VPS. See the "Production
verification" entry in `CLAUDE.md` for the authoritative summary.

## 11. Out of scope

Vault/secret-manager migration; credential rotation (no evidence of actual
unauthorised access was found — if it had been, that becomes a separate incident
decision); OS-wide permission redesign; co-tenant application redesign; SSH or
firewall hardening; the dedicated-runtime-account migration described in §5.
