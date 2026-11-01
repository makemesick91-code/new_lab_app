# SOP Kasir — "Selesai Tanpa Biaya" (Konsultasi & Kontrol Rp0)

**Rule:** `.cursor/rules/217-rme-rp0-consultation-control.mdc` · **Keputusan Owner:** S6-D1 … S6-D8, S6-Q1, S6-Q2, S6-Q3 (revisi), S6-Q3a, S6-Q3b (2026-11-01)
**Status fitur:** dibangun, flag `rme.zero_value_closure` **OFF**. SOP ini berlaku setelah Owner
mengaktifkan fitur (`ACTIVATION-RME-RP0-CONTROL-CONSULTATION-1`). Sebelum itu tombol tidak muncul.

## Kapan dipakai

Hanya untuk tagihan bernilai **Rp0** yang memang gratis:

- **Konsultasi Dokter** (tindakan "Konsultasi Dokter" dengan harga Rp0), atau
- kunjungan **Kontrol** yang dibuat dari kunjungan sebelumnya yang **sudah diperiksa**, berisi **hanya**
  layanan Kontrol dan/atau Konsultasi Dokter dengan harga Rp0.

**Kontrol dengan tindakan lain.** Bila pada kunjungan Kontrol dokter juga melakukan tindakan yang
normalnya berbayar (scaling, tambal, dll.), tindakan itu **wajib ditagih dengan harga lebih dari Rp0**.
Layanan Kontrol/Konsultasinya boleh tetap Rp0. Total tagihan menjadi lebih dari Rp0, jadi tagihan
**dibayar seperti biasa** ("Bayar Sekarang") — **bukan** "Selesai Tanpa Biaya". Saat fitur aktif, sistem
menolak membuat tagihan Kontrol yang berisi tindakan berbayar dengan harga Rp0, dengan pesan:
*"Pada kunjungan Kontrol, hanya layanan Kontrol dan Konsultasi Dokter yang boleh Rp0. Tindakan lain yang
normalnya berbayar wajib diberi harga lebih dari Rp0."* Untuk tindakan yang memerlukan pekerjaan lab, sistem
meminta harga lebih dari Rp0 agar dibayar dan permintaan lab dibuat seperti biasa. Sistem juga menolak:
**diskon 100%** pada item apa pun di kunjungan Kontrol (layanan Kontrol/Konsultasi diberi harga Rp0
langsung, bukan didiskon), **voucher** di kunjungan Kontrol, dan item Rp0 apa pun bila kunjungan Kontrol
**tidak terhubung** ke kunjungan sebelumnya yang sudah diperiksa (laporkan ke penanggung jawab cabang).

**Tidak boleh** untuk: voucher (Scalling Voucher Gratis), amal, layanan komplimenter, item yang menjadi
Rp0 karena **diskon**, item yang memerlukan **pekerjaan lab**, tagihan yang pernah dikoreksi, atau tagihan
yang sedang **dalam peninjauan (sengketa)**. Sistem menolak semua ini secara otomatis.

**Tidak dapat dibatalkan.** Setelah "Tanpa Biaya", tagihan tidak bisa dikoreksi, di-refund, atau
disengketakan. Pastikan sebelum menekan tombol.

## Langkah

1. Buka **Kasir RME → Detail Tagihan** kunjungan tersebut.
2. Jika layak, muncul kartu **"Selesai Tanpa Biaya"** (tombol "Bayar Sekarang" tidak muncul). Jika **tidak**
   layak, muncul alasan penolakan dan tombol "Bayar Sekarang" tetap ada seperti biasa.
3. Pastikan ke pasien bahwa kunjungan ini memang tidak dikenakan biaya.
4. Centang **"Saya memastikan pasien tidak dikenakan biaya untuk kunjungan ini"**.
5. Tekan **Selesai Tanpa Biaya**.
6. Hasil: status tagihan **"Tanpa Biaya"** (bukan "Lunas"), kunjungan **selesai**, **tidak ada pembayaran** dan
   **tidak ada kwitansi**.

### Peringatan "Periksa dulu: mungkin ada tindakan berbayar" (rule 218)

Kartu "Selesai Tanpa Biaya" dapat menampilkan peringatan kuning bila sistem menemukan tanda bahwa tindakan
berbayar mungkin dilakukan:

- **Tindakan Awal** kunjungan ini bukan layanan gratis yang disetujui (Konsultasi Dokter / Kontrol) dan tidak ada
  di tagihan; dan/atau
- **status gigi berubah** dibanding odontogram sebelumnya menjadi tambalan, mahkota, perawatan saluran akar, atau
  hilang. Ini berarti *mungkin* ada tindakan — bukan bukti bahwa tindakan dilakukan pada kunjungan ini. Pada
  Kontrol, odontogram sebelumnya biasanya milik kunjungan asal; peringatan menyebutkannya, karena tindakan itu
  mungkin sudah dilakukan dan ditagih di sana.
- **dokter mencatat tindakan** di "Tindakan Dilakukan" (rule 219, hanya bila fitur itu aktif) dan tindakan itu
  bukan layanan gratis yang disetujui serta tidak ada di tagihan. Ini catatan dokter sendiri — tanda paling kuat.
  Panel "Ringkasan Klinis (Dokter)" menampilkan "Tindakan Dokter" dengan label "Belum ada di tagihan".

Catatan: bila dokter menyatakan **"Tidak Ada Tindakan"**, kunjungan dicatat sebagai **Kontrol operasional**. Itu
**bukan** izin otomatis untuk "Selesai Tanpa Biaya" — syarat Kontrol Rp0 tetap kunjungan terdaftar sebagai Kontrol
dengan kunjungan asal yang nyata (S6-Q1), dan baris tagihan tetap layanan gratis yang disetujui (S6-Q3).

Ini **peringatan, bukan penolakan** (keputusan Owner CB-Q2) — tombol tetap ada. Sebelum menekannya:

1. Konfirmasi ke dokter apakah tindakan berbayar memang dilakukan.
2. Bila **ya**: **jangan** selesaikan tanpa biaya, dan jangan mengubah apa pun sendiri. Laporkan ke penanggung
   jawab cabang sebagai insiden (formulir di bagian "Jika terjadi kesalahan"). Saat ini **tidak ada cara di
   aplikasi** untuk menambah tindakan berbayar pada tagihan yang sudah dibuat Rp0: tagihan Rp0 asli tidak dapat
   dikoreksi (D28, rule 201), tagihan RME tidak dapat dibatalkan, dan satu kunjungan hanya punya satu tagihan
   aktif. Tagihan tetap terbuka dan kunjungan tetap menunggu kasir sampai ada keputusan Owner.
3. Bila **tidak** (mis. Tindakan Awal berubah setelah pemeriksaan): lanjutkan langkah di atas.

Pencegahan terbaik ada **sebelum** tagihan dibuat: bila dokter melakukan tindakan berbayar, tagih tindakan itu
dengan harga lebih dari Rp0 sejak awal (S6-Q3).

Hasil pemeriksaan ini **tersimpan bersama penutupan** dan terlihat oleh Oversight. Tidak adanya peringatan
**tidak** berarti tagihan pasti sesuai rekam medis — RME tulisan tangan tidak dapat diperiksa sistem.

## Jika ditolak

Pesan penolakan selalu memuat kode, misalnya `[ZERO_VALUE_LINE_NOT_FREE]`.

| Kode | Arti | Tindakan |
|---|---|---|
| `ZERO_VALUE_TOTAL_NOT_ZERO` | Total bukan Rp0 | Tagih seperti biasa |
| `ZERO_VALUE_LINE_NOT_FREE` | Ada item Rp0 karena diskon | Bukan layanan gratis — hubungi penanggung jawab cabang |
| `ZERO_VALUE_VOUCHER_EXCLUDED` | Ada voucher | Di luar fitur ini |
| `ZERO_VALUE_CATEGORY_NOT_ELIGIBLE` | Ada item Rp0 yang bukan layanan Kontrol/Konsultasi | Tindakan itu harus ditagih > Rp0 — hubungi penanggung jawab cabang |
| `ZERO_VALUE_CONTROL_PARENT_INVALID` | Kontrol tidak terhubung ke kunjungan sebelumnya yang sudah diperiksa (dibatalkan/belum diperiksa/pasien lain) | Laporkan ke penanggung jawab cabang |
| `ZERO_VALUE_LAB_LINE_EXCLUDED` | Ada item yang memerlukan pekerjaan lab | Proses melalui pembayaran agar permintaan lab dibuat |
| `ZERO_VALUE_VISIT_NOT_PENDING` | Kunjungan sudah tidak menunggu kasir | Jangan diubah manual; laporkan |
| `ZERO_VALUE_INVOICE_CORRECTED` / `ZERO_VALUE_HAS_PAYMENTS` | Pernah dikoreksi / sudah ada pembayaran | Laporkan ke penanggung jawab cabang |
| "dalam peninjauan" | Ada sengketa aktif | Tunggu hasil peninjauan (rule 203) |

## Piutang kunjungan sebelumnya

Menutup Kontrol tanpa biaya **tidak** menutup piutang kunjungan sebelumnya. Tagih piutang itu melalui
**tagihan asalnya** atau dari halaman pembayaran Kontrol seperti biasa. Bila piutang lama dibayar dari
halaman Kontrol, pembayaran itu **hanya** untuk tagihan asal; tagihan Kontrol Rp0 tetap terbuka dan
kunjungan tetap menunggu kasir. Sistem menampilkan: *"Pembayaran piutang sebelumnya tercatat. … Tagihan Rp0
kunjungan ini belum selesai — selesaikan dengan "Selesai Tanpa Biaya"."* Lanjutkan dengan langkah di atas.

## Larangan

- Jangan mencatat pembayaran Rp1 atau nominal simbolis untuk "menutup" tagihan.
- Jangan mengubah harga atau diskon agar tagihan menjadi Rp0.
- Jangan meminta perubahan status langsung di database.

## Jika terjadi kesalahan (insiden "Tanpa Biaya")

Status "Tanpa Biaya" **tidak dapat dibatalkan di aplikasi** — tidak ada tombol, koreksi, refund, atau
pembalikan. Bila sebuah tagihan ternyata keliru ditutup tanpa biaya:

1. **INCIDENT_REPORTED** — kasir segera melapor ke penanggung jawab cabang: nomor invoice, nomor kunjungan,
   waktu, dan apa yang keliru. Jangan mencoba "memperbaiki" sendiri.
2. **FINANCIAL_REVIEW** — Finance Klinik / Owner memeriksa di **Oversight → Billing Correction → Tanpa Biaya
   (Rp0)** (siapa membuat tagihan, siapa menutup, dokter, tindakan).
3. **OWNER_AUTHORIZATION_IF_REQUIRED** — setiap tindak lanjut yang menyangkut uang pasien memerlukan
   keputusan Owner tertulis.
4. **AUDIT_PRESERVATION** — catatan tagihan dan jejak audit tidak boleh diubah atau dihapus.
5. **APPROVED_RECOVERY_PATH** — **belum ada jalur pemulihan yang disetujui dan diimplementasikan.**
   Keterbatasan ini dicatat; pemulihan apa pun memerlukan keputusan Owner dan sprint terpisah.

Dilarang: mengubah status langsung di database, membuat pembayaran fiktif, menerbitkan tagihan baru untuk
"menggantikan" tanpa keputusan Owner, atau menghapus catatan.
