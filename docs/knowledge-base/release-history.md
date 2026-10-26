---
kb_status: snapshot
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---

# Riwayat Rilis

Kembali ke [00-INDEX](../00-INDEX.md).

Riwayat rilis **diturunkan dari git**, bukan disalin ke dokumen. Daftar yang disalin akan langsung
usang, karena rata-rata ada lebih dari satu GO tag baru setiap hari.

## Sumber

| Sumber | Cakupan | Status |
|---|---|---|
| GO tag git (`*-go`) | Semua sprint yang ditutup dengan GO | `AUTHORITATIVE` untuk "apa yang dinyatakan GO" |
| `docs/sprints/` | Catatan per sprint (311 dokumen) | `HISTORICAL` |
| `CLAUDE.md` | Hanya aturan global CG-01…CG-24; tidak lagi memuat catatan sprint (sejak 2026-10-26) | `CURRENT` |
| [Arsip `CLAUDE.md`](../sprints/claude-md-archive/README.md) | 240 bagian penutupan sprint yang dulu ada di `CLAUDE.md`, verbatim, sampai commit `53d76643` (2026-10-25) | `HISTORICAL` |
| [sprint_history](../sprint_history.md) | Hanya sampai **2026-06-28** | `STALE` |
| [24 Sprint History](../ai-knowledge/24_DaengtisiaMS_Sprint_History_Changelog.md) | Hanya sampai **2026-06-28** | `STALE` |

## Snapshot per 2026-10-25 (commit `572a32a9`)

- 673 tag; 422 GO tag; 421 GO tag sudah ter-merge ke branch basis.
- 325 GO tag dibuat **setelah** 2026-06-28. Rentang inilah yang tidak tercakup oleh `sprint_history.md`.

| Bulan | GO tag ter-merge |
|---|---:|
| 2026-06 | 115 |
| 2026-07 | 155 |
| 2026-08 | 64 |
| 2026-09 | 48 |
| 2026-10 (hingga tanggal 25) | 39 |

## Perintah untuk menurunkan riwayat

Semua perintah ini hanya membaca dan aman dijalankan dari checkout mana pun.

```bash
# GO tag terbaru (paling baru di atas)
git for-each-ref --merged HEAD --sort=-creatordate \
  --format='%(creatordate:short)  %(refname:short)' 'refs/tags/*-go' | head -30

# GO tag dalam rentang tanggal tertentu
git for-each-ref --merged HEAD --sort=creatordate \
  --format='%(creatordate:short)  %(refname:short)' 'refs/tags/*-go' \
  | awk '$1 >= "2026-10-01" && $1 <= "2026-10-31"'

# Isi satu rilis: commit yang ditunjuk tag dan pesannya
git show --no-patch --format='%h %cs %s' <nama-tag>

# Dokumen sprint terkait (nama tag tanpa akhiran -go biasanya sama dengan slug sprint)
ls docs/sprints | grep <bagian-nama-sprint>
```

## Batasan yang perlu diketahui

- **GO tag tidak sama dengan "sudah di-deploy".** Status deployment produksi tercatat di dokumen
  sprint terkait (`docs/sprints/`; untuk sprint sampai 2026-10-25 juga di arsip `CLAUDE.md`) dan di runbook deploy. Halaman ini tidak memuatnya.
- Beberapa sprint ditutup dengan status `WATCH` atau `NO-GO` **tanpa** GO tag. Sprint seperti
  itu hanya tercatat di `docs/sprints/` (dan, sampai 2026-10-25, di arsip `CLAUDE.md`).
- Tanggal yang dipakai adalah tanggal **pembuatan tag**, bukan tanggal merge.
