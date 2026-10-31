# VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-CLOSURE-FINAL-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` — **Sprint 2/7**, closure pilot koreksi
tagihan pra-bayar SPN4 (maker akun 29, checker akun 1). Arahan Owner: tutup Sprint 2
secara sah sebelum Sprint 6, dan jangan pernah mengganti peristiwa operasional nyata
dengan uji teknis.

Aturan: rule 196 (tangga status; §7 pengukuran ulang; §9 kasus tanpa verifier; **§10
ditambahkan oleh tugas ini**), rule 197 (identitas pilot D1e), rule 202 (prosedur loket),
rule 203/204 (dispute), rule 205 (idempotensi pembayaran), rule 207 (receipt snapshot).

**Hasil:** `SPRINT2_FULL_PILOT_GO = NO_GO` · `FINAL_DECISION = OPERATIONAL_BLOCKED` (klasifikasi
rule 196 §9: `OPERATIONAL_OR_EVIDENCE_BLOCKED`) ·
`GO_TAG = NONE` · `DEPLOY_REQUIRED = NO` (`NO_RUNTIME_DELTA`).

**Temuan utama.** Saat tugas ini mulai mengukur (14:49:11 UTC = 22:49 WITA, Sabtu), produksi
sudah memuat **koreksi nyata kedua**, `RMECOR-202610-000002` pada tagihan #43
(`RME-202610-000009`, SPN4), yang dikerjakan dan dibayar antara 14:35 dan 14:42 UTC —
beberapa menit sebelum pengukuran pertama. Kasus itu **terekonsiliasi secara keuangan**
(PASS, direkonstruksi — konsistensi baris, bukan kebenaran harga), tetapi **tidak diamati** (tidak ada verifier, tidak ada baseline segar,
D10 tidak diamati menurut state), **tidak memiliki bukti persetujuan Owner spesifik kasus yang
mendahului pengajuan**, dan **pemisahan manusianya kembali bertanda bahaya**. Karena itu ia
tidak dapat menutup pilot (rule 196 §31, §32, §41). Tugas ini tidak membuat, mengubah, atau
mengulang transaksi apa pun.

**Addendum §19 (klarifikasi Owner, 2026-10-31):** Owner menyatakan kunjungan nyata, kesalahan
harga karena rate card berbeda, persetujuan diberikan sebelum pengajuan, dan mengidentifikasi
akun 29/1/21. Dicatat sebagai `OWNER_RETROSPECTIVE_ATTESTATION`; bukti persetujuan asli masih
`PENDING_VERIFICATION`; Sprint 2 tetap NO_GO.

Semua waktu UTC kecuali ditandai WITA (UTC+8). Tidak ada identitas pasien, nomor KTP/NIK,
rincian tindakan, alamat IP, atau user-agent lengkap di dokumen ini.

## 1. Baseline repositori dan produksi (diukur 2026-10-31 14:49–15:20 UTC, read-only)

| Butir | Nilai |
|---|---|
| Basis kanonik | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `938aee25` (PR #572, docs-only) |
| HEAD / tree produksi | `a550e00d7eef303d6f76a290d493c6ab48a35aa1` / `656ae3a87e7439eeaa05e6b98ce2d54fc2345f5f` |
| Tag produksi (cocok persis) | `feature-rme-refund-settlement-1-implementation-go` |
| Delta runtime basis vs produksi | tidak ada — `938aee25` hanya dokumentasi di atas `a550e00d` |
| Working tree produksi | hanya `storage/runtime-home/` (sudah ada sebelumnya, dibiarkan) |
| Deploy | status runner terakhir `exit=0`; tidak ada proses deploy aktif |
| PR terbuka saat mulai | 0 |
| Tag penutupan penuh | `verification-rme-invoice-correction-prepayment-pilot-1-go` **tidak ada** (benar) |
| `laravel.log` | 1.443.432 byte / 164 ERROR sebelum dan sesudah seluruh pengukuran (0 baru) |
| `/login`, `/health/live`, `/health/ready`, `/health/lb` | 200 lewat `https://daengtisia.online` |
| `queue:failed` | kosong |

## 2. Otoritas aturan dan keputusan yang berlaku

- Rule terbaru 216 (settlement refund). Rule 196 tetap otoritas verifikasi; rule 202 tetap
  prosedur operasional. Tidak ada rule pengganti yang bertentangan.
- Register `docs/decisions/rme-billing-correction-owner-decisions.md`: D1a (approve =
  apply atomik), D1b, D1c (pilot SPN4), D1e (hanya akun 29 mengajukan, hanya akun 1
  menyetujui/menolak), D28 (original Rp0 ditolak di kode). D21c, D21g, D22 tetap PENDING.
- Allowlist identitas pilot **tidak diubah** dan tidak ada keputusan Owner baru untuk mengubahnya.

## 3. Kasus historis #42 — tidak berubah

`RMECOR-202610-000001` (tagihan #42, Rp6.000.000 → Rp5.000.000) tetap seperti dicatat
REAL-CASE-42-1: keuangan `PASS (RECONSTRUCTED)`, persetujuan Owner `UNVERIFIED`, pemisahan
manusia `RED_FLAG_UNRESOLVED`. Tidak ada event, timestamp, alasan, tagihan, atau pembayaran #50
yang disentuh. Tidak ditemukan rekaman persetujuan Owner otentik yang mendahului 2026-10-25
03:20:52 UTC di sumber yang dapat diakses tugas ini; tidak ada yang diminta atau dibuat
secara retroaktif.

## 4. Kasus nyata kedua — `RMECOR-202610-000002` (tagihan #43)

### 4.1 Lini masa (timestamp server basis data)

| UTC | WITA | Peristiwa | Akun |
|---|---|---|---|
| 14:34:34 | 22:34:34 | konteks kerja SPN4 dipilih (konteks harian pertama hari itu) | 29 |
| 14:35:11 | 22:35:11 | kunjungan #64 dibuat | 29 |
| 14:36:14 | 22:36:14 | sesi dokter (lease mati diklaim ulang, audit 1644) | 21 |
| 14:36:34 / 14:36:41 | 22:36 | check-in / pemeriksaan dimulai | — |
| 14:36:57 | 22:36:57 | consent Persetujuan Tindakan Medis ditandatangani | 21 |
| 14:37:14–16 | 22:37 | odontogram dibuat dan difinalkan | — |
| 14:37:20 → 14:37:55 | 22:37 | rekam medis dibuat → difinalkan (35 detik); tulisan tangan disimpan 14:37:45 di lembar kanonik pasien | 21 |
| 14:38:27 | 22:38:27 | tagihan #43 dibuat, **Rp9.000.000** (audit 1645) | 29 |
| 14:38:52 | 22:38:52 | koreksi **SUBMITTED** (25 detik setelah tagihan) → Rp12.000.000 (audit 1646) | 29 |
| 14:40:50 | 22:40:50 | halaman pengawasan koreksi dibuka (audit 1647) | 1 |
| 14:41:11 | 22:41:11 | **APPROVED**, v0 ORIGINAL dibekukan (audit 1648, 1649) | 1 |
| 14:41:12 | 22:41:12 | **APPLIED** (D1a, transaksi yang sama), v1 REVISION dibekukan (audit 1650, 1651) | 1 |
| 14:41:32 | 22:41:32 | pembayaran #51 Rp12.000.000 (`paid_at` formulir 14:41:00), receipt snapshot #1, kandidat lab, kunjungan selesai (audit 1652, 1653) | 29 |
| 14:42:01 | 22:42:01 | aktivitas terakhir sesi akun 29 | 29 |
| 14:49:11 | 22:49:11 | **pengukuran pertama tugas ini** | — |

Runtime yang menjalankan kasus: `a550e00d` (deploy `20261031-130558`, selesai sebelum kasus;
rule 196 §35).

### 4.2 Data koreksi

| Kolom | Nilai |
|---|---|
| `correction_type` / `status` | `PRE_PAYMENT` / `APPLIED` |
| `original_grand_total` → `proposed_grand_total` | Rp9.000.000 → Rp12.000.000, `difference_amount` **+Rp3.000.000** (koreksi NAIK) |
| `invoice_status_at_request` / `paid_total_at_request` / jumlah pembayaran | `UNPAID` / Rp0 / 0 |
| `invoice_version_at_request` → `applied_invoice_version` | 0 → 1 |
| `requested_by` / `decided_by` / `applied_by` | 29 / 1 / 1 |
| `reason` | "salah harga pada card rate" (26 karakter) |
| `decision_note` | kosong |
| `owner_approval_reference` | **NULL** |
| `idempotency_key` | ada |
| Baris koreksi | satu `CHANGE` pada item 51: harga satuan Rp9.000.000 → Rp12.000.000, qty 1, diskon 0 |

## 5. Rekonsiliasi keuangan — `PASS (RECONSTRUCTED)`

### 5.1 Integritas snapshot (skrip PHP di VPS sebagai `daengtisiams`, transaksi `READ ONLY`, selalu di-rollback)

| Pemeriksaan | Hasil |
|---|---|
| `transaction_read_only` | `on` |
| v0 (#3) dan v1 (#4) `integrityHolds()` | PASS / PASS |
| v0 dan v1 `isComplete()` | PASS / PASS |
| v0 dan v1 tanpa tanda perubahan (`created_at = updated_at`; bukti utama adalah hash di atas) | PASS / PASS |
| `fingerprint(v0)` = `invoice_fingerprint` saat pengajuan | PASS |
| `fingerprint(original_snapshot)` = `invoice_fingerprint` | PASS |
| v0 / v1 / proposal `grand_total` | 900.000.000 / 1.200.000.000 / 1.200.000.000 sen |
| Item v1 = item proposal (tuple fingerprint, `original_item_id` 51 dipetakan ke `id` 51) | PASS — perbandingan JSON mentah awalnya melaporkan "beda" palsu (rule 196 §46) |
| Item tagihan kini = item v1 | PASS |
| Tagihan kini: `grand_total` / `paid` / `correction_version` | 1.200.000.000 / 1.200.000.000 sen / 1 |
| Receipt snapshot #1 (pembayaran #51): ada, integritas, bukan rekonstruksi | PASS / PASS / live capture |

### 5.2 Sidik jari global terhadap baseline Sprint 5 (2026-10-31, deploy `a550e00d`)

Dokumen Sprint 5 hanya mempublikasikan angka tagihan, pembayaran, dan status. Sel lain di
kolom baseline ditandai *(r)*: **direkonstruksi** dari baris imutabel (id dan timestamp),
bukan diukur pada saat itu.

| Butir | Baseline Sprint 5 | Kini | Δ | Atribusi |
|---|---|---|---|---|
| Tagihan / Σ grand total / max id | 8 / Rp12.548.000 / 42 | 9 / Rp24.548.000 / 43 | +1 / +Rp12.000.000 | #43 (total terkoreksi) |
| Item / Σ subtotal | 9 / Rp12.548.000 *(r)* | 10 / Rp24.548.000 | +1 / +Rp12.000.000 | item 51 |
| Pembayaran / Σ / max id | 9 / Rp10.548.000 / 50 | 10 / Rp22.548.000 / 51 | +1 / +Rp12.000.000 | #51 |
| Status PAID / PARTIAL / UNPAID | 6 / 1 / 1 | 7 / 1 / 1 | PAID +1 | #43; UNPAID tetap #36 Rp0 |
| Koreksi / baris / event / versi dokumen | 1 / 1 / 3 / 2 *(r)* | 2 / 2 / 6 / 4 | +1 / +1 / +3 / +2 | koreksi #2 — sesuai rule 202 §4 |
| Koreksi aktif / APPROVED tanpa apply | 0 / 0 *(r)* | 0 / 0 | 0 | — |
| Audit koreksi (SUBMITTED, APPROVED, 2× DOCUMENT_VERSION_ISSUED, APPLIED) | — | 5 | +5 | 1646, 1648, 1649, 1650, 1651 — sesuai rule 202 §4 |
| `sys_audit_logs` max id | 1643 *(r, baris terakhir sebelum 14:30)* | 1653 | +10 | 1644 lease dokter, 1645 tagihan, 1646–1651 koreksi (+1647 pengawasan), 1652 pembayaran, 1653 receipt |
| `trx_rme_payment_submissions` | 0 *(r)* | 1 | +1 | pembayaran #51 (rule 205 §C); 0 pembayaran tanpa baris sejak rilis rule 205 |
| Receipt snapshot | 0 *(r, snapshot pertama ber-id 1)* | 1 | +1 | pembayaran #51 (rule 207) |
| Kandidat lab untuk #43 | 0 *(r)* | 1 | +1 | dibuat setelah lunas — bukan mutasi keuangan; PP-8(a) kini mengecualikan #43 dari koreksi lanjutan |
| Order lab sejak 14:30 | — | 0 | 0 | — |
| Permintaan / settlement refund / klaim registry Owner | 0 / 0 / 0 *(r)* | 0 / 0 / 0 | 0 | — |
| `role_has_permissions` / `model_has_permissions` | 524 / 0 *(r, diturunkan:* 517 per rule 196 §29 + 7 grant Sprint 4; Sprint 5 tidak mengubah grant peran)* | 524 / 0 | 0 | — |
| Dispute aktif | 0 *(r)* | 0 | 0 | `rme:billing-dispute-status` VERDICT GO |

Tagihan, item, dan pembayaran yang berubah sejak 14:30 UTC hanya #43, item 51, dan #51. Tidak
ada audit `RME_PAYMENT_REFUSED_*` sejak 14:30, sehingga tidak ada percobaan pembayaran selama
koreksi aktif (14:38:52–14:41:12) dan formulir pembayaran membawa versi pasca-apply (rule 191,
rule 196 §34). **Selisih yang tidak dapat dijelaskan: 0.**

Hasil ini adalah **rekonstruksi** dari baris imutabel. Baseline segar sebelum pengajuan dan
observasi D10 menurut state **tidak dilakukan** pada saat kejadian dan tidak dapat dibuat
belakangan (rule 196 §31).

## 6. Persetujuan Owner spesifik kasus — `UNVERIFIED`

- `owner_approval_reference` NULL, `decision_note` kosong, dan alasan "salah harga pada card
  rate" tidak memuat referensi persetujuan. Registry referensi Owner (rule 214): 0 klaim
  (koreksi pra-bayar boleh tanpa referensi, tetapi tanpa referensi tidak ada bukti).
- Akun checker (1) memegang peran Owner; itu **tidak** menggabungkan persetujuan checker
  dengan otorisasi Owner atas kasus (rule 196 §32).
- Koreksi ini **menaikkan** tagihan pasien +Rp3.000.000 (+33%). Kenaikan memerlukan otorisasi
  Owner yang sama dengan penurunan (rule 196 §44). Klaim "card rate" tidak dapat dicek dari
  sistem: SPN4 tidak memiliki baris `mst_tariffs` untuk tindakan ini (otoritas harga manual,
  rule 194). Hanya `ERROR_DESCRIPTION` tertulis dari Owner yang dapat memutuskan apakah ini
  salah input atau perubahan harga.
- Tidak ada persetujuan yang diminta, dirancang, atau dicatat secara retroaktif (rule 196 §33).

## 7. Operator, sesi, dan pemisahan manusia

| Butir | Hasil |
|---|---|
| Akun 29 (Front Office, maker) / akun 1 (Super Admin + Owner, checker) | aktif, peran sesuai, identitas pilot valid, berbeda akun |
| Matriks identitas (rule 197) untuk 7, 8, 16, 17, 29, 30, 31, 32, 1, 6 | hanya 29 = maker dan 1 = checker; akun lain `none` (ditolak di service); akun 6 (Owner) tanpa izin koreksi |
| Sesi akun 29 / akun 1 | baris sesi aktivitas terakhir 14:42:01 / 14:41:12 |
| Sesi verifier IT di VPS dalam jendela kasus | **0** login SSH 14:00–14:48:59 (pembanding: 21 pada 13:00–14:00 dan 14 sejak 14:49 — milik tugas ini) |
| Alamat jaringan sesi akun 29, 1, dan akun dokter 21 | **1 alamat yang sama** untuk ketiganya |
| Keluarga peramban | 29: Firefox/Linux · 1: Chrome/Linux · 21: Chrome/Android |

Akun berbeda (29 ≠ 1) adalah satu-satunya yang dibuktikan sistem. Alamat bersama dapat berarti
jaringan klinik yang sama — atau satu orang dengan dua perangkat; basis data tidak dapat
membedakannya. Tanda bahaya kasus #42 **berulang**: `HUMAN_SEPARATION = RED_FLAG_UNRESOLVED`.
Tidak ada login atas nama operator, tidak ada perubahan peran, kata sandi, sesi, atau konteks.

## 8. Kelayakan dan keaslian kasus

Pada saat pengajuan, tagihan #43 memenuhi kontrak kelayakan secara teknis (SPN4, total asli
positif, `UNPAID`, 0 pembayaran, versi 0, tidak ada dispute, bukan Rp0). Kini tagihan itu
`PAID` dan memiliki kandidat lab, sehingga **tidak ada kasus layak** di produksi:
`POSITIVE_UNPAID_UNSETTLED_INVOICES_IN_PILOT = 0`.

Keaslian kasus **tidak dapat diputuskan dari basis data**. Tanda peringatan yang dicatat —
bukan tuduhan (rule 196 §45):

1. seluruh rantai kunjungan → consent → odontogram → tulisan tangan → rekam medis final →
   tagihan → koreksi → persetujuan → pembayaran selesai dalam 6 menit 21 detik; rekam medis
   dibuat → final dalam 35 detik;
2. koreksi diajukan 25 detik setelah tagihan dibuat;
3. sesi maker, checker, dan dokter berasal dari satu alamat jaringan;
4. terjadi pukul 22:35 WITA hari Sabtu (distribusi jam kunjungan SPN4 sepanjang sejarah juga
   memuat 00, 06, dan 18 WITA, sehingga jam saja tidak membuktikan apa pun);
5. selesai tepat sebelum tugas closure ini dimulai.

Rantai klinisnya lengkap dan melewati semua gerbang server (consent, ruangan, finalisasi
dengan tulisan tangan). Kasus yang dibuat demi menutup pilot adalah "kesalahan disengaja" yang
dilarang rule 196 §8, siapa pun pelakunya; karena itu kasus ini tidak dapat dihitung sebelum
Owner menegaskan secara tertulis, dari sumber yang sah, bahwa kunjungan, kesalahan harga, dan
persetujuannya nyata.

## 9. Tangga enam tahap rule 196

| Tahap | Bukti yang diperlukan | Bukti aktual | Putusan | Blocker |
|---|---|---|---|---|
| `PILOT_READY` | runtime terdeploy, jalur flag, grant RoleSeeder | `a550e00d`; grant koreksi per peran sesuai rule 195; `role_has_permissions` 524 | **PASS** | — |
| `PILOT_ACTIVE` | probe `STATE=PILOT_ACTIVE`, admitted `[SPN4]` | `STATE=PILOT_ACTIVE`, `PILOT_DECLARED=SPN4 ADMITTED=SPN4`, flag `rme.invoice_correction` ON via env | **PASS** | — |
| `PILOT_LIVE` | probe `--maker=29 --checker=1` → `LIVE_NOW=yes`, GO, **untuk kasus** (sebelum pengajuan) | probe sebelum pengajuan tidak dijalankan; state 14:49:11 UTC (setelah kasus): `LIVE_NOW=yes`, Decision GO — dicatat sebagai state saja (rule 196 §42) | **NOT_REACHED** | probe sebelum pengajuan |
| `AUTHENTICATED_VERIFIED` | login operator sendiri diamati server-side + pemisahan manusia | baris sesi ada, tetapi tidak diamati saat kejadian; tanda bahaya pemisahan manusia | **NOT_REACHED** | observasi langsung; konfirmasi Owner atas operator setiap akun |
| `FINANCIAL_CORRECTION_VERIFIED` | satu koreksi nyata, diotorisasi Owner, SUBMITTED → APPLIED, rekonsiliasi | rekonsiliasi `PASS (RECONSTRUCTED)`; otorisasi Owner `UNVERIFIED`; baseline segar dan D10 tidak diamati | **NOT_REACHED** | persetujuan Owner pra-pengajuan; observasi |
| `FULL_ACTIVATION_GO` | semua di atas + CI/aturan/keamanan/cleanup | — | **NO_GO** | tahap 4–5 |

Tahap yang lebih tinggi tidak di-GO-kan selama bukti tahap di bawahnya kurang.

## 10. Dispute dan idempotensi pembayaran

- Dispute: `ACTIVE_TOTAL=0` di semua cabang; 0 event dispute dalam 24 jam; peninjau yang dapat
  dijangkau SPN4 = 2. Kasus #2 tidak melibatkan dispute, sehingga resolusi
  `CORRECTION_APPLIED` tidak relevan.
- Idempotensi (rule 205): pembayaran #51 memiliki baris `trx_rme_payment_submissions`; 0
  pembayaran sejak rilis rule 205 tanpa baris; 0 pasangan duplikat.

## 11. Review keamanan terfokus

Tidak ada perubahan kode. Invariant diverifikasi terhadap kode (`RmeInvoiceCorrectionService`,
`RmePaymentService`, Graphify + `grep` pada basis) dan terhadap produksi:

| Kontrol | Hasil |
|---|---|
| Peran maker/checker, allowlist identitas (D1e) di service di bawah lock | PASS — `assertAllowed()` pada submit/approve/apply; matriks produksi hanya 29/1 |
| Cakupan cabang SPN4 | PASS — admitted `[SPN4]`, kasus di SPN4 |
| Akun aktif | PASS — `ActiveAccountResolver` (rule 201 §G); kedua akun aktif |
| Maker ≠ checker per akun | PASS (akun); pemisahan manusia **tidak terverifikasi** |
| Otorisasi Owner spesifik kasus | **UNVERIFIED** (kontrol prosedural, bukan kode) |
| Blok pembayaran D10 | `NOT_EXERCISED` — tidak dilanggar (0 pembayaran, 0 penolakan selama aktif), tetapi tidak ada percobaan dan observasi state `NOT_PERFORMED` |
| Pengikatan versi | PASS — tidak ada `RME_PAYMENT_REFUSED_INVOICE_VERSION`; pembayaran setelah apply |
| Imutabilitas keuangan | PASS — v0/v1 hash berlaku, tidak pernah diubah |
| Idempotensi pembayaran | PASS — baris submission ada |
| Dispute / kompatibilitas Kontrol / merge pasien | PASS — 0 dispute; tidak ada kunjungan Kontrol atau merge terlibat |
| Integritas audit | PASS — 5 baris audit koreksi, 3 event, berurutan menurut waktu server |

`OPEN_CRITICAL=0` · `OPEN_HIGH=0` · `OPEN_RELEASE_BLOCKING_MEDIUM=0` (temuan kode). Temuan
operasional (otorisasi Owner, pemisahan manusia, keaslian kasus) adalah blocker penerimaan,
bukan cacat kode, dan diserahkan ke Owner.

## 12. Regresi, DEVFLOW, dan CI

| Pemeriksaan | Hasil |
|---|---|
| `tests/Feature/RmeInvoiceCorrection`, `RmeBillingDispute`, `RmePaymentIdempotency` pada PostgreSQL **16.15** (container sementara milik tugas) | **605 passed / 2940 assertions / 0 failed / 0 skipped** (509,8 s; dihitung dari JUnit; suite konkurensi khusus PG ikut berjalan) |
| Uji tata kelola dokumen/aturan (`DevflowAiInstructionSystemTest`, `ProductionShellCommandGuardTest`, `RmeTariffAuthorityGovernanceTest`, `RmeManualPricingGovernanceTest`, `LegacyRmeProgramClosureContractTest`) | **62 passed / 2587 assertions** |
| `devflow:base-ref-check --strict`, `sprint:manifest-check`, `sprint:scope-audit` | exit 0; basis kanonik `938aee25` (ref lokal basis usang `9eabff07`, remote menang) |
| `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict`, `foundation:roadmap-check --strict` | GO / GO (10/10) / GO |
| `foundation:ci-runtime-control-check --strict`, `foundation:security-compliance-check`, `foundation:enterprise-documentation-check`, `foundation:cicd-enterprise-gate-check` | GO 6/6 · GO 9/9 · GO 21/21 · GO 10/10 pada DB termigrasi — **identik dengan basis yang belum disentuh**. Dijalankan pertama tanpa tabel (SQLite `:memory:`) ketiganya FAIL karena tabel audit tidak ada: kesalahan lingkungan, bukan regresi |
| Classifier CI (`scripts/ci/resolve-gates.sh --head HEAD` pada commit kandidat) | `gate_profile=docs_only`, `run_critical_tests=false` — sama dengan classifier di CI. Gerbang kualitas, NSF-9, dan NSF-10 tetap wajib; langkah uji kritis dilewati sesuai CICD-CTRL-1 (dilewati bukan lulus). Catatan: menjalankan classifier sebelum commit memberi diff kosong dan hasil fail-closed `unknown_high_risk` — itu bukan klasifikasi diff ini |
| CI wajib | pada SHA kandidat persis di PR (bukan di dokumen ini, karena dokumen ini ada di dalam pohon yang diuji) |
| Full Suite | `SKIPPED_NOT_PASS` — kebijakan sementara global AKTIF, tidak ada otorisasi |

Tidak ada uji yang dilemahkan; tidak ada perubahan kode.

## 13. Deploy

`DEPLOY_REQUIRED = NO` · `NO_RUNTIME_DELTA = YES`. Tugas ini hanya menambah dokumentasi dan
aturan; runtime produksi tetap `a550e00d`. Tidak ada redeploy SHA yang sama (rule 196 §15).

## 14. Sinkronisasi aturan

- `.cursor/rules/196-…-verification.mdc`: §10 item 41–48 (kasus tanpa verifier tidak menumpuk
  menjadi GO; probe GO setelah kasus adalah state, bukan observasi; ketiadaan verifier diukur
  dari jurnal SSH; koreksi naik memerlukan persetujuan yang sama; tanda peringatan ke Owner;
  pemetaan `original_item_id`; receipt snapshot pertama; prosedur penutupan tidak berubah).
- `.claude/rules/rme-billing.md`: AIR-RMB-25 diperbarui.
- Register keputusan: observasi produksi 2026-10-31 dicatat dan dievaluasi, tanpa keputusan baru.
- Rule 202 dan dokumen operasional tidak diubah: prosedurnya sudah mensyaratkan verifikasi IT
  sebelum pengajuan; yang gagal adalah kepatuhan, bukan prosedur.

## 15. Pembersihan

Sumber daya milik tugas: berkas SQL dan skrip PHP sementara di VPS (dihapus segera setelah
setiap pemakaian; skrip PHP dikirim lewat stdin, tidak pernah ditulis di VPS), container
PostgreSQL 16 sementara, berkas lingkungan kosong di worktree, berkas scratch lokal, dan
worktree tugas ini. Container, berkas lokal, dan worktree dihapus pada penutupan; hasilnya
dilaporkan di laporan akhir. Tidak disentuh: `storage/runtime-home/`, sesi klinik, audit,
catatan keuangan, cadangan, monitoring, runner GitHub, dan vault Obsidian (hanya dimajukan
dengan checkout terlepas setelah merge).

## 16. Blocker dan tindak lanjut Owner (bukan tindakan tugas ini)

1. **Keaslian kasus tagihan #43.** Mohon tegaskan secara tertulis dari sumber yang sah: apakah
   kunjungan #64 nyata dengan pasien hadir, apakah Rp9.000.000 benar salah input terhadap
   card rate klinik (sertakan `ERROR_DESCRIPTION`), dan apakah ada persetujuan Owner tertulis
   yang **sudah ada sebelum 14:38:52 UTC (22:38:52 WITA)**. Jawaban ini mengubah status
   keaslian kasus, tetapi tidak dapat menghasilkan tahap observasi yang terlewat.
2. **Siapa mengoperasikan akun 29, 1, dan 21** pada malam itu (sama seperti permintaan untuk
   kasus #42).
3. **Kasus berikutnya harus diamati.** Sebelum maker menekan *Ajukan*, kasir menghubungi IT;
   IT menjalankan probe (`LIVE_NOW=yes`) dan baseline segar; checker mengambil persetujuan
   langsung dari Owner; baru kemudian pengajuan (rule 202, SOP §8).
4. **Keputusan kebijakan (opsional).** Jika Owner ingin menerima bukti rekonstruksi sebagai
   pengganti observasi langsung, itu adalah keputusan Owner baru yang memerlukan rule-nya
   sendiri. Tugas verifikasi tidak boleh menurunkan standar (rule 196 §48).

## 17. Tracker program (7 sprint)

| Sprint | Status |
|---|---|
| 1 — fondasi koreksi pra-bayar | GO |
| 2 — closure pilot pra-bayar SPN4 | **NO_GO (`OPERATIONAL_BLOCKED`)** — dua kasus nyata tanpa observasi |
| 3 — koreksi pasca-bayar | IMPLEMENTATION_GO, flag OFF |
| 4 — permintaan & persetujuan refund | IMPLEMENTATION_GO, flag OFF |
| 5 — settlement refund | IMPLEMENTATION_GO, flag OFF |
| 6 — | ditangguhkan sampai Sprint 2 selesai (arahan Owner) |
| 7 — | belum dimulai |

Progres penutupan operasional program: **1/7**.

## 18. Status mesin

```text
TASK=VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-CLOSURE-FINAL-1
SPRINT=2/7
PILOT_BRANCH=SPN4
MAKER_USER_ID=29
CHECKER_USER_ID=1
CASE42_FINANCIAL=PASS_RECONSTRUCTED
CASE42_HISTORICAL_APPROVAL=UNVERIFIED
CASE42_HUMAN_SEPARATION=RED_FLAG_UNRESOLVED
CASE2_REFERENCE=RMECOR-202610-000002
CASE2_FINANCIAL=PASS_RECONSTRUCTED
CASE2_OWNER_APPROVAL=UNVERIFIED
CASE2_HUMAN_SEPARATION=RED_FLAG_UNRESOLVED
CASE2_OBSERVATION=NOT_PERFORMED
CURRENT_PRODUCTION_SHA=a550e00d7eef303d6f76a290d493c6ab48a35aa1
PILOT_READY=PASS
PILOT_ACTIVE=PASS
PILOT_LIVE=NOT_REACHED
PILOT_LIVE_STATE_AT_RUN=PASS_14:49:11Z_AFTER_CASE
AUTHENTICATED_VERIFIED=NOT_REACHED
REAL_ELIGIBLE_CASE=NONE
OWNER_CASE_APPROVAL_PRE_SUBMISSION=NOT_REACHED
NEW_CASE_HUMAN_SEPARATION=NOT_REACHED
FINANCIAL_CORRECTION_VERIFIED=NOT_REACHED
FINANCIAL_RECONCILIATION=PASS_RECONSTRUCTED
DEPLOY_REQUIRED=NO
DEPLOY_LOCATION=NOT_APPLICABLE
DEPLOY_STATUS=NOT_REQUIRED
FULL_SUITE=SKIPPED_NOT_PASS
SPRINT2_GO_TAG=NONE
SPRINT2_FULL_PILOT_GO=NO_GO
SPRINT3_FLAG=OFF
SPRINT4_FLAG=OFF
SPRINT5_FLAG=OFF
MAIN_PROGRAM_PROGRESS=1/7
FINAL_DECISION=OPERATIONAL_BLOCKED
RULE196_CLASSIFICATION=OPERATIONAL_OR_EVIDENCE_BLOCKED
```

## 19. Addendum resmi — klarifikasi Owner atas tagihan #43 (2026-10-31, `UPDATE-SPRINT2-CASE43-OWNER-CLARIFICATION-1`)

Bagian 1–18 di atas adalah keadaan yang diukur sebelum klarifikasi ini dan **tidak diubah**.
Addendum ini mencatat pernyataan Owner, memisahkannya dari bukti, dan memverifikasi bagian
yang dapat diverifikasi secara read-only. Tidak ada invoice, pembayaran, audit, timestamp,
atau dokumen RME pasien yang disentuh.

### 19.1 Pernyataan Owner (dicatat apa adanya)

1. Kunjungan pasien adalah nyata; pasien benar-benar hadir di klinik.
2. Kesalahan harga terjadi karena penggunaan rate card yang berbeda.
3. Persetujuan Owner telah diberikan sebelum 31 Oktober 2026 pukul 22.38.52 WITA.
4. Identitas akun: user 29 = Admin Klinik; user 1 = IT; user 21 = drg. Aisyah.

Sifat pernyataan ini: **`OWNER_RETROSPECTIVE_ATTESTATION`** — pernyataan Owner yang dibuat
SETELAH pengajuan (14:38:52 UTC) tentang hal-hal sebelum pengajuan. Ini **bukan** bukti
persetujuan tertulis yang berasal dari sebelum pengajuan (rule 196 §32–§33, §49).

### 19.2 Verifikasi read-only

| Pernyataan | Yang dapat diverifikasi sistem | Status |
|---|---|---|
| Kunjungan nyata | rantai klinis lengkap di server (kunjungan, consent bertanda tangan dokter, odontogram, tulisan tangan, rekam medis final); kehadiran fisik tidak dapat dibuktikan basis data | `OWNER_CONFIRMED_REAL` |
| Selisih rate card | SPN4 memiliki **0** baris `mst_tariffs` aktif; tidak ada dokumen rate card di sistem. Tidak ada tarif yang ditetapkan atau dikarang oleh tugas ini. Koreksi ini menaikkan harga, bukan diskon, sehingga kebijakan diskon D21c tidak tersentuh | `OWNER_EXPLAINED_RATE_CARD_DIFFERENCE` — harga yang benar belum didukung dokumen |
| Persetujuan sebelum 22:38:52 WITA | `owner_approval_reference` NULL, `decision_note` kosong, alasan tanpa referensi, registry referensi Owner 0 klaim, tidak ada catatan di repositori. Bukti asli di kanal resmi klinik **tidak tersedia** bagi tugas ini | `OWNER_ATTESTED_EVIDENCE_PENDING_VERIFICATION` |
| User 29 = Admin Klinik | akun "Admin Sunu", aktif, peran sistem **Front Office** (gabungan tugas Admin Klinik + Kasir, D1b), identitas pilot maker; dapat mengajukan dan menyetujui menurut izin, tetapi hanya mengajukan menurut D1e | `OWNER_IDENTIFIED` — sesuai |
| User 1 = IT | akun "IT Support", aktif, peran sistem **Super Admin + Owner**, identitas pilot checker | `OWNER_IDENTIFIED` — lihat catatan peran §19.4 |
| User 21 = drg. Aisyah | akun "drg Aisyah", aktif, peran **Doctor**, terhubung ke master dokter #24 yang merupakan dokter kunjungan #64; tanpa izin koreksi | `OWNER_IDENTIFIED` — sesuai |

### 19.3 Kronologi yang harus dipenuhi bukti asli

Tagihan #43 baru ada sejak **22:38:27 WITA** dan koreksi diajukan **22:38:52 WITA**.
Persetujuan kasus menurut formulir rule 202 memuat referensi tagihan, nominal asal, nominal
usulan, rincian perubahan, dan alasan. Karena itu bukti asli harus menunjukkan, dari kanal
resmi dan dengan timestamp kanal itu sendiri, salah satu dari:

- persetujuan yang menyebut tagihan #43 / `RME-202610-000009` dan diberikan dalam jendela
  25 detik 22:38:27–22:38:52 WITA; atau
- persetujuan yang diberikan lebih awal dan mengidentifikasi kasus yang sama dengan cara lain
  (kunjungan, pasien, tindakan), dengan nominal Rp9.000.000 → Rp12.000.000 yang sama.

Ini adalah syarat pembuktian, bukan keraguan atas pernyataan Owner. Bukti diserahkan sebagai
teks dengan timestamp kanal (bukan tangkapan layar mentah, rule 202), tanpa identitas pasien,
dan checker (akun 1) menyatakan bahwa ia menerimanya langsung dari Owner sebelum menyetujui
pukul 22:41:11 WITA. Tugas ini tidak membuat referensi, tanggal, tanda tangan, atau dokumen apa
pun, dan tidak menulis apa pun ke kolom alasan, catatan, atau referensi koreksi.

### 19.4 Pemisahan manusia dan peran

- **Akun berbeda (29 ≠ 1): terverifikasi sistem.** Pemisahan manusia untuk kasus #43 kini
  **`OWNER_ATTESTED_DISTINCT`** (Admin Klinik dan IT adalah dua orang menurut Owner). Sistem
  tetap tidak dapat memverifikasinya; alamat jaringan yang sama untuk akun 29, 1, dan 21
  **bukan** bukti bahwa satu orang memakai semua akun — konsisten dengan jaringan klinik yang
  sama — dan tidak dibaca sebagai bukti ke arah mana pun.
- Tanda bahaya kasus #42 (`RED_FLAG_UNRESOLVED`) **tidak diubah**: temuan itu spesifik pada
  sidik peramban kasus tersebut, dan klarifikasi ini diberikan untuk kasus #43.
- **Catatan peran (untuk Owner, bukan tindakan tugas ini):** akun IT (user 1) memegang peran
  sistem `Owner`. Karena itu, persetujuan checker oleh akun 1 tidak boleh dibaca sebagai
  persetujuan Owner — klarifikasi ini menegaskan bahwa checker adalah IT, sehingga persetujuan
  Owner harus datang dari Owner sendiri sebagai bukti terpisah (rule 196 §32). Apakah akun IT
  tetap perlu memegang peran `Owner` adalah keputusan least-privilege Owner. Tidak ada peran
  yang diubah.
- Akun 1 sebagai checker adalah penunjukan Owner D1d/D1e dan tetap berlaku.

### 19.5 Yang tidak berubah

- Rekonsiliasi keuangan tetap **`PASS_RECONSTRUCTED`**.
- Kekurangan observasi langsung tetap: baseline segar, `PILOT_LIVE` untuk kasus,
  `AUTHENTICATED_VERIFIED`, dan D10 menurut state tidak terjadi dan tidak dapat dibuat
  belakangan (rule 196 §31, §41). Pernyataan Owner tidak menggantikan observasi.
- Sprint 2: **NO_GO**, tanpa GO tag.

### 19.6 Tindak lanjut Owner yang tersisa

1. Serahkan bukti asli persetujuan dari kanal resmi (syarat §19.3). Jika terverifikasi,
   status persetujuan kasus #43 menjadi `PRE_SUBMISSION_APPROVAL_VERIFIED` — kasus tetap tidak
   menutup pilot karena observasi langsungnya tidak ada.
2. Jika tersedia, dokumen rate card SPN4 yang menjadi dasar harga Rp12.000.000.
3. Tinjau apakah akun IT perlu memegang peran `Owner`.
4. Kasus berikutnya: IT menjalankan probe dan baseline segar SEBELUM maker mengajukan.

### 19.7 Status addendum

```text
TASK=UPDATE-SPRINT2-CASE43-OWNER-CLARIFICATION-1
CASE43_OWNER_CLARIFICATION=RECORDED
CASE43_CLARIFICATION_NATURE=OWNER_RETROSPECTIVE_ATTESTATION
CASE43_VISIT=OWNER_CONFIRMED_REAL
CASE43_PRICING_ERROR=OWNER_EXPLAINED_RATE_CARD_DIFFERENCE
CASE43_RATE_CARD_DOCUMENT=NOT_PROVIDED
CASE43_PRE_SUBMISSION_APPROVAL=OWNER_ATTESTED_EVIDENCE_PENDING_VERIFICATION
CASE43_ORIGINAL_APPROVAL_EVIDENCE=NOT_AVAILABLE_TO_TASK
CASE43_ACCOUNT_IDENTITIES=OWNER_IDENTIFIED
CASE43_ACCOUNT_SEPARATION=SYSTEM_VERIFIED
CASE43_HUMAN_SEPARATION=OWNER_ATTESTED_DISTINCT_NOT_SYSTEM_VERIFIABLE
CASE43_FINANCIAL_RECONCILIATION=PASS_RECONSTRUCTED
CASE43_LIVE_OBSERVATION=NOT_PERFORMED
CASE42_HUMAN_SEPARATION=RED_FLAG_UNRESOLVED
SPRINT2_FULL_PILOT_GO=NO_GO
SPRINT2_PENDING=LIVE_OBSERVATION_AND_REQUIRED_EVIDENCE
SPRINT2_GO_TAG=NONE
```
