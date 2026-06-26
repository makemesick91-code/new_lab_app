<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sprint 61.1 — Direct KTP Scanner Capture & Compression.
 *
 * Handles the DaengtisiaMS side of the scanner workflow:
 *   1. {@see storeTempFromBase64()} validates + compresses the scanned image and
 *      parks it on the private disk under a per-user temp folder, returning an
 *      opaque token (the patient may not exist yet at scan time).
 *   2. {@see attachTempToPatient()} promotes that temp file into the patient's
 *      private document folder and records the {@see PatientDocument} row.
 *
 * Compression uses GD when the extension is available; otherwise the validated
 * bytes are stored unchanged (no upscaling, no dependency added). KTP text
 * readability always wins over aggressive size reduction.
 *
 * Privacy: files live on the private `local` disk only. No public URL is ever
 * generated and {@see PatientDocument::$file_path} is never surfaced to the UI.
 */
class KtpScanService
{
    private const DISK = 'local';

    private const TEMP_DIR = 'tmp/patient-ktp-scans';

    private const DOC_DIR = 'patient-documents';

    /**
     * Validate, compress and store a scanned KTP image under a temp token.
     *
     * @return array{token: string, mime_type: string, original_size: int, compressed_file_size: int, width: ?int, height: ?int}
     */
    public function storeTempFromBase64(string $base64, ?string $declaredMime, ?string $originalFilename, int $userId): array
    {
        $binary = $this->decodeBase64($base64);

        $maxBytes = (int) config('scanner.ktp.max_input_kb', 6144) * 1024;
        if (strlen($binary) > $maxBytes) {
            throw new RuntimeException('Berkas hasil scan terlalu besar.');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new RuntimeException('Berkas hasil scan bukan gambar yang valid.');
        }

        $detectedMime = $info['mime'] ?? $declaredMime;
        $allowed = (array) config('scanner.ktp.allowed_mime_types', ['image/jpeg', 'image/png', 'image/webp']);
        if (! in_array($detectedMime, $allowed, true)) {
            throw new RuntimeException('Tipe berkas hasil scan tidak didukung.');
        }

        $originalSize = strlen($binary);
        $compressed = $this->compress($binary, $detectedMime, (int) $info[0], (int) $info[1]);

        $token = (string) Str::uuid();
        $ext = $this->extensionForMime($compressed['mime']);
        $path = self::TEMP_DIR.'/'.$userId.'/'.$token.'.'.$ext;

        Storage::disk(self::DISK)->put($path, $compressed['binary']);
        Storage::disk(self::DISK)->put($this->metaPath($userId, $token), json_encode([
            'mime_type' => $compressed['mime'],
            'original_filename' => $originalFilename,
            'original_size' => $originalSize,
            'compressed_file_size' => strlen($compressed['binary']),
            'file_path' => $path,
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return [
            'token' => $token,
            'mime_type' => $compressed['mime'],
            'original_size' => $originalSize,
            'compressed_file_size' => strlen($compressed['binary']),
            'width' => $compressed['width'],
            'height' => $compressed['height'],
        ];
    }

    /**
     * Promote a temp KTP scan into the patient's private document folder and
     * record the PatientDocument row. Idempotent-safe: a missing/invalid token
     * simply returns null (patient creation must not fail because of it).
     */
    public function attachTempToPatient(Patient $patient, string $token, int $userId): ?PatientDocument
    {
        $token = $this->sanitizeToken($token);
        if ($token === '') {
            return null;
        }

        $metaPath = $this->metaPath($userId, $token);
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($metaPath)) {
            return null;
        }

        $meta = json_decode((string) $disk->get($metaPath), true);
        $tempPath = $meta['file_path'] ?? null;

        if (! is_string($tempPath) || ! $disk->exists($tempPath)) {
            $disk->delete($metaPath);

            return null;
        }

        $binary = (string) $disk->get($tempPath);
        $mime = $meta['mime_type'] ?? 'application/octet-stream';
        $ext = $this->extensionForMime($mime);

        $finalPath = self::DOC_DIR.'/'.$patient->id.'/ktp-'.now()->format('Ymd-His').'-'.Str::random(8).'.'.$ext;
        $disk->put($finalPath, $binary);

        $document = $patient->documents()->create([
            'document_type' => PatientDocument::TYPE_KTP,
            'file_path' => $finalPath,
            'original_filename' => $meta['original_filename'] ?? null,
            'mime_type' => $mime,
            'file_size' => $meta['original_size'] ?? strlen($binary),
            'compressed_file_size' => strlen($binary),
            'checksum' => hash('sha256', $binary),
            'uploaded_by' => $userId,
        ]);

        // Invalidate the temp token + file once promoted.
        $disk->delete([$tempPath, $metaPath]);

        return $document;
    }

    /**
     * Permanently remove the stored file and soft-delete the record so the
     * scan is no longer viewable.
     */
    public function deleteDocument(PatientDocument $document): void
    {
        if ($document->file_path) {
            Storage::disk(self::DISK)->delete($document->file_path);
        }

        $document->delete();
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * Resize (never upscale) to the configured max width and re-encode as JPEG
     * when GD is available. Without GD, the original bytes pass through.
     *
     * @return array{binary: string, mime: string, width: ?int, height: ?int}
     */
    private function compress(string $binary, string $mime, int $width, int $height): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['binary' => $binary, 'mime' => $mime, 'width' => $width ?: null, 'height' => $height ?: null];
        }

        $maxWidth = (int) config('scanner.ktp.max_width', 1600);
        $quality = (int) config('scanner.ktp.quality', 82);

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return ['binary' => $binary, 'mime' => $mime, 'width' => $width ?: null, 'height' => $height ?: null];
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        $targetW = $srcW;
        $targetH = $srcH;
        if ($maxWidth > 0 && $srcW > $maxWidth) {
            $targetW = $maxWidth;
            $targetH = (int) max(1, round($srcH * ($maxWidth / $srcW)));
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        // White background so transparent PNG/WebP flattens cleanly to JPEG.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $out = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($out === '') {
            return ['binary' => $binary, 'mime' => $mime, 'width' => $srcW, 'height' => $srcH];
        }

        return ['binary' => $out, 'mime' => 'image/jpeg', 'width' => $targetW, 'height' => $targetH];
    }

    private function decodeBase64(string $value): string
    {
        $value = trim($value);

        // Strip an optional data URI prefix (e.g. "data:image/jpeg;base64,").
        if (str_contains($value, ',') && str_starts_with($value, 'data:')) {
            $value = substr($value, strpos($value, ',') + 1);
        }

        $value = str_replace([' ', "\n", "\r", "\t"], '', $value);

        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('Data hasil scan tidak valid (base64).');
        }

        return $decoded;
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function metaPath(int $userId, string $token): string
    {
        return self::TEMP_DIR.'/'.$userId.'/'.$token.'.json';
    }

    private function sanitizeToken(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9\-]/', '', trim($token)) ?? '';
    }
}
