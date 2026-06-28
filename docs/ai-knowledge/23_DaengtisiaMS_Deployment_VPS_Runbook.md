# DaengtisiaMS — Deployment VPS Runbook

## Tujuan
Checklist deploy aman ke VPS pilot: backup, pull, build, migrate, cache, permission, health check, rollback.

## Ringkasan
Deploy ke VPS **tidak** boleh `migrate:fresh`/`db:wipe`. Selalu backup DB dulu. Path contoh: `/var/www/asia-dental-lab-v2`.

## Konteks DaengtisiaMS
Production-adjacent pilot di Hostinger VPS. Branch baseline berubah per sprint — verifikasi tag/commit sebelum deploy.

## File / Area Repo Terkait
- `docs/pilot/vps_pilot_deployment_checklist.md`
- `docs/sprint_21_vps_pilot_deployment_checklist.md`
- `docs/pilot/sprint_22_release_candidate_notes.md`
- `docs/sprint_23_phase_23_10_7_vps_deploy_combined_medical_record_print_smoke.md`
- `docs/non_production_restore_runbook.md`
- `docs/backup_restore_rehearsal_plan.md`
- `tests/Feature/Pilot/VpsPilotDeploymentChecklistTest.php`

## Aturan Utama

### Path & environment
| Item | Nilai (dari docs) |
|---|---|
| VPS path contoh | `/var/www/asia-dental-lab-v2` |
| Local path | `~/Projects/new_lab_app` |
| DB | PostgreSQL — backup wajib |
| APP_DIR | Sesuaikan `.env` VPS |

### Pre-deploy lokal
```bash
git status --short
php artisan test          # atau subset modul terdampak
./vendor/bin/pint --dirty
```
Branch & tag harus sudah di-push ke remote.

### Backup VPS (WAJIB)
```bash
pg_dump -U $DB_USER -d $DB_NAME -Fc -f backup_pre_deploy_$(date +%Y%m%d_%H%M).dump
```
**Stop deploy jika backup gagal atau file size 0.**

### Deploy steps (ringkas)
```bash
cd /var/www/asia-dental-lab-v2   # APP_DIR
git fetch origin
git checkout <approved-branch>
git pull origin <approved-branch>

composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force    # HANYA migrate, bukan fresh
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permission storage
chmod -R ug+rwx storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache  # sesuaikan user VPS

sudo systemctl reload php8.x-fpm   # sesuaikan versi PHP
sudo systemctl reload nginx
```

### Health check
- Login aplikasi
- Smoke RME: buka visit, RM, kasir (sesuai role test)
- Cek `storage/logs/laravel.log` untuk error
- Verifikasi queue worker jika dipakai

### Perintah TERLARANG di VPS
- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `git reset --hard` tanpa backup & approval
- Force push dari VPS
- Restore SQL mentah legacy over Sprint DB (gunakan `rme:import-pilot-backup` untuk data pilot)

### Rollback
1. Checkout commit/tag sebelumnya
2. `composer install`, `npm run build`
3. Restore DB dari backup jika migration irreversible
4. Clear cache
5. Document incident

### Seeder di VPS
- `RmeSmokeTestSeeder` — **opsional**, jangan auto-run di production pilot
- Permission/role seeder: idempotent tapi review dulu

## Workflow / Alur
```text
Lokal test pass → push branch/tag → SSH VPS
→ backup DB → maintenance (opsional) → pull → composer → npm build
→ migrate --force → cache → permissions → reload services
→ smoke test → GO/NO-GO
```

## Struktur Teknis
Queue: database driver — pastikan worker running jika fitur async dipakai

**TODO:** Exact PHP-FPM version & nginx config path di VPS — verifikasi manual di server.

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan deploy tanpa backup
- Jangan deploy jika full test lokal gagal
- Jangan migrate destructive tanpa runbook rollback

## Checklist Validasi
- [ ] Backup verified
- [ ] `git log -1` match approved commit
- [ ] Migrate exit 0
- [ ] App loads HTTPS
- [ ] Critical RME path smoke OK
- [ ] Storage writable

## Catatan untuk AI
Dokumen deploy di repo banyak yang **planning-only** — bedakan yang bilang "Deploy dilakukan: Tidak".

Baseline RC Sprint 21: branch `release/sprint-21-rme-advanced-workflow` — mungkin sudah superseded; ikuti branch yang user approve untuk deploy aktual.

Current dev branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` — belum tentu sama dengan VPS production.
