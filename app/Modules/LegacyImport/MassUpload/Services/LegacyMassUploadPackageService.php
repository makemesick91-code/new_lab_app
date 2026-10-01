<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadExtractedDocument;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadPackageContents;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Package intake and safe extraction — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §16.
 *
 * FAIL CLOSED, ALWAYS. Every refusal here is a package-integrity refusal: the
 * batch never begins item dispatch and nothing clinical is created.
 *
 * THE ZIP SLIP DEFENCE IS STRUCTURAL, NOT A SANITISER
 * ---------------------------------------------------
 * ZipArchive::extractTo() is never called. Each entry is streamed to a path
 * THIS CLASS generates (`docs/000001.pdf`), and the archive's own name is kept
 * only as a logical key for manifest matching. An entry named
 * `../../../../etc/cron.d/payload` therefore cannot reach the filesystem at
 * all — the worst it can do is fail to match a manifest row.
 *
 * Unsafe names are STILL rejected rather than quietly tolerated, because an
 * archive containing traversal segments or symlinks is hostile, and importing
 * clinical documents from a hostile archive is not a thing we should do even
 * when we happen to be immune to the specific trick.
 *
 * ARCHIVE BOMBS ARE CHECKED TWICE
 * -------------------------------
 * The central directory is audited before a byte is extracted, and the real
 * decompressed total is counted again during extraction. A crafted central
 * directory can understate its payload, so the pre-check alone is advisory; the
 * streaming counter is what actually holds.
 */
class LegacyMassUploadPackageService
{
    /**
     * macOS resource-fork noise. Present in a large share of operator-produced
     * archives and completely meaningless to us. Ignored rather than rejected,
     * because failing a real migration batch over `__MACOSX/` would be a
     * self-inflicted support burden.
     *
     * @var list<string>
     */
    private const IGNORED_PREFIXES = ['__MACOSX/', '__macosx/'];

    /** @var list<string> */
    private const IGNORED_BASENAMES = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

    public function disk(): Filesystem
    {
        $disk = (string) config('legacy_mass_upload.workspace.disk', 'legacy_mass_upload_private');

        $this->assertPrivateDisk($disk);

        return Storage::disk($disk);
    }

    public function diskName(): string
    {
        return (string) config('legacy_mass_upload.workspace.disk', 'legacy_mass_upload_private');
    }

    /**
     * A public or object-store disk would put clinical scans behind a URL (§39).
     * Refusing at runtime rather than trusting config review means a
     * well-meaning edit cannot quietly expose an archive.
     */
    public function assertPrivateDisk(string $disk): void
    {
        $forbidden = (array) config('legacy_mass_upload.workspace.forbidden_disks', ['public', 's3']);

        if (in_array($disk, $forbidden, true)) {
            throw new \RuntimeException(
                'Legacy mass upload workspace disk is not permitted to be a public or object-store disk.'
            );
        }

        $visibility = config("filesystems.disks.{$disk}.visibility");

        if ($visibility === 'public') {
            throw new \RuntimeException(
                'Legacy mass upload workspace disk must not be publicly visible.'
            );
        }
    }

    public function workspaceRelativeDir(string $batchUuid): string
    {
        $prefix = trim((string) config('legacy_mass_upload.workspace.path_prefix', 'legacy-mass-upload'), '/');

        return $prefix.'/'.$batchUuid;
    }

    /**
     * Store the uploaded archive on the private disk and return its relative
     * path plus digest.
     *
     * The archive is kept (not discarded after extraction) so a resumed batch
     * can re-extract without asking the operator to upload 400 MB again, and so
     * the digest in the audit trail refers to something that still exists.
     *
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function storePackage(UploadedFile $package, string $batchUuid): array
    {
        $this->assertUploadedPackage($package);

        $realPath = $package->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $sha256 = hash_file('sha256', $realPath);

        if (! is_string($sha256)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $relative = $this->workspaceRelativeDir($batchUuid).'/package.zip';

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        try {
            $this->disk()->put($relative, $stream);
        } finally {
            fclose($stream);
        }

        return [
            'path' => $relative,
            'sha256' => $sha256,
            'bytes' => (int) filesize($realPath),
        ];
    }

    /**
     * Technical checks on the upload itself, before ZipArchive is involved.
     *
     * MIME is read from the file's own bytes via getMimeType(), never from the
     * client-supplied Content-Type, which an attacker controls entirely.
     */
    public function assertUploadedPackage(UploadedFile $package): void
    {
        if (! $package->isValid()) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $maxBytes = (int) config('legacy_mass_upload.package.max_bytes', 524288000);

        if ($package->getSize() !== false && $package->getSize() > $maxBytes) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::PACKAGE_TOO_LARGE,
                ['max_bytes' => $maxBytes],
            );
        }

        $allowedExtensions = (array) config('legacy_mass_upload.package.allowed_extensions', ['zip']);
        $extension = strtolower((string) $package->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_NOT_ZIP);
        }

        $allowedMimes = (array) config('legacy_mass_upload.package.allowed_mimes', ['application/zip']);
        $mime = (string) $package->getMimeType();

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_NOT_ZIP);
        }
    }

    /**
     * Validate the archive and extract it into the batch workspace.
     *
     * @param  list<string>  $requiredHeaders
     * @param  list<string>  $optionalHeaders
     */
    public function validateAndExtract(
        string $packageRelativePath,
        string $batchUuid,
        LegacyMassUploadManifestParser $manifestParser,
        string $importType,
    ): LegacyMassUploadPackageContents {
        $disk = $this->disk();

        $packageAbsolute = $disk->path($packageRelativePath);

        if (! is_file($packageAbsolute)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($packageAbsolute, ZipArchive::RDONLY);

        if ($opened !== true) {
            // The ZipArchive error code is deliberately not surfaced. It tells
            // an operator nothing actionable and tells an attacker something.
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        try {
            $plan = $this->auditCentralDirectory($zip);

            $workspaceRelative = $this->workspaceRelativeDir($batchUuid);
            $docsRelative = $workspaceRelative.'/docs';

            // Ensure the docs directory exists on the private disk before any
            // stream is opened against it.
            $disk->makeDirectory($docsRelative);

            $manifest = $this->extractManifest($zip, $plan['manifest_index'], $workspaceRelative);

            $rows = $manifestParser->parse($manifest['absolute_path'], $importType);

            $documents = $this->extractDocuments($zip, $plan['document_indexes'], $docsRelative);

            $this->assertManifestDocumentsPresent($rows, $documents);

            $claimed = [];
            foreach ($rows as $row) {
                $claimed[$row->fileName] = true;
            }

            $unreferenced = array_values(array_diff(array_keys($documents), array_keys($claimed)));

            return new LegacyMassUploadPackageContents(
                workspaceRelativeDir: $workspaceRelative,
                workspaceAbsoluteDir: $disk->path($workspaceRelative),
                manifestSha256: $manifest['sha256'],
                rows: $rows,
                documents: $documents,
                unreferencedDocuments: $unreferenced,
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * Inspect every entry BEFORE extracting anything.
     *
     * @return array{manifest_index: int, document_indexes: array<string, int>}
     */
    private function auditCentralDirectory(ZipArchive $zip): array
    {
        $maxEntries = (int) config('legacy_mass_upload.package.max_entries', 1200);
        $totalMax = (int) config('legacy_mass_upload.package.total_uncompressed_max_bytes', 2147483648);
        $maxRatio = (float) config('legacy_mass_upload.package.max_compression_ratio', 120.0);
        $docMax = (int) config('legacy_mass_upload.package.document_max_bytes', 20971520);
        $manifestName = (string) config('legacy_mass_upload.package.manifest_entry_name', 'manifest.csv');
        $manifestMax = (int) config('legacy_mass_upload.package.manifest_max_bytes', 5242880);
        $docExtensions = (array) config('legacy_mass_upload.package.document_allowed_extensions', ['pdf']);

        $count = $zip->count();

        if ($count > $maxEntries) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::PACKAGE_TOO_MANY_ENTRIES,
                ['max_entries' => $maxEntries, 'found' => $count],
            );
        }

        $manifestIndex = null;
        $documentIndexes = [];
        $seenNames = [];
        $declaredTotal = 0;

        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
            }

            if ($this->isIgnorableEntry($name)) {
                continue;
            }

            // Directory entries carry no payload and are never extracted.
            if (str_ends_with($name, '/')) {
                $this->assertSafeEntryName($name);

                continue;
            }

            $this->assertSafeEntryName($name);
            $this->assertNotSymlink($zip, $i, $name);

            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
            }

            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);

            // Per-entry ratio. A single entry that expands absurdly is the
            // classic bomb shape, and catching it here means we never allocate
            // for it.
            if ($compressed > 0 && $size > 0) {
                $ratio = $size / $compressed;

                if ($ratio > $maxRatio) {
                    throw LegacyMassUploadPackageRejected::because(
                        LegacyMassUploadReason::PACKAGE_COMPRESSION_RATIO,
                        ['max_ratio' => $maxRatio],
                    );
                }
            }

            $declaredTotal += $size;

            if ($declaredTotal > $totalMax) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::PACKAGE_UNCOMPRESSED_TOO_LARGE,
                    ['max_bytes' => $totalMax],
                );
            }

            $basename = $this->basenameOf($name);

            // Duplicate logical names cannot be mapped deterministically to a
            // manifest row, so this is an ambiguity refusal rather than a
            // last-one-wins (§17).
            $key = strtolower($basename);

            if (isset($seenNames[$key])) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::PACKAGE_DUPLICATE_ENTRY,
                    ['entry' => $basename],
                );
            }

            $seenNames[$key] = true;

            if ($basename === $manifestName) {
                if ($size > $manifestMax) {
                    throw LegacyMassUploadPackageRejected::because(
                        LegacyMassUploadReason::MANIFEST_TOO_LARGE,
                        ['max_bytes' => $manifestMax],
                    );
                }

                $manifestIndex = $i;

                continue;
            }

            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

            if (! in_array($extension, $docExtensions, true)) {
                // A non-PDF, non-manifest entry is not something we can import
                // and not something we should silently keep. It is also not
                // worth destroying a batch over, so it is simply not extracted.
                continue;
            }

            if ($size > $docMax) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::PACKAGE_UNCOMPRESSED_TOO_LARGE,
                    ['max_bytes' => $docMax, 'entry' => $basename],
                );
            }

            $documentIndexes[$basename] = $i;
        }

        if ($manifestIndex === null) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::MANIFEST_MISSING,
                ['expected' => $manifestName],
            );
        }

        return [
            'manifest_index' => $manifestIndex,
            'document_indexes' => $documentIndexes,
        ];
    }

    /**
     * Reject any entry name that is absolute, traversing, or otherwise unsafe.
     *
     * Checked even though extraction never uses these names, because an archive
     * that contains them is hostile (see class docblock).
     */
    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNSAFE_PATH);
        }

        // Absolute POSIX path, or a Windows drive/UNC path.
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1 || str_starts_with($name, '\\\\')) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_ABSOLUTE_PATH);
        }

        // Backslashes are not a legal ZIP separator and are a known way to
        // smuggle traversal past naive forward-slash-only checks.
        if (str_contains($name, '\\')) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNSAFE_PATH);
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNSAFE_PATH);
            }
        }
    }

    /**
     * Symlink entries are refused outright.
     *
     * A symlink inside an archive is only ever useful for reaching something
     * outside it. PHP exposes the unix mode in the high 16 bits of the entry's
     * external attributes; S_IFLNK there is the marker.
     */
    private function assertNotSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attributes = 0;

        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) !== true) {
            // Unknown attributes are not proof of safety, but refusing every
            // archive whose attributes we cannot read would reject most
            // Windows-produced ZIPs. Extraction is name-independent, so an
            // unreadable-attribute entry cannot escape regardless.
            return;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $mode = ($attributes >> 16) & 0xFFFF;

        if (($mode & 0170000) === 0120000) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::PACKAGE_SYMLINK_ENTRY,
                ['entry' => $this->basenameOf($name)],
            );
        }
    }

    /**
     * @return array{absolute_path: string, sha256: string}
     */
    private function extractManifest(ZipArchive $zip, int $index, string $workspaceRelative): array
    {
        $manifestMax = (int) config('legacy_mass_upload.package.manifest_max_bytes', 5242880);
        $relative = $workspaceRelative.'/manifest.csv';

        $written = $this->streamEntryTo($zip, $index, $relative, $manifestMax, LegacyMassUploadReason::MANIFEST_TOO_LARGE);

        return [
            'absolute_path' => $this->disk()->path($relative),
            'sha256' => $written['sha256'],
        ];
    }

    /**
     * @param  array<string, int>  $documentIndexes  logical name => zip index
     * @return array<string, LegacyMassUploadExtractedDocument>
     */
    private function extractDocuments(ZipArchive $zip, array $documentIndexes, string $docsRelative): array
    {
        $docMax = (int) config('legacy_mass_upload.package.document_max_bytes', 20971520);
        $totalMax = (int) config('legacy_mass_upload.package.total_uncompressed_max_bytes', 2147483648);

        $documents = [];
        $sequence = 0;
        $runningTotal = 0;

        foreach ($documentIndexes as $logicalName => $index) {
            $sequence++;

            // OUR filename. The archive's name never becomes a path.
            $relative = sprintf('%s/%06d.pdf', $docsRelative, $sequence);

            $written = $this->streamEntryTo($zip, $index, $relative, $docMax, LegacyMassUploadReason::FILE_TOO_LARGE);

            $runningTotal += $written['bytes'];

            // Second bomb check, against the REAL decompressed size.
            if ($runningTotal > $totalMax) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::PACKAGE_UNCOMPRESSED_TOO_LARGE,
                    ['max_bytes' => $totalMax],
                );
            }

            $absolute = $this->disk()->path($relative);

            $this->assertExtractedPdf($absolute, (string) $logicalName);

            $documents[(string) $logicalName] = new LegacyMassUploadExtractedDocument(
                logicalName: (string) $logicalName,
                absolutePath: $absolute,
                relativePath: $relative,
                bytes: $written['bytes'],
                sha256: $written['sha256'],
            );
        }

        return $documents;
    }

    /**
     * Stream one entry to a chosen relative path, enforcing a hard byte cap as
     * it goes and hashing the real bytes.
     *
     * @return array{bytes: int, sha256: string}
     */
    private function streamEntryTo(
        ZipArchive $zip,
        int $index,
        string $relativeTarget,
        int $maxBytes,
        string $overflowReason,
    ): array {
        $name = $zip->getNameIndex($index);

        if ($name === false) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $source = $zip->getStream($name);

        if ($source === false) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $absoluteTarget = $this->disk()->path($relativeTarget);
        $directory = dirname($absoluteTarget);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $target = fopen($absoluteTarget, 'wb');

        if ($target === false) {
            fclose($source);

            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 262144);

                if ($chunk === false) {
                    throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                // The cap is enforced on bytes actually produced, which is what
                // makes an understated central directory harmless.
                if ($bytes > $maxBytes) {
                    throw LegacyMassUploadPackageRejected::because(
                        $overflowReason,
                        ['max_bytes' => $maxBytes],
                    );
                }

                hash_update($hash, $chunk);

                if (fwrite($target, $chunk) === false) {
                    throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE);
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    /**
     * Confirm the extracted bytes really are a PDF.
     *
     * The extension came from the archive and proves nothing. This checks the
     * magic bytes, and the canonical single-item service validates the document
     * again at createFromUpload() — two independent checks, because a polyglot
     * that satisfies one naive test is a real technique.
     */
    private function assertExtractedPdf(string $absolutePath, string $logicalName): void
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::FILE_INVALID,
                ['entry' => $logicalName],
            );
        }

        try {
            $magic = (string) fread($handle, 5);
        } finally {
            fclose($handle);
        }

        if (! str_starts_with($magic, '%PDF')) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::FILE_NOT_PDF,
                ['entry' => $logicalName],
            );
        }

        $allowedMimes = (array) config('legacy_mass_upload.package.document_allowed_mimes', ['application/pdf']);
        $mime = @mime_content_type($absolutePath);

        if (is_string($mime) && $mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::FILE_NOT_PDF,
                ['entry' => $logicalName],
            );
        }
    }

    /**
     * Every document a manifest row names must exist in the archive.
     *
     * This is a PACKAGE failure, not a per-item one, and that is a deliberate
     * reading of §16 ("every manifest file exists"). A manifest referencing
     * files that are not there means the operator shipped the wrong archive or
     * a partial one — proceeding with the subset would migrate an incomplete
     * archive while reporting success for the rows that happened to be present.
     *
     * @param  list<LegacyMassUploadManifestRow>  $rows
     * @param  array<string, LegacyMassUploadExtractedDocument>  $documents
     */
    private function assertManifestDocumentsPresent(array $rows, array $documents): void
    {
        foreach ($rows as $row) {
            if ($row->fileName === '' || ! isset($documents[$row->fileName])) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::MANIFEST_FILE_MISSING,
                    ['row' => $row->rowNumber, 'file_name' => Str::limit($row->fileName, 120)],
                );
            }
        }
    }

    private function isIgnorableEntry(string $name): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return in_array($this->basenameOf($name), self::IGNORED_BASENAMES, true);
    }

    /**
     * Basename without using pathinfo on a hostile string.
     *
     * The manifest matches on the leaf name, so an archive that nests documents
     * in folders still works for the operator who zipped a directory.
     */
    private function basenameOf(string $name): string
    {
        $trimmed = rtrim($name, '/');
        $position = strrpos($trimmed, '/');

        return $position === false ? $trimmed : substr($trimmed, $position + 1);
    }

    /**
     * Remove the batch workspace (§40).
     *
     * Deterministic and safe to call repeatedly — on success, on validation
     * failure, on cancel and from an exception path. It removes ONLY the
     * mass-upload workspace. Documents the canonical single-item service has
     * already taken ownership of live under their own import uuid on a
     * different disk and are untouched: once createFromUpload() has stored a
     * copy, that copy is source evidence under clinical retention, not ours to
     * delete.
     */
    public function cleanWorkspace(string $batchUuid): void
    {
        $relative = $this->workspaceRelativeDir($batchUuid);
        $disk = $this->disk();

        if ($disk->directoryExists($relative)) {
            $disk->deleteDirectory($relative);
        }
    }
}
