# SOP Dokter — "Tindakan Dilakukan" pada halaman RME

**Berlaku hanya bila fitur `rme.doctor_performed_treatments` diaktifkan Owner** (saat ini: NONAKTIF). Aturan
kanonik: `.cursor/rules/219-rme-doctor-performed-treatments.mdc`.

## Siapa dan kapan

- Hanya **dokter yang menangani kunjungan** (dokter yang tercatat pada kunjungan), dengan akun dokter aktif.
  Perawat, Admin Klinik, Kasir dan Super Admin tidak dapat mencatat atas nama dokter.
- Hanya setelah **"Mulai Pemeriksaan"** dan **Persetujuan Tindakan Medis ditandatangani**, dan sebelum
  **"Selesai Pemeriksaan"**. Setelah pemeriksaan selesai, catatan hanya dapat dibaca.
- Mencatat tindakan **tidak** menyelesaikan pemeriksaan. Tekan "Selesai Pemeriksaan" seperti biasa.

## Langkah

1. Buka halaman RME kunjungan, kartu **"Tindakan Dilakukan"**.
2. Bila melakukan tindakan: pilih **"Ada Tindakan"**, lalu tambahkan setiap tindakan dari daftar (jumlah, dan gigi
   bila relevan — nomor FDI). Satu baris per tindakan; tindakan yang sama pada gigi yang sama tidak dicatat dua kali.
3. Bila **tidak** melakukan tindakan apa pun: pilih **"Tidak Ada Tindakan"** dan centang konfirmasi. Kunjungan
   akan tercatat sebagai **Kontrol operasional**.
4. Daftar kosong **bukan** pernyataan "tidak ada tindakan" — statusnya "Belum Dicatat". Bila memang tidak ada
   tindakan, nyatakan secara eksplisit.

## Bila salah catat (masih dalam pemeriksaan)

- Pilih **Batalkan** pada baris yang salah, tulis alasannya, lalu catat ulang yang benar. Baris tidak dihapus; ia
  tetap tersimpan sebagai riwayat "dibatalkan".
- Untuk berpindah dari "Ada Tindakan" ke "Tidak Ada Tindakan", batalkan dulu semua baris tindakan.

## Bila salah catat setelah "Selesai Pemeriksaan"

Tidak ada cara mengubahnya di aplikasi (menunggu keputusan Owner DPT-Q4). Laporkan ke penanggung jawab cabang.
Jangan meminta perubahan lewat database.

## Yang perlu dipahami

- "Kontrol operasional" adalah **klasifikasi**, bukan tarif. Ia **tidak** membuat kunjungan gratis, tidak mengubah
  jenis kunjungan saat pendaftaran, dan tidak menghubungkan kunjungan ke kunjungan sebelumnya. Kasir tetap
  menentukan harga (D21); "Selesai Tanpa Biaya" tetap mengikuti syarat rule 217.
- Catatan ini dibaca kasir dan Oversight sebagai **peringatan** bila ada tindakan yang tidak ada di tagihan. Ia
  tidak menolak penutupan tagihan (keputusan Owner CB-Q2).
- Jangan menulis nama pasien, NIK, atau catatan klinis bebas pada alasan pembatalan.
