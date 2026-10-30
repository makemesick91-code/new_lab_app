---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# G7-B — Runbook Seremoni Escrow Dual-Custodian

**Status: SIAP DIJALANKAN SETELAH SYARAT TERPENUHI. Belum dijalankan.** Runbook ini menjabarkan langkah 1–8
prosedur seremoni yang sudah diaudit di
[G7B-FINAL-READINESS-REPORT](../release-cicd-phase2-2026-10-29/G7B-FINAL-READINESS-REPORT.md), dengan peran
bernama dari keputusan owner 2026-10-30 ([G7B-CUSTODIAN-APPOINTMENT](G7B-CUSTODIAN-APPOINTMENT.md)).
Satu-satunya tambahan adalah **langkah 0** (uji pinentry dengan key sekali pakai). Tool tidak diubah.
Kembali ke [00-INDEX](../../00-INDEX.md).

> Runbook ini dijalankan **oleh manusia** di terminal biasa. Sesi AI atau otomasi tidak boleh menjalankan
> langkah 0–5. Sesi seperti itu tidak punya terminal untuk prompt passphrase, dan passphrase tidak boleh
> lewat sesi tersebut. Sesi otomasi hanya boleh menjalankan pemeriksaan baca-saja di bagian "Sebelum mulai".

Seremoni ini **tidak** merotasi application key. Seremoni hanya menyimpan key aktif ke escrow dua custodian.
Rotasi produksi tetap NO-GO sampai ada persetujuan eksekusi tersendiri.

## Peran

| Peran | Orang | Tugas |
|---|---|---|
| Operator host | Raushan Fikri Ridha (IT), pemegang akses root | Menjalankan semua perintah dan menyiapkan media |
| Custodian 1 — IT | Raushan Fikri Ridha, **setelah dikonfirmasi formal oleh owner** | Mengetik passphrase sendiri untuk salinan `custodian-1-it` |
| Custodian 2 — manajemen | Jene Monika, Supervisor RME | Mengetik passphrase sendiri untuk salinan `custodian-2-manajemen` |
| Saksi | Jene Monika untuk langkah milik custodian 1; Raushan Fikri Ridha untuk langkah milik custodian 2 | Menyaksikan hasil (bukan passphrase) dan menandatangani catatan custody |

Karena custodian 1 juga operator host, custodian 2 wajib hadir sebagai saksi untuk seal, verify, dan
recovery check milik custodian 1 (aturan 210 no. 13).

## Syarat sebelum mulai

Seremoni tidak dimulai bila salah satu syarat ini belum terpenuhi.

1. Owner sudah mengonfirmasi custodian 1 secara formal, tertulis dan bertanggal.
2. Jadwal disepakati di luar jam klinik dan kasir. Recovery check memulihkan backup sekitar 32 MB ke
   database sementara selama kurang lebih 10 detik per salinan.
3. Kedua custodian hadir secara fisik di workstation operator.
4. Tiap custodian membawa **media offline miliknya sendiri** yang kosong dan berlabel. Media itu bukan
   media custody signing Android. Media custodian 1 dan custodian 2 disimpan di lokasi fisik yang berbeda.
5. Tiap custodian sudah memilih passphrase yang kuat dan berbeda. Passphrase tidak dicatat di media escrow,
   tidak dibagikan, dan tidak diucapkan. Cara custodian menyimpan passphrase-nya adalah tanggung jawab
   custodian itu sendiri, terpisah dari media.
6. Terminal operator adalah terminal biasa. Saat passphrase diketik, tidak ada perekam layar, berbagi layar,
   remote desktop, atau sesi asisten AI yang aktif di terminal itu.
7. Disiapkan catatan custody kosong di luar repository (format di bagian akhir).

## Variabel (di terminal lokal operator)

```bash
H=<alias SSH host produksi>
D=/root/security-g7b-escrow-<YYYYMMDD>     # direktori kerja root-only di host
E=<path lengkap file environment aktif>    # dibaca tool di host, tidak pernah dicetak
A=<direktori aplikasi di host>
T=g7b-escrow-app-key.sh
```

Nilai variabel ini bukan rahasia, tetapi jangan ditempel ke chat atau tiket.

## Sebelum mulai — pemeriksaan baca-saja

Boleh dijalankan oleh operator atau sesi otomasi. Semua hasil harus sesuai sebelum lanjut.

1. Ambil tool dari KB dan cocokkan sha256-nya:

   ```bash
   awk '/^## `g7b-escrow-app-key.sh`/{f=1} f&&/^```bash$/{g=1;next} g&&/^```$/{exit} g{print}' \
     docs/operations/release-cicd-phase2-2026-10-29/G7B-ESCROW-TOOLS.md > "$T"
   sha256sum "$T"
   ```

   Hasil wajib: `c23037cbb4daa56487772fb956612e0e6ee64b54bc7f49006e0685b2e0dd68a2`.

2. Di host, pastikan tidak ada sisa escrow: 0 direktori `/root/security-g7b-escrow*` dan 0 database
   `dms_g7b%`.
3. Dry run re-enkripsi di host sebagai `daengtisiams` (`php artisan security:app-key-reencrypt --json`,
   tanpa `--apply`): total 8, current 8, previous 0, tidak terbaca 0. Catat ukuran log aplikasi.

## Langkah 1 — Unggah tool

```bash
ssh "$H" "install -d -m 0700 $D"
scp "$T" "$H:$D/$T"
ssh "$H" "chmod 0700 $D/$T && sha256sum $D/$T"
```

sha256 di host harus sama dengan nilai di atas. Kalau berbeda, berhenti dan hapus direktori itu.

**Jangan pernah** menjalankan tool dengan `bash -s` lewat stdin. Tool hanya bisa menampilkan prompt
passphrase bila stdin-nya adalah terminal. Semua langkah interaktif di bawah memakai `ssh -t` dan
`bash $D/$T`.

## Langkah 0 — Uji pinentry dengan key sekali pakai

Langkah ini menguji prompt passphrase di terminal ini **tanpa menyentuh key aktif**. Custodian yang akan
mengetik pertama (custodian 1) memakai passphrase latihan, **bukan** passphrase sungguhan.

```bash
ssh "$H" "install -d -m 0700 $D/latihan && printf '%s=base64:%s\n' APP_KEY \"\$(head -c 32 /dev/urandom | base64 -w0)\" > $D/latihan/latihan-kunci.txt && chmod 600 $D/latihan/latihan-kunci.txt"
ssh -t "$H" "bash $D/$T --mode seal --custodian latihan --env $D/latihan/latihan-kunci.txt --out $D/latihan/latihan.gpg"
ssh -t "$H" "bash $D/$T --mode verify --in $D/latihan/latihan.gpg"
ssh "$H" "rm -rf -- $D/latihan && test ! -e $D/latihan && echo LATIHAN_DIHAPUS"
```

Hasil wajib: prompt muncul dua kali saat seal dan sekali saat verify; keluaran `SEALED custodian=latihan`
dan `VERIFY MATCH custodian=latihan`; lalu `LATIHAN_DIHAPUS`. Key latihan dibuat acak di host dan tidak
pernah tercetak.

Kalau prompt tidak muncul atau macet, **berhenti**. Jangan lanjut ke key aktif. Periksa bahwa perintah
memakai `ssh -t`, bahwa `TERM` terisi, dan bahwa terminal mendukung tampilan curses.

## Langkah 2 — Seal

Satu salinan per custodian. Tool menolak menimpa file yang sudah ada.

**Custodian 1** mengetik passphrase sungguhan (custodian 2 menyaksikan hasil, bukan passphrase):

```bash
ssh -t "$H" "bash $D/$T --mode seal --custodian custodian-1-it --env $E --out $D/custodian-1-it.gpg"
```

**Custodian 2** mengetik passphrase-nya di terminal operator. Operator mundur dan tidak melihat layar maupun
keyboard selama passphrase diketik:

```bash
ssh -t "$H" "bash $D/$T --mode seal --custodian custodian-2-manajemen --env $E --out $D/custodian-2-manajemen.gpg"
```

Hasil wajib per salinan: `SEALED custodian=<label> identity=<16 hex> ciphertext_sha256=<awalan>…`. Salin
identitas dan awalan sha256 itu ke catatan custody. Identitas adalah hash bergaram, bukan key.

## Langkah 3 — Verify

Tiap custodian mengetik passphrase-nya sendiri:

```bash
ssh -t "$H" "bash $D/$T --mode verify --in $D/custodian-1-it.gpg --env $E"
ssh -t "$H" "bash $D/$T --mode verify --in $D/custodian-2-manajemen.gpg --env $E"
```

Hasil wajib untuk keduanya: `VERIFY MATCH custodian=<label> — escrowed key == live key`. Keluaran
"escrowed key is NOT the live key" berarti salinan itu salah: berhenti, hapus salinan itu per path, lalu
ulangi seal untuk custodian tersebut.

## Langkah 4 — Bukti independensi

```bash
ssh "$H" "cd $D && python3 -I - custodian-1-it.gpg.manifest custodian-2-manajemen.gpg.manifest" <<'PY'
import json, sys
a, b = (json.load(open(p)) for p in sys.argv[1:3])
print('identitas_sama=%s ciphertext_berbeda=%s' % (
    a['key_identity'] == b['key_identity'],
    a['ciphertext_sha256'] != b['ciphertext_sha256'],
))
PY
```

Hasil wajib: `identitas_sama=True ciphertext_berbeda=True`. Kedua salinan memuat key yang sama, tetapi
dienkripsi terpisah dengan passphrase berbeda.

## Langkah 5 — Recovery check per salinan

Tiap custodian mengetik passphrase-nya sendiri. Setiap run memulihkan backup pra-deploy terbaru ke database
sementara, lalu menghapusnya.

```bash
ssh -t "$H" "bash $D/$T --mode recovery-check --in $D/custodian-1-it.gpg --env $E --app-dir $A"
ssh -t "$H" "bash $D/$T --mode recovery-check --in $D/custodian-2-manajemen.gpg --env $E --app-dir $A"
```

Hasil wajib untuk keduanya:

- `A0 fresh key only: total=8 undecryptable=8` (kontrol: backup tidak terbaca tanpa key escrow).
- `A  fresh key + escrowed as previous: total=8 previous=8 undecryptable=0 previous_keys=1`.
- `RECOVERY CHECK PASS`.

Jumlah total bisa berbeda bila backup pra-deploy terbaru berbeda dari backup rilis `fb3726c5`. Syaratnya
tetap: total lebih dari 0, previous sama dengan total, tidak terbaca 0, dan A0 membuat semua nilai tidak
terbaca. Setelah run, pastikan 0 database `dms_g7b%`.

Kalau recovery check gagal: **berhenti**. Escrow belum PASS, jangan pindahkan salinan ke media. Catat
keluarannya (tanpa key dan passphrase) dan laporkan.

## Langkah 6 — Pindah ke media custodian

Media custodian dipasang di workstation operator. Ciphertext disalin **langsung** ke media, tidak ke disk
workstation.

```bash
M1=<titik mount media custodian 1>
M2=<titik mount media custodian 2>
scp "$H:$D/custodian-1-it.gpg" "$H:$D/custodian-1-it.gpg.manifest" "$M1/"
scp "$H:$D/custodian-2-manajemen.gpg" "$H:$D/custodian-2-manajemen.gpg.manifest" "$M2/"
for f in "$M1/custodian-1-it.gpg" "$M2/custodian-2-manajemen.gpg"; do
  python3 -I - "$f" <<'PY'
import hashlib, json, sys
p = sys.argv[1]
m = json.load(open(p + '.manifest'))
ok = hashlib.sha256(open(p, 'rb').read()).hexdigest() == m['ciphertext_sha256']
print(m['custodian'], 'COCOK' if ok else 'TIDAK COCOK')
PY
done
sync
```

Hasil wajib: `custodian-1-it COCOK` dan `custodian-2-manajemen COCOK`. Lepas media dengan aman, lalu serahkan
ke pemiliknya. Tiap media dibawa pulang oleh custodiannya ke lokasi masing-masing.

## Langkah 7 — Bersihkan host

Hapus per path, bukan dengan glob:

```bash
ssh "$H" "rm -- $D/custodian-1-it.gpg $D/custodian-1-it.gpg.manifest $D/custodian-2-manajemen.gpg $D/custodian-2-manajemen.gpg.manifest $D/$T && rmdir $D && test ! -e $D && echo HOST_BERSIH"
ssh "$H" "runuser -u postgres -- psql -Atc \"select count(*) from pg_database where datname like 'dms_g7b%'\""
```

Hasil wajib: `HOST_BERSIH` dan `0`. Ulangi dry run re-enkripsi (tetap 8/8 current) dan pastikan ukuran log
aplikasi tidak bertambah. Hapus juga file tool di workstation operator bila tidak disimpan untuk recovery
check berkala. Tool tetap bisa diambil ulang dari KB.

## Langkah 8 — Catat

Isi catatan custody di luar repository dan minta kedua custodian menandatanganinya. Sesudah itu laporan final
kesiapan G7-B boleh dibuat (syaratnya di [G7B-CUSTODIAN-APPOINTMENT](G7B-CUSTODIAN-APPOINTMENT.md)).

## Format catatan custody (diisi di luar repository)

Satu baris per aksi. **Jangan** pernah menulis key, passphrase, atau isi file environment.

| Kolom | Isi |
|---|---|
| Waktu | Tanggal dan jam WITA |
| Aksi | `latihan` / `seal` / `verify` / `pemisahan` / `recovery-check` / `pindah-media` / `bersih-host` |
| Label salinan | `custodian-1-it` atau `custodian-2-manajemen` |
| Operator | Nama |
| Custodian yang mengetik passphrase | Nama |
| Saksi | Nama |
| Identitas key | 16 hex dari keluaran tool |
| sha256 ciphertext | Nilai lengkap dari manifest |
| Hasil | PASS / FAIL, dengan baris keluaran tool yang relevan |
| Media | Jenis dan label fisik, tanpa nomor seri |
| Lokasi simpan | Tingkat organisasi, tanpa alamat jalan |
| Atestasi custodian 2 | "Saya tidak memegang akses root atau SSH ke host produksi" (sekali, saat seal) |
| Tanda tangan | Operator, custodian, dan saksi |

## Recovery check berkala

- Sebelum key lama dipensiunkan, dan setiap 90 hari sesudah seal.
- Custodian membawa media, operator menyalin ciphertext dan manifest ke direktori 0700 baru di host,
  mencocokkan sha256 dengan manifest, lalu menjalankan langkah 5 untuk salinan itu saja. Setelahnya
  dilakukan langkah 7 untuk direktori tersebut.
- Pergantian custodian berarti seal ulang untuk custodian baru, lalu pemusnahan salinan lama yang dicatat.
