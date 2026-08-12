<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeIngestionCapacityService;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * LEGACY-RME-PDF-ROLL-3 — read-only operational view of a migration wave.
 *
 * Answers the questions an operator actually asks mid-wave: which branches are
 * admitted, how much is queued, what is waiting for human review, and is the
 * pipeline keeping up.
 *
 * PII POLICY. Counts, branch codes, statuses and timings only. No patient name,
 * no Nomor RM, no KTP/NIK, no filename, no document path — an operations
 * dashboard is not a place clinical identity may leak into.
 *
 * It changes nothing. There is no flag to enable a branch here: admission is a
 * deliberate configuration change followed by a config-cache rebuild, never a
 * side effect of running a status command.
 */
class LegacyRmeWaveStatusCommand extends Command
{
    protected $signature = 'legacy-rme:wave-status
        {--json : Emit the report as JSON}';

    protected $description = 'Report the current legacy RME migration wave: admitted branches, backlog and pipeline headroom';

    public function handle(
        LegacyRmeFeatureGuard $feature,
        LegacyRmeBranchAdmissionService $admission,
        LegacyRmeIngestionCapacityService $capacity,
    ): int {
        $measured = $capacity->evaluate();

        $report = [
            'sprint' => 'LEGACY-RME-PDF-ROLL-3',
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) app()->environment(),
            'capability_enabled' => $feature->enabled(),
            'admission' => [
                'enforced' => $admission->enforced(),
                'wave' => $admission->wave(),
                'admitted_branch_codes' => $admission->admittedBranchCodes(),
            ],
            'capacity' => [
                'enforced' => $capacity->enforced(),
                'available' => $measured->available,
                'code' => $measured->code,
            ] + $measured->measurements,
            'imports_by_status' => $this->importsByStatus(),
            'imports_by_branch' => $this->importsByBranch(),
            'records' => $this->recordCounts(),
            'oldest_pending_review_hours' => $this->oldestPendingReviewHours(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Legacy RME wave %s — capability %s, admission %s',
            $report['admission']['wave'] ?? '(unnamed)',
            $report['capability_enabled'] ? 'ON' : 'OFF',
            $report['admission']['enforced'] ? 'enforced' : 'NOT enforced',
        ));

        $admitted = $report['admission']['admitted_branch_codes'];
        $this->components->twoColumnDetail(
            'Admitted branches',
            $admitted === [] ? '(none — closed)' : implode(', ', $admitted),
        );
        $this->components->twoColumnDetail(
            'Pipeline',
            $measured->available ? 'has room' : sprintf('SATURATED (%s)', $measured->code),
        );
        $this->components->twoColumnDetail(
            'Pending render jobs',
            (string) ($measured->measurements['pending_jobs'] ?? 'not measurable'),
        );

        foreach ($report['imports_by_status'] as $status => $count) {
            $this->components->twoColumnDetail(sprintf('Imports · %s', $status), (string) $count);
        }

        foreach ($report['imports_by_branch'] as $branch => $count) {
            $this->components->twoColumnDetail(sprintf('Branch · %s', $branch), (string) $count);
        }

        $this->components->twoColumnDetail('Published records', (string) $report['records']['published']);
        $this->components->twoColumnDetail('Void records', (string) $report['records']['void']);
        $this->components->twoColumnDetail(
            'Oldest awaiting review (hours)',
            (string) ($report['oldest_pending_review_hours'] ?? 'none'),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function importsByStatus(): array
    {
        try {
            if (! Schema::hasTable('stg_rme_legacy_imports')) {
                return [];
            }

            /** @var array<string, int> $counts */
            $counts = LegacyRmeImport::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(static fn ($value): int => (int) $value)
                ->all();

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Import counts per branch CODE. The code is an operational label, never
     * patient data, and no patient row is opened to produce it.
     *
     * @return array<string, int>
     */
    private function importsByBranch(): array
    {
        try {
            if (! Schema::hasTable('stg_rme_legacy_imports') || ! Schema::hasTable('mst_branches')) {
                return [];
            }

            /** @var array<string, int> $counts */
            $counts = DB::table('stg_rme_legacy_imports')
                ->leftJoin('mst_branches', 'mst_branches.id', '=', 'stg_rme_legacy_imports.origin_branch_id')
                // A literal, not a binding: `selectRaw` takes positional
                // bindings, and the value is a fixed label rather than input.
                ->selectRaw("COALESCE(mst_branches.code, 'UNKNOWN') as branch_code, COUNT(*) as aggregate")
                ->groupBy('branch_code')
                ->pluck('aggregate', 'branch_code')
                ->map(static fn ($value): int => (int) $value)
                ->all();

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{published: int, void: int}
     */
    private function recordCounts(): array
    {
        try {
            if (! Schema::hasTable('trx_rme_legacy_records')) {
                return ['published' => 0, 'void' => 0];
            }

            return [
                'published' => LegacyRmeRecord::query()->where('status', LegacyRmeRecordStatus::PUBLISHED)->count(),
                'void' => LegacyRmeRecord::query()->where('status', LegacyRmeRecordStatus::VOID)->count(),
            ];
        } catch (Throwable) {
            return ['published' => 0, 'void' => 0];
        }
    }

    /**
     * How long the oldest document has been waiting for a human reviewer.
     *
     * A growing number here is the signal that a wave has outrun its reviewers
     * — the pipeline is healthy, the PEOPLE are the bottleneck, and that is a
     * different remedy from adding capacity.
     */
    private function oldestPendingReviewHours(): ?int
    {
        try {
            if (! Schema::hasTable('stg_rme_legacy_imports')) {
                return null;
            }

            $oldest = LegacyRmeImport::query()
                ->where('status', LegacyRmeImportStatus::READY_FOR_REVIEW)
                ->min('updated_at');

            if ($oldest === null) {
                return null;
            }

            return (int) max(0, now()->diffInHours($oldest, true));
        } catch (Throwable) {
            return null;
        }
    }
}
