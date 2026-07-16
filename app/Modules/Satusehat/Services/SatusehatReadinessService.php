<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Support\SatusehatReadinessResult;
use Carbon\CarbonInterface;

/**
 * Readiness engine. Evaluates a completed visit against the SATUSEHAT-1 hard
 * gates and produces a status (ready|incomplete|blocked), structured PII-free
 * reasons, and the deterministic clinical source facts used for hashing.
 *
 * It NEVER performs any external request and NEVER emits a full NIK.
 */
class SatusehatReadinessService
{
    /** @var list<array{code: string, severity: string, message: string}> */
    private array $reasons = [];

    public function __construct(
        private readonly SatusehatMappingRepositoryInterface $mappings,
        private readonly SatusehatIdentifierRepositoryInterface $identifiers,
        private readonly BranchService $branches,
    ) {}

    public function evaluate(ClinicVisit $visit): SatusehatReadinessResult
    {
        $this->reasons = [];
        $env = (string) config('satusehat.environment');

        $visit->loadMissing(['patient', 'doctor', 'branch', 'clinicRoom', 'medicalRecord']);
        $patient = $visit->patient;
        $doctor = $visit->doctor;
        $mr = $visit->medicalRecord;
        $treatments = $this->collectTreatments($visit, $env);
        $diagnoses = $this->collectDiagnoses($visit, $env);

        // G1 — visit not cancelled.
        if ($visit->status === ClinicVisit::STATUS_CANCELLED) {
            $this->blocked('visit_cancelled', 'Kunjungan dibatalkan — tidak dapat dikirim.');
        }

        // G2 — visit completed.
        if ($visit->status !== ClinicVisit::STATUS_COMPLETED) {
            $this->incomplete('visit_not_completed', 'Kunjungan belum berstatus selesai.');
        }

        // G3 — medical record final.
        if ($mr === null) {
            $this->incomplete('medical_record_missing', 'Rekam medis belum dibuat.');
        } elseif ($mr->status !== MedicalRecord::STATUS_FINAL) {
            $this->incomplete('medical_record_not_final', 'Rekam medis belum difinalisasi.');
        }

        // G4 — relation consistency.
        if ($patient === null || $visit->patient_id === null) {
            $this->blocked('relation_missing_patient', 'Relasi pasien tidak konsisten.');
        }
        if ($mr !== null && (int) $mr->clinic_visit_id !== (int) $visit->id) {
            $this->blocked('relation_mismatch_medical_record', 'Rekam medis tidak sesuai dengan kunjungan.');
        }
        if (! in_array((int) $visit->branch_id, $this->branches->rmeEnabledIds(), true)) {
            $this->blocked('branch_not_rme', 'Cabang kunjungan bukan cabang RME aktif.');
        }

        // G5 — minimum patient identity.
        if ($patient !== null) {
            $missing = [];
            if (blank($patient->name)) {
                $missing[] = 'name';
            }
            if (blank($patient->date_of_birth)) {
                $missing[] = 'date_of_birth';
            }
            if (blank($patient->gender)) {
                $missing[] = 'gender';
            }
            if ($missing !== []) {
                $this->incomplete('patient_identity_incomplete', 'Data identitas minimum pasien belum lengkap.');
            }
        }

        // G6 — Patient IHS identifier.
        $patientIdentifier = $patient
            ? $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PATIENT, 'patient', (int) $patient->id)
            : null;
        if ($patientIdentifier === null) {
            $this->incomplete('patient_ihs_missing', 'IHS Patient identifier belum tersedia untuk lingkungan ini.');
        }

        // G7 — Practitioner IHS identifier.
        if ($doctor === null) {
            $this->incomplete('practitioner_missing', 'Dokter penanggung jawab belum tersedia.');
            $practitionerIdentifier = null;
        } else {
            $practitionerIdentifier = $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PRACTITIONER, 'doctor', (int) $doctor->id);
            if ($practitionerIdentifier === null) {
                $this->incomplete('practitioner_ihs_missing', 'IHS Practitioner identifier belum tersedia.');
            }
        }

        // G8 — Organization identifier (branch).
        $organizationIdentifier = $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_ORGANIZATION, 'branch', (int) $visit->branch_id);
        if ($organizationIdentifier === null) {
            $this->incomplete('organization_ihs_missing', 'IHS Organization identifier cabang belum tersedia.');
        }

        // G9 — Location identifier (clinic room).
        if ($visit->clinic_room_id === null) {
            $this->incomplete('location_missing', 'Ruangan perawatan belum ditetapkan.');
            $locationIdentifier = null;
        } else {
            $locationIdentifier = $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_LOCATION, 'clinic_room', (int) $visit->clinic_room_id);
            if ($locationIdentifier === null) {
                $this->incomplete('location_ihs_missing', 'IHS Location identifier ruangan belum tersedia.');
            }
        }

        // G10 — structured diagnosis + Condition mapping (SATUSEHAT-4A).
        // Handwriting RM stays primary; a record without an explicit structured
        // diagnosis is surfaced as informational — Condition is NEVER fabricated
        // from free text. When structured diagnoses exist, each needs an ACTIVE,
        // clinically reviewed Condition mapping.
        if ($diagnoses === []) {
            $this->info('diagnosis_not_structured', 'Diagnosis terstruktur belum tersedia — resource Condition tidak dibuat pada tahap ini.');
        } else {
            $unmappedDx = collect($diagnoses)->filter(fn (array $d) => $d['mapping_code'] === null)->count();
            if ($unmappedDx > 0) {
                $this->incomplete('diagnosis_mapping_missing', "Terdapat {$unmappedDx} diagnosis tanpa mapping Condition SATUSEHAT aktif.");
            }
        }

        // G11 — treatment/procedure mapping.
        $unmapped = collect($treatments)->filter(fn (array $t) => $t['mapping_code'] === null)->count();
        if ($unmapped > 0) {
            $this->incomplete('treatment_mapping_missing', "Terdapat {$unmapped} tindakan tanpa mapping SATUSEHAT aktif.");
        }

        // G12 — timestamp normalizable to UTC.
        if (blank($visit->visit_date)) {
            $this->incomplete('timestamp_unnormalizable', 'Tanggal kunjungan tidak dapat dinormalisasi ke UTC.');
        }

        // G13 — local preview shape (Encounter requires patient + org + period).
        if ($patientIdentifier === null || $organizationIdentifier === null || blank($visit->visit_date)) {
            $this->info('preview_incomplete', 'Preview Encounter belum dapat dibentuk secara lengkap.');
        }

        $facts = $this->buildFacts($visit, $treatments, $env, [
            'patient' => $patientIdentifier?->remote_identifier,
            'practitioner' => $practitionerIdentifier?->remote_identifier,
            'organization' => $organizationIdentifier?->remote_identifier,
            'location' => $locationIdentifier?->remote_identifier,
        ], $diagnoses);

        return new SatusehatReadinessResult($this->rollup(), $this->reasons, $facts);
    }

    /**
     * Collect billed treatments (procedures) for the visit + their active
     * Procedure mapping. Read-only.
     *
     * @return list<array{treatment_id: int, code: ?string, qty: int, mapping_code: ?string, mapping_version: ?int}>
     */
    private function collectTreatments(ClinicVisit $visit, string $env): array
    {
        $invoices = RmeInvoice::query()
            ->where('clinic_visit_id', $visit->id)
            ->with('items.treatment')
            ->get();

        $rows = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                if ($item->treatment_id === null) {
                    continue;
                }
                $mapping = $this->mappings->findActive($env, 'treatment', (int) $item->treatment_id, null, SatusehatSubmissionItem::RESOURCE_PROCEDURE);
                $rows[(int) $item->treatment_id] = [
                    'treatment_id' => (int) $item->treatment_id,
                    'code' => $item->treatment?->code ?? null,
                    'qty' => (int) ($item->qty ?? 1),
                    'mapping_code' => $mapping?->target_code,
                    'mapping_version' => $mapping?->version,
                ];
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Collect structured diagnoses for the visit's medical record + their
     * ACTIVE Condition mapping. Read-only; empty for legacy records.
     *
     * @return list<array{diagnosis_id: int, code: ?string, role: string, mapping_code: ?string, mapping_version: ?int}>
     */
    private function collectDiagnoses(ClinicVisit $visit, string $env): array
    {
        $mr = $visit->medicalRecord;
        if ($mr === null) {
            return [];
        }

        $rows = [];
        $diagnoses = MedicalRecordDiagnosis::query()
            ->where('medical_record_id', $mr->id)
            ->with('clinicalDiagnosis:id,code_system,code,display,status')
            ->orderBy('id')
            ->get();

        foreach ($diagnoses as $dx) {
            $master = $dx->clinicalDiagnosis;
            if ($master === null) {
                continue;
            }
            $mapping = $this->mappings->findActive($env, 'diagnosis', (int) $master->id, (string) $master->code, SatusehatSubmissionItem::RESOURCE_CONDITION);
            $rows[] = [
                'diagnosis_id' => (int) $master->id,
                'code' => $master->code !== null ? (string) $master->code : null,
                'role' => (string) $dx->diagnosis_role,
                'mapping_code' => $mapping?->target_code,
                'mapping_version' => $mapping?->version,
            ];
        }

        return $rows;
    }

    /**
     * Deterministic clinical fingerprint input. NIK is hashed, never included raw.
     *
     * @param  list<array<string, mixed>>  $treatments
     * @param  array<string, ?string>  $identifiers
     * @param  list<array<string, mixed>>  $diagnoses
     * @return array<string, mixed>
     */
    private function buildFacts(ClinicVisit $visit, array $treatments, string $env, array $identifiers, array $diagnoses = []): array
    {
        $patient = $visit->patient;
        $mr = $visit->medicalRecord;

        return [
            'environment' => $env,
            'visit' => [
                'id' => (int) $visit->id,
                'visit_number' => $visit->visit_number,
                'visit_date' => $this->dateString($visit->visit_date),
                'status' => $visit->status,
                'doctor_id' => $visit->doctor_id !== null ? (int) $visit->doctor_id : null,
                'branch_id' => (int) $visit->branch_id,
                'clinic_room_id' => $visit->clinic_room_id !== null ? (int) $visit->clinic_room_id : null,
            ],
            'patient' => $patient ? [
                'id' => (int) $patient->id,
                'name' => $patient->name,
                'date_of_birth' => $this->dateString($patient->date_of_birth),
                'gender' => $patient->gender,
                // NIK is fingerprinted, never stored raw here.
                'ktp_hash' => filled($patient->ktp_number) ? hash('sha256', (string) $patient->ktp_number) : null,
            ] : null,
            'medical_record' => $mr ? [
                'id' => (int) $mr->id,
                'status' => $mr->status,
                'finalized_at' => $mr->finalized_at?->toIso8601String(),
                'notes_hash' => filled($mr->notes) ? hash('sha256', (string) $mr->notes) : null,
            ] : null,
            'treatments' => $treatments,
            'identifiers' => $identifiers,
            // Key omitted entirely when no structured diagnosis exists so the
            // source hash of every legacy candidate stays byte-stable.
            ...($diagnoses !== [] ? ['diagnoses' => $diagnoses] : []),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return filled($value) ? (string) $value : null;
    }

    private function rollup(): string
    {
        foreach ($this->reasons as $reason) {
            if ($reason['severity'] === 'blocked') {
                return SatusehatCandidate::READINESS_BLOCKED;
            }
        }
        foreach ($this->reasons as $reason) {
            if ($reason['severity'] === 'incomplete') {
                return SatusehatCandidate::READINESS_INCOMPLETE;
            }
        }

        return SatusehatCandidate::READINESS_READY;
    }

    private function blocked(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'blocked', 'message' => $message];
    }

    private function incomplete(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'incomplete', 'message' => $message];
    }

    private function info(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'info', 'message' => $message];
    }
}
