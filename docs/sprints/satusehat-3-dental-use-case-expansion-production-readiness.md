# SATUSEHAT-3 — Dental Use-Case Expansion & Production Readiness

**Branch:** `feature/satusehat-3-dental-use-case-expansion-production-readiness`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Baseline:** `satusehat-2` runtime anchor `b841c485`, evidence anchor `a4600ee`
**Status intent:** GO for the **internal dental implementation + production-readiness** scope only.

## What SATUSEHAT-3 GO does and does NOT mean

GO means: dental implementation complete, official mappings locally governed, local
conformance tests pass, production guards implemented, deployed **disabled**.

GO does **NOT** mean: live sandbox pass, production active, Kemkes acceptance, or a
SATUSEHAT-2 GO. **SATUSEHAT-2 remains WATCH**, its GO tag remains **absent**, external
submission stays **disabled**.

## Credential-independent

No `SATUSEHAT_ENABLED`/`SATUSEHAT_SEND_ENABLED` activation, no OAuth, no sandbox/production
call, no external lookup, no live verification. Every SATUSEHAT-3 test is hermetic, uses
`Http::preventStrayRequests()` + `Http::assertNothingSent()`, synthetic data, and no
credential.

## Official documentation audit (2026-07-16)

Source (server-rendered Antora HTML, read directly):
- Playbook: `https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rawat-jalan-gigi/` (**v1.5, 7 Aug 2024**)
- Annex: `https://satusehat.kemkes.go.id/platform/docs/id/terminology/lampiran-terminologi/rawat-jalan-gigi/`

Verified verbatim: FDI→SNOMED bodySite (Lampiran 1, 52 rows), Keadaan Gigi (Lampiran 5),
Restorasi (Lampiran 7), DMF counts (Decayed 251319000 / Missing 251317003 / Filled 251318008,
all `valueString`), parent Observation OC000061 (`valueBoolean`), OC000060 (`valueString`),
Occlusi/Torus/Palatum/Diastema/Anomali codes. Terminology systems: SNOMED CT, LOINC,
`http://terminology.kemkes.go.id/CodeSystem/clinical-term`, HL7 observation-category.

**Not verified (kept `official_mapping_unverified` / needs human sign-off):** payload
instance structure (Postman collection, not fetched); Condition (ICD-10) + Procedure
terminology (delegated to the Resume Medis module); Lampiran 4 surface-row alignment; a
handful of documented source defects (Permukaan Gigi code/display swap, duplicate Lampiran 5
abbreviations, `rct` semantic). **No clinical code is guessed.**

## Coverage matrix

`config/satusehat_dental.php` `coverage[]` is the canonical decision table. Statuses:
`supported` (odontogram examination, DMF D/M/F, other-oral, encounter), `supported_with_mapping`
(per-tooth condition, procedure), `official_mapping_unverified` (occlusion),
`incomplete_local_data` (torus ×2, palatum, diastema, anomaly — only free-text locally),
`unsupported_local_schema` (dental diagnosis Condition, ClinicalImpression), `out_of_scope`
(ServiceRequest).

## Terminology governance

`mst_satusehat_code_mappings` gains `profile_family`, `official_source`,
`official_source_version`, `verified_at`, `verified_by`, `effective_to`, `mapping_confidence`.
A **profile-family** mapping (e.g. `dental`) can only be **ACTIVATED** once it has an official
source **and** a human verification stamp (`SatusehatMappingService::activate` throws
otherwise). `SatusehatDentalMappingSeeder` seeds all official dental codes as **DRAFT** — nothing
is auto-activated; the dental readiness engine reports `dental_mapping_blocked` until a human
verifies + activates. Idempotent + non-destructive.

## Dental readiness + builders + conformance

- `SatusehatDentalSnapshotBuilder` → immutable PII-free `SatusehatDentalSnapshot` from
  `trx_odontograms.tooth_map_payload` (free-text folded to a hash, never stored raw).
- Domain builders: `DentalOdontogramObservationBuilder`, `DentalToothConditionObservationBuilder`,
  `DentalDmfObservationBuilder`, `DentalOtherFindingObservationBuilder`
  (typed, side-effect-free, official codes only, no HTTP). A tooth is only emitted when BOTH its
  FDI bodySite and its condition mapping are active.
- `SatusehatDentalConformanceValidator` — local FHIR structure/cardinality/system check; bans
  attachment/image/handwriting/ktp/nik keys. **NOT** SATUSEHAT acceptance.
- `SatusehatDentalReadinessService` → `dental_ready | dental_incomplete | dental_mapping_blocked
  | dental_unsupported | dental_source_changed | dental_conformance_failed` with structured
  PII-free reasons. Integrated into `SatusehatCandidateService`: a separate dental source hash
  is pinned at approval; **dental drift after approval revokes the approval**.

## Production readiness + activation guard

`SatusehatProductionActivationGuard` (permanent) — production can only ever activate when ALL of:
SATUSEHAT-2 sandbox GO, `production_enabled`, `production_approved`, approval reference,
`environment=production`, credentials, org+location, master+send switches. On SATUSEHAT-3 they
all fail; `Satusehat3ProductionGuardTest` proves production cannot activate.
`SatusehatProductionReadinessService` reports 15 categories (external = blocked/not-started;
internal dental = ready/in-progress) without enabling anything.

## Commands (read-only, no network, PII-free)

`satusehat:dental-profile-audit` (GO/WATCH/NO_GO), `satusehat:dental-readiness {visit}`,
`satusehat:dental-preview {visit}`, `satusehat:production-readiness`,
`satusehat:production-guard-check`.

## UI

Read-only Cakupan Gigi page (`satusehat.dental.coverage`), production-readiness page
(`satusehat.production-readiness`), dental panel + `dental_readiness_status` filter on the
submission workspace, mapping verify form. Every page carries **PREVIEW LOKAL — BELUM DIKIRIM KE
SATUSEHAT** and **SATUSEHAT-2 MASIH WATCH** notices; NIK never rendered.

## Tests

`tests/Feature/Satusehat/Satusehat3*` (39): dental readiness (7), terminology governance (5),
production guard (4), workspace/HTTP/drift/no-network (8 incl. controller), commands (6) + golden
fixtures. Full SATUSEHAT dir 110 passed. RME critical regression preserved.

## Migration

`2026_07_16_120001_extend_satusehat_for_dental_use_case_and_production_readiness` — additive only,
nullable/indexed, no drop/backfill. **Never** `migrate:fresh`/`db:wipe`.

## Deploy (disabled)

`php artisan migrate --force`; `db:seed --class=SatusehatDentalMappingSeeder --force`. Keep
`SATUSEHAT_ENABLED`/`SATUSEHAT_SEND_ENABLED`/production flags **false**. Post-deploy verify
`satusehat:dental-profile-audit`, `satusehat:production-guard-check` (blocked),
`satusehat:production-readiness`.

## Next

**SATUSEHAT-2 External Credential Closure Campaign** — only after the owner obtains official
sandbox credentials.
