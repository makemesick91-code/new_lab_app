# SOP Kasir — "Selesai Tanpa Biaya" (Konsultasi & Kontrol Rp0)

**Rule:** `.cursor/rules/217-rme-rp0-consultation-control.mdc` · **Keputusan Owner:** S6-D1 … S6-D8 (2026-11-01)
**Status fitur:** dibangun, flag `rme.zero_value_closure` **OFF**. SOP ini berlaku setelah Owner
mengaktifkan fitur (`ACTIVATION-RME-RP0-CONTROL-CONSULTATION-1`). Sebelum itu tombol tidak muncul.

## Kapan dipakai

Hanya untuk tagihan bernilai **Rp0** yang memang gratis:

- **Konsultasi Dokter** (tindakan "Konsultasi Dokter" dengan harga Rp0), atau
- kunjungan **Kontrol** yang dibuat dari kunjungan sebelumnya yang **sudah diperiksa** (semua item Rp0).

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

## Jika ditolak

Pesan penolakan selalu memuat kode, misalnya `[ZERO_VALUE_LINE_NOT_FREE]`.

| Kode | Arti | Tindakan |
|---|---|---|
| `ZERO_VALUE_TOTAL_NOT_ZERO` | Total bukan Rp0 | Tagih seperti biasa |
| `ZERO_VALUE_LINE_NOT_FREE` | Ada item Rp0 karena diskon | Bukan layanan gratis — hubungi penanggung jawab cabang |
| `ZERO_VALUE_VOUCHER_EXCLUDED` | Ada voucher | Di luar fitur ini |
| `ZERO_VALUE_CATEGORY_NOT_ELIGIBLE` | Bukan Konsultasi dan bukan Kontrol | Tidak dapat ditutup tanpa biaya |
| `ZERO_VALUE_CONTROL_PARENT_INVALID` | Kontrol tidak terhubung ke kunjungan sebelumnya yang sudah diperiksa (dibatalkan/belum diperiksa/pasien lain) | Laporkan ke penanggung jawab cabang |
| `ZERO_VALUE_LAB_LINE_EXCLUDED` | Ada item yang memerlukan pekerjaan lab | Proses melalui pembayaran agar permintaan lab dibuat |
| `ZERO_VALUE_VISIT_NOT_PENDING` | Kunjungan sudah tidak menunggu kasir | Jangan diubah manual; laporkan |
| `ZERO_VALUE_INVOICE_CORRECTED` / `ZERO_VALUE_HAS_PAYMENTS` | Pernah dikoreksi / sudah ada pembayaran | Laporkan ke penanggung jawab cabang |
| "dalam peninjauan" | Ada sengketa aktif | Tunggu hasil peninjauan (rule 203) |

## Piutang kunjungan sebelumnya

Menutup Kontrol tanpa biaya **tidak** menutup piutang kunjungan sebelumnya. Tagih piutang itu melalui
**tagihan asalnya**. Jangan membayar piutang lama dari halaman Kontrol Rp0 sebelum menutupnya — itu
membuat tagihan Rp0 tetap "Belum Dibayar".

## Larangan

- Jangan mencatat pembayaran Rp1 atau nominal simbolis untuk "menutup" tagihan.
- Jangan mengubah harga atau diskon agar tagihan menjadi Rp0.
- Jangan meminta perubahan status langsung di database.
