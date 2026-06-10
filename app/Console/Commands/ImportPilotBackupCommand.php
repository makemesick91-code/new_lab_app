<?php

namespace App\Console\Commands;

use App\Support\PilotImport\PilotBackupImportService;
use App\Support\PilotImport\PostgresCopyDumpReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class ImportPilotBackupCommand extends Command
{
    protected $signature = 'rme:import-pilot-backup
                            {--file=storage/app/imports/asia_dental_lab_2026-06-08_2246.sql : Path to PostgreSQL plain SQL backup}
                            {--dry-run : Preview import without writing}
                            {--only= : Optional comma-separated groups: branches,doctors,patients,treatments}
                            {--limit= : Optional row limit per whitelisted table}';

    protected $description = 'Safely import whitelisted RME pilot master data from a PostgreSQL COPY dump';

    public function __construct(
        private readonly PostgresCopyDumpReader $reader,
        private readonly PilotBackupImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $path = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);

        if (! File::exists($path)) {
            $this->error("Backup file not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        try {
            $extracted = $this->importService->extract($path);
            $result = $this->importService->import(
                $extracted,
                dryRun: $dryRun,
                only: $this->option('only') ? (string) $this->option('only') : null,
                limit: $limit,
            );
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'RME pilot backup import — DRY RUN' : 'RME pilot backup import — LIVE');
        $this->line('File: '.$path);
        $this->newLine();

        $this->components->twoColumnDetail('Mode', $dryRun ? 'dry-run (no writes)' : 'live import');
        $this->components->twoColumnDetail('Protected tables', 'never imported (roles, users, invoices, visits, etc.)');
        $this->newLine();

        $this->info('Detected COPY rows (whitelisted only)');
        foreach ($result->detected as $table => $count) {
            $this->line(sprintf('  %-20s %d', $table, $count));
        }

        if ($extracted['skipped_tables'] !== []) {
            $this->newLine();
            $this->warn('Skipped non-whitelisted COPY tables detected in dump');
            foreach ($extracted['skipped_tables'] as $table => $count) {
                $this->line(sprintf('  %-30s %d rows ignored', $table, $count));
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Planned actions' : 'Import summary');
        $this->renderTableCounts('Imported', $result->imported);
        $this->renderTableCounts('Updated', $result->updated);
        $this->renderTableCounts('Skipped', $result->skipped);

        if ($result->messages !== []) {
            $this->newLine();
            $this->info('Notes');
            foreach (array_slice($result->messages, 0, 20) as $message) {
                $this->line('  '.$message);
            }

            if (count($result->messages) > 20) {
                $this->line('  ...');
            }
        }

        $this->newLine();
        $this->line('Whitelisted: '.implode(', ', PostgresCopyDumpReader::WHITELISTED_TABLES));
        $this->line('Never imported: '.implode(', ', array_slice(PostgresCopyDumpReader::PROTECTED_TABLES, 0, 8)).', ...');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderTableCounts(string $label, array $counts): void
    {
        if ($counts === []) {
            $this->line("  {$label}: none");

            return;
        }

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-10s %-20s %d', $label.':', $table, $count));
        }
    }
}
