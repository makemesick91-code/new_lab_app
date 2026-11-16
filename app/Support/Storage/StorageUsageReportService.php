<?php

declare(strict_types=1);

namespace App\Support\Storage;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 PR9 (internal memo 2026-10-17, M6.2; owner
 * decision 2026-11-17 "Usage report + owner measurement", rule 239) — a
 * READ-ONLY capacity report: free and total space of the filesystem holding
 * storage/, the size and file count of every private disk, the clinical
 * evidence disk by category, the database size, and the backup directory.
 *
 * Sizes and counts only. No file name, object key, absolute host path or patient
 * detail is ever returned: a disk is named by its config key, a clinical
 * category only when it is one of the prefixes the application itself writes
 * (everything else is "other"), and the filesystem by a label. It writes nothing
 * and makes no network call. An unreadable directory is counted, never fatal.
 */
class StorageUsageReportService
{
    /** Private disks, by config key, with an operator-facing description. */
    public const DISKS = [
        'clinical_evidence' => 'Bukti klinis RME (tulisan tangan, resep, tanda tangan PTM, bukti lab)',
        'legacy_rme_private' => 'Arsip Legacy RME (PDF dan gambar halaman)',
        'legacy_odontogram_private' => 'Arsip Legacy odontogram',
        'legacy_mass_upload_private' => 'Unggahan massal Legacy (sementara)',
        'rme_refund_settlement_evidence' => 'Bukti penyelesaian refund',
        'local' => 'Penyimpanan privat umum (termasuk tanda tangan PTM yang belum disalin)',
        'public' => 'Disk publik (tidak boleh berisi bukti klinis)',
    ];

    /**
     * Top-level prefixes the application writes on the clinical evidence disk.
     * Anything else is aggregated as "other" so no unexpected name is printed.
     */
    public const CLINICAL_CATEGORIES = [
        'handwritings' => 'Halaman RM tulisan tangan',
        'prescriptions' => 'Kanvas resep dan tanda tangan dokter',
        'rme-consents' => 'Tanda tangan PTM',
        'lab-orders' => 'Lampiran order lab',
        'lab-workflow-evidence' => 'Bukti alur kerja lab',
    ];

    public const OTHER_CATEGORY = 'other';

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'generated_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'filesystem' => $this->filesystem(),
            'disks' => $this->disks(),
            'clinical_evidence_categories' => $this->clinicalCategories(),
            'database' => $this->database(),
            'backups' => $this->backups(),
        ];
    }

    /** @return array<string, mixed> */
    private function filesystem(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        if ($total === false || $free === false || $total <= 0) {
            return ['label' => 'storage', 'readable' => false, 'total_bytes' => null, 'free_bytes' => null, 'used_percent' => null];
        }

        return [
            'label' => 'storage',
            'readable' => true,
            'total_bytes' => (int) $total,
            'free_bytes' => (int) $free,
            'used_percent' => round((($total - $free) / $total) * 100, 1),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function disks(): array
    {
        $rows = [];

        foreach (self::DISKS as $disk => $description) {
            $config = config("filesystems.disks.{$disk}");

            if (! is_array($config)) {
                continue;
            }

            $row = ['disk' => $disk, 'description' => $description, 'driver' => (string) ($config['driver'] ?? '')];
            $root = $this->localRoot($disk, $config);

            $rows[] = $root === null
                ? $row + ['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 0]
                : $row + $this->measure($root);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function clinicalCategories(): array
    {
        $config = config('filesystems.disks.clinical_evidence');
        $root = is_array($config) ? $this->localRoot('clinical_evidence', $config) : null;

        $totals = [];
        foreach (array_keys(self::CLINICAL_CATEGORIES) + [count(self::CLINICAL_CATEGORIES) => self::OTHER_CATEGORY] as $category) {
            $totals[$category] = ['bytes' => 0, 'files' => 0];
        }

        if ($root !== null && is_dir($root)) {
            try {
                foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                    /** @var SplFileInfo $entry */
                    $name = $entry->getFilename();
                    $category = ($entry->isDir() && array_key_exists($name, self::CLINICAL_CATEGORIES)) ? $name : self::OTHER_CATEGORY;
                    $measured = $entry->isDir() ? $this->measure($entry->getPathname()) : ['bytes' => (int) $entry->getSize(), 'files' => 1];

                    $totals[$category]['bytes'] += (int) ($measured['bytes'] ?? 0);
                    $totals[$category]['files'] += (int) ($measured['files'] ?? 0);
                }
            } catch (UnexpectedValueException) {
                // Unreadable root: reported through the disk row.
            }
        }

        $rows = [];
        foreach ($totals as $category => $sum) {
            $rows[] = [
                'category' => $category,
                'description' => self::CLINICAL_CATEGORIES[$category] ?? 'Lainnya',
                'bytes' => $sum['bytes'],
                'files' => $sum['files'],
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            return ['driver' => $driver, 'bytes' => null, 'note' => 'size reported only on PostgreSQL'];
        }

        try {
            $bytes = (int) DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes;
        } catch (Throwable $e) {
            return ['driver' => $driver, 'bytes' => null, 'note' => 'size not readable ('.$e::class.')'];
        }

        return ['driver' => $driver, 'bytes' => $bytes];
    }

    /** @return array<string, mixed> */
    private function backups(): array
    {
        $relative = (string) config('backup_dr.backup_expectations.required_backup_directory', '');

        if ($relative === '') {
            return ['configured' => false, 'readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 0];
        }

        return ['configured' => true] + $this->measure(base_path($relative));
    }

    /**
     * The root of a LOCAL disk as the running driver sees it (a test fake
     * included); null for any other driver.
     *
     * @param  array<string, mixed>  $config
     */
    private function localRoot(string $disk, array $config): ?string
    {
        if (($config['driver'] ?? null) !== 'local') {
            return null;
        }

        try {
            return rtrim(Storage::disk($disk)->path(''), DIRECTORY_SEPARATOR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Size and file count of a directory tree, read-only. An unreadable
     * sub-directory is skipped and counted.
     *
     * @return array{readable: bool, bytes: int|null, files: int|null, unreadable_dirs: int}
     */
    private function measure(string $directory): array
    {
        if (! is_dir($directory)) {
            return ['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 0];
        }

        if (! is_readable($directory)) {
            return ['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 1];
        }

        $bytes = 0;
        $files = 0;
        $unreadable = 0;
        $pending = [$directory];

        while ($pending !== []) {
            $current = array_pop($pending);

            try {
                $entries = new FilesystemIterator($current, FilesystemIterator::SKIP_DOTS);
            } catch (UnexpectedValueException) {
                $unreadable++;

                continue;
            }

            foreach ($entries as $entry) {
                /** @var SplFileInfo $entry */
                if ($entry->isLink()) {
                    continue;
                }

                if ($entry->isDir()) {
                    $pending[] = $entry->getPathname();
                } elseif ($entry->isFile()) {
                    $bytes += (int) $entry->getSize();
                    $files++;
                }
            }
        }

        return ['readable' => true, 'bytes' => $bytes, 'files' => $files, 'unreadable_dirs' => $unreadable];
    }
}
