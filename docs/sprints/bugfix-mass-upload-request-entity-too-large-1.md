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
