<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Facades\DB;

/**
 * SATUSEHAT-4A — synthetic rehearsal data pack.
 *
 * ISOLATION: everything lives inside the dedicated synthetic branch
 * (config satusehat_data_quality.synthetic.branch_code) — the reset boundary.
 * SAFETY: no real patient, no real NIK, no fabricated remote IHS identifier,
 * no external request. SATUSEHAT mappings created here are keyed to synthetic
 * entity ids only (never a shared code like dental tooth statuses), so real
 * candidates are never affected. Factories are NOT used (VPS runs --no-dev).
 */
class SatusehatSyntheticPilotService
{
    public function __construct(
        private readonly SatusehatCandidateService $candidates,
        private readonly SatusehatDataQualityIssueService $issues,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * Idempotent seed of the synthetic campaign. Returns a summary of ids.
     *
     * @return array<string, mixed>
     */
    public function seed(?User $actor = null): array
    {
        $cfg = (array) config('satusehat_data_quality.synthetic');
        $env = (string) config('satusehat.environment');
        $marker = (string) $cfg['marker'];

        // trx_clinic_visits.created_by + trx_rme_invoices.cashier_id are NOT
        // NULL — resolve a real acting user id (CLI runs pass --actor or fall
        // back to the first user; the VPS always has users).
        $actingUserId = $actor?->id ?? User::query()->orderBy('id')->value('id');
        if ($actingUserId === null) {
            throw new \RuntimeException('Synthetic seed butuh minimal satu user terdaftar.');
        }

        $result = DB::transaction(function () use ($cfg, $env, $marker, $actingUserId) {
            $branch = Branch::withTrashed()->firstOrNew(['code' => $cfg['branch_code']]);
            $branch->fill([
                'name' => $cfg['branch_name'],
                'is_active' => true,
                'is_rme_enabled' => true,
                'is_inventory_enabled' => false,
            ]);
            if ($branch->exists && method_exists($branch, 'trashed') && $branch->trashed()) {
                $branch->restore();
            }
            $branch->save();

            $clinic = Clinic::query()->firstOrCreate(
                ['code' => 'SYN4A-CL'],
                ['name' => $marker.' Klinik Rehearsal', 'is_active' => true],
            );

            $room = ClinicRoom::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'code' => 'SYN4A-R1'],
                ['name' => $marker.' Ruangan Rehearsal', 'type' => ClinicRoom::TYPE_TREATMENT_ROOM, 'status' => ClinicRoom::STATUS_ACTIVE],
            );

            $doctor = Doctor::query()->firstOrCreate(
                ['code' => 'SYN4A-DR'],
                ['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'name' => $marker.' Dokter Rehearsal', 'is_active' => true],
            );

            $patient = Patient::query()->firstOrCreate(
                ['medical_record_number' => 'SYN4A-0001'],
                [
                    'branch_id' => $branch->id,
                    'name' => $marker.' Pasien Rehearsal',
                    'gender' => 'Male',
                    'date_of_birth' => '1990-01-01',
                    'ktp_number' => $cfg['patient_ktp'],
                    'is_active' => true,
                    'registered_at' => now()->toDateString(),
                ],
            );

            $category = TreatmentCategory::query()->firstOrCreate(
                ['code' => 'SYN4A-CAT'],
                ['name' => $marker.' Kategori Rehearsal', 'is_active' => true],
            );

            $treatment = Treatment::query()->firstOrCreate(
                ['code' => 'SYN4A-TRT'],
                [
                    'treatment_category_id' => $category->id,
                    'name' => $marker.' Tindakan Rehearsal',
                    'requires_doctor' => true,
                    'requires_room' => true,
                    'requires_lab' => false,
                    'is_active' => true,
                ],
            );

            // Synthetic master diagnosis: dedicated code system so it never
            // appears in the doctor-facing ACTIVE-only search.
            $diagnosisMaster = ClinicalDiagnosis::query()->firstOrCreate(
                ['code_system' => $cfg['diagnosis_code_system'], 'code' => 'SYN4A-DX'],
                [
                    'display' => $marker.' Diagnosis Rehearsal',
                    'status' => ClinicalDiagnosis::STATUS_SYNTHETIC,
                    'source' => $marker,
                ],
            );

            $visit = ClinicVisit::query()->firstOrCreate(
                ['visit_number' => 'SYN4A-0001'],
                [
                    'branch_id' => $branch->id,
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'clinic_room_id' => $room->id,
                    'visit_date' => now()->toDateString(),
                    'queue_number' => 991,
                    'status' => ClinicVisit::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'created_by' => $actingUserId,
                ],
            );

            $mr = MedicalRecord::query()->firstOrCreate(
                ['clinic_visit_id' => $visit->id],
                [
                    'branch_id' => $branch->id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'notes' => $marker.' catatan rehearsal sintetis (bukan data klinis nyata).',
                    'status' => MedicalRecord::STATUS_FINAL,
                    'finalized_at' => now(),
                ],
            );

            MedicalRecordDiagnosis::query()->firstOrCreate(
                ['medical_record_id' => $mr->id, 'clinical_diagnosis_id' => $diagnosisMaster->id],
                [
                    'clinic_visit_id' => $visit->id,
                    'branch_id' => $branch->id,
                    'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
                    'diagnosed_at' => now(),
                ],
            );

            $invoice = RmeInvoice::query()->firstOrCreate(
                ['invoice_number' => 'SYN4A-INV-0001'],
                [
                    'branch_id' => $branch->id,
                    'clinic_visit_id' => $visit->id,
                    'patient_id' => $patient->id,
                    'medical_record_id' => $mr->id,
                    'cashier_id' => $actingUserId,
                    'status' => RmeInvoice::STATUS_PAID,
                    'subtotal' => 0,
                    'discount_total' => 0,
                    'grand_total' => 0,
                    'notes' => $marker,
                ],
            );

            RmeInvoiceItem::query()->firstOrCreate(
                ['rme_invoice_id' => $invoice->id, 'treatment_id' => $treatment->id],
                ['description' => $marker.' item rehearsal', 'qty' => 1, 'unit_price' => 0, 'discount' => 0, 'subtotal' => 0],
            );

            // Mappings keyed STRICTLY to the synthetic entity ids (env-scoped) —
            // they can never influence a real candidate's readiness.
            SatusehatCodeMapping::query()->firstOrCreate(
                [
                    'environment' => $env,
                    'local_entity_type' => 'treatment',
                    'local_entity_id' => $treatment->id,
                    'target_resource_type' => 'Procedure',
                    'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                ],
                [
                    'terminology_system' => 'http://snomed.info/sct',
                    'target_code' => '2340003',
                    'target_display' => $marker.' prosedur rehearsal',
                    'version' => 1,
                    'effective_date' => now()->toDateString(),
                    'notes' => $marker,
                ],
            );

            SatusehatCodeMapping::query()->firstOrCreate(
                [
                    'environment' => $env,
                    'local_entity_type' => 'diagnosis',
                    'local_entity_id' => $diagnosisMaster->id,
                    'local_code' => 'SYN4A-DX',
                    'target_resource_type' => 'Condition',
                    'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                ],
                [
                    'terminology_system' => 'http://hl7.org/fhir/sid/icd-10',
                    'target_code' => 'Z00.0',
                    'target_display' => $marker.' kondisi rehearsal',
                    'version' => 1,
                    'effective_date' => now()->toDateString(),
                    'notes' => $marker,
                ],
            );

            return [
                'branch_id' => (int) $branch->id,
                'clinic_id' => (int) $clinic->id,
                'clinic_room_id' => (int) $room->id,
                'doctor_id' => (int) $doctor->id,
                'patient_id' => (int) $patient->id,
                'treatment_id' => (int) $treatment->id,
                'clinical_diagnosis_id' => (int) $diagnosisMaster->id,
                'clinic_visit_id' => (int) $visit->id,
                'medical_record_id' => (int) $mr->id,
            ];
        });

        // Candidate generation runs OUTSIDE the seed transaction (idempotent).
        $visit = ClinicVisit::query()->with('medicalRecord')->find($result['clinic_visit_id']);
        $candidate = $visit !== null ? $this->candidates->generateForVisit($visit, $actor) : null;
        if ($candidate !== null) {
            $this->issues->syncForCandidate($candidate, $actor);
            $result['satusehat_candidate_id'] = (int) $candidate->id;
        }

        $this->audit->log(
            'synthetic_campaign',
            $result['clinic_visit_id'],
            SatusehatAuditLog::EVENT_SYNTHETIC_SEEDED,
            'Synthetic rehearsal pack seeded',
            $result,
            $result['branch_id'],
            $actor,
        );

        return $result;
    }

    /**
     * Verify the synthetic pack exists and is intact (read-only).
     *
     * @return array<string, bool>
     */
    public function verify(): array
    {
        $cfg = (array) config('satusehat_data_quality.synthetic');
        $branch = Branch::query()->where('code', $cfg['branch_code'])->first();

        $visit = ClinicVisit::query()->where('visit_number', 'SYN4A-0001')->first();

        return [
            'branch_present' => $branch !== null && (bool) $branch->is_rme_enabled,
            'patient_present' => Patient::query()->where('medical_record_number', 'SYN4A-0001')->exists(),
            'doctor_present' => Doctor::query()->where('code', 'SYN4A-DR')->exists(),
            'visit_present' => $visit !== null,
            'medical_record_final' => $visit !== null && MedicalRecord::query()
                ->where('clinic_visit_id', $visit->id)->where('status', MedicalRecord::STATUS_FINAL)->exists(),
            'structured_diagnosis_present' => $visit !== null && MedicalRecordDiagnosis::query()
                ->where('clinic_visit_id', $visit->id)->exists(),
            'candidate_present' => $visit !== null && SatusehatCandidate::query()
                ->where('clinic_visit_id', $visit->id)->exists(),
        ];
    }

    /**
     * Reset: removes ONLY this campaign's records (the synthetic branch is the
     * isolation boundary). Never touches real data, never uses a destructive
     * schema command. Master reference data (real ICD-10 entries) untouched.
     *
     * @return array<string, int>
     */
    public function reset(?User $actor = null): array
    {
        $cfg = (array) config('satusehat_data_quality.synthetic');
        $branch = Branch::query()->where('code', $cfg['branch_code'])->first();

        if ($branch === null) {
            return ['deleted' => 0];
        }

        $counts = [];

        DB::transaction(function () use ($branch, $cfg, &$counts) {
            $visitIds = ClinicVisit::query()->where('branch_id', $branch->id)->pluck('id');
            $candidateIds = SatusehatCandidate::query()->whereIn('clinic_visit_id', $visitIds)->pluck('id');

            $counts['issues'] = SatusehatDataQualityIssue::query()
                ->whereIn('satusehat_candidate_id', $candidateIds)->delete();
            $counts['candidates'] = SatusehatCandidate::query()->whereKey($candidateIds)->forceDelete();

            $counts['invoice_items'] = RmeInvoiceItem::query()
                ->whereIn('rme_invoice_id', RmeInvoice::query()->whereIn('clinic_visit_id', $visitIds)->select('id'))
                ->delete();
            $counts['invoices'] = RmeInvoice::query()->whereIn('clinic_visit_id', $visitIds)->forceDelete();

            $counts['diagnoses'] = MedicalRecordDiagnosis::query()->whereIn('clinic_visit_id', $visitIds)->forceDelete();
            $counts['medical_records'] = MedicalRecord::query()->whereIn('clinic_visit_id', $visitIds)->forceDelete();
            $counts['visits'] = ClinicVisit::query()->whereKey($visitIds)->forceDelete();

            // Synthetic-only master rows + their mappings.
            $syntheticTreatmentIds = Treatment::query()->where('code', 'SYN4A-TRT')->pluck('id');
            $syntheticDxIds = ClinicalDiagnosis::query()
                ->where('code_system', $cfg['diagnosis_code_system'])->pluck('id');

            $counts['mappings'] = SatusehatCodeMapping::query()
                ->where(function ($q) use ($syntheticTreatmentIds, $syntheticDxIds) {
                    $q->where(fn ($qq) => $qq->where('local_entity_type', 'treatment')->whereIn('local_entity_id', $syntheticTreatmentIds))
                        ->orWhere(fn ($qq) => $qq->where('local_entity_type', 'diagnosis')->whereIn('local_entity_id', $syntheticDxIds));
                })->forceDelete();

            $counts['treatments'] = Treatment::query()->whereKey($syntheticTreatmentIds)->forceDelete();
            $counts['treatment_categories'] = TreatmentCategory::query()->where('code', 'SYN4A-CAT')->forceDelete();
            $counts['clinical_diagnoses'] = ClinicalDiagnosis::query()->whereKey($syntheticDxIds)->forceDelete();
            $counts['patients'] = Patient::query()->where('branch_id', $branch->id)->forceDelete();
            $counts['doctors'] = Doctor::query()->where('code', 'SYN4A-DR')->forceDelete();
            $counts['rooms'] = ClinicRoom::query()->where('branch_id', $branch->id)->forceDelete();

            // The branch itself is deactivated + soft-deleted (kept for audit FK integrity).
            $branch->update(['is_active' => false, 'is_rme_enabled' => false]);
            $branch->delete();
            $counts['branch'] = 1;
        });

        $this->audit->log(
            'synthetic_campaign',
            (int) $branch->id,
            SatusehatAuditLog::EVENT_SYNTHETIC_RESET,
            'Synthetic rehearsal pack reset',
            $counts,
            (int) $branch->id,
            $actor,
        );

        return $counts;
    }
}
