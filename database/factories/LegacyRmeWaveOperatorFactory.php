<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyRmeWaveOperator>
 */
class LegacyRmeWaveOperatorFactory extends Factory
{
    protected $model = LegacyRmeWaveOperator::class;

    public function definition(): array
    {
        return [
            'wave_id' => LegacyRmeMigrationWave::factory(),
            'assigned_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
