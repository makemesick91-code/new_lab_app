<?php

namespace App\Support\PilotImport;

class PilotBackupImportResult
{
    /**
     * @param  array<string, int>  $detected
     * @param  array<string, int>  $imported
     * @param  array<string, int>  $updated
     * @param  array<string, int>  $skipped
     * @param  list<string>  $messages
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly array $detected,
        public readonly array $imported,
        public readonly array $updated,
        public readonly array $skipped,
        public readonly array $messages = [],
    ) {}
}
