<?php

declare(strict_types=1);

namespace App\Modules\Odontogram\Support;

/**
 * The canonical FDI tooth numbers of the Daengtisia odontogram (Hotfix Sprint
 * 60.3), shared by every input that names a tooth so the chart and the doctor's
 * structured treatment record (rule 219) can never disagree about what a tooth is.
 *
 *   Permanent: 11–18, 21–28, 31–38, 41–48
 *   Primary:   51–55, 61–65, 71–75, 81–85
 */
final class FdiToothNumbers
{
    /** @return list<string> */
    public static function all(): array
    {
        $numbers = [];
        foreach ([1, 2, 3, 4] as $quadrant) {
            for ($i = 1; $i <= 8; $i++) {
                $numbers[] = (string) ($quadrant * 10 + $i);
            }
        }
        foreach ([5, 6, 7, 8] as $quadrant) {
            for ($i = 1; $i <= 5; $i++) {
                $numbers[] = (string) ($quadrant * 10 + $i);
            }
        }

        return $numbers;
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::all(), true);
    }
}
