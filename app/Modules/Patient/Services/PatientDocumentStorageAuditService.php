<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\PatientDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 61.3 — Patient Scan Document Storage Governance.
 *
 * Read-only audit of the private patient document store. It cross-checks the
 * {@see PatientDocument} rows against the files on the private disk and reports
 * integrity / hygiene anomalies. This service NEVER mutates storage or the
 * database — it only inspects and summarises.
 *
 * Privacy: reports only relative private paths plus opaque ids/checksums. No
 * full KTP number and no image bytes are ever surfaced.
 */
class PatientDocumentStorageAuditService
{
    public function audit(): array
    {
        $disk = $this->disk();
        $privateRoot = (string) config('patient_documents.private_root', 'patient-documents');
        $tempRoot = (string) config('patient_documents.temp_root', 'tmp/patient-ktp-scans');
        $ttlHours = max(1, (int) config('patient_documents.temp_ttl_hours', 24));
        $maxBytes = max(0, (int) config('patient_documents.max_document_bytes', 6291456));

        $records = PatientDocument::withTrashed()->get();
        $active = $records->whereNull('deleted_at');
        $trashed = $records->whereNotNull('deleted_at');

        // Every file path referenced by ANY row (active or soft-deleted).
        $referencedPaths = $records
            ->pluck('file_path')
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->map(fn ($p) => $this->normalize($p))
            ->unique()
            ->all();
        $referencedLookup = array_fill_keys($referencedPaths, true);

        $summary = $this->emptySummary();
        $details = [
            'missing_files' => [],
            'orphan_files' => [],
            'checksum_mismatch' => [],
            'mime_mismatch' => [],
            'size_mismatch' => [],
            'suspicious_path' => [],
            'deleted_records_with_file' => [],
            'stale_temp_files' => [],
            'oversized_files' => [],
            'duplicate_checksum' => [],
        ];

        $summary['total_document_records'] = $records->count();
        $summary['active_document_records'] = $active->count();
        $summary['soft_deleted_document_records'] = $trashed->count();

        // --- Active records vs disk ------------------------------------------
        $activeFilesBytes = 0;
        $activeFilesCount = 0;

        foreach ($active as $record) {
            $path = $this->normalize((string) $record->file_path);

            // 6. file path outside the allowed private document directory.
            if (! $this->isUnder($path, $privateRoot)) {
                $summary['suspicious_path_count']++;
                $details['suspicious_path'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'path' => $path,
                ];
            }

            // 1. record exists but file is missing.
            if (! $disk->exists($path)) {
                $summary['missing_files_count']++;
                $details['missing_files'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'path' => $path,
                ];

                continue;
            }

            $size = (int) $disk->size($path);
            $activeFilesCount++;
            $activeFilesBytes += $size;

            // 9. unusually large scan file.
            if ($maxBytes > 0 && $size > $maxBytes) {
                $summary['oversized_files_count']++;
                $details['oversized_files'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'path' => $path,
                    'bytes' => $size,
                ];
            }

            // 5. compressed_file_size mismatch.
            if ($record->compressed_file_size !== null && (int) $record->compressed_file_size !== $size) {
                $summary['size_mismatch_count']++;
                $details['size_mismatch'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'recorded_bytes' => (int) $record->compressed_file_size,
                    'actual_bytes' => $size,
                ];
            }

            // The remaining checks need the bytes; read once.
            $contents = (string) $disk->get($path);

            // 3. checksum mismatch.
            if (is_string($record->checksum) && $record->checksum !== '') {
                $actualChecksum = hash('sha256', $contents);
                if (! hash_equals($record->checksum, $actualChecksum)) {
                    $summary['checksum_mismatch_count']++;
                    $details['checksum_mismatch'][] = [
                        'document_id' => $record->id,
                        'patient_id' => $record->patient_id,
                        'path' => $path,
                    ];
                }
            }

            // 4. mime type mismatch (only when safely detectable).
            $detectedMime = $this->detectMime($contents);
            if ($detectedMime !== null && is_string($record->mime_type) && $record->mime_type !== ''
                && $detectedMime !== $record->mime_type) {
                $summary['mime_mismatch_count']++;
                $details['mime_mismatch'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'recorded_mime' => $record->mime_type,
                    'detected_mime' => $detectedMime,
                ];
            }
        }

        $summary['active_files_count'] = $activeFilesCount;
        $summary['active_files_bytes'] = $activeFilesBytes;

        // 10. duplicate checksums across active records (report only).
        $summary['duplicate_checksum_count'] = $active
            ->pluck('checksum')
            ->filter(fn ($c) => is_string($c) && $c !== '')
            ->countBy()
            ->filter(fn ($count) => $count > 1)
            ->each(function ($count, $checksum) use (&$details) {
                $details['duplicate_checksum'][] = [
                    'checksum' => $checksum,
                    'count' => $count,
                ];
            })
            ->count();

        // 7. soft-deleted records whose file is still present.
        foreach ($trashed as $record) {
            $path = $this->normalize((string) $record->file_path);
            if ($path !== '' && $disk->exists($path)) {
                $summary['deleted_records_with_file_count']++;
                $details['deleted_records_with_file'][] = [
                    'document_id' => $record->id,
                    'patient_id' => $record->patient_id,
                    'path' => $path,
                ];
            }
        }

        // 2. files under the private root referenced by no record at all.
        foreach ($disk->allFiles($privateRoot) as $file) {
            $normalized = $this->normalize($file);
            if (! isset($referencedLookup[$normalized])) {
                $bytes = (int) $disk->size($normalized);
                $summary['orphan_files_count']++;
                $summary['orphan_files_bytes'] += $bytes;
                $details['orphan_files'][] = [
                    'path' => $normalized,
                    'bytes' => $bytes,
                ];
            }
        }

        // 8. temp scan files older than the configured TTL.
        $cutoff = Carbon::now()->subHours($ttlHours);
        foreach ($disk->allFiles($tempRoot) as $file) {
            $normalized = $this->normalize($file);

            // Skip meta sidecars; they are accounted with their image.
            if (str_ends_with($normalized, '.json')) {
                continue;
            }

            $age = $this->resolveTempTimestamp($disk, $normalized);
            if ($age !== null && $age->lessThan($cutoff)) {
                $bytes = (int) $disk->size($normalized);
                $summary['stale_temp_files_count']++;
                $summary['stale_temp_files_bytes'] += $bytes;
                $details['stale_temp_files'][] = [
                    'path' => $normalized,
                    'bytes' => $bytes,
                ];
            }
        }

        return [
            'summary' => $summary,
            'details' => $details,
            'config' => [
                'disk' => (string) config('patient_documents.disk', 'local'),
                'private_root' => $privateRoot,
                'temp_root' => $tempRoot,
                'temp_ttl_hours' => $ttlHours,
                'max_document_bytes' => $maxBytes,
            ],
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total_document_records' => 0,
            'active_document_records' => 0,
            'soft_deleted_document_records' => 0,
            'active_files_count' => 0,
            'active_files_bytes' => 0,
            'orphan_files_count' => 0,
            'orphan_files_bytes' => 0,
            'stale_temp_files_count' => 0,
            'stale_temp_files_bytes' => 0,
            'missing_files_count' => 0,
            'checksum_mismatch_count' => 0,
            'mime_mismatch_count' => 0,
            'size_mismatch_count' => 0,
            'suspicious_path_count' => 0,
            'deleted_records_with_file_count' => 0,
            'duplicate_checksum_count' => 0,
            'oversized_files_count' => 0,
        ];
    }

    /** Resolve a temp file's age via its meta sidecar created_at, else mtime. */
    private function resolveTempTimestamp(Filesystem $disk, string $imagePath): ?Carbon
    {
        $metaPath = preg_replace('/\.[^.\/]+$/', '.json', $imagePath);
        if (is_string($metaPath) && $metaPath !== $imagePath && $disk->exists($metaPath)) {
            $meta = json_decode((string) $disk->get($metaPath), true);
            $createdAt = is_array($meta) ? ($meta['created_at'] ?? null) : null;
            if (is_string($createdAt) && $createdAt !== '') {
                try {
                    return Carbon::parse($createdAt);
                } catch (\Throwable) {
                    // fall through to mtime.
                }
            }
        }

        $mtime = $disk->lastModified($imagePath);

        return $mtime ? Carbon::createFromTimestamp($mtime) : null;
    }

    private function detectMime(string $contents): ?string
    {
        $info = @getimagesizefromstring($contents);

        return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : null;
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
