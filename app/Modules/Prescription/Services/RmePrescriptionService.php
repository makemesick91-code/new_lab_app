<?php

namespace App\Modules\Prescription\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Interfaces\RmePrescriptionRepositoryInterface;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RmePrescriptionService
{
    public function __construct(
        private readonly RmePrescriptionRepositoryInterface $prescriptions,
        private readonly BranchService $branches,
        private readonly PrescriptionCanvasDecoder $canvasDecoder,
    ) {}

    public function showDataForVisit(ClinicVisit $clinicVisit): array
    {
        $clinicVisit->loadMissing(['patient', 'doctor', 'branch', 'medicalRecord']);
        $branchIds = $this->branches->rmeEnabledIds();

        $prescription = $this->prescriptions->findByClinicVisit($clinicVisit->id);
        $history = $this->prescriptions->historyForPatient(
            $clinicVisit->patient_id,
            $branchIds,
            $clinicVisit->id,
        );

        return [
            'prescription' => $prescription,
            'defaults' => $this->buildDefaults($clinicVisit),
            'history' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDefaults(ClinicVisit $clinicVisit): array
    {
        $patient = $clinicVisit->patient;
        $age = $patient?->age();

        return [
            'prescribed_by_name' => $clinicVisit->doctor?->name ?? '',
            'prescription_date' => now()->toDateString(),
            'patient_name_snapshot' => $patient?->name ?? '',
            'patient_age_snapshot' => $age !== null ? (string) $age : '',
            'allergy_note' => '',
            'pregnant_or_breastfeeding' => '',
            'renal_function_issue' => '',
        ];
    }

    public function create(ClinicVisit $clinicVisit, array $payload, User $user): RmePrescription
    {
        return DB::transaction(function () use ($clinicVisit, $payload, $user) {
            $this->assertRmeBranch($clinicVisit->branch_id);

            if ($this->prescriptions->findByClinicVisit($clinicVisit->id) !== null) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Resep untuk kunjungan ini sudah ada. Gunakan Edit Resep.',
                ]);
            }

            $prescriptionBytes = $this->canvasDecoder->decode(
                $payload['prescription_canvas_data'] ?? null,
                'prescription_canvas_data',
            );
            $signatureBytes = $this->canvasDecoder->decode(
                $payload['doctor_signature_canvas_data'] ?? null,
                'doctor_signature_canvas_data',
            );

            if ($prescriptionBytes === null || $signatureBytes === null) {
                throw ValidationException::withMessages([
                    'prescription_canvas_data' => 'Area R/ dan tanda tangan dokter wajib diisi.',
                ]);
            }

            $prescriptionPath = $this->storeCanvas(
                $clinicVisit,
                'prescription',
                $prescriptionBytes,
            );
            $signaturePath = $this->storeCanvas(
                $clinicVisit,
                'signature',
                $signatureBytes,
            );

            return $this->prescriptions->create([
                'branch_id' => $clinicVisit->branch_id,
                'clinic_visit_id' => $clinicVisit->id,
                'medical_record_id' => $clinicVisit->medicalRecord?->id,
                'patient_id' => $clinicVisit->patient_id,
                'doctor_id' => $clinicVisit->doctor_id,
                'prescribed_by_name' => $payload['prescribed_by_name'],
                'prescription_date' => $payload['prescription_date'],
                'patient_name_snapshot' => $payload['patient_name_snapshot'],
                'patient_age_snapshot' => $payload['patient_age_snapshot'] ?? null,
                'allergy_note' => $payload['allergy_note'] ?? null,
                'pregnant_or_breastfeeding' => $payload['pregnant_or_breastfeeding'] ?? null,
                'renal_function_issue' => $payload['renal_function_issue'] ?? null,
                'prescription_canvas_path' => $prescriptionPath,
                'doctor_signature_canvas_path' => $signaturePath,
                'notes' => $payload['notes'] ?? null,
                'status' => RmePrescription::STATUS_DRAFT,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }

    public function update(RmePrescription $prescription, array $payload, User $user): RmePrescription
    {
        return DB::transaction(function () use ($prescription, $payload, $user) {
            $this->assertRmeBranch($prescription->branch_id);
            $prescription->loadMissing('clinicVisit');
            $visit = $prescription->clinicVisit;

            $updates = [
                'prescribed_by_name' => $payload['prescribed_by_name'],
                'prescription_date' => $payload['prescription_date'],
                'patient_name_snapshot' => $payload['patient_name_snapshot'],
                'patient_age_snapshot' => $payload['patient_age_snapshot'] ?? null,
                'allergy_note' => $payload['allergy_note'] ?? null,
                'pregnant_or_breastfeeding' => $payload['pregnant_or_breastfeeding'] ?? null,
                'renal_function_issue' => $payload['renal_function_issue'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'updated_by' => $user->id,
            ];

            $prescriptionBytes = $this->canvasDecoder->decode(
                $payload['prescription_canvas_data'] ?? null,
                'prescription_canvas_data',
            );
            if ($prescriptionBytes !== null) {
                $updates['prescription_canvas_path'] = $this->storeCanvas(
                    $visit,
                    'prescription',
                    $prescriptionBytes,
                    $prescription->clinic_visit_id,
                );
            }

            $signatureBytes = $this->canvasDecoder->decode(
                $payload['doctor_signature_canvas_data'] ?? null,
                'doctor_signature_canvas_data',
            );
            if ($signatureBytes !== null) {
                $updates['doctor_signature_canvas_path'] = $this->storeCanvas(
                    $visit,
                    'signature',
                    $signatureBytes,
                    $prescription->clinic_visit_id,
                );
            }

            return $this->prescriptions->update($prescription, $updates);
        });
    }

    public function markPrinted(RmePrescription $prescription): RmePrescription
    {
        if ($prescription->printed_at === null) {
            return $this->prescriptions->update($prescription, [
                'printed_at' => now(),
            ]);
        }

        return $prescription;
    }

    /**
     * @return Collection<int, RmePrescription>
     */
    public function patientHistory(int $patientId, array $branchIds, ?int $excludeVisitId = null): Collection
    {
        return $this->prescriptions->historyForPatient($patientId, $branchIds, $excludeVisitId);
    }

    private function storeCanvas(
        ClinicVisit $clinicVisit,
        string $kind,
        string $bytes,
        ?int $visitId = null,
    ): string {
        $visitId ??= $clinicVisit->id;
        $path = sprintf(
            'prescriptions/%d/%d/%s_%s.png',
            $clinicVisit->branch_id,
            $visitId,
            $kind,
            now()->format('YmdHis'),
        );

        // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — prescription and signature
        // canvases are clinical evidence and stay on the private disk.
        ClinicalEvidenceStorage::disk()->put($path, $bytes);

        return $path;
    }

    private function assertRmeBranch(?int $branchId): void
    {
        if ($branchId === null || ! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Kunjungan tidak berada di cabang RME aktif.',
            ]);
        }
    }
}
