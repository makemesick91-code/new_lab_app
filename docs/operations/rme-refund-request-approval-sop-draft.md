# SOP (DRAFT) — Pengajuan & Persetujuan Refund RME

> **DRAFT. Belum berlaku. Fitur belum dibangun dan belum aktif.** SOP ini disiapkan oleh
> `PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1` dan harus diperbarui mengikuti keputusan Owner
> RF-1…RF-8 (`docs/decisions/rme-refund-request-approval-owner-decision-package.md`) sebelum dipakai.
> Bagian bertanda **[RF-n]** bergantung pada keputusan itu. Per 2026-10-31 Owner sudah menjawab RF-1, RF-2,
> RF-4 dan audit permanen (paket §7); bagian yang bertanda selain itu masih menunggu.

## 1. Kapan refund boleh diajukan

Refund hanya untuk **kelebihan bayar yang sudah tercatat oleh sistem**: tagihan berstatus Lunas yang
memiliki **"Kewajiban Kelebihan Bayar — Belum Dikembalikan"**. Kewajiban itu lahir dari koreksi tagihan
setelah pembayaran yang sudah disetujui dan diterapkan.

Refund **bukan** untuk:

* Salah input pembayaran (uang tidak pernah diterima atau tercatat dua kali) — itu **pembalikan**, bukan
  refund, dan belum tersedia. Laporkan ke IT/Owner. **[RF-13]**
* Mengubah harga tagihan — gunakan koreksi tagihan atau laporan ketidaksesuaian.
* Menyimpan sebagai saldo/deposit pasien — tidak tersedia. **[RF-12]**
* Memakai kelebihan bayar untuk melunasi tagihan lain — tidak tersedia.

Bila tagihan sedang memiliki **laporan ketidaksesuaian aktif**, refund tidak dapat diajukan sampai
laporan itu selesai. **[RF-7]**

## 2. Mengajukan (hanya Front Office, untuk cabang kerja yang ditugaskan) **[RF-1, disetujui]**

Kasir dan Admin Klinik **tidak** mengajukan refund. Sebelum mengajukan, minta **referensi persetujuan
Owner yang baru untuk kasus ini** — pengajuan tanpa referensi itu ditolak sistem.

1. Buka tagihan pasien di cabang Anda. Pastikan statusnya menampilkan kewajiban kelebihan bayar.
2. Tekan **Ajukan Refund**. Jumlah sudah diisi sistem dan **tidak dapat diubah** — jumlahnya sama
   dengan kewajiban; tidak ada refund sebagian. **[RF-4, disetujui]**
3. Isi alasan dengan jelas (tanpa NIK, tanpa nomor rekening), lalu isi **referensi persetujuan Owner**
   untuk kasus ini — bukan referensi koreksi sumbernya, dan bukan referensi yang pernah dipakai untuk
   koreksi atau refund lain (sistem menolaknya, termasuk yang hanya berbeda huruf besar/kecil). **[RF-1]**
4. Isi **referensi permintaan pasien** (misalnya nomor formulir permintaan refund). **[RF-5]**
5. Kirim satu kali. Bila halaman lambat, **jangan** menekan ulang; muat ulang halaman tagihan untuk
   melihat apakah pengajuan sudah tercatat.

Tidak ada uang yang diserahkan kepada pasien pada tahap ini. Sampaikan kepada pasien bahwa permintaannya
sedang diproses.

## 3. Meninjau (Super Admin — semua cabang; Supervisor RME — cakupannya; Kepala Cabang — cabangnya sendiri) **[RF-1, disetujui]**

1. Anda **tidak boleh** memeriksa pengajuan yang Anda buat sendiri — sistem akan menolak.
2. Periksa: pasien, tagihan, jumlah, alasan, referensi permintaan pasien, dan riwayat koreksi sumbernya.
3. **Setujui** hanya bila pengajuan membawa referensi persetujuan Owner untuk kasus ini dan datanya benar.
   Peninjau **tidak** memasukkan referensi baru; referensi sudah dicantumkan saat pengajuan. **[RF-1]**
4. **Tolak** dengan catatan bila data tidak benar. Penolakan **tidak menghapus utang** kepada pasien;
   pengajuan baru dapat dibuat setelah data diperbaiki. **[RF-6]**

## 4. Arti status

| Status di layar | Artinya | Uang sudah keluar? |
|---|---|---|
| Diajukan | Menunggu pemeriksa | Tidak |
| **Disetujui — belum dibayarkan** | Klinik akan membayar kembali | **Tidak** |
| Ditolak | Pengajuan ini tidak diterima; utang tetap ada | Tidak |
| Dibatalkan | Ditarik pembuat, atau dicabut pemeriksa sebelum dibayar | Tidak |
| Kedaluwarsa | Data tagihan berubah sejak diajukan; ajukan ulang | Tidak |

**PERSETUJUAN BUKAN PEMBAYARAN.** Pembayaran kembali dicatat terpisah pada tahap berikutnya (Sprint 5),
dengan metode dan bukti yang ditetapkan Owner. **[RF-2, RF-9]** Jangan menyerahkan uang tunai atau
melakukan transfer hanya karena status "Disetujui".

## 5. Larangan

* Mengubah pembayaran, tagihan, atau kewajiban secara langsung, atau meminta IT melakukannya.
* Membuat pembayaran, tagihan, atau koreksi palsu untuk "menyeimbangkan".
* Memakai akun orang lain, atau memakai dua akun untuk mengajukan dan menyetujui sendiri.
* Menuliskan NIK, nomor rekening, atau data identitas di kolom alasan atau referensi.
* Menjanjikan tanggal pembayaran di luar ketentuan tercetak yang berlaku. **[RF-14]**

## 6. Eskalasi

Data tidak sesuai, sistem menolak dengan kode yang tidak dipahami, atau pasien keberatan: hentikan,
catat kode penolakan yang tampil, dan laporkan ke penanggung jawab cabang dan IT. Jangan mencoba ulang
berkali-kali.
