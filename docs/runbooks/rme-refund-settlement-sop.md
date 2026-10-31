# SOP — Pencatatan Pembayaran Refund RME

Untuk: pembayar refund yang ditunjuk Owner, Kepala Cabang, dan Supervisor RME.
Aturan sistem: `.cursor/rules/216-rme-refund-settlement.mdc`.

**Status saat ini: fitur dimatikan (flag `rme.billing_refund_settlement` OFF) dan belum ada pembayar yang
ditunjuk. Tidak ada seorang pun yang dapat mencatat pembayaran refund sampai Owner menunjuk orangnya secara
tertulis dan menyetujui aktivasi.**

## 1. Prinsip

1. **DISETUJUI ≠ DIBAYARKAN.** Persetujuan refund hanya keputusan. Uang dianggap keluar hanya setelah
   pembayaran dicatat TELAH DIBAYARKAN dengan bukti.
2. **Sistem tidak terhubung ke bank.** Sistem tidak mengirim uang, tidak memeriksa mutasi rekening, dan tidak
   pernah mengulang transfer. Pembayaran dilakukan di luar sistem (m-banking/kas), lalu dicatat.
3. **Pembayar ≠ penyetuju.** Orang yang menyetujui refund tidak boleh membayarkannya. Sistem menolaknya.
   Akun yang berbeda belum tentu orang yang berbeda: jangan meminjam akun orang lain.
4. **Refund selalu penuh.** Tidak ada pembayaran sebagian, pembulatan, kredit pasien, atau potong tagihan lain.
5. **Waktu pencatatan = waktu server.** Tidak ada isian tanggal. Waktu transfer yang sebenarnya ada di bukti.

## 2. Alur

1. **Mulai pembayaran.** Menu *Pembayaran Refund* → *Refund disetujui — menunggu dibayarkan* → *Bayarkan*.
   Pilih metode (Transfer Bank / Tunai). Status menjadi **SEDANG DIBAYARKAN**. Uang **belum** tercatat keluar.
2. **Bayarkan di luar sistem.** Transfer ke rekening pasien yang sudah diverifikasi, atau serahkan tunai dan
   minta penerima menandatangani tanda terima.
3. **Konfirmasi dengan bukti** (hanya oleh orang yang memulai):
   * Transfer bank: referensi transfer dari bank (minimal 6 huruf/angka), nama bank tujuan, **4 digit
     terakhir** rekening (jangan pernah nomor lengkap), file bukti transfer (PDF/JPG/PNG). PDF yang
     dikunci kata sandi tidak diterima — unggah tangkapan layar (PNG/JPG) bila perlu.
   * Tunai: nomor tanda terima, penerima (pasien sendiri / wakil yang dikuasakan), foto/scan tanda terima
     bertanda tangan penerima.
   * Centang pernyataan bahwa uang telah diterima penerima. Status menjadi **TELAH DIBAYARKAN**; kewajiban
     pengembalian dan permintaan refund tertutup.

## 3. Jika hasil transfer tidak pasti

Contoh: aplikasi bank menampilkan "tertunda" atau koneksi terputus saat transfer.

1. **Jangan transfer ulang.**
2. Pilih *Laporkan Hasil Tidak Pasti* dan tulis keterangannya. Status menjadi **HASIL PEMBAYARAN TIDAK
   PASTI**.
3. Periksa rekening koran/mutasi. Lalu:
   * uang terkirim → *Catat Telah Dibayarkan* dengan bukti mutasi; atau
   * uang tidak terkirim → *Batalkan Pembayaran* dengan bukti bahwa uang tidak keluar (wajib).

## 4. Membatalkan

*Batalkan Pembayaran* hanya untuk pembayaran yang **tidak** jadi dilakukan, dan **hanya oleh pembayar yang
memulainya** — dialah yang tahu apakah transfer benar-benar dikirim. Pembayar lain hanya boleh melaporkan
hasil tidak pasti, lalu mengonfirmasi dengan bukti bank. Wajib alasan dan pernyataan bahwa uang tidak keluar. Permintaan refund tetap Disetujui dan dapat dibayarkan ulang. Pembayaran yang
sudah TELAH DIBAYARKAN tidak dapat diubah atau dihapus.

## 5. Hal yang ditolak sistem (dan apa artinya)

| Pesan | Arti | Tindakan |
|---|---|---|
| Akun Anda tidak ditunjuk untuk membayarkan refund | belum ada penunjukan Owner untuk Anda/cabang ini | hubungi Owner; jangan meminjam akun |
| Penyetuju … tidak dapat membayarkan refund yang ia setujui sendiri | pemisahan tugas | serahkan ke pembayar lain |
| … sudah memiliki pembayaran yang sedang diproses | ada orang lain yang sedang membayar | buka pembayaran itu; jangan membayar ganda |
| Referensi/bukti sudah dipakai | satu bukti hanya untuk satu refund (penulisan referensi berbeda tetap dianggap sama) | periksa ulang bukti |
| Hanya pembayar yang memulai … | pembatalan/konfirmasi awal milik pemulai | hubungi pemulai, atau laporkan hasil tidak pasti |
| Tagihan sedang disengketakan / dikoreksi | refund ditahan | tunggu sengketa/koreksi selesai |
| Nominal tidak sesuai | refund hanya penuh | muat ulang halaman |

## 6. Larangan

* Mencatat TELAH DIBAYARKAN sebelum uang benar-benar diterima penerima.
* Mengunggah bukti milik refund lain, atau bukti yang dibuat sendiri tanpa bukti bank/tanda tangan penerima.
* Menulis nomor rekening lengkap, NIK, atau data pasien di kolom referensi atau keterangan.
* Meminta Super Admin atau IT "membereskan" data lewat database. Koreksi selalu lewat batal (sebelum uang
  keluar) atau permintaan refund baru.
