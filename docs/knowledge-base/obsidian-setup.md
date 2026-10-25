---
kb_status: current
kb_verified_against: 572a32a9
kb_updated: 2026-10-26
---

# Setup Obsidian untuk Vault `docs/`

Kembali ke [00-INDEX](../00-INDEX.md).

## Status pada mesin pengembangan (diverifikasi 2026-10-26)

| Pemeriksaan | Hasil |
|---|---|
| Sistem operasi | Ubuntu 26.04 LTS, x86_64, sesi X11 (XFCE) |
| Obsidian terpasang | **Ya.** Snap `obsidian` **1.14.5**, rev 71, kanal `latest/stable`, publisher `obsidianmd`, confinement `classic` |
| Executable | `/snap/bin/obsidian` (wrapper snapd). Menu desktop: `obsidian_obsidian.desktop` |
| Perubahan sistem | Hanya satu: `snap install obsidian --classic`. Tidak ada `apt upgrade`, repositori baru, atau perubahan konfigurasi sistem |
| Vault | `docs/` terbuka sebagai vault di Obsidian |
| Konfigurasi lokal | `~/.config/obsidian/` (daftar vault, cache aplikasi) dan `docs/.obsidian/` (pengaturan per vault) |

Paket snap dipilih karena halaman resmi [obsidian.md/download](https://obsidian.md/download)
menautkannya ke [snapcraft.io/obsidian](https://snapcraft.io/obsidian), dan versinya identik
dengan rilis resmi di GitHub `obsidianmd/obsidian-releases`. Snap memperbarui diri otomatis.

**Catatan keamanan.** Launcher resmi snap (`/snap/obsidian/current/command.sh`) menjalankan
Obsidian dengan `--no-sandbox`, sehingga sandbox renderer Chromium tidak aktif. Ini keputusan
vendor untuk build snap, bukan konfigurasi proyek ini. Risikonya kecil selama vault hanya berisi
dokumen proyek dan tidak ada plugin komunitas. Itu alasan tambahan untuk tidak memasang plugin
komunitas (lihat Aturan pemakaian).

## Pilihan instalasi resmi

Untuk mesin lain. Unduh hanya dari tautan yang tercantum di obsidian.md/download.

| Opsi | Penerbit | Catatan |
|---|---|---|
| **Snap** | Obsidian (obsidianmd) | Disarankan: resmi dan diperbarui otomatis. `sudo snap install obsidian --classic` |
| **`.deb` (amd64)** | Obsidian, lewat GitHub releases | Resmi. Tidak ada repositori apt, jadi pembaruan dilakukan manual. Cocokkan SHA-256 dengan `digest` aset di halaman rilis GitHub sebelum memasang |
| AppImage (x86_64 / arm64) | Obsidian, lewat GitHub releases | AppImage umumnya membutuhkan FUSE 2, sedangkan mesin ini hanya punya `fuse3` (belum diuji) |
| Flatpak | **Dikelola komunitas** (Flathub `md.obsidian.Obsidian`) | Bukan build resmi Obsidian |

`sudo` membutuhkan password yang diketik operator sendiri di terminal. Jangan pernah memberikan
password kepada asisten AI atau menyimpannya di berkas.

## Membuka `docs/` sebagai vault

1. Jalankan Obsidian, lalu pilih **Open folder as vault → Open**.
2. Di dialog folder, tekan **Ctrl+L** dan tempel path folder `docs` dari checkout yang ingin
   dipakai, misalnya `/home/fikri/Projects/new_lab_app/docs`. Setiap worktree adalah vault
   yang terpisah.
3. Buka `00-INDEX.md` sebagai halaman awal.

Setelah vault terdaftar, berkas dapat dibuka lewat URI resmi Obsidian, misalnya
`obsidian://open?vault=docs&file=00-INDEX`. Nama vault adalah nama foldernya (`docs`).

**Jangan** membuka root repo sebagai vault. Root repo memuat `vendor/` dan `node_modules/`
yang berisi ribuan berkas Markdown pihak ketiga.

### Menjaga `docs/.obsidian/` tetap di luar Git

Obsidian membuat folder `docs/.obsidian/` berisi pengaturan lokal dan tata letak jendela.
Folder ini **tidak boleh** ter-commit. Periksa dengan:

```bash
git check-ignore -v docs/.obsidian/app.json
```

Jika perintah itu tidak mengeluarkan apa pun (folder belum dikecualikan di checkout Anda),
tambahkan pengecualian **lokal** yang tidak ikut ter-commit:

```bash
printf '/docs/.obsidian/\n/docs/.trash/\n/.obsidian/\n' >> "$(git rev-parse --git-common-dir)/info/exclude"
```

`info/exclude` dipakai bersama oleh semua worktree repo ini. Path tersebut tidak pernah
di-track di branch mana pun, jadi pengecualian ini tidak mengubah berkas yang sudah di-track.

## Pengaturan wajib saat pertama kali dibuka

Buka **Settings** (ikon ⚙ di kiri bawah).

**Files and links** (label sesuai Obsidian 1.14.5):

| Pengaturan | Nilai | Yang tersimpan di `docs/.obsidian/app.json` | Alasan |
|---|---|---|---|
| **Use [[Wikilinks]]** | **Off** | `"useMarkdownLinks": true` | Tautan baru menjadi tautan Markdown standar yang juga terbaca di GitHub dan ChatGPT |
| **New link format** | **Path from current file** | `"newLinkFormat": "relative"` | Sesuai gaya tautan relatif di repo ini |
| **Automatically update internal links** | **Off** | Tidak ada kunci; ini nilai bawaan | Mencegah rename mengubah puluhan berkas lain secara diam-diam; 119 berkas kode/test merujuk path dokumen secara persis |

Nama kunci di kolom ketiga **diamati** dari perubahan `app.json` saat pengaturan diubah di
Obsidian 1.14.5 pada 2026-10-26, bukan diasumsikan. Obsidian hanya menulis nilai yang berbeda
dari bawaan.

**Core plugins:**

| Plugin inti | Nilai | Alasan |
|---|---|---|
| **Sync** | **Off** | Di Obsidian 1.14.5 plugin ini **aktif secara bawaan** pada vault baru. Matikan agar tidak ada jalur sinkronisasi cloud untuk dokumen internal |
| **Publish** | Off (bawaan) | Dokumen proyek bersifat internal |

Pengaturan ini disimpan per vault di `docs/.obsidian/` dan **tidak** di-commit.

## Aturan pemakaian

- **Jangan mengganti nama atau memindahkan** dokumen yang sudah ada dari dalam Obsidian
  (lihat [conventions](conventions.md)).
- **Jangan masuk ke Obsidian Sync atau Publish**, dan jangan memasang plugin komunitas yang
  mengunggah ke cloud, untuk vault ini tanpa persetujuan. Dokumen proyek bersifat internal.
- **Plugin komunitas:** biarkan *restricted mode* aktif. Plugin bawaan (Graph view, Backlinks,
  Outline, Search) sudah cukup. Tinjau dulu sebelum memasang plugin apa pun.
- Tangkapan layar yang ditempel akan masuk ke `docs/` dan ikut ter-commit. **Tidak boleh ada
  data pasien.**
- Perubahan dokumen tetap melalui branch dan PR, sama seperti kode.

## Verifikasi (2026-10-26)

| Pemeriksaan | Hasil |
|---|---|
| Aplikasi terbuka | Ya. Jendela "Obsidian 1.14.5" tampil |
| Vault terdaftar | Ya. `~/.config/obsidian/obsidian.json` memuat path vault |
| `00-INDEX.md` tampil | Ya. Frontmatter terbaca sebagai *Properties* |
| Resolusi tautan di Obsidian | Ya. Obsidian menghitung **11 backlink** ke `00-INDEX`, sama persis dengan 11 tautan relatif yang dihitung validator dari isi berkas |
| `docs/.obsidian/` di luar Git | Ya. `git check-ignore` mencocokkan semua berkas; `git add -A --dry-run` tidak menambahkan satu pun |
| Plugin komunitas | Tidak ada |

## Belum dilakukan

- **Berkas pengaturan bersama** (opsional). Kunci pengaturan sekarang sudah terverifikasi
  (lihat tabel di atas), sehingga `docs/.obsidian/app.json` dapat di-commit sebagai pengaturan
  bersama. Itu membutuhkan pengecualian khusus di `.gitignore`, jadi sengaja tidak dilakukan
  tanpa keputusan terpisah.
