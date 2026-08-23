<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — the single storage authority for native
 * clinical evidence binaries (RME handwriting pages, prescription and doctor
 * signature canvases).
 *
 * Why this exists as one class rather than a disk name repeated at each call
 * site: the incident this sprint remediates was caused by clinical evidence
 * being written to the 'public' disk, which is symlinked into the document root
 * and served without authentication. A single named authority means a future
 * writer cannot silently pick a different (or public) disk, and the governance
 * check has exactly one symbol to assert against.
 *
 * The disk is intentionally NOT configurable per call site. Callers ask this
 * class for the disk; they never name one themselves.
 */
final class ClinicalEvidenceStorage
{
    /**
     * Disks that must never hold clinical evidence because the web server can
     * serve them without passing through DaengtisiaMS authorization.
     *
     * @var list<string>
     */
    public const FORBIDDEN_DISKS = ['public'];

    public static function diskName(): string
    {
        return (string) config('filesystems.clinical_evidence_disk', 'clinical_evidence');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /**
     * True when the stored value is an inline data URI rather than an object
     * key. Some legacy rows persisted the canvas inline; those never touched
     * the filesystem and are already private, so they are passed through
     * unchanged by every read path.
     */
    public static function isInlineDataUri(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, 'data:image/');
    }

    /**
     * Read a stored artifact back as a data URI so a print/PDF template can
     * embed it without the binary ever gaining a URL. Mirrors the approach
     * already proven for consent signatures.
     *
     * Returns null when the object is absent — a caller must render an honest
     * "not available" state rather than a broken image.
     */
    public static function dataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (self::isInlineDataUri($path)) {
            return $path;
        }

        $disk = self::disk();

        if (! $disk->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $disk->get($path));
    }

    public static function exists(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return self::isInlineDataUri($path) || self::disk()->exists($path);
    }
}
