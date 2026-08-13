<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyRmeWaveBranch>
 */
class LegacyRmeWaveBranchFactory extends Factory
{
    protected $model = LegacyRmeWaveBranch::class;

    public function definition(): array
    {
        return [
            'wave_id' => LegacyRmeMigrationWave::factory(),
            'status' => LegacyRmeWaveBranchStatus::ACTIVE,
        ];
    }

    public function planned(): static
    {
        return $this->state(fn (): array => ['status' => LegacyRmeWaveBranchStatus::PLANNED]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => LegacyRmeWaveBranchStatus::PAUSED]);
    }

    public function draining(): static
    {
        return $this->state(fn (): array => ['status' => LegacyRmeWaveBranchStatus::DRAINING]);
    }
}
