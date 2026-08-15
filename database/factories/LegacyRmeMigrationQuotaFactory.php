<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyRmeMigrationQuota>
 */
class LegacyRmeMigrationQuotaFactory extends Factory
{
    protected $model = LegacyRmeMigrationQuota::class;

    public function definition(): array
    {
        return [
            'wave_id' => LegacyRmeMigrationWave::factory(),
            // The clinical calendar day, taken from the SAME ClinicalClock the
            // quota gate reads — a UTC-anchored default would land in a
            // different bucket from the one the service checks.
            'quota_date' => app(ClinicalClock::class)->todayString(),
            'consumed' => 0,
        ];
    }
}
