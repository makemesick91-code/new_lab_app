<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use Carbon\CarbonImmutable;
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
            // The clinical calendar day, matching the service that writes these
            // buckets — a UTC-anchored default would land in a different bucket
            // from the one the quota gate reads.
            'quota_date' => CarbonImmutable::now(
                (string) config('legacy_rme.dates.clinical_timezone', config('app.timezone', 'UTC'))
            )->toDateString(),
            'consumed' => 0,
        ];
    }
}
