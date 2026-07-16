<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;

/**
 * "Kondisi Gigi dan Mulut Lainnya" narrative Observation — Kemkes clinical-term
 * OC000060, valueString (official playbook Tabel 5). Emitted only when the
 * odontogram carries a narrative note; the value is a presence marker, NOT the
 * raw clinical note (the snapshot is PII-free — the raw text never leaves the
 * clinical tables). Kept `supported=false` until a real narrative pipeline is
 * approved, so no clinical free-text is exported by preview.
 */
class DentalOtherFindingObservationBuilder extends AbstractDentalObservationBuilder
{
    public function variable(): string
    {
        return 'other_oral_condition';
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function build(SatusehatDentalSnapshot $snapshot, array $ctx, int $order): array
    {
        if (! $snapshot->hasOtherConditions) {
            return $this->descriptor($order, false, ['Tidak ada kondisi gigi/mulut lainnya yang tercatat.'], null);
        }

        // Present but deliberately NOT emitted as a payload: exporting a raw
        // clinical narrative needs an explicit, reviewed de-identification step.
        return $this->descriptor(
            $order,
            false,
            ['Terdapat catatan kondisi lainnya — ekspor narasi bebas memerlukan langkah de-identifikasi yang belum disetujui.'],
            null,
        );
    }
}
