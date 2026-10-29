---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# Laporan Rilis Produksi F-4b/F-4c — `fb3726c5` (2026-10-30)

**Keputusan: GO.** Rilis F-4b/F-4c berjalan di produksi sejak **2026-10-30 01:08 WITA** (17:08 UTC
2026-10-29), di dalam jendela yang disetujui owner (01:00–01:30 WITA). Semua syarat persetujuan
terpenuhi sebelum deploy, dan semua verifikasi pasca-deploy lulus.

Halaman ini bukan sumber kebenaran; sumbernya tetap kode, GO tag git, dan pull request. Kembali ke
[00-INDEX](../../00-INDEX.md). Halaman terkait:
[G7B-FINAL-READINESS-REPORT](G7B-FINAL-READINESS-REPORT.md),
[status remediasi keamanan](../security-remediation-status-2026-10-27.md).

> Halaman ini tidak memuat application key, password, isi file environment, nama file salinan
> environment, alamat produksi, atau data pasien. Angka yang dicatat hanya jumlah baris agregat.

## Otoritas

| Item | Nilai |
|---|---|
| Commit produksi | `fb3726c58dc46e79d5221e8ef26065e39e6d41cf` (tree `e25afb7a`) |
| GO tag | `post-audit-f4bc-release-1-go` (objek tag `60262a21`), cocok persis dengan HEAD produksi |
| Otoritas produksi sebelumnya | `755c6bce` = `post-audit-security-release-1-go` (target rollback) |
| Isi rilis | #548 (F-4c lab), #549 (F-4b1 ledger inventory), #550 (F-4b2 kedaluwarsa), #551 (F-4b3 laporan), #552 (F-4b4 analitik), #553 (dokumentasi) |
| Migrasi | Tidak ada. Tidak ada backfill historis, tidak ada data yang ditulis ulang, application key tidak diubah. |

## Syarat persetujuan owner

Owner menyetujui deploy di jendela 01:00–01:30 WITA, dengan syarat ketat. Semua diperiksa ulang pada
**01:06 WITA**, tepat sebelum deploy:

| Syarat | Hasil | Bukti |
|---|---|---|
| Semua gate CI wajib lulus | **LULUS** | Run `40897488296` pada `fb3726c5`, semua job wajib `success` (rincian di bawah) |
| Tidak ada pekerjaan klinis/kasir aktif | **LULUS** | Gate aktivitas baca-saja: 0 sesi aktif 10 menit, 0 kunjungan sedang diperiksa, 0 perubahan 30 menit di tabel kunjungan, rekam medis, odontogram, consent, invoice, pembayaran, inventory, lab; 0 job antre |
| Kesiapan backup terverifikasi | **LULUS** | `foundation:backup-dr-check` GO 13/13; backup malam terakhir sukses; deploy menulis dan memverifikasi backup sendiri (`pre_auto_deploy_20261029-170635.sql`, 32,3 MB) |
| Rollback ke `755c6bce` tersedia | **LULUS** | Tag rollback terselesaikan ke `755c6bce` di host produksi |

Catatan informasi (bukan pekerjaan aktif, sudah ada sebelum rilis): 1 kunjungan menunggu kasir yang
tidak tersentuh dalam 30 menit, 1 konteks online dokter dan 1 lease sesi dokter yang sudah basi.

## Validasi rilis (CI)

`workflow_dispatch` run `40897488296` pada `fb3726c5`, profil classifier `unknown_high_risk`
(profil terkuat — semua modul dijalankan):

| Gate | Hasil |
|---|---|
| NSF-R011 Critical Test Gate | 6.991 lulus (39.059 assertion), exit 0, 1 jam 58 menit |
| Selective Module Gate | Inventory 1.608 · Lab 686 (+1 skip) · UI 950 · Permission 669, semua lulus |
| NSF-R012 Quality, NSF-9 Release Safety & Smoke, NSF-10 Release Evidence, Android Gate | lulus |
| NSF-R011 Full Suite | **SKIPPED** — lihat bagian Full Suite |

## Deploy

Lewat runner deploy resmi di host produksi (`scripts/deploy-vps-runner.sh start`), mulai 01:06 WITA:

- `exit=0`, `DEPLOY OK`, target dipatok ke `fb3726c5`, `DEPLOY_HEAD_TARGET_MATCH=YES`.
- Tidak ada migrasi. Isolasi runtime 106 GO / 0 FAIL. Izin file rahasia dan backup diterapkan ulang.
- Worker queue di-restart oleh deploy (01:07:40 WITA) dan aktif.
- Satu peringatan smoke yang sudah dikenal: probe `http://127.0.0.1/login` mendapat 404 karena aplikasi
  lain di host yang sama menjawab loopback. Domain kanonik menjawab 200.

## Verifikasi pasca-deploy

Semua pemeriksaan baca-saja. Probe database berjalan dengan sesi database yang dipaksa baca-saja.

### Integritas data (sidik jari tabel sebelum dan sesudah deploy)

Sidik jari = jumlah baris, id maksimum, dan hash isi seluruh baris. Diambil 23:14 WITA (sebelum) dan
01:08 WITA (sesudah). **16 dari 16 identik.**

| Kelompok | Tabel | Jumlah baris |
|---|---|---|
| Ledger inventory | movements, batches, goods receipts + items, purchase requests, purchase orders, stock opnames, stock transfers | 56 · 20 · 7 + 20 · 6 · 7 · 7 · 11 |
| Lab | lab orders, status logs, deliveries | 0 · 0 · 0 |
| Klinis dan kasir | pasien, kunjungan, rekam medis, invoice, pembayaran | identik |

Deploy tidak menulis satu baris pun ke tabel inventory, lab, klinis, atau kasir.

### Tanggal bisnis WITA

Pengukuran langsung jatuh di jendela yang diperbaiki rilis ini: pada **01:08 WITA**, tanggal UTC masih
2026-10-29, sedangkan tanggal klinik dan tanggal bisnis inventory sudah **2026-10-30**. Batch yang
kedaluwarsa pada "kemarin" klinik terbaca `expired`.

Instan simulasi (waktu proses lokal, tidak ada yang ditulis):

| Instan (WITA) | Tanggal UTC | Tanggal klinik = bisnis inventory | Batch kedaluwarsa kemarin: sebelum → sesudah | Prefiks nomor lab / delivery: sebelum → sesudah |
|---|---|---|---|---|
| 29 Okt 23:59 | 10-29 | 10-29 | expired → expired | ADL-2026 → ADL-2026 |
| 30 Okt 00:00 | 10-29 | 10-30 | **near_expiry → expired** | ADL-2026 → ADL-2026 |
| 30 Okt 07:30 | 10-29 | 10-30 | **near_expiry → expired** | ADL-2026 → ADL-2026 |
| 30 Okt 07:59 | 10-29 | 10-30 | **near_expiry → expired** | ADL-2026 → ADL-2026 |
| 30 Okt 08:00 | 10-30 | 10-30 | expired → expired | ADL-2026 → ADL-2026 |
| 31 Okt 23:59 | 10-31 | 10-31 | expired → expired | ADL-2026 → ADL-2026 |
| 1 Nov 00:00 | 10-31 | **11-01** | **near_expiry → expired** | ADL-2026 → ADL-2026 |
| 31 Des 23:59 | 12-31 | 12-31 | expired → expired | ADL-2026 → ADL-2026 |
| 1 Jan 2027 00:00 | 12-31 | **2027-01-01** | **near_expiry → expired** | **ADL-2026 → ADL-2027**, DLV-2027 |
| 1 Jan 2027 07:59 | 12-31 | 2027-01-01 | **near_expiry → expired** | **ADL-2026 → ADL-2027**, DLV-2027 |

Sebelum rilis, kode produksi masih menilai kedaluwarsa dan penomoran tahun dengan tanggal UTC selama
00:00–08:00 WITA. Sesudah rilis, semua instan mengikuti tanggal klinik, termasuk pergantian bulan dan
tahun. `clinical:date-diagnose` mengonfirmasi zona klinik `Asia/Makassar` kanonik, dengan 0 penulisan
database.

### Aplikasi dan modul

| Area | Hasil |
|---|---|
| Rilis | HEAD cocok, tree bersih, 0 migrasi tertunda, environment pilot, debug OFF, maintenance OFF |
| Kesehatan dan login | `/login`, `/health/live`, `/health/ready`, `/health/lb` 200 di domain kanonik; `/register` 404; `/storage` 403; smoke landing autentikasi deploy GO |
| RME, consent, kasir | Rute RME, odontogram, consent, kasir, pembayaran, kwitansi ada; permukaan terautentikasi mengalihkan tamu (302); readiness koreksi invoice dan status sengketa billing GO |
| Akses dokter | Fleet readiness `TRUSTED_PATHS_COMPLETE`; WebAuthn armed; scope pilot GO; enforcement global tetap mati |
| Ledger inventory | Audit tata kelola batch 10/10, audit batch dokumen sumber 10/10, audit procurement (strict) 9/9, semua GO |
| Laporan dan analitik inventory | Rute laporan, ekspor, dan analitik ada; 24 rute ekspor, semuanya di belakang autentikasi |
| Lab | Readiness pilot Lab Workflow V2 GO; audit akun teknisi GO; penomoran order dan delivery terbukti lewat simulasi di atas |
| Queue dan backup | php-fpm, nginx, worker aktif; 0 job gagal; timer backup aktif, terakhir sukses; backup-DR GO |
| Penyimpanan privat dan rahasia | Isolasi runtime GO; izin file rahasia GO; 0 file di disk publik; direktori bukti klinis privat 2770; kepatuhan keamanan GO; verifier F-8b/F-8c GO |
| G7-B | Dry run saja (tanpa `--apply`): 8 nilai terenkripsi, semuanya di key saat ini, 0 tidak terbaca |
| Log aplikasi | Ukuran identik sebelum deploy, sesudah deploy, dan sesudah semua verifikasi — tidak ada error baru |

## Full Suite

**SKIPPED — tidak dijalankan dan tidak diklaim lulus.** Full Suite tidak diminta. Kebijakan sementara
global Full Suite masih berlaku, dan run rilis memakai `run_full_suite=false`.

Risiko yang diterima: test di luar filter critical gate dan empat modul selektif tidak berjalan pada
commit ini. Mitigasinya: classifier memakai profil terkuat, critical gate menjalankan 6.991 test, dan
keempat modul yang paling dekat dengan perubahan (Inventory, Lab, UI, Permission) dijalankan penuh.
Perubahan rilis terbatas pada modul Inventory, Lab, dan Delivery tanpa migrasi.

## Rollback

Belum dibutuhkan. Bila dibutuhkan: `bash scripts/rollback-vps.sh post-audit-security-release-1-go`
di host produksi. Rollback ini hanya memindahkan kode, karena rilis tidak membawa migrasi. Data yang
ditulis setelah rilis tetap valid untuk kode lama, karena kolom dan formatnya tidak berubah.

## Risiko yang tersisa

1. **Belum ada uji login manusia setelah rilis.** Verifikasi bersifat baca-saja dan sebagai tamu.
   Pengguna klinik pertama pagi ini adalah uji fungsional pertama. Sarannya, admin klinik membuka satu
   laporan inventory dan satu kartu stok.
2. **Lab belum punya data produksi.** Tabel order, status, dan delivery lab masih kosong, sehingga
   penomoran dan tanggal lab terbukti lewat simulasi generator dan readiness audit, bukan lewat order
   nyata. Tidak ada order lab buatan yang dibuat untuk membuktikannya.
3. **Full Suite tidak dijalankan** (lihat di atas).
4. Hal yang sudah ada sebelum rilis dan tidak diubah: 1 kunjungan menunggu kasir, satu konteks online
   dan satu lease dokter yang basi, cakupan WebAuthn segar 1 dari 9 perangkat aktif, serta peringatan
   smoke loopback.
