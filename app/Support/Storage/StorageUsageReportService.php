<?php

declare(strict_types=1);

namespace App\Support\Storage;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileInfo;
use Throwable;

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
 * and makes no network call. A symbolic link is never followed. An unreadable
 * directory or an entry that cannot be stat'ed is counted, never fatal, and a
 * figure that could not be measured is null, never zero.
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
                ? $row + ['measurable' => false] + self::unmeasured(0)
                : $row + ['measurable' => true] + $this->measure($root);
        }

        return $rows;
    }

    /**
     * The clinical evidence disk by category. A category row is null (never
     * zero) when it could not be measured: the disk is not local
     * (measurable=false), the root cannot be listed, or every part of the
     * category is unreadable; otherwise it sums what could be read and carries
     * the unreadable counts. Symbolic links are skipped exactly as in measure().
     *
     * @return list<array<string, mixed>>
     */
    private function clinicalCategories(): array
    {
        $config = config('filesystems.disks.clinical_evidence');
        $root = is_array($config) ? $this->localRoot('clinical_evidence', $config) : null;

        $sums = [];
        foreach ([...array_keys(self::CLINICAL_CATEGORIES), self::OTHER_CATEGORY] as $category) {
            $sums[$category] = ['parts' => 0, 'bytes' => 0, 'files' => 0, 'unreadable_dirs' => 0, 'unreadable_entries' => 0];
        }

        $rootReadable = $root !== null && is_dir($root) && is_readable($root);

        if ($rootReadable) {
            try {
                foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                    /** @var SplFileInfo $entry */
                    [$kind, $size] = $this->inspect($entry);

                    if ($kind === 'link' || $kind === 'special') {
                        continue;
                    }

                    $name = $entry->getFilename();
                    $category = ($kind !== 'file' && array_key_exists($name, self::CLINICAL_CATEGORIES)) ? $name : self::OTHER_CATEGORY;
                    $part = match ($kind) {
                        'dir' => $this->measure($entry->getPathname()),
                        'file' => ['readable' => true, 'bytes' => $size, 'files' => 1, 'unreadable_dirs' => 0, 'unreadable_entries' => 0],
                        default => ['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 0, 'unreadable_entries' => 1],
                    };

                    $sums[$category]['parts'] += $part['bytes'] === null ? 0 : 1;
                    $sums[$category]['bytes'] += (int) ($part['bytes'] ?? 0);
                    $sums[$category]['files'] += (int) ($part['files'] ?? 0);
                    $sums[$category]['unreadable_dirs'] += $part['unreadable_dirs'];
                    $sums[$category]['unreadable_entries'] += $part['unreadable_entries'];
                }
            } catch (RuntimeException) {
                // The root could not be listed: every category reads as unreadable
                // (the disk row carries the count).
                $rootReadable = false;
            }
        }

        $rows = [];
        foreach ($sums as $category => $sum) {
            $row = ['category' => $category, 'description' => self::CLINICAL_CATEGORIES[$category] ?? 'Lainnya', 'measurable' => $root !== null];

            if (! $rootReadable) {
                $rows[] = $row + self::unmeasured(0);

                continue;
            }

            $unreadable = $sum['unreadable_dirs'] + $sum['unreadable_entries'];
            $readable = $sum['parts'] > 0 || $unreadable === 0;

            $rows[] = $row + [
                'readable' => $readable,
                'bytes' => $readable ? $sum['bytes'] : null,
                'files' => $readable ? $sum['files'] : null,
                'unreadable_dirs' => $sum['unreadable_dirs'],
                'unreadable_entries' => $sum['unreadable_entries'],
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
            return ['driver' => $driver, 'bytes' => null, 'note' => 'size not readable ('.get_debug_type($e).')'];
        }

        return ['driver' => $driver, 'bytes' => $bytes];
    }

    /** @return array<string, mixed> */
    private function backups(): array
    {
        $relative = (string) config('backup_dr.backup_expectations.required_backup_directory', '');

        if ($relative === '') {
            return ['configured' => false] + self::unmeasured(0);
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
     * Size and file count of a directory tree, read-only. A symbolic link is
     * never followed. A sub-directory that cannot be listed is skipped and
     * counted (unreadable_dirs); an entry that cannot be stat'ed or sized — a
     * file deleted meanwhile, a parent without search permission — is skipped
     * and counted (unreadable_entries). No exception (they carry the host
     * path) leaves this method.
     *
     * @return array{readable: bool, bytes: int|null, files: int|null, unreadable_dirs: int, unreadable_entries: int}
     */
    private function measure(string $directory): array
    {
        if (! is_dir($directory)) {
            return self::unmeasured(0);
        }

        if (! is_readable($directory)) {
            return self::unmeasured(1);
        }

        $bytes = 0;
        $files = 0;
        $unreadableDirs = 0;
        $unreadableEntries = 0;
        $pending = [$directory];

        while ($pending !== []) {
            $current = array_pop($pending);

            try {
                foreach (new FilesystemIterator($current, FilesystemIterator::SKIP_DOTS) as $entry) {
                    /** @var SplFileInfo $entry */
                    [$kind, $size] = $this->inspect($entry);

                    if ($kind === 'dir') {
                        $pending[] = $entry->getPathname();
                    } elseif ($kind === 'file') {
                        $bytes += $size;
                        $files++;
                    } elseif ($kind === 'unreadable') {
                        $unreadableEntries++;
                    }
                }
            } catch (RuntimeException) {
                $unreadableDirs++;
            }
        }

        return ['readable' => true, 'bytes' => $bytes, 'files' => $files, 'unreadable_dirs' => $unreadableDirs, 'unreadable_entries' => $unreadableEntries];
    }

    /**
     * One directory entry, never following a link: link, dir, file (with its
     * size), special (fifo, socket, device: not stored data) or unreadable (it
     * cannot be stat'ed or sized).
     *
     * @return array{0: 'link'|'dir'|'file'|'special'|'unreadable', 1: int}
     */
    private function inspect(SplFileInfo $entry): array
    {
        try {
            if ($entry->isLink()) {
                return ['link', 0];
            }

            if ($entry->isDir()) {
                return ['dir', 0];
            }

            if ($entry->isFile()) {
                return ['file', (int) $entry->getSize()];
            }

            $entry->getType(); // throws when the entry cannot be stat'ed at all

            return ['special', 0];
        } catch (RuntimeException) {
            return ['unreadable', 0];
        }
    }

    /**
     * @return array{readable: false, bytes: null, files: null, unreadable_dirs: int, unreadable_entries: int}
     */
    private static function unmeasured(int $unreadableDirs): array
    {
        return ['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => $unreadableDirs, 'unreadable_entries' => 0];
    }
}
