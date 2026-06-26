<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Sprint 61.0 — Branch-level RM gap review.
 *
 * Inspects the finalized RM format `DG-{KODE_CABANG}-{TAHUN}-{NOMOR_RM_MANUAL}`
 * and reports, per RME-enabled branch, the min/max numeric suffix, total count,
 * and detected missing sequence numbers.
 *
 * Defensive by design: malformed / non-DG / non-numeric RM values are skipped
 * (counted as "unparseable") and never crash the review. When a branch has fewer
 * than two parseable suffixes the gap cannot be computed and the summary carries
 * a human note ("Tidak dapat dihitung") instead of numbers.
 */
class PatientRmGapReviewService
{
    /**
     * Maximum suffix span we will enumerate per branch. A pathological RM (e.g.
     * a manual number in the millions) must not build a giant array — beyond this
     * span we still report the missing count but skip the sample list.
     */
    private const MAX_ENUMERABLE_SPAN = 5000;

    private const MISSING_SAMPLE_LIMIT = 20;

    /**
     * Build the per-branch RM gap summary. When $branchId is given, only that
     * RME-enabled branch is reviewed.
     *
     * @return list<array<string, mixed>>
     */
    public function review(?int $branchId = null): array
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->when($branchId !== null, fn ($q) => $q->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return $branches
            ->map(fn (Branch $branch) => $this->reviewBranch($branch))
            ->all();
    }

    /**
     * Parse the numeric suffix from a finalized RM value, or null when it does
     * not match the DG format / is non-numeric. Never throws.
     */
    public function parseSuffix(?string $rm): ?int
    {
        if (! is_string($rm)) {
            return null;
        }

        if (! preg_match('/^DG-[^-]+-\d{4}-(\d+)$/', trim($rm), $matches)) {
            return null;
        }

        // Guard against absurdly long numeric strings overflowing int math.
        if (strlen($matches[1]) > 9) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewBranch(Branch $branch): array
    {
        /** @var Collection<int, string|null> $rmNumbers */
        $rmNumbers = Patient::query()
            ->where('branch_id', $branch->id)
            ->pluck('medical_record_number');

        $suffixes = [];
        $unparseable = 0;

        foreach ($rmNumbers as $rm) {
            $suffix = $this->parseSuffix($rm);

            if ($suffix === null) {
                $unparseable++;

                continue;
            }

            $suffixes[$suffix] = true;
        }

        $base = [
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
            'branch_name' => $branch->name,
            'total_patients' => $rmNumbers->count(),
            'unparseable_count' => $unparseable,
        ];

        $present = array_keys($suffixes);

        if (count($present) < 2) {
            return $base + [
                'parseable' => false,
                'note' => 'Tidak dapat dihitung — RM numerik tidak konsisten / belum cukup data ber-format DG.',
                'min' => $present[0] ?? null,
                'max' => $present[0] ?? null,
                'parseable_count' => count($present),
                'missing_count' => 0,
                'missing_sample' => [],
            ];
        }

        $min = min($present);
        $max = max($present);
        $span = $max - $min + 1;
        $presentSet = array_flip($present);

        if ($span > self::MAX_ENUMERABLE_SPAN) {
            return $base + [
                'parseable' => true,
                'note' => 'Rentang nomor terlalu besar untuk dirinci — hanya jumlah yang ditampilkan.',
                'min' => $min,
                'max' => $max,
                'parseable_count' => count($present),
                'missing_count' => $span - count($present),
                'missing_sample' => [],
            ];
        }

        $missing = [];
        for ($n = $min; $n <= $max; $n++) {
            if (! isset($presentSet[$n])) {
                $missing[] = $n;
            }
        }

        return $base + [
            'parseable' => true,
            'note' => null,
            'min' => $min,
            'max' => $max,
            'parseable_count' => count($present),
            'missing_count' => count($missing),
            'missing_sample' => array_slice($missing, 0, self::MISSING_SAMPLE_LIMIT),
        ];
    }
}
