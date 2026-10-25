---
kb_status: current
kb_verified_against: 572a32a9
kb_updated: 2026-10-25
---

# Menyediakan Knowledge Base untuk ChatGPT

Kembali ke [00-INDEX](../00-INDEX.md).

## Yang perlu dipahami lebih dulu

- **ChatGPT tidak membaca repository lokal secara otomatis.** ChatGPT hanya mengetahui apa yang
  Anda berikan dalam percakapan: teks yang ditempel, berkas yang diunggah, atau sumber yang
  Anda hubungkan sendiri di akun ChatGPT.
- Apa pun yang Anda berikan adalah **salinan pada satu titik waktu**. Begitu repo berubah,
  salinan itu tidak ikut berubah.
- Pilihan yang tersedia (unggah berkas, Projects, connector) bergantung pada paket dan pengaturan
  akun ChatGPT Anda. Dokumen ini tidak mengasumsikan fitur tertentu; periksalah di akun Anda.
- Tahap ini **tidak** membangun API, layanan RAG, server, atau integrasi cloud apa pun.

## Dua fungsi yang berbeda

| | Dokumen sumber | Ringkasan / paket konteks |
|---|---|---|
| Lokasi | Di repo (`docs/`, ADR, rule) | Di luar repo (berkas sementara) atau di `docs/ai-knowledge/` |
| Status | Kanonik, ditinjau lewat PR | Turunan, bertanggal, mudah usang |
| Bila salah | Usulkan perbaikan pada sumbernya | Buang, lalu buat ulang dari sumber |
| Contoh | `docs/adr/0012-….md`, `docs/runbooks/….md` | Paket yang dibuat dengan perintah di bawah |

Jangan memperbaiki fakta hanya di ringkasan. Perbaiki sumbernya, lalu buat ulang ringkasannya.

Ringkasan yang sudah ada,
[DaengtisiaMS_ChatGPT_Master_Knowledge_Compact](../ai-knowledge/DaengtisiaMS_ChatGPT_Master_Knowledge_Compact.md),
berstatus `STALE` (2026-08-21; 13 dari 41 modul tidak disebut). Bila dipakai, beri tahu
ChatGPT tanggalnya dan bahwa isinya mungkin sudah tertinggal.

## Paket konteks yang disarankan per tugas

Kirim sesedikit mungkin, tetapi selengkap yang dibutuhkan:

1. [00-INDEX](../00-INDEX.md) dan [doc-status-registry](doc-status-registry.md), untuk orientasi
   dan agar ChatGPT tahu dokumen mana yang dapat dipercaya.
2. Bagian modul yang relevan dari [module-index](module-index.md).
3. Dokumen spesifik untuk tugas tersebut: ADR, rule, runbook, atau catatan sprint.
4. **Potongan kode yang sebenarnya** (controller, service, migration, atau test). Untuk pertanyaan
   tentang perilaku, kode lebih berharga daripada dokumen mana pun.

## Membuat paket konteks (berkas tunggal untuk diunggah)

Jalankan dari mesin pengembang. Hasilnya ditulis ke `/tmp`, **di luar repo**, sehingga tidak
pernah ikut ter-commit. Ganti daftar `FILES` sesuai tugas Anda.

```bash
REPO=/home/fikri/Projects/new_lab_app          # atau path worktree Anda
OUT=/tmp/daengtisiams-chatgpt-pack.md
FILES="docs/00-INDEX.md docs/knowledge-base/doc-status-registry.md docs/adr/0012-rme-postpayment-invoice-correction.md"

SHA=$(git -C "$REPO" rev-parse --short HEAD)
{
  echo "# Paket konteks DaengtisiaMS — commit $SHA, dibuat $(date +%F)"
  echo "Kode adalah sumber kebenaran. Isi di bawah adalah salinan per commit $SHA."
  for f in $FILES; do
    printf '\n---\n## BERKAS: %s\n\n' "$f"
    cat "$REPO/$f"
  done
} > "$OUT"

# Periksa sebelum mengunggah. Hasil pencarian ini harus ditinjau manusia.
grep -nEi 'password|secret|token|private key|BEGIN [A-Z ]*KEY' "$OUT" || echo "tidak ada kata kunci sensitif"
wc -c "$OUT"
```

## Jangan pernah diunggah

- File environment, isi `storage/`, dump atau backup database, log produksi.
- Berkas di `docs/evidence/` yang memuat identitas produksi, dan dokumen operasi yang berisi
  daftar akun.
- Data pasien dalam bentuk apa pun: nama + identitas, KTP/NIK, rekam medis, tangkapan layar,
  maupun hasil OCR.
- Private key, keystore, atau material signing Android.

## Saat bertanya ke ChatGPT

- Sebutkan commit (`SHA`) paket tersebut, dan tegaskan bahwa kode menang jika ada pertentangan.
- Minta ChatGPT menandai jawaban yang merupakan **asumsi** dan bukan isi dokumen.
- Bawa jawabannya kembali ke repo, lalu verifikasi dengan kode (atau dengan Claude Code)
  sebelum diterapkan.
