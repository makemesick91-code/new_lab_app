<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * LEGACY-RME-PDF-ROLL-4 — the read-only operational picture of a migration.
 *
 * WHAT AN OPERATOR ACTUALLY NEEDS MID-WAVE, in the order they need it: is the
 * wave running, is each branch keeping up, is there quota left today, is the
 * pipeline draining, how much disk has this consumed, and does anything fail to
 * reconcile. Each panel below answers exactly one of those.
 *
 * IT CHANGES NOTHING. No enable, no retry, no requeue, no status rewrite. A
 * dashboard that can act on the pipeline it is measuring stops being a
 * measurement.
 *
 * EVERY PROBE IS GUARDED. A missing table, a non-database queue driver or an
 * unreadable disk yields NULL — "not measurable" — never a zero. A fabricated
 * zero here would read as "healthy, nothing pending", which is the single most
 * dangerous thing an operations panel can say. That is the ROLL-2 lesson, and it
 * is why nothing in this class invents a number it did not measure.
 *
 * PII POLICY. Counts, branch codes, statuses, byte totals and timings. Never a
 * patient name, a Nomor RM, a KTP/NIK, a filename or a document path.
 */
class LegacyRmeMigrationOperationsService
{
    public function __construct(
        private readonly LegacyRmeWaveBindingService $binding,
        private readonly LegacyRmeOperationsGateService $gate,
        private readonly LegacyRmeMigrationQuotaService $quota,
        private readonly LegacyRmeMigrationReconciliationService $reconciliation,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeIngestionCapacityService $capacity,
        private readonly LegacyRmeStorageService $storage,
    ) {}

    /**
     * The whole operational report for one wave (or the declared one).
     *
     * @return array<string, mixed>
     */
    public function overview(?LegacyRmeMigrationWave $wave = null): array
    {
        $wave ??= $this->binding->resolveWave();
        $measured = $this->capacity->evaluate();

        return [
            'sprint' => (string) config('legacy_rme_operations.sprint'),
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) app()->environment(),

            'operations' => [
                'enforced' => $this->gate->enforced(),
                'registered' => $wave !== null,
            ],

            // ROLL-3's gates are REPORTED here, never re-decided. The operations
            // layer narrows what admission already permitted; showing them side
            // by side is what lets a reader see which gate refused a document.
            'admission' => [
                'enforced' => $this->admission->enforced(),
                'wave' => $this->admission->wave(),
                'admitted_branch_codes' => $this->admission->admittedBranchCodes(),
                'approval_reference' => $this->admission->approvalReference() !== ''
                    ? $this->admission->approvalReference()
                    : null,
                'approved_branch_codes' => $this->admission->approvedBranchCodes(),
                'unapproved_admitted' => $this->admission->unapprovedAdmittedBranchCodes(),
            ],

            'binding' => $this->binding->bindingReport($wave),

            'wave' => $wave === null ? null : [
                'code' => $wave->code,
                'name' => $wave->name,
                'status' => $wave->status,
                'ingesting' => $wave->status === LegacyRmeWaveStatus::ACTIVE,
                'planned_start_date' => $wave->planned_start_date?->toDateString(),
                'planned_end_date' => $wave->planned_end_date?->toDateString(),
                'daily_quota' => $this->quota->waveDailyLimit($wave),
                'per_branch_daily_quota' => $wave->per_branch_daily_quota,
                'approved_at' => $wave->approved_at?->toIso8601String(),
                'activated_at' => $wave->activated_at?->toIso8601String(),
                'paused_at' => $wave->paused_at?->toIso8601String(),
                'completed_at' => $wave->completed_at?->toIso8601String(),
            ],

            'branches' => $wave === null ? [] : $this->branchProgress($wave),
            'quota_today' => $wave === null ? [] : $this->quotaToday($wave),

            'queue' => [
                'render_queue' => (string) config('legacy_rme.processing.queue', 'legacy-rme-documents'),
                'capacity_enforced' => $this->capacity->enforced(),
                'available' => $measured->available,
                'code' => $measured->code,
            ] + $measured->measurements,

            'storage' => $this->storageFootprint($wave),
            'backlog' => $this->backlog($wave),
            'reconciliation' => $wave === null ? null : $this->reconciliation->forWave($wave)->toArray(),
        ];
    }

    /**
     * Per-branch progress.
     *
     * `planned_document_count` is NULL unless a human counted the archive, and
     * `completion_percent` is NULL with it. A percentage against an invented
     * denominator would be worse than no percentage at all.
     *
     * @return list<array<string, mixed>>
     */
    public function branchProgress(LegacyRmeMigrationWave $wave): array
    {
        $reconciliations = $this->reconciliation->perBranch($wave);
        $rows = [];

        foreach ($wave->branches()->orderBy('branch_code')->get() as $branch) {
            $code = (string) $branch->branch_code;
            $recon = $reconciliations[$code] ?? null;
            $planned = $branch->planned_document_count;
            $published = $recon?->published ?? 0;

            $rows[] = [
                'branch_code' => $code,
                'status' => $branch->status,
                'ingesting' => $branch->isActive() && $wave->status === LegacyRmeWaveStatus::ACTIVE,
                'daily_quota' => $branch->effectiveDailyQuota($wave),
                'planned_document_count' => $planned,
                'accepted' => $recon?->accepted ?? 0,
                'published' => $published,
                'failed_unresolved' => $recon?->failedUnresolved ?? 0,
                'in_flight' => $recon?->inFlight ?? 0,
                'cancelled' => $recon?->cancelled ?? 0,
                'stale_processing' => $recon?->staleProcessing ?? 0,
                'unexplained' => $recon?->unexplained ?? 0,
                'quota_drift' => $recon?->quotaDrift ?? 0,
                'completion_percent' => ($planned !== null && $planned > 0)
                    ? (int) floor($published / $planned * 100)
                    : null,
                'completable' => $recon?->completable() ?? false,
                'blockers' => $recon?->blockers() ?? [],
                'completed_at' => $branch->completed_at?->toIso8601String(),
                'assigned_operators' => $this->assignedOperatorCount($wave, (int) $branch->branch_id),
            ];
        }

        return $rows;
    }

    /**
     * Today's quota consumption per branch, plus the wave-wide total.
     *
     * @return array<string, mixed>
     */
    public function quotaToday(LegacyRmeMigrationWave $wave): array
    {
        $waveLimit = $this->quota->waveDailyLimit($wave);
        $branches = [];
        $total = 0;

        foreach ($wave->branches()->orderBy('branch_code')->get() as $branch) {
            $consumed = $this->quota->consumedToday($wave, (int) $branch->branch_id);
            $limit = $branch->effectiveDailyQuota($wave);
            // The wave total is the same on every row (it is a sum across the
            // whole wave), but it is read from the probe rather than accumulated
            // from the per-branch figures: a branch that consumed quota and was
            // later un-enrolled still counts against the wave, and summing only
            // the enrolled rows would quietly under-report it.
            $total = max($total, $consumed['wave']);

            $branches[(string) $branch->branch_code] = [
                'consumed' => $consumed['branch'],
                'limit' => $limit,
                // NULL limit means no ceiling declared, so "remaining" is not a
                // number that exists.
                'remaining' => $limit === null ? null : max(0, $limit - $consumed['branch']),
            ];
        }

        return [
            'date' => $this->quota->today()->toDateString(),
            'wave_consumed' => $total,
            'wave_limit' => $waveLimit,
            'wave_remaining' => $waveLimit === null ? null : max(0, $waveLimit - $total),
            'branches' => $branches,
        ];
    }

    /**
     * What the archive has actually cost in storage, and what the disk has left.
     *
     * Measured from the rows themselves (`size_bytes`, `page_count`), not
     * estimated. The per-document averages are what a growth forecast is built
     * from, and they are labelled as measured so nobody has to guess whether a
     * number was observed or assumed.
     *
     * @return array<string, mixed>
     */
    public function storageFootprint(?LegacyRmeMigrationWave $wave): array
    {
        try {
            if (! Schema::hasTable('stg_rme_legacy_imports')) {
                return ['measurable' => false];
            }

            $scope = LegacyRmeImport::query()
                ->when($wave !== null, static fn ($query) => $query->where('migration_wave_id', $wave->getKey()));

            $documents = (clone $scope)->count();
            $bytes = (int) (clone $scope)->sum('size_bytes');
            $pages = (int) (clone $scope)->sum('page_count');

            return [
                'measurable' => true,
                'scope' => $wave?->code ?? 'ALL',
                'documents' => $documents,
                'source_bytes' => $bytes,
                'rendered_pages' => $pages,
                'average_source_bytes' => $documents > 0 ? (int) round($bytes / $documents) : null,
                'average_pages' => $documents > 0 ? (int) round($pages / $documents) : null,
                'disk_free_bytes' => $this->diskFreeBytes(),
            ];
        } catch (Throwable) {
            return ['measurable' => false];
        }
    }

    /**
     * Free space on the private archive disk, or NULL when not measurable.
     *
     * Delegates to the ROLL-3 capacity probe rather than stat-ing the disk
     * again: that probe is already the one admission throttles on, and a second
     * implementation could disagree with it — which would put a dashboard
     * reading "plenty of room" beside a gate refusing uploads for low disk.
     */
    public function diskFreeBytes(): ?int
    {
        return $this->capacity->freeDiskBytes();
    }

    /**
     * Where the wave is bottlenecked: the pipeline, or the reviewers.
     *
     * A growing review backlog with a healthy queue is a PEOPLE problem, and its
     * remedy (more reviewers, or a smaller quota) is the opposite of the remedy
     * for a stalled worker. Separating them is the point of this panel.
     *
     * @return array<string, mixed>
     */
    public function backlog(?LegacyRmeMigrationWave $wave): array
    {
        try {
            if (! Schema::hasTable('stg_rme_legacy_imports')) {
                return ['measurable' => false];
            }

            $scope = static fn () => LegacyRmeImport::query()
                ->when($wave !== null, static fn ($query) => $query->where('migration_wave_id', $wave->getKey()));

            $oldestReview = $scope()
                ->where('status', LegacyRmeImportStatus::READY_FOR_REVIEW)
                ->min('updated_at');

            $warningHours = (int) config('legacy_rme_operations.monitoring.review_backlog_warning_hours', 48);
            $oldestHours = $oldestReview === null ? null : (int) max(0, now()->diffInHours($oldestReview, true));

            return [
                'measurable' => true,
                'awaiting_review' => $scope()->where('status', LegacyRmeImportStatus::READY_FOR_REVIEW)->count(),
                'reviewed_awaiting_publish' => $scope()->where('status', LegacyRmeImportStatus::REVIEWED)->count(),
                'processing' => $scope()->where('status', LegacyRmeImportStatus::PROCESSING)->count(),
                'queued' => $scope()->where('status', LegacyRmeImportStatus::QUEUED)->count(),
                'failed' => $scope()->where('status', LegacyRmeImportStatus::FAILED)->count(),
                'oldest_awaiting_review_hours' => $oldestHours,
                'review_backlog_warning' => $oldestHours !== null && $warningHours > 0 && $oldestHours >= $warningHours,
            ];
        } catch (Throwable) {
            return ['measurable' => false];
        }
    }

    /**
     * A deterministic QA sample of PUBLISHED records produced by this wave.
     *
     * DETERMINISTIC (oldest first) so two operators auditing the same wave audit
     * the same documents and can compare findings.
     *
     * STRUCTURAL FACTS ONLY — record id, branch code, the declared clinical date
     * range, page count and whether the stored evidence is still present. A QA
     * sample is a completeness check, not a clinical review, and it is not a
     * place to render patient identity.
     *
     * @return list<array<string, mixed>>
     */
    public function qaSample(LegacyRmeMigrationWave $wave, ?int $limit = null): array
    {
        $limit ??= (int) config('legacy_rme_operations.monitoring.qa_sample_size', 5);

        try {
            $records = LegacyRmeRecord::query()
                ->where('status', LegacyRmeRecordStatus::PUBLISHED)
                ->whereIn('source_import_id', LegacyRmeImport::query()
                    ->where('migration_wave_id', $wave->getKey())
                    ->select('id'))
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get();
        } catch (Throwable) {
            return [];
        }

        return $records->map(function (LegacyRmeRecord $record): array {
            return [
                'record_id' => (int) $record->getKey(),
                'branch_code' => $record->originBranch?->code,
                'rme_date' => $record->rme_date?->toDateString(),
                'latest_rme_date' => $record->latest_rme_date?->toDateString(),
                'page_count' => (int) $record->page_count,
                'status' => (string) $record->status,
                // Evidence presence, checked rather than assumed: a published
                // record whose private source vanished is exactly what a
                // completeness sample exists to find.
                'source_present' => $this->sourcePresent($record),
            ];
        })->all();
    }

    private function sourcePresent(LegacyRmeRecord $record): ?bool
    {
        try {
            $path = (string) $record->source_pdf_path;

            if ($path === '') {
                return false;
            }

            return $this->storage->exists($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function assignedOperatorCount(LegacyRmeMigrationWave $wave, int $branchId): int
    {
        return $wave->operators()
            ->whereNull('revoked_at')
            ->where('branch_id', $branchId)
            ->count();
    }

    /**
     * Enrolled branches for a wave, ordered for stable presentation.
     *
     * @return Collection<int, LegacyRmeWaveBranch>
     */
    public function enrolledBranches(LegacyRmeMigrationWave $wave)
    {
        return $wave->branches()->with('branch')->orderBy('branch_code')->get();
    }

    /**
     * Every registered wave, newest first, for the operations index.
     *
     * @return Collection<int, LegacyRmeMigrationWave>
     */
    public function waves()
    {
        return LegacyRmeMigrationWave::query()->orderByDesc('id')->get();
    }

    /**
     * Whether a user may see the operations surface at all. Presentation only —
     * the routes and the policy are the boundary.
     */
    public function visibleTo(?User $user): bool
    {
        return $user !== null && $user->canAny([
            'view_legacy_rme_migration_operations',
            'manage_legacy_rme_migration_operations',
        ]);
    }
}
