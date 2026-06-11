# Sprint 21 Phase 21.7 — VPS Pilot Deployment Checklist

> **Phase type:** Documentation / runbook only  
> **Deployment performed:** No — this document was created locally; no VPS commands were executed.  
> **Review required:** Read this entire runbook and confirm backup/rollback steps before any production action.

---

## 1. Executive Summary

This document is a safe, copy-pasteable deployment runbook for moving **Sprint 21 — RME Advanced Workflow + Lab Integration** to the ADLMS VPS pilot environment.

Sprint 21 adds RME → Lab staging (`LabCaseCandidate`), Admin Lab review/conversion to `LabOrder`, RME lab workflow visibility, and hardened RME print/PDF export. The VPS already runs Sprint 20 RME pilot code; this deployment extends that baseline without data loss.

**Important:**

- This phase (**21.7**) is **documentation-only**. No deployment was performed while writing this runbook.
- **Do not execute** VPS commands until local verification passes, a backup is verified, and a deployment window is approved.
- **Never** run `php artisan migrate:fresh` or `php artisan db:wipe` on the VPS pilot/production database.

| Item | Value |
|---|---|
| VPS app path | `/var/www/asia-dental-lab-v2` |
| Local project path | `~/Projects/new_lab_app` |
| GitHub repo | `makemesick91-code/new_lab_app` |
| Deployment branch | `feature/sprint-21-rme-pdf-print-hardening` |
| Deployment tag | `sprint-21-phase-21-6-rme-pdf-print-hardening` |
| Baseline commit (at runbook creation) | `327e55f` |
| VPS IP (pilot) | `145.79.13.224` |

---

## 2. Deployment Scope

The following Sprint 21 capabilities will be deployed when this runbook is executed:

| Phase | Feature |
|---|---|
| 21.1 | RME → Lab integration architecture (design baseline) |
| 21.2 | `LabCaseCandidate` generation after paid RME invoice (`requires_lab` treatments) |
| 21.3 | Admin Lab candidate queue UI (`/lab/case-candidates`) |
| 21.4 | Convert candidate to `LabOrder` with explicit `lab_service_id` |
| 21.5 | RME lab workflow visibility (invoice, receipt, LabOrder show) |
| 21.6 | RME print hardening + PDF export (`rme.visits.pdf`) |

**Also included in the deployment artifact:**

- Updated documentation (`docs/sprint_21_*`, `docs/sprint_history.md`, `CLAUDE.md`)
- Pest feature tests (not run on VPS unless explicitly chosen)

**Out of scope for this deployment:**

- Cicilan / installment payments (deferred)
- WhatsApp / notifications (deferred)
- New migrations beyond Sprint 21.2 `trx_lab_case_candidates` (verify with `php artisan migrate:status` on VPS)
- Auto-creation of `LabOrder` from RME payment (still manual conversion only)
- Lab billing / `trx_payments` from RME flows

**Expected new migration on VPS:**

- `2026_06_14_210001_create_trx_lab_case_candidates_table`

---

## 3. Non-Negotiable Production Rules

1. **Never** run `php artisan migrate:fresh` on VPS.
2. **Never** run `php artisan db:wipe` on VPS.
3. **Always** take and verify a database backup **before** `git pull` and **before** `php artisan migrate --force`.
4. **Always** verify current branch, commit, and tag before deployment.
5. **Always** use `php artisan migrate --force` (not `migrate:fresh`).
6. **Never** commit or expose `.env` secrets in the repository or runbook.
7. **Do not** delete `storage/` contents unless a verified backup exists.
8. **Keep a rollback path ready** (known-good commit + backup file path) before running migrations.
9. If backup fails or backup file is empty/suspicious → **stop deployment immediately**.
10. Put the app in maintenance mode (`php artisan down`) during migration if your ops policy requires it.

---

## 4. Required Access / Assumptions

### Required access

| Access | Purpose |
|---|---|
| SSH to VPS | Pull code, run artisan, inspect logs |
| GitHub read access | `git fetch` / `git pull` from `makemesick91-code/new_lab_app` |
| `sudo` (if needed) | Restart `php-fpm`, reload `nginx`, fix ownership |
| PostgreSQL credentials | Backup (`pg_dump`) and optional restore |
| Deployer Laravel user | Same user that owns `/var/www/asia-dental-lab-v2` or can `cd` there |

### Environment assumptions

| Assumption | Notes |
|---|---|
| App path | `/var/www/asia-dental-lab-v2` |
| Web user | Typically `www-data` (confirm on VPS) |
| PHP | 8.1+ required by Laravel 11; VPS may use `php8.3-fpm`, `php8.2-fpm`, or `php8.1-fpm` — **confirm with `php -v` on VPS** |
| Composer | Available on VPS for `composer install --no-dev` |
| Node/npm | Required if building assets on VPS (`npm ci && npm run build`) |
| Database | PostgreSQL; default name in repo backup script is `asia_dental_lab` — **confirm actual DB name in VPS `.env`** |
| DomPDF | `barryvdh/laravel-dompdf` already in `composer.json` (^3.1) |

### Credentials policy

- Store VPS passwords, DB passwords, and SSH keys **outside** this repository.
- Do not paste real credentials into tickets, commits, or this runbook.

### Open items (verify on VPS before deploy)

- [ ] Exact PHP-FPM service name (`php8.3-fpm` vs `php8.2-fpm` vs `php8.1-fpm`)
- [ ] Exact PostgreSQL database name and backup location disk space
- [ ] Whether assets are built on VPS or copied from CI (this runbook assumes build on VPS)
- [ ] HTTPS/domain configuration (pilot currently reachable at `http://145.79.13.224`)

---

## 5. Pre-Deployment Local Verification

Run on your **local machine** before approving VPS deployment:

```bash
cd ~/Projects/new_lab_app

git checkout feature/sprint-21-rme-pdf-print-hardening
git pull origin feature/sprint-21-rme-pdf-print-hardening

git status --short
git log --oneline --decorate -10
git tag --sort=-creatordate | head -20

php artisan test --filter=RME
php artisan test
./vendor/bin/pint --dirty
npm run build
```

### Expected local results

| Check | Expected |
|---|---|
| Working tree | Clean (`git status --short` empty) |
| HEAD commit | `327e55f` or newer **approved** Sprint 21 deployment commit |
| Tag present | `sprint-21-phase-21-6-rme-pdf-print-hardening` |
| RME tests | All pass |
| Full test suite | All pass |
| Pint | No dirty-file style issues |
| `npm run build` | Success (`public/build` updated) |

**Do not deploy to VPS if local tests fail.**

---

## 6. VPS Pre-Deployment Inspection

> **Label:** Run on VPS only when deploying

```bash
cd /var/www/asia-dental-lab-v2

pwd
git status --short
git branch --show-current
git log --oneline --decorate -10

php artisan --version
php -v
composer --version
node -v
npm -v

df -h
free -h

php artisan about
php artisan route:list | grep -Ei "rme|lab-case|pdf|receipt|lab-order" || true
```

### Record before proceeding

- Current branch and commit (rollback reference)
- Disk space for backup + build artifacts
- Whether working tree is clean (uncommitted VPS changes must be resolved first)
- `APP_ENV`, `APP_DEBUG` from `php artisan about` (`APP_DEBUG` should be `false` on pilot)

---

## 7. VPS Database Backup

> **Label:** Run on VPS only when deploying  
> **Stop rule:** If backup fails or file is empty → **abort deployment**.

### 7.1 Create backup directory (outside web root)

```bash
mkdir -p ~/backups/asia-dental-lab
```

### 7.2 Optional maintenance mode during backup

```bash
cd /var/www/asia-dental-lab-v2
php artisan down --render="errors::503" || true
```

### 7.3 Option A — Plain SQL dump (manual env)

Read DB name from `.env` **on VPS** (do not commit values). Example pattern:

```bash
# Replace YOUR_DB_NAME with value from DB_DATABASE in .env
BACKUP_FILE=~/backups/asia-dental-lab/asia_dental_lab_before_sprint21_$(date +%Y%m%d_%H%M%S).sql

pg_dump -h 127.0.0.1 -U postgres -d YOUR_DB_NAME > "$BACKUP_FILE"
```

PostgreSQL will prompt for password unless `~/.pgpass` is configured.

### 7.4 Option B — Laravel-assisted env load + SQL dump

```bash
cd /var/www/asia-dental-lab-v2

export $(grep -E '^(DB_DATABASE|DB_USERNAME|DB_HOST|DB_PORT)=' .env | xargs)

BACKUP_FILE=~/backups/asia-dental-lab/${DB_DATABASE}_before_sprint21_$(date +%Y%m%d_%H%M%S).sql

pg_dump -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" > "$BACKUP_FILE"
```

### 7.5 Option C — Existing repo backup script (custom format)

The repository includes `scripts/backup_postgres.sh` (produces `*.dump` via `pg_dump -F c`):

```bash
cd /var/www/asia-dental-lab-v2

export $(grep -E '^(DB_DATABASE|DB_USERNAME|DB_HOST|DB_PORT)=' .env | xargs)
BACKUP_DIR=~/backups/asia-dental-lab ./scripts/backup_postgres.sh
```

Restore companion: `scripts/restore_postgres.sh` (uses `pg_restore --clean --if-exists`).

### 7.6 Verify backup (mandatory)

```bash
ls -lh "$BACKUP_FILE"
head -n 5 "$BACKUP_FILE"
```

For `.dump` files from Option C:

```bash
ls -lh ~/backups/asia-dental-lab/*.dump | tail -1
pg_restore --list ~/backups/asia-dental-lab/YOUR_BACKUP_FILE.dump | head -5
```

**Pass criteria:** file exists, non-zero size, header/listing looks valid.

### 7.7 Bring app back up (if still in maintenance)

```bash
cd /var/www/asia-dental-lab-v2
php artisan up
```

**Record the backup file path in the Deployment Sign-Off table (Section 19).**

---

## 8. Git Pull / Checkout Plan

> **Label:** Run on VPS only when deploying

```bash
cd /var/www/asia-dental-lab-v2

git fetch origin --prune
git fetch --tags

git status --short
git branch --show-current

git checkout feature/sprint-21-rme-pdf-print-hardening
git pull origin feature/sprint-21-rme-pdf-print-hardening

git log --oneline --decorate -8
git tag --sort=-creatordate | head -15
```

### Expected after pull

| Check | Expected |
|---|---|
| HEAD | `327e55f` or newer approved Sprint 21 commit |
| Tag | `sprint-21-phase-21-6-rme-pdf-print-hardening` present |
| Working tree | Clean |

If VPS has local modifications, **stop** and resolve (stash/commit on a side branch) before continuing.

---

## 9. Dependency Install / Build Plan

> **Label:** Run on VPS only when deploying

```bash
cd /var/www/asia-dental-lab-v2

composer install --no-dev --optimize-autoloader

npm ci
npm run build
```

### Rules

- If `npm ci` fails due to lockfile mismatch → **stop and investigate**. Do not delete `package-lock.json` on VPS.
- If `composer install` fails → stop; do not run migrations on a broken vendor tree.
- Confirm `public/build` exists after `npm run build`.

---

## 10. Laravel Maintenance / Cache / Migration Plan

> **Label:** Run on VPS only when deploying

```bash
cd /var/www/asia-dental-lab-v2

php artisan down --render="errors::503" || true

php artisan optimize:clear
php artisan migrate --force
php artisan permission:cache-reset || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
```

### Forbidden commands on VPS

```bash
# NEVER RUN ON VPS PILOT / PRODUCTION:
php artisan migrate:fresh
php artisan db:wipe
```

### Post-migrate verification

```bash
php artisan migrate:status | grep -i lab_case_candidates || true
```

Expected: `2026_06_14_210001_create_trx_lab_case_candidates_table` → **Ran**.

---

## 11. Storage / Permission Reset

Known safe permission reset (VPS previously had storage/cache permission issues):

```bash
cd /var/www/asia-dental-lab-v2

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

php artisan optimize:clear
php artisan permission:cache-reset || true

sudo chown -R www-data:www-data storage bootstrap/cache public/build
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

If `sudo` is unavailable, escalate to server admin — do not use `chmod 777`.

---

## 12. Service Restart / Reload

> **Label:** Run on VPS only when deploying

```bash
sudo systemctl restart php8.3-fpm || sudo systemctl restart php8.2-fpm || sudo systemctl restart php8.1-fpm
sudo systemctl reload nginx

sudo systemctl status nginx --no-pager
sudo systemctl status php8.3-fpm --no-pager || sudo systemctl status php8.2-fpm --no-pager || true
```

---

## 13. Post-Deployment Smoke Tests

### CLI checks (VPS)

```bash
cd /var/www/asia-dental-lab-v2

curl -I http://145.79.13.224
curl -I http://145.79.13.224/login

php artisan route:list | grep -Ei "rme\.visits\.pdf|lab-case-candidates|convert|receipt"
```

**Expected routes (names):**

| Route name | Purpose |
|---|---|
| `rme.visits.print` | RME visit print bundle |
| `rme.visits.pdf` | RME visit PDF download |
| `rme.cashier.receipt.show` | Receipt with lab workflow |
| `lab-case-candidates.index` | Admin Lab queue |
| `lab-case-candidates.show` | Candidate detail |
| `lab-case-candidates.convert` | Convert to LabOrder |
| `lab-orders.show` | LabOrder detail (Sumber RME block) |

### Manual UI smoke tests (browser)

Use authorized pilot accounts only:

- [ ] Login as Admin Lab / authorized user
- [ ] Open RME visit list
- [ ] Open a completed/paid RME visit
- [ ] Open RME print page (`/rme/visits/{id}/print`)
- [ ] Download RME PDF (`/rme/visits/{id}/pdf`)
- [ ] Open cashier invoice show
- [ ] Open receipt — confirm lab workflow panel when candidate exists
- [ ] Open `/lab/case-candidates`
- [ ] Open candidate detail
- [ ] Convert candidate to LabOrder **on test/pilot data only** (explicit `lab_service_id`)
- [ ] Open generated LabOrder — confirm **Sumber RME** block
- [ ] Confirm no unauthorized branch data appears

---

## 14. Database Verification Queries

Read-only checks on VPS:

```bash
cd /var/www/asia-dental-lab-v2

php artisan tinker --execute="dump(Schema::hasTable('trx_lab_case_candidates'));"
php artisan tinker --execute="dump(DB::table('trx_lab_case_candidates')->count());"
php artisan tinker --execute="dump(DB::table('migrations')->where('migration', 'like', '%lab_case_candidates%')->exists());"

php artisan route:list | grep -Ei "lab-case-candidates|rme\.visits\.pdf"
```

Expected:

- `trx_lab_case_candidates` table exists
- Migration row present (count may be `0` until first paid RME invoice with `requires_lab` treatment)

---

## 15. Sprint 21 Feature Verification Checklist

- [ ] `trx_lab_case_candidates` table exists
- [ ] RME payment still succeeds (full-payment only)
- [ ] Paid RME invoice with `requires_lab` treatment creates candidate
- [ ] Candidate appears in Admin Lab queue (`/lab/case-candidates`)
- [ ] Candidate can be converted with explicit `lab_service_id`
- [ ] LabOrder created with `RECEIVED` status
- [ ] RME invoice/receipt shows lab workflow status
- [ ] LabOrder show displays **Sumber RME**
- [ ] RME print shows handwriting / odontogram / invoice / lab workflow
- [ ] RME PDF downloads successfully
- [ ] Cross-branch access blocked (policy tests validated locally; spot-check on VPS)
- [ ] No lab payment records (`trx_payments`) created by RME payment/conversion

---

## 16. Rollback Plan

### Level 1 — Code rollback only (no DB restore)

Use when code/build/cache issue but migrations already applied and data is acceptable.

```bash
cd /var/www/asia-dental-lab-v2

php artisan down --render="errors::503" || true

# Replace ROLLBACK_COMMIT with last known-good commit on VPS
git checkout ROLLBACK_COMMIT

composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan optimize:clear
php artisan permission:cache-reset || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Storage permissions (Section 11)
sudo chown -R www-data:www-data storage bootstrap/cache public/build

sudo systemctl restart php8.3-fpm || sudo systemctl restart php8.2-fpm || sudo systemctl restart php8.1-fpm
sudo systemctl reload nginx

php artisan up
```

### Level 2 — Database rollback (destructive — approval required)

Use **only** if migration/data corruption occurred and stakeholders approve downtime.

1. `php artisan down`
2. Verify backup file from Section 7 (`ls -lh`, `head` or `pg_restore --list`)
3. Restore:

```bash
# For .sql backup:
psql -h 127.0.0.1 -U postgres -d YOUR_DB_NAME < ~/backups/asia-dental-lab/YOUR_BACKUP.sql

# For .dump backup (repo script format):
cd /var/www/asia-dental-lab-v2
export $(grep -E '^(DB_DATABASE|DB_USERNAME|DB_HOST|DB_PORT)=' .env | xargs)
./scripts/restore_postgres.sh ~/backups/asia-dental-lab/YOUR_BACKUP.dump
```

4. Checkout last known-good code (Level 1 steps)
5. `php artisan up`
6. Re-run smoke tests

> **Warning:** Rollback after migrations may require careful DB restore. Do not improvise on production. Document downtime and get explicit approval.

---

## 17. Failure Handling

| Symptom | Likely cause | Actions |
|---|---|---|
| HTTP 500 after deploy | `storage/` or `bootstrap/cache` permissions | Section 11; `tail storage/logs/laravel.log` |
| PDF download fails | DomPDF config/cache, missing fonts, permissions | `composer show barryvdh/laravel-dompdf`; `php artisan optimize:clear`; check log |
| Route not found | Stale route cache | `php artisan route:clear`; `php artisan route:cache` |
| 403 on lab/RME pages | Permission cache / missing role | `php artisan permission:cache-reset`; verify Spatie roles on VPS |
| Candidates not generated | Invoice not `PAID`, treatment `requires_lab=false`, post-commit hook warning | Check `storage/logs/laravel.log`; verify invoice status and treatment flags |
| Conversion blocked | Invalid/inactive `lab_service_id`, wrong status, permissions | Confirm candidate `pending_review`; user has `create_lab_orders` |
| Branch mismatch | User active branch ≠ record branch | Verify branch switcher / `BranchContext` |
| `npm ci` failure | Lockfile out of sync with `package.json` | Stop deploy; fix locally, commit lockfile, re-pull |

### Diagnostic commands

```bash
cd /var/www/asia-dental-lab-v2
tail -n 100 storage/logs/laravel.log
php artisan optimize:clear
php artisan permission:cache-reset || true
```

---

## 18. Security Checklist

- [ ] `.env` not committed to git
- [ ] `APP_DEBUG=false` on VPS (`php artisan about`)
- [ ] HTTPS/domain plan documented if moving beyond IP access
- [ ] File permissions are `775`/`664` — not `777`
- [ ] Backup files stored outside web root (`~/backups/asia-dental-lab`)
- [ ] `storage/logs` not web-accessible
- [ ] PDF and lab/RME routes require authentication and policy authorization
- [ ] No secrets in deployment sign-off notes

---

## 19. Deployment Sign-Off Template

| Field | Value |
|---|---|
| Date/time | |
| Deployer | |
| Branch | `feature/sprint-21-rme-pdf-print-hardening` |
| Commit | |
| Tag | `sprint-21-phase-21-6-rme-pdf-print-hardening` |
| Backup file path | |
| Migration result | |
| Build result (`npm run build`) | |
| Smoke test result | |
| Rollback required? | yes / no |
| Notes | |

---

## 20. Final Recommendation

Deploy Sprint 21 to the VPS **only after**:

1. Database backup has been taken and **verified** (Section 7)
2. Local verification passes (Section 5) — RME tests, full suite, pint, build
3. Deployment window approved by project owner / ops
4. Rollback plan prepared (known-good commit + backup path recorded)
5. Authorized user accounts and roles ready for pilot UAT (Admin Lab, Cashier, Doctor as needed)

**This runbook does not authorize deployment by itself.** It must be reviewed, and all open items in Section 4 resolved on the VPS before execution.

---

## Appendix — Sprint 21 Route Reference

```
GET  /rme/visits/{clinicVisit}/print     → rme.visits.print
GET  /rme/visits/{clinicVisit}/pdf       → rme.visits.pdf
GET  /rme/cashier/{visit}/billing/{invoice}/receipt → rme.cashier.receipt.show
GET  /lab/case-candidates                → lab-case-candidates.index
GET  /lab/case-candidates/{candidate}      → lab-case-candidates.show
POST /lab/case-candidates/{candidate}/convert → lab-case-candidates.convert
GET  /lab-orders/{labOrder}              → lab-orders.show
```

## Appendix — Related Documentation

- `docs/sprint_21_planning.md`
- `docs/sprint_21_rme_lab_integration_architecture.md`
- `docs/sprint_history.md`
- `docs/rme_pilot_backup_import_guide.md` (pilot data import — not for production overwrite)
- `scripts/backup_postgres.sh` / `scripts/restore_postgres.sh`
