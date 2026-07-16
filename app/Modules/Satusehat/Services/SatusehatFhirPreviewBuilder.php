<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Support\SatusehatReadinessResult;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Builds a LOCAL FHIR preview (Encounter/Condition/Procedure) from local data +
 * active mappings/identifiers. It NEVER sends anything, never claims the payload
 * is accepted/certified by SATUSEHAT, hides the full NIK, excludes
 * odontogram/scan/handwriting, states the environment, distinguishes a local
 * reference from a future remote IHS identifier, and normalizes times to UTC.
 */
class SatusehatFhirPreviewBuilder
{
    public const HONEST_NOTE = 'Preview lokal — belum dikirim dan belum diverifikasi oleh API SATUSEHAT.';

    public function __construct(
        private readonly SatusehatSourceHasher $hasher,
        private readonly PatientDataCompletenessService $patientData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ClinicVisit $visit, SatusehatReadinessResult $readiness): array
    {
        $visit->loadMissing(['patient', 'doctor', 'branch', 'clinicRoom']);
        $facts = $readiness->facts;
        $env = (string) ($facts['environment'] ?? config('satusehat.environment'));
        $ids = $facts['identifiers'] ?? [];

        $period = $this->period($visit);

        $resources = [];
        $resources[] = $this->encounter($visit, $facts, $ids, $period, 0);
        $resources[] = $this->condition(1);
        foreach (($facts['treatments'] ?? []) as $offset => $treatment) {
            $resources[] = $this->procedure($treatment, $ids, $period, 2 + $offset);
        }

        return [
            'environment' => $env,
            'note' => self::HONEST_NOTE,
            'readiness_status' => $readiness->status,
            'readiness_reasons' => $readiness->reasons,
            'resources' => $resources,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, ?string>  $ids
     * @param  array<string, ?string>  $period
     * @return array<string, mixed>
     */
    private function encounter(ClinicVisit $visit, array $facts, array $ids, array $period, int $order): array
    {
        $patient = $visit->patient;

        $payload = [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'subject' => $this->reference('Patient', $facts['patient']['id'] ?? null, $ids['patient'] ?? null, $patient?->name),
            'participant' => $visit->doctor ? [$this->reference('Practitioner', $visit->doctor_id, $ids['practitioner'] ?? null, $visit->doctor->name)] : [],
            'serviceProvider' => $this->reference('Organization', $visit->branch_id, $ids['organization'] ?? null, $visit->branch?->name),
            'location' => $visit->clinic_room_id ? [$this->reference('Location', $visit->clinic_room_id, $ids['location'] ?? null, $visit->clinicRoom?->name)] : [],
            'period' => $period,
            // NIK is masked and shown only as supporting context; never the full value.
            'subject_nik_masked' => $patient ? $this->patientData->maskKtp($patient->ktp_number) : null,
        ];

        $supported = ($ids['patient'] ?? null) !== null && ($ids['organization'] ?? null) !== null && ($period['start'] ?? null) !== null;

        return [
            'order' => $order,
            'resource_type' => SatusehatSubmissionItem::RESOURCE_ENCOUNTER,
            'supported' => $supported,
            'issues' => $supported ? [] : ['Encounter membutuhkan IHS Patient + Organization + periode kunjungan.'],
            'payload' => $payload,
            'payload_hash' => $this->hasher->hash($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(int $order): array
    {
        // No structured diagnosis source exists — never fabricate a Condition.
        return [
            'order' => $order,
            'resource_type' => SatusehatSubmissionItem::RESOURCE_CONDITION,
            'supported' => false,
            'issues' => ['Diagnosis terstruktur belum tersedia — Condition tidak dipetakan pada SATUSEHAT-1.'],
            'payload' => null,
            'payload_hash' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $treatment
     * @param  array<string, ?string>  $ids
     * @param  array<string, ?string>  $period
     * @return array<string, mixed>
     */
    private function procedure(array $treatment, array $ids, array $period, int $order): array
    {
        $mapped = ($treatment['mapping_code'] ?? null) !== null;

        $payload = $mapped ? [
            'resourceType' => 'Procedure',
            'status' => 'completed',
            'code' => [
                'coding' => [[
                    'system' => 'http://sys-ids.kemkes.go.id/kfa',
                    'code' => $treatment['mapping_code'],
                ]],
            ],
            'subject' => $this->reference('Patient', null, $ids['patient'] ?? null, null),
            'performedDateTime' => $period['end'] ?? $period['start'] ?? null,
            'local_treatment_id' => $treatment['treatment_id'] ?? null,
        ] : null;

        return [
            'order' => $order,
            'resource_type' => SatusehatSubmissionItem::RESOURCE_PROCEDURE,
            'supported' => $mapped,
            'issues' => $mapped ? [] : ['Tindakan belum memiliki mapping Procedure aktif.'],
            'payload' => $payload,
            'payload_hash' => $mapped ? $this->hasher->hash($payload) : null,
        ];
    }

    /**
     * A reference distinguishes the LOCAL reference from the FUTURE remote IHS
     * identifier (which is only used for a real submission in SATUSEHAT-2).
     *
     * @return array<string, mixed>
     */
    private function reference(string $type, ?int $localId, ?string $remoteIdentifier, ?string $display): array
    {
        return [
            'type' => $type,
            'local_reference' => $localId !== null ? "{$type}/local-{$localId}" : null,
            'remote_ihs_identifier' => $remoteIdentifier, // null until an IHS id is registered
            'display' => $display,
        ];
    }

    /**
     * UTC-normalized visit period from WITA local timestamps.
     *
     * @return array<string, ?string>
     */
    private function period(ClinicVisit $visit): array
    {
        $tz = (string) config('satusehat.clinic_timezone', 'Asia/Makassar');

        $start = $visit->started_at ?? $visit->check_in_at ?? $visit->visit_date;
        $end = $visit->completed_at;

        return [
            'start' => $this->toUtc($start, $tz),
            'end' => $this->toUtc($end, $tz),
        ];
    }

    private function toUtc(mixed $value, string $tz): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Clinic timestamps are recorded as WITA wall-clock time. Reinterpret
            // the stored wall-clock in the clinic timezone, then convert to true UTC.
            $wallClock = $value instanceof CarbonInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value;

            return Carbon::parse($wallClock, $tz)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
