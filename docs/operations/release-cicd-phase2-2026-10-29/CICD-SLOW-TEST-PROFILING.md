---
kb_status: snapshot
kb_verified_against: 52b7a486
kb_updated: 2026-10-30
---

# CI-PERF-01 — Profiling Test Lambat (2026-10-30)

Laporan profiling critical gate (NSF-R011) dan rekomendasi perbaikan yang berbasis pengukuran. Kembali ke
[00-INDEX](../../00-INDEX.md). Halaman terkait:
[CICD-PHASE2-BASELINE](CICD-PHASE2-BASELINE.md),
[CICD-PHASE2-FINAL-SUMMARY](CICD-PHASE2-FINAL-SUMMARY.md).

## Sumber data

- Log console Pest dari enam run critical gate (40823677696, 40836499781, 40837127984, 40855928295,
  40856285308, 40872747306). Log tersebut sudah memuat durasi per test, sehingga profiling **tidak
  membutuhkan run CI tambahan**.
- Satu entri per kombinasi kelas dan nama test; durasinya adalah median dari keenam run.
- Pengukuran langsung biaya setup dilakukan pada PostgreSQL 16 (container `postgres:16`), versi yang sama
  dengan service CI.

**Batas data:** Pest memotong nama test panjang ("…") di output console, sehingga beberapa kasus dataset
(`->with([...])`) bisa tergabung menjadi satu entri. Entri seperti itu terlihat "lambat" padahal berisi
beberapa kasus yang masing-masing sekitar 1 detik.

## Ringkasan

| Metrik | Nilai |
|---|---|
| Entri test unik | 6.378 |
| Jumlah durasi (median per entri) | 6.810 s |
| Median durasi per test (p50) | 1,08 s |
| p90 / p99 | 1,34 s / 6,7 s |
| Test ≥ 1 detik | 4.157 entri, **89,4%** waktu |
| Porsi 50 test terlambat | **6,9%** waktu |

**Kesimpulan utama:** waktu critical gate **tidak** didominasi beberapa test lambat. Hampir semua test
membayar biaya tetap sekitar satu detik. Mengoptimalkan 50 test terlambat paling banyak menghemat 6,9%.

## Penyebab, diukur

| Pengukuran (PostgreSQL 16) | Durasi |
|---|---:|
| Test kosong dengan `RefreshDatabase` | 37 ms |
| `BranchSeeder` | 10 ms |
| `seedAccessControl()` (PermissionSeeder + RoleSeeder lewat `db:seed`) | **889 ms** |

| Kelompok penyebab (semua entri) | Porsi waktu |
|---|---:|
| Kelas yang memanggil `seedAccessControl()` | **76,7%** |
| Bukti konkurensi (koneksi database kedua, subproses, tunggu lock) | 9,8% |
| Lainnya | 13,5% |

`seedAccessControl()` dipanggil sebelum hampir setiap test fitur: 186 query `firstOrCreate` dan
`syncPermissions` untuk 19 role, lewat command `db:seed`. Dengan sekitar 3.400 pemanggilan di critical
gate, biaya ini sekitar **50 dari ~118 menit** step test.

## 50 test terlambat

| # | Detik | Kelas | Test (nama terpotong oleh Pest) | Penyebab dominan |
|---:|---:|---|---|---|
| 1 | 26.30 | `RmeInvoiceCorrectionRejectionDeactivationRaceTest` | it B1: a deactivation committed while the mutation waits on the inv… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 2 | 14.83 | `LegacyRmeSeparationOfDutiesTest` | it resolves an unset, empty or misspelled environment value to ENAB… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 3 | 13.95 | `RmePaymentSubmissionConcurrencyTest` | it V: a payment retried after a lock conflict still completes its v… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 4 | 13.61 | `PilotPerformanceSnapshotCoverageAnchorTest` | it ignores the class of the scanned content when deciding coverage… | fixture log multi-MiB |
| 5 | 12.18 | `LegacyOdontogramPatientLookupTest` | it never coerces a malformed identifier into a different patient wi… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 6 | 11.94 | `RmeManualPricingInvoiceTest` | it refuses malformed money with a stable code and never converts it… | nama terpotong, bisa menggabungkan kasus dataset |
| 7 | 11.86 | `LegacyPatientArchiveCompletenessTest` | it agrees with the real single-active-document guard on every lifec… | nama terpotong, bisa menggabungkan kasus dataset |
| 8 | 11.70 | `RmeInvoiceCorrectionRejectionDeactivationRaceTest` | it A2: a payment waits on a rejection that rolls back, then D10 re… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 9 | 11.66 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it F2: a Kontrol-only payment waiting on an origin report that rol… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 10 | 11.34 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it L: a Kontrol-only payment waiting on an origin correction that… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 11 | 11.07 | `PatientKtpCameraOcrPilotTest` | it covers nobody when the pilot configuration is invalid with datas… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 12 | 10.59 | `PilotPerformanceSnapshotCoverageAnchorTest` | it is unaffected by where in the scanned tail the ancient anchor si… | fixture log multi-MiB |
| 13 | 10.30 | `RmePaymentSubmissionConcurrencyTest` | it B: a double-submitted payment waiting on a first attempt that r… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 14 | 10.25 | `ClinicOversightAccessSecurityTest` | it refuses every oversight page to roles without an oversight permi… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 15 | 10.13 | `RmePaymentSubmissionConcurrencyTest` | it C: a double-submitted FULL payment is answered as a replay of t… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 16 | 9.96 | `RmeInvoiceCorrectionPostPaymentLifecycleTest` | it refuses a malformed reference instead of dropping it with datase… | nama terpotong, bisa menggabungkan kasus dataset |
| 17 | 9.92 | `LegacyRmeSourceRmBindingTest` | it folds transcription noise without changing a single significant… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 18 | 9.85 | `RmeInvoiceCorrectionPilotIdentityTest` | it fails closed when the identity allowlist is empty, missing or ma… | nama terpotong, bisa menggabungkan kasus dataset |
| 19 | 9.82 | `LegacyRmeMigrationEligibilityBranchTest` | it returns null for anything that is not a canonical Nomor RM with… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 20 | 9.57 | `DoctorDeviceRegistrationWorkflowAccessTest` | it refuses every workflow page to a doctor who types the URL with (… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 21 | 9.42 | `LegacyPatientArchiveCompletenessAccessTest` | it denies every role that was not granted the read permission with… | nama terpotong, bisa menggabungkan kasus dataset |
| 22 | 8.97 | `DoctorDeviceRegistrationWorkflowAccessTest` | it lets the filing authority READ every step of the shared workflow… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 23 | 8.91 | `RmeInvoiceCorrectionPaymentVersionBindingTest` | it refuses a programmatic payment whose declared version is not a w… | nama terpotong, bisa menggabungkan kasus dataset |
| 24 | 8.83 | `ClinicVisitBranchAuthorityTest` | it refuses a null actor at the boundary instead of answering every… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 25 | 8.80 | `DoctorDeviceRegistrationWorkflowAccessTest` | it renders the workflow sub-sidebar on every page of the workflow w… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 26 | 8.51 | `ObservabilityConsoleTest` | it opens every console page for the Super Admin with ('developer-co… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 27 | 8.43 | `PatientMergeSafetyTest` | it handles a chain merge: an alias keeps resolving after its canoni… | seed per test |
| 28 | 8.36 | `RmeTariffAuthorityInvoicePricingTest` | it refuses every price a strict branch tariff does not authorize wi… | nama terpotong, bisa menggabungkan kasus dataset |
| 29 | 7.87 | `LegacySingleActiveDocumentTest` | it refuses a second NEW legacy odontogram while one is still in fli… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 30 | 7.83 | `LegacyRmeBranchAdmissionTest` | it matches an admitted branch code exactly and rejects prefixes or… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 31 | 7.81 | `LegacySingleActiveDocumentTest` | it refuses a second NEW legacy RME while one is still in flight wit… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 32 | 7.79 | `RmePaymentSubmissionConcurrencyTest` | it U: a visit payment that deadlocks with another transaction is re… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 33 | 7.78 | `ObservabilityConsoleTest` | it forbids every console page to every operational role, including… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 34 | 7.63 | `PatientMergeGuardAndSearchTest` | it finds the canonical patient when an old Nomor RM is typed (alias… | seed per test |
| 35 | 7.48 | `LegacyPatientArchiveCompletenessTest` | it reports every live RME staging status as in progress, never as m… | nama terpotong, bisa menggabungkan kasus dataset |
| 36 | 7.39 | `ObservabilityConsoleTest` | it redirects guests away from every console page with ('developer-c… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 37 | 7.27 | `RmeBillingRefundPhase0FinancialPrerequisitesTest` | it refuses to re-amount, re-date, re-point or re-batch a recorded p… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 38 | 7.04 | `RmeTariffAuthorityInvoicePricingTest` | it refuses an amount that a cents conversion would wrap — exponent… | nama terpotong, bisa menggabungkan kasus dataset |
| 39 | 6.95 | `PatientKtpCameraOcrPilotTest` | it fails safe to requiring a device unless explicitly disabled with… | seed per kasus; nama terpotong, bisa menggabungkan kasus dataset |
| 40 | 6.94 | `RmePaymentSubmissionConcurrencyTest` | it S: a double-submitted Kontrol payment waiting on a first attempt… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 41 | 6.91 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it L: a Kontrol-only payment waiting on an origin correction that a… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 42 | 6.91 | `RmePaymentSubmissionConcurrencyTest` | it E: the same intent submitted by a different cashier is refused a… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 43 | 6.89 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it J: two concurrent full Kontrol-only payments record exactly one;… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 44 | 6.87 | `RmePaymentSubmissionConcurrencyTest` | it L: a double-submitted Kontrol-only payment is answered as a repl… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 45 | 6.86 | `RmeTariffAuthorityCommandsTest` | it refuses the whole plan on any row that cannot be price authority… | nama terpotong, bisa menggabungkan kasus dataset |
| 46 | 6.86 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it K2: a full-amount retry waiting on a first attempt that commits… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 47 | 6.85 | `RmePaymentSubmissionConcurrencyTest` | it H: a double-submitted Kontrol payment is answered as a replay of… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 48 | 6.83 | `RmeBillingDisputeConcurrencyTest` | it C: a payment waiting on a report that rolls back is accepted, an… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 49 | 6.82 | `RmeBillingDisputeBranchReviewerConcurrencyTest` | it N: a cashier whose branch reassignment commits while the Kontrol… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |
| 50 | 6.81 | `RmeInvoiceCorrectionRejectionDeactivationRaceTest` | it B2: a deactivation still uncommitted at the authorization read o… | bukti konkurensi (koneksi DB kedua, subproses, tunggu lock) |

## 20 kelas terlambat

| # | Detik (median, jumlah per kelas) | Kelas |
|---:|---:|---|
| 1 | 153.6 | `RmePaymentIdempotency/RmePaymentSubmissionConcurrencyTest` |
| 2 | 125.7 | `RmeBillingDispute/RmeBillingDisputeBranchReviewerConcurrencyTest` |
| 3 | 95.2 | `RME/OdontogramTest` |
| 4 | 81.3 | `DoctorAccess/DoctorEstateResilienceTest` |
| 5 | 73.8 | `RmeInvoiceCorrection/RmeInvoiceCorrectionPostPaymentLifecycleTest` |
| 6 | 67.9 | `RmeInvoiceCorrection/RmeInvoiceCorrectionPilotActivationTest` |
| 7 | 64.0 | `RmeBillingDispute/RmeBillingDisputeConcurrencyTest` |
| 8 | 62.9 | `PatientMerge/PatientMergeSafetyTest` |
| 9 | 62.6 | `LegacyCompleteness/LegacyPatientArchiveCompletenessTest` |
| 10 | 62.0 | `RME/PatientKtpArchiveBranchIsolationTest` |
| 11 | 61.8 | `Observability/ObservabilityConsoleTest` |
| 12 | 61.1 | `PatientMerge/PatientMergeHardeningTest` |
| 13 | 58.5 | `RmePaymentIdempotency/RmePaymentSubmissionIdempotencyTest` |
| 14 | 58.3 | `DoctorDevice/DoctorDeviceRegistrationWorkflowAccessTest` |
| 15 | 57.2 | `RmeInvoiceCorrection/RmeInvoiceCorrectionConcurrencyTest` |
| 16 | 56.2 | `RME/RmeExamConsentOdontogramHistoryTest` |
| 17 | 55.5 | `RME/PatientKtpCameraOcrTest` |
| 18 | 55.3 | `RmeBillingDispute/RmeBillingDisputeBranchReviewerTest` |
| 19 | 55.1 | `RME/PatientKtpCameraOcrPilotTest` |
| 20 | 55.0 | `RmeInvoiceCorrection/RmeInvoiceCorrectionPilotIdentityTest` |

## Rekomendasi (berbasis pengukuran)

| # | Rekomendasi | Dampak terukur | Status |
|---|---|---|---|
| 1 | Pulihkan hasil `seedAccessControl()` dari snapshot per proses, bukan menjalankan ulang seeder | Slice AccessControl 251,4 s → 74,3 s (3,4×), hasil identik; di CI step critical −34% dan modul selektif −84% (lihat di bawah) | **Diimplementasikan — PR #555, merge `ec7f1c7c`** |
| 2 | Isolasi bukti test per proses agar test paralel aman | Prasyarat paralel; menghentikan penulisan ke direktori bukti kanonik | **Diimplementasikan — PR #556, merge `500f8be3`** |
| 3 | Uji paralel terkontrol (1, 2, 4 worker) | Lokal 2,67× pada 4 worker; menemukan dua cacat keamanan-paralel. Lihat [CICD-PARALLEL-TESTING-BENCHMARK](CICD-PARALLEL-TESTING-BENCHMARK.md) | Benchmark; tidak diaktifkan default |
| 4 | Bukti konkurensi: biarkan apa adanya | 9,8% waktu; test ini membuktikan semantik lock nyata dan tidak boleh dipalsukan | Tidak diubah |
| 5 | Fixture log multi-MiB pada test monitoring | < 1% waktu; ukurannya bagian dari kontrak cakupan scan | Tidak diubah |

Tidak ada test yang dihapus, dilewati, atau dilemahkan.

## Hasil terukur di CI

| Run | Tree | Step test critical (s) | Modul selektif (s) | Menit runner |
|---|---|---:|---:|---:|
| 40927067649 (pembanding, tanpa snapshot) | CI-PERF-02 saja | 4.329 | 3.837 | 144,8 |
| 40924536826 | CI-PERF-01 | 2.875 | 626 | 66,1 |
| 40939517489 | CI-PERF-01 + 02 | 2.707 | 780 | 66,8 |
| 40947344916 | merge `500f8be3` | 2.496 | 987 | 67,2 |
| 40970233065 | merge `52b7a486` | 2.423 | 265 | 53,2 |

Rekomendasi 1 sendirian menurunkan step critical sekitar sepertiga dan modul selektif sekitar lima per enam
dibanding pembanding sejajarnya. Angka ini konsisten dengan profil di atas: biaya `seedAccessControl()` adalah
biaya tetap per test, dan modul selektif (Inventory, Lab, Ui, Permission) memanggilnya hampir di setiap test.

