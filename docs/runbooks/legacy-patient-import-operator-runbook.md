# Legacy Patient Import — Operator Runbook

**Menu:** Master Data → Impor Pasien Legacy
**Permission:** `manage patients`

**Mengunggah berkas bukan mengimpor pasien.** Tidak ada satu pasien pun dibuat
sebelum Anda menekan **Konfirmasi & Import Pasien**.

---

## Menyiapkan berkas

1. Unduh **Template CSV** dari halaman impor. Gunakan urutan kolom template.
2. **Nomor KTP boleh dikosongkan.** Jika nomor KTP pasien tidak tersedia,
   **biarkan sel itu kosong.**

   **Jangan** mengisi `0`, `0000000000000000`, `9999999999999999`, `-`, `TMP`,
   atau angka apa pun sebagai pengganti. Nomor yang dikarang akan menjadi
   identitas permanen pasien yang salah, dan dua pasien dengan nomor karangan
   yang sama akan saling menolak sebagai duplikat.
3. **Cabang**, **Nomor RM Manual**, **Timestamp** dan **Nama Pasien** wajib diisi.
   Cabang harus cabang RME yang aktif. MAIN tidak dapat dipakai.
4. Kolom **Ruangan, Tindakan Awal, Keluhan Utama, TTD** hanya disimpan sebagai
   catatan. Kolom-kolom itu **tidak** masuk ke RME.

## Mengunggah dan meninjau

5. Unggah berkas. Sistem menyimpan berkas secara privat, mencatat sidik jari
   SHA256-nya, lalu memverifikasi **seluruh** baris.
6. Baca ringkasannya: **Total · Valid · Warning · Error**.
7. Gunakan filter status untuk memeriksa baris **error**, lalu baris **warning**.
8. Bila perlu, **Unduh Laporan Verifikasi** (CSV) untuk dibawa ke berkas sumber.

## Jika ada ERROR

> **Selama masih ada satu saja baris ERROR, seluruh batch ditolak. Tidak ada satu
> pasien pun yang diimpor — termasuk baris yang sudah lolos verifikasi.**

9. **Jangan** mencoba menekan konfirmasi. Permintaan akan ditolak oleh server.
10. Perbaiki **berkas sumber** di luar sistem (di aplikasi spreadsheet Anda).
11. Tekan **Batalkan Batch**. Isi alasan bila membantu (opsional). Pembatalan
    tidak membuat dan tidak menghapus pasien; riwayat batch tetap tersimpan.
12. Unggah berkas yang sudah diperbaiki sebagai batch baru.

## Jika ERROR = 0

13. Tetap periksa baris **warning** — warning tidak memblokir impor, tetapi
    warning adalah hal yang ingin Anda ketahui sebelum data masuk. Contoh:
    "kemungkinan duplikat: nama + tanggal lahir cocok dengan pasien yang sudah
    ada", atau "dokter tidak ditemukan di master".
14. Centang pernyataan **"Saya telah memeriksa hasil verifikasi batch"**.
15. Tekan **Konfirmasi & Import Pasien**.
16. Server memverifikasi ulang seluruh batch terhadap data master **terkini**,
    lalu mengimpor semuanya dalam satu transaksi.
17. Periksa jumlah akhir: **"N pasien legacy berhasil diimpor."**

## Jika konfirmasi ditolak padahal sebelumnya bersih

Itu perilaku yang benar, bukan kegagalan sistem. Antara Anda meninjau dan Anda
menekan konfirmasi, data master berubah — biasanya karena rekan Anda mendaftarkan
salah satu pasien itu lebih dulu, atau sebuah cabang dinonaktifkan.

Yang terjadi: **tidak ada satu pasien pun diimpor.** Hasil verifikasi diperbarui.

Lakukan: tinjau ulang temuan baru → perbaiki berkas sumber bila perlu → batalkan
dan unggah ulang. Bila penyebabnya sudah hilang (misalnya pasien bentrok itu
dihapus), Anda dapat menekan konfirmasi lagi tanpa mengunggah ulang.

Bila pesannya menyebut **berkas sumber berubah atau tidak ditemukan**: batch itu
tidak dapat dipakai lagi. Batalkan dan unggah ulang berkasnya.

## Setelah impor

18. Batch yang sudah diimpor **tidak dapat dibatalkan.** Pembatalan hanya ada
    sebelum konfirmasi.
19. Untuk menarik pasien hasil impor, gunakan **Rollback Batch**. Rollback ditolak
    bila ada pasien hasil impor yang sudah memiliki kunjungan atau rekam medis —
    pasien yang sudah memulai alur klinis tidak boleh hilang diam-diam. Tangani
    kasus seperti itu lewat koreksi data pasien yang biasa.

---

## Yang tidak boleh dilakukan

* Jangan mengarang Nomor KTP untuk mengisi sel kosong.
* Jangan mengakali baris ERROR agar batch lolos. Perbaiki berkas sumbernya.
* Jangan memakai Rollback sebagai pembatalan rutin. Rollback adalah penarikan
  data pasien yang sudah nyata, bukan langkah normal.
* Jangan menyunting berkas sumber setelah diunggah lalu berharap batch yang sama
  mengimpor isi barunya. Batch terikat pada sidik jari berkas yang Anda tinjau.

## Batas

* Tidak ada kuota harian. Seluruh estate legacy boleh diimpor.
* Ukuran berkas maksimal 5 MB per unggahan (batas teknis, bukan kebijakan).
* KTP/NIK selalu disamarkan di seluruh tampilan dan di laporan verifikasi.

---

## Rollback adalah pintu satu arah

**Setelah Rollback, berkas yang sama tidak dapat diunggah ulang tanpa bantuan
teknis.** Ini perilaku nyata yang terukur di produksi, bukan dugaan.

Rollback **tidak** menghapus pasien sampai bersih — pasien itu ditandai terhapus
(soft delete). Pemeriksaan duplikat tetap melihat pasien yang ditandai terhapus.
Jadi setiap Nomor KTP dan setiap Nomor RM final yang sudah pernah dibuat batch itu
**tetap dianggap terpakai**, walaupun di layar jumlah pasien terlihat 0.

Gejalanya saat Anda mengunggah ulang berkas yang sama:

* `Nomor KTP sudah terdaftar pada pasien lain.` — satu baris untuk setiap pasien
  yang dulu berhasil diimpor;
* `Nomor RM final <RM> sudah digunakan pasien lain.`;
* dan pasien yang disebut itu **tidak muncul di mana pun** di aplikasi.

Kejadian nyata 2026-09-27: satu batch berisi 1519 baris diimpor lalu di-rollback
enam menit kemudian. Unggahan berikutnya atas data yang sama menghasilkan 1318
konflik KTP dan 520 konflik RM. Unggahan berkas itu **sebelum** impor hanya
menghasilkan 1 error sumber yang wajar.

### Yang harus Anda lakukan

* **Jangan** mengarang Nomor KTP atau mengubah Nomor RM Manual supaya lolos. Itu
  memberi pasien identitas permanen yang salah.
* **Jangan** menunggu konflik hilang sendiri. Tidak ada tombol di aplikasi yang
  membebaskannya.
* **Laporkan ke tim teknis** dengan menyebut nomor batch yang di-rollback.
  Pembebasan identitas memerlukan pembersihan basis data yang diaudit: cadangan
  lebih dulu, manifes dengan jumlah baris yang diharapkan, dan pembatalan otomatis
  bila jumlah nyata tidak sama dengan jumlah yang diharapkan. Pembersihan hanya
  boleh dilakukan bila pasien tersebut **belum** memiliki satu pun kunjungan,
  rekam medis, tagihan, pembayaran, persetujuan, resep, atau order lab.

### Cara menghindarinya

* Pakai **Batalkan Batch** (sebelum konfirmasi) untuk semua koreksi rutin.
  Pembatalan tidak pernah menyentuh data pasien, jadi selalu bisa diulang.
* Perbaiki berkas sumber sampai **Error = 0** sebelum menekan konfirmasi.
* Simpan Rollback hanya untuk kekeliruan impor yang benar-benar harus ditarik.
