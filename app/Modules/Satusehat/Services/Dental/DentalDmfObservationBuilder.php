<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;

/**
 * DMF count Observations (Decayed 251319000 / Missing 251317003 / Filled
 * 251318008), each SNOMED-coded with valueString per the official playbook
 * Tabel 5. Derived from the odontogram's dmftCounts() — the single source of
 * truth already used by the print template.
 */
class DentalDmfObservationBuilder extends AbstractDentalObservationBuilder
{
    public function variable(): string
    {
        return 'dmf_counts';
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>> one descriptor per D/M/F component
     */
    public function build(SatusehatDentalSnapshot $snapshot, array $ctx, int $startOrder): array
    {
        if (! $snapshot->odontogramPresent) {
            return [$this->descriptor($startOrder, false, ['Odontogram belum tersedia — DMF-T tidak dapat dihitung.'], null)];
        }

        $map = (array) config('satusehat_dental.dmf_map', []);
        $descriptors = [];
        $order = $startOrder;

        foreach (['D', 'M', 'F'] as $component) {
            $def = $map[$component] ?? null;
            if (! is_array($def)) {
                continue;
            }

            $payload = [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => $this->examCategory(),
                'code' => [
                    'coding' => [[
                        'system' => (string) config('satusehat_dental.systems.'.$def['system']),
                        'code' => $def['code'],
                        'display' => $def['display'],
                    ]],
                ],
                'subject' => $this->subject($ctx),
                'encounter' => $this->encounterRef($ctx),
                'effectiveDateTime' => $ctx['period']['end'] ?? $ctx['period']['start'] ?? null,
                // Official value type is valueString (not integer/quantity).
                'valueString' => (string) ($snapshot->dmft[$component] ?? 0),
                'local_dmf_component' => $component,
            ];

            $descriptors[] = $this->descriptor($order++, true, [], $payload, 'verified_official');
        }

        return $descriptors;
    }
}
