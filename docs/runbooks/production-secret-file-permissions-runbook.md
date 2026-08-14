# Production Secret File Permissions — Runbook (INFRA-SEC-ENV-1)

> **Do NOT add this file to `config/enterprise_documentation.php` `mandatory_runbooks`
> without rewording it first.** The ENT-15 scanner runs
> `release_evidence.forbidden_patterns` over every *registered* runbook, and that
> list contains the literal environment-file name that this runbook necessarily
> uses throughout. It is deliberately unregistered so it can stay operationally
> precise. It is linked from the ENT-11 deploy/rollback runbook instead.

## Purpose

Keep the production environment file — and every secret-bearing variant or backup
beside it — readable only by the accounts that genuinely need it, and make the
deploy prove that on every run instead of trusting historical filesystem state.

## Approved state

| File | Owner | Group | Mode |
| --- | --- | --- | --- |
| `.env` | `root` | PHP-FPM runtime group (`www-data`) | `0640` |
| `.env.bak*`, `.env.backup*`, `.env.save*`, `.env.old*`, `.env.orig*` | `root` | any | `0600` |
| `.env.example` | tracked in git, holds no secret | — | untouched |

Forbidden for any of the above, permanently: `0644`, `0664`, `0666`, and any mode
with a read/write bit for *other* or a write bit for *group*.

### Why 0640 and not 0600

This was derived from the real runtime, not assumed. `scripts/deploy-vps.sh`
rebuilds the Laravel caches **as the PHP-FPM runtime user**
(`as_runtime php artisan config:cache`, from FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS).
`config:cache` clears the cached config and re-boots the framework to read the
environment file. phpdotenv's `safeLoad()` **swallows a permission error and
returns an empty environment**, so at `0600 root:root` the deploy would silently
write a config cache with an empty application key and empty database
credentials — a total, silent outage with no error message. The runtime group
therefore genuinely requires read access, and `0640` is the correct
least-privilege state.

Do not "tighten" this to `0600` without first moving the cache rebuild off the
runtime user — which would reintroduce the root-owned-cache post-login 500 that
FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS fixed.

## Safe commands

Inspect metadata only — never print the contents of a secret file:

```bash
stat -c '%U %G %a %n' /var/www/asia-dental-lab-v2/.env
namei -l /var/www/asia-dental-lab-v2/.env
ls -la /var/www/asia-dental-lab-v2/ | grep -i env      # a trailing '+' means an ACL exists
getfacl -p /var/www/asia-dental-lab-v2/.env            # only if acl is installed
```

Verify (read-only, exits non-zero when unsafe):

```bash
cd /var/www/asia-dental-lab-v2
bash scripts/harden-secret-permissions.sh verify --owner root --group www-data
```

Repair (idempotent; chown only applies when run as root):

```bash
cd /var/www/asia-dental-lab-v2
bash scripts/harden-secret-permissions.sh apply --owner root --group www-data
```

Prove an unrelated account cannot read it (permission probe only — never `cat`):

```bash
sudo -u postgres test -r /var/www/asia-dental-lab-v2/.env && echo READABLE || echo DENIED
```

## Forbidden commands

Never run any of these against a production secret file:

```bash
cat .env            # prints secrets into the terminal, logs, and AI transcripts
head .env
tail .env
grep '=' .env
chmod -R 600 /var/www/asia-dental-lab-v2     # breaks directories, public assets, scripts
chmod 644 .env                                # reintroduces the finding
```

Also never paste secret values into CI logs, pull requests, docs, screenshots, or
an AI session.

## How the deploy enforces it

`scripts/deploy-vps.sh` calls the helper twice:

1. **Early — before it sources the environment file** and long before the runtime
   user rebuilds the config cache, so no deploy phase ever runs against a
   world-readable secret.
2. **Late — after every file-touching phase**, then a separate `verify` whose
   failure aborts the deploy with `NOT GO`.

`scripts/rollback-vps.sh` re-asserts the same invariant, so rolling back to an
older ref cannot restore an unsafe mode.

The call is a **required marker** in `config/deployment_rollback.php`
(`deploy_expectations.required_markers` and `rollback_expectations.required_markers`),
so `php artisan foundation:deployment-rollback-check` — which runs both in CI and
during every deploy — fails if anyone removes it.

## Evidence

A deploy prints, without any secret value:

```
== Harden secret file permissions (INFRA-SEC-ENV-1) ==
secret-perm: applied 0640 to .env
secret-perm: verified .env mode=0640 owner=root group=www-data
SECRET FILE PERMISSIONS: GO
```

## Recovery — the runtime lost access

Symptom: after a deploy, pages 500 and the log shows a missing application key or
a database authentication failure, i.e. the config cache was built from an empty
environment.

```bash
cd /var/www/asia-dental-lab-v2
stat -c '%U %G %a' .env                                   # expect: root www-data 640
bash scripts/harden-secret-permissions.sh apply --owner root --group www-data
runuser -u www-data -- php artisan config:cache
runuser -u www-data -- php artisan deploy:auth-landing-smoke --strict
systemctl reload php8.3-fpm
```

If the mode is right but the group is wrong, the runtime user is not in the file's
group — re-check the PHP-FPM pool user and pass the correct `--group`.

## Troubleshooting

- **`is a symlink — refusing to harden`** — the helper fails closed on a symlinked
  secret file because the link target's mode is what actually governs access.
  Replace the symlink with a real file, or extend the helper deliberately.
- **`has a named-user/named-group ACL granting read access`** — an ACL grants
  access the Unix mode does not show. Inspect with `getfacl -p` and remove the
  entry with `setfacl -x`. Only runs when `acl` is installed; production currently
  has no ACL support installed and no ACL on the file.
- **`owner '<x>' != expected 'root'`** — something re-created the file. Do not
  chown blindly; confirm the file is the intended one first.

## Smoke verification

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/login
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/health/live
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/health/ready
systemctl is-active daengtisiams-queue-worker.service
runuser -u www-data -- php artisan about > /dev/null && echo "bootstrap OK"
```

## Security

- Metadata only. This runbook, the helper, and the deploy output never print a
  secret value.
- Temporary copies of a secret file must be created under `umask 077` (mode
  `0600`) — never created world-readable and chmodded afterwards.
- Every environment variant except `.env.example` is gitignored (`.env.*` with a
  `!.env.example` negation).
- The residual risk this sprint does **not** close: the co-tenant application on
  this host runs under the *same* `www-data` account, so a file mode cannot stop
  same-UID read. Closing that requires a dedicated runtime account — tracked as a
  follow-up, not silently claimed as done.

## Owner

Platform / deployment owner (same owner as the ENT-11 deploy and rollback
automation).

## Review Cadence

Re-verify at every deploy (automatic, fail-closed) and review this runbook
whenever the PHP-FPM pool user, the queue worker unit, or the co-tenant layout on
the host changes.
