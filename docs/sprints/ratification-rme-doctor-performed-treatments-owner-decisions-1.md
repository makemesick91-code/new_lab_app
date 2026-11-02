# RATIFICATION-RME-DOCTOR-PERFORMED-TREATMENTS-OWNER-DECISIONS-1 — Ratifikasi keputusan Owner DPT-Q2 … DPT-Q10

**Jenis:** dokumentasi/kebijakan saja (`docs_only`). **Tidak ada perubahan runtime, migrasi, permission, route,
flag, atau deploy.** Program: DAENGTISIAMS-RME-BILLING-CORRECTION. Lanjutan: `ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1`.

## 1. Baseline otoritatif

| Item | Nilai |
|---|---|
| Base (remote, `origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`) | `584e2bd6` (evidence docs, tidak di-deploy) |
| Runtime produksi saat ratifikasi | `931c716c` / tree `150679aa`, tag exact-match `feature-rme-doctor-performed-treatments-1-implementation-go` |
| `FEATURE_RME_DOCTOR_PERFORMED_TREATMENTS` | tidak di-set di produksi → default **OFF** |
| `FEATURE_RME_ZERO_VALUE_CLOSURE` | tidak di-set → default **OFF** |
| Tabel DPT di produksi | `trx_rme_treatment_declarations` 0 baris, `trx_rme_performed_treatments` 0 baris |
| Kunjungan 7 hari terakhir per cabang | SPN4 = 2; TLK1, LDK2, ATG3 = 0 (diukur read-only 2026-11-01 15:51 UTC) |

## 2. Sumber persetujuan

- **Kebijakan DPT-Q2 … DPT-Q10:** pernyataan eksplisit Owner di brief eksekusi sprint ini (diterima 2026-11-01
  ≈ 23:50 WITA) yang menyetujui secara prinsip rekomendasi yang sebelumnya disajikan. **Tidak ada dokumen
  bertanda tangan; tidak ada yang diklaim.**
- **Penetapan pilot DPT-Q8:** brief hanya menyetujui "satu cabang" tanpa menyebut cabangnya. Empat fakta operasional
  ditanyakan dalam sesi dan dijawab Owner pada 2026-11-02 ≈ 05:05 WITA (waktu jawaban tiba): **SPN4 — Cabang
  Sunu**; kohort **semua dokter penangan resmi di SPN4**; pelatihan **sudah, dijamin Owner** (`OWNER_ATTESTED`);
  jendela aktivasi **segera setelah deploy aktivasi terverifikasi**.

## 3. Matriks keputusan

| ID | Status | Inti |
|---|---|---|
| DPT-Q2 | `OWNER_POLICY_APPROVED_STAGED` | detektif selama pilot; blokir NO_CHARGE tahap berikutnya, belum diimplementasikan |
| DPT-Q3 | `OWNER_POLICY_APPROVED_STAGED` | wajib sebagai target; opsional selama pilot |
| DPT-Q4 | `OWNER_POLICY_APPROVED` (arah) | amandemen terkendali & teraudit; belum dibangun |
| DPT-Q5 | `OWNER_POLICY_APPROVED` | Konsultasi/Kontrol bukan tindakan; tetap dikecualikan dari picker |
| DPT-Q6 | `OWNER_POLICY_APPROVED` | Kontrol operasional ≠ `visit_type` Kontrol ≠ NO_CHARGE; tanpa restatement KPI |
| DPT-Q7 | `OWNER_POLICY_APPROVED` (arah) | pemulihan D28 terkendali diperlukan; tidak ada operasi yang diizinkan |
| DPT-Q8 | `OWNER_POLICY_APPROVED` + penetapan | satu cabang pilot: SPN4; opsional; detektif; independen dari NO_CHARGE |
| DPT-Q9 | `OWNER_POLICY_APPROVED` | hanya dokter penangan resmi (`visit.doctor_id`) |
| DPT-Q10 | `OWNER_POLICY_APPROVED` | sumber Procedure SATUSEHAT tidak berubah |

Rincian 9 field per keputusan: `docs/decisions/rme-doctor-performed-treatments-owner-decision-package.md`.

## 4. Siklus status (dipisahkan, tidak digabung)

```mermaid
flowchart LR
  A[OWNER_POLICY_APPROVED] --> B[REPOSITORY_POLICY_RECORDED]
  B --> C[TECHNICAL_ENFORCEMENT_DEPLOYED]
  C --> D[PRODUCTION_ACTIVATED]
  D --> E[OPERATIONAL_ACCEPTANCE_VERIFIED]
  classDef done fill:#e6f4ea,stroke:#2e7d32;
  class A,B done;
```

Sprint ini mencapai **A dan B saja.** C, D, E adalah milik `ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1` dan
masing-masing butuh bukti sendiri.

## 5. File yang diubah

| File | Perubahan |
|---|---|
| `docs/decisions/rme-doctor-performed-treatments-owner-decision-package.md` | status `PENDING` → digantikan (teks lama dipertahankan); 9 field per DPT; penetapan pilot |
| `docs/decisions/rme-billing-correction-owner-decisions.md` | legenda +2 label aktivasi; kronologi CB-Q, DPT-Q1, DPT-Q2…Q10, penetapan DPT-Q8; bagian ratifikasi |
| `docs/decisions/rme-d28-recovery-proposal.md` | baru — proposal DPT-Q7, `PROPOSAL_NOT_APPROVED` |
| `.cursor/rules/219-rme-doctor-performed-treatments.mdc` | setiap "(pending)" diganti keputusan; §15 baru (ruang lingkup pilot, prasyarat tahap STAGED) |
| `.cursor/rules/218-rme-clinical-billing-consistency.mdc` | tiga referensi DPT-Q2/Q3/Q7 diselaraskan |
| `docs/adr/0016-rme-doctor-performed-treatments.md` | addendum ratifikasi (keputusan ADR tidak berubah) |
| `docs/operations/rme-tindakan-dilakukan-dokter-sop.md` | cakupan pilot SPN4, opsional/detektif, DPT-Q4/Q5/Q9, eskalasi selisih tagihan |
| `.claude/rules/rme-clinical.md`, `.claude/rules/rme-billing.md` | ringkasan AI diselaraskan dengan rule 219 §15 |

Rule 217 tidak diubah: §10-nya (Kontrol operasional ≠ NO_CHARGE) sudah sesuai dan tidak menyebut keputusan yang tertunda.

## 6. Pemeriksaan konsistensi

Tidak ada dokumen yang menyatakan atau menyiratkan bahwa: pernyataan wajib sudah aktif; NO_CHARGE sudah diblokir;
amandemen sudah ada; pemulihan D28 sudah ada; aktivasi global disetujui; sumber Procedure SATUSEHAT berubah;
MEDIUM-1 tertutup. Pemeriksaan dilakukan dengan grep atas `(pending)`, `PENDING_OWNER_DECISION`, `CONSISTENT`,
`CLOSED`, dan `mandatory` di semua file yang diubah (hasil di §7).

## 7. Bukti

### 7.1 Lokal (worktree terisolasi dari base remote `584e2bd6`)

| Pemeriksaan | Hasil |
|---|---|
| `scripts/ci/resolve-gates.sh` atas 10 file yang berubah | `gate_profile=docs_only`, `run_critical_tests=false` |
| `git diff --check` | bersih |
| Test governance dokumen (`DevflowAiInstruction`, `DevflowSprintTooling`, `LegacyRmeProgramClosureContract`, `ProductionShellCommandGuard`, `EnterpriseDocumentation`) | 99 passed / 2619 assertions |
| `devflow:base-ref-check --strict`, `foundation:devflow-check --strict`, `sprint:manifest-check`, `foundation:roadmap-check --strict` | GO / exit 0 |
| `foundation:enterprise-documentation-check` | exit 1 **identik pada base murni** (stash → exit 1, 6 sub-gate gagal); sub-gate ENT-11/ENT-12/ENT-13 butuh lingkungan deploy/DB — `LOCAL_ENVIRONMENT_FAULT`, bukan temuan sprint ini |
| Grep kontradiksi (§6) | 0 klaim positif; semua temuan adalah kalimat penyangkalan atau konteks OPS-OQ-1 (memang semua cabang) |

`.sprint/current.yml` sengaja tidak diubah (sprint `docs_only`; mengubahnya memaksa profil `unknown_high_risk`).

### 7.2 CI, merge, Obsidian

Dicatat oleh catatan sprint `ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1` (PR ini tidak dapat memuat SHA merge-nya sendiri).
