<?php

namespace App\Modules\PatientMerge\Support;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — every column that says
 * "this row belongs to patient X", and what a merge does to it.
 *
 * WHY A REGISTRY AND NOT `UPDATE everything SET patient_id = …`.
 * Each domain was audited on its own. The registry is that audit written down,
 * and it is checked against the LIVE schema before every merge: a foreign key
 * to mst_patients that is not listed here — a table added next year — makes
 * the merge refuse ({@see PatientMergeOwnershipRepository::unregisteredPatientForeignKeys}),
 * because silently leaving rows behind on a merged patient is exactly the
 * data loss this module exists to prevent.
 *
 * Every entry is REASSIGNED: ownership moves, content does not. No row is
 * deleted, renumbered, merged with another row or re-posted. Invoices keep
 * their number, amount, status and timestamps; payments keep theirs; an RME
 * keeps its visit; a legacy archive stays legacy and keeps the branch and
 * source Nomor RM it was filed under.
 *
 * `count` groups feed the before/after integrity check and the preview.
 */
final class PatientOwnedRecordRegistry
{
    /**
     * @var array<string, array{column: string, label: string, group: string}>
     */
    public const REASSIGN = [
        'trx_clinic_visits' => ['column' => 'patient_id', 'label' => 'Kunjungan', 'group' => 'visits'],
        'trx_medical_records' => ['column' => 'patient_id', 'label' => 'RME', 'group' => 'medical_records'],
        'trx_rme_invoices' => ['column' => 'patient_id', 'label' => 'Invoice', 'group' => 'invoices'],
        'trx_rme_payments' => ['column' => 'patient_id', 'label' => 'Pembayaran', 'group' => 'payments'],
        'trx_rme_prescriptions' => ['column' => 'patient_id', 'label' => 'Resep', 'group' => 'prescriptions'],
        'trx_rme_prescription_whatsapp_deliveries' => ['column' => 'patient_id', 'label' => 'Pengiriman Resep WhatsApp', 'group' => 'prescriptions'],
        'trx_rme_visit_consents' => ['column' => 'patient_id', 'label' => 'Persetujuan Tindakan', 'group' => 'consents'],
        'mst_patient_documents' => ['column' => 'patient_id', 'label' => 'Dokumen Pasien', 'group' => 'documents'],
        'trx_lab_case_candidates' => ['column' => 'patient_id', 'label' => 'Kandidat Lab', 'group' => 'lab'],
        'trx_lab_orders' => ['column' => 'patient_id', 'label' => 'Order Lab', 'group' => 'lab'],
        'trx_rme_legacy_records' => ['column' => 'patient_id', 'label' => 'Arsip RME Lama', 'group' => 'legacy_rme'],
        'stg_rme_legacy_imports' => ['column' => 'patient_id', 'label' => 'Impor RME Lama', 'group' => 'legacy_rme_imports'],
        'trx_odontogram_legacy_records' => ['column' => 'patient_id', 'label' => 'Arsip Odontogram Lama', 'group' => 'legacy_odontogram'],
        'stg_odontogram_legacy_imports' => ['column' => 'patient_id', 'label' => 'Impor Odontogram Lama', 'group' => 'legacy_odontogram_imports'],
        'stg_legacy_batch_review_decisions' => ['column' => 'patient_id', 'label' => 'Keputusan Batch Review', 'group' => 'legacy_staging'],
        'stg_legacy_review_triage' => ['column' => 'patient_id', 'label' => 'Triage Batch Review', 'group' => 'legacy_staging'],
        'stg_legacy_batch_publish_items' => ['column' => 'patient_id', 'label' => 'Item Batch Publish', 'group' => 'legacy_staging'],
        'stg_legacy_mass_upload_items' => ['column' => 'resolved_patient_id', 'label' => 'Item Mass Upload', 'group' => 'legacy_staging'],
        'trx_satusehat_candidates' => ['column' => 'patient_id', 'label' => 'Kandidat SATUSEHAT', 'group' => 'satusehat'],
        'trx_satusehat_data_quality_issues' => ['column' => 'patient_id', 'label' => 'Isu Kualitas Data SATUSEHAT', 'group' => 'satusehat'],
        // Special: two ACTIVE assignments of the same doctor would violate the
        // partial unique index; the source's duplicate is closed (not deleted)
        // before ownership moves. See PatientMergeExecutionService.
        'trx_rme_patient_doctor_assignments' => ['column' => 'patient_id', 'label' => 'Penugasan Dokter', 'group' => 'doctor_assignments'],
    ];

    /**
     * Patient references that are deliberately LEFT AS THEY ARE.
     *
     * @var array<string, string> "table.column" => reason
     */
    public const PRESERVE = [
        'mst_patients.merged_into_patient_id' => 'The merge pointer itself.',
        'mst_patient_rm_aliases.source_patient_id' => 'Records which patient the old Nomor RM was issued to.',
        'mst_patient_rm_aliases.canonical_patient_id' => 'Maintained by the merge; repointed when the canonical patient is merged later.',
        'trx_patient_merge_cases.patient_a_id' => 'Merge case provenance.',
        'trx_patient_merge_cases.patient_b_id' => 'Merge case provenance.',
        'trx_patient_merge_cases.canonical_patient_id' => 'Merge case provenance.',
        'trx_patient_merge_cases.source_patient_id' => 'Merge case provenance.',
        // No foreign key: import evidence of which row a legacy CSV created.
        'stg_legacy_patient_imports.committed_patient_id' => 'Legacy patient import provenance.',
    ];

    /** Groups that must be conserved exactly: canonical_after == A + B. */
    public const CONSERVED_GROUPS = [
        'visits', 'medical_records', 'invoices', 'payments', 'prescriptions', 'consents',
        'documents', 'lab', 'legacy_rme', 'legacy_rme_imports', 'legacy_odontogram',
        'legacy_odontogram_imports', 'legacy_staging', 'satusehat', 'doctor_assignments',
    ];

    /** The groups an operator sees in the preview, in reading order. */
    public const PREVIEW_GROUPS = [
        'visits' => 'Kunjungan',
        'medical_records' => 'RME',
        'odontograms' => 'Odontogram',
        'invoices' => 'Invoice',
        'payments' => 'Pembayaran',
        'receivables' => 'Piutang Aktif',
        'documents' => 'Dokumen',
        'legacy_rme' => 'Arsip RME Lama',
        'legacy_odontogram' => 'Arsip Odontogram Lama',
        'prescriptions' => 'Resep',
        'consents' => 'Persetujuan Tindakan',
        'lab' => 'Lab',
    ];

    /** @return array<int, string> "table.column" for every reassigned reference. */
    public static function reassignedReferences(): array
    {
        return array_map(
            static fn (string $table, array $spec): string => $table.'.'.$spec['column'],
            array_keys(self::REASSIGN),
            self::REASSIGN,
        );
    }

    /** @return array<int, string> */
    public static function knownReferences(): array
    {
        return array_merge(self::reassignedReferences(), array_keys(self::PRESERVE));
    }
}
