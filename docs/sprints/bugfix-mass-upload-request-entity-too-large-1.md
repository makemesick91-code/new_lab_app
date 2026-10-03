# BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1

**Branch** `bugfix/mass-upload-request-entity-too-large-1`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline** `feature-legacy-rme-odontogram-mass-upload-1-go` @ `0de6c1c4a1adabf0795caa69a0c0f406da7fa43e`
**GO tag on merge** `bugfix-mass-upload-request-entity-too-large-1-go`

Production HEAD, the canonical base tip and the Mass Upload GO tag were all the
same commit when this began, so there was no drift to reconcile first.

No migration. No permission, role, route or schema change. No clinical
behaviour, no publish path, no branch isolation, no ledger.

---

## 1. Symptom

Mass Upload Legacy RME returned **`Request Entity Too Large`**.

## 2. Root cause — measured, not guessed

The production nginx error log named the layer, the status and the exact size:

```
2026/10/03 10:22:28 [error] 2072012#2072012: *874 client intended to send too
large body: 2860825 bytes, client: 103.175.222.229, server: daengtisia.online,
request: "POST /settings/rme/legacy-mass-imports HTTP/1.1",
referrer: "https://daengtisia.online/settings/rme/legacy-mass-imports/create"
```

```
103.175.222.229 - - [03/Oct/2026:10:22:31 +0000]
"POST /settings/rme/legacy-mass-imports HTTP/1.1" 413 192
```

- **Rejecting layer: nginx.** Status **413**, response body 192 bytes — nginx's
  own error page.
- **Failed package: 2 860 825 bytes (2.73 MiB)** of multipart body.
- The request **never reached PHP or Laravel**, which is why no application log
  entry and no application test could have shown it.

The decisive measurement:

```
$ sudo nginx -T | grep -c client_max_body_size
0
```

`client_max_body_size` was declared **nowhere** — not at `http`, not at
`server`, not at `location`. nginx was therefore enforcing its **compiled
default of 1 MiB**, and nginx — not the application — was deciding what an
operator may upload.

### Not a Mass Upload bug

The same 1 MiB default had already refused two other clinical upload paths:

| Date | Path | Body | Status |
|---|---|---|---|
| 2026-10-03 | `POST /settings/rme/legacy-mass-imports` | 2 860 825 B | 413 |
| 2026-09-02 | `POST /settings/rme/legacy-imports` (single item) | 1 189 484 B | 413 |
| 2026-07-10 | `POST /lab/workflow-requests` | 5 333 569 B | 413 |

One shared infrastructure gap, three visible symptoms. The fix is scoped to the
DaengtisiaMS `server` block, so it closes all three.

### Ruled out: there is no third party in front of nginx

A CDN or load balancer would impose its own body cap (Cloudflare's free tier caps
uploads at 100 MB, which would silently defeat a 512 MiB ceiling), so this was
checked rather than assumed:

```
$ getent hosts daengtisia.online
145.79.13.224   daengtisia.online          # the VPS's own public IP

$ curl -sSI https://daengtisia.online/login | grep -iE '^(HTTP|server|via|cf-|x-cache)'
HTTP/1.1 200 OK
Server: nginx/1.24.0 (Ubuntu)
```

No `Via`, no `CF-*`, no `X-Cache`, and the A record is the origin itself. LB-1
independently reports `single_vps_ready` with `LB_TRUSTED_PROXIES` empty. The
request path is exactly **nginx → PHP-FPM → Laravel**, so nginx is the outermost
HTTP layer and there is no fourth ceiling above the 512 MiB one.

### A second layer was also below the application

| Layer | Limit | Failed package (2 860 825 B) | Verdict |
|---|---|---|---|
| nginx `client_max_body_size` | **1 MiB** (compiled default) | 2 860 825 | **FAIL — first rejector** |
| PHP-FPM `upload_max_filesize` | **2M** (stock php.ini) | ~2.86 MB ZIP part | **would FAIL next** |
| PHP-FPM `post_max_size` | 8M (stock php.ini) | 2 860 825 | pass |
| Application `package.max_bytes` | 500 MiB | 2 860 825 | pass |

Fixing only nginx would have moved the failure one layer down, where PHP
silently discards the uploaded file and Laravel reports the misleading
*"Paket arsip ZIP wajib diunggah."* Both layers had to move.

The pool file's own header had asserted the opposite for months — that php.ini
carried "the limits which the Legacy RME PDF upload path depends on". php.ini
held the stock Debian defaults and had never been tuned. That claim is corrected
in place rather than left to mislead the next reader.

## 3. The application stays the authority

`config/legacy_mass_upload.php` → `package.max_bytes` (500 MiB) is canonical and
is **not changed**. The runtime is raised to sit just *above* it so that an
oversize package reaches Laravel and earns the application's own reasoned
refusal — `LegacyMassUploadReason::PACKAGE_TOO_LARGE` /
*"Ukuran paket arsip melebihi batas yang diizinkan."* — instead of a status code.

| Package size | Outcome |
|---|---|
| ≤ 500 MiB | accepted; preflight runs |
| 500–512 MiB | reaches Laravel → *"Ukuran paket arsip melebihi batas yang diizinkan."* |
| > 512 MiB | nginx 413 (bounded, honest) |

nginx is pinned to the **same** 512 MiB as `post_max_size` so nginx is always the
outer bound. If PHP were the one to refuse an oversize POST it would discard
`$_POST`, the CSRF token would go with it, and the operator would be told
"Page Expired" (419) — a message about sessions for a problem about size.

## 4. Limits before and after

| Layer | Before | After |
|---|---|---|
| nginx `client_max_body_size` | 1 MiB (compiled default, declared nowhere) | **512m** (declared, `server`-scoped) |
| FPM `upload_max_filesize` | 2M (inherited php.ini) | **500M** (`php_admin_value`) |
| FPM `post_max_size` | 8M (inherited php.ini) | **512M** (`php_admin_value`) |
| FPM `max_input_time` | 60 | **900** (`php_admin_value`) |
| FPM `memory_limit` | 128M (inherited php.ini) | **256M** (`php_admin_value`) |
| FPM `max_execution_time` | 30 | **30 — unchanged pool-wide** |
| Intake execution budget | n/a | **900s, route-scoped at runtime** |
| App `package.max_bytes` | 500 MiB | **500 MiB — unchanged (canonical)** |

Relationship holds: `nginx (512 MiB) ≤ post_max_size (512 MiB) > upload_max_filesize (500 MiB) ≥ package.max_bytes (500 MiB)`.

### Nothing is unlimited

`client_max_body_size 0` and `memory_limit = -1` are forbidden and asserted
against. Every archive-safety ceiling is **untouched and asserted untouched**:

| Control | Value |
|---|---|
| `document_max_bytes` | 20 MiB |
| `manifest_max_bytes` | 5 MiB |
| `max_entries` | 1200 |
| `total_uncompressed_max_bytes` | 2 GiB |
| `max_compression_ratio` | 120.0 |

Transport ceilings and archive-safety ceilings are independent controls. Zip
Slip, symlink entries, magic-byte validation, private storage and deterministic
cleanup are unaffected.

### Why the time budget is scoped two different ways

`max_execution_time` is `PHP_INI_ALL`, so the intake route raises it for *itself*
(`legacy_mass_upload.intake.max_execution_seconds`, default 900, bounded, 0
ignored rather than treated as unlimited) and every other request on the pool
keeps the 30s ceiling — a slow page or a runaway query is still cut off.

`upload_max_filesize`, `post_max_size` and `max_input_time` are `PHP_INI_PERDIR`
and **cannot** be set per route. That is the only reason they live at pool level.

Raising `max_input_time` is low risk because nginx buffers the request body in
full before handing it to the pool (`fastcgi_request_buffering` is on by
default), so the budget is spent reading a local buffer rather than waiting on a
clinic uplink. A stalled client remains bounded by nginx's own
`client_body_timeout`.

Headroom checked rather than assumed: 89 GB free on `/` (which also carries
`/tmp`, where PHP buffers uploads), 7.0 GiB RAM available, `pm.max_children = 5`
— so the raised `memory_limit` caps this pool at ~1.25 GiB.

The raised `memory_limit` is **not** for the package body. The intake path was
read rather than assumed about, and it is streamed end to end:
`hash_file('sha256', ...)` over the upload, `$disk->put($path, $stream)` into the
private workspace, and extraction via `ZipArchive::getStream()` consumed in
256 KiB `fread` chunks, with magic-byte validation reading 5 bytes. A package the
size of the ceiling is therefore never held in memory. The headroom is for the
ZipArchive central directory of up to 1200 entries plus framework overhead.

## 5. Config as code — no hidden drift

`deploy/php-fpm/daengtisiams.conf` was already repo-managed and matched
production byte-for-byte:

```
REPO_SHA256=fe0dc62174f5ccd792457d63fb6096a8b7817cc0043ad0c1dc8761fad7f71833
VPS_SHA256=fe0dc62174f5ccd792457d63fb6096a8b7817cc0043ad0c1dc8761fad7f71833
```

The ceilings therefore go **there**, with `php_admin_value`, rather than into a
hand-edited php.ini this repository does not manage.

Production nginx is not deployed from this repository, so
`deploy/nginx/upload-body-size.conf` is the version-controlled record and carries
its own one-time apply and verify instructions — the existing
`deploy/nginx/pwa-manifest-mime.conf` pattern. It is `include`d into the
DaengtisiaMS `server` block only, so the catch-all `default_server`, the
HTTP→HTTPS redirect blocks and any co-tenant virtual host on this shared VPS
keep nginx's 1 MiB default.

## 6. Files changed

| File | Change |
|---|---|
| `deploy/nginx/upload-body-size.conf` | **new** — `client_max_body_size 512m;` + apply/verify record |
| `deploy/php-fpm/daengtisiams.conf` | four `php_admin_value` ceilings; false "PARITY" claim corrected |
| `config/legacy_mass_upload.php` | **new** `intake.max_execution_seconds` (bounded, route-scoped) |
| `.../MassUpload/Controllers/LegacyMassUploadController.php` | `grantIntakeExecutionBudget()` on `store()` |
| `tests/Feature/LegacyMassUpload/LegacyMassUploadRequestEntityLimitTest.php` | **new** — 18 tests |
| `.cursor/rules/100-legacy-mass-upload.mdc` | durable rules §21–§23; globs widened to `deploy/` |
| `docs/runbooks/legacy-mass-upload-operator-runbook.md` | 413 diagnosis section |
| `.sprint/current.yml` | manifest |

## 7. Tests

`tests/Feature/LegacyMassUpload/LegacyMassUploadRequestEntityLimitTest.php` — 18
tests / 60 assertions.

The archive-safety suites already proved Zip Slip, symlinks, bomb ceilings,
magic bytes and per-document caps. **None of them could fail for this bug,
because none of them can see the runtime.** These tests assert the one property
that was missing: every runtime ceiling sits at or above the application's, and
all of them stay bounded. They read the repository-managed runtime records, so a
future edit that re-lowers a ceiling fails here rather than in a clinic.

Comment lines are excluded when parsing those records — a ceiling documented in a
comment must not satisfy an assertion that it is set.

### Mutation-proved

With the two runtime records reverted to their exact pre-fix production state
(nginx directive absent, pool ceilings back to inherited php.ini), **6 of the 18
fail**. The suite detects the real defect rather than describing it.

### A defect CI caught that no local run could

The first version of `grantIntakeExecutionBudget()` called
`@set_time_limit(900)` unconditionally. `set_time_limit()` does **not** extend a
budget — it REPLACES it and restarts the counter — and the **CLI SAPI defaults
`max_execution_time` to 0, meaning unlimited**. So one feature test POSTing to
the intake route armed a 900-second kill timer on the entire Pest process, and
the critical gate died with:

```
PHP Fatal error: Maximum execution time of 900 seconds exceeded
Pest\Exceptions\FatalException
runner=github-hosted   critical_test_exit_status=1
```

The fatal landed in an unrelated suite long after the Mass Upload tests had
passed, which is what made it look like someone else's problem. It was not: 900
is this sprint's number.

No local run could have shown it. The targeted suites took 11s and 50s, and the
full legacy regression took 731s — all inside the 900s the bug itself installed.

The method now **raises only**: it reads `ini_get('max_execution_time')` and
returns early when the ambient limit is `0` (unlimited) or already at least as
generous. Three tests pin this, and reverting to the unconditional call fails two
of them.

### Two assumptions the tests corrected

- A refused package **does** leave a `LegacyMassUploadBatch` row — it is the
  provenance record of a refusal, transitioned to `PACKAGE_REJECTED`. The first
  draft asserted no row existed; the real contract is asserted instead.
- The minimal fixture archive is ~510 bytes, which rounds to one kilobyte and
  made every boundary assertion degenerate. Boundary tests now pad the document
  with **incompressible** bytes; padding with repeated bytes would be squeezed
  back out by deflate.

## 8. Deploy

Repository files changed, so this deploys through the canonical path. Two
one-time host steps are required and are **not** performed by the deploy script.

1. Deploy (**on the VPS only**): `bash scripts/deploy-vps-runner.sh start`
2. Install the pool file and reload FPM (idempotent; the provisioning script is
   the canonical installer).
3. `include` the nginx record in the DaengtisiaMS `server` block, then
   `sudo nginx -t && sudo systemctl reload nginx`.

No migration. No seeder. No permission reseed.

### Verify

```
sudo nginx -T | grep client_max_body_size                 # 512m
sudo php-fpm8.3 -tt 2>&1 | grep -iE 'upload_max|post_max' # 500M / 512M
```

Then upload the previously failing package to **preflight only**. It must reach
the review screen. Do **not** confirm or dispatch: that would publish clinical
records.

---

## 9. Deploy evidence (2026-10-03)

**Merged** PR #441 squash-merged as `db96df67c3b1081192ea67f59ec27041288f98a7`.
**GO tag** `bugfix-mass-upload-request-entity-too-large-1-go` @ `db96df67` (annotated).

**CI** run `37122250633` **success** on the exact merged tree SHA
`14dd6001fbb4b3cc4f75ca2a6d32e77560d54c99`: CICD-CTRL Gate Classifier, NSF-R012
Quality, CICD-CTRL Selective Module, **NSF-R011 Critical Test Gate (93 min)**,
NSF-9 Release Safety & Automated Smoke, NSF-10 Release Evidence — all pass.
NSF-R011 Full Suite skipped per the standing policy; the second
`NSF-R011 Critical Test Gate` row is the inactive CICD-CTRL-3 variant
(`CI_RUNNER_MODE=github-hosted`), not a bypass.

The earlier run `37117726124` on `5636c944` failed — see §7, the
`set_time_limit()` defect. That failure was this sprint's own and is recorded
rather than attributed elsewhere.

**Deployed** on the VPS via `bash scripts/deploy-vps-runner.sh start` (detached,
SSH-safe). VPS was at `0de6c1c4` before.

```
exit=0
DEPLOY OK: 20261003-140054
DEPLOY_HEAD_TARGET_MATCH=YES (db96df67c3b1081192ea67f59ec27041288f98a7)
DEPLOY_SNAPSHOT_CLEANED=YES
```

Automated smoke 6 passed / 1 warning / 0 errors. The warning is
`SMOKE-HTTP-HEALTH: http://127.0.0.1/login returned 404` — the nginx
"Unknown HTTP hosts → deny" `default_server` block answering a localhost `Host`
header. Not a regression; the canonical domain returns 200 (below).

### Host steps (one-time; the deploy script performs neither)

**1. FPM pool.** Backed up to
`/etc/php/8.3/fpm/pool.d/daengtisiams.conf.pre-413-fix.20261003-141022`, then
installed with `install -o root -g root -m 0644` from the repo file — the
provisioning script's isolated pool step, deliberately **not** a full
`provision-runtime-identity.sh --apply`, which would also recreate users, rebind
nginx's `fastcgi_pass` and restart the queue worker. `php-fpm8.3 -t` passed
before `systemctl reload php8.3-fpm`. Installed file `sha256` matches the repo.

**2. nginx.** Backed up to
`/etc/nginx/sites-available/asia-dental-lab.pre-413-fix.20261003-141109`, then
the include was added after the single `pwa-manifest-mime.conf` anchor, inside
the canonical 443 block. `nginx -t` **passed** before `systemctl reload nginx`
(reload, not restart).

### Effective limits on production, read from the runtime

```
$ sudo php-fpm8.3 -tt | grep -iE 'upload_max|post_max|max_input_time|memory_limit'
php_admin_value[memory_limit]       = 256M
php_admin_value[max_input_time]     = 900
php_admin_value[post_max_size]      = 512M
php_admin_value[upload_max_filesize] = 500M
                                      # max_execution_time absent -> stays 30

$ sudo nginx -T | grep -E '^[[:space:]]*client_max_body_size'
client_max_body_size 512m;
```

Application limits survived `config:cache`: `intake.max_execution_seconds = 900`,
`package.max_bytes = 524288000`, and every archive-safety ceiling unchanged —
`max_entries 1200`, `total_uncompressed_max_bytes 2147483648`,
`max_compression_ratio 120`, `document_max_bytes 20971520`,
`manifest_max_bytes 5242880`.

### Behavioural proof the 413 is gone

A 2 MiB body — larger than the old 1 MiB ceiling — posted to the canonical host:

```
POST https://daengtisia.online/  -> HTTP 405  (sent 2097152 bytes)
```

nginx accepted the **whole** body and Laravel answered. An hour earlier the same
request was a 413. `POST /` is unrouted, so nothing was created.

**Honest limit of the probes:** the companion probe against the co-tenant
`default_server` returned `404` with `size_upload=0` — it short-circuits before
reading a body, so its ceiling was never exercised and that probe proves nothing
about leakage. Non-leakage is established **structurally** instead: the include
is referenced exactly once in the site file, there is exactly **one** non-comment
`client_max_body_size` directive in the entire live configuration, and a
brace-depth walk places it inside `server_name daengtisia.online`. The `http`
level, the `www` block, the port-80 redirect block and the co-tenant
`default_server` all keep nginx's 1 MiB default.

### Surfaces and logs

| Surface | Result |
|---|---|
| `https://daengtisia.online/login` | 200 |
| `/health/live`, `/health/ready`, `/health/lb` | 200 |
| `/settings/rme/legacy-mass-imports` (+ `/create`) | 302 auth redirect |
| `/settings/rme/legacy-mass-odontograms` | 302 |
| `/settings/rme/legacy-imports` (single item) | 302 |
| `/dashboard` | 302 |

`env=pilot`, debug OFF, maintenance OFF. Queue worker active, `queue:failed`
empty. HEAD `db96df67` is an exact `git describe --exact-match` match for the GO
tag. **No Laravel log file for today → zero application errors**, and **no new
`too large body` entry in the nginx error log since the ceiling went live** — the
most recent one remains the original 10:22:28 operator failure this sprint fixed.

### Drift posture

Both ceilings are repository-managed. A future `deploy-vps.sh` run reloads nginx,
so a change to `deploy/nginx/upload-body-size.conf` takes effect without another
hand edit; a future `provision-runtime-identity.sh --apply` reinstalls the pool
from the repo file, which now carries the ceilings. The only manual step that
cannot be re-derived is the one-line `include` in the host's server block, which
is why that file documents itself.

### Not done, and why

The operator's original failing package was not re-uploaded: it is their file and
is not available here. Re-uploading a *fabricated* clinical archive to production
would create staging records and prove nothing about their data, so the
verification above is limited to the runtime ceilings and a non-clinical body
probe. When the operator retries, the package should be taken only as far as the
**preflight / review** screen — confirming or dispatching would create clinical
records.
