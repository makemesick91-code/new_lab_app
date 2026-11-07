<?php

namespace App\Modules\Prescription\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Interfaces\RmePrescriptionRepositoryInterface;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RmePrescriptionService
{
    /** Rule 228 §9 — the audit entity and actions. */
    public const AUDIT_TABLE = 'trx_rme_prescriptions';

    public const AUDIT_CREATED = 'RME_PRESCRIPTION_CREATED';

    public const AUDIT_UPDATED = 'RME_PRESCRIPTION_UPDATED';

    /**
     * The clinical content an audit row names. `updated_by` and the server-derived
     * snapshots are bookkeeping, not an amendment, so they are not listed.
     */
    private const AUDITED_FIELDS = [
        'prescription_date',
        'patient_age_snapshot',
        'allergy_note',
        'pregnant_or_breastfeeding',
        'renal_function_issue',
        'notes',
        'prescription_canvas_path',
        'doctor_signature_canvas_path',
    ];

    public function __construct(
        private readonly RmePrescriptionRepositoryInterface $prescriptions,
        private readonly BranchService $branches,
        private readonly PrescriptionCanvasDecoder $canvasDecoder,
        private readonly ClinicalClock $clock,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly PrescriptionAuthoringAuthority $authority,
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * DMS-RME-REVISION-PROGRAM-8B / D1 — the history list beside the form is a
     * clinical document read too, so it follows the viewer's document scope:
     * the front desk and the cashier see only their working branch's
     * prescriptions. The viewer is required so no caller can fall back to the
     * whole estate by omission.
     */
    public function showDataForVisit(ClinicVisit $clinicVisit, User $viewer): array
    {
        $clinicVisit->loadMissing(['patient', 'doctor', 'branch', 'medicalRecord']);
        $branchIds = $this->workingBranch->clinicalDocumentBranchIdsFor($viewer);

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
     * DMS-RME-REVISION-PROGRAM-8B / D6 (P8R-S3) — the dentist and patient named
     * on the printed prescription.
     *
     * Both used to be free-text request fields, so the drawn doctor signature
     * sat above whatever name an authoring account typed. They now come only
     * from the clinical records: the doctor and patient the visit (or the
     * prescription itself) already points at. A prescription with no doctor is
     * refused rather than printed under an invented or blank name.
     *
     * @return array{doctor_id: int, prescribed_by_name: string, patient_name_snapshot: string}
     */
    private function printedIdentity(?Doctor $doctor, ?Patient $patient): array
    {
        $doctorName = trim((string) $doctor?->name);
        $patientName = trim((string) $patient?->name);

        if ($doctor === null || $doctorName === '') {
            throw ValidationException::withMessages([
                'prescribed_by_name' => 'Dokter kunjungan belum ditetapkan. Resep hanya dapat disimpan atas nama dokter kunjungan.',
            ]);
        }

        if ($patient === null || $patientName === '') {
            throw ValidationException::withMessages([
                'patient_name_snapshot' => 'Data pasien kunjungan tidak ditemukan.',
            ]);
        }

        return [
            'doctor_id' => (int) $doctor->getKey(),
            'prescribed_by_name' => $doctorName,
            'patient_name_snapshot' => $patientName,
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
            'prescription_date' => $this->clock->todayString(),
            'patient_name_snapshot' => $patient?->name ?? '',
            'patient_age_snapshot' => $age !== null ? (string) $age : '',
            'allergy_note' => '',
            'pregnant_or_breastfeeding' => '',
            'renal_function_issue' => '',
        ];
    }

    /**
     * DMS-RME-REVISION-PROGRAM-6 / P6-RX-01..02 — the row and both canvases are
     * one unit. Both canvases are decoded before either is written, the visit
     * row is locked FOR NO KEY UPDATE so a double submit answers the existing
     * "already exists" message instead of racing the UNIQUE(clinic_visit_id)
     * index into an HTTP 500, and any failure removes the canvases this call
     * wrote (a database rollback cannot undo a filesystem write).
     */
    public function create(ClinicVisit $clinicVisit, array $payload, User $user): RmePrescription
    {
        $written = [];
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($clinicVisit, $payload, $user, &$written, &$bodyDone): RmePrescription {
                $prescription = $this->createWithinTransaction($clinicVisit, $payload, $user, $written);
                $bodyDone = true;

                return $prescription;
            });
        } catch (Throwable $e) {
            // Never after the body finished: the COMMIT outcome may be unknown and
            // the row may already reference these canvases (rule 224 §1).
            if (! $bodyDone) {
                $this->discardUncommitted($written);
            }

            throw $e;
        }
    }

    /** @param  list<string>  $written  receives every canvas key this call wrote */
    private function createWithinTransaction(ClinicVisit $clinicVisit, array $payload, User $user, array &$written): RmePrescription
    {
        $this->assertRmeBranch($clinicVisit->branch_id);

        // DMS-RME-REVISION-PROGRAM-8A / P8-N1 — re-assert the authoring authority
        // here as well as in the policy (CG-06): a caller that never passes
        // through the policy is refused before any lock is taken or any canvas
        // is decoded or written.
        $this->authority->assertPermits($user, $clinicVisit);

        // Lock the visit row first, always (rule 223 §1), and decide the
        // completion lock (rule 228 §8) on the row we now hold, not on the copy
        // the request brought: a visit completed by the cashier a moment ago is
        // already closed here.
        $lockedVisit = $this->assertDocumentOpen($this->visits->lockForStatusDecision($clinicVisit->id));

        if ($this->prescriptions->findByClinicVisit($clinicVisit->id) !== null) {
            throw ValidationException::withMessages([
                'clinic_visit_id' => 'Resep untuk kunjungan ini sudah ada. Gunakan Edit Resep.',
            ]);
        }

        // DMS-RME-REVISION-PROGRAM-8B / D6 — the printed identity comes from the
        // visit's own doctor and patient, never from the request. Resolved
        // before either canvas is decoded or written, so a refusal leaves
        // nothing behind.
        // Soft-deleted rows included: a doctor who left the clinic, or an archived
        // patient record, still names the visits they belong to.
        $identity = $this->printedIdentity(
            $clinicVisit->doctor()->withTrashed()->first(),
            $clinicVisit->patient()->withTrashed()->first(),
        );

        // Rule 228 §12 — only the prescription's own doctor signs. Refused before
        // either canvas is decoded, so an account that may not sign never has its
        // signature payload parsed, let alone written.
        $maySign = $this->authority->maySign($user, $identity['doctor_id']);
        $this->assertSignatureAllowed($payload, $maySign);

        $prescriptionBytes = $this->canvasDecoder->decode(
            $payload['prescription_canvas_data'] ?? null,
            'prescription_canvas_data',
        );
        $signatureBytes = $maySign
            ? $this->canvasDecoder->decode($payload['doctor_signature_canvas_data'] ?? null, 'doctor_signature_canvas_data')
            : null;

        if ($prescriptionBytes === null) {
            throw ValidationException::withMessages([
                'prescription_canvas_data' => 'Area R/ wajib diisi.',
            ]);
        }

        // The named doctor still signs what they write themselves, as before. A
        // nurse or supervisor writes the content and leaves the signature to that
        // doctor, who adds it with an update.
        if ($maySign && $signatureBytes === null) {
            throw ValidationException::withMessages([
                'doctor_signature_canvas_data' => 'Tanda tangan dokter wajib diisi.',
            ]);
        }

        $written[] = $prescriptionPath = $this->storeCanvas(
            $clinicVisit,
            'prescription',
            $prescriptionBytes,
        );
        $signaturePath = null;

        if ($signatureBytes !== null) {
            $written[] = $signaturePath = $this->storeCanvas(
                $clinicVisit,
                'signature',
                $signatureBytes,
            );
        }

        $prescription = $this->prescriptions->create([
            'branch_id' => $clinicVisit->branch_id,
            'clinic_visit_id' => $clinicVisit->id,
            'medical_record_id' => $clinicVisit->medicalRecord?->id,
            'patient_id' => $clinicVisit->patient_id,
            'doctor_id' => $identity['doctor_id'],
            'prescribed_by_name' => $identity['prescribed_by_name'],
            'prescription_date' => $payload['prescription_date'],
            'patient_name_snapshot' => $identity['patient_name_snapshot'],
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

        $this->audit(
            $prescription,
            self::AUDIT_CREATED,
            $lockedVisit,
            $this->fieldNames(array_keys(array_filter(
                Arr::only($prescription->getAttributes(), self::AUDITED_FIELDS),
                static fn ($value) => $value !== null && $value !== '',
            ))),
            $user,
        );

        return $prescription;
    }

    /**
     * DMS-RME-REVISION-PROGRAM-6 / P6-RX-01 — both canvases are decoded BEFORE
     * either is written. The base wrote the R/ canvas and then decoded the
     * signature, so a refused signature left an orphaned R/ object behind. A
     * replaced canvas's previous object is never deleted (history, as before).
     */
    public function update(RmePrescription $prescription, array $payload, User $user): RmePrescription
    {
        $written = [];
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($prescription, $payload, $user, &$written, &$bodyDone): RmePrescription {
                $updated = $this->updateWithinTransaction($prescription, $payload, $user, $written);
                $bodyDone = true;

                return $updated;
            });
        } catch (Throwable $e) {
            // Never after the body finished: the COMMIT outcome may be unknown and
            // the row may already reference these canvases (rule 224 §1).
            if (! $bodyDone) {
                $this->discardUncommitted($written);
            }

            throw $e;
        }
    }

    /** @param  list<string>  $written  receives every canvas key this call wrote */
    private function updateWithinTransaction(RmePrescription $prescription, array $payload, User $user, array &$written): RmePrescription
    {
        $this->assertRmeBranch($prescription->branch_id);
        $prescription->loadMissing('clinicVisit');
        $visit = $prescription->clinicVisit;

        // P8-N1 — same re-assertion as create(), before either canvas is written.
        $this->authority->assertPermits($user, $visit);

        // Rule 228 §8 — the completion lock, decided on the locked visit row
        // (update() did not lock the visit before 8C; it now does, first, as
        // create() always has — rule 223 §1).
        $lockedVisit = $this->assertDocumentOpen($this->visits->lockForStatusDecision($visit->id));

        // DMS-RME-REVISION-PROGRAM-8B / D6 — re-derived on every update from the
        // prescription's OWN doctor and patient (the visit's when the row never
        // recorded one), never from the request. Using the prescription's
        // doctor keeps the printed name and `doctor_id` the same person.
        // Soft-deleted rows included, as on create.
        $identity = $this->printedIdentity(
            $prescription->doctor()->withTrashed()->first() ?? $visit?->doctor()->withTrashed()->first(),
            $prescription->patient()->withTrashed()->first() ?? $visit?->patient()->withTrashed()->first(),
        );

        $updates = [
            'doctor_id' => $identity['doctor_id'],
            'prescribed_by_name' => $identity['prescribed_by_name'],
            'prescription_date' => $payload['prescription_date'],
            'patient_name_snapshot' => $identity['patient_name_snapshot'],
            'patient_age_snapshot' => $payload['patient_age_snapshot'] ?? null,
            'allergy_note' => $payload['allergy_note'] ?? null,
            'pregnant_or_breastfeeding' => $payload['pregnant_or_breastfeeding'] ?? null,
            'renal_function_issue' => $payload['renal_function_issue'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'updated_by' => $user->id,
        ];

        // Rule 228 §12 — a signature may only come from the prescription's own
        // doctor; refused before anything is decoded or written. The stored
        // signature is never touched by another account's save.
        $this->assertSignatureAllowed($payload, $this->authority->maySign($user, $identity['doctor_id']));

        // Decode (and so validate) BOTH canvases before writing either.
        $prescriptionBytes = $this->canvasDecoder->decode(
            $payload['prescription_canvas_data'] ?? null,
            'prescription_canvas_data',
        );
        $signatureBytes = $this->canvasDecoder->decode(
            $payload['doctor_signature_canvas_data'] ?? null,
            'doctor_signature_canvas_data',
        );

        if ($prescriptionBytes !== null) {
            $written[] = $updates['prescription_canvas_path'] = $this->storeCanvas(
                $visit,
                'prescription',
                $prescriptionBytes,
                $prescription->clinic_visit_id,
            );
        }

        if ($signatureBytes !== null) {
            $written[] = $updates['doctor_signature_canvas_path'] = $this->storeCanvas(
                $visit,
                'signature',
                $signatureBytes,
                $prescription->clinic_visit_id,
            );
        }

        $changed = $this->changedFields($prescription, $updates);
        $updated = $this->prescriptions->update($prescription, $updates);

        $this->audit($updated, self::AUDIT_UPDATED, $lockedVisit, $changed, $user);

        return $updated;
    }

    /**
     * Rule 228 §8 — refuse when the locked visit is gone or closed. A
     * ValidationException, so the form shows why instead of a bare 403 (the
     * policy refuses the request itself before this is reached).
     */
    private function assertDocumentOpen(?ClinicVisit $locked): ClinicVisit
    {
        if ($locked === null || $this->authority->documentLocked($locked)) {
            throw ValidationException::withMessages([
                'clinic_visit_id' => PrescriptionAuthoringAuthority::LOCKED_MESSAGE,
            ]);
        }

        return $locked;
    }

    /** Rule 228 §12 — a submitted signature from an account that may not sign is refused. */
    private function assertSignatureAllowed(array $payload, bool $maySign): void
    {
        $submitted = $payload['doctor_signature_canvas_data'] ?? null;

        if (! $maySign && $submitted !== null && $submitted !== '') {
            throw ValidationException::withMessages([
                'doctor_signature_canvas_data' => PrescriptionAuthoringAuthority::SIGNATURE_REFUSAL_MESSAGE,
            ]);
        }
    }

    /**
     * The audited fields an update actually changes, compared with the casts
     * applied (a date string equal to the stored date is not a change). Computed
     * on a copy, so the row the repository writes is untouched.
     *
     * @param  array<string, mixed>  $updates
     * @return list<string>
     */
    private function changedFields(RmePrescription $prescription, array $updates): array
    {
        $probe = (clone $prescription)->forceFill(Arr::only($updates, self::AUDITED_FIELDS));

        return $this->fieldNames(array_keys($probe->getDirty()));
    }

    /**
     * Canvas columns are named for what they hold, without the storage `_path`:
     * the audit records THAT a canvas was written, never where.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function fieldNames(array $columns): array
    {
        $names = array_map(static fn (string $column) => preg_replace('/_path$/', '', $column), $columns);
        sort($names);

        return array_values($names);
    }

    /**
     * Rule 228 §9 (OD-8A-2) — one sys_audit_logs row per prescription write, in
     * the same transaction, so a rolled-back write leaves no row and a committed
     * one always has its row. Ids, the visit status, the actor and the NAMES of
     * the changed fields only: no value, no patient data, no storage key.
     *
     * @param  list<string>  $changedFields
     */
    private function audit(RmePrescription $prescription, string $action, ClinicVisit $visit, array $changedFields, User $user): void
    {
        $this->auditLog->log(
            self::AUDIT_TABLE,
            (int) $prescription->getKey(),
            $action,
            null,
            [
                'clinic_visit_id' => (int) $visit->getKey(),
                'visit_status' => (string) $visit->status,
                'changed_fields' => $changedFields,
            ],
            $user,
        );
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

        // DMS-RME-REVISION-PROGRAM-6 / P6-RX-01 — the random suffix makes every
        // write a fresh object. A second-granular key let a refused or failed
        // save in the same second replace the bytes a committed row pointed at.
        $path = sprintf(
            'prescriptions/%d/%d/%s_%s_%s.png',
            $clinicVisit->branch_id,
            $visitId,
            $kind,
            now()->format('YmdHis'),
            bin2hex(random_bytes(8)),
        );

        // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — prescription and signature
        // canvases are clinical evidence and stay on the private disk.
        ClinicalEvidenceStorage::disk()->put($path, $bytes);

        return $path;
    }

    /**
     * Best effort removal of canvases written by a call that did not commit.
     * Their keys are fresh, so no committed row can reference them.
     *
     * @param  list<string>  $keys
     */
    private function discardUncommitted(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                ClinicalEvidenceStorage::disk()->delete($key);
            } catch (Throwable $e) {
                Log::warning('An uncommitted clinical evidence object could not be removed.', [
                    'type' => $e::class,
                ]);
            }
        }
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
