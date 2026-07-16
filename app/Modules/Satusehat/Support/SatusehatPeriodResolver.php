<?php

namespace App\Modules\Satusehat\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves a visit's clinical period as true UTC ISO-8601 timestamps. Clinic
 * timestamps are stored as WITA wall-clock; this reinterprets them in the clinic
 * timezone and converts to UTC — the single normalization used by both the local
 * preview (SATUSEHAT-1) and the real FHIR resource builder (SATUSEHAT-2).
 */
final class SatusehatPeriodResolver
{
    /**
     * @return array{start: ?string, end: ?string}
     */
    public function resolve(ClinicVisit $visit): array
    {
        $tz = (string) config('satusehat.clinic_timezone', 'Asia/Makassar');

        $start = $visit->started_at ?? $visit->check_in_at ?? $visit->visit_date;
        $end = $visit->completed_at;

        return [
            'start' => $this->toUtc($start, $tz),
            'end' => $this->toUtc($end, $tz),
        ];
    }

    public function toUtc(mixed $value, ?string $tz = null): ?string
    {
        $tz ??= (string) config('satusehat.clinic_timezone', 'Asia/Makassar');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            $wallClock = $value instanceof CarbonInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value;

            return Carbon::parse($wallClock, $tz)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
