<?php

namespace App\Modules\Satusehat\Repositories;

use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SatusehatDataQualityIssueRepository implements SatusehatDataQualityIssueRepositoryInterface
{
    public function paginate(array $filters, array $branchIds, int $perPage = 20): LengthAwarePaginator
    {
        return $this->scoped($branchIds)
            ->with([
                'patient:id,name,medical_record_number',
                'doctor:id,name',
                'clinicVisit:id,visit_number,visit_date',
                'assignedTo:id,name',
            ])
            ->when($this->str($filters, 'status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->when(($filters['open_only'] ?? false), fn (Builder $q) => $q->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES))
            ->when($this->str($filters, 'severity'), fn (Builder $q, $v) => $q->where('severity', $v))
            ->when($this->str($filters, 'rule_code'), fn (Builder $q, $v) => $q->where('rule_code', $v))
            ->when($this->str($filters, 'owner_role'), fn (Builder $q, $v) => $q->where('owner_role', $v))
            ->when($this->int($filters, 'branch_id'), fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($this->int($filters, 'doctor_id'), fn (Builder $q, $v) => $q->where('doctor_id', $v))
            ->when($this->int($filters, 'assigned_to'), fn (Builder $q, $v) => $q->where('assigned_to', $v))
            ->when($this->str($filters, 'search'), function (Builder $q, $v) {
                $escaped = addcslashes($v, '\\%_');
                $q->whereHas('patient', function (Builder $p) use ($escaped) {
                    $p->where('name', 'like', "%{$escaped}%")
                        ->orWhere('medical_record_number', 'like', "%{$escaped}%");
                });
            })
            ->when($this->str($filters, 'detected_from'), fn (Builder $q, $v) => $q->whereDate('last_detected_at', '>=', $v))
            ->when($this->str($filters, 'detected_to'), fn (Builder $q, $v) => $q->whereDate('last_detected_at', '<=', $v))
            ->orderByDesc('last_detected_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranches(int $id, array $branchIds): ?SatusehatDataQualityIssue
    {
        // Scope the per-record write-action lookup by environment too
        // (defense-in-depth; environment is config-fixed in this deployment).
        return $this->scoped($branchIds)
            ->where('environment', (string) config('satusehat.environment'))
            ->whereKey($id)->first();
    }

    public function aggregates(array $branchIds): array
    {
        $base = fn () => $this->scoped($branchIds);

        $byStatus = $base()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();

        $open = fn (Builder $q) => $q->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES);

        $bySeverity = $open($base())->selectRaw('severity, count(*) as total')
            ->groupBy('severity')->pluck('total', 'severity')->map(fn ($v) => (int) $v)->all();

        $byRule = $open($base())->selectRaw('rule_code, count(*) as total')
            ->groupBy('rule_code')->pluck('total', 'rule_code')->map(fn ($v) => (int) $v)->all();

        $byOwner = $open($base())->selectRaw('owner_role, count(*) as total')
            ->groupBy('owner_role')->pluck('total', 'owner_role')->map(fn ($v) => (int) $v)->all();

        $byBranch = $open($base())->selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')->pluck('total', 'branch_id')->map(fn ($v) => (int) $v)->all();

        return [
            'by_status' => $byStatus,
            'open_by_severity' => $bySeverity,
            'open_by_rule' => $byRule,
            'open_by_owner_role' => $byOwner,
            'open_by_branch' => $byBranch,
        ];
    }

    public function openAggregatesForCandidates(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $rows = SatusehatDataQualityIssue::query()
            ->whereIn('satusehat_candidate_id', $candidateIds)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)
            ->get(['satusehat_candidate_id', 'status', 'severity', 'rule_code']);

        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row->satusehat_candidate_id;
            $result[$cid] ??= ['open' => 0, 'awaiting_clinical_review' => 0, 'invalid_demographics' => 0, 'diagnosis_mapping_gap' => 0];
            if ($row->severity !== SatusehatDataQualityIssue::SEVERITY_INFO) {
                $result[$cid]['open']++;
            }
            if ($row->status === SatusehatDataQualityIssue::STATUS_AWAITING_CLINICAL_REVIEW) {
                $result[$cid]['awaiting_clinical_review']++;
            }
            if ($row->rule_code === 'patient_demographics') {
                $result[$cid]['invalid_demographics']++;
            }
            if ($row->rule_code === 'diagnosis_mapping') {
                $result[$cid]['diagnosis_mapping_gap']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function scoped(array $branchIds): Builder
    {
        $query = SatusehatDataQualityIssue::query();

        // Fail-closed: an empty branch set can never read anything.
        return $branchIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('branch_id', $branchIds);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function str(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function int(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
