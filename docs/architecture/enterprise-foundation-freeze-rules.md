# DaengtisiaMS — Enterprise Foundation Freeze Rules

> **Registrasi kanonik (ENT-0, 2026-07-05):** Dokumen ini adalah sumber governance untuk fase
> Enterprise Foundation Lock. Sprint ENT-0 — *Enterprise Foundation Freeze Rules & Roadmap
> Reconciliation* — mendaftarkan dokumen ini dan merekonsiliasi sprint ENT-1 s/d ENT-16 ke dalam
> roadmap kanonik `config/foundation_roadmap.php` (divalidasi oleh
> `php artisan foundation:roadmap-check`). Pekerjaan fondasi yang sudah GO-tagged sebelumnya
> (NSF-9/NSF-10, CACHE-1, QUEUE-1, DBPERF-1/DBPERF-2, RPT-1, STORAGE-1, STATELESS-1, LB-1,
> REPLICA-1, CACHE-1-REDIS-READINESS, OBS-1, OBS-2, ROADMAP-1-CANONICALIZATION) dipetakan sebagai
> dependensi/fondasi terkait pada entri ENT — TIDAK ditandai sebagai penyelesaian penuh sprint ENT
> mana pun. Lihat bagian **Pemetaan ENT ↔ Fondasi Terkirim** di akhir dokumen ini.

## Status

DaengtisiaMS sedang masuk fase **Enterprise Foundation Lock**.
Sampai semua fondasi enterprise selesai dan mendapat GO tag final, seluruh perubahan aplikasi wajib mengikuti aturan ini.

Tujuan utama fase ini adalah mencegah kerja dua kali, mencegah perubahan arsitektur liar, dan memastikan semua modul dibangun di atas fondasi yang konsisten, scalable, aman, auditable, dan siap ekspansi multi-cabang.

---

## 1. Prinsip Utama

Selama fase Enterprise Foundation Lock:

1. Jangan mengejar fitur besar baru sebelum fondasi inti selesai.
2. Jangan mengubah pola arsitektur yang sudah disepakati.
3. Jangan memperbaiki masalah dengan shortcut yang merusak governance.
4. Jangan membuat duplikasi service, repository, route, permission, atau tabel tanpa audit.
5. Jangan membuat perubahan lintas modul kecuali sprint memang menyebutkan scope tersebut.
6. Semua perubahan wajib kecil, terukur, bisa dites, dan punya rollback path.
7. Sumber kebenaran adalah kode, migration, route, policy, test, dan evidence terbaru.
8. Dokumen lama yang konflik harus dikalahkan oleh kode dan test terbaru.

---

## 2. Architecture Freeze

> **ENT-1 — Enterprise Architecture Baseline Lock (2026-07-05):** Bagian ini dikunci sebagai
> baseline arsitektur enterprise yang durable dan dapat diaudit melalui
> `docs/architecture/enterprise-architecture-baseline-lock.md` (rule ENT1-R001..ENT1-R014),
> terdaftar di `config/foundation_roadmap.php` (`rules.enterprise_architecture_baseline_doc`)
> dan divalidasi oleh `Ent1EnterpriseArchitectureBaselineLockTest`.

Arsitektur wajib DaengtisiaMS tetap:

```text
HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model
         ↓ authorize via Policy
```

Aturan wajib:

1. Controller hanya boleh menangani request, authorization, dan delegasi.
2. Business logic wajib berada di Service.
3. Query data wajib melalui Repository dan RepositoryInterface.
4. Write multi-table wajib menggunakan `DB::transaction`.
5. Branch-owned repository wajib menerima `int $branchId` sebagai parameter awal.
6. Authorization wajib melalui Policy/Gate, bukan hanya sidebar `@can`.
7. Tidak boleh bypass RepositoryInterface.
8. Tidak boleh menaruh business logic di Blade.
9. Tidak boleh introduce React/Vue.
10. Tidak boleh membuat pola arsitektur baru sebelum Enterprise Foundation Closure GO.

---

## 3. Foundation Freeze Scope

Sampai Enterprise Foundation selesai, prioritas sprint hanya boleh berada dalam area berikut:

1. Database performance.
2. Reporting summary/materialized summary.
3. Redis cache governance.
4. Queue, retry, failed job, idempotency, dan outbox.
5. Observability, health check, log viewer, dan Developer Assistance Console.
6. Security, PII masking, audit, dan branch isolation hardening.
7. CI/CD gate, automated smoke, deploy gate, rollback automation.
8. Backup, restore, disaster recovery, dan evidence automation.
9. Load test untuk 5 cabang, 20 cabang, dan proyeksi nasional.
10. Enterprise documentation, runbook, SOP incident, SOP deploy, dan GO/NO-GO closure.

Fitur bisnis baru boleh dibuat hanya jika:

1. Tidak mengubah kontrak arsitektur.
2. Tidak menambah technical debt foundation.
3. Tidak melewati security/branch/audit rules.
4. Tidak mengganggu sprint foundation aktif.
5. Disetujui eksplisit sebagai exception.

---

## 4. Enterprise Foundation Sprint Roadmap Lock

Roadmap fondasi enterprise dikunci menjadi 16 sprint:

1. ENT-1 — Enterprise Architecture Baseline Lock.
2. ENT-2 — Database Performance Contract.
3. ENT-3 — Reporting Materialized Summary Expansion.
4. ENT-4 — Redis Cache Enterprise Policy.
5. ENT-5 — Queue, Retry & Failed Job Governance.
6. ENT-6 — Idempotency & Outbox Foundation.
7. ENT-7 — Developer Assistance Console.
8. ENT-8 — Observability & Health Check Pack.
9. ENT-9 — Security & PII Compliance Hardening.
10. ENT-10 — CI/CD Enterprise Gate.
11. ENT-11 — Deployment & Rollback Automation.
12. ENT-12 — Backup & Disaster Recovery Automation.
13. ENT-13 — Load Test 5 Cabang Baseline.
14. ENT-14 — Load Test Scale Projection.
15. ENT-15 — Enterprise Documentation & Runbook.
16. ENT-16 — Enterprise Foundation Closure GO/NO-GO.

Urutan boleh disesuaikan hanya jika ada alasan teknis kuat, tetapi scope enterprise foundation tidak boleh dihapus.

---

## 5. Database Enterprise Rules

> **ENT-2 — Database Performance Contract (2026-07-06):** Bagian ini dikunci sebagai
> kontrak performa database enterprise yang durable dan dapat diaudit melalui
> `docs/architecture/database-performance-contract.md` (rule DBPERF-R001..DBPERF-R014),
> dengan baseline hotspot di `docs/architecture/database-performance-hotspot-inventory.md`,
> terdaftar di `config/foundation_roadmap.php` (`rules.database_performance_contract_doc`)
> dan divalidasi oleh `Ent2DatabasePerformanceContractTest`.

1. Semua query berat wajib diaudit dengan index dan EXPLAIN plan.
2. Dashboard dan laporan berat tidak boleh terus-menerus menghitung dari transaksi mentah jika volume data sudah besar.
3. Laporan berat wajib diarahkan ke `rpt_*`, summary table, cached aggregate, atau materialized summary — detail strateginya dikunci ENT-3 di `docs/architecture/reporting-materialized-summary-contract.md` (RPTSUM-R001..R016).
4. Migration production wajib additive.
5. Tidak boleh `migrate:fresh`, `db:wipe`, atau destructive migration di VPS.
6. Kolom baru harus nullable/default-safe jika menyentuh tabel production.
7. Index wajib diberi nama jelas dan sesuai query pattern.
8. Query cross-branch harus read-only dan permission-gated.
9. PII seperti KTP/NIK tidak boleh muncul di index/debug/report/export tanpa masking.
10. Perubahan schema wajib disertai test minimal untuk happy path dan regression terkait.

---

## 6. Cache Enterprise Rules

> **ENT-4 — Redis Cache Enterprise Policy (2026-07-06):** Bagian ini dikunci sebagai
> policy cache enterprise yang durable dan dapat diaudit melalui
> `docs/architecture/redis-cache-enterprise-policy.md` (rule CACHE-R001..CACHE-R018),
> TTL matrix `docs/architecture/cache-ttl-matrix.md`, invalidation matrix
> `docs/architecture/cache-invalidation-matrix.md`, dan Redis readiness runbook
> `docs/architecture/redis-readiness-runbook.md`. ENT-4 tidak mengubah runtime
> cache/session/queue driver.

1. Redis adalah shared cache utama untuk data yang aman dicache.
2. Cache key wajib menggunakan struktur kanonik `dms:{env}:{domain}:{scope}:{identifier}:{version}`.
3. Cache tidak boleh menyimpan data PII mentah.
4. TTL wajib eksplisit.
5. Invalidation wajib jelas.
6. Data transaksi penting tidak boleh bergantung pada cache sebagai source of truth.
7. Cache hanya akselerator, bukan pengganti database.
8. Dashboard/report boleh cache, tetapi harus punya stale policy — akselerasi cache di atas summary mengikuti kontrak ENT-3 (`docs/architecture/reporting-materialized-summary-contract.md`, RPTSUM-R014) dan TTL/invalidation matrix ENT-4.
9. Cache/readiness status wajib masuk observability/health check ENT-7/ENT-8.
10. Cache governance command wajib bisa membaca status konfigurasi cache.
11. Perubahan cache wajib punya test atau smoke evidence untuk key format, TTL, invalidation, branch scope, dan PII safety.

---

## 7. Queue, Idempotency & Outbox Rules

1. Proses berat wajib dipindahkan ke queue jika berpotensi memperlambat request.
2. Export report, audit scan, notification candidate, dan report generation wajib queue-ready.
3. Job wajib idempotent.
4. Job wajib punya retry policy.
5. Failed job wajib bisa dilihat dan ditindaklanjuti.
6. Event penting lintas modul wajib menggunakan outbox pattern atau mekanisme equivalent sebelum integrasi eksternal.
7. Payment tidak boleh rollback hanya karena job sekunder gagal.
8. RME payment → LabCaseCandidate tetap idempotent dan tidak boleh auto-create LabOrder.
9. Future WA/API integration wajib melewati queue/outbox, bukan direct send dari request.
10. Queue health wajib masuk observability/health check.

> Durable lock (ENT-5): aturan retry/backoff/timeout, failed job storage,
> kebijakan koneksi per environment, dan konvensi `ShouldQueue` dikunci di
> `docs/architecture/queue-retry-failed-job-governance.md` (ENT5-Q001..Q008)
> dengan runbook operasional `docs/architecture/queue-worker-operations-runbook.md`
> dan gate `foundation:queue-retry-failed-job-check`.

> Durable lock (ENT-6): aturan idempotency/outbox enterprise dikunci di
> `docs/architecture/idempotency-outbox-foundation-governance.md`
> (ENT6-IO001..ENT6-IO008) dan gate
> `foundation:idempotency-outbox-check`. External dispatch tetap nonaktif,
> WhatsApp tidak boleh auto-send dari request path, dan RME payment tidak boleh
> auto-create LabOrder.

---

## 8. Observability & Developer Assistance Rules

DaengtisiaMS wajib memiliki fondasi Developer Assistance untuk internal admin/developer.

Minimal harus bisa membaca:

1. Application log.
2. Error log.
3. Failed job.
4. User activity.
5. Audit event.
6. Slow query summary.
7. Recent deploy evidence.
8. Storage permission status.
9. DB/Redis/queue health.
10. Disk usage dan backup status.

Redis/cache health mengikuti runbook ENT-4 dan tidak boleh menampilkan secret, session payload,
atau isi file environment.

Aturan keamanan:

1. Developer Assistance hanya boleh untuk Super Admin atau permission khusus.
2. Log harus masking PII.
3. KTP/NIK full tidak boleh tampil.
4. Password, token, session, cookie, API key, dan isi file environment tidak boleh ditampilkan.
5. Semua akses ke Developer Assistance harus diaudit.

> Durable lock (ENT-7): aturan Developer Assistance Console enterprise dikunci di
> `docs/architecture/developer-assistance-console-governance.md`
> (ENT7-DC001..ENT7-DC010) dan diverifikasi oleh
> `foundation:developer-console-check`. Console tetap read-only, digating
> permission `view_developer_console`, semua akses diaudit ke `sys_audit_logs`,
> dan seluruh excerpt melewati masking PII/secret.

> Durable lock (ENT-8): aturan Observability & Health Check Pack dikunci di
> `docs/architecture/observability-health-check-pack-governance.md`
> (ENT8-HC001..ENT8-HC010) dan diverifikasi oleh `foundation:health-check`.
> Endpoint `GET /health/live` dan `GET /health/ready` tetap read-only, GET-only,
> minimal, dan non-sensitif (status + state komponen saja). Alerting hanya
> readiness — tanpa vendor paging eksternal sebelum review privasi. LB-1
> `/health/lb` tetap utuh. ENT-5/ENT-6/ENT-7 tetap wajib GO.

> Durable lock (ENT-9): aturan Security & PII Compliance Hardening dikunci di
> `docs/architecture/security-pii-compliance-hardening-governance.md`
> (ENT9-SEC001..ENT9-SEC012) dan diverifikasi oleh
> `foundation:security-compliance-check`. Full KTP/NIK server-side only — UI,
> report, export, dan log hanya nilai tersamar; sidebar bukan boundary keamanan.
> Masking helper wajib ada, setiap route export wajib digating auth/permission,
> `sys_audit_logs` tetap immutable, dan scope cabang selalu lewat
> `BranchContext::requireId()` (branch_id dari request tidak pernah dipercaya).
> ENT-5/ENT-6/ENT-7/ENT-8 tetap wajib GO.

> Durable lock (ENT-10): aturan CI/CD Enterprise Gate dikunci di
> `docs/architecture/cicd-enterprise-gate-governance.md`
> (ENT10-CICD001..ENT10-CICD012) dan diverifikasi oleh
> `foundation:cicd-enterprise-gate-check`. CI gate berjalan di setiap pull
> request ke base branch; gate gagal memblok merge dan tidak boleh dilewati
> diam-diam. Deploy/CI wajib menjalankan stack ENT-5..9, `migrate --force` saja
> (tanpa perintah destruktif), backup DB sebelum migrate, dan mempertahankan
> urutan cache ENT-8 (route/config di-clear sebelum gate route-dependent).
> Artefak evidence `cicd-enterprise-gate-check.json` wajib di profil ci/vps dan
> tetap non-sensitif. ENT-5/ENT-6/ENT-7/ENT-8/ENT-9 tetap wajib GO.

> Durable lock (ENT-11): aturan Deployment & Rollback Automation dikunci di
> `docs/architecture/deployment-rollback-automation-governance.md`
> (ENT11-DR001..ENT11-DR012) dan diverifikasi oleh
> `foundation:deployment-rollback-check`. Deploy VPS otomatis lewat
> `scripts/deploy-vps.sh` (backup terverifikasi sebelum migrate, `migrate --force`
> saja tanpa perintah destruktif, urutan cache ENT-8 dipertahankan, reset
> permission, dan smoke). Setiap deploy wajib punya rollback plan teruji:
> `scripts/rollback-vps.sh <tag/commit>` mencatat ref saat ini, backup DB sebelum
> checkout, kembali ke GO tag/commit lama, rebuild, re-verify gate ENT-5..11,
> restart, dan smoke — tanpa perintah database destruktif (restore data hanya via
> `scripts/restore_postgres.sh` secara eksplisit). Artefak evidence
> `deployment-rollback-check.json` wajib di profil ci/vps dan tetap non-sensitif.
> ENT-5/ENT-6/ENT-7/ENT-8/ENT-9/ENT-10 tetap wajib GO.

> Durable lock (ENT-12): aturan Backup & Disaster Recovery Automation dikunci di
> `docs/architecture/backup-disaster-recovery-automation-governance.md`
> (ENT12-BDR001..ENT12-BDR012) dan diverifikasi oleh `foundation:backup-dr-check`.
> Backup DB otomatis lewat `scripts/backup-vps.sh` (fail-fast `pg_dump` →
> `foundation:backup-verify` → prune retensi `BACKUP_DR_RETENTION_DAYS` dengan
> floor minimum, tanpa perintah destruktif). Restore rehearsal lewat
> `scripts/restore-rehearsal.sh` WAJIB ke database scratch non-produksi
> (`REHEARSAL_DB` harus berbeda dari database produksi; abort bila sama), verify
> jumlah tabel, drop hanya scratch DB, dan mencatat evidence non-sensitif
> (path/size, RTO/RPO). Restore data produksi hanya via `scripts/restore_postgres.sh`
> secara eksplisit — tidak pernah otomatis. Target RTO/RPO + retensi
> didokumentasikan di `config/backup_dr.php`. Artefak evidence
> `backup-dr-check.json` wajib di profil ci/vps dan tetap non-sensitif.
> ENT-5/ENT-6/ENT-7/ENT-8/ENT-9/ENT-10/ENT-11 tetap wajib GO.

> Durable lock (ENT-13): aturan Load Test 5 Cabang Baseline dikunci di
> `docs/architecture/load-test-5-cabang-baseline-governance.md`
> (ENT13-LT001..ENT13-LT012) dan diverifikasi oleh `foundation:load-test-baseline-check`.
> Baseline 5 cabang (25 klinik + 2 lab + 2 inventory user, 60k–70k pasien) hanya
> berjalan di lingkungan non-produksi lewat `scripts/load-test-baseline.sh` →
> `loadtest:baseline-run` (guarded, read-only); dataset dari harness `stress:seed-*`.
> Target latensi 200–300ms (p50 200ms / p95 300ms), bottleneck diklasifikasikan ke
> db/cache/queue/php/network/frontend/storage. Load test ke database VPS pilot
> produksi di luar scope. Artefak evidence `load-test-baseline-check.json` wajib di
> profil ci/vps dan tetap non-sensitif.
> ENT-5/ENT-6/ENT-7/ENT-8/ENT-9/ENT-10/ENT-11/ENT-12 tetap wajib GO.

> Durable lock (ENT-14): aturan Load Test Scale Projection dikunci di
> `docs/architecture/load-test-scale-projection-governance.md`
> (ENT14-SP001..ENT14-SP012) dan diverifikasi oleh
> `foundation:load-test-scale-projection-check`. Proyeksi bersifat analysis-only:
> mengekstrapolasi baseline terukur ENT-13 (5 cabang) ke tier 20 cabang dan
> nasional lewat `scripts/load-test-scale-projection.sh` →
> `loadtest:scale-projection-run` (guarded, non-produksi, read-only). Semua angka
> berlabel modeled/estimated — bukan benchmark produksi. Tier tinggi menautkan ke
> foundation LB-1 (horizontal scale) dan REPLICA-1 (read routing); proyeksi tidak
> pernah mengaktifkan replica read routing / multi-node traffic atau mengubah
> topologi produksi. Artefak evidence `load-test-scale-projection-check.json` wajib
> di profil ci/vps dan tetap non-sensitif.
> ENT-5/ENT-6/ENT-7/ENT-8/ENT-9/ENT-10/ENT-11/ENT-12/ENT-13 tetap wajib GO.

> Durable lock (ENT-15): aturan Enterprise Documentation & Runbook dikunci di
> `docs/architecture/enterprise-documentation-runbook-governance.md`
> (ENT15-DOC001..ENT15-DOC012) dan diverifikasi oleh
> `foundation:enterprise-documentation-check`. Set runbook wajib di
> `docs/runbooks/` dideklarasikan di `config/enterprise_documentation.php` dan
> ditegakkan (bukan docs-only): setiap runbook harus actionable (purpose, safe
> commands, forbidden commands, evidence, rollback, smoke, security, owner,
> review cadence). Perintah destruktif (`migrate:fresh`, `db:wipe`, `schema:drop`,
> `migrate:reset`) hanya boleh muncul di seksi forbidden/warning; literalnya
> disimpan di config, bukan di kode scanner. Dokumen tidak pernah memuat rahasia,
> kredensial, file environment, atau KTP/NIK tanpa masking. Set runbook menautkan
> setiap perintah readiness ENT-5..15; `docs:enterprise-runbook-summary`
> menampilkan registry read-only. Artefak evidence
> `enterprise-documentation-check.json` wajib di profil ci/vps dan tetap
> non-sensitif.
> ENT-5/ENT-6/ENT-7/ENT-8/ENT-9/ENT-10/ENT-11/ENT-12/ENT-13/ENT-14 tetap wajib GO.

---

## 9. Security & PII Rules

1. KTP/NIK wajib dimasking di UI, audit, export, log, dan report.
2. Full KTP/NIK hanya boleh diproses server-side untuk validasi identitas.
3. Cross-branch lookup pasien hanya boleh berdasarkan RM number sesuai rule existing.
4. Tidak boleh search pasien cross-branch by nama atau KTP.
5. Scan document pasien wajib access-controlled.
6. Export wajib permission-gated.
7. Owner dashboard tidak boleh expose raw notes, KTP, scan document, atau data klinis sensitif.
8. Branch isolation wajib diuji untuk semua modul branch-owned.
9. Sidebar bukan security boundary.
10. Semua route sensitif wajib punya middleware, policy, dan ownership check.

---

## 10. Branch Isolation Rules

1. Jangan percaya `branch_id` dari request.
2. Write branch-owned wajib menggunakan `BranchContext::requireId()`.
3. Repository branch-owned wajib branch-scoped.
4. MAIN tidak boleh dipakai sebagai ruangan klinik.
5. Cross-branch hanya boleh untuk lookup RM terbatas, owner analytics, atau permission khusus.
6. Semua query lintas cabang wajib read-only kecuali ada sprint eksplisit.
7. User branch resolver harus defensive karena `users.branch_id` tidak ada di schema saat ini.
8. Branch isolation test wajib ada jika perubahan menyentuh data cabang.

---

## 11. Inventory Enterprise Rules

1. Inventory tetap ledger-only.
2. Source of truth stok adalah `trx_inventory_movements`.
3. Tidak boleh membuat kolom mutable seperti `current_stock`, `qty_on_hand`, atau update stok langsung di produk.
4. Stok dihitung dari SUM quantity in/out per branch, location, product, dan batch jika applicable.
5. Transfer wajib melalui status transfer resmi, bukan manual adjustment pair.
6. Procurement wajib PR → PO → GR → movement PURCHASE.
7. Stock opname wajib count → review → finalize → adjustment movement.
8. Outbound wajib reject jika stok tidak cukup.
9. Batch tracking wajib jika product requires batch tracking.
10. Semua perubahan inventory wajib punya ledger correctness test.

---

## 12. RME & Patient Rules

1. Format RM tetap `DG-{KODE_CABANG}-{TAHUN}-{NOMOR_MANUAL}`.
2. RM unique global termasuk soft-deleted.
3. Handwriting PNG wajib sebelum finalize RM.
4. SOAP tetap hidden di UI dokter sampai ada spec eksplisit.
5. RM dan odontogram tetap editable post-finalize sesuai keputusan sprint terbaru.
6. Doctor hanya boleh mengubah visit `in_progress` ke `cashier_pending`.
7. Visit `completed` hanya boleh via kasir/payment service.
8. Gate ruangan wajib sebelum RM/Odontogram untuk pre-exam.
9. Triage/initial treatment tidak boleh auto-billing.
10. Patient-centric RM workspace tetap: 1 pasien = buku RM, 1 sheet = 1 visit.

---

## 13. Cashier, Receivable & Lab Integration Rules

1. Billing gate: visit `cashier_pending` dan RM final.
2. Consent wajib sebelum payment.
3. Partial payment tetap menyelesaikan visit dan sisanya menjadi piutang aktif.
4. Piutang dihitung dari invoice status/remaining, bukan visit status.
5. Carry-over piutang tetap opt-in.
6. Payment RME tidak boleh membuat `trx_payments` lab.
7. RME invoice PAID boleh trigger LabCaseCandidate secara idempotent.
8. LabOrder tetap harus dibuat manual oleh Admin Lab.
9. Failure LabCaseCandidate tidak boleh rollback payment.
10. Billing RME dan billing Lab tetap terpisah.

---

## 14. Reporting & Owner Dashboard Rules

> **ENT-3 — Reporting Materialized Summary Expansion (2026-07-06):** Strategi
> summary/materialized summary untuk semua dashboard/report berat dikunci sebagai
> kontrak durable di `docs/architecture/reporting-materialized-summary-contract.md`
> (rule RPTSUM-R001..RPTSUM-R016), dengan baseline kandidat di
> `docs/architecture/reporting-summary-candidate-inventory.md`, terdaftar di
> `config/foundation_roadmap.php` (`rules.reporting_materialized_summary_contract_doc`)
> dan divalidasi oleh `Ent3ReportingMaterializedSummaryExpansionTest`.

1. Dashboard owner wajib read-only.
2. Report/export wajib mask PII.
3. KPI piutang wajib berdasarkan invoice remaining.
4. Supervisor RME tidak otomatis mendapat owner dashboard.
5. Cross-branch analytics wajib permission khusus.
6. Laporan berat wajib memakai summary/materialized summary/cache strategy.
7. Export wajib auditable.
8. Report query wajib diuji performanya jika menyentuh data besar.
9. Jangan expose raw clinical notes/scans di dashboard.
10. Perubahan report wajib punya smoke export/print jika applicable.

---

## 15. CI/CD & Deploy Rules

1. Tidak boleh merge tanpa relevant test.
2. Tidak boleh deploy tanpa backup DB.
3. Deploy VPS wajib menggunakan approved branch/tag.
4. `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, cache rebuild, permission reset, smoke check wajib dijalankan sesuai kebutuhan perubahan.
5. Tidak boleh `migrate:fresh` atau `db:wipe` di VPS.
6. Deploy harus punya rollback plan.
7. GO tag hanya boleh dibuat setelah test dan smoke evidence cukup.
8. CI/CD enterprise gate wajib mengecek test kritikal, migration safety, route sanity, config sanity, dan smoke command.
9. Failed CI tidak boleh diabaikan.
10. Evidence deploy wajib didokumentasikan.

---

## 16. Backup & Disaster Recovery Rules

1. Backup DB wajib sebelum deploy.
2. Backup gagal berarti deploy stop.
3. Backup harus punya retention policy.
4. Restore rehearsal wajib dijalankan berkala.
5. Restore evidence wajib terdokumentasi.
6. Backup file upload/storage perlu strategi terpisah dari DB.
7. Disaster recovery harus punya RTO/RPO target.
8. Restore ke non-production wajib bisa dilakukan tanpa merusak VPS pilot.
9. Backup path dan ukuran file wajib dicatat pada deploy evidence.
10. DR readiness menjadi syarat Enterprise Foundation Closure GO.

---

## 17. Load Test & Performance Rules

1. Target baseline 5 cabang: 25 user klinik, 2 user lab, 2 user inventory.
2. Dataset baseline: 60.000–70.000 pasien.
3. Halaman utama RME, kasir, owner dashboard, inventory dashboard, reports, dan lookup RM wajib masuk load test.
4. Target average load time enterprise: 200–300ms untuk halaman utama yang sudah dioptimasi.
5. Halaman report berat boleh async/export jika tidak realistis real-time.
6. Slow query wajib dicatat dan ditindaklanjuti.
7. Tidak boleh klaim scalable tanpa evidence load/stress test.
8. Load test result wajib masuk evidence pack.
9. Bottleneck harus dikategorikan: DB, cache, queue, PHP, network, frontend, storage.
10. Scale projection wajib dibuat sebelum ekspansi cabang besar.

---

## 18. AI Assistant Rules During Enterprise Lock

AI assistant, Claude, Cursor, Codex, atau tool lain wajib mengikuti aturan ini:

1. Jangan ubah logic di luar scope.
2. Jangan refactor besar tanpa instruksi eksplisit.
3. Jangan membuat tabel, route, permission, enum, atau service yang tidak diverifikasi dari repo.
4. Jangan target branch `main`.
5. Jangan commit/push/deploy tanpa permintaan eksplisit.
6. Jangan membuka SOAP UI tanpa spec.
7. Jangan auto-send WhatsApp.
8. Jangan expose KTP/NIK penuh.
9. Jangan bypass branch isolation.
10. Jangan bypass policy/permission.
11. Jangan menambah kolom stok mutable.
12. Jangan mengganti arsitektur modular monolith.
13. Jangan introduce React/Vue.
14. Jangan menghapus test lama untuk membuat test baru hijau.
15. Jangan menyatakan smoke/test pass jika command tidak dijalankan.

---

## 19. Definition of Done Enterprise

Setiap sprint enterprise wajib memenuhi DoD berikut:

1. Patch minimal dan scoped.
2. Arsitektur Controller → FormRequest → Service → Repository tetap dihormati.
3. Branch isolation diuji jika applicable.
4. Ledger correctness diuji jika inventory.
5. PII masking diuji jika menyentuh pasien/export/log/report.
6. Relevant `php artisan test` dijalankan.
7. Pint dijalankan untuk file berubah.
8. Route/config/cache sanity dicek jika menyentuh route/config.
9. Migration safety dicek jika ada migration.
10. Evidence command ditulis jujur.
11. Tidak ada file unrelated berubah.
12. Risiko dan asumsi ditulis.
13. Rollback plan tersedia jika menyentuh deploy/runtime.
14. GO tag hanya setelah merge/smoke sesuai aturan.
15. Dokumentasi rule/architecture diperbarui jika ada keputusan baru.

---

## 20. Change Freeze Rule

Sebelum Enterprise Foundation Closure GO:

1. Tidak boleh mengubah keputusan fundamental tanpa ADR atau sprint governance.
2. Tidak boleh mengganti rule RM, KTP, inventory ledger, RME cashier, lab candidate, branch isolation, RBAC, atau deploy safety.
3. Tidak boleh menambah modul besar yang belum melewati architecture review.
4. Tidak boleh mengubah workflow kasir/RME/lab/inventory hanya karena kebutuhan UI sementara.
5. Tidak boleh menghapus guardrail demi mempercepat development.
6. Semua exception harus ditulis eksplisit dalam sprint prompt dan evidence.
7. Jika ada konflik antara kecepatan fitur dan fondasi enterprise, fondasi enterprise menang.
8. Jika ada konflik antara dokumen lama dan kode/test terbaru, kode/test terbaru menang.
9. Jika ada konflik antara permintaan fitur dan security/privacy, security/privacy menang.
10. Enterprise Foundation Closure GO menjadi titik akhir freeze awal.

---

## 21. Enterprise Foundation Closure Criteria

Fondasi enterprise dianggap selesai hanya jika ENT-1 sampai ENT-16 memenuhi:

1. Architecture governance command/check tersedia.
2. Database performance baseline tersedia.
3. Cache governance tersedia.
4. Queue/failed job/idempotency/outbox governance tersedia.
5. Observability dan Developer Assistance tersedia.
6. Security dan PII hardening tersedia.
7. CI/CD gate berjalan.
8. Deploy dan rollback automation tersedia.
9. Backup dan restore rehearsal evidence tersedia.
10. Load test 5 cabang dan scale projection tersedia.
11. Documentation dan runbook tersedia.
12. Final GO/NO-GO evidence pack tersedia.
13. GO tag final dibuat: `enterprise-foundation-go`.

Sampai tag `enterprise-foundation-go` dibuat, seluruh perubahan aplikasi tetap tunduk pada Enterprise Foundation Freeze Rules ini.

---

## Pemetaan ENT ↔ Fondasi Terkirim (ENT-0 Reconciliation)

Sprint fondasi yang sudah GO-tagged/terkirim dipetakan sebagai **dependensi/fondasi terkait**
pada entri ENT di `config/foundation_roadmap.php`. Tidak ada sprint ENT yang ditandai selesai
hanya karena overlap parsial — semua ENT-1 s/d ENT-16 berstatus `planned` sampai masing-masing
punya evidence dan GO tag sendiri.

| ENT | Overlap / fondasi terkirim yang dipakai sebagai baseline |
| --- | --- |
| ENT-1 | STORAGE-1, STATELESS-1, LB-1, ROADMAP-1-CANONICALIZATION (baseline arsitektur + governance summary) |
| ENT-2 | DBPERF-1 (index/EXPLAIN audit), DBPERF-2 (PgBouncer/runtime tuning design) |
| ENT-3 | RPT-1 (rpt_* summary + materialized view readiness) |
| ENT-4 | CACHE-1 (invalidation governance design), CACHE-1-REDIS-READINESS (Redis runtime readiness) |
| ENT-5 | QUEUE-1 (queue/retry/failure policy design) |
| ENT-6 | QUEUE-1 (konvensi idempotency + outbox design), IdempotencyService/OutboxService existing |
| ENT-7 | OBS-1, OBS-2 (request/correlation id + pipeline readiness sebagai sumber data console) |
| ENT-8 | OBS-1, OBS-2, LB-1 (`/health/lb`); MON-1 lama di-queue setelah sekuens ENT karena overlap scope |
| ENT-9 | Pola masking KTP/NIK existing + release-evidence PII scan |
| ENT-10 | NSF-9 (feature flag + automated smoke), NSF-10 (release evidence gate) |
| ENT-11 | `scripts/deploy-vps.sh` + pre-deploy backup flow existing |
| ENT-12 | NSF-10 (backup verification) + BackupVerificationService existing |
| ENT-13/14 | Baseline dataset & target dari Bagian 17 dokumen ini |
| ENT-15 | Dokumen `docs/architecture/*` existing sebagai korpus awal runbook |
| ENT-16 | Konsolidasi; GO tag final `enterprise-foundation-go`; RC-1 nasional tetap item terakhir roadmap |

Catatan urutan roadmap: sekuens ENT disisipkan setelah `ROADMAP-1-CANONICALIZATION` sehingga
`next_recommended_sprint` = **ENT-1**. `MON-1` (Health Monitoring, Alerting & Uptime Readiness)
tetap terdaftar tetapi di-queue setelah sekuens ENT karena scope-nya overlap dengan ENT-8 dan
dapat dikonsolidasikan ke sana melalui sprint governance berikutnya. `PART-1`, `SEARCH-1`,
`NDA-1`, dan `RC-1` tetap di belakang; `RC-1` tetap release candidate terakhir dan kini juga
bergantung pada `ENT-16`.
