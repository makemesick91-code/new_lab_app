# Runbook — DaengtisiaMS Dedicated Runtime Identity & Co-Tenant Isolation

**Sprint:** INFRA-SEC-RUNTIME-1
**Applies to:** production VPS `srv1730088`, `/var/www/asia-dental-lab-v2`
**Authority:** `deploy/runtime-identity.conf` — the single source of truth for
the DaengtisiaMS runtime identity.

No secret value, patient datum, or file content appears anywhere in this runbook
or in any command it prescribes. Access is always proven with `test -r` /
`test -w`, never by reading a file.

---

## 1. The model in one page

```
nginx (www-data)  ──connect──▶  /run/php/php8.3-fpm-daengtisiams.sock
                                        │
                                        ▼
                          PHP-FPM pool [daengtisiams]
                          user/group = daengtisiams
                                        │
        ┌───────────────────────────────┼───────────────────────────────┐
        ▼                               ▼                               ▼
  storage/  bootstrap/cache/      secret file                     app/ config/
  daengtisiams — READ/WRITE       root:daengtisiams 0640          routes/ scripts/
                                  READ only                       root:root — NO WRITE

co-tenant (www-data)  ──▶  secret file            DENIED
                      ──▶  storage/app/private    DENIED
                      ──▶  backups/*.sql          DENIED
```

Queue worker and `daengtisiams-pilot-snapshot.service` run as `daengtisiams`.
The distribution default `[www]` pool on PHP 8.3 is retired.

---

## 2. Commands

All of these are safe to run at any time. Only `--apply` changes anything.

| Purpose | Command |
|---|---|
| Show what provisioning *would* do (default) | `bash scripts/provision-runtime-identity.sh` |
| Provision / converge the identity | `bash scripts/provision-runtime-identity.sh --apply` |
| Verify isolation (read-only, host-strict) | `bash scripts/verify-runtime-isolation.sh --require-host` |
| Verify without host strictness | `bash scripts/verify-runtime-isolation.sh` |
| Emergency recovery to the shared runtime | `bash scripts/provision-runtime-identity.sh --rollback --apply` |

`provision-runtime-identity.sh` is **idempotent**: re-running converges. It never
creates a duplicate user, group, pool or unit.

### Forbidden here

Never run a destructive database command as part of runtime-identity work — no
schema drop, no database reset or refresh, and no data restore performed "to fix
permissions". They destroy clinical data and are not part of any procedure in
this document. Ownership problems are fixed with ownership commands.

Never do this either:

```
# WRONG — destroys the trust boundary that makes this sprint work.
chown -R daengtisiams:daengtisiams /var/www/asia-dental-lab-v2
```

That would make the runtime able to rewrite application source, deploy scripts and
`vendor/`. Only `storage/` and `bootstrap/cache/` are runtime-owned.

---

## 3. First-time provisioning (production)

Order matters. Step 1 is the reason this is a runbook and not a one-liner.

1. **Get the code on the host first.** The deploy runner executes
   `scripts/deploy-vps.sh` from disk while `git pull` may rewrite it mid-run, so
   the merged commit must already be checked out before the runner starts:

   ```bash
   cd /var/www/asia-dental-lab-v2
   git fetch origin
   git checkout <base-branch> && git pull --ff-only origin <base-branch>
   git rev-parse HEAD        # must equal the merge SHA
   ```

   This pre-pull runs no migration and no service change.

2. **Dry run, and read it.**

   ```bash
   bash scripts/provision-runtime-identity.sh
   ```

3. **Provision.** Takes a short maintenance window (`php artisan down`/`up` is
   handled by the script, including on failure via an EXIT trap).

   ```bash
   bash scripts/provision-runtime-identity.sh --apply
   ```

4. **Deploy normally.** From now on the deploy resolves the identity explicitly
   and fails closed if it is missing:

   ```bash
   cd /var/www/asia-dental-lab-v2
   bash scripts/deploy-vps-runner.sh start
   ```

   Wait for `exit=0` **and** `DEPLOY OK`. A launcher that is still `RUNNING`,
   `QUEUED` or `DETACHED` is not a finished deploy.

> `deploy-vps-runner.sh` runs **on the VPS only**. Never from a workstation, a
> worktree, or a CI runner.

---

## 4. Acceptance matrix

Run on the host. Every line must hold.

```bash
cd /var/www/asia-dental-lab-v2
bash scripts/verify-runtime-isolation.sh --require-host
```

| Check | Expected |
|---|---|
| `id daengtisiams` | exists, no `sudo`/`docker`/`adm`/`www-data` |
| `getent group daengtisiams` | no foreign members |
| FPM pool user/group | `daengtisiams:daengtisiams` |
| FPM socket | `/run/php/php8.3-fpm-daengtisiams.sock` exists |
| Default `[www]` pool on 8.3 | absent (`.disabled`) |
| nginx `fastcgi_pass` | the dedicated socket |
| `systemctl show daengtisiams-queue-worker.service -p User -p Group` | `daengtisiams` |
| Secret file | `root:daengtisiams`, mode `640`, no ACL |
| `sudo -u www-data test -r .env` | **non-zero** (denied) |
| `sudo -u www-data test -r storage/app/private` | **non-zero** (denied) |
| `sudo -u daengtisiams test -r .env` | zero (allowed) |
| `sudo -u daengtisiams test -w storage/framework` | zero (allowed) |
| `sudo -u daengtisiams test -w artisan` | **non-zero** (denied) |
| `sudo -u daengtisiams test -w scripts/deploy-vps.sh` | **non-zero** (denied) |

Application health:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/login
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/health/live
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/health/ready
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/health/lb
php artisan queue:failed
```

---

## 5. Troubleshooting

**Site returns 502 after provisioning.**
nginx is pointing at a socket the pool is not listening on.

```bash
grep fastcgi_pass /etc/nginx/sites-available/asia-dental-lab
ls -la /run/php/
systemctl status php8.3-fpm --no-pager
```

Fix the `fastcgi_pass` target, `nginx -t`, then `systemctl reload nginx`.

**Site returns 500, logs show permission denied on `storage/` or
`bootstrap/cache`.**
Something wrote runtime artefacts as the wrong identity (classically: a root-run
`php artisan` after the ownership normalisation).

```bash
chown -R daengtisiams:daengtisiams storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 2775 {} +
find storage bootstrap/cache -type f -exec chmod 0664 {} +
runuser -u daengtisiams -- php artisan optimize:clear
runuser -u daengtisiams -- php artisan config:cache
systemctl reload php8.3-fpm
```

**Config cache looks empty / login fails after a change to the secret file.**
The runtime lost group read. `phpdotenv`'s `safeLoad()` swallows the permission
error and yields an empty environment, so the symptom is a *silently* empty
application key and empty database credentials rather than a crash.

```bash
bash scripts/harden-secret-permissions.sh verify \
  --app-dir /var/www/asia-dental-lab-v2 --owner root --group daengtisiams
```

**Deploy aborts with "Refusing to guess the runtime user".**
Correct behaviour: the authority is missing or the account does not exist. Do not
work around it by exporting `RUNTIME_USER`. Provision the identity.

**Queue worker will not start.**

```bash
systemctl status daengtisiams-queue-worker.service --no-pager
journalctl -u daengtisiams-queue-worker.service -n 50 --no-pager
ls -ld storage/logs storage/framework/cache
```

Usually ownership: the unit runs as `daengtisiams` but a path is still owned by
the previous identity. Re-run the ownership block above.

---

## 6. Emergency recovery

If the dedicated identity breaks production and cannot be fixed in place:

```bash
bash scripts/provision-runtime-identity.sh --rollback --apply
```

This restores the shared `www-data` runtime **and keeps the secret at
`0640`** — it never restores a world-readable secret.

After a rollback:

- co-tenant isolation is **not** in place;
- INFRA-SEC-RUNTIME-1 is **WATCH / NO-GO**, not GO;
- record why, and re-establish the dedicated identity before claiming closure.

---

## 7. Standing rules

1. DaengtisiaMS must have a dedicated Unix runtime identity and must never share
   its runtime uid with a co-tenant application.
2. Deployment must never resolve the runtime user by taking the first FPM pool or
   process it finds. Missing or mismatched identity is fail-closed.
3. No fallback to `www-data`. Ever.
4. The runtime may write only `storage/` and `bootstrap/cache/`. Application
   source, deploy scripts and `vendor/` stay deploy/root owned.
5. Secret and private clinical paths are checked with `test -r`, never by reading
   contents.
6. ACLs and supplementary groups must not reintroduce cross-application access;
   `www-data` must never join the `daengtisiams` group.
7. A future co-tenant application must not be placed in the `daengtisiams` group
   for convenience.
8. `deploy-vps-runner.sh` is started on the production VPS only, and a start is
   not a completion — require `exit=0` and `DEPLOY OK`.
