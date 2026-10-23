# SOP — Ketidaksesuaian Tagihan RME (OPS-OQ-1, rule 203)

**Berlaku untuk:** semua cabang RME. **Pembaca:** kasir, Front Office, penanggung
jawab cabang, IT. **Dasar:** kebijakan Owner OPS-OQ-1 (dicatat di
`docs/decisions/rme-billing-correction-owner-decisions.md`).

## 1. Kapan dipakai

Tagihan RME yang sudah terbit **diketahui atau diduga salah nominalnya** (harga,
item, diskon, tagihan ganda) dan belum ada penyelesaian yang sah.

## 2. Lima aturan yang tidak boleh dilanggar

1. **Jangan memungut** nominal yang diketahui salah.
2. **Jangan mengubah** tagihan sendiri — tidak ada edit, tidak ada tagihan baru
   untuk "menggantikan", tidak ada pembatalan sepihak.
3. **Laporkan** ke penanggung jawab cabang lewat sistem (§3).
4. Tagihan **tetap belum selesai** secara keuangan sampai ada penyelesaian yang sah.
   Laporan **tidak** membuat tagihan Lunas, Dibatalkan, atau Rp0.
5. **Pasien boleh pulang.** Jangan menahan pasien demi menyelesaikan pilot koreksi.

## 3. Langkah kasir

1. Buka halaman tagihan (Kasir RME → tagihan).
2. Pada kartu **Ketidaksesuaian Tagihan**, klik **Laporkan Ketidaksesuaian Tagihan**.
3. Pilih jenis ketidaksesuaian dan tulis keterangan singkat (10–1000 karakter).
   **Jangan** menulis NIK, diagnosis, atau data pribadi pasien.
4. Klik **Kirim Laporan & Tahan Pembayaran**. Sistem memberi nomor `RMEDSP-…`.
5. Sampaikan ke pasien bahwa tagihan sedang ditinjau dan klinik akan menghubungi.
   Pasien boleh pulang.
6. Tambahkan **catatan tindak lanjut** bila ada informasi baru (mis. pasien sudah
   pulang dan akan dihubungi).

Sejak laporan dikirim, pembayaran tagihan itu ditolak di **semua jalur kasir**
(pembayaran biasa, piutang yang dipilih saat kunjungan baru, kunjungan kontrol)
dengan pesan:

> Tagihan ini sedang dalam peninjauan karena terdapat ketidaksesuaian nominal.
> Pembayaran sementara tidak dapat diproses. Silakan hubungi penanggung jawab cabang.

Bila tagihan yang ditahan ikut terpilih dalam pembayaran gabungan, **seluruh**
pembayaran gabungan itu ditolak — tidak ada uang yang dialihkan diam-diam ke
tagihan lain. Pada pembayaran kunjungan baru, hapus pilihan piutang yang ditahan,
lalu bayar yang lain. Pada **kunjungan kontrol**, tagihan asal yang dilaporkan ikut
wajib diselesaikan dalam pembayaran kontrol, sehingga pembayaran kontrol ikut
tertahan sampai laporan selesai — pasien tetap boleh pulang.

## 4. Langkah penanggung jawab cabang

Menu **Ketidaksesuaian Tagihan** → buka laporan.

| Tindakan | Kapan | Akibat |
|---|---|---|
| **Mulai Tinjau** | laporan baru | status *Sedang Ditinjau*; pembayaran tetap ditahan |
| **Eskalasi ke Owner** (alasan wajib) | perlu keputusan Owner, mis. koreksi tagihan | status *Dieskalasi ke Owner*; Owner menerima notifikasi; pembayaran tetap ditahan |
| **Selesaikan Laporan** | ada bukti penyelesaian (§5) | status *Selesai*; penahanan dari laporan ini dilepas |

Peninjau **tidak** memiliki kuasa keuangan: tidak dapat mengubah tagihan, membuat
koreksi, atau mencatat pembayaran dari laporan.

## 5. Penyelesaian yang sah (hanya dua)

1. **Selesai dengan koreksi tagihan yang telah diterapkan** — hanya bila koreksi
   sudah **diterapkan setelah** laporan dibuat (saat ini hanya mungkin di **SPN4**,
   lewat Maker 29 dan Checker 1, dengan persetujuan Owner untuk kasus itu). Nomor
   koreksi diisi otomatis oleh sistem.
2. **Tagihan asli dinyatakan benar** — hanya bila tagihan **belum berubah** sejak
   dilaporkan, dengan referensi (mis. referensi persetujuan Owner). **Pelapor tidak
   boleh** menyatakan sendiri bahwa tagihannya benar.

Tidak ada pilihan "tutup tanpa bukti". Bila koreksi masih menunggu keputusan,
laporan belum dapat diselesaikan. Di cabang selain SPN4 belum ada jalur koreksi:
bila tagihan asli memang salah, laporan tetap *Dieskalasi ke Owner* dan tagihan
tetap belum selesai sampai Owner memutuskan.

## 6. Pembayaran setelah selesai

Setelah laporan *Selesai*, **muat ulang** halaman pembayaran. Sistem tetap mengikat
pembayaran ke versi tagihan terbaru (formulir lama ditolak `INVOICE_VERSION_CHANGED`).
Tagihan yang sudah Sebagian/Lunas tidak dikembalikan — refund dan pembalikan
pembayaran belum tersedia.

## 7. Bila tidak ada penanggung jawab peninjau

Saat ini izin peninjau hanya dimiliki Super Admin; siapa peninjau di tingkat cabang
adalah pertanyaan terbuka **OPS-OQ-1a** untuk Owner. Bila tidak ada peninjau aktif,
laporan tetap tercatat dan pembayaran tetap ditahan. **Jangan** memberi izin peninjau
ke akun kasir. IT memeriksa dengan:

```
runuser -u daengtisiams -- php artisan rme:billing-dispute-status
```

## 8. Privasi

Laporan, catatan, audit, dan notifikasi hanya memuat nomor (`RMEDSP-…`, `RME-…`),
status, dan kode — tidak pernah nama pasien, NIK, nomor rekam medis, atau isi klinis.
Bukti operasional dicatat sebagai teks, bukan tangkapan layar mentah.
