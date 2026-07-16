<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Support\SatusehatDentalConformanceValidator;
use App\Modules\Satusehat\Support\SatusehatDentalReadinessResult;
use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;

/**
 * Dental readiness engine (SATUSEHAT-3). Evaluates a visit's odontogram against
 * the dental hard gates, builds the local dental Observations, runs them through
 * the local conformance validator, and produces a status + PII-free reasons +
 * deterministic dental facts. It NEVER performs an external request and NEVER
 * emits a name/NIK/raw note. Nothing here activates a mapping.
 */
class SatusehatDentalReadinessService
{
    /** @var list<array{code:string, severity:string, message:string}> */
    private array $reasons = [];

    public function __construct(
        private readonly SatusehatDentalSnapshotBuilder $snapshots,
        private readonly SatusehatDentalResourceBuilder $resources,
        private readonly SatusehatDentalConformanceValidator $validator,
        private readonly SatusehatIdentifierRepositoryInterface $identifiers,
    ) {}

    public function evaluate(ClinicVisit $visit): SatusehatDentalReadinessResult
    {
        $this->reasons = [];
        $env = (string) config('satusehat.environment');

        $visit->loadMissing(['doctor']);
        $snapshot = $this->snapshots->build($visit, $env);

        // G1 — odontogram present.
        if (! $snapshot->odontogramPresent) {
            $this->incomplete('dental_odontogram_missing', 'Odontogram belum tersedia untuk kunjungan ini.');
        }

        // G2 — tooth data valid (FDI numbers recognised).
        $fdiMap = (array) config('satusehat_dental.fdi_bodysite_map', []);
        $conditionMap = (array) config('satusehat_dental.tooth_condition_map', []);
        foreach ($snapshot->teeth as $tooth) {
            if (! array_key_exists((string) $tooth['number'], $fdiMap)) {
                $this->conformanceFailed('dental_unknown_tooth_number', "Nomor gigi {$tooth['number']} tidak dikenal (FDI).");
            }
            // G3 — no unknown local tooth status codes.
            if (! array_key_exists((string) $tooth['status'], $conditionMap)) {
                $this->unsupported('dental_unknown_tooth_status', "Keadaan gigi '{$tooth['status']}' tidak didukung use-case dental.");
            }
        }

        // G4 — official mapping availability for each tooth condition/bodySite.
        foreach ($snapshot->teeth as $tooth) {
            if (! $tooth['mapped']) {
                $this->mappingBlocked('dental_condition_mapping_missing', "Keadaan gigi '{$tooth['status']}' belum memiliki mapping aktif.");
            }
        }

        // G5 — examiner present.
        if ($visit->doctor === null) {
            $this->incomplete('dental_examiner_missing', 'Dokter pemeriksa belum tersedia.');
        }

        // G6 — IHS references (Patient/Practitioner/Organization) ready.
        $this->assertIdentifiers($env, $visit);

        // G7 — build the dental resources + run local conformance.
        $built = $this->resources->build($visit, $snapshot, $env);
        $supportedCount = 0;
        foreach ($built['resources'] as $descriptor) {
            if (! ($descriptor['supported'] ?? false) || ($descriptor['payload'] ?? null) === null) {
                continue;
            }
            $supportedCount++;
            $verdict = $this->validator->validate($descriptor['payload']);
            if ($verdict['result'] !== 'valid') {
                $this->conformanceFailed('dental_conformance_failed', 'Validasi konformansi lokal gagal untuk '.$descriptor['variable'].'.');
            }
        }

        if ($snapshot->odontogramPresent && $supportedCount === 0) {
            $this->mappingBlocked('dental_no_supported_resource', 'Belum ada resource gigi yang dapat dibentuk (mapping/identifier belum lengkap).');
        }

        $status = $this->rollup($snapshot);

        $coverage = array_merge($snapshot->coverage, [
            'dental_status' => $status,
            'reason_codes' => array_column($this->reasons, 'code'),
            'supported_resource_count' => $supportedCount,
        ]);

        $facts = array_merge($snapshot->facts, [
            'dental_env' => $env,
            'mapping_versions' => $built['mapping_versions'] ?? [],
        ]);

        return new SatusehatDentalReadinessResult($status, $this->reasons, $facts, $coverage);
    }

    private function assertIdentifiers(string $env, ClinicVisit $visit): void
    {
        $patient = $visit->patient_id
            ? $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PATIENT, 'patient', (int) $visit->patient_id)
            : null;
        if ($patient === null) {
            $this->incomplete('dental_patient_ihs_missing', 'IHS Patient identifier belum tersedia.');
        }

        $practitioner = $visit->doctor_id
            ? $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PRACTITIONER, 'doctor', (int) $visit->doctor_id)
            : null;
        if ($practitioner === null) {
            $this->incomplete('dental_practitioner_ihs_missing', 'IHS Practitioner identifier belum tersedia.');
        }

        $org = $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_ORGANIZATION, 'branch', (int) $visit->branch_id);
        if ($org === null) {
            $this->incomplete('dental_organization_ihs_missing', 'IHS Organization identifier cabang belum tersedia.');
        }
    }

    private function rollup(SatusehatDentalSnapshot $snapshot): string
    {
        foreach ($this->reasons as $r) {
            if ($r['severity'] === 'unsupported') {
                return SatusehatCandidate::DENTAL_UNSUPPORTED;
            }
        }
        foreach ($this->reasons as $r) {
            if ($r['severity'] === 'conformance_failed') {
                return SatusehatCandidate::DENTAL_CONFORMANCE_FAILED;
            }
        }
        foreach ($this->reasons as $r) {
            if ($r['severity'] === 'mapping_blocked') {
                return SatusehatCandidate::DENTAL_MAPPING_BLOCKED;
            }
        }
        foreach ($this->reasons as $r) {
            if ($r['severity'] === 'incomplete') {
                return SatusehatCandidate::DENTAL_INCOMPLETE;
            }
        }

        return SatusehatCandidate::DENTAL_READY;
    }

    private function incomplete(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'incomplete', 'message' => $message];
    }

    private function mappingBlocked(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'mapping_blocked', 'message' => $message];
    }

    private function unsupported(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'unsupported', 'message' => $message];
    }

    private function conformanceFailed(string $code, string $message): void
    {
        $this->reasons[] = ['code' => $code, 'severity' => 'conformance_failed', 'message' => $message];
    }
}
