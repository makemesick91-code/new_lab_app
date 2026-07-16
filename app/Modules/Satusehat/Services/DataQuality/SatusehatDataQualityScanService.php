<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Facades\Log;

/**
 * Bounded batch scan: runs the rule engine over candidates in RME-enabled
 * branches, chunked and capped. Dry-run by default at the command layer.
 * Read-only per candidate except issue rows; never HTTP.
 */
class SatusehatDataQualityScanService
{
    public function __construct(
        private readonly SatusehatDataQualityIssueService $issues,
        private readonly BranchService $branches,
    ) {}

    /**
     * @return array{scanned: int, detected: int, created: int, reopened: int, auto_resolved: int, errors: int}
     */
    public function scan(
        ?int $branchId = null,
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null,
        ?User $actor = null,
        bool $apply = true,
    ): array {
        $branchIds = $this->branches->rmeEnabledIds();
        if ($branchId !== null) {
            $branchIds = in_array($branchId, $branchIds, true) ? [$branchId] : [];
        }

        $default = (int) config('satusehat_data_quality.scan.default_batch_size', 200);
        $max = (int) config('satusehat_data_quality.scan.max_batch_size', 1000);
        $limit = min($limit ?? $default, $max);

        $query = SatusehatCandidate::query()
            ->when($branchIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($branchIds !== [], fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($from, fn ($q, $v) => $q->whereHas('clinicVisit', fn ($cv) => $cv->whereDate('visit_date', '>=', $v)))
            ->when($to, fn ($q, $v) => $q->whereHas('clinicVisit', fn ($cv) => $cv->whereDate('visit_date', '<=', $v)))
            ->orderBy('id')
            ->limit($limit);

        $summary = ['scanned' => 0, 'detected' => 0, 'created' => 0, 'reopened' => 0, 'auto_resolved' => 0, 'errors' => 0];

        foreach ($query->get() as $candidate) {
            $summary['scanned']++;

            if (! $apply) {
                continue;
            }

            try {
                $result = $this->issues->syncForCandidate($candidate, $actor);
                $summary['detected'] += $result['detected'];
                $summary['created'] += $result['created'];
                $summary['reopened'] += $result['reopened'];
                $summary['auto_resolved'] += $result['auto_resolved'];
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::warning('SATUSEHAT data-quality scan failed for candidate', [
                    'satusehat_candidate_id' => (int) $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }
}
