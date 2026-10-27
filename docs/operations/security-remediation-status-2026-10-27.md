---
kb_status: snapshot
kb_verified_against: f9134aac
kb_updated: 2026-10-28
---

# Status Remediasi Keamanan — 2026-10-27

Snapshot status remediasi keamanan setelah audit sistem menyeluruh 2026-10-27. Halaman ini
**bukan** sumber kebenaran. Yang menjadi sumber kebenaran adalah kode, GO tag git, dan pull
request. Status di sini dicatat per **2026-10-28 01:34 WITA**. Untuk keadaan terbaru, periksa GO tag dan
PR terkait.

Kembali ke [00-INDEX](../00-INDEX.md).

> Halaman ini sengaja tidak memuat password, application key, nama role database, isi file
> environment, alamat produksi, atau data pasien. Lihat larangan keamanan di
> [conventions](../knowledge-base/conventions.md).

## Otoritas produksi

| Item | Nilai |
|---|---|
| Commit produksi | `f9134aac` (tree `2aa2d01c`) |
| GO tag pada HEAD produksi | `security-f8c-env-copy-backup-classification-1-go` |
| Diverifikasi | 2026-10-27, setelah deploy F-8c dan setelah rotasi G7-A |

## Selesai dan terverifikasi di produksi

| Item | Status | Bukti |
|---|---|---|
| F-8 — path penyimpanan privat | **COMPLETE / GO** | GO tag `security-f8-private-storage-paths-1-go` → `043a4ae2`; [catatan sprint](../sprints/security-f8-private-storage-paths-1.md) |
| F-8b — config cache privat | **COMPLETE / GO** | GO tag `security-f8b-config-cache-private-1-go` → `2d258013`; [catatan sprint](../sprints/security-f8b-config-cache-private-1.md) |
| F-8c — klasifikasi salinan file environment | **COMPLETE / GO** | GO tag `security-f8c-env-copy-backup-classification-1-go` → `f9134aac`; [catatan sprint](../sprints/security-f8c-env-copy-backup-classification-1.md) |
| G7-A — rotasi password database aplikasi | **COMPLETE / GO** | lihat di bawah |

### G7-A — rotasi password database aplikasi

- Dijalankan 2026-10-27 pukul 21:00 WITA di jendela pemeliharaan yang disetujui owner.
- Server database hanya menerima verifier SCRAM-SHA-256 yang dihitung secara lokal. Nilai
  password tidak pernah dicetak, tidak masuk ke argumen perintah, dan tidak tertulis di dokumen
  mana pun.
- Pemeriksaan setelah rotasi: password baru diterima dan password lama ditolak; health 200;
  layanan aktif; queue worker sudah di-restart; satu backup terjadwal berhasil memakai kredensial
  baru; 0 error aplikasi sejak rotasi; nilai password baru tidak ditemukan di log mana pun.
- Pemeriksaan regresi F-8b/F-8c setelah rotasi: GO.

## Belum selesai — jangan dianggap COMPLETE

### G7-B — rotasi application key: PENDING

- Persiapan ada di PR #534 (`SECURITY-G7B-APP-KEY-REENCRYPTION-1`, kandidat `dee15aa1`).
  Status CI: **hijau** — run `40512596020` pada `dee15aa1`.
- **Rotasi di produksi: NO-GO.** Rotasi baru boleh dilakukan setelah verifikasi kesiapan selesai
  dan ada persetujuan eksekusi produksi yang terpisah.
- Uji terisolasi yang sudah ada:
  - re-enkripsi data SATUSEHAT yang terenkripsi;
  - pembacaan tanpa key lama;
  - dampak ke login dokter, pairing perangkat, dan signature payment intent;
  - pemulihan backup;
  - rollback.
- Ketergantungan yang masih terbuka:
  - Backup yang dibuat sebelum rotasi memerlukan key lama. Key lama harus disimpan (escrow)
    sebelum dipensiunkan.
  - Konsumen yang hanya memakai key saat ini akan langsung tidak berlaku saat rotasi: tiket login
    dokter, kode pairing perangkat, dan signature payment intent. Rotasi harus dijadwalkan pada
    jendela tanpa sesi aktif.

### Perbaikan keamanan F-1 sampai F-7: PR terbuka, belum di-merge, belum di-deploy

Setiap perbaikan satu PR. Basis kanonik `f9134aac` sudah di-merge ke setiap branch. Sebelum
dipublikasikan, setiap rentang commit sudah dipindai dari kredensial dan data pasien.

| Item | PR | Status CI |
|---|---|---|
| F-1 — registrasi publik ditutup | #535 | hijau — run `40514442201` |
| F-2 — penghapusan akun sendiri ditolak | #536 | hijau — run `40514457445` |
| F-3 — sel CSV diamankan dari formula | #539 | hijau — run `40517131444` |
| F-4a1 — tanggal rekam medis memakai kalender klinik | #541 | hijau — run `40520290991` |
| F-4a2 — layar keuangan memakai kalender klinik | #542 | hijau — run `40520300582` |
| F-4a3 — periode adopsi SATUSEHAT memakai kalender klinik | #543 | hijau — run `40520308137` |
| F-4b — tanggal ledger inventori | — | **BLOCKED** menunggu keputusan D15 |
| F-4c — tanggal order lab | — | **BLOCKED** menunggu keputusan D16 |
| F-5 — class dari JavaScript tetap ada di build produksi | #540 | hijau — run `40517144937` |
| F-6 — REPL tidak ikut ke produksi | #537 | hijau — run `40514468718` |
| F-7 — test guard reset sintetis SATUSEHAT dipilih di critical gate | #538 | hijau — run `40514484922` |

"Hijau" berarti semua gate CI wajib lulus pada SHA head PR tersebut. Full Suite dilewati oleh
kebijakan sementara dan tidak dihitung sebagai PASS.

F-4a dipecah menjadi tiga PR. Perbaikan awalnya menyentuh tujuh modul, sedangkan
`sprint:scope-audit` menolak `RUNTIME_FIX` yang menyentuh lebih dari tiga modul. Tipe sprint tidak
diubah hanya supaya lolos gate.

### Keamanan dependency: PR terbuka

| Item | PR | Status CI |
|---|---|---|
| Pembaruan dependency di dalam major yang sama | #544 | hijau — run `40522106555` |

- `composer audit`: 30 advisory produksi menjadi 0.
- `npm audit`: 12 menjadi 0.
- Tidak ada major version yang naik.
- `DependencySecurityFloorTest` mengunci versi minimum.

### Optimasi CI/CD: PR terbuka

| Item | PR | Status CI |
|---|---|---|
| Memo evaluasi governance (setiap gate enterprise dihitung sekali per evaluasi) | #545 | hijau — run `40551686916` |
| Cache Composer di NSF-9, NSF-10, dan Full Suite | #546 | hijau — run `40551702141` |

Hasil lokal memo evaluasi governance:

- `foundation:enterprise-closure-check` turun dari 60,4 detik menjadi 0,6 detik.
- `foundation:enterprise-documentation-check` turun dari 63,9 detik menjadi 0,6 detik.
- Output JSON identik byte per byte dengan versi kanonik.
- `architecture:foundation-governance-summary` turun dari 103,3 detik menjadi 0,67 detik.
- Tiga kelas test governance turun dari 1.868 detik menjadi 9,6 detik.

Hasil CI memo evaluasi governance (run `40551686916`), dibandingkan run push basis `40491341757` dan
run PR cache `40551702141` yang berjalan pada waktu yang sama tanpa memo:

| Step CI | Basis | PR cache (tanpa memo) | PR memo |
|---|---:|---:|---:|
| NSF-9 ENT-14 load test scale projection | 51 dtk | 50 dtk | 1 dtk |
| NSF-9 ENT-15 dokumentasi enterprise | 100 dtk | 101 dtk | 0 dtk |
| NSF-9 ENT-16 closure enterprise | 100 dtk | 100 dtk | 1 dtk |
| NSF-10 capture release evidence | 293 dtk | 205 dtk | 2 dtk |
| Critical gate: governance audit commands | 284 dtk | 457 dtk | 16 dtk |
| Critical gate: critical regression tests | 5.603 dtk | 9.293 dtk | 6.983 dtk |

Step perintah governance turun dari ratusan detik menjadi satu digit, konsisten di ketiga job. Step
critical regression tests hanya menunjukkan arah: PR memo 2.310 detik lebih cepat daripada PR cache
yang berjalan bersamaan, tetapi masih lebih lambat daripada run basis, karena variasi runner lebih
besar daripada selisihnya.

Waktu critical test step pada PR keamanan di atas berkisar 5.396–9.416 detik untuk suite yang
setara, karena kecepatan runner berbeda-beda dan sebelas run berjalan bersamaan. Karena itu, satu
run CI hanya menunjukkan arah perubahan, bukan angka penghematan yang presisi.

Parallel testing dievaluasi, tetapi tidak diaktifkan. Lebih dari 30 berkas test governance menulis
ke path bersama yang tetap (`storage/release-evidence/…`), sehingga paralelisasi di satu runner
belum aman. Matrix sharding lintas job sudah siap dan menjadi langkah berikutnya, setelah durasi
critical gate diukur ulang dengan memo terpasang.

## Keputusan owner yang masih terbuka

| Kode | Topik | Status | Dampak |
|---|---|---|---|
| D4 | Payload staging pasien legacy (berisi NIK lengkap) | PENDING | Rekomendasi: kosongkan kolom payload hanya untuk batch terminal; baris dan verdict tetap disimpan |
| D5 | Visibilitas repository | Disetujui prinsipnya (PRIVATE); **belum dialihkan** | Prasyarat: deploy key VPS, anggaran CI (D17), pengganti secret scanning, rencana branch protection (D6) |
| D6 | Branch protection | Disetujui untuk disiapkan; penerapan PENDING | Pada repository privat butuh GitHub Pro atau lebih tinggi |
| D15 | Kalender untuk tanggal ledger inventori | PENDING | Memblokir F-4b |
| D16 | Kalender untuk tanggal order lab | PENDING | Memblokir F-4c |
| D17 | Paket GitHub dan anggaran CI jika repository privat | PENDING | Memblokir pengalihan D5 |
| D20 | Aturan angka di CSV | Default yang direkomendasikan, sudah dipakai di F-3 | Owner dapat mengubahnya |

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi sebelum ada persetujuan kesiapan.
- Jangan merge atau deploy otomatis. Setiap PR melewati CI wajib dan tinjauan keamanan.
- Jangan menganggap Full Suite lulus. Full Suite dilewati oleh kebijakan sementara dan tidak pernah
  dicatat sebagai PASS.
- Jangan mengubah catatan medis historis atau transaksi keuangan.
