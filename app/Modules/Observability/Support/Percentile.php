<?php

namespace App\Modules\Observability\Support;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — nearest-rank percentile, integer
 * arithmetic only (no floating-point off-by-one at a rank boundary).
 */
final class Percentile
{
    /**
     * @param  list<int>  $sorted  ascending
     */
    public static function nearestRank(array $sorted, int $p): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }

        $rank = intdiv($p * $n + 99, 100);

        return (int) $sorted[max(0, min($n - 1, $rank - 1))];
    }
}
