<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bounded, idempotent backfill of SATUSEHAT readiness candidates for older
 * completed visits. NEVER performs a full-table unbounded scan (a per-run limit
 * is always applied) and NEVER makes an external request. Reports a PII-free
 * summary. Re-running is safe (firstOrCreate keyed on the visit).
 */
class SatusehatBackfillCandidatesCommand extends Command
{
    protected $signature = 'satusehat:backfill-candidates
        {--dry-run : Only count eligible visits; create/refresh nothing}
        {--branch= : Restrict to a single RME-enabled branch id}
        {--from= : Visit date from (Y-m-d)}
        {--to= : Visit date to (Y-m-d)}
        {--limit= : Max visits processed this run (bounded by config)}
        {--json : Output a machine-readable JSON summary}';

    protected $description = 'Backfill SATUSEHAT readiness candidates for completed visits (bounded, idempotent, no external call).';

    public function handle(BranchService $branches, SatusehatCandidateService $service): int
    {
        $branchIds = $this->resolveBranchIds($branches);
        if ($branchIds === []) {
            $this->error('No RME-enabled branch in scope.');

            return self::FAILURE;
        }

        $limit = $this->resolveLimit();
        $dryRun = (bool) $this->option('dry-run');

        $query = ClinicVisit::query()
            ->with('medicalRecord')
            ->whereIn('branch_id', $branchIds)
            ->where('status', ClinicVisit::STATUS_COMPLETED)
            ->whereNotNull('patient_id')
            ->whereHas('medicalRecord', fn (Builder $q) => $q->where('status', MedicalRecord::STATUS_FINAL))
            ->when($this->option('from'), fn (Builder $q, $v) => $q->whereDate('visit_date', '>=', $v))
            ->when($this->option('to'), fn (Builder $q, $v) => $q->whereDate('visit_date', '<=', $v))
            ->orderBy('id')
            ->limit($limit);

        $eligible = 0;
        $created = 0;
        $refreshed = 0;
        $skipped = 0;

        foreach ($query->get() as $visit) {
            $eligible++;

            if ($dryRun) {
                continue;
            }

            $candidate = $service->generateForVisit($visit);
            if ($candidate === null) {
                $skipped++;
            } elseif ($candidate->wasRecentlyCreated) {
                $created++;
            } else {
                $refreshed++;
            }
        }

        $summary = [
            'dry_run' => $dryRun,
            'branch_ids' => $branchIds,
            'limit' => $limit,
            'eligible' => $eligible,
            'created' => $created,
            'refreshed' => $refreshed,
            'skipped' => $skipped,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($summary));
        } else {
            $this->info(sprintf(
                '%s — eligible=%d created=%d refreshed=%d skipped=%d (limit=%d)',
                $dryRun ? 'DRY-RUN' : 'BACKFILL',
                $eligible, $created, $refreshed, $skipped, $limit,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveBranchIds(BranchService $branches): array
    {
        $rmeIds = $branches->rmeEnabledIds();

        if ($this->option('branch') !== null) {
            $branchId = (int) $this->option('branch');

            return in_array($branchId, $rmeIds, true) ? [$branchId] : [];
        }

        return $rmeIds;
    }

    private function resolveLimit(): int
    {
        $default = (int) config('satusehat.candidate.backfill_default_batch_size', 200);
        $max = (int) config('satusehat.candidate.backfill_max_batch_size', 1000);

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : $default;

        return max(1, min($limit, $max));
    }
}
