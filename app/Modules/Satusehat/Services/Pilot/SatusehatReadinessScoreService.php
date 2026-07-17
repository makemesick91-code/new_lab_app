<?php

namespace App\Modules\Satusehat\Services\Pilot;

/**
 * SATUSEHAT-4C — deterministic internal readiness score.
 *
 * Transparent + versioned — NOT an opaque AI score. The score is a
 * weight-normalized average of component readiness rates (0..100). A component
 * with no measurable data (rate === null) contributes nothing AND is excluded
 * from the denominator — "N/A", never a fabricated 0. Any hard blocker caps the
 * score at the configured ceiling and can never yield `pilot_ready_internal`.
 */
class SatusehatReadinessScoreService
{
    /**
     * @param  array<string, float|int|null>  $componentRates  keyed by score weight key, each 0..100 or null
     * @return array{score: ?int, version: int, capped: bool, weight_total: int, components: array<string, array{weight:int, rate:?float, contribution:?float}>}
     */
    public function calculate(array $componentRates, bool $hasHardBlocker): array
    {
        $weights = (array) config('satusehat_pilot.score.weights', []);
        $version = (int) config('satusehat_pilot.score.version', 1);
        $ceiling = (int) config('satusehat_pilot.score.hard_blocker_score_ceiling', 60);

        $weightedSum = 0.0;
        $weightTotal = 0;
        $components = [];

        foreach ($weights as $key => $weight) {
            $weight = (int) $weight;
            $rate = $componentRates[$key] ?? null;

            if ($rate === null) {
                $components[$key] = ['weight' => $weight, 'rate' => null, 'contribution' => null];

                continue;
            }

            $rate = max(0.0, min(100.0, (float) $rate));
            $weightedSum += $weight * $rate;
            $weightTotal += $weight;
            $components[$key] = [
                'weight' => $weight,
                'rate' => round($rate, 2),
                'contribution' => round($weight * $rate / 100, 2),
            ];
        }

        if ($weightTotal <= 0) {
            return [
                'score' => null,
                'version' => $version,
                'capped' => false,
                'weight_total' => 0,
                'components' => $components,
            ];
        }

        $score = (int) round($weightedSum / $weightTotal);
        $capped = false;

        if ($hasHardBlocker && $score > $ceiling) {
            $score = $ceiling;
            $capped = true;
        }

        return [
            'score' => $score,
            'version' => $version,
            'capped' => $capped,
            'weight_total' => $weightTotal,
            'components' => $components,
        ];
    }
}
