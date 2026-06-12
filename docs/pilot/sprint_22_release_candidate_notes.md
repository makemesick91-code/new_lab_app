# Sprint 22 Release Candidate Notes — Pilot Stabilization + Owner Dashboard Foundation

> **Jenis fase:** Dokumentasi / release candidate / go-no-go readiness  
> **Deploy dilakukan:** Tidak — dokumen ini disusun lokal; perintah VPS tidak dijalankan saat penulisan.  
> **Wajib dibaca:** Baca seluruh runbook dan konfirmasi langkah backup/rollback sebelum aksi produksi.

---

## 2. Status

| Item | Nilai |
|------|-------|
| Status rilis | **Release candidate** untuk VPS pilot |
| Branch deploy terbaru (Phase 22.7) | `feature/sprint-22-vps-owner-dashboard-smoke-checklist` |
| Tag deploy terbaru (Phase 22.7) | `sprint-22-phase-22-7-owner-dashboard-smoke-checklist` |
| Commit baseline Phase 22.7 | `1c5c198` |
| Branch closure (Phase 22.8) | `feature/sprint-22-closure-rc-go-no-go` |
| Tag closure (Phase 22.8) | `sprint-22-phase-22-8-closure-rc-go-no-go` |
| Reset database destruktif | **Tidak diperlukan** |

Tag Phase 22.8 dibuat setelah fase dokumentasi ini selesai dan diverifikasi.

---

## 3. Ringkasan Eksekutif

Sprint 22 adalah sprint **stabilisasi pilot** setelah rilis Sprint 21 (RME Advanced Workflow) di VPS Hostinger. Fokus utama: memperkuat akses role/permission/menu, menyediakan data dan checklist smoke test, menyiapkan runbook deploy VPS aman, memvalidasi alur RME → Lab end-to-end, dan membangun fondasi **Dasbor Owner** read-only untuk monitoring RME/Lab.

**Yang disampaikan Sprint 22:**

1. **Role/permission/menu hardening** — role pilot Owner, Kasir, Perawat; permission `view_owner_dashboard` / `view_branch_dashboard`; sidebar dan route `/dashboard` diperkuat.
2. **Data smoke test RME & checklist operator** — `RmeSmokeTestSeeder`, akun smoke test, checklist operator/developer.
3. **Checklist deploy VPS & safe seeder rollout** — runbook backup/deploy/rollback, panduan seeder idempotent, skrip preflight read-only.
4. **Validasi E2E RME → Lab Candidate → Lab Order** — tes otomatis dan checklist operator/developer.
5. **KPI Dasbor Owner RME/Lab** — kartu KPI, funnel, panel perhatian, layanan reporting.
6. **Filter cabang & drilldown Dasbor Owner** — filter cabang, ringkasan per cabang, link drilldown permission-aware read-only.
7. **Checklist smoke test manual Dasbor Owner & pembaruan checklist VPS** — validasi manual pasca-deploy.

**Tidak termasuk Sprint 22:** HR, redesign UI global, perubahan schema baru di Phase 22.8, perubahan perilaku seeder.

---

## 4. Tabel Ringkasan Fase Sprint 22

| Phase | Judul | Branch | Tag | Deliverable utama | Verifikasi |
|-------|-------|--------|-----|-------------------|------------|
| 22.1 | Pilot Role/Permission/Menu Hardening | `feature/sprint-22-role-permission-menu-hardening` | `sprint-22-phase-22-1-pilot-role-permission-menu-hardening` | Role Owner/Kasir/Perawat, permission dashboard, hardening sidebar/route | Full suite 1940 passed; RolePermissionHardening, Sidebar, PilotRoute tests |
| 22.2 | RME End-to-End Smoke-Test Data & Operator Checklist | `feature/sprint-22-rme-smoke-test-checklist` | `sprint-22-phase-22-2-rme-smoke-test-checklist` | `RmeSmokeTestSeeder`, akun smoke, checklist operator/developer | RmeSmokeTestSeeder/Route tests; Pilot filter 34 passed |
| 22.3 | VPS Pilot Deployment Checklist & Safe Seeder Rollout | `feature/sprint-22-vps-pilot-deployment-checklist` | `sprint-22-phase-22-3-vps-pilot-deployment-checklist` | VPS checklist, safe seeder rollout, preflight script | VpsPilotDeploymentChecklist tests |
| 22.4 | RME → Lab Candidate End-to-End Validation | `feature/sprint-22-rme-lab-candidate-e2e-validation` | `sprint-22-phase-22-4-rme-lab-candidate-e2e-validation` | E2E validation tests, checklist operator/developer lab | RmeLabCandidateE2EValidationTest |
| 22.5 | Owner Dashboard RME/Lab Pilot KPI Wiring | `feature/sprint-22-owner-dashboard-rme-lab-kpi` | `sprint-22-phase-22-5-owner-dashboard-rme-lab-kpi` | `OwnerDashboardRmeLabKpiService`, kartu KPI, funnel, attention panel | OwnerDashboardRmeLabKpiTest, OwnerDashboardUiTest |
| 22.6 | Owner Dashboard Branch Filter & KPI Drilldown Polish | `feature/sprint-22-owner-dashboard-branch-filter-drilldown` | `sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown` | Filter cabang, ringkasan per cabang, drilldown permission-aware | OwnerDashboardBranchFilterDrilldownTest; full suite 1991 passed / 6886 assertions |
| 22.7 | VPS Pilot Checklist Update & Owner Dashboard Manual Smoke Test | `feature/sprint-22-vps-owner-dashboard-smoke-checklist` | `sprint-22-phase-22-7-owner-dashboard-smoke-checklist` | Manual smoke checklist, pembaruan VPS checklist, catatan safe seeder | OwnerDashboardManualSmokeChecklistTest; full suite 2003 passed / 6949 assertions |
| 22.8 | Sprint 22 Closure, Release Candidate Notes & VPS Pilot Go/No-Go Checklist | `feature/sprint-22-closure-rc-go-no-go` | `sprint-22-phase-22-8-closure-rc-go-no-go` | Release notes, go/no-go checklist, closure docs | Sprint22ReleaseCandidateChecklistTest |

---

## 5. Rekomendasi Target Deploy

### Branch deploy pilot saat ini

| Strategi | Branch | Kapan |
|----------|--------|-------|
| **Fungsional terakhir (Phase 22.7)** | `feature/sprint-22-vps-owner-dashboard-smoke-checklist` | Deploy jika hanya butuh kode fungsional tanpa dok closure |
| **Dokumentasi-inclusive RC (disarankan setelah 22.8)** | `feature/sprint-22-closure-rc-go-no-go` | Deploy setelah Phase 22.8 di-push — termasuk release notes & go/no-go |

### Tag deploy

| Item | Nilai |
|------|-------|
| Tag fungsional Phase 22.7 | `sprint-22-phase-22-7-owner-dashboard-smoke-checklist` |
| Tag closure Phase 22.8 | `sprint-22-phase-22-8-closure-rc-go-no-go` |
| Tag RC opsional (setelah persetujuan stakeholder) | `sprint-22-release-candidate` |

**Aturan deploy:**

- **Jangan** deploy perubahan lokal yang belum di-commit/push.
- **Jangan** deploy dari working tree kotor (`git status` harus bersih atau hanya perubahan yang disengaja dan sudah di-review).
- **Selalu** backup database sebelum deploy.

---

## 6. Urutan Deploy VPS Aman

Path contoh: `/var/www/asia-dental-lab-v2` (sesuaikan dengan `APP_DIR` di VPS).

```bash
cd /var/www/asia-dental-lab-v2

git branch --show-current
git status --short

# Backup dulu — WAJIB

mkdir -p ~/backups/daengtisia
export BACKUP_FILE=~/backups/daengtisia/backup_before_sprint22_rc_$(date +%Y%m%d_%H%M%S).dump
pg_dump -U "$DB_USER" -d "$DB_NAME" -F c -f "$BACKUP_FILE"
ls -lh "$BACKUP_FILE"

# Fetch kode

git fetch --all --tags
git checkout feature/sprint-22-closure-rc-go-no-go
git pull origin feature/sprint-22-closure-rc-go-no-go

# Install/cache

composer install --no-dev --optimize-autoloader
php artisan optimize:clear

# Migrasi — hanya jika sudah direview dan diperlukan
# Sprint 22 Phase 22.1–22.8 tidak menambah migrasi schema baru di Phase 22.8.
# Jika tidak ada migrasi baru sejak deploy terakhir, langkah ini BOLEH dilewati setelah review.

php artisan migrate --force

# Seeder aman — wajib untuk permission/role pilot

php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force

# Data smoke test — OPSIONAL, hanya jika tim pilot setuju

php artisan db:seed --class=RmeSmokeTestSeeder --force

# Rebuild cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

# Cek log

tail -n 100 storage/logs/laravel.log
```

**Catatan penting:**

- Jika **tidak ada migrasi baru** sejak deploy terakhir (kondisi normal untuk Sprint 22.8), `php artisan migrate --force` boleh **dilewati** setelah review `php artisan migrate:status`.
- `RmeSmokeTestSeeder` **opsional** — jangan dijalankan jika tim pilot tidak ingin akun/data smoke test di VPS.
- **Jangan** menjalankan `php artisan db:seed` tanpa `--class=` dan tanpa review.

---

## 7. Perintah Terlarang di VPS

Perintah berikut **dilarang keras** di lingkungan pilot/VPS:

| Perintah | Alasan |
|----------|--------|
| `php artisan migrate:fresh` | Menghapus seluruh schema dan data |
| `php artisan migrate:fresh --seed` | Reset penuh + seed default |
| `php artisan db:wipe` | Menghapus semua tabel |
| `php artisan db:seed` tanpa `--class=` | Menjalankan `DatabaseSeeder` penuh — berbahaya di pilot |
| `TRUNCATE` / hapus massal data pilot | Kehilangan data irreversible |
| `git reset --hard` di VPS tanpa backup dan persetujuan | Risiko kehilangan konfigurasi lokal |
| Force push dari VPS | Tidak sesuai prosedur deploy |

Jika ragu, **hentikan** dan konsultasi developer sebelum menjalankan perintah artisan terkait database.

---

## 8. Ringkasan Verifikasi

### Verifikasi lokal yang diketahui

| Fase | Hasil |
|------|-------|
| Phase 22.6 | RME 398 passed / 1147 assertions; full suite **1991 passed / 6886 assertions** |
| Phase 22.7 | Full suite **2003 passed / 6949 assertions**; `./vendor/bin/pint --dirty` PASS, 0 file |

### Catatan kejujuran

- Phase 22.7: `php artisan test --filter=RME` sempat gagal **sekali** (flaky/transien — `LabCaseCandidateQueueTest` search by patient name) sebelum full suite lulus.
- **Sebelum deploy VPS**, jalankan ulang dari **Ubuntu Terminal biasa** (bukan terminal terintegrasi Cursor/VS Code):
  - `php artisan test --filter=RME`
  - `php artisan test` (full suite, jika memungkinkan)

---

## 9. Referensi Checklist Smoke Test Manual

| Dokumen | Tujuan |
|---------|--------|
| `docs/pilot/rme_smoke_test_operator_checklist.md` | Smoke test alur RME end-to-end |
| `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md` | Smoke test RME → Lab Candidate → Lab Order |
| `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md` | Smoke test manual Dasbor Owner |
| `docs/pilot/vps_pilot_deployment_checklist.md` | Runbook deploy VPS aman |
| `docs/pilot/safe_seeder_rollout.md` | Rollout seeder idempotent |
| `docs/pilot/vps_pilot_go_no_go_checklist.md` | Keputusan GO / NO-GO pilot |

---

## 10. Kriteria GO

Deploy pilot **GO** jika **semua** kondisi berikut terpenuhi:

- [ ] File backup ada dan ukurannya **> 0 byte** (bukti disimpan).
- [ ] Branch/tag git sesuai target deploy yang disetujui.
- [ ] `composer install --no-dev --optimize-autoloader` berhasil.
- [ ] `php artisan optimize:clear` berhasil.
- [ ] Migrasi (jika dijalankan) sudah direview dan berhasil — atau dilewati dengan bukti `migrate:status` aman.
- [ ] `PermissionSeeder` dan `RoleSeeder` berhasil.
- [ ] Owner dapat login dan membuka `/dashboard`.
- [ ] Bagian **Monitoring Pilot RME & Lab** muncul di Dasbor Owner.
- [ ] **Filter Cabang** berfungsi (Semua Cabang / per cabang).
- [ ] Tabel **Ringkasan Per Cabang** muncul.
- [ ] Link drilldown **tidak** mengekspos aksi create/edit yang tidak diizinkan.
- [ ] Dasbor Branch Admin dan Kasir **tidak terpengaruh** (tidak melihat dasbor Owner global secara tidak sengaja).
- [ ] Alur smoke test RME lulus secara manual (atau sudah diterima dari validasi E2E).
- [ ] Alur RME → Lab Candidate → Lab Order lulus manual atau sudah tercakup validasi E2E yang diterima.
- [ ] Log Laravel **tidak** menampilkan error kritis baru setelah deploy.
- [ ] Membuka dashboard **tidak** membuat/mengubah record operasional.

---

## 11. Kriteria NO-GO

Deploy pilot **NO-GO** jika **salah satu** kondisi berikut terjadi:

- [ ] Backup gagal atau file backup **0 byte**.
- [ ] Branch/tag deploy tidak sesuai yang disetujui.
- [ ] `composer install` gagal.
- [ ] Migrasi gagal.
- [ ] Seeder aman (`PermissionSeeder` / `RoleSeeder`) gagal.
- [ ] `/dashboard` mengembalikan error 500.
- [ ] Owner tidak bisa login atau tidak punya akses dashboard.
- [ ] **Monitoring Pilot RME & Lab** hilang untuk Owner.
- [ ] **Filter Cabang** error atau mengekspos data cabang tidak aktif/cross-branch.
- [ ] Branch Admin/Kasir melihat dasbor Owner global secara tidak terduga.
- [ ] Drilldown membuka halaman aksi tidak berizin (create/edit).
- [ ] Alur kasir/pembayaran/kandidat lab RME rusak.
- [ ] Log Laravel menampilkan error produksi kritis setelah deploy.
- [ ] Perintah destruktif (`migrate:fresh`, `db:wipe`, `db:seed` tanpa class) terlanjur dijalankan.
- [ ] Data operasional berubah hanya karena membuka dashboard.

Jika NO-GO, ikuti rencana rollback dan laporkan insiden (lihat `docs/pilot/vps_pilot_deployment_checklist.md` bagian format laporan).

---

## 12. Rencana Rollback

### Rollback aplikasi (non-destruktif)

```bash
cd "$APP_DIR"

git fetch --all --tags
git checkout sprint-21-release-candidate
# atau: git checkout sprint-22-phase-22-7-owner-dashboard-smoke-checklist
# catat commit: git log -1 --oneline

composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

### Rollback database

- **Utamakan:** tidak restore DB kecuali deploy/seeder menyebabkan **korupsi data nyata**.
- Jika restore benar-benar diperlukan:
  1. Hentikan akses aplikasi (`php artisan down` atau stop web server).
  2. Restore **hanya** dari file backup yang sudah diverifikasi (bagian backup di atas).
  3. Gunakan `pg_restore` atau prosedur restore yang disetujui ops — **jangan** `migrate:fresh`.
  4. Verifikasi aplikasi, lalu `php artisan up`.

**Jangan** restore DB secara casual untuk masalah UI/dokumentasi saja.

### Smoke test setelah rollback

- Login Owner → `/dashboard` → pastikan tidak error 500.
- Login Kasir → pastikan akses kasir RME masih normal.
- Cek `storage/logs/laravel.log` untuk error baru.

---

## 13. Keterbatasan yang Diketahui

- Filter rentang tanggal (`date_from` / `date_to`) di Dasbor Owner **masih ditunda** (deferred).
- Halaman tujuan drilldown memakai **BranchContext** aktif user, **bukan** `branch_id` filter dashboard — operator mungkin perlu mengganti cabang aktif di halaman tujuan.
- Tabel **Ringkasan Per Cabang** skala pilot; pagination mungkin diperlukan jika jumlah cabang bertambah.
- Kartu KPI eksekutif inventory/lab finance mungkin masih terpisah atau placeholder tergantung versi dashboard.
- Akun Owner pilot asli mungkin **tidak** melihat link drilldown lab jika `view_lab_orders` sengaja tidak diberikan (read-only terbatas — perilaku yang diharapkan).
- Sprint 22 **tidak** mengimplementasikan HR.
- Sprint 22 **tidak** melakukan redesign UI global.
- Dasbor Owner **read-only** — tidak membuat/mengubah transaksi operasional.

---

## 14. Backlog Kandidat Sprint 23

1. Filter rentang tanggal Dasbor Owner.
2. Polish perbandingan cabang / pagination ringkasan per cabang.
3. Penyelarasan konteks cabang drilldown dengan filter dashboard.
4. Triage bug pilot dari hasil smoke test manual.
5. Eksekusi deploy VPS dan dokumentasi bukti (screenshot, log, backup path).
6. Checklist verifikasi backup production-grade (opsional).
7. Polish UI/UX hanya setelah stabilitas pilot terjamin.
8. Konsolidasi dashboard eksekutif inventory/RME.

---

## Referensi Terkait

- `docs/pilot/vps_pilot_go_no_go_checklist.md`
- `docs/pilot/vps_pilot_deployment_checklist.md`
- `docs/pilot/safe_seeder_rollout.md`
- `docs/sprint_22_planning.md`
- `docs/sprint_history.md`
- `scripts/vps_pilot_preflight.sh`
