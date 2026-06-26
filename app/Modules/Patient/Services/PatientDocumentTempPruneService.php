<?php

namespace App\Modules\Patient\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 61.3 — Patient Scan Document Storage Governance.
 *
 * Safely prunes STALE TEMPORARY KTP scan uploads (pre-attach) from the private
 * disk. It only ever touches files under the configured temp root
 * (tmp/patient-ktp-scans) — final attached patient documents under
 * patient-documents/ are structurally out of reach of this service.
 *
 * Safety: dry-run is the default. Deletion only happens when $force is true.
 * A temp file's age is resolved from its meta sidecar `created_at`, falling
 * back to the filesystem mtime, so freshly uploaded scans are never deleted.
 */
class PatientDocumentTempPruneService
{
    /**
     * @return array{
     *     dry_run: bool,
     *     older_than_hours: int,
     *     would_delete_count: int,
     *     would_delete_bytes: int,
     *     deleted_count: int,
     *     deleted_bytes: int,
     *     temp_root: string,
     * }
     */
    public function prune(int $olderThanHours, bool $force): array
    {
        $olderThanHours = max(1, $olderThanHours);
        $disk = $this->disk();
        $tempRoot = (string) config('patient_documents.temp_root', 'tmp/patient-ktp-scans');
        $cutoff = Carbon::now()->subHours($olderThanHours);

        $files = $disk->allFiles($tempRoot);

        // Group files by token-set (directory + filename stem) so an image and
        // its .json sidecar are evaluated and deleted together.
        $groups = [];
        foreach ($files as $file) {
            $normalized = $this->normalize($file);
            $stem = preg_replace('/\.[^.\/]+$/', '', $normalized);
            $groups[$stem][] = $normalized;
        }

        $wouldDeleteCount = 0;
        $wouldDeleteBytes = 0;
        $deletedCount = 0;
        $deletedBytes = 0;

        foreach ($groups as $paths) {
            $timestamp = $this->resolveTimestamp($disk, $paths);
            if ($timestamp === null || ! $timestamp->lessThan($cutoff)) {
                continue;
            }

            foreach ($paths as $path) {
                // Defence in depth: never act outside the temp root.
                if (! $this->isUnder($path, $tempRoot)) {
                    continue;
                }

                $bytes = (int) $disk->size($path);
                $wouldDeleteCount++;
                $wouldDeleteBytes += $bytes;

                if ($force) {
                    $disk->delete($path);
                    $deletedCount++;
                    $deletedBytes += $bytes;
                }
            }
        }

        return [
            'dry_run' => ! $force,
            'older_than_hours' => $olderThanHours,
            'would_delete_count' => $wouldDeleteCount,
            'would_delete_bytes' => $wouldDeleteBytes,
            'deleted_count' => $deletedCount,
            'deleted_bytes' => $deletedBytes,
            'temp_root' => $tempRoot,
        ];
    }

    /** Age of a token-set: meta sidecar created_at, else earliest mtime. */
    private function resolveTimestamp(Filesystem $disk, array $paths): ?Carbon
    {
        foreach ($paths as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            $meta = json_decode((string) $disk->get($path), true);
            $createdAt = is_array($meta) ? ($meta['created_at'] ?? null) : null;
            if (is_string($createdAt) && $createdAt !== '') {
                try {
                    return Carbon::parse($createdAt);
                } catch (\Throwable) {
                    // fall through to mtime below.
                }
            }
        }

        $earliest = null;
        foreach ($paths as $path) {
            $mtime = $disk->lastModified($path);
            if (! $mtime) {
                continue;
            }
            $ts = Carbon::createFromTimestamp($mtime);
            if ($earliest === null || $ts->lessThan($earliest)) {
                $earliest = $ts;
            }
        }

        return $earliest;
    }

    private function isUnder(string $path, string $root): bool
    {
        $root = rtrim($root, '/').'/';

        return str_starts_with($path, $root);
    }

    private function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('patient_documents.disk', 'local'));
    }
}
