<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;

/**
 * Per-tooth "Keadaan Gigi" Observations. bodySite = the tooth's FDI→SNOMED
 * mapping (official annex Lampiran 1); component[Tooth finding 278544002] value
 * = the ACTIVE tooth-condition mapping for the local status. A tooth is only
 * emitted when BOTH its bodySite and its condition mapping are active — never a
 * guessed code, never a silent default.
 */
class DentalToothConditionObservationBuilder extends AbstractDentalObservationBuilder
{
    public function __construct(
        SatusehatSourceHasher $hasher,
        private readonly SatusehatMappingRepositoryInterface $mappings,
    ) {
        parent::__construct($hasher);
    }

    public function variable(): string
    {
        return 'tooth_condition';
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>> one descriptor per tooth
     */
    public function build(SatusehatDentalSnapshot $snapshot, array $ctx, int $startOrder): array
    {
        $env = (string) ($ctx['environment'] ?? config('satusehat.environment'));
        $descriptors = [];
        $order = $startOrder;

        foreach ($snapshot->teeth as $tooth) {
            $number = (string) $tooth['number'];
            $status = (string) $tooth['status'];

            $bodySite = $this->mappings->findActive($env, 'odontogram_tooth_bodysite', null, $number, 'Observation');
            $condition = $this->mappings->findActive($env, 'odontogram_tooth_condition', null, $status, 'Observation');

            if ($condition === null) {
                $descriptors[] = $this->unsupportedTooth($order++, $number, "Keadaan gigi '{$status}' belum memiliki mapping SATUSEHAT aktif.");

                continue;
            }
            if ($bodySite === null) {
                $descriptors[] = $this->unsupportedTooth($order++, $number, "Nomor gigi {$number} belum memiliki mapping bodySite aktif.");

                continue;
            }

            $descriptors[] = $this->supportedTooth($order++, $number, $bodySite, $condition, $ctx);
        }

        return $descriptors;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function supportedTooth(int $order, string $number, SatusehatCodeMapping $bodySite, SatusehatCodeMapping $condition, array $ctx): array
    {
        $payload = [
            'resourceType' => 'Observation',
            'status' => 'final',
            'category' => $this->examCategory(),
            'code' => [
                'coding' => [[
                    'system' => (string) config('satusehat_dental.systems.snomed'),
                    'code' => '278544002',
                    'display' => 'Tooth finding',
                ]],
            ],
            'subject' => $this->subject($ctx),
            'encounter' => $this->encounterRef($ctx),
            'effectiveDateTime' => $ctx['period']['end'] ?? $ctx['period']['start'] ?? null,
            'bodySite' => [
                'coding' => [[
                    'system' => (string) ($bodySite->terminology_system ?: config('satusehat_dental.systems.snomed')),
                    'code' => $bodySite->target_code,
                    'display' => $bodySite->target_display,
                ]],
                'text' => "FDI {$number}",
            ],
            'valueCodeableConcept' => [
                'coding' => [[
                    'system' => (string) ($condition->terminology_system ?: config('satusehat_dental.systems.snomed')),
                    'code' => $condition->target_code,
                    'display' => $condition->target_display,
                ]],
            ],
            'local_tooth_number' => $number,
        ];

        // The tooth is only fully "verified" when BOTH mappings are.
        $confidence = ($bodySite->mapping_confidence === 'verified_official' && $condition->mapping_confidence === 'verified_official')
            ? 'verified_official'
            : ($condition->mapping_confidence ?? 'unverified');

        return array_merge(
            $this->descriptor($order, true, [], $payload, $confidence),
            ['tooth_number' => $number],
        );
    }

    private function unsupportedTooth(int $order, string $number, string $issue): array
    {
        return array_merge(
            $this->descriptor($order, false, [$issue], null),
            ['tooth_number' => $number],
        );
    }
}
