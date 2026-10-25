---
kb_status: current
kb_verified_against: 572a32a9
kb_updated: 2026-10-26
---

# Cara Claude Code Memakai Knowledge Base

Kembali ke [00-INDEX](../00-INDEX.md).

## Mekanisme

Ada dua titik masuk, keduanya native Claude Code, tanpa MCP server tambahan:

1. **Pointer di `CLAUDE.md`**: bagian singkat "Developer Knowledge Base" di bagian atas
   `CLAUDE.md` menunjuk ke `docs/00-INDEX.md` dan aturan pemakaiannya.
2. **Skill proyek `knowledge-base`** di `.claude/skills/knowledge-base/SKILL.md`: berisi prosedur
   lengkap. Skill ini dimuat hanya saat dipakai.

### Mengapa tidak memakai `@import`

Fakta dari dokumentasi resmi Claude Code (diperiksa 2026-10-25):

| Fakta | Sumber |
|---|---|
| Berkas yang di-import dengan sintaks `@path` **diekspansi dan dimuat ke konteks** bersama `CLAUDE.md` saat sesi dimulai, maksimal empat hop | [memory](https://code.claude.com/docs/en/memory.md) |
| Path di dalam backtick tetap berupa teks literal dan tidak di-import | [memory](https://code.claude.com/docs/en/memory.md) |
| Untuk skill, hanya **deskripsinya** yang dimuat di awal sesi; isinya baru dimuat saat skill dipanggil | [skills](https://code.claude.com/docs/en/skills.md) |
| Disarankan `CLAUDE.md` di bawah 200 baris; berkas lebih dari 4 MiB dilewati seluruhnya | [memory](https://code.claude.com/docs/en/memory.md) |

Karena itu indeks ini **sengaja tidak di-`@import`**. Mengimpor indeks ke setiap sesi akan
menambah beban konteks pada `CLAUDE.md` yang sudah berukuran 1,56 MB, padahal sebagian besar
tugas hanya membutuhkan satu atau dua dokumen.

## Prosedur (ringkas)

1. **Mulai dari tugas, bukan dari dokumen.** Tentukan modul atau topik yang tersentuh.
2. **Buka [00-INDEX](../00-INDEX.md)**, lalu bagian modul yang relevan di
   [module-index](module-index.md). Jangan membaca seluruh `docs/`.
3. **Baca paling banyak 3–5 dokumen tertaut**, utamakan yang terbaru, ditambah rule
   `.cursor/rules/*.mdc` yang disebutkan.
4. **Cek status** di [doc-status-registry](doc-status-registry.md). `SNAPSHOT` dan `STALE`
   hanya petunjuk.
5. **Verifikasi ke kode** sebelum mengandalkan klaim apa pun: cari dengan `grep` atau
   `graphify query` (bila `graphify-out/graph.json` tersedia), lalu baca berkas yang relevan.
   Kode menang jika ada pertentangan.
6. **Laporkan drift** dengan format usulan di [conventions](conventions.md). Jangan mengedit
   dokumen di luar cakupan tugas tanpa persetujuan.

## Anggaran konteks

| Lakukan | Hindari |
|---|---|
| Membaca indeks lalu dokumen tertaut secara selektif | Membaca seluruh `docs/`, `docs/sprints/`, atau `.cursor/rules/` |
| Mencari dengan `grep` untuk istilah tertentu | Membaca `DaengtisiaMS_ChatGPT_Master_Knowledge_Compact.md` utuh (snapshot, 13 modul hilang) |
| Membaca bagian dokumen yang relevan saja | Membaca ulang `CLAUDE.md`; berkas ini sudah dimuat otomatis |
| Memakai subagent untuk penelusuran luas | Membuka puluhan dokumen sprint satu per satu di konteks utama |

## Interaksi dengan alat yang sudah ada

- **Graphify**: `graphify-out/` tidak di-commit, jadi worktree baru biasanya **tidak** memilikinya.
  Hook di `.claude/settings.json` hanya memberi saran bila `graphify-out/graph.json` ada.
  Jika tidak ada, gunakan `grep`.
- **Skill lain** (`limit-saver`, `graphify-navigator`, `sprint-doc-writer`, dan seterusnya)
  tetap berlaku. Skill `knowledge-base` hanya menangani navigasi dokumentasi.
- **Guardrail proyek** di `CLAUDE.md` dan `.cursor/rules/` selalu berlaku. Knowledge base
  tidak pernah menimpa atau melonggarkannya.

## Setelah perubahan fitur

Laporan akhir tugas sebaiknya memuat bagian **Dokumentasi**:

- dokumen yang dibuat atau diubah sebagai keluaran tugas;
- dokumen yang kini keliru akibat perubahan tersebut, dalam format usulan;
- apakah [module-index](module-index.md) perlu diperbarui (modul baru, atau rule/ADR baru).

## Verifikasi integrasi (2026-10-26)

Diuji dengan sesi Claude Code headless **baru** yang dimulai di worktree branch ini. Sesi
dikunci read-only: `--permission-mode dontAsk`, hanya `Read Grep Glob Skill` yang diizinkan,
tanpa server MCP. Promptnya meminta dokumentasi autentikasi dokter, device approval, branch
isolation, dan aturan akses RME, **tanpa menyebut skill**.

| Pemeriksaan | Hasil |
|---|---|
| Skill `knowledge-base` terdaftar di sesi | Ya |
| Dipanggil tanpa diminta | Ya, sebagai tool call **pertama** |
| Urutan baca | `00-INDEX.md` → `module-index.md` → `doc-status-registry.md` → ADR/rule terkait → `grep` ke `app/` dan `config/` |
| Dokumen `STALE`/`SNAPSHOT` | Diberi label dan disarankan untuk dilewati; tidak dipakai sebagai otoritas |
| Klaim diverifikasi ke kode | Ya, dengan sitasi `file:baris`; klaim yang tidak diverifikasi dipisahkan |
| Perubahan berkas | Tidak ada (0 tool penulis dipanggil) |
| Drift dilaporkan | Sebagai usulan. Usulan tersebut (dok. 07 dari `SNAPSHOT` ke `STALE`) diukur ulang lalu diterapkan di [doc-status-registry](doc-status-registry.md) |
| Biaya | Sekitar US$13 untuk 26 turn. Sebagian besar berasal dari `CLAUDE.md` (~1,56 MB) yang dimuat di setiap sesi. Lihat pekerjaan lanjutan `DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1` |

**Skill dimuat dari direktori kerja sesi.** Sesi yang dimulai di checkout yang belum memuat
`.claude/skills/knowledge-base/` (misalnya checkout utama sebelum PR ini di-merge) tidak akan
melihat skill tersebut. Itu bukan kegagalan.

Cara mengulang uji (read-only, jalankan dari root worktree):

```bash
claude -p "Temukan dokumentasi sistem autentikasi dokter, device approval, branch isolation, dan aturan akses RME di DaengtisiaMS. Laporkan dokumen yang relevan beserta status kepercayaannya. Jangan mengubah source code." \
  --output-format stream-json --verbose --permission-mode dontAsk \
  --allowedTools Read Grep Glob Skill \
  --disallowedTools Bash Edit Write NotebookEdit WebFetch WebSearch Agent \
  --strict-mcp-config --no-session-persistence > /tmp/kb-test.jsonl
```
