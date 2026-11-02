# SOP Dokter — "Tindakan Dilakukan" pada halaman RME

**Berlaku hanya bila fitur `rme.doctor_performed_treatments` aktif untuk cabang kunjungan.** Keputusan Owner
DPT-Q8 (2026-11-02): pilot **hanya Cabang Sunu (SPN4)**; di cabang lain kartu ini tidak muncul dan server
menolak pencatatan. Status aktivasi terkini ada di rule 219 §14–§15. Aturan kanonik:
`.cursor/rules/219-rme-doctor-performed-treatments.mdc`.

Selama pilot: pernyataan **opsional** (pemeriksaan tetap bisa diselesaikan tanpa mengisinya), dan pemeriksaan
silang dengan tagihan hanya **peringatan** — tidak pernah menolak pembayaran atau "Selesai Tanpa Biaya".

## Siapa dan kapan

- Hanya **dokter yang menangani kunjungan** (dokter yang tercatat pada kunjungan), dengan akun dokter aktif.
  Perawat, Admin Klinik, Kasir dan Super Admin tidak dapat mencatat atas nama dokter.
- Hanya setelah **"Mulai Pemeriksaan"** dan **Persetujuan Tindakan Medis ditandatangani**, dan sebelum
  **"Selesai Pemeriksaan"**. Setelah pemeriksaan selesai, catatan hanya dapat dibaca.
- Mencatat tindakan **tidak** menyelesaikan pemeriksaan. Tekan "Selesai Pemeriksaan" seperti biasa.

## Yang dicatat: yang DILAKUKAN, bukan yang direncanakan

- Catat hanya tindakan yang **benar-benar dilakukan** pada kunjungan ini. Rencana perawatan, tindakan untuk kunjungan
  berikutnya, atau rujukan **bukan** tindakan dilakukan.
- Beberapa tindakan berbeda → satu baris per tindakan. Tindakan yang sama pada beberapa gigi → satu baris dengan
  jumlah dan nomor gigi FDI yang sesuai (nomor gigi opsional bila tidak relevan).
- Catatan ini **tidak menentukan harga**. Kasir tetap menetapkan tagihan (D21); dokter tidak perlu menghitung biaya.

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

Owner menyetujui arah DPT-Q4 (koreksi setelah selesai melalui alur yang terkendali dan teraudit), tetapi alur itu
**belum dibangun**. Saat ini tidak ada cara mengubahnya di aplikasi. Laporkan ke penanggung jawab cabang. Jangan
meminta perubahan lewat database, dan jangan meminta akun lain mencatat atas nama Anda.

## Yang perlu dipahami

- "Kontrol operasional" adalah **klasifikasi**, bukan tarif. Ia **tidak** membuat kunjungan gratis, tidak mengubah
  jenis kunjungan saat pendaftaran, dan tidak menghubungkan kunjungan ke kunjungan sebelumnya. Kasir tetap
  menentukan harga (D21); "Selesai Tanpa Biaya" tetap mengikuti syarat rule 217.
- Catatan ini dibaca kasir dan Oversight sebagai **peringatan** bila ada tindakan yang tidak ada di tagihan. Ia
  tidak menolak penutupan tagihan (keputusan Owner CB-Q2).
- Jangan menulis nama pasien, NIK, atau catatan klinis bebas pada alasan pembatalan.
- Konsultasi dan Kontrol adalah **layanan**, bukan tindakan, sehingga tidak ada di daftar pilihan (DPT-Q5). Pada
  kunjungan Konsultasi/Kontrol tanpa prosedur, "Tidak Ada Tindakan" adalah jawaban yang benar.
- Hanya dokter yang **resmi tercatat** pada kunjungan yang boleh mencatat (DPT-Q9). Bila dokter lain yang benar-benar
  menangani, kunjungan harus lebih dulu dialihkan kepadanya lewat alur resmi — bukan dicatat oleh akun lain.

## Melaporkan kesalahan atau kendala

- Fitur tidak muncul di SPN4, tombol menolak dengan kode `[DPT_…]`, atau hasil tampak salah: catat kode yang tampil,
  nomor kunjungan, dan waktu (WITA), lalu laporkan ke penanggung jawab cabang dan IT. Jangan mencoba lewat akun lain.
- Kode `[DPT_BRANCH_NOT_IN_PILOT]` berarti kunjungan bukan di cabang pilot — itu perilaku yang benar, bukan kerusakan.

## Bila tindakan dokter tidak ada di tagihan

Kasir dan Oversight akan melihat peringatan. Itu **bukan** izin untuk mengubah tagihan secara sepihak:

1. Jangan menekan "Selesai Tanpa Biaya" bila sebuah tindakan berbayar memang dilakukan.
2. Jangan memakai jalan pintas keuangan (void, tagihan pengganti, piutang buatan, hapus buku, SQL).
3. Bila tagihan ASLI kunjungan bernilai Rp0, koreksi tetap ditolak (D28). Catat insiden dan eskalasikan ke Owner.
   Arah pemulihan D28 (DPT-Q7) disetujui, tetapi belum ada operasi yang diizinkan.
