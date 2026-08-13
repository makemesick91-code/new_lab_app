<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyRmeMigrationWave>
 */
class LegacyRmeMigrationWaveFactory extends Factory
{
    protected $model = LegacyRmeMigrationWave::class;

    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            // Deterministic and unique: the code is a unique column, and a
            // faker word collides often enough to make suites flaky.
            'code' => sprintf('WAVE-%d', $sequence),
            'name' => sprintf('Gelombang Migrasi %d', $sequence),
            'status' => LegacyRmeWaveStatus::DRAFT,
            'approval_reference' => null,
            'approved_branch_codes' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => LegacyRmeWaveStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => LegacyRmeWaveStatus::PAUSED,
            'paused_at' => now(),
            'pause_reason' => 'Dijeda untuk pemeriksaan operasional.',
        ]);
    }

    public function draining(): static
    {
        return $this->state(fn (): array => ['status' => LegacyRmeWaveStatus::DRAINING]);
    }
}
