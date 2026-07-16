<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\Satusehat\Support\SatusehatSourceHasher;

/**
 * Shared, side-effect-free helpers for the dental Observation builders. Every
 * concrete builder is typed, deterministic, network-silent, uses only active
 * official mappings, includes Encounter/Patient references, normalizes time to
 * UTC (upstream), produces a payload hash, and reports an explicit unsupported
 * reason instead of a silent fallback.
 */
abstract class AbstractDentalObservationBuilder
{
    public function __construct(
        protected readonly SatusehatSourceHasher $hasher,
    ) {}

    /**
     * The dental variable this builder covers (matches config coverage keys).
     */
    abstract public function variable(): string;

    /**
     * A FHIR reference distinguishing the LOCAL reference from a FUTURE remote
     * IHS identifier (only used for a real SATUSEHAT-2 submission).
     *
     * @param  array<string, mixed>  $ref  ['local_id' => ?int, 'ihs' => ?string, 'display' => ?string]
     * @return array<string, mixed>
     */
    protected function reference(string $type, array $ref): array
    {
        $localId = $ref['local_id'] ?? null;

        return [
            'type' => $type,
            'local_reference' => $localId !== null ? "{$type}/local-{$localId}" : null,
            'remote_ihs_identifier' => $ref['ihs'] ?? null,
            'display' => $ref['display'] ?? null,
        ];
    }

    protected function examCategory(): array
    {
        return [[
            'coding' => [[
                'system' => (string) config('satusehat_dental.systems.observation_category'),
                'code' => 'exam',
            ]],
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function hash(array $payload): string
    {
        return $this->hasher->hash($payload);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function subject(array $ctx): array
    {
        return $this->reference('Patient', $ctx['patient'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function encounterRef(array $ctx): array
    {
        return $this->reference('Encounter', $ctx['encounter'] ?? []);
    }

    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    protected function descriptor(int $order, bool $supported, array $issues, ?array $payload, ?string $confidence = null): array
    {
        return [
            'order' => $order,
            'variable' => $this->variable(),
            'resource_type' => 'Observation',
            'supported' => $supported,
            'mapping_confidence' => $confidence,
            'issues' => $issues,
            'payload' => $payload,
            'payload_hash' => $payload !== null ? $this->hash($payload) : null,
        ];
    }
}
