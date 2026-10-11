<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
    /**
     * AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 — shown when a registration
     * carried a KTP photo that did NOT become the patient's archived document.
     * The patient is saved either way; the operator must never believe a KTP
     * was archived when it was not.
     */
    public const NOT_ATTACHED_WARNING = 'Pasien tersimpan, tetapi foto KTP TIDAK ikut tersimpan di arsip pasien (foto sudah tidak berlaku atau gagal disimpan).';

    private const DISK = 'local';

    private const TEMP_DIR = 'tmp/patient-ktp-scans';

    private const DOC_DIR = 'patient-documents';

    /**
     * Validate, compress and store a scanned KTP image under a temp token.
     *
     * @return array{token: string, mime_type: string, original_size: int, compressed_file_size: int, width: ?int, height: ?int}
     */
    public function storeTempFromBase64(string $base64, ?string $declaredMime, ?string $originalFilename, int $userId, ?string $replacesToken = null): array
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

        // REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — decompression-bomb guard.
        // Read from the header only, BEFORE GD decodes the pixels (a small file
        // can declare an enormous canvas and exhaust worker memory).
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $maxDimension = (int) config('scanner.ktp.max_dimension', 12000);
        $maxPixels = (int) config('scanner.ktp.max_pixels', 40_000_000);
        if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || $width * $height > $maxPixels) {
            throw new RuntimeException('Dimensi gambar KTP tidak valid.');
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
            // Identity: the archive must hold exactly these bytes (checked on attach).
            'checksum' => hash('sha256', $compressed['binary']),
            'file_path' => $path,
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        // A retake supersedes the previous capture: drop that temp image so a
        // registration session never accumulates duplicate identity images.
        // Scoped to THIS user's temp folder by construction (metaPath).
        if (is_string($replacesToken) && $replacesToken !== '') {
            $this->discardTemp($replacesToken, $userId);
        }

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
     * record the PatientDocument row. A missing/invalid token, or any failure
     * on the way, returns null (patient creation must not fail because of it)
     * and the caller tells the operator — see {@see NOT_ATTACHED_WARNING}.
     *
     * AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 — the local disk does not throw
     * (`throw => false`), so every step is verified instead of assumed: the
     * temp bytes are non-empty and match the checksum recorded at upload, the
     * archive file reads back identical, and a document row that cannot be
     * written takes its archive file away again (a transaction cannot roll
     * back a file). The temp photo is only released once the archive and its
     * record both exist, so a failure never loses the confirmed image.
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

        // Claim the photo first: renaming its meta succeeds for exactly ONE
        // request, so a token submitted twice at the same time (double-click
        // on Save) is archived at most once. Every failure puts the claim back
        // so the confirmed image is never lost.
        $claimPath = self::TEMP_DIR.'/'.$userId.'/'.$token.'.claim-'.Str::lower(Str::random(12)).'.json';
        if (! $this->moveQuietly($disk, $metaPath, $claimPath)) {
            return null;
        }
        $release = function (string $reason) use ($disk, $claimPath, $metaPath, $patient, $userId): null {
            $this->moveQuietly($disk, $claimPath, $metaPath);

            return $this->notAttached($reason, $patient, $userId);
        };

        $meta = json_decode((string) $disk->get($claimPath), true);
        $meta = is_array($meta) ? $meta : [];
        $tempPath = $meta['file_path'] ?? null;
        $ownPrefix = self::TEMP_DIR.'/'.$userId.'/'.$token.'.';

        if (! is_string($tempPath) || ! str_starts_with($tempPath, $ownPrefix) || ! $disk->exists($tempPath)) {
            $disk->delete($claimPath);

            return null;
        }

        $binary = $disk->get($tempPath);
        if (! is_string($binary) || $binary === '') {
            return $release('temp_unreadable');
        }

        $checksum = hash('sha256', $binary);
        $expected = $meta['checksum'] ?? null;
        if (is_string($expected) && $expected !== '' && ! hash_equals($expected, $checksum)) {
            return $release('temp_checksum_mismatch');
        }

        $mime = $meta['mime_type'] ?? 'application/octet-stream';
        $ext = $this->extensionForMime($mime);
        $finalPath = self::DOC_DIR.'/'.$patient->id.'/ktp-'.now()->format('Ymd-His').'-'.Str::random(8).'.'.$ext;

        // `throw => false` only silences UnableToWriteFile; a directory that
        // cannot be created still throws, so both outcomes are handled here.
        try {
            $written = $disk->put($finalPath, $binary) === true ? $disk->get($finalPath) : null;
        } catch (Throwable) {
            $written = null;
        }
        if (! is_string($written) || ! hash_equals($checksum, hash('sha256', $written))) {
            $disk->delete($finalPath);

            return $release('archive_write_failed');
        }

        try {
            // Nested transaction = savepoint, so a failed insert never poisons
            // an enclosing PostgreSQL transaction.
            $document = DB::transaction(fn () => $patient->documents()->create([
                'document_type' => PatientDocument::TYPE_KTP,
                'file_path' => $finalPath,
                'original_filename' => $meta['original_filename'] ?? null,
                'mime_type' => $mime,
                'file_size' => $meta['original_size'] ?? strlen($binary),
                'compressed_file_size' => strlen($binary),
                'checksum' => $checksum,
                'uploaded_by' => $userId,
            ]));
        } catch (Throwable) {
            $disk->delete($finalPath);

            return $release('document_record_failed');
        }

        // Invalidate the temp token + file once promoted.
        $disk->delete([$tempPath, $claimPath]);

        return $document;
    }

    /** A rename that reports failure instead of throwing (the local disk can still throw). */
    private function moveQuietly(Filesystem $disk, string $from, string $to): bool
    {
        try {
            return $disk->move($from, $to) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ids and a reason code only — never a path, a name or a KTP number.
     */
    private function notAttached(string $reason, Patient $patient, int $userId): null
    {
        Log::warning('patient_ktp_document_not_attached', [
            'reason' => $reason,
            'patient_id' => $patient->id,
            'user_id' => $userId,
        ]);

        return null;
    }

    /**
     * Delete a not-yet-attached temp scan owned by $userId. A token that is
     * unknown, already promoted, or belongs to another user is a silent no-op:
     * the path is always rebuilt inside the caller's own temp folder from a
     * sanitized token, so a crafted value cannot reach any other file.
     */
    public function discardTemp(string $token, int $userId): void
    {
        $token = $this->sanitizeToken($token);
        if ($token === '') {
            return;
        }

        $disk = Storage::disk(self::DISK);
        $metaPath = $this->metaPath($userId, $token);
        if (! $disk->exists($metaPath)) {
            return;
        }

        $meta = json_decode((string) $disk->get($metaPath), true);
        $tempPath = is_array($meta) ? ($meta['file_path'] ?? null) : null;
        $ownPrefix = self::TEMP_DIR.'/'.$userId.'/'.$token.'.';

        $paths = [$metaPath];
        if (is_string($tempPath) && str_starts_with($tempPath, $ownPrefix)) {
            $paths[] = $tempPath;
        }
        $disk->delete($paths);
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
