<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Support\OptimizedEvidenceImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 — typed evidence storage on the PRIVATE local disk.
 *
 * Follows the KtpScanService reference pattern: never trust the client MIME
 * (re-validated from the real bytes via getimagesizefromstring), sanitized
 * generated filename, sha256 checksum, metadata row + binary kept consistent
 * inside one transaction with disk cleanup when the DB write fails. Files are
 * NEVER exposed through the public storage symlink — serving goes through the
 * authorized evidence controller only.
 */
class LabWorkflowEvidenceService
{
    /** Private disk (storage/app/private) — no public URL exists for it. */
    public const DISK = 'local';

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly LabEvidenceImageOptimizer $optimizer,
    ) {}

    /**
     * Store an uploaded photo as typed evidence for a V2 order. Every photo
     * flows through the canonical adaptive-compression pipeline (config
     * lab_workflow_uploads) — re-encoding strips EXIF/GPS metadata and
     * neutralizes polyglot payloads before the binary ever reaches disk.
     */
    public function storePhoto(LabOrder $order, string $type, UploadedFile $file, User $actor): LabWorkflowEvidence
    {
        $this->assertKnownType($type);

        $binary = (string) file_get_contents($file->getRealPath());
        $maxInput = (int) config('lab_workflow_uploads.max_input_bytes');

        if ($binary === '' || strlen($binary) > $maxInput) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran foto tidak valid (maksimal 10 MB).',
            ]);
        }

        // Real-bytes validation — never trust the client-declared MIME.
        $imageInfo = @getimagesizefromstring($binary);
        $mime = $imageInfo['mime'] ?? null;

        if ($imageInfo === false || ! isset(self::ALLOWED_IMAGE_MIMES[$mime])) {
            throw ValidationException::withMessages([
                'file' => 'File harus berupa gambar JPG, PNG, atau WebP yang valid.',
            ]);
        }

        // Decompression-bomb guard from header bytes, before any full decode.
        $this->optimizer->assertSafeDimensions($imageInfo);

        $optimized = $this->optimizer->optimizePhoto($binary, $mime, $type);

        $path = $this->generatePath($order, $type, $optimized->extension);

        return $this->persist($order, $type, $path, $optimized, $actor);
    }

    /**
     * Store an already-validated PNG (signature canvas) as typed evidence.
     * Used by the Phase 4 delivery signatures; decoding/magic-byte checks are
     * the caller's responsibility (PrescriptionCanvasDecoder pattern).
     */
    public function storePng(LabOrder $order, string $type, string $binary, User $actor): LabWorkflowEvidence
    {
        $this->assertKnownType($type);

        $maxInput = (int) config('lab_workflow_uploads.max_signature_input_bytes');

        if ($binary === '' || strlen($binary) > $maxInput) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran berkas tanda tangan tidak valid.',
            ]);
        }

        if (substr($binary, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages([
                'file' => 'Berkas tanda tangan harus berupa PNG yang valid.',
            ]);
        }

        // Real-bytes decode validation + bomb guard (magic bytes alone can be spoofed).
        $imageInfo = @getimagesizefromstring($binary);

        if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== 'image/png') {
            throw ValidationException::withMessages([
                'file' => 'Berkas tanda tangan harus berupa PNG yang valid.',
            ]);
        }

        $this->optimizer->assertSafeDimensions($imageInfo);

        // Signatures stay PNG: canvas capped, alpha preserved, lossless re-encode.
        $optimized = $this->optimizer->optimizeSignaturePng($binary);

        $path = $this->generatePath($order, $type, 'png');

        return $this->persist($order, $type, $path, $optimized, $actor);
    }

    public function has(LabOrder $order, string $type): bool
    {
        return $order->hasWorkflowEvidence($type);
    }

    public function disk(): string
    {
        return self::DISK;
    }

    private function persist(
        LabOrder $order,
        string $type,
        string $path,
        OptimizedEvidenceImage $optimized,
        User $actor,
    ): LabWorkflowEvidence {
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($path, $optimized->binary)) {
            throw ValidationException::withMessages([
                'file' => 'Gagal menyimpan berkas bukti. Coba lagi.',
            ]);
        }

        try {
            return DB::transaction(function () use ($order, $type, $path, $optimized, $actor) {
                $evidence = LabWorkflowEvidence::create([
                    'lab_order_id' => $order->id,
                    'branch_id' => $order->branch_id,
                    'type' => $type,
                    'file_path' => $path,
                    'mime_type' => $optimized->mime,
                    'file_size' => $optimized->size(),
                    'original_file_size' => $optimized->originalSize,
                    'width' => $optimized->width,
                    'height' => $optimized->height,
                    'compression_method' => $optimized->method,
                    'checksum' => hash('sha256', $optimized->binary),
                    'uploaded_by' => $actor->id,
                    'captured_at' => now(),
                ]);

                $this->auditLogs->log(
                    LabOrder::ENTITY_TYPE,
                    $order->id,
                    AuditLog::ACTION_UPLOAD_ATTACHMENT,
                    null,
                    [
                        'evidence_type' => $type,
                        'evidence_id' => $evidence->id,
                        'checksum' => $evidence->checksum,
                        'original_size' => $optimized->originalSize,
                        'compressed_size' => $optimized->size(),
                        'compression_method' => $optimized->method,
                    ],
                    $actor,
                );

                return $evidence;
            });
        } catch (\Throwable $e) {
            // Keep disk + DB consistent: remove the orphan binary on failure.
            $disk->delete($path);

            throw $e;
        }
    }

    private function generatePath(LabOrder $order, string $type, string $ext): string
    {
        $token = strtolower((string) preg_replace('/[^A-Za-z0-9\-]/', '-', $type));

        return sprintf(
            'lab-workflow-evidence/%d/%s-%s-%s.%s',
            $order->id,
            $token,
            now()->format('Ymd-His'),
            Str::random(8),
            $ext,
        );
    }

    private function assertKnownType(string $type): void
    {
        if (! in_array($type, LabWorkflowEvidence::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => "Jenis bukti tidak dikenal: {$type}.",
            ]);
        }
    }
}
