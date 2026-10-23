# Matriks Insiden dan Eskalasi — Koreksi Tagihan (Pilot SPN4)

Bagian dari SOP `docs/operations/rme-invoice-correction-pilot-sop-spn4.md`.
Aturan: rule 195 §30 (rollback), 196 §16/§21, 197 §15–19, 201 §16–17, 202.

**Prinsip dasar setiap insiden:**

1. Pasien **tidak ditahan** demi bukti pilot.
2. Tidak ada yang melewati Maker–Checker, blokir pembayaran D10, atau penjaga Rp0.
3. Tidak ada persetujuan, sesi login, tagihan, pembayaran, atau koreksi yang
   dikarang.
4. Tidak ada SQL keuangan, REPL (`tinker`/psysh) di produksi, atau edit manual
   status/total/pembayaran.
5. Operator hanya memakai kewenangan yang benar-benar dimiliki akunnya.
6. Bukti dicatat tanpa NIK, nomor rekam medis, atau isi klinis.

**Rantai eskalasi:** Kasir → Maker (user 29) → Owner (keputusan bisnis) →
Checker (user 1, keputusan di sistem) → IT (teknis, probe, rekonsiliasi,
penghentian darurat).

**Penghentian darurat pilot (hanya IT, atas keputusan Owner; rule 195 §30):**
(1) selesaikan setiap koreksi aktif dengan Tolak (Checker) atau Batalkan (Maker);
(2) matikan flag `FEATURE_RME_INVOICE_CORRECTION` lewat environment dan bangun
ulang config cache sebagai `daengtisiams`. Urutan ini wajib: Tolak dan Batalkan
ikut mati bila flag OFF, sedangkan blokir D10 **tidak** — tagihan dengan koreksi
aktif akan tertahan sampai flag menyala lagi. Koreksi yang sudah diterapkan,
event, audit, dan versi dokumen **tidak pernah** dihapus atau dikembalikan.

## Matriks

| Kode | Kondisi | Tindakan aman segera | Penanggung jawab | Eskalasi | Bukti | Syarat mulai lagi |
|---|---|---|---|---|---|---|
| **A** | Owner tidak dapat dihubungi / belum menyetujui | Jangan ajukan. Jangan ubah tagihan. Jangan pungut nominal yang diketahui salah seolah-olah benar. Pasien tidak ditahan. | Kasir | Manajemen klinik; pertanyaan terbuka **OPS-OQ-1** ke Owner | Catatan kasus `NOT_EXECUTED` + alasan singkat | Persetujuan tertulis Owner untuk kasus **baru** yang memenuhi semua syarat |
| **B** | Maker (user 29) tidak tersedia | Jangan ajukan dengan akun lain (sistem menolak `PILOT_IDENTITY_NOT_AUTHORIZED`). Jangan login sebagai user 29. | Kasir | Owner | `NOT_EXECUTED` — "Maker tidak tersedia" | User 29 bertugas dan login sendiri |
| **C** | Checker (user 1) tidak tersedia setelah pengajuan | Pembayaran tetap terblokir. Jika pasien tidak dapat menunggu: **Maker membatalkan** pengajuannya (alasan wajib) agar blokir lepas. Membatalkan **tidak** berarti boleh menagih nominal asli bila kesalahan masih diketahui (lihat H). | Maker | Owner; manajemen untuk keputusan atas pasien | Nomor koreksi, status Dibatalkan | Checker tersedia + persetujuan Owner (baru) |
| **D** | Tagihan Rp0 | Jangan ajukan; sistem menolak `CORRECTION_ORIGINAL_TOTAL_ZERO`. Jangan menerbitkan tagihan baru, jangan mengubah tagihan. | Kasir | Owner (D21g, D22 **PENDING**) | Nomor invoice + kode penolakan | Keputusan Owner atas D21g/D22 **dan** perubahan kode yang direview |
| **E** | Tagihan PARTIAL (dibayar sebagian) | Jangan ajukan; di luar pilot. Tidak ada refund/pembalikan. | Kasir | Owner (koreksi setelah pembayaran = FUTURE_SCOPE) | Nomor invoice | Keputusan Owner + fitur baru (bukan pilot ini) |
| **F** | Tagihan PAID (lunas) | Sama dengan E. | Kasir | Owner | Nomor invoice | Sama dengan E |
| **G** | Blokir D10 menolak pembayaran | Jangan kirim ulang, jangan cari jalan lain. Tunggu keputusan Checker, atau Maker membatalkan. | Kasir | Maker → Checker | Pesan `[INVOICE_CORRECTION_ACTIVE]` (audit tercatat otomatis) | Koreksi Diterapkan / Ditolak / Dibatalkan / Kedaluwarsa, lalu **muat ulang** halaman |
| **H** | Koreksi ditolak | Tagihan tetap asli; blokir D10 lepas. Tagih nominal asli **hanya** bila Checker/Owner menyimpulkan tagihan asli benar. Bila kesalahan masih diketahui: jangan pungut nominal asli seolah-olah benar. | Kasir | Manajemen untuk keputusan pembayaran (OPS-OQ-1); Owner bila kesalahan masih ada | Nomor koreksi, status Ditolak, alasan | Kasus baru + persetujuan Owner baru |
| **I** | Koreksi dibatalkan | Sama dengan H. | Kasir | Manajemen (OPS-OQ-1); Owner bila kesalahan masih ada | Nomor koreksi, status Dibatalkan | Kasus baru + persetujuan Owner baru |
| **J** | Setujui & Terapkan gagal (pesan galat) | Jangan klik berulang. Baca kode di pesan. Koreksi tetap Menunggu Persetujuan (atau Kedaluwarsa); tidak ada yang diterapkan sebagian. | Checker | IT (kode galat), Owner | Kode galat, nomor koreksi | Penyebab hilang (mis. login dengan akun yang benar); jika tidak bisa → **Tolak** |
| **K** | Versi tagihan berubah (Kedaluwarsa / `INVOICE_VERSION_CHANGED`) | Koreksi Kedaluwarsa tidak dapat diterapkan; blokir D10 lepas. Penagihan mengikuti SOP §9.1 (nominal yang diketahui salah tidak dipungut seolah-olah benar). Formulir pembayaran lama: muat ulang. | Checker / Kasir | IT bila perubahan tagihan tidak dapat dijelaskan | Kode `CORRECTION_STALE:<alasan>` | Pengajuan baru terhadap tagihan terkini + persetujuan Owner |
| **L** | Cabang salah (bukan SPN4) | Tidak ada tombol koreksi; halaman pengajuan 404. Jangan mencoba lewat cabang lain. | Kasir / Maker | Owner (menambah cabang = keputusan baru + commit) | — | Keputusan Owner + perubahan kode yang direview |
| **M** | Identitas Maker salah (akun selain 29) | Sistem menolak (403 / `PILOT_IDENTITY_NOT_AUTHORIZED`). Jangan berbagi kata sandi. | Pemegang akun | Owner; IT bila ada percobaan berulang | Pesan penolakan/403; audit `RME_INVOICE_CORRECTION_REFUSED` hanya bila penolakan terjadi di layanan (bukan di lapisan halaman) | User 29 sendiri yang mengajukan |
| **N** | Identitas Checker salah (akun selain 1) | Tombol keputusan tidak muncul ("Akun Anda tidak ditunjuk…"); sistem menolak. | Pemegang akun | Owner; IT | Pesan penolakan/403; audit `RME_INVOICE_CORRECTION_REFUSED` hanya bila penolakan terjadi di layanan | User 1 sendiri yang memutuskan |
| **O** | Akun Maker/Checker tidak aktif | Sistem mengakhiri sesi dan menolak tindakan (`ACTOR_UNUSABLE`). Jangan mengaktifkan ulang tanpa keputusan Owner. Pengajuan SUBMITTED tetap dapat ditolak Checker. | IT | Owner | Kode `ACTOR_UNUSABLE` | Reaktivasi yang disetujui Owner dan diaudit; untuk baris lama APPROVED lihat panduan Maker–Checker §6.3 |
| **P** | Jaringan terputus saat mengirim | Jangan kirim ulang. Muat ulang halaman tagihan, lihat panel *Koreksi Tagihan* / status koreksi. Sistem hanya mengizinkan satu koreksi aktif per tagihan; persetujuan ganda tidak menerapkan dua kali. | Maker / Checker | IT bila status tidak jelas | Status dicatat sebagai teks (nomor koreksi, status, waktu) — **bukan** tangkapan layar mentah, karena halaman menampilkan nama dan nomor rekam medis pasien | Status terverifikasi |
| **Q** | Pasien tidak dapat tetap di klinik | **Jangan menahan pasien.** Kasir tidak memutuskan kredit/pembayaran sendiri. Bila koreksi sudah diajukan dan tidak dapat diputuskan segera: Maker membatalkan agar blokir lepas; keputusan atas pasien dan pembayarannya oleh manajemen — nominal yang diketahui salah tidak dipungut seolah-olah benar. | Kasir → manajemen | Owner (**OPS-OQ-1**) | Catatan `NOT_EXECUTED` atau nomor koreksi Dibatalkan | Kasus baru di sesi klinik nyata |
| **R** | Rekonsiliasi keuangan gagal | **Berhenti.** Jangan menambal data. Simpan bukti read-only. IT menghentikan pilot (penghentian darurat di atas) atas keputusan Owner. Cacat ditangani lewat sprint perbaikan penuh (uji gagal → perbaikan → review keamanan → CI → merge → deploy di VPS). | IT | Owner | Formulir rekonsiliasi FAIL + keluaran query | Cacat diperbaiki dan diverifikasi di produksi; Owner menyetujui pilot dibuka lagi |

## Kode yang mungkin terlihat

### Ditampilkan dalam pesan di layar (kode dalam kurung siku)

| Kode | Arti | Insiden |
|---|---|---|
| `CORRECTION_ORIGINAL_TOTAL_ZERO` | Total asli Rp0 — dilarang sementara (D28) | D |
| `CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE` | Total asli tidak dapat dibuktikan dari dokumen v0 | J, R |
| `CORRECTION_INVOICE_TOTAL_NEGATIVE` | Total tagihan / total asli negatif — menunggu keputusan Owner | J, R |
| `CORRECTION_INVOICE_NOT_UNPAID` | Tagihan tidak berstatus Belum Dibayar | E, F |
| `CORRECTION_TYPE_NOT_ENABLED` | Sudah ada pembayaran / jenis koreksi belum aktif | E, F |
| `CORRECTION_ALREADY_ACTIVE` | Sudah ada pengajuan aktif untuk tagihan ini | P |
| `CORRECTION_VISIT_CANCELLED` | Kunjungan dibatalkan | — |
| `CORRECTION_LAB_CANDIDATES_EXIST` | Tagihan sudah menghasilkan kandidat lab (D23) | — |
| `CORRECTION_STALE:<alasan>` | Tagihan berubah sejak pengajuan; alasan: `INVOICE_REMOVED`, `PAYMENT_RECORDED`, `INVOICE_STATUS_CHANGED`, `INVOICE_VERSION_CHANGED`, `INVOICE_CONTENT_CHANGED`, `LAB_CANDIDATES_EXIST` | K |
| `CORRECTION_STATE_INVALID` | Status pengajuan tidak mengizinkan tindakan itu | J |
| `INVOICE_CORRECTION_ACTIVE` | Pembayaran ditolak D10 | G |
| `INVOICE_VERSION_CHANGED` | Formulir pembayaran lama setelah koreksi diterapkan | K |
| `INVOICE_VERSION_MISSING` | Formulir pembayaran tidak memuat versi tagihan — muat ulang halaman | K |

### Tidak tampil di layar — hanya tercatat di audit `RME_INVOICE_CORRECTION_REFUSED`

Penolakan otorisasi tampil sebagai halaman/pesan **403** (atau 404 di luar
cabang pilot) **tanpa kode**. Kodenya hanya ada di baris audit bila penolakan
terjadi di layanan; IT membacanya read-only.

| Kode audit | Arti | Insiden |
|---|---|---|
| `PILOT_IDENTITY_NOT_AUTHORIZED` | Akun bukan identitas pilot | B, M, N |
| `PILOT_IDENTITY_ACTOR_UNUSABLE` | Akun identitas pilot tidak aktif / terhapus | O |
| `SELF_APPROVAL_FORBIDDEN` / `SELF_APPLICATION_FORBIDDEN` | Pengaju mencoba memutuskan / menerapkan pengajuannya sendiri | M |
| `BRANCH_NOT_IN_PILOT` / `BRANCH_OUT_OF_SCOPE` | Cabang di luar pilot / di luar cakupan akun | L |
| `ACTOR_UNUSABLE` | Akun tidak aktif / terhapus | O |
| `PERMISSION_MISSING` / `NOT_THE_MAKER` | Akun tanpa izin / pembatalan oleh bukan pengaju | M, N |
