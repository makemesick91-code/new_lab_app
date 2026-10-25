# RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` — **Sprint B** dari eksekusi tiga sprint
(2026-10-25 WITA). Memasukkan persetujuan Owner atas PP-1 s.d. PP-10 ke dokumen
keputusan otoritatif dan menyelesaikan dependensi kebijakan untuk implementasi koreksi
pasca-bayar (Sprint 3/7).

**Hasil: `OWNER_DECISION_RATIFICATION = GO`** · docs/rules only ·
**`DEPLOY_REQUIRED = NO` / `VPS_DEPLOY = NOT_REQUIRED`** · tidak ada GO tag runtime.

## 1. Baseline

| Ukuran | Nilai |
|---|---|
| Base tip | `aca70951` (PR #522, Sprint A) |
| Produksi | `20303a5b` — tidak berubah (tidak ada deploy) |
| Aturan terakhir | 206 (tidak ada aturan bernomor baru — §F ditambahkan ke rule 206) |
| Sprint A | `FULL_PREPAYMENT_PILOT_GO = NO_GO`, `OWNER_CASE_APPROVAL = UNVERIFIED`, `HUMAN_SEPARATION = RED_FLAG_UNRESOLVED` |

## 2. Otoritas

Arahan Owner dalam brief tugas ini: *"PP-1 THROUGH PP-10 = OWNER APPROVED"*, berlaku
terhadap proposal kanonik yang dapat diidentifikasi secara tepat dan tidak bertentangan
dengan aturan finansial yang lebih tinggi. Tidak ada tanda tangan, nomor rujukan, atau
waktu persetujuan yang dikarang (pola provenance register untuk D1, OPS-OQ-1a/1b).

Persetujuan ini **bukan** persetujuan Owner atas tagihan tertentu dan tidak mengubah
hasil Sprint A (rule 196 §9).

## 3. B1 — Ekstraksi proposal kanonik

Sumber: `docs/decisions/rme-postpayment-correction-owner-decision-proposals.md`
(PR #521). Redaksi usulan **tidak diubah**; yang ditambahkan hanya baris *Status* dan
*Yang disetujui* per PP, catatan bahwa tabel klasifikasi §1 adalah keadaan pra-ratifikasi,
dan ringkasan §4 baru (ringkasan lama disimpan).

| PP | Opsi dalam proposal | Usulan kanonik | Disetujui | Ambiguitas |
|---|---|---|---|---|
| PP-1 | A / B / C | A | **A** | tidak ada |
| PP-2 | A / B / C | A | **A** | tidak ada |
| PP-3 | A / B / C | A ("alternatif aman bila Owner ingin bertahap: B") | **A** | tidak ada — B bersyarat pada pernyataan Owner yang tidak dibuat |
| PP-4 | A / B / C | A | **A** | tidak ada |
| PP-5 | A / B | A | **A** | tidak ada |
| PP-6 | satu usulan | — | sebagaimana usulan | tidak ada |
| PP-7 | satu usulan | — | sebagaimana usulan | tidak ada |
| PP-8 | (a) + (b) | tolak / tidak dibuat | sebagaimana usulan | tidak ada |
| PP-9 | satu usulan | — | sebagaimana usulan | tidak ada |
| PP-10 | satu usulan | — | sebagaimana usulan | tidak ada |

Tidak ada proposal dengan opsi saling eksklusif yang dibiarkan terbuka, sehingga tidak
ada bagian `APPROVED_IN_PRINCIPLE / IMPLEMENTATION_BLOCKED`.

## 4. Pemetaan ke kode register (cakupan dibatasi)

| Kode | Sebelum | Sesudah |
|---|---|---|
| D2 | PENDING | dijawab PP-2 — **hanya** koreksi pasca-bayar |
| D7 | approved, catatan teknis `CONFLICTING` | diperluas ke kwitansi terbit oleh PP-5; catatan teknis tetap benar untuk pra-bayar |
| D8, D9, D13 | PENDING | dijawab PP-6 untuk pelaporan koreksi; netting refund milik sprint refund |
| D12 | PENDING | dijawab PP-3 (turun) + PP-4 (naik) |
| D14, D15, D17 | PENDING | dijawab PP-7 |
| D19 | `APPROVED_SCOPE` | diperluas ke kewajiban kelebihan bayar oleh PP-9 (read-only) |
| D23 | PENDING | dijawab PP-8 untuk koreksi pasca-bayar |
| D4, D5, D6, D18, D25, D26 | PENDING | **tetap PENDING** (refund, settlement, reversal, kredit pasien) |
| D21c, D21g, D22 | PENDING | **tetap PENDING** |
| D28 | interim guard | **tetap** |

## 5. B2 — Resolusi konflik kebijakan

Rinciannya di register, bagian "Ratification of PP-1 … PP-10 → Policy conflict
review". Ringkas:

- **D7** — tidak ada data historis yang diubah. Snapshot historis dan dokumen koreksi
  baru dibedakan: v0/vN untuk tagihan, snapshot kwitansi untuk kwitansi terbit. Catatan
  teknis "tidak ada kwitansi terbit yang pernah tidak berlaku" tetap benar untuk koreksi
  pra-bayar dan hanya digantikan untuk koreksi pasca-bayar. Bukan perubahan substansial
  kebijakan → tidak ada aktivasi yang perlu dihentikan karenanya.
- Asumsi koreksi pra-bayar, otoritas persetujuan, penyesuaian keuangan, kewajiban
  kelebihan bayar, batas refund (permintaan/persetujuan/settlement), konsekuensi lab,
  visibilitas cabang, dan urutan aktivasi — konsisten; tidak ada kebijakan lama yang
  dihapus.
- **Urutan aktivasi:** PP-10 + Sprint A NO_GO ⇒ aktivasi pasca-bayar **diblokir**;
  implementasi dengan flag OFF diizinkan.
- **Temuan Sprint A** (`RED_FLAG_UNRESOLVED` pada akun 29/1, pasangan yang dinamai PP-1)
  bukan konflik ratifikasi, melainkan tindak lanjut Owner sebelum aktivasi apa pun
  bergantung pada pasangan itu.

## 6. B3 — Dokumen yang diperbarui

| Berkas | Perubahan |
|---|---|
| `docs/decisions/rme-postpayment-correction-owner-decision-proposals.md` | status `OWNER_POLICY_APPROVED`, opsi yang disetujui per PP, ringkasan §4; teks asli disimpan |
| `docs/decisions/rme-billing-correction-owner-decisions.md` | baris kronologi 2026-10-25, tabel PP, catatan D7, kalimat kode PENDING, bagian ratifikasi (pemetaan + konflik) |
| `docs/adr/0012-rme-postpayment-invoice-correction.md` | status ACCEPTED (keputusan); implementasi/aktivasi belum |
| `docs/architecture/rme-postpayment-invoice-correction-design.md` | baris status |
| `docs/operations/rme-postpayment-invoice-correction-sop-draft.md` | catatan: keputusan disetujui, SOP tetap DRAF sampai aktivasi |
| `.cursor/rules/206-…preparation.mdc` | §A butir 2 ditandai SUPERSEDED; §C judul; **§F baru (butir 23–28)** |
| `CLAUDE.md` | entri sprint ini |

Status yang dipakai adalah `OWNER_POLICY_APPROVED` + `REPOSITORY_POLICY_RECORDED` —
**bukan** `TECHNICAL_ENFORCEMENT_DEPLOYED`. Tidak ada aturan bernomor baru: fondasi
permanen yang ditambahkan (rujukan persetujuan wajib di kode, D7 ke kwitansi, aktivasi
diblokir) masuk rule 206 §F. `.sprint/current.yml` tidak diubah (sprint docs-only).

## 7. B4 — Governance dan penutupan

*Dicatat oleh FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (2026-10-25):*

| Butir | Bukti |
|---|---|
| CI kandidat (PR #523, head `107dfa20`) | run `40195496972` — success |
| Merge | `572a32a9`, tree `1cae6cba` = tree kandidat |
| CI pasca-merge (push ke base) | run `40197230789` — success (Full Suite skipped sesuai kebijakan; skipped ≠ pass) |
| Deploy | tidak — perubahan dokumen saja (`DEPLOY_REQUIRED = NO`) |
| GO tag runtime | tidak ada (keputusan, bukan runtime) |
| Worktree, branch lokal/remote | dihapus; tidak ada perubahan tak ter-commit milik tugas ini |

## 8. Roadmap program (7 sprint)

| Sprint | Isi | Status |
|---|---|---|
| 1/7 | OPS-OQ-1 dispute + tahan pembayaran | GO |
| 1A | peninjau cabang + bayar Kontrol saja | GO |
| 2/7 | closure pilot koreksi pra-bayar SPN4 | **NO_GO — `OPERATIONAL_OR_EVIDENCE_BLOCKED`** (Sprint A) |
| 3/7 | koreksi pasca-bayar | keputusan **disetujui** (sprint ini); implementasi = Sprint C (flag OFF); aktivasi diblokir PP-10 |
| 4/7 | refund — permintaan & persetujuan | belum dimulai; D4/D5/D18 PENDING |
| 5/7 | refund — settlement | belum dimulai; D25 PENDING |
| 6/7, 7/7 | — | belum didefinisikan di repositori |

Progres tercatat: **1/7**.
