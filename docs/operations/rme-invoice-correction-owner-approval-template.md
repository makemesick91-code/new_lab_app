# Formulir Persetujuan Owner — Koreksi Tagihan Sebelum Pembayaran (Pilot SPN4)

Bagian dari SOP `docs/operations/rme-invoice-correction-pilot-sop-spn4.md`.
Aturan: rule 196 §3 (kasus hanya "tertangkap"), §9 (persetujuan harus spesifik
kasus), rule 202.

## Aturan pakai

1. **Satu formulir = satu tagihan.** Tidak ada persetujuan umum untuk tagihan
   yang belum ada atau belum diketahui.
2. Persetujuan harus menyebut **tagihan ini** dan **perubahan keuangannya**
   (item, total asli, total usulan, selisih).
3. **Tidak ada tanda tangan palsu, waktu karangan, atau nomor rujukan karangan.**
   Kolom yang belum diisi Owner dibiarkan kosong.
4. Otorisasi sprint, instruksi "selesaikan pilot", atau persetujuan di dokumen
   repositori **bukan** persetujuan kasus.
5. Formulir yang sudah terisi disimpan di **kanal internal klinik yang sah**
   (arsip kertas di klinik atau kanal komunikasi resmi dengan Owner), **tidak**
   di repositori Git, tidak di grup chat umum, tidak di layanan eksternal.
6. Jangan menulis NIK/KTP, nomor rekam medis, diagnosis, atau riwayat medis.
   Nama pasien tidak diperlukan — nomor invoice sudah cukup untuk menunjuk
   tagihan di sistem.
7. Jika ada satu saja kolom wajib yang kosong, Maker **tidak boleh**
   mengajukan dan Checker **tidak boleh** menyetujui.

## Nomor rujukan persetujuan

`OWNER_APPROVAL_REFERENCE` adalah label pendek yang menunjuk formulir ini tanpa
data pasien, ditulis **Owner sendiri** setelah menyetujui. Tidak ada orang lain
yang boleh menuliskannya atas nama Owner.
Format yang disarankan: `OWNER-COR-SPN4-YYYYMMDD-NN` (mis. urutan ke-1 hari
itu = `-01`). Ketentuan: 6–120 karakter, hanya huruf, angka, `.`, `_`, `:`, `/`,
`-`; tidak boleh berisi deretan 13 digit atau lebih (bentuk NIK/telepon).

Rujukan ini ditulis Maker di kolom **Alasan Koreksi** dan oleh Checker di
**Catatan persetujuan**, sehingga catatan di sistem tertaut ke formulir tanpa
menyalin data pasien.

## Formulir

```
==============================================================
PERSETUJUAN OWNER — KOREKSI TAGIHAN SEBELUM PEMBAYARAN
==============================================================

CASE_REFERENCE            : ______________________________
  (label kasus internal, tanpa data pasien)

BRANCH                    : SPN4 — Cabang Sunu
  (pilot hanya SPN4; cabang lain = TIDAK BOLEH diajukan)

INVOICE_REFERENCE         : RME-______-______
  (nomor invoice persis seperti di sistem)

DISCOVERED_AT             : ____-__-__ __:__ WITA
  (waktu kesalahan ditemukan, sebelum pembayaran)

ERROR_DESCRIPTION         : ______________________________
  (apa yang salah — tanpa NIK/diagnosis)

ORIGINAL_TOTAL            : Rp ______________
  (Total Asli di sistem; HARUS > Rp0)

PROPOSED_TOTAL            : Rp ______________
  (total setelah koreksi; HARUS > Rp0)

DIFFERENCE                : Rp ______________  (naik / turun)

CORRECTED_ITEMS           :
  No | Operasi (UBAH/HAPUS/TAMBAH) | Deskripsi | Qty | Harga satuan | Diskon | Subtotal
  1  | ____ | ________________ | ___ | Rp ________ | Rp ______ | Rp ________
  2  | ____ | ________________ | ___ | Rp ________ | Rp ______ | Rp ________
  (item yang tidak disebut = TETAP seperti aslinya)

CORRECTION_REASON         : ______________________________
  (alasan bisnis singkat; akan ditulis Maker di sistem, min. 10 karakter)

PAYMENT_STATUS_CONFIRMED  : [ ] Belum Dibayar   [ ] Nol pembayaran (termasuk sebagian)

MAKER_USER_ID             : 29   (Admin Sunu — satu-satunya pengaju pilot)
CHECKER_USER_ID           : 1    (IT Support — satu-satunya pemeriksa pilot)

OWNER_APPROVAL_STATUS     : [ ] DISETUJUI   [ ] DITOLAK   [ ] PERLU KLARIFIKASI
OWNER_APPROVAL_REFERENCE  : ______________________________
APPROVED_AT               : ____-__-__ __:__ WITA
  (diisi Owner sendiri; tidak boleh diisi orang lain dengan waktu karangan)

RECONCILIATION_OWNER      : ______________________________
  (siapa yang menandatangani formulir rekonsiliasi sesudah penerapan)

Tanda tangan / konfirmasi tertulis Owner : ______________________
==============================================================
```

## Siapa mengisi apa

| Kolom | Diisi oleh |
|---|---|
| CASE_REFERENCE, INVOICE_REFERENCE, DISCOVERED_AT, ERROR_DESCRIPTION, ORIGINAL_TOTAL, PAYMENT_STATUS_CONFIRMED | Kasir yang menemukan kesalahan |
| PROPOSED_TOTAL, DIFFERENCE, CORRECTED_ITEMS, CORRECTION_REASON | Kasir bersama Maker, lalu diperiksa Owner |
| OWNER_APPROVAL_STATUS, OWNER_APPROVAL_REFERENCE, APPROVED_AT, tanda tangan | **Owner sendiri** |
| RECONCILIATION_OWNER | Owner menunjuk |

## Yang diperiksa Checker sebelum menyetujui di sistem

- Checker menerima atau membaca formulir ini **langsung dari Owner** melalui
  kanal Owner sendiri — **bukan** dari salinan yang diteruskan Maker atau kasir.
  Rujukan di Alasan Koreksi diketik Maker; itu saja bukan bukti persetujuan.

- Nomor invoice di sistem sama dengan `INVOICE_REFERENCE`.
- Total Asli di sistem sama dengan `ORIGINAL_TOTAL`.
- Total Usulan di sistem sama dengan `PROPOSED_TOTAL`, dan setiap baris yang
  berubah sama dengan `CORRECTED_ITEMS`.
- Pengaju di sistem (*Diajukan oleh*) adalah **Admin Sunu** (akun user 29) —
  layar menampilkan nama akun, bukan nomor id.
- `OWNER_APPROVAL_STATUS = DISETUJUI` dan rujukan tercantum di Alasan Koreksi.

Ada satu perbedaan saja → **Tolak** dengan alasan, jangan setujui.
