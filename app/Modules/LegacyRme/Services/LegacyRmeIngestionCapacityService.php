<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Support\LegacyRmeIngestionCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * LEGACY-RME-PDF-ROLL-3 — backpressure for legacy document ingestion.
 *
 * WHY. Multi-branch migration multiplies render load onto ONE worker. Poppler
 * rasterization is minutes of CPU and hundreds of MiB per document, so several
 * branches uploading at once can grow the queue faster than it drains. Two
 * failures follow, and both are worse than a refused upload: a stalled queue
 * that silently swallows every new import (the ROLL-2 pilot symptom), and a
 * disk that fills up mid-render, which endangers evidence already stored.
 *
 * WHAT IT DOES. Closes admission for NEW ingestion when the pipeline is
 * saturated. It NEVER cancels, re-queues, re-prioritises or otherwise touches
 * work already in flight — throttling intake is safe, surgery on live clinical
 * jobs is not. Existing jobs keep draining, and ingestion reopens by itself
 * once they have.
 *
 * READ-ONLY AND GUARDED. It counts rows and stats a filesystem. Any probe that
 * throws (missing jobs table on a non-database queue driver, an unreadable
 * disk) degrades to "not measurable" and is reported as such — a probe that
 * cannot be evaluated never silently blocks a legitimate clinical migration,
 * and never silently permits one either: `blocked` is only ever set by a
 * threshold that was actually measured and actually exceeded.
 *
 * A threshold of 0 disables that individual probe.
 */
class LegacyRmeIngestionCapacityService
{
    public function enforced(): bool
    {
        return (bool) config('legacy_rme_rollout.capacity.enforced', true);
    }

    public function renderQueueName(): string
    {
        return (string) config('legacy_rme.processing.queue', 'legacy-rme-documents');
    }

    /**
     * Measure the pipeline and decide whether new ingestion may start.
     */
    public function evaluate(): LegacyRmeIngestionCapacity
    {
        $queue = $this->renderQueueName();

        $maxPending = (int) config('legacy_rme_rollout.capacity.max_pending_jobs', 0);
        $maxOldest = (int) config('legacy_rme_rollout.capacity.max_oldest_pending_seconds', 0);
        $minFree = (int) config('legacy_rme_rollout.capacity.min_free_disk_bytes', 0);

        $pending = $this->pendingJobs($queue);
        $oldest = $this->oldestPendingSeconds($queue);
        $free = $this->freeDiskBytes();

        $measurements = [
            'render_queue' => $queue,
            'pending_jobs' => $pending,
            'oldest_pending_seconds' => $oldest,
            'free_disk_bytes' => $free,
            'max_pending_jobs' => $maxPending,
            'max_oldest_pending_seconds' => $maxOldest,
            'min_free_disk_bytes' => $minFree,
            'enforced' => $this->enforced(),
        ];

        if (! $this->enforced()) {
            return LegacyRmeIngestionCapacity::available($measurements);
        }

        if ($maxPending > 0 && $pending !== null && $pending >= $maxPending) {
            return LegacyRmeIngestionCapacity::saturated(
                LegacyRmeIngestionCapacity::CODE_QUEUE_DEPTH,
                'Antrean pemrosesan arsip sedang penuh. Tunggu dokumen yang sedang diproses selesai sebelum mengunggah lagi.',
                $measurements,
            );
        }

        if ($maxOldest > 0 && $oldest !== null && $oldest >= $maxOldest) {
            return LegacyRmeIngestionCapacity::saturated(
                LegacyRmeIngestionCapacity::CODE_QUEUE_STALLED,
                'Dokumen terlama di antrean belum diproses. Periksa worker pemrosesan sebelum mengunggah dokumen baru.',
                $measurements,
            );
        }

        if ($minFree > 0 && $free !== null && $free < $minFree) {
            return LegacyRmeIngestionCapacity::saturated(
                LegacyRmeIngestionCapacity::CODE_DISK_LOW,
                'Ruang penyimpanan arsip tidak lagi mencukupi untuk memproses dokumen baru dengan aman.',
                $measurements,
            );
        }

        return LegacyRmeIngestionCapacity::available($measurements);
    }

    /**
     * Pending jobs on the dedicated render queue. Null when the count cannot be
     * taken — a non-database queue driver has no `jobs` table to read, and
     * guessing zero there would fake headroom that was never measured.
     */
    public function pendingJobs(?string $queue = null): ?int
    {
        $queue ??= $this->renderQueueName();

        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return null;
            }

            return (int) DB::table('jobs')->where('queue', $queue)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Age of the OLDEST pending job in seconds, or null when not measurable.
     *
     * `available_at` is a unix timestamp on Laravel's database queue, so this
     * arithmetic stays portable across PostgreSQL and SQLite by being done in
     * PHP rather than in SQL.
     */
    public function oldestPendingSeconds(?string $queue = null): ?int
    {
        $queue ??= $this->renderQueueName();

        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return null;
            }

            $oldest = DB::table('jobs')->where('queue', $queue)->min('available_at');

            if ($oldest === null) {
                return 0;
            }

            return max(0, time() - (int) $oldest);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Free bytes on the volume backing the private legacy disk.
     *
     * Reported for the configured disk root when it is a local path; a remote
     * disk has no meaningful local free space, so the probe reports null rather
     * than measuring the wrong filesystem.
     */
    public function freeDiskBytes(): ?int
    {
        try {
            $disk = (string) config('legacy_rme.storage.disk', 'local');
            $root = config("filesystems.disks.{$disk}.root");

            if (! is_string($root) || $root === '' || ! is_dir($root)) {
                return null;
            }

            $free = @disk_free_space($root);

            return is_float($free) && $free >= 0 ? (int) $free : null;
        } catch (Throwable) {
            return null;
        }
    }
}
