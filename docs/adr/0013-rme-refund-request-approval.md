# ADR 0013 — Pengajuan & persetujuan refund RME (Sprint 4/7)

* Status: **DITERIMA SEBAGAI DESAIN — BELUM KODE.** Pada 2026-10-31 Owner menjawab seluruh RF-1 … RF-14,
  RF-1a dan RF-8a (register: D4, D5, D6, D18, D25, D26; paket keputusan §7.1 dan §7.4). Dari sisi
  keputusan Owner implementasi tidak lagi terblokir; gerbang repositori dan F-17 tetap berlaku (paket
  §7.6). Tidak ada yang dibangun atau diaktifkan.
* Tanggal: 2026-10-31 (WITA)
* Tugas: `PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1` (program
  `DAENGTISIAMS-RME-BILLING-CORRECTION`, persiapan Sprint 4/7)
* Baseline: basis kanonik `f60d8232`; produksi `fb3726c5` (tag `post-audit-f4bc-release-1-go`),
  rule terbaru 212
* Desain lengkap: `docs/architecture/rme-refund-request-approval-design.md`
* Paket keputusan Owner: `docs/decisions/rme-refund-request-approval-owner-decision-package.md`

ADR ini **tidak mengaktifkan apa pun** dan **tidak menyetujui kebijakan apa pun**. Setiap keputusan di
bawah adalah usulan desain yang baru boleh diimplementasikan setelah Owner menjawab kode RF yang
disebutkan.

## Konteks

Koreksi setelah pembayaran (Sprint 3, rule 207) mencatat **kewajiban kelebihan bayar** `OPEN` bila total
turun di bawah uang yang diterima (PP-3). Kewajiban itu tidak dapat diubah (model menolak update/delete),
dan belum ada jalur untuk mengembalikannya. Di produksi (2026-10-31) terdapat 0 kewajiban, flag koreksi
setelah bayar OFF, dan aktivasinya diblokir PP-10.

## Keputusan yang diusulkan

1. **Refund hanya mengonsumsi kewajiban `OPEN`.** Tidak pernah pembayaran langsung, tidak pernah saldo
   yang dihitung dari input. Jumlah = jumlah kewajiban, dihitung server; tanpa refund sebagian
   **[RF-4 = A, disetujui Owner 2026-10-31]**.
2. **Persetujuan ≠ pembayaran.** Sprint 4 hanya pengajuan/persetujuan; tidak ada tabel, izin, rute,
   atau status pembayaran keluar **[RF-2 = A, disetujui Owner 2026-10-31]**.
3. **Tabel sendiri, kewajiban tidak disentuh.** `trx_rme_refund_requests` + peristiwa append-only;
   baris kewajiban hanya dibaca dan dikunci. Status kewajiban baru berubah di Sprint 5.
4. **Urutan kunci tetap:** tagihan → kewajiban → pengajuan refund.
5. **Pembuat ≠ pemeriksa per akun, termasuk Super Admin**, di service di bawah kunci; CHECK DB sebagai
   cadangan **[RF-1, disetujui Owner 2026-10-31]**.
6. **Otoritas = peran + cakupan cabang yang diselesaikan server** **[RF-1, disetujui Owner 2026-10-31]**.
   Pembuat: hanya Front Office di cabang kerja yang ditugaskan (bukan Kasir/Admin Klinik lewat izin
   warisan; Super Admin dan Supervisor RME bukan pembuat). Peninjau: Super Admin — semua cabang RME aktif;
   Supervisor RME — cakupan sah; Kepala Cabang — cabang yang dikelolanya, dengan izin sempit tanpa akses
   RME luas. Melihat cabang tetap bukan otoritas. Lingkup pilot SPN4 **[RF-3, disetujui]**; identitas pilot
   tidak mewarisi pasangan Sprint 2 dan wajib terpisah secara manusia **[RF-1a, disetujui]**.
7. **Referensi persetujuan Owner per kasus wajib pada setiap pengajuan**, sebelum diproses, terkait
   tagihan dan kewajiban **[RF-1, disetujui Owner 2026-10-31]**; satu referensi = satu keputusan:
   dibandingkan tanpa membedakan huruf besar/kecil terhadap semua refund **dan** semua koreksi setelah
   bayar, dua arah, dengan tabel bersama referensi terpakai sebagai cadangan database; divalidasi di
   aplikasi dan database. Pengajuan baru sesudah penolakan, penarikan, atau pencabutan wajib membawa
   referensi Owner **baru** **[RF-6, disetujui]**. Referensi permintaan pasien wajib, unik per kewajiban
   **[RF-5 = B, disetujui]**.
8. **Penolakan tidak menghapus utang**; kewajiban tetap `OPEN` **[RF-6, disetujui]**. Audit permanen:
   pengajuan dan peristiwanya tidak pernah dihapus atau diubah **[disetujui Owner 2026-10-31]**.
9. **Refund ditolak selama ada laporan ketidaksesuaian aktif**, saat pengajuan dan saat persetujuan
   **[RF-7, disetujui]**.
10. **Idempotensi** dengan intent HMAC khusus refund yang terikat pada kewajiban; pengulangan persis
    mengembalikan hasil asli.
11. **Flag terpisah** `rme.billing_refund_request` default OFF; pilot SPN4; aktivasi menunggu Sprint 2
    closure, F-17 terverifikasi, aktivasi Sprint 3, satu kewajiban nyata, staf terkonfirmasi, dan
    keputusan aktivasi Owner **[RF-3, disetujui]**.
12. **Tanpa** saldo kredit pasien **[RF-12]**, pembalikan pembayaran **[RF-13]**, offset antartagihan,
    atau penegakan sistem atas ketentuan tercetak **[RF-14]** — semuanya disetujui Owner 2026-10-31.

## Konsekuensi

* Positif: uang tidak dapat keluar di Sprint 4; semua rollback non-finansial; tidak ada kolom yang
  diubah pada tabel yang sudah ada.
* Negatif: dua langkah operasional (setuju lalu bayar); pasien menunggu bila tagihan sedang
  dipersoalkan; refund tidak berguna sampai Sprint 3 aktif.
* Sprint 5 wajib mengubah definisi kelebihan bayar ledger menjadi
  `Σ pembayaran − Σ refund dibayar − total` dalam satu perubahan
  (`docs/architecture/rme-refund-financial-reconciliation-contract.md` §3).

## Alternatif yang ditolak

* Menyimpan status refund pada baris kewajiban — melanggar imutabilitas rule 207.
* Satu tabel refund untuk pengajuan, keputusan, dan pembayaran (desain 2026-10-16) — mencampur dua aktor
  dan dua fakta keuangan; membuat jalur pembayaran "tidur" di Sprint 4.
* Saldo refund dari `Σ pembayaran − total` dikurangi komitmen — menggandakan otoritas yang kini dipegang
  kewajiban.
* Memakai ulang intent pembayaran — token pembayaran akan valid untuk formulir refund tagihan yang sama.
