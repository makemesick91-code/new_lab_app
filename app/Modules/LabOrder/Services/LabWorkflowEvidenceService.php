<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
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

    private const MAX_BYTES = 10 * 1024 * 1024; // matches existing lab attachment cap (10240 KB)

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Store an uploaded photo as typed evidence for a V2 order.
     */
    public function storePhoto(LabOrder $order, string $type, UploadedFile $file, User $actor): LabWorkflowEvidence
    {
        $this->assertKnownType($type);

        $binary = (string) file_get_contents($file->getRealPath());

        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
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

        $path = $this->generatePath($order, $type, self::ALLOWED_IMAGE_MIMES[$mime]);

        return $this->persist($order, $type, $path, $binary, $mime, $actor);
    }

    /**
     * Store an already-validated PNG (signature canvas) as typed evidence.
     * Used by the Phase 4 delivery signatures; decoding/magic-byte checks are
     * the caller's responsibility (PrescriptionCanvasDecoder pattern).
     */
    public function storePng(LabOrder $order, string $type, string $binary, User $actor): LabWorkflowEvidence
    {
        $this->assertKnownType($type);

        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran berkas tanda tangan tidak valid.',
            ]);
        }

        if (substr($binary, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages([
                'file' => 'Berkas tanda tangan harus berupa PNG yang valid.',
            ]);
        }

        $path = $this->generatePath($order, $type, 'png');

        return $this->persist($order, $type, $path, $binary, 'image/png', $actor);
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
        string $binary,
        string $mime,
        User $actor,
    ): LabWorkflowEvidence {
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($path, $binary)) {
            throw ValidationException::withMessages([
                'file' => 'Gagal menyimpan berkas bukti. Coba lagi.',
            ]);
        }

        try {
            return DB::transaction(function () use ($order, $type, $path, $binary, $mime, $actor) {
                $evidence = LabWorkflowEvidence::create([
                    'lab_order_id' => $order->id,
                    'branch_id' => $order->branch_id,
                    'type' => $type,
                    'file_path' => $path,
                    'mime_type' => $mime,
                    'file_size' => strlen($binary),
                    'checksum' => hash('sha256', $binary),
                    'uploaded_by' => $actor->id,
                    'captured_at' => now(),
                ]);

                $this->auditLogs->log(
                    LabOrder::ENTITY_TYPE,
                    $order->id,
                    AuditLog::ACTION_UPLOAD_ATTACHMENT,
                    null,
                    ['evidence_type' => $type, 'evidence_id' => $evidence->id, 'checksum' => $evidence->checksum],
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
