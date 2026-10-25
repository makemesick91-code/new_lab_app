---
kb_status: snapshot
kb_verified_against: 572a32a9
kb_updated: 2026-10-25
---

# Indeks Modul DaengtisiaMS

Peta dari setiap modul di `app/Modules/` (41 modul per 2026-10-25) ke dokumentasi yang
membahasnya. Kembali ke [00-INDEX](../00-INDEX.md).

## Cara indeks ini dibuat

Indeks ini adalah **snapshot hasil turunan**, bukan dokumen yang ditulis tangan per modul.
Dibuat pada 2026-10-25 terhadap commit `572a32a9`.

- **Jumlah file PHP** dihitung dari `app/Modules/<Modul>/`.
- **"N dokumen menyebut path modul ini"** menghitung dokumen di `docs/` dan `.cursor/rules/`
  yang menulis path modul secara eksplisit (`app/Modules/<Modul>`, `Modules\<Modul>`).
  Angka ini adalah bukti cakupan, bukan ukuran kualitas.
- **Tautan dokumen dan rule** dipilih berdasarkan **kata kunci pada nama berkas**
  (misalnya `satusehat-`, `doctor-device-`, `legacy-rme-`), karena repo ini menamai dokumennya
  secara deskriptif. Dokumen yang relevan tetapi namanya tidak memuat kata kunci tersebut
  bisa terlewat.
- Dokumen sprint diurutkan dari **commit terakhir yang terbaru**, sehingga yang paling mungkin
  masih berlaku muncul lebih dulu.
- Pengelompokan per domain adalah klasifikasi penyusun indeks, bukan struktur di kode.
- Rule `.cursor/rules/*.mdc` ditulis sebagai teks kode, bukan tautan, karena berada di luar
  vault Obsidian.

**GAP** artinya tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya
merujuk modul tersebut. Untuk modul GAP, bacalah kodenya langsung. Modul GAP per 2026-10-25:
`Prescription`, `RmeDashboard`, `Clinic`, `Treatment`, `TreatmentCategory`, `PaymentMethod`,
`LabService`, `Invoice`, `User`, `WaReminderTemplate`.

**Cara memperbarui:** jika `ls app/Modules` menampilkan modul yang tidak ada di halaman ini,
indeks sudah usang. Tambahkan modul tersebut dengan metode yang sama (path-mention untuk
bukti, kata kunci nama berkas untuk tautan), lalu perbarui `kb_verified_against`.

## RME klinis — kunjungan, rekam medis, odontogram, consent

### `ClinicVisit`

- Kode: `app/Modules/ClinicVisit/` — 16 file PHP; 51 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-new-visit-patient-search-combobox.mdc`, `101-new-visit-global-patient-identity-lookup.mdc`, `199-rme-clinic-visit-branch-authority.mdc`
- Sprint terbaru: [audit-rme-clinic-visit-branch-authority-1](../sprints/audit-rme-clinic-visit-branch-authority-1.md), [bugfix-new-visit-patient-search-runtime-1](../sprints/bugfix-new-visit-patient-search-runtime-1.md), [revision-new-visit-global-patient-lookup-1](../sprints/revision-new-visit-global-patient-lookup-1.md) — total 4 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [08_DaengtisiaMS_RME_Workflow](../ai-knowledge/08_DaengtisiaMS_RME_Workflow.md), [10_DaengtisiaMS_Visit_Medical_Record_Rules](../ai-knowledge/10_DaengtisiaMS_Visit_Medical_Record_Rules.md)

### `MedicalRecord`

- Kode: `app/Modules/MedicalRecord/` — 43 file PHP; 36 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `127-rme-print-composition-no-duplicate-patient-card.mdc`, `173-unified-medical-record-index.mdc`
- Sprint terbaru: [feature-rme-medical-records-unified-native-legacy-1](../sprints/feature-rme-medical-records-unified-native-legacy-1.md), [fix-rme-print-remove-patient-visit-duplicate-1](../sprints/fix-rme-print-remove-patient-visit-duplicate-1.md), [uix-11-rme-medical-record-odontogram-print-bundle-polish](../sprints/uix-11-rme-medical-record-odontogram-print-bundle-polish.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [08_DaengtisiaMS_RME_Workflow](../ai-knowledge/08_DaengtisiaMS_RME_Workflow.md), [10_DaengtisiaMS_Visit_Medical_Record_Rules](../ai-knowledge/10_DaengtisiaMS_Visit_Medical_Record_Rules.md)

### `Odontogram`

- Kode: `app/Modules/Odontogram/` — 8 file PHP; 19 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `109-rme-exam-consent-odontogram-history.mdc`, `110-post-rme-odontogram-stabilization.mdc`, `134-odontogram-pre-consent-authoring.mdc`
- Sprint terbaru: [feature-legacy-rme-odontogram-mass-upload-1](../sprints/feature-legacy-rme-odontogram-mass-upload-1.md), [revision-rme-consent-odontogram-preconsent-edit-1](../sprints/revision-rme-consent-odontogram-preconsent-edit-1.md), [post-rme-odontogram-stabilization-1](../sprints/post-rme-odontogram-stabilization-1.md) — total 6 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [11_DaengtisiaMS_Odontogram_Rules](../ai-knowledge/11_DaengtisiaMS_Odontogram_Rules.md)

### `Consent`

- Kode: `app/Modules/Consent/` — 8 file PHP; 5 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-rme-consent-payment-gate-print-ownership.mdc`, `109-rme-exam-consent-odontogram-history.mdc`, `134-odontogram-pre-consent-authoring.mdc`, `180-ktp-ocr-consent-d7.mdc`
- Sprint terbaru: [bugfix-rme-preconsent-first-page-ui-gate-1](../sprints/bugfix-rme-preconsent-first-page-ui-gate-1.md), [revision-rme-consent-odontogram-preconsent-edit-1](../sprints/revision-rme-consent-odontogram-preconsent-edit-1.md), [fix-rme-consent-workflow-print-ux-2](../sprints/fix-rme-consent-workflow-print-ux-2.md) — total 4 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [08_DaengtisiaMS_RME_Workflow](../ai-knowledge/08_DaengtisiaMS_RME_Workflow.md)

### `Prescription`

- Kode: `app/Modules/Prescription/` — 20 file PHP; 1 dokumen menyebut path modul ini.
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `RME`

- Kode: `app/Modules/RME/` — 4 file PHP; 10 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `138-doctor-room-scoped-access-and-print-deny.mdc`
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [08_DaengtisiaMS_RME_Workflow](../ai-knowledge/08_DaengtisiaMS_RME_Workflow.md)

### `RmeDashboard`

- Kode: `app/Modules/RmeDashboard/` — 1 file PHP; 2 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [19_DaengtisiaMS_Owner_Dashboard_KPI](../ai-knowledge/19_DaengtisiaMS_Owner_Dashboard_KPI.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `RmeOnlineContext`

- Kode: `app/Modules/RmeOnlineContext/` — 26 file PHP; 23 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `108-clinic-ops-working-branch-context.mdc`, `73-rme-branch-sun4-perawat-online-context.mdc`, `92-daily-branch-context-lock.mdc`, `99-legacy-rme-doctor-clinical-branch-context.mdc`
- Sprint terbaru: [feature-daily-branch-context-lock-1](../sprints/feature-daily-branch-context-lock-1.md), [fix-clinic-ops-branch-context-wa-1](../sprints/fix-clinic-ops-branch-context-wa-1.md), [legacy-rme-pdf-history-1b-doctor-clinical-branch-context-production-read-uat](../sprints/legacy-rme-pdf-history-1b-doctor-clinical-branch-context-production-read-uat.md) — total 4 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [07_DaengtisiaMS_Branch_Context_Rules](../ai-knowledge/07_DaengtisiaMS_Branch_Context_Rules.md)

### `ClinicRoom`

- Kode: `app/Modules/ClinicRoom/` — 8 file PHP; 4 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `137-branch-room-provisioning.mdc`
- Sprint terbaru: [revision-sunu-add-room-a-b-1](../sprints/revision-sunu-add-room-a-b-1.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [10_DaengtisiaMS_Visit_Medical_Record_Rules](../ai-knowledge/10_DaengtisiaMS_Visit_Medical_Record_Rules.md)

### `Clinic`

- Kode: `app/Modules/Clinic/` — 8 file PHP; 1 dokumen menyebut path modul ini.
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `ClinicOversight`

- Kode: `app/Modules/ClinicOversight/` — 27 file PHP; 6 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `187-oversight-dashboard-date-range-foundation.mdc`
- Sprint terbaru: [revision-oversight-dashboard-date-range-1](../sprints/revision-oversight-dashboard-date-range-1.md)

## Kasir & tagihan RME

### `RmeInvoice`

- Kode: `app/Modules/RmeInvoice/` — 98 file PHP; 55 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-rme-consent-payment-gate-print-ownership.mdc`, `185-rme-visit-detail-invoice-registration-ux.mdc`, `188-rme-billing-correction-financial-integrity-foundation.mdc`, `189-rme-billing-refund-phase0-financial-prerequisites.mdc` — dan 12 lainnya
- Desain / ADR / runbook / operasi: [0012-rme-postpayment-invoice-correction](../adr/0012-rme-postpayment-invoice-correction.md), [rme-postpayment-invoice-correction-design](../architecture/rme-postpayment-invoice-correction-design.md), [rme-billing-correction-owner-decisions](../decisions/rme-billing-correction-owner-decisions.md), [rme-postpayment-correction-owner-decision-proposals](../decisions/rme-postpayment-correction-owner-decision-proposals.md), [rme-postpayment-invoice-correction-sop-draft](../operations/rme-postpayment-invoice-correction-sop-draft.md) — dan 9 lainnya
- Sprint terbaru: [preparation-rme-postpayment-invoice-correction-1](../sprints/preparation-rme-postpayment-invoice-correction-1.md), [ratification-rme-postpayment-owner-decisions-pp1-pp10-1](../sprints/ratification-rme-postpayment-owner-decisions-pp1-pp10-1.md), [verification-rme-invoice-correction-prepayment-pilot-real-case-42-1](../sprints/verification-rme-invoice-correction-prepayment-pilot-real-case-42-1.md) — total 21 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [12_DaengtisiaMS_Cashier_Receivable_Rules](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md)

### `Tariff`

- Kode: `app/Modules/Tariff/` — 17 file PHP; 5 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `192-rme-branch-tariff-authority-financial-pricing.mdc`, `193-rme-branch-tariff-provisioning-governance.mdc`, `194-rme-manual-pricing-authority.mdc`
- Sprint terbaru: [revision-rme-manual-pricing-authority-1](../sprints/revision-rme-manual-pricing-authority-1.md), [feature-rme-branch-tariff-authority-1](../sprints/feature-rme-branch-tariff-authority-1.md), [feature-rme-branch-tariff-provisioning-1](../sprints/feature-rme-branch-tariff-provisioning-1.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [12_DaengtisiaMS_Cashier_Receivable_Rules](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md)

### `Treatment`

- Kode: `app/Modules/Treatment/` — 8 file PHP; 3 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [12_DaengtisiaMS_Cashier_Receivable_Rules](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `TreatmentCategory`

- Kode: `app/Modules/TreatmentCategory/` — 8 file PHP; 1 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [12_DaengtisiaMS_Cashier_Receivable_Rules](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `PaymentMethod`

- Kode: `app/Modules/PaymentMethod/` — 8 file PHP; 1 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [12_DaengtisiaMS_Cashier_Receivable_Rules](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

## Pasien

### `Patient`

- Kode: `app/Modules/Patient/` — 44 file PHP; 32 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-new-visit-patient-search-combobox.mdc`, `101-new-visit-global-patient-identity-lookup.mdc`, `127-rme-print-composition-no-duplicate-patient-card.mdc`, `131-legacy-odontogram-patient-lookup.mdc` — dan 11 lainnya
- Desain / ADR / runbook / operasi: [patient-ktp-archive-access-runbook](../runbooks/patient-ktp-archive-access-runbook.md), [ktp-camera-ocr-supervised-pilot-protocol](../runbooks/ktp-camera-ocr-supervised-pilot-protocol.md), [ktp-camera-ocr-pilot-activation-spn4-2026-10-10](../operations/ktp-camera-ocr-pilot-activation-spn4-2026-10-10.md), [full-patient-estate-reset-1-runbook](../operations/full-patient-estate-reset-1-runbook.md), [sunu-first-real-patient-checklist](../operations/sunu-first-real-patient-checklist.md)
- Sprint terbaru: [hardening-rme-active-user-patient-selection-1](../sprints/hardening-rme-active-user-patient-selection-1.md), [audit-patient-ktp-archive-persistence-1](../sprints/audit-patient-ktp-archive-persistence-1.md), [governance-patient-ktp-new-patient-only-1](../sprints/governance-patient-ktp-new-patient-only-1.md) — total 20 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [09_DaengtisiaMS_Patient_RM_KTP_Rules](../ai-knowledge/09_DaengtisiaMS_Patient_RM_KTP_Rules.md)

### `PatientMerge`

- Kode: `app/Modules/PatientMerge/` — 37 file PHP; 3 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `172-patient-duplicate-resolution-merge.mdc`
- Desain / ADR / runbook / operasi: [patient-duplicate-resolution-merge](../architecture/patient-duplicate-resolution-merge.md)
- Sprint terbaru: [feature-patient-duplicate-resolution-merge-1](../sprints/feature-patient-duplicate-resolution-merge-1.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [09_DaengtisiaMS_Patient_RM_KTP_Rules](../ai-knowledge/09_DaengtisiaMS_Patient_RM_KTP_Rules.md)

## Akses & perangkat dokter / front office

### `Doctor`

- Kode: `app/Modules/Doctor/` — 13 file PHP; 14 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `92-doctor-account-identity-link.mdc`
- Sprint terbaru: [feature-doctor-account-performance-income-linkage-1](../sprints/feature-doctor-account-performance-income-linkage-1.md)

### `DoctorAccess`

- Kode: `app/Modules/DoctorAccess/` — 63 file PHP; 24 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `153-doctor-single-active-session-lease.mdc`, `154-doctor-branch-lock-and-cover.mdc`, `156-doctor-fleet-rollout-readiness.mdc`, `157-doctor-access-governance-phase.mdc` — dan 2 lainnya
- Desain / ADR / runbook / operasi: [doctor-global-activation-window](../runbooks/doctor-global-activation-window.md), [doctor-branch-lock-operations](../runbooks/doctor-branch-lock-operations.md), [doctor-access-fleet-rollout-readiness-1-closure](../operations/doctor-access-fleet-rollout-readiness-1-closure.md), [doctor-branch-lock-and-cover](../architecture/doctor-branch-lock-and-cover.md), [doctor-single-active-session-lease](../architecture/doctor-single-active-session-lease.md) — dan 2 lainnya
- Sprint terbaru: [doctor-access-global-activation-half-a-cutover](../sprints/doctor-access-global-activation-half-a-cutover.md), [doctor-access-global-device-enforcement-readiness-1](../sprints/doctor-access-global-device-enforcement-readiness-1.md), [doctor-access-global-activation-phase0-delta-recheck-1](../sprints/doctor-access-global-activation-phase0-delta-recheck-1.md) — total 12 dokumen sprint

### `DoctorDevice`

- Kode: `app/Modules/DoctorDevice/` — 65 file PHP; 27 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `139-doctor-device-registry.mdc`, `140-android-clinic-device-identity.mdc`, `141-android-production-release-governance.mdc`, `142-android-direct-apk-signing-distribution.mdc` — dan 13 lainnya
- Desain / ADR / runbook / operasi: [doctor-pwa-webauthn](../architecture/doctor-pwa-webauthn.md), [doctor-device-loss-rehearsal](../runbooks/doctor-device-loss-rehearsal.md), [doctor-device-estate-owner-risk-acceptance-pre-live](../operations/doctor-device-estate-owner-risk-acceptance-pre-live.md), [android-device-loss-replacement-and-decommission](../runbooks/android-device-loss-replacement-and-decommission.md), [android-phase4a-pilot-activation-checklist](../runbooks/android-phase4a-pilot-activation-checklist.md) — dan 11 lainnya
- Sprint terbaru: [doctor-device-guided-registration-workflow-1](../sprints/doctor-device-guided-registration-workflow-1.md), [bugfix-doctor-pwa-webauthn-verify-device-no-feedback-2](../sprints/bugfix-doctor-pwa-webauthn-verify-device-no-feedback-2.md), [fix-doctor-webauthn-readiness-live-proof-1](../sprints/fix-doctor-webauthn-readiness-live-proof-1.md) — total 25 dokumen sprint

### `FrontOfficeDevice`

- Kode: `app/Modules/FrontOfficeDevice/` — 6 file PHP; 1 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `164-front-office-branch-context-lock.mdc`, `92-front-office-branch-device-lock.mdc`
- Desain / ADR / runbook / operasi: [front-office-branch-context-lock-activation-runbook](../runbooks/front-office-branch-context-lock-activation-runbook.md), [front-office-branch-device-lock-activation-runbook](../runbooks/front-office-branch-device-lock-activation-runbook.md)
- Sprint terbaru: [revision-front-office-branch-context-lock-1](../sprints/revision-front-office-branch-context-lock-1.md), [revision-front-office-branch-device-lock-1](../sprints/revision-front-office-branch-device-lock-1.md)

## Legacy import — arsip historis

### `LegacyImport`

- Kode: `app/Modules/LegacyImport/` — 95 file PHP; 14 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-legacy-mass-upload.mdc`, `128-legacy-import-hub.mdc`, `166-legacy-import-unlimited-quota.mdc`, `167-legacy-visit-bound-preverified-ingestion.mdc` — dan 5 lainnya
- Desain / ADR / runbook / operasi: [legacy-mass-upload-operator-runbook](../runbooks/legacy-mass-upload-operator-runbook.md), [legacy-patient-import-operator-runbook](../runbooks/legacy-patient-import-operator-runbook.md), [legacy-single-active-document-operator-runbook](../runbooks/legacy-single-active-document-operator-runbook.md), [legacy-visit-bound-preverified-ingestion-runbook](../runbooks/legacy-visit-bound-preverified-ingestion-runbook.md)
- Sprint terbaru: [feature-legacy-patient-document-completeness-1](../sprints/feature-legacy-patient-document-completeness-1.md), [bugfix-legacy-batch-review-decision-not-submitted-1](../sprints/bugfix-legacy-batch-review-decision-not-submitted-1.md), [feature-legacy-batch-review-publish-1-pr2](../sprints/feature-legacy-batch-review-publish-1-pr2.md) — total 13 dokumen sprint

### `LegacyRme`

- Kode: `app/Modules/LegacyRme/` — 97 file PHP; 42 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-legacy-rme-activation-states.mdc`, `100-legacy-rme-production-migration-wave.mdc`, `101-legacy-rme-import-lifecycle-cli.mdc`, `102-legacy-rme-separation-of-duties.mdc` — dan 14 lainnya
- Desain / ADR / runbook / operasi: [legacy-rme-pdf-rollout-runbook](../runbooks/legacy-rme-pdf-rollout-runbook.md), [legacy-rme-routine-batch-operator-checklist](../runbooks/legacy-rme-routine-batch-operator-checklist.md), [legacy-rme-steady-state-operations-runbook](../runbooks/legacy-rme-steady-state-operations-runbook.md), [legacy-rme-migration-operations-runbook](../runbooks/legacy-rme-migration-operations-runbook.md), [0008-legacy-rme-pdf-archive-foundation](../adr/0008-legacy-rme-pdf-archive-foundation.md)
- Sprint terbaru: [feature-legacy-rme-odontogram-mass-upload-1](../sprints/feature-legacy-rme-odontogram-mass-upload-1.md), [fix-legacy-rme-routine-ops-1](../sprints/fix-legacy-rme-routine-ops-1.md), [legacy-rme-program-closure-1-final-program-audit-production-steady-state](../sprints/legacy-rme-program-closure-1-final-program-audit-production-steady-state.md) — total 26 dokumen sprint

### `LegacyOdontogram`

- Kode: `app/Modules/LegacyOdontogram/` — 41 file PHP; 14 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `111-legacy-odontogram-native-reference-cutoff.mdc`, `131-legacy-odontogram-patient-lookup.mdc`, `132-legacy-odontogram-native-optional.mdc`, `174-legacy-odontogram-read-scope.mdc`
- Sprint terbaru: [fix-legacy-odontogram-cross-branch-read-scope-1](../sprints/fix-legacy-odontogram-cross-branch-read-scope-1.md), [bugfix-legacy-odontogram-queue-consumer-1](../sprints/bugfix-legacy-odontogram-queue-consumer-1.md), [legacy-odontogram-native-reference-cutoff-1](../sprints/legacy-odontogram-native-reference-cutoff-1.md) — total 5 dokumen sprint

## SATUSEHAT

### `Satusehat`

- Kode: `app/Modules/Satusehat/` — 154 file PHP; 10 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `83-satusehat-integration-readiness.mdc`, `84-satusehat-sandbox-api-adapter.mdc`, `85-satusehat-dental-use-case-production-readiness.mdc`, `86-satusehat-4a-operational-readiness-data-quality.mdc` — dan 3 lainnya
- Desain / ADR / runbook / operasi: [satusehat-integration-readiness-runbook](../runbooks/satusehat-integration-readiness-runbook.md), [0004-satusehat-4a-credential-independent-operational-readiness](../adr/0004-satusehat-4a-credential-independent-operational-readiness.md), [0005-satusehat-4b-structured-diagnosis-adoption-terminology-rollout](../adr/0005-satusehat-4b-structured-diagnosis-adoption-terminology-rollout.md), [0006-satusehat-4c-branch-readiness-internal-pilot](../adr/0006-satusehat-4c-branch-readiness-internal-pilot.md), [0007-satusehat-4d-multi-branch-readiness-scale-up](../adr/0007-satusehat-4d-multi-branch-readiness-scale-up.md) — dan 11 lainnya
- Sprint terbaru: [satusehat-4a-credential-independent-operational-readiness-data-quality-closure](../sprints/satusehat-4a-credential-independent-operational-readiness-data-quality-closure.md), [satusehat-4b-structured-diagnosis-adoption-clinical-terminology-rollout](../sprints/satusehat-4b-structured-diagnosis-adoption-clinical-terminology-rollout.md), [satusehat-4c-branch-readiness-remediation-internal-pilot-operations](../sprints/satusehat-4c-branch-readiness-remediation-internal-pilot-operations.md) — total 7 dokumen sprint

## Lab (dental lab)

### `LabOrder`

- Kode: `app/Modules/LabOrder/` — 90 file PHP; 26 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `72-lab-workflow-v2.mdc`, `76-lab-v2-notification-destination-routing.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`, `79-lab-ops-readiness-technician-external-lab.mdc` — dan 1 lainnya
- Desain / ADR / runbook / operasi: [lab-operational-analytics-kpi-contract](../architecture/lab-operational-analytics-kpi-contract.md), [lab-workflow-v2-pilot-runbook](../operations/lab-workflow-v2-pilot-runbook.md), [lab-workflow-v2-pilot-uat-checklist](../operations/lab-workflow-v2-pilot-uat-checklist.md), [lab-request-operator-rme-branch-mapping-2026-07-10](../operations/lab-request-operator-rme-branch-mapping-2026-07-10.md)
- Sprint terbaru: [lab-ops-readiness-1-evidence](../sprints/lab-ops-readiness-1-evidence.md), [lab-ops-readiness-1-technician-external-lab-closure](../sprints/lab-ops-readiness-1-technician-external-lab-closure.md), [lab-prod-2-operational-analytics-kpi](../sprints/lab-prod-2-operational-analytics-kpi.md) — total 12 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)

### `LabService`

- Kode: `app/Modules/LabService/` — 8 file PHP; 1 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `LabCapacity`

- Kode: `app/Modules/LabCapacity/` — 16 file PHP; 2 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `159-doctor-trusted-device-estate-capacity-policy.mdc`, `81-lab-technician-capacity-planning.mdc`
- Sprint terbaru: [revision-doctor-trusted-device-estate-capacity-policy-1](../sprints/revision-doctor-trusted-device-estate-capacity-policy-1.md), [lab-prod-3-technician-capacity-planning](../sprints/lab-prod-3-technician-capacity-planning.md)

### `Production`

- Kode: `app/Modules/Production/` — 30 file PHP; 6 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `72-lab-workflow-v2.mdc`, `74-lab-technician-assignment-upload-compression.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`
- Desain / ADR / runbook / operasi: [lab-workflow-v2-pilot-runbook](../operations/lab-workflow-v2-pilot-runbook.md), [lab-workflow-v2-pilot-uat-checklist](../operations/lab-workflow-v2-pilot-uat-checklist.md)
- Sprint terbaru: [lab-workflow-v2-pilot-uat-1-evidence](../sprints/lab-workflow-v2-pilot-uat-1-evidence.md), [lab-workflow-v2-pilot-uat-1-operational-readiness-closure](../sprints/lab-workflow-v2-pilot-uat-1-operational-readiness-closure.md), [lab-workflow-v2-phase-1-foundation](../sprints/lab-workflow-v2-phase-1-foundation.md) — total 7 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)

### `QualityControl`

- Kode: `app/Modules/QualityControl/` — 25 file PHP; 5 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `72-lab-workflow-v2.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`
- Desain / ADR / runbook / operasi: [lab-workflow-v2-pilot-runbook](../operations/lab-workflow-v2-pilot-runbook.md), [lab-workflow-v2-pilot-uat-checklist](../operations/lab-workflow-v2-pilot-uat-checklist.md)
- Sprint terbaru: [lab-workflow-v2-pilot-uat-1-evidence](../sprints/lab-workflow-v2-pilot-uat-1-evidence.md), [lab-workflow-v2-pilot-uat-1-operational-readiness-closure](../sprints/lab-workflow-v2-pilot-uat-1-operational-readiness-closure.md), [lab-workflow-v2-phase-1-foundation](../sprints/lab-workflow-v2-phase-1-foundation.md) — total 7 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)

### `Delivery`

- Kode: `app/Modules/Delivery/` — 17 file PHP; 4 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `72-lab-workflow-v2.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`
- Desain / ADR / runbook / operasi: [lab-workflow-v2-pilot-runbook](../operations/lab-workflow-v2-pilot-runbook.md), [lab-workflow-v2-pilot-uat-checklist](../operations/lab-workflow-v2-pilot-uat-checklist.md)
- Sprint terbaru: [lab-workflow-v2-pilot-uat-1-evidence](../sprints/lab-workflow-v2-pilot-uat-1-evidence.md), [lab-workflow-v2-pilot-uat-1-operational-readiness-closure](../sprints/lab-workflow-v2-pilot-uat-1-operational-readiness-closure.md), [lab-workflow-v2-phase-1-foundation](../sprints/lab-workflow-v2-phase-1-foundation.md) — total 7 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)

### `Technician`

- Kode: `app/Modules/Technician/` — 10 file PHP; 7 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `74-lab-technician-assignment-upload-compression.mdc`, `79-lab-ops-readiness-technician-external-lab.mdc`, `81-lab-technician-capacity-planning.mdc`
- Sprint terbaru: [lab-ops-readiness-1-technician-external-lab-closure](../sprints/lab-ops-readiness-1-technician-external-lab-closure.md), [lab-prod-3-technician-capacity-planning](../sprints/lab-prod-3-technician-capacity-planning.md), [fix-lab-technician-role-assignment-upload-compression](../sprints/fix-lab-technician-role-assignment-upload-compression.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)

### `Invoice`

- Kode: `app/Modules/Invoice/` — 20 file PHP; 5 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [13_DaengtisiaMS_Lab_Module_Workflow](../ai-knowledge/13_DaengtisiaMS_Lab_Module_Workflow.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

## Inventory & procurement

### `Inventory`

- Kode: `app/Modules/Inventory/` — 193 file PHP; 44 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `20-inventory-branch-ledger.mdc`, `inventory-expert.mdc`
- Desain / ADR / runbook / operasi: [clinic-tablet-procurement-specification](../operations/clinic-tablet-procurement-specification.md), [inventory-procurement-workflow-audit-runbook](../runbooks/inventory-procurement-workflow-audit-runbook.md), [database-performance-hotspot-inventory](../architecture/database-performance-hotspot-inventory.md), [reporting-summary-candidate-inventory](../architecture/reporting-summary-candidate-inventory.md), [canonical-entity-workflow-inventory](../architecture/canonical-entity-workflow-inventory.md) — dan 4 lainnya
- Sprint terbaru: [sprint-68-45-inventory-procurement-workflow-stabilization](../sprints/sprint-68-45-inventory-procurement-workflow-stabilization.md), [uix-9-inventory-analytics-workflow-forms-polish](../sprints/uix-9-inventory-analytics-workflow-forms-polish.md), [uix-6-inventory-polish](../sprints/uix-6-inventory-polish.md) — total 12 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [14_DaengtisiaMS_Inventory_Master_Data](../ai-knowledge/14_DaengtisiaMS_Inventory_Master_Data.md), [15_DaengtisiaMS_Inventory_Ledger_Rules](../ai-knowledge/15_DaengtisiaMS_Inventory_Ledger_Rules.md), [16_DaengtisiaMS_Procurement_PR_PO_GR](../ai-knowledge/16_DaengtisiaMS_Procurement_PR_PO_GR.md), [17_DaengtisiaMS_Stock_Transfer_Opname](../ai-knowledge/17_DaengtisiaMS_Stock_Transfer_Opname.md)

## Platform & lintas modul

### `AccessControl`

- Kode: `app/Modules/AccessControl/` — 12 file PHP; 11 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `100-production-secret-file-permission-hardening.mdc`, `186-finance-klinik-data-analyst-role-foundation.mdc`, `75-admin-lab-lab-only-access.mdc`, `82-login-redirect-runtime-permissions.mdc`
- Desain / ADR / runbook / operasi: [production-secret-file-permissions-runbook](../runbooks/production-secret-file-permissions-runbook.md)
- Sprint terbaru: [security-fix-deploy-backup-file-permissions-1](../sprints/security-fix-deploy-backup-file-permissions-1.md), [infra-sec-env-1-production-secret-file-permission-hardening](../sprints/infra-sec-env-1-production-secret-file-permission-hardening.md), [cicd-fix-4-lab-permission-postgresql-selective-gate-portability](../sprints/cicd-fix-4-lab-permission-postgresql-selective-gate-portability.md) — total 8 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [06_DaengtisiaMS_RBAC_Permissions](../ai-knowledge/06_DaengtisiaMS_RBAC_Permissions.md)

### `Branch`

- Kode: `app/Modules/Branch/` — 11 file PHP; 42 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `136-sunu-branch-code-canonical.mdc`, `92-telkomas-branch-code-canonical.mdc`
- Sprint terbaru: [revision-sunu-branch-code-sun4-to-spn4-1](../sprints/revision-sunu-branch-code-sun4-to-spn4-1.md), [revision-telkomas-branch-code-tkm1-to-tlk1-1](../sprints/revision-telkomas-branch-code-tkm1-to-tlk1-1.md)
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [07_DaengtisiaMS_Branch_Context_Rules](../ai-knowledge/07_DaengtisiaMS_Branch_Context_Rules.md)

### `User`

- Kode: `app/Modules/User/` — 7 file PHP; 1 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [06_DaengtisiaMS_RBAC_Permissions](../ai-knowledge/06_DaengtisiaMS_RBAC_Permissions.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.

### `Reporting`

- Kode: `app/Modules/Reporting/` — 29 file PHP; 18 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `129-rme-report-default-clinical-today.mdc`, `52-reporting-materialized-summary.mdc`, `80-lab-operational-analytics-kpi.mdc`
- Desain / ADR / runbook / operasi: [lab-operational-analytics-kpi-contract](../architecture/lab-operational-analytics-kpi-contract.md), [reporting-materialized-summary-contract](../architecture/reporting-materialized-summary-contract.md), [reporting-summary-candidate-inventory](../architecture/reporting-summary-candidate-inventory.md), [canonical-metric-reconciliation](../architecture/canonical-metric-reconciliation.md), [canonical-metrics-foundation](../architecture/canonical-metrics-foundation.md) — dan 2 lainnya
- Sprint terbaru: [revision-rme-reports-today-default-1](../sprints/revision-rme-reports-today-default-1.md), [lab-prod-2-operational-analytics-kpi](../sprints/lab-prod-2-operational-analytics-kpi.md), [uix-13-owner-dashboard-kpi-polish](../sprints/uix-13-owner-dashboard-kpi-polish.md) — total 11 dokumen sprint
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [18_DaengtisiaMS_Reports_Exports](../ai-knowledge/18_DaengtisiaMS_Reports_Exports.md), [19_DaengtisiaMS_Owner_Dashboard_KPI](../ai-knowledge/19_DaengtisiaMS_Owner_Dashboard_KPI.md)

### `Observability`

- Kode: `app/Modules/Observability/` — 18 file PHP; 2 dokumen menyebut path modul ini.
- Rule durable (`.cursor/rules/`, di luar vault): `112-monitoring-baseline-zero-known-failures.mdc`, `113-monitoring-logs-watch-truthfulness.mdc`, `114-monitoring-log-source-authority.mdc`, `115-monitoring-log-timestamp-authority.mdc` — dan 5 lainnya
- Desain / ADR / runbook / operasi: [observability-console](../architecture/observability-console.md), [mon-1-foundation-monitoring-observability-runbook](../runbooks/mon-1-foundation-monitoring-observability-runbook.md), [observability-health-check-pack-governance](../architecture/observability-health-check-pack-governance.md), [nsf-10-observability-backup-release-safety-hardening](../architecture/nsf-10-observability-backup-release-safety-hardening.md), [observability-governance-rules](../architecture/observability-governance-rules.md) — dan 3 lainnya
- Sprint terbaru: [feature-dev-console-observability-1](../sprints/feature-dev-console-observability-1.md), [ci-monitoring-critical-token-coverage-1](../sprints/ci-monitoring-critical-token-coverage-1.md), [monitoring-log-coverage-anchor-injection-1](../sprints/monitoring-log-coverage-anchor-injection-1.md) — total 18 dokumen sprint

### `WaReminderTemplate`

- Kode: `app/Modules/WaReminderTemplate/` — 8 file PHP; 3 dokumen menyebut path modul ini.
- Ringkasan `ai-knowledge` (snapshot, cek [status](doc-status-registry.md)): [20_DaengtisiaMS_WhatsApp_Reminder_Workflow](../ai-knowledge/20_DaengtisiaMS_WhatsApp_Reminder_Workflow.md)
- ⚠️ **GAP**: tidak ada ADR, desain, runbook, rule, atau dokumen sprint yang namanya merujuk modul ini. Baca kodenya langsung.
