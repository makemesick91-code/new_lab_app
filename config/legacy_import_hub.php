<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — the legacy import contract
|--------------------------------------------------------------------------
|
| Three legacy importers already existed independently, each with its own
| module, permissions, storage and lifecycle:
|
|   LEGACY_PATIENT     Sprint 62.3      CSV master-data staging → preview → commit
|   LEGACY_RME         LEGACY-RME-PDF-* scanned RME PDF → review → publish
|   LEGACY_ODONTOGRAM  FIX-04b          scanned odontogram chart → review → publish
|
| This file adds what none of them could express alone: ONE ceiling with the
| SAME meaning across all three, and one registry naming where each capability
| lives so the hub page and the sidebar cannot drift from the routes.
|
| THE CEILING, EXACTLY.
|
|   100 ACCEPTED records, per BRANCH, per CLINICAL DAY, per IMPORT TYPE.
|
| Each word is load-bearing:
|
|   ACCEPTED — one unit is consumed when a record is admitted into its
|     canonical store, inside the same transaction that writes it. A refused
|     upload, a failed validation, a duplicate, an error row or a rolled-back
|     transaction consumes nothing, because the increment dies with the write it
|     is counting. A retry re-queues work for a record that was already accepted
|     and already charged, so it is never charged twice.
|
|   BRANCH — the branch the record is charged to is always SERVER-RESOLVED. For
|     Legacy RME and Legacy Odontogram it is derived from the branch-code
|     segment of the patient's Nomor RM; for Legacy Patient it is the branch the
|     row's `Cabang` column resolved to, which is required and strict. A
|     request-supplied branch is never the authority.
|
|   CLINICAL DAY — ClinicalClock, the same clinical calendar the legacy date
|     rules and the ROLL-4 wave quota already use. Never `config('app.timezone')`
|     and never the host's wall clock: those resolve to UTC in production, which
|     rolls the day over at 08:00 WITA, in the middle of a working morning.
|
|   IMPORT TYPE — the three types are counted SEPARATELY. A branch that has
|     archived 100 RME documents today may still archive 100 odontogram charts
|     and admit 100 patients. They are different work, done by different people,
|     with different downstream cost.
|
| THIS CEILING COMPOSES; IT NEVER WIDENS.
|
| Legacy RME keeps its ROLL-4 wave/branch quota untouched. That counter answers
| a question this one cannot express — how much may this WAVE take today across
| every branch enrolled in it — and deleting it to "consolidate" would remove a
| governance control. Both are evaluated; either may refuse; neither can turn
| the other's refusal into an acceptance. Lock ordering is fixed at the single
| call site (hub bucket first, then the wave buckets) so the two can never form
| a cycle.
|
| NULL IS NOT ZERO. A NULL ceiling declines to limit; a ceiling of 0 admits
| nothing. The same distinction the ROLL-4 quota draws, drawn the same way, so
| an operator reading either surface reads one vocabulary.
|
| PII POLICY. Everything this file governs, everything the hub service returns
| and everything the hub page renders is counts, limits, labels and route names.
| Never a patient name, a Nomor RM, a KTP/NIK, a filename or a document path.
*/

/*
| Read an optional integer environment value at CONFIG-BUILD time (the ROLL-1
| capture rule: env() is read only while this file is built, so the value
| survives `config:cache` and nothing reads the environment at runtime).
|
| Blank or unset yields the canonical default rather than NULL: unlike the
| ROLL-4 wave quota, which is genuinely optional, the hub ceiling is the
| contract. An operator who wants "no ceiling" must say so explicitly by
| setting the value to `none`.
*/
$legacyImportHubLimit = static function (mixed $value, int $default): ?int {
    if ($value === null || $value === '' || $value === false) {
        return $default;
    }

    if (is_string($value) && in_array(strtolower(trim($value)), ['none', 'null', 'unlimited'], true)) {
        return null;
    }

    return max(0, (int) $value);
};

$legacyImportHubDefaultLimit = (int) env('LEGACY_IMPORT_HUB_DAILY_LIMIT', 100);

return [

    'sprint' => 'FEATURE-LEGACY-IMPORT-HUB-1',

    /*
    | The hub landing page. Off would hide the page, never the capabilities:
    | each importer keeps its own route, its own permissions and its own flag,
    | so turning the hub off removes a convenience, not a control.
    */
    'enabled' => (bool) env('LEGACY_IMPORT_HUB_ENABLED', true),

    /*
    | The canonical daily ceiling per import type.
    |
    | The upper bound exists for the same reason ROLL-4's does: a ceiling that
    | anyone may set to 1,000,000 is decoration. A configured value above it is
    | clamped, and the clamp is reported by the hub rather than applied silently.
    */
    'daily_limit' => [
        'legacy_patient' => $legacyImportHubLimit(env('LEGACY_IMPORT_PATIENT_DAILY_LIMIT'), $legacyImportHubDefaultLimit),
        'legacy_rme' => $legacyImportHubLimit(env('LEGACY_IMPORT_RME_DAILY_LIMIT'), $legacyImportHubDefaultLimit),
        'legacy_odontogram' => $legacyImportHubLimit(env('LEGACY_IMPORT_ODONTOGRAM_DAILY_LIMIT'), $legacyImportHubDefaultLimit),
    ],

    'max_declarable_daily' => (int) env('LEGACY_IMPORT_HUB_MAX_DECLARABLE_DAILY', 500),

    /*
    | INVARIANTS, NOT TOGGLES. Each of these is enforced in code and pinned by a
    | test. They are recorded here so a reader auditing the contract does not
    | have to reconstruct it from three modules.
    */
    'invariants' => [
        // The unit is an accepted record, never an HTTP request.
        'unit_is_accepted_record' => true,
        // The reservation shares the transaction that writes the record.
        'reservation_is_transactional' => true,
        // The bucket is locked FOR UPDATE before the decision is taken.
        'reservation_takes_row_lock' => true,
        // A retry of an already-accepted record is never charged again.
        'retry_never_double_charges' => true,
        // A rejected record consumes nothing.
        'rejected_record_consumes_nothing' => true,
        // The branch is server-resolved; a request-supplied branch is ignored.
        'branch_is_server_resolved' => true,
        // The day boundary is ClinicalClock's, not the host's.
        'clinical_day_from_clinical_clock' => true,
        // Types are counted separately.
        'types_counted_separately' => true,
        // The hub ceiling never widens an importer's own gates.
        'hub_ceiling_only_narrows' => true,
    ],

    /*
    | The capability registry.
    |
    | ONE PLACE, so the hub page, the sidebar and the tests all read the same
    | route names and the same permissions. `feature_flag` is null for Legacy
    | Patient because that capability has never had one — its availability is
    | its permission, and inventing a flag for symmetry would add a way to break
    | a working surface.
    |
    | `permissions` is the set that makes the capability REACHABLE at all (the
    | union the route group's middleware accepts). `create_permission` is the
    | one that makes it USABLE for new work, which is what the hub reports.
    */
    'types' => [

        'legacy_patient' => [
            'label' => 'Legacy Pasien',
            'description' => 'Impor data master pasien lama dari berkas CSV, melalui staging dan pratinjau sebelum commit.',
            'index_route' => 'settings.patients.import.index',
            'create_route' => 'settings.patients.import.index',
            'permissions' => ['manage patients'],
            'create_permission' => 'manage patients',
            'feature_flag' => null,
            'unit' => 'baris pasien yang di-commit',
        ],

        'legacy_rme' => [
            'label' => 'Legacy RME',
            'description' => 'Arsip dokumen rekam medis lama (PDF hasil pindai), melalui review sebelum publish.',
            'index_route' => 'settings.rme.legacy-imports.index',
            'create_route' => 'settings.rme.legacy-imports.create',
            'permissions' => ['view_legacy_rme_imports', 'create_legacy_rme_imports'],
            'create_permission' => 'create_legacy_rme_imports',
            'feature_flag' => 'rme.legacy_pdf_archive',
            'unit' => 'dokumen yang diterima ke staging',
        ],

        'legacy_odontogram' => [
            'label' => 'Legacy Odontogram',
            'description' => 'Arsip kartu odontogram lama (PDF hasil pindai), melalui review sebelum publish.',
            'index_route' => 'settings.rme.legacy-odontograms.index',
            'create_route' => 'settings.rme.legacy-odontograms.create',
            'permissions' => ['view_legacy_odontogram_imports', 'create_legacy_odontogram_imports'],
            'create_permission' => 'create_legacy_odontogram_imports',
            'feature_flag' => 'rme.legacy_odontogram_archive',
            'unit' => 'dokumen yang diterima ke staging',
        ],
    ],
];
