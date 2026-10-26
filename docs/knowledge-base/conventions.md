---
kb_status: current
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---

# Konvensi Dokumentasi

Aturan untuk menulis, menempatkan, menautkan, dan memperbarui dokumentasi di vault `docs/`.
Kembali ke [00-INDEX](../00-INDEX.md).

## Di mana dokumen baru diletakkan

Gunakan folder yang sudah ada. Jangan membuat folder paralel untuk jenis dokumen yang sudah
memiliki rumah.

| Jenis dokumen | Lokasi | Pola nama berkas |
|---|---|---|
| Architecture Decision Record | `docs/adr/` | `NNNN-slug.md` (berikutnya: `0013`) |
| Register keputusan owner | `docs/decisions/` | `topik-owner-decisions.md` |
| Spesifikasi fitur / catatan sprint | `docs/sprints/` | `feature-…`, `revision-…`, `sprint-…`, sesuai jenis sprint |
| Dokumentasi bugfix | `docs/sprints/` | `bugfix-…`, `fix-…`, `hotfix-…` |
| Kontrak desain & governance | `docs/architecture/` | `topik-….md` |
| Runbook operasional | `docs/runbooks/` | `topik-runbook.md` (lihat larangan di bawah) |
| Bukti operasi & SOP bertanggal | `docs/operations/` | sertakan tanggal `YYYY-MM-DD` bila bersifat sekali jalan |
| Artefak bukti (JSON, ringkasan uji) | `docs/evidence/<topik>/` | — |
| Templat proses engineering | `docs/engineering/` | — |
| Navigasi knowledge base | `docs/knowledge-base/` | hanya indeks, registry, dan panduan |

Templat sprint resmi: [sprint-runtime-template](../engineering/sprint-runtime-template.md),
[foundation-sprint-template](../engineering/foundation-sprint-template.md),
[hotfix-runtime-template](../engineering/hotfix-runtime-template.md).

## Jangan mengganti nama atau memindahkan dokumen yang sudah ada

Per 2026-10-25, **119 berkas** (8 di `app/`, 20 di `config/`, 91 di `tests/`) merujuk
dokumen dengan **path persis**. Contohnya register keputusan di `docs/decisions/`, ADR
Android di `docs/adr/`, dan runbook yang dipindai oleh test.

Mengganti nama atau memindahkan dokumen bisa membuat test gagal atau, lebih buruk, membuat
gate governance kehilangan berkas yang harus diperiksanya. Bila nama memang harus diubah,
perlakukan sebagai perubahan kode: cari semua rujukan, perbarui bersama, dan jalankan test.

Di Obsidian, nonaktifkan "Automatically update internal links" (lihat
[obsidian-setup](obsidian-setup.md)). Dengan begitu, operasi rename tidak diam-diam mengubah
puluhan berkas lain.

## Gaya tautan

- Gunakan **tautan Markdown relatif standar**: `[teks](../folder/berkas.md)`.
  Obsidian memperlakukannya sebagai tautan internal (graph view dan backlinks tetap berfungsi),
  dan tautan ini juga dapat dibuka di GitHub, VS Code, maupun oleh ChatGPT.
- **Jangan memakai wikilink** `[[…]]`. Wikilink tidak dirender di luar Obsidian.
- **Jangan menautkan folder.** Tulis path folder sebagai teks kode, misalnya `docs/sprints/`.
- **Jangan menautkan berkas di luar `docs/`** (`CLAUDE.md`, `.cursor/rules/`, `app/`, dan
  sebagainya). Tulis sebagai teks kode. Obsidian tidak dapat membuka berkas di luar vault,
  dan mengklik tautan yang tidak dapat di-resolve bisa membuat catatan kosong baru.
- **Jangan menautkan anchor heading** (`berkas.md#bagian`). GitHub dan Obsidian membentuk
  anchor dengan cara berbeda. Sebutkan nama bagiannya di dalam teks.
- Nama berkas tanpa spasi. Saat ini tidak ada berkas di `docs/` yang namanya mengandung spasi.

## Status dan frontmatter

Dokumen knowledge base yang baru diawali frontmatter berikut:

```yaml
---
kb_status: current        # current | snapshot | stale | historical | unverified
kb_verified_against: <SHA commit pendek tempat isi diverifikasi>
kb_updated: YYYY-MM-DD
---
```

Untuk dokumen lama, status dicatat di [doc-status-registry](doc-status-registry.md).
Dokumen lama **tidak** diberi frontmatter secara massal.

## Larangan keamanan

Jangan menulis hal berikut ke dokumen mana pun, termasuk catatan pribadi di dalam vault:

- kredensial, password, private key, token, atau isi file environment;
- dump database, ekspor SQL, atau cuplikan baris tabel produksi;
- data pasien: nama yang dipasangkan dengan identitas, KTP/NIK asli, isi rekam medis,
  atau tangkapan layar yang memuat data pasien;
- alamat IP produksi, daftar akun pengguna, atau topologi jaringan di luar yang sudah
  terdokumentasi di runbook;
- hasil OCR KTP, berkas bukti dari produksi, atau dokumen pindaian.

Aturan khusus dari gate yang sudah ada:

- **Runbook** di `docs/runbooks/` dipindai oleh `ProductionShellCommandGuardTest`. Baris yang
  *menginstruksikan* perintah produksi terlarang (misalnya REPL interaktif) akan membuat test
  gagal, kecuali baris tersebut memakai penanda larangan milik repo (`-- never`).
- Dokumen yang ikut ditangkap sebagai release evidence tidak boleh memuat nama literal berkas
  environment. Tulis "file environment" sebagai gantinya.
- Saat menempelkan gambar di Obsidian, gambar disimpan ke dalam `docs/` dan akan ikut
  ter-commit. Periksa setiap gambar sebelum commit.

## Protokol pembaruan: usulkan, jangan mengedit diam-diam

Setelah perubahan fitur atau saat menemukan dokumen yang keliru, agen AI (dan sebaiknya manusia
juga) **mengusulkan** perubahan dalam laporannya, bukan langsung mengeditnya:

```text
USULAN PEMBARUAN DOKUMEN
- Dokumen        : docs/…/berkas.md
- Klaim saat ini : "…"
- Kenyataan kode : path/ke/Berkas.php:123 (atau migration/route/test)
- Usulan         : …
- Status baru    : current | snapshot | stale | historical
- Risiko jika dibiarkan: …
```

Pengeditan dilakukan setelah disetujui, melalui branch dan PR seperti perubahan kode.
Pengecualian hanya untuk dokumen yang memang menjadi keluaran tugas tersebut (misalnya
catatan sprint dari sprint yang sedang dikerjakan).

Dokumen `HISTORICAL` (catatan sprint lama, ADR yang sudah di-supersede) tidak ditulis ulang.
Koreksi dicatat di dokumen yang lebih baru beserta rujukan ke dokumen lama.

## Merawat knowledge base

| Pemicu | Tindakan |
|---|---|
| Modul baru di `app/Modules/` | Tambahkan ke [module-index](module-index.md) |
| ADR baru | Tambahkan baris di tabel ADR pada [00-INDEX](../00-INDEX.md) |
| Audit menemukan drift | Perbarui baris di [doc-status-registry](doc-status-registry.md) beserta buktinya |
| Folder dokumentasi baru | Tambahkan ke peta topik di [00-INDEX](../00-INDEX.md) |

Validasi tautan sebelum commit: setiap tautan relatif di berkas yang diubah harus menunjuk ke
berkas yang benar-benar ada. Repo ini belum memiliki gate CI untuk tautan dokumentasi.

## Usulan yang sudah diadopsi

- **Pertumbuhan `CLAUDE.md`** (diadopsi 2026-10-26, DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1):
  catatan penutupan sprint **tidak lagi** ditambahkan ke `CLAUDE.md`. Catatan lengkap ditulis
  di `docs/sprints/<sprint-id>.md` (skill `sprint-doc-writer`), rule durable di
  `.cursor/rules/NNN-*.mdc` ditambah satu baris di `.claude/rules/<domain>.md`. Rilisnya dicatat oleh GO tag;
  [release-history](release-history.md) menurunkan riwayat dari tag, jadi tidak perlu ditambah baris. Ke-240 catatan lama ada verbatim di
  [arsip](../sprints/claude-md-archive/README.md). Test yang dulu memeriksa teks bukti di
  `CLAUDE.md` kini membaca `CLAUDE.md` **dan** arsipnya (`aiProjectMemory()` di `tests/Pest.php`).

## Usulan terbuka (belum diadopsi)

- **`AGENTS.md` dan `README.md`** masih memakai nama "ADLMS" dan perlu diselaraskan.
