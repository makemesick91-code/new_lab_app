# Checklist GO / NO-GO Deploy VPS Pilot — Sprint 22

> **Jenis dokumen:** Keputusan operasional pilot  
> **Bahasa:** Indonesia  
> **Pasangan:** `docs/pilot/sprint_22_release_candidate_notes.md`

---

## 1. Tujuan

Dokumen ini membantu tim pilot memutuskan apakah deploy Sprint 22 ke VPS **siap go-live (GO)**, **go dengan catatan**, atau **harus ditunda (NO-GO)**.

Checklist ini melengkapi:

- `docs/pilot/sprint_22_release_candidate_notes.md` — release candidate notes
- `docs/pilot/vps_pilot_deployment_checklist.md` — urutan deploy teknis
- `docs/pilot/safe_seeder_rollout.md` — keamanan seeder

---

## 2. Kapan Digunakan

Gunakan checklist ini:

- Sebelum menyetujui deploy Sprint 22 RC ke VPS pilot.
- Setelah deploy selesai, sebelum membuka akses ke operator klinik.
- Setelah hotfix yang menyentuh dashboard, RME, atau permission/role pilot.
- Saat rollback — ulangi bagian post-deploy untuk memastikan sistem stabil.

**Tidak digunakan untuk:** deploy lokal dev, `migrate:fresh`, atau lingkungan tanpa data pilot nyata.

---

## 3. Kesiapan Pra-Deploy

Centang semua sebelum `git pull` di VPS:

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Branch/tag target sudah di-push ke remote | ☐ | |
| 2 | Full suite lokal lulus (atau minimal RME + Pilot + Dashboard) | ☐ | Ubuntu Terminal untuk tes berat |
| 3 | `git status` lokal bersih sebelum tag RC | ☐ | |
| 4 | Jendela deploy disetujui operator/owner | ☐ | |
| 5 | Path VPS (`APP_DIR`) dan kredensial DB diketahui | ☐ | |
| 6 | Runbook deploy sudah dibaca: `vps_pilot_deployment_checklist.md` | ☐ | |
| 7 | Release notes RC sudah dibaca: `sprint_22_release_candidate_notes.md` | ☐ | |
| 8 | Keputusan menjalankan `RmeSmokeTestSeeder` sudah dibuat (ya/tidak) | ☐ | |

---

## 4. Kesiapan Deploy

Centang selama/ setelah eksekusi deploy:

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Backup `pg_dump` berhasil; file > 0 byte; path dicatat | ☐ | |
| 2 | `git checkout` branch/tag Sprint 22 RC benar | ☐ | |
| 3 | `composer install --no-dev --optimize-autoloader` sukses | ☐ | |
| 4 | `php artisan optimize:clear` sukses | ☐ | |
| 5 | Migrasi direview; `migrate --force` sukses atau dilewati dengan bukti | ☐ | |
| 6 | `PermissionSeeder --force` sukses | ☐ | |
| 7 | `RoleSeeder --force` sukses | ☐ | |
| 8 | `RmeSmokeTestSeeder` hanya dijalankan jika disetujui | ☐ | N/A jika tidak dijalankan |
| 9 | Cache rebuild (`config/route/view`) sukses | ☐ | |
| 10 | `queue:restart` sukses (jika queue dipakai) | ☐ | |
| 11 | Tidak ada perintah terlarang yang dijalankan | ☐ | lihat bagian 9 |

---

## 5. Smoke Test Pasca-Deploy

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Halaman login dapat diakses | ☐ | |
| 2 | Tidak ada error 500 di halaman utama setelah login | ☐ | |
| 3 | Sidebar menu sesuai role (tidak bocor permission) | ☐ | |
| 4 | `storage/logs/laravel.log` tidak ada error kritis baru | ☐ | |
| 5 | Preflight read-only (opsional): `scripts/vps_pilot_preflight.sh` | ☐ | |

---

## 6. Pengecekan Dasbor Owner

Gunakan akun **Owner** (`owner.smoke@pilot-test.local` atau akun Owner pilot asli).

Referensi detail: `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Owner dapat login dan membuka `/dashboard` | ☐ | |
| 2 | Bagian **Monitoring Pilot RME & Lab** tampil | ☐ | |
| 3 | Kartu KPI RME/Lab tampil (nilai boleh nol) | ☐ | |
| 4 | **Filter Cabang** — opsi Semua Cabang dan per cabang berfungsi | ☐ | |
| 5 | Teks konteks filter benar (semua cabang / cabang tertentu) | ☐ | |
| 6 | Tabel **Ringkasan Per Cabang** tampil | ☐ | |
| 7 | Link **Lihat detail** drilldown hanya untuk permission yang dimiliki | ☐ | |
| 8 | Drilldown **tidak** menampilkan tombol create/edit tidak berizin | ☐ | |
| 9 | Membuka dashboard **tidak** mengubah data kunjungan/invoice/lab | ☐ | |
| 10 | Screenshot bukti disimpan | ☐ | |

---

## 7. Pengecekan Smoke Workflow RME

Referensi: `docs/pilot/rme_smoke_test_operator_checklist.md`

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Perawat dapat registrasi/mengelola kunjungan (sesuai role) | ☐ | |
| 2 | Dokter dapat finalisasi RM + handwriting | ☐ | |
| 3 | Kunjungan berpindah ke status `cashier_pending` setelah finalisasi | ☐ | |
| 4 | Kasir dapat membuat invoice dan pembayaran penuh | ☐ | |
| 5 | Kunjungan menjadi `completed` setelah bayar | ☐ | |
| 6 | Cetak receipt/struk berfungsi | ☐ | |
| 7 | Kasir **tidak** melihat menu lab/admin yang tidak berizin | ☐ | |

---

## 8. Pengecekan RME → Lab Candidate

Referensi: `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md`

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | Setelah pembayaran, kandidat lab terbentuk untuk treatment `requires_lab` | ☐ | |
| 2 | Admin Lab melihat antrian kandidat di `/lab/case-candidates` | ☐ | |
| 3 | Konversi kandidat ke Lab Order berhasil (manual, `lab_service_id` eksplisit) | ☐ | |
| 4 | Status kandidat menjadi `converted_to_lab_order` | ☐ | |
| 5 | Visibilitas di invoice RME / Lab Order show sesuai desain | ☐ | |
| 6 | Validasi E2E otomatis sudah lulus di lingkungan dev (referensi) | ☐ | |

---

## 9. Pengecekan Keamanan Seeder

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | `PermissionSeeder` dijalankan dengan `--class=` eksplisit | ☐ | |
| 2 | `RoleSeeder` dijalankan dengan `--class=` eksplisit | ☐ | |
| 3 | `RmeSmokeTestSeeder` hanya jika disetujui | ☐ | |
| 4 | **Tidak** menjalankan `php artisan db:seed` tanpa `--class=` | ☐ | |
| 5 | **Tidak** menjalankan `migrate:fresh`, `migrate:fresh --seed`, `db:wipe` | ☐ | |
| 6 | Data pasien/kunjungan pilot nyata masih ada setelah deploy | ☐ | |

---

## 10. Pengecekan Log / Error

| # | Pengecekan | OK | Catatan |
|---|------------|----|---------|
| 1 | `tail -n 100 storage/logs/laravel.log` — tidak ada exception kritis baru | ☐ | |
| 2 | Tidak ada error 500 berulang di akses normal | ☐ | |
| 3 | Web server / PHP-FPM stabil setelah cache rebuild | ☐ | |

---

## 11. Tabel Keputusan GO

**GO** jika semua bagian 3–10 relevan **lulus** tanpa isu blocker.

| Kondisi | Keputusan |
|---------|-----------|
| Semua pra-deploy, deploy, smoke test, Owner dashboard, RME, lab candidate, seeder, log — lulus | **GO** |
| Isu minor (mis. KPI nol karena belum ada data, link lab tidak muncul karena permission Owner sengaja read-only) — terdokumentasi dan diterima owner | **GO dengan catatan** |
| Satu atau lebih kriteria NO-GO (bagian 12) terpenuhi | **NO-GO** |

---

## 12. Tabel Keputusan NO-GO

**NO-GO** jika **salah satu** terjadi:

| # | Kondisi blocker |
|---|-----------------|
| 1 | Backup gagal atau file 0 byte |
| 2 | Branch/tag deploy salah |
| 3 | `composer install` gagal |
| 4 | Migrasi atau seeder wajib gagal |
| 5 | `/dashboard` error 500 untuk Owner |
| 6 | Monitoring Pilot RME & Lab hilang |
| 7 | Filter Cabang error atau bocor data cabang |
| 8 | Branch Admin/Kasir melihat dasbor Owner global tidak terduga |
| 9 | Drilldown membuka halaman aksi tidak berizin |
| 10 | Alur kasir/pembayaran/kandidat lab rusak |
| 11 | Perintah destruktif terlanjur dijalankan |
| 12 | Data berubah hanya karena membuka dashboard |
| 13 | Error kritis baru di log produksi |

**Tindakan NO-GO:** hentikan rollout ke operator → rollback aplikasi (lihat release notes) → laporkan insiden → jangan restore DB kecuali korupsi data.

---

## 13. Bagian Sign-Off

Isi setelah smoke test selesai. Keputusan: **GO** / **GO dengan catatan** / **NO-GO**.

| Role | Nama | Tanggal/Waktu | Keputusan | Catatan |
|------|------|---------------|-----------|---------|
| Developer | | | ☐ GO ☐ GO dengan catatan ☐ NO-GO | |
| Operator Klinik | | | ☐ GO ☐ GO dengan catatan ☐ NO-GO | |
| Owner / Perwakilan Owner | | | ☐ GO ☐ GO dengan catatan ☐ NO-GO | |
| Admin Lab | | | ☐ GO ☐ GO dengan catatan ☐ NO-GO | |
| Kasir | | | ☐ GO ☐ GO dengan catatan ☐ NO-GO | |

**Bukti yang disarankan dilampirkan:**

- Path file backup (`BACKUP_FILE`)
- Screenshot Dasbor Owner (filter cabang + ringkasan per cabang)
- Screenshot alur RME/kasir (jika diuji)
- Cuplikan log jika ada catatan

---

## Referensi Terkait

- `docs/pilot/sprint_22_release_candidate_notes.md`
- `docs/pilot/vps_pilot_deployment_checklist.md`
- `docs/pilot/safe_seeder_rollout.md`
- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`
- `docs/pilot/rme_smoke_test_operator_checklist.md`
- `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md`
