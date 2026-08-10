<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * LEGACY-RME-PDF-1B — the only place legacy archive bytes touch a filesystem.
 *
 * Guarantees:
 *  - the configured disk is asserted to be a PRIVATE disk before the first
 *    byte is written (a misconfiguration fails loudly, it does not silently
 *    publish clinical documents);
 *  - every path segment is opaque — record ids and UUIDs only. A patient name,
 *    KTP/NIK, phone number, diagnosis or doctor name is never part of a path;
 *  - the absolute filesystem path never leaves this service.
 */
class LegacyRmeStorageService
{
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        $disk = config('legacy_rme.storage.disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : 'legacy_rme_private';

        $this->assertPrivateDisk($disk);

        return $disk;
    }

    /**
     * A misconfigured disk is a security failure, not a warning: refuse rather
     * than write a clinical archive somewhere publicly reachable.
     */
    public function assertPrivateDisk(string $disk): void
    {
        $forbidden = (array) config('legacy_rme.storage.forbidden_disks', ['public']);

        if (in_array($disk, $forbidden, true)) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_STORAGE_FAILED,
                'Konfigurasi penyimpanan arsip RME lama tidak aman.',
            );
        }

        $config = config('filesystems.disks.'.$disk);

        if (! is_array($config)) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_STORAGE_FAILED,
                'Konfigurasi penyimpanan arsip RME lama tidak ditemukan.',
            );
        }

        // `serve` is checked as well as `visibility`/`url`: a local disk with
        // serve enabled gets a framework `storage.{disk}` route, which would
        // make archive files addressable outside the policy-gated controller.
        if (($config['visibility'] ?? 'private') === 'public'
            || array_key_exists('url', $config)
            || ($config['serve'] ?? false) === true) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_STORAGE_FAILED,
                'Konfigurasi penyimpanan arsip RME lama tidak aman.',
            );
        }
    }

    /**
     * Base directory of one import. Opaque by construction: an integer surrogate
     * key for the patient and the import's own UUID.
     */
    public function importDirectory(int $patientId, string $importUuid): string
    {
        return sprintf('%s/imports/p%d/%s', $this->prefix(), $patientId, $importUuid);
    }

    public function sourcePath(int $patientId, string $importUuid): string
    {
        return $this->importDirectory($patientId, $importUuid).'/source/source.pdf';
    }

    public function pagePath(int $patientId, string $importUuid, int $pageNumber): string
    {
        return sprintf('%s/pages/page-%04d.png', $this->importDirectory($patientId, $importUuid), $pageNumber);
    }

    public function thumbnailPath(int $patientId, string $importUuid, int $pageNumber): string
    {
        return sprintf('%s/thumbnails/page-%04d.png', $this->importDirectory($patientId, $importUuid), $pageNumber);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * @throws LegacyRmePdfException
     */
    public function putFile(string $path, string $absoluteSourcePath): void
    {
        $stream = @fopen($absoluteSourcePath, 'rb');

        if ($stream === false) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }

        try {
            $written = $this->disk()->put($path, $stream);
        } catch (\Throwable $exception) {
            $this->logStorageFailure('put', $exception);
            $written = false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }
    }

    /**
     * Absolute path for read-only consumption by a bounded local process
     * (Poppler). Never returned to a client and never rendered.
     */
    public function absolutePathForProcessing(string $path): ?string
    {
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        try {
            $absolute = $disk->path($path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($absolute) && is_file($absolute) ? $absolute : null;
    }

    public function deleteDirectory(string $directory): void
    {
        try {
            $this->disk()->deleteDirectory($directory);
        } catch (\Throwable $exception) {
            $this->logStorageFailure('delete_directory', $exception);
        }
    }

    /**
     * Remove previously rendered output so a retry can never mix stale pages
     * with fresh ones. The SOURCE pdf is deliberately left untouched — it is
     * immutable once stored.
     */
    public function deleteRenderedOutput(int $patientId, string $importUuid): void
    {
        $base = $this->importDirectory($patientId, $importUuid);

        $this->deleteDirectory($base.'/pages');
        $this->deleteDirectory($base.'/thumbnails');
    }

    private function prefix(): string
    {
        $prefix = config('legacy_rme.storage.path_prefix', 'rme-legacy');

        return is_string($prefix) && $prefix !== '' ? trim($prefix, '/') : 'rme-legacy';
    }

    private function logStorageFailure(string $operation, \Throwable $exception): void
    {
        // The path itself is intentionally omitted — it identifies a patient's
        // archive location and belongs in neither the log nor the response.
        Log::warning('legacy_rme.storage_failed', [
            'operation' => $operation,
            'exception' => $exception::class,
        ]);
    }
}
