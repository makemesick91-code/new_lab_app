# SATUSEHAT-4A — Credential-Independent Operational Readiness & Data Quality Closure

**Status:** GO (internal operational-readiness & data-quality only)
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline:** SATUSEHAT-3 GO @ `f2fc2a2`

## GO semantics (immutable)

SATUSEHAT-4A GO **hanya** berarti: kesiapan operasional internal + workflow
remediasi kualitas data + fondasi diagnosis terstruktur + rehearsal sintetis +
kontrol operasional — semuanya **tanpa kredensial dan tanpa koneksi eksternal**.

SATUSEHAT-4A GO **tidak** berarti: SATUSEHAT-2 GO, OAuth sandbox sukses,
FHIR terkirim, verifikasi Kemkes, atau aktivasi produksi.

- **SATUSEHAT-2 tetap WATCH** (runtime anchor `b841c485`, evidence anchor `a4600ee`).
- **GO tag SATUSEHAT-2 tetap ABSENT.**
- `SATUSEHAT_ENABLED=false`, `SATUSEHAT_SEND_ENABLED=false`, produksi tetap terblokir.

## What shipped (runtime, not docs-only)

1. **Data-quality rule engine** — `SatusehatDataQualityRuleInterface` + 11 rules
   (`app/Modules/Satusehat/Services/DataQuality/Rules/`): PatientIdentity,
   PatientDemographics (HARD), PractitionerReadiness, OrganizationReadiness,
   LocationReadiness, StructuredDiagnosis, DiagnosisMapping, TreatmentMapping,
   DentalCompleteness, SourceDrift (HARD), LocalConformance (HARD/unsupported).
   Deterministic, idempotent (sha256 fingerprint per env|candidate|rule|entity|field),
   side-effect-free evaluate, registered in `config/satusehat_data_quality.php`.
2. **Issue persistence + lifecycle** — additive table
   `trx_satusehat_data_quality_issues` (fingerprint UNIQUE); lifecycle
   open→acknowledged→in_remediation→awaiting_clinical_review→resolved /
   reopened / waived / unsupported via `SatusehatRemediationService`
   (locked, audited, server-validated). **Resolve = revalidation by the rule
   engine** — a still-failing issue can never be marked resolved. **Hard issues
   can never be waived**; waivers need `manage_satusehat_readiness_waivers`,
   a reason, optional expiry (expired+still-detected ⇒ auto-reopen), and never
   change the canonical readiness engine's verdict.
3. **Operational readiness workspace** — routes `satusehat.readiness.*`
   (`/rme/satusehat/readiness[...]`): dashboard (metrics, candidate board with
   ONE resolved operational status per candidate, practitioner/organization/
   location readiness, treatment-mapping summary, onboarding checklist), issue
   list + detail + remediation actions + append-only audit timeline. Branch
   scope selalu server-side (`BranchService::rmeEnabledIds()`, fail-closed);
   NIK never rendered. Status resolver: `SatusehatOperationalStatusResolver`
   (drift → hard local → internal gaps → workflow → BLOCKED_EXTERNAL_CREDENTIAL
   → READY_INTERNAL; IHS gaps are ALWAYS external, never fabricated).
4. **Structured diagnosis foundation** — additive tables
   `mst_clinical_diagnoses` (master, default ICD-10, seeded WHO ICD-10 dental
   set via `ClinicalDiagnosisSeeder`) + `trx_medical_record_diagnoses`
   (primary/secondary, at most one primary, soft-delete). Doctor entry on the
   RME page (Alpine autocomplete → `rme.diagnoses.search`, ACTIVE-only, no
   synthetic/deprecated) via `rme.visits.medical-record.diagnoses.store|destroy`
   (reuses `MedicalRecordPolicy::update` + room gate). Master governance page
   `satusehat.diagnoses.*` (`manage_structured_diagnoses`). **Legacy RM stays
   readable, never backfilled, never auto-coded**; readiness G10 upgraded:
   no diagnosis ⇒ info `diagnosis_not_structured` (unchanged legacy hash — the
   `diagnoses` fact key is omitted when empty), diagnosis without ACTIVE
   Condition mapping ⇒ `diagnosis_mapping_missing` (incomplete). Diagnosis
   changes after approval drift the source hash ⇒ approval revoked.
5. **Synthetic rehearsal pack** — `satusehat:synthetic-pilot seed|verify|reset`
   (`--confirm` required for writes): isolated branch `SYN4A`, marker
   `[SYNTHETIC-SATUSEHAT-4A]`, synthetic KTP, synthetic-only mappings (keyed to
   synthetic entity ids — can never touch real candidates), NO fabricated IHS.
   Reset removes ONLY campaign records. No factories (VPS is `--no-dev`).
6. **Credential-independent rehearsal** — `satusehat:rehearse --synthetic`
   (dry-run default; `--prepare-batch --confirm` for the one controlled local
   write): pipeline synthetic visit → candidate → readiness → issues →
   deterministic re-hash → local FHIR preview → operational classification →
   outbound gate (must be OFF) → production guard (must be blocked). Final
   state is honestly `BLOCKED_EXTERNAL_CREDENTIAL` — never submitted/succeeded.
7. **Diagnostics** — `satusehat:diagnose`, `satusehat:readiness-audit`
   (`--strict` fails on open HARD issues/drift), `satusehat:data-quality-scan`
   (dry-run default, bounded, `--apply`), `satusehat:queue-health`,
   `satusehat:reconciliation-status`; SATUSEHAT-3's
   `satusehat:production-guard-check` unchanged and still blocked.
8. **RBAC** — 4 new permissions: `view_satusehat_readiness` (Owner, Supervisor
   RME, Admin Klinik), `manage_satusehat_remediation` (Supervisor RME, Admin
   Klinik), `manage_satusehat_readiness_waivers` (Supervisor RME only),
   `manage_structured_diagnoses` (Supervisor RME only). Doctor/Kasir/Admin Lab:
   none. `SatusehatDataQualityIssuePolicy` adds the record-level RME-branch
   IDOR boundary on top of route permissions.

## Migrations (additive only)

- `2026_07_17_100001_create_mst_clinical_diagnoses_table`
- `2026_07_17_100002_create_trx_medical_record_diagnoses_table`
- `2026_07_17_100003_create_trx_satusehat_data_quality_issues_table`

No drop/rename/NOT-NULL tightening; `migrate --force` only (never
`migrate:fresh`/`db:wipe` on the VPS).

## Explicit non-goals (unchanged from SATUSEHAT-2/3 governance)

No OAuth/token, no external Patient/Practitioner lookup, no FHIR POST/PUT/GET,
no remote reconciliation, no real IHS identifier creation, no production
activation, no auto-send, no "Send All", no free-text auto-coding, no guessed
ICD/SNOMED mapping, no billing/inventory change.

## Deploy notes

`php artisan migrate --force` (3 additive tables) +
`db:seed --class=PermissionSeeder --force` + `db:seed --class=RoleSeeder --force` +
`db:seed --class=ClinicalDiagnosisSeeder --force` + `permission:cache-reset`.
Post-deploy verification: `satusehat:diagnose --json` (enabled=false,
send_enabled=false, production_blocked=true), `satusehat:readiness-audit`,
`satusehat:production-guard-check`, optional synthetic rehearsal window
(`seed` → `rehearse --synthetic` → expect BLOCKED_EXTERNAL_CREDENTIAL → `reset`).

## Next

**SATUSEHAT-2 External Credential Closure Campaign** — the only path to an
external GO. Until credentials exist, only internal work that does not change
SATUSEHAT-2's WATCH status is recommended.
