<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — cross-branch issue governance (bounded bulk operations).
 *
 * Bulk assignment is bounded (paginated selection only — never select-all-across
 * -all-pages), server-side branch-scoped (issues outside the authorized branch
 * set are dropped, an IDOR boundary), never performs bulk HARD resolution, and
 * audits every affected issue (via the 4C SLA service, which locks + audits per
 * issue). Reuses the 4C SLA/escalation engine — no duplicate issue subsystem.
 */
class SatusehatCrossBranchIssueService
{
    /** Hard cap on a single bulk operation → forces paginated selection. */
    public const MAX_BULK = 100;

    public function __construct(
        private readonly SatusehatIssueSlaService $sla,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    /**
     * Assign a bounded, explicitly-selected set of issues to an operator.
     *
     * @param  list<int>  $issueIds  explicitly selected ids (a single page, bounded)
     * @param  list<int>  $authorizedBranchIds  the actor's branch-scoped set
     * @return array{assigned:int, skipped:int, skipped_ids:list<int>}
     */
    public function bulkAssign(
        array $issueIds,
        int $assigneeId,
        array $authorizedBranchIds,
        User $actor,
        ?string $priority = null,
        ?string $assignedRole = null,
    ): array {
        $issueIds = array_values(array_unique(array_map('intval', $issueIds)));

        if ($issueIds === []) {
            throw ValidationException::withMessages(['issue_ids' => 'Pilih minimal satu isu untuk ditugaskan.']);
        }
        if (count($issueIds) > self::MAX_BULK) {
            throw ValidationException::withMessages([
                'issue_ids' => 'Terlalu banyak isu dalam satu aksi (maks. '.self::MAX_BULK.'). Gunakan pemilihan per-halaman.',
            ]);
        }
        if ($authorizedBranchIds === []) {
            throw ValidationException::withMessages(['issue_ids' => 'Tidak ada cabang yang diizinkan untuk aksi ini.']);
        }

        // Only OPEN issues within the authorized branch set are eligible — the
        // IDOR boundary. Anything else is silently dropped and reported.
        $eligible = SatusehatDataQualityIssue::query()
            ->where('environment', $this->env())
            ->whereIn('id', $issueIds)
            ->whereIn('branch_id', $authorizedBranchIds)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)
            ->get();

        $eligibleIds = $eligible->pluck('id')->map(fn ($id) => (int) $id)->all();
        $skippedIds = array_values(array_diff($issueIds, $eligibleIds));

        $assigned = 0;
        foreach ($eligible as $issue) {
            $this->sla->assign($issue, $actor, $assigneeId, $priority, $assignedRole);
            $assigned++;
        }

        return [
            'assigned' => $assigned,
            'skipped' => count($skippedIds),
            'skipped_ids' => $skippedIds,
        ];
    }
}
