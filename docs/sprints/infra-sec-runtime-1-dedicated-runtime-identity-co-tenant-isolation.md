# INFRA-SEC-RUNTIME-1 — Dedicated Runtime Identity & Co-Tenant Isolation

**Branch:** `feature/infra-sec-runtime-1-dedicated-runtime-identity-co-tenant-isolation`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `19d18a41e97a90a54bb8ce4b0145a935545a2a78`
**Type:** SECURITY_FIX · deploy/host automation only
**Runbook:** `docs/runbooks/dedicated-runtime-identity-runbook.md`
**Rule mirror:** `.cursor/rules/92-dedicated-runtime-identity-co-tenant-isolation.mdc`

---

## 1. Why this sprint exists

`INFRA-SEC-ENV-1` removed world-read from the production secret file (`0644` →
`0640 root:www-data`) and closed its own scope correctly. It explicitly did **not**
claim same-UID isolation, and said so in its own commit message:

> Known residual, deliberately not claimed as closed: the co-tenant application
> runs under the SAME www-data account, so a file mode cannot stop same-UID read.

That residual is what this sprint closes.

**`INFRA-SEC-ENV-1` remains CLOSED / IMMUTABLE / GO.** It was a valid, correct
improvement under the architecture that existed at the time. This sprint does not
weaken any of its invariants — it re-asserts them, including across a rollback.

Correct relationship:

```
INFRA-SEC-ENV-1     removed world-readable secret exposure
INFRA-SEC-RUNTIME-1 creates application-to-application runtime isolation
```

---

## 2. Production finding (srv1730088, Ubuntu 24.04.4)

Measured, not assumed:

| Component | Before | uid |
|---|---|---|
| DaengtisiaMS PHP-FPM pool | `[www]` — the **distribution default** pool, PHP 8.3 | `33 www-data` |
| aish-pos PHP-FPM pool | `[aish-pos]`, PHP 8.5, own socket | `33 www-data` |
| DaengtisiaMS queue worker | `daengtisiams-queue-worker.service` | `33 www-data` |
| DaengtisiaMS snapshot unit | `daengtisiams-pilot-snapshot.service` | `33 www-data` |
| nginx workers | — | `33 www-data` |
| Secret file | `root:www-data 0640` | — |
| Private clinical storage | `www-data:www-data`, world-traversable dirs | — |

`getent passwd daengtisiams` → **no such user**. `www-data` uid 33, gid 33, in no
supplementary group. No ACLs anywhere (the `acl` package is not even installed,
and no mode string carries a `+`).

**Verdict before this sprint:**

```
world isolation                      PASS   (INFRA-SEC-ENV-1)
application-to-application isolation FAIL / NOT PROVIDED
```

A file mode cannot stop a read by the *same uid*. Any code executing as
`www-data` — including the co-tenant pool — could read the DaengtisiaMS secret
and every file under `storage/app/private` (lab workflow evidence, patient
documents, legacy patient imports, KTP scan staging).

### 2.1 Second, independent defect found in the same area

`scripts/deploy-vps.sh` resolved its runtime user like this:

```bash
RUNTIME_USER="$(grep -rhoE '^\s*user\s*=\s*[A-Za-z0-9_.-]+' /etc/php/*/fpm/pool.d/ \
  | grep -vE '^\s*;' | head -1 | sed -E 's/.*=\s*//')"
# ...then a process-table guess, then:
[ -n "$RUNTIME_USER" ] || RUNTIME_USER="www-data"
```

This only ever *looked* correct because every pool on the host ran as `www-data`.
It is a directory-walk race dressed as configuration: the glob spans **every PHP
version** (`/etc/php/8.3/...` and `/etc/php/8.5/...`, the latter being the
co-tenant's), and `head -1` takes whichever `user =` line the walk happens to
return first. The moment DaengtisiaMS gained a dedicated pool, this could resolve
the **co-tenant's** identity and chown DaengtisiaMS's runtime to it.

Both the process-table probe and the `www-data` default share the same flaw: they
guess, and a guess that lands on a shared account is exactly the vulnerability
being closed.

---

## 3. What was built

### 3.1 One explicit authority — `deploy/runtime-identity.conf`

Shell-sourceable `KEY=value`, committed, contains no secrets. It is the single
answer to "which OS principal runs DaengtisiaMS", consumed by the deploy script,
the rollback script, the provisioning script and the verifier.

```
DMS_RUNTIME_USER=daengtisiams
DMS_RUNTIME_GROUP=daengtisiams
DMS_FORBIDDEN_RUNTIME_USERS="root www-data nobody daemon postgres"
DMS_FPM_POOL=daengtisiams
DMS_FPM_SOCKET=/run/php/php8.3-fpm-daengtisiams.sock
DMS_SECRET_OWNER=root
...
```

Resolution is **fail closed**. A missing or unreadable authority, an undeclared
identity, a forbidden identity, a missing OS account, or a primary-group mismatch
aborts the deploy with `exit 2`. There is no fallback of any kind.

### 3.2 Dedicated identity, pool, socket and services

- **`daengtisiams`** — system account: no password, no home, `/usr/sbin/nologin`,
  no sudo/docker/adm/staff membership, primary group `daengtisiams` with no other
  members. Provisioning refuses to co-opt a pre-existing interactive account of
  the same name.
- **`deploy/php-fpm/daengtisiams.conf`** — dedicated pool on its own socket
  `/run/php/php8.3-fpm-daengtisiams.sock`. Process-manager sizing matches the
  `[www]` pool it replaces and **no `php_admin_value` limit is declared**, so
  `php.ini` (`memory_limit`, `upload_max_filesize`, `post_max_size` — which the
  Legacy RME PDF upload path depends on) is inherited exactly as before. This
  changes identity, not behaviour.
- The distribution default `[www]` pool on PHP 8.3 is **retired** (renamed to
  `.disabled`) once nginx points at the dedicated socket, so no `www-data` worker
  is left able to execute DaengtisiaMS code.
- **Queue worker** and **`daengtisiams-pilot-snapshot.service`** run as
  `daengtisiams`. The tracked unit source declares it, so a reinstall from the
  repository can never restore the shared account.
- **nginx keeps running as `www-data`** and only its `fastcgi_pass` is rewritten,
  in place. `listen 80 default_server` — which keeps DaengtisiaMS reachable on
  this shared host — is preserved untouched.

### 3.3 aish-pos is not modified

Its pool, socket, files and data are untouched. The goal is to isolate
DaengtisiaMS *from* it, not to redesign it.

### 3.4 Filesystem model

The tree is **not** treated with one ownership rule:

| Class | Owner | Runtime access |
|---|---|---|
| Application source (`app/`, `config/`, `routes/`, `artisan`, `scripts/`, `vendor/`) | `root:root` | **read only — write DENIED** |
| Runtime-writable (`storage/`, `bootstrap/cache/`) | `daengtisiams:daengtisiams`, dirs `2775`, files `0664` | read/write |
| Secret file | `root:daengtisiams 0640` | read only |
| Private clinical storage | `daengtisiams`, world bits removed | read/write |
| Database dumps under `backups/` | `root:root 0640` | **DENIED** |

There is deliberately **no** `chown -R` of the checkout. The runtime cannot
rewrite its own code, its deploy scripts, or its configuration — that boundary is
the point, and a blanket recursive chown would destroy it.

### 3.5 Durable enforcement, not a one-time fix

`scripts/verify-runtime-isolation.sh` is read-only and proves the whole matrix. It
never creates, moves, deletes, chowns or chmods anything, never restarts a
service, never prints a secret or a patient file, and never runs a database
command. Access is proven with `test -r` / `test -w` on metadata only.

It is wired as a **required marker** in `config/deployment_rollback.php` for both
the deploy and rollback contracts, so removing the call fails
`foundation:deployment-rollback-check` in CI and on the VPS — the same durable
pattern INFRA-SEC-ENV-1 used for its hardening helper.

`--require-host` makes "I could not inspect this host fact" a **FAIL**, so the
production path can never report GO on evidence it did not actually see.

---

## 4. Contract changes to sibling foundations

| Contract | Before | After | Why |
|---|---|---|---|
| `config/deployment_rollback.php` deploy + rollback `required_markers` | `chown -R www-data:www-data` | `chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}"` + `verify-runtime-isolation.sh` | The old literal pinned the contract to the co-tenant-shared account. The new marker proves the deploy chowns to the *resolved* identity. |
| `config/enterprise_foundation_runtime_hardening.php` `queue_worker.service_user` | `www-data` | `daengtisiams` | A worker processing clinical documents must not run on a shared uid. |
| `PostEntFoundationRuntimeHardeningTest` | asserts `User=www-data` | asserts `User=daengtisiams`, `Group=daengtisiams`, and **not** `User=www-data` | Sibling repin. |
| `DeployRuntimePermissionsScriptTest` | asserts the script contains `php-fpm` | asserts explicit authority + forbidden-identity check | That assertion encoded the pool-scan heuristic, which is the defect. |

CLAUDE.md records a prior CI trap here: a previous sprint parameterised this
`chown` and dropped the required literal, failing
`foundation:deployment-rollback-check`. This sprint changes the marker
**deliberately and coherently** — config, both scripts, and the tests move
together, and the gate is green locally.

---

## 5. Transition safety

A filesystem-ownership mistake here produces a production 500 or a lost queue.
The cutover is therefore ordered and guarded:

1. Preflight: root, app dir, authority, name availability, binaries present.
2. Create group + system user; assert least privilege.
3. Install the dedicated pool; `php-fpm8.3 -t` **PASS** required.
4. Stage the nginx `fastcgi_pass` rewrite (backup first); `nginx -t` **PASS**
   required — validated but *not yet reloaded*.
5. `php artisan down` — maintenance mode. The maintenance page is served by a
   plain file read in `public/index.php` before the framework boots, so it keeps
   working while ownership is mid-flip. An `EXIT` trap lifts maintenance even if
   a later step fails.
6. Move ownership of the runtime-writable paths; strip world bits from private
   storage; transition the secret group **via the INFRA-SEC-ENV-1 helper** (this
   script never chmods a secret itself); restrict legacy `backups/*.sql`.
7. Retire the shared default pool; `php-fpm8.3 -t`; reload php-fpm; `nginx -t`;
   reload nginx — back to back.
8. Install units, `daemon-reload`, restart the worker.
9. Rebuild the Laravel cache **as** the new runtime user (the same rule
   `FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS` established — a cache written by the
   wrong identity is exactly what caused the historical post-login 500).
10. `php artisan up`, then run the verifier with `--require-host`.

The only unavoidable interruption is the socket rename between the two reloads in
step 7 — a sub-second 502, taken under maintenance mode rather than a 500.

`--apply` is required for any change; **dry run is the default**.

---

## 6. Rollback

`scripts/provision-runtime-identity.sh --rollback --apply` restores the previous
shared-identity runtime so the site can serve.

It **preserves INFRA-SEC-ENV-1**: the secret is re-hardened to `root:<group>
0640`, never restored to `0644`.

Reaching this path means co-tenant isolation is not in place, so:

> **A rollback to the shared runtime makes INFRA-SEC-RUNTIME-1 `WATCH / NO-GO`,
> never GO.** The script says so on stdout.

Separately, `scripts/rollback-vps.sh` (code rollback) now resolves the identity
**before** the checkout and stages the verifier in a private `0700` temp dir, so
rolling the *code* back to a ref that predates this sprint can neither hand secret
read back to the shared account nor silently drop the isolation gate.

---

## 7. Accepted residuals (stated, not hidden)

1. **nginx runs as `www-data` and must connect to the FastCGI socket.** Socket
   connect permission is a different privilege from secret read: `www-data` can
   hand a request to the pool, the request executes as `daengtisiams`, and
   `www-data` can no longer read the secret or the private storage. But because
   nginx itself is `www-data`, any process with that uid can also reach the
   socket. Closing that requires a per-application reverse-proxy identity, which
   is out of scope here and does not re-open secret read.
2. **`root` can read everything.** Deployment authority stays root; this sprint
   isolates the *application runtime*, not the deploy user.
3. **`aish-pos` currently has no nginx server block** on this host (`nginx -T`
   shows only the DaengtisiaMS site; `/etc/nginx/conf.d/` is empty; no
   `aish-pos` application directory was found; Docker has no containers). Its FPM
   pool is running but nothing routes to it. It is left untouched — and it remains
   a live same-uid execution surface, which is precisely why isolation is still
   the right fix rather than a no-op.

---

## 8. Explicitly out of scope

Rewriting aish-pos · containerisation · a new VPS · network segmentation ·
database-role redesign · credential rotation without evidence of compromise ·
changing the nginx worker identity · Legacy Wave-2 · clinical timezone.

`FEATURE_RME_LEGACY_PDF_ARCHIVE` stays `false`, capability OFF, admission empty,
no active wave. This sprint authorises **no** clinical mutation.

---

## 9. Verification

**CI cannot reproduce a real `/etc/php`, systemd, or Unix account.** Verification
is therefore split, and neither half is claimed as the other:

- **CI / local** — `tests/Feature/Deploy/RuntimeIdentityIsolationTest.php`:
  contract assertions plus a functional negative matrix that runs the real
  verifier against synthetic fixtures in a private `0700` temp dir. Every way the
  isolation can break must FAIL, not pass quietly.
- **Production** — the same script with `--require-host`, plus the live
  `runuser` co-tenant denial proof, run on the VPS during and after deploy.

Local: 26 passed. `tests/Feature/Deploy` 64 passed. Governance gates
`deployment-rollback` / `queue-worker-runtime` / `runtime-hardening` /
`ent-1-4-audit` / `cicd-enterprise-gate` / `security-compliance` /
`enterprise-closure` / `ci-runtime-control --strict` all **GO**.
