# RME-LAB-FEE-APPROVAL-1 — Owner Design Lock, rule 245–249, dan rencana remediasi R6 (dokumentasi saja)

| Atribut | Nilai |
|---|---|
| Sprint | `RME-LAB-FEE-APPROVAL-1` |
| Tanggal | 2026-11-20 (WITA) |
| Status | **Gate 1: NO-GO.** Dokumentasi governance saja; tidak ada implementasi |
| Klasifikasi | FOUNDATION-FIRST: FINANCIAL INTEGRITY & DATABASE SECURITY = FOUNDATION + SECURITY FIX (A-14). PR ini: FOUNDATION DOCUMENTATION, manifest `DOCS_ONLY` |
| Base / acuan audit | `3a231c28633d981de7b3c97270f2814897c23f41` (tip base terverifikasi lewat `git fetch` dan `ls-remote`, 2026-11-20) |
| Branch | `docs/rme-lab-fee-approval-1-design-lock` |
| PR | Dibuka dari branch ini; **tidak di-merge** tanpa keputusan Owner |
| Merge SHA / GO tag | — (tidak ada; dokumentasi tidak di-deploy, tidak ada GO tag) |
| Keputusan Owner | [Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md); register kronologis [rme-billing-correction-owner-decisions](../decisions/rme-billing-correction-owner-decisions.md) |

## 1. Ringkasan

Sprint ini mencatat keputusan Owner atas desain persetujuan biaya LAB pada invoice RME, FULL_REVERSAL dan ICFR,
independensi maker-checker, perlindungan jejak audit, dan pemisahan privilege PostgreSQL (R6). Hasilnya rule kanonik
245–249 berstatus **DESIGN LOCKED, NOT IMPLEMENTED**.

Tidak ada kode aplikasi, migrasi, konfigurasi database, skrip produksi, flag, role database, maupun data produksi yang
diubah. Produksi tidak diakses. BK-2 tidak dijalankan, tidak dipicu, dan timernya tidak diubah oleh eksekusi ini
(ODR-03).

## 2. Audit Gate 1 (read-only, 2026-11-20)

Audit dilakukan pada `3a231c28` dengan analisis statis. Hak produksi tidak diperiksa (tidak ada izin akses produksi).

- **Hasil:** 37 temuan (CRITICAL 0, HIGH 8, MEDIUM 19, LOW 10). **Rekomendasi: NO-GO.** R6 tidak bisa dinyatakan clear.
- **Catatan severitas:** R6-02 naik menjadi CRITICAL bila katalog produksi menunjukkan `rolsuper` atau `rolbypassrls`
  bernilai true untuk role runtime.

### 2.1 Tujuh blocker (semuanya masih terbuka)

| ID | Temuan | Rencana |
|---|---|---|
| GOV-01 | Desain sprint belum tercatat di repo | PR dokumentasi ini mencatatnya; implementasi belum ada |
| GOV-03 | FULL_REVERSAL/ICFR tidak ada; bersinggungan dengan D26/D6 | Diselesaikan secara desain (ODR-02, ICFR Opsi 2, rule 249); belum diimplementasikan |
| R6-01 | Satu identitas DB untuk migrasi dan runtime (runtime = owner objek; trigger penjaga bisa dimatikan) | R6-T02…T06 |
| R6-02 | Atribut, ownership, dan keanggotaan role produksi NOT VERIFIED | R6-T01 (PERM-01) |
| FIN-01 | Tidak ada klasifikasi biaya LAB; baris free-text berharga bebas tanpa approval | FIN-T01, FIN-T03 |
| FIN-03 | Tidak ada CHECK atau trigger di tabel keuangan; bypass SQL langsung terbuka | FIN-T04, FIN-T05, ditambah R6 |
| MC-02 | Calon checker (Supervisor RME) juga memegang hak kasir di seluruh cabang | MC-T01 |

### 2.2 HIGH lain

- **FIN-02:** HAS_LAB dibaca live dari master. Rencana: FIN-T02.
- **AUD-01:** `sys_audit_logs` bisa diubah dan dihapus. Rencana: AUD-T01.

### 2.3 Temuan lain (MEDIUM/LOW)

- **Governance dan lingkungan:** GOV-02, GOV-04, ENV-01.
- **R6:** R6-03, R6-04.
- **Keuangan:** FIN-04 (risiko biaya LAB ganda), FIN-05 (baris LAB Rp0), FIN-06 (koreksi dan treatment LAB), FIN-07
  (perintah synthetic pilot tanpa guard environment), FIN-08.
- **Maker-checker:** MC-01 (konversi kandidat oleh satu aktor), MC-03, MC-04.
- **Cabang:** BR-01 (kandidat fail-open pada cabang null), BR-02, BR-03 (MAIN hanya dikecualikan lewat data; seeder
  smoke-test), BR-04, BR-05.
- **Audit:** AUD-02, AUD-03, AUD-04.
- **Konkurensi:** CON-01…CON-05.
- **Arsitektur dan dokumentasi:** ARC-01, DOC-01.

Pemetaan setiap temuan ke tugas ada di §6. Dokumentasi ini tidak menutup satu pun temuan.

## 3. Keputusan Owner (ringkas)

Teks lengkap ada di [Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md).

- **Disetujui:**
  - ODR-01…04, DL-LAB, DL-SNAP, DL-REV, DL-TRANS, DL-ICFR (Opsi 2), T1–T10, DL-R6 (baseline), DL-CLASS;
  - A-01, A-02, A-04…A-11, A-13…A-25, A-29, A-30;
  - REV-01…REV-05;
  - M1 sebagai preferensi bersyarat.
- **Usang:** A-12, karena approval terjadi sebelum terbit sehingga tidak ada hold pembayaran.
- **Masih terbuka:**
  - isi daftar kode alasan (A-25);
  - alur invoice LAB Rp0 (DES-LAB-RP0, A-23);
  - A-26, A-27, A-28;
  - bukti rekonsiliasi M1.
- **RA-LAB-1:** `PENDING_OWNER_APPROVAL`.

## 4. Cakupan PR ini

**Dokumen baru:**
- `docs/decisions/rme-lab-fee-approval-1-owner-design-lock.md`
- `docs/architecture/rme-lab-financial-state-machine.md`
- `docs/architecture/rme-billing-reversal-icfr.md`
- `docs/architecture/maker-checker-authorization.md`
- `docs/architecture/database-least-privilege-r6.md`
- `docs/operations/rme-billing-posture-2026-10-25.md` (dipindah verbatim dari `.claude/rules/rme-billing.md`)
- dokumen ini

**Rule kanonik baru (DESIGN LOCKED, NOT IMPLEMENTED):**
- `.cursor/rules/245-database-least-privilege.mdc`
- `.cursor/rules/246-rme-lab-fee-approval-and-billing-integrity.mdc`
- `.cursor/rules/247-maker-checker-independence.mdc`
- `.cursor/rules/248-audit-trail-append-only.mdc`
- `.cursor/rules/249-rme-billing-reversal-and-icfr.mdc`

**Amandemen dengan penambahan** (teks lama tetap utuh):
- rule 215 dan 216: bagian "reversal-sourced obligations" (A-13, A-19);
- rule 238 §4: penanda SUPERSEDED IN PART, khusus klausul biaya LAB.

**Rule domain:**
- File baru `.claude/rules/database-security.md` (AIR-DBS-01…09).
- `rme-billing.md`: posture dipindah, AIR-RMB-48…51, dan tiga baris kanonik.
- `lab.md`: AIR-LAB-35, AIR-LAB-36, dan satu baris kanonik.
- `branch-context.md`: AIR-BRC-32.
- Kalimat otoritas A-21 di ke-16 file domain.

**Instruksi dan indeks:**
- `CLAUDE.md`: `database-security` ditambahkan ke daftar domain, paragraf otoritas A-21, serta butir 1 dan 3.
- `docs/00-INDEX.md`: urutan otoritas dan tautan baru.
- `docs/decisions/rme-billing-correction-owner-decisions.md`: entri kronologis dan bagian baru.

**Test:** satu baris literal otoritas di `tests/Feature/Foundation/DevflowAiInstructionSystemTest.php` (A-21).

**Sengaja tidak diubah:**
- kode aplikasi, migrasi, konfigurasi, skrip deploy dan backup;
- `.sprint/current.yml` (program CC1 masih terbuka) dan `AGENTS.md`;
- kalimat CG-09 (A-15: hanya bila penegakan sudah ada);
- rule 166, 194, 207, 232;
- catatan sprint historis yang masih memuat literal otoritas lama (`dev-ai-claude-memory-optimization-1.md`; teks
  historis dipertahankan);
- `docs/knowledge-base/doc-status-registry.md`: dokumen baru memakai frontmatter `kb_status` sesuai
  [konvensi](../knowledge-base/conventions.md), sehingga registry (untuk dokumen lama) tidak perlu entri baru.

## 5. Manifest DEVFLOW (`DOCS_ONLY`, tanpa mengganti `.sprint/current.yml`)

Dipakai lewat `sprint:scope-audit --manifest=<file sementara>` (opsi `--manifest` didukung perintah).

```yaml
id: RME-LAB-FEE-APPROVAL-1
type: DOCS_ONLY
module: Rme
title: "Owner design lock documentation, rules 245-249 and the R6 remediation plan (documentation only)"
base_branch: feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
runtime_change: false
schema_change: false
frontend_change: false
security_impact: true
branch_isolation_impact: false
ledger_impact: false
deploy_required: false
browser_required: false
test_profiles: [focused]
```

## 6. Backlog remediasi (dependency-aware; semua PROPOSED)

| ID | Prio | Tugas | Temuan |
|---|---|---|---|
| GOV-T01 | P0 | PR dokumentasi ini | GOV-01, GOV-02, DOC-01 (sebagian) |
| R6-T01 | P0 | Paket query read-only oleh Owner (PERM-01) | R6-02 |
| R6-T02…T06 | P0 | Inventaris kelas tabel dan row-lock → `pgsql_migrate`, `foundation:db-privilege-check`, job CI, saklar deploy → rehearsal → produksi (PERM-06) | R6-01, R6-03, R6-04 |
| A19-T01 | P0 | Amandemen rule 215/216 + skema sumber obligation | GOV-03 (T5) |
| AUD-T01 | P0 | Lapis 1 append-only, termasuk log status lab | AUD-01, AUD-02 |
| MC-T01 | P0 | Guard generik, penunjukan, allowlist Owner, CHECK, retrofit koreksi | MC-02, MC-03, BR-02 |
| FIN-T01 | P0 | Spesifikasi final biaya LAB | FIN-01, FIN-04, FIN-05 |
| FIN-T05 | P0 | Audit data calon CHECK (PERM-01) | FIN-03 |
| REV-T00 | P0 | Desain reversal final: bukti M1, gate LAB, deklarasi A-30, guard dispute | GOV-03 |
| LAB-T01, LAB-T02 | P0 | Kontrak `RmeLabObligationGate`; integritas bukti (FK, log append-only, bukti VOID) | A-05 |
| BR-T01, BR-T02, FIN-T07 | P0 | Kandidat fail-closed; eksklusi MAIN dan seeder smoke; guard environment synthetic pilot | BR-01, BR-03, FIN-07 |
| DES-LAB-RP0 | P1 (desain) | Alur invoice LAB Rp0 (penutupan visit, kandidat untuk G = 0); pengecualian A-02 tetap nonaktif sampai selesai | A-23 |
| FIN-T02, FIN-T03, FIN-T04 | P1 | Snapshot; approval sebelum terbit + larangan koreksi; CHECK + constraint trigger | FIN-02, FIN-01, FIN-06, FIN-03 |
| REV-T01, REV-T02 | P1 | Mesin reversal (flag OFF), antrean "Dibatalkan", render DIBATALKAN | GOV-03 |
| CON-T01…T04 | P1 | Lock, blocker, dan retry merge; satu invoice per visit; kandidat via outbox; dokumentasi urutan lock | CON-01…CON-05 |
| MC-T02, AUD-T02, CI-T01 | P1 | Konversi kandidat; audit merge; token NSF-R011 | MC-01, AUD-03, AUD-04 |
| P2 | P2 | OBS-T01, DOC-T01, ARC-T01, BR-T03, BR-T04, FIN-T08, MC-T03 | BR-04, BR-05, FIN-08, MC-04, ARC-01, DOC-01 |

## 7. Register konflik governance

| # | Konflik | Status |
|---|---|---|
| C-01 | Batas impor legacy 100/hari vs rule 166 | RESOLVED (ODR-01) |
| C-02, C-03 | FULL_REVERSAL vs D26/D6/D28 | RESOLVED (ODR-02; mesin terpisah; ICFR Opsi 2) |
| C-04 | Invarian 2 vs D23/PP-8 | RESOLVED (reversal bukan koreksi; T1, A-05) |
| C-05 | REVERSED vs rule 194 §9 / 188 §2 | RESOLVED secara desain (penulis kedua, rule 249) |
| C-06 | Obligation dari reversal vs `source_correction_id` NOT NULL | RESOLVED secara desain (A-13); implementasi P0 |
| C-07 | Biaya LAB vs rule 238 §4 | RESOLVED (supersede sebagian) |
| C-08 | Biaya LAB setelah terbit vs imutabilitas | RESOLVED (A-01: sebelum terbit) |
| C-09 | Free-text vs kamuflase | RESOLVED (biaya LAB tidak pernah free-text) |
| C-10 | "Tanpa BK-2" vs timer otomatis | RESOLVED (ODR-03; timer tidak diubah) |
| C-11 | Daftar transisi baseline vs rule 207 | RESOLVED (A-11) |
| C-12 | Label klasifikasi vs kategori lock | RESOLVED (A-14) |
| C-13 | Batas ukuran rule domain | RESOLVED (A-15; terukur) |
| C-14 | Urutan lock usulan vs kode | RESOLVED secara desain |
| C-15 | Path health endpoint | RESOLVED (path repo) |
| C-16 | Otoritas "kode = sumber kebenaran" | RESOLVED (A-21) |
| C-17 | Pengecualian A-02 (G = 0) vs S6-Q2 dan kandidat hanya untuk PAID | OPEN (DES-LAB-RP0; jalur nonaktif) |
| C-18 | Pengecualian A-02 vs Inv1 | RESOLVED (A-22) |
| C-19 | A-05 vs V2 tanpa COMPLETED | RESOLVED (terminal V2 = DELIVERED/CANCELLED) |
| C-20 | Integritas bukti LAB | OPEN (A-27, A-28; LAB-T02) |
| C-21 | A-19 vs rule 215/216 yang hanya menerima PAID | RESOLVED secara desain; implementasi P0 |
| C-22 | A-20 vs AIR-RMB-27 | RESOLVED (reversal bukan penyelesaian dispute) |
| C-23 | REV-01 vs draf lama (koreksi dengan approval) | RESOLVED |
| C-24 | Append-only vs hak row-lock | RESOLVED secara desain; dibuktikan di CI |
| C-25 | Pekerjaan lab tanpa tautan | RESOLVED (A-30) |

## 8. Dependensi teknis yang belum selesai

| ID | Dependensi | Memblokir |
|---|---|---|
| D-01 | R6: bukti produksi (PERM-01), implementasi, rehearsal, produksi | R6-01/02; CHECK yang tahan runtime terkompromi |
| D-02 | A-19: amandemen 215/216 + skema sumber obligation belum diimplementasikan | T5; ICFR operasional |
| D-03 | Bukti M1 (R1–R11) | Finalisasi M1 |
| D-04 | DES-LAB-RP0 | Jalur pengecualian A-02 |
| D-05 | Daftar kode alasan (A-25) | Jalur pengecualian A-02 |
| D-06 | Integritas bukti LAB; A-26, A-27, A-28 | Guard A-05 untuk invoice yang punya kandidat |
| D-09 | Identitas DB pembuat paket BK-2 dan status timer di host (PERM-02) | Fase produksi R6 |

## 9. Checklist otorisasi

- [x] PERM-03: worktree dan branch dokumen, commit, push, PR tanpa merge (2026-11-20).
- [ ] Merge PR ini: keputusan Owner terpisah.
- [ ] PERM-01: query R6 read-only dan audit data oleh Owner.
- [ ] PERM-02: status timer BK-2 di host (read-only).
- [ ] PERM-04+: setiap sprint implementasi (worktree baru dari base remote; flag baru default OFF; test fokus di
  PostgreSQL 16; token NSF-R011).
- [ ] PERM-06: perubahan role DB produksi (jendela pemeliharaan, rehearsal, admin sudo ber-password Owner).
- [ ] PERM-07: rilis, deploy, dan GO tag (approval bertanda tangan, rule 236).
- Gate 1 GO hanya bila ketujuh blocker RESOLVED dengan bukti dan R6 CLEAR.

## 10. Test dan quality gate

### 10.1 Lingkungan

Lokal dan terisolasi: SQLite `:memory:` dari `phpunit.xml`, `APP_KEY` sekali pakai, dan variabel DB lain dikosongkan.
Tidak ada koneksi ke database lain maupun produksi. Satu proses test per worktree (CG-20).

### 10.2 Hasil

| Pemeriksaan | Hasil |
|---|---|
| `php artisan test --filter` untuk 8 file: `DevflowAiInstructionSystemTest`, `ProductionShellCommandGuardTest`, `ProductionHostIdentityGuardTest`, `DeployHardenBk2OffServerRecoveryTest`, `RmeBillingDisputeGovernanceTest`, `RmeInvoiceCorrectionZeroOriginalGuardTest`, `RmeManualPricingGovernanceTest`, `RmeTariffAuthorityGovernanceTest` | 126 test, 3.217 asersi, **0 gagal**. Ke-126 test berstatus WARN karena faktor lingkungan (lihat bawah) |
| `DevflowAiInstructionSystemTest` saja | 16 test, 2.549 asersi, 0 gagal (16 WARN lingkungan) |
| `sprint:manifest-check --manifest=<sementara> --base-sha=3a231c28…` | **GO**: manifest valid dan konsisten |
| `sprint:scope-audit --manifest=<sementara> --strict` | **GO**, exit 0: modul tersentuh 0; cakupan koheren untuk `DOCS_ONLY` |
| Ukuran file domain (batas 30.000 karakter) | Maksimum `rme-clinical` 29.971, `deploy-infra` 29.944, `doctor-access` 28.934, `rme-billing` 28.066 (sebelumnya 29.959); `database-security` 3.475 |
| `CLAUDE.md` (batas 200 baris / 25.000 byte) | 187 baris / 16.383 byte |
| Nomor rule 245–249 | Bebas di semua ref setelah `git fetch`; tidak ada PR terbuka ke base |
| Posture `rme-billing.md` | Dipindah verbatim (3.804 karakter identik) |
| Pemindaian keamanan atas baris yang ditambahkan | Tanpa kredensial, token, alamat host, alias SSH, path workstation, id akun, perintah terlarang, atau bentuk pengenal disk. Satu-satunya id invoice ada di teks posture historis yang dipindah verbatim (sudah publik di base) |
| Full Suite | **Tidak dijalankan** (CG-18: deferred, bukan passed). CI di PR adalah gate otoritatif |

**Warning lingkungan.** Setiap test melaporkan bahwa pembacaan the environment file di root worktree gagal, karena
worktree ini sengaja tidak memiliki environment file. Warning yang sama terjadi pada file test yang tidak diubah
(`tests/Feature/Foundation/DevflowCanonicalBaseRefTest.php`) di lingkungan yang sama, sehingga bukan akibat perubahan
ini. Status WARN tidak dilaporkan sebagai PASS bersih.

## 11. Risiko dan residual

- **Celah keamanan tetap ada.** Semua kontrol di rule 245–249 belum diimplementasikan. Selama itu, temuan Gate 1 tetap
  menjadi risiko produksi.
- **Margin ukuran sempit.** `rme-clinical.md` berada pada 29.971 dari 30.000 karakter (sisa 29). Penambahan berikutnya
  ke file itu butuh pemadatan.
- **Literal otoritas lama** masih ada di satu catatan sprint historis. Ini disengaja (teks historis dipertahankan).
- **BK-2:** status timer produksi NOT VERIFIED. Eksekusi ini tidak menjalankan maupun mengubah BK-2.
