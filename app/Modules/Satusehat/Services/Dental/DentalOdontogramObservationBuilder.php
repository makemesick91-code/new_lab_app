<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;

/**
 * Parent "Pemeriksaan Odontogram" Observation — Kemkes clinical-term OC000061,
 * valueBoolean (official playbook Tabel 5). Supported when an odontogram exists.
 */
class DentalOdontogramObservationBuilder extends AbstractDentalObservationBuilder
{
    public function variable(): string
    {
        return 'odontogram_examination';
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function build(SatusehatDentalSnapshot $snapshot, array $ctx, int $order): array
    {
        if (! $snapshot->odontogramPresent) {
            return $this->descriptor($order, false, ['Odontogram belum tersedia untuk kunjungan ini.'], null);
        }

        $payload = [
            'resourceType' => 'Observation',
            'status' => 'final',
            'category' => $this->examCategory(),
            'code' => [
                'coding' => [[
                    'system' => (string) config('satusehat_dental.systems.kemkes_clinical_term'),
                    'code' => 'OC000061',
                    'display' => 'Pemeriksaan Odontogram',
                ]],
            ],
            'subject' => $this->subject($ctx),
            'encounter' => $this->encounterRef($ctx),
            'effectiveDateTime' => $ctx['period']['end'] ?? $ctx['period']['start'] ?? null,
            'valueBoolean' => true,
        ];

        return $this->descriptor($order, true, [], $payload, 'verified_official');
    }
}
