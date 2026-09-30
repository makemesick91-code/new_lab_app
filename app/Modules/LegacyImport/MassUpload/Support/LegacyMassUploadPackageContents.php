<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * The result of a package that PASSED integrity validation.
 *
 * Reaching this object means the archive was readable, bounded, free of unsafe
 * entries, and carried a manifest whose every referenced document was present.
 * Nothing about clinical eligibility has been decided yet — that is preflight's
 * job, and a fully valid package can still yield entirely blocked items.
 */
final class LegacyMassUploadPackageContents
{
    /**
     * @param  list<LegacyMassUploadManifestRow>  $rows
     * @param  array<string, LegacyMassUploadExtractedDocument>  $documents  keyed by logical name
     * @param  list<string>  $unreferencedDocuments  logical names present but not claimed by any row
     */
    public function __construct(
        public readonly string $workspaceRelativeDir,
        public readonly string $workspaceAbsoluteDir,
        public readonly string $manifestSha256,
        public readonly array $rows,
        public readonly array $documents,
        public readonly array $unreferencedDocuments = [],
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function documentFor(string $logicalName): ?LegacyMassUploadExtractedDocument
    {
        return $this->documents[$logicalName] ?? null;
    }

    /**
     * Documents present in the archive that no manifest row claimed.
     *
     * Surfaced as a package-level WARNING rather than a rejection, on purpose.
     * An operator who drags one extra scan into a folder of 250 should not lose
     * the whole batch — but they must be told, because the alternative is a
     * document they believe was migrated and silently was not. Silence here is
     * the genuinely dangerous outcome, not the extra file.
     */
    public function hasUnreferencedDocuments(): bool
    {
        return $this->unreferencedDocuments !== [];
    }
}
