<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 PR9 (memo M6.2) — storage usage report
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 ("Usage report + owner measurement"): a READ-ONLY
| capacity report — free space, every private disk, the clinical evidence disk
| by category, the database and the backup directory — with sizes and counts
| only. No file name, object key, host path or patient detail ever reaches the
| output, and the command writes nothing.
|
| Selected by the existing `ClinicalEvidence` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `sur` is unique because the critical gate loads every suite into one process.
*/

use App\Support\Storage\StorageUsageReportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    foreach (array_keys(StorageUsageReportService::DISKS) as $disk) {
        Storage::fake($disk);
    }

    $clinical = Storage::disk('clinical_evidence');
    $clinical->put('handwritings/12/34/handwriting_p1_aaa_bbb.png', str_repeat('h', 1000));
    $clinical->put('handwritings/12/35/handwriting_p2_ccc_ddd.png', str_repeat('h', 500));
    $clinical->put('prescriptions/7/8/rx_eee.png', str_repeat('p', 300));
    $clinical->put('rme-consents/CONSENT-SUR-0001/consenter-x.png', str_repeat('c', 200));
    // A prefix the application never writes, and a file at the root: both are
    // aggregated as "other" so their names never appear.
    $clinical->put('patient-sur-secret-name/notes.txt', str_repeat('o', 70));
    $clinical->put('nama-pasien-sur.png', str_repeat('o', 30));

    Storage::disk('legacy_rme_private')->put('archives/LEGACY-SUR-1/source.pdf', str_repeat('l', 4096));
});

/** @return array{0: int, 1: string} */
function surRun(array $options = []): array
{
    $code = Artisan::call('storage:usage-report', $options);

    return [$code, Artisan::output()];
}

/** @return array<string, mixed> */
function surJson(): array
{
    [, $out] = surRun(['--json' => true]);

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

function surDisk(array $report, string $disk): array
{
    return collect($report['disks'])->firstWhere('disk', $disk);
}

function surCategory(array $report, string $category): array
{
    return collect($report['clinical_evidence_categories'])->firstWhere('category', $category);
}

it('SU-01 reports size and file count for each private disk with a stable JSON shape', function () {
    $report = surJson();

    expect($report)->toHaveKeys(['generated_at_utc', 'filesystem', 'disks', 'clinical_evidence_categories', 'database', 'backups'])
        ->and(surDisk($report, 'clinical_evidence'))->toMatchArray(['readable' => true, 'bytes' => 2100, 'files' => 6, 'unreadable_dirs' => 0])
        ->and(surDisk($report, 'legacy_rme_private'))->toMatchArray(['bytes' => 4096, 'files' => 1])
        ->and(surDisk($report, 'legacy_odontogram_private'))->toMatchArray(['bytes' => 0, 'files' => 0])
        ->and($report['filesystem'])->toHaveKeys(['total_bytes', 'free_bytes', 'used_percent']);
});

it('SU-02 breaks the clinical disk down by the categories the application writes, everything else as other', function () {
    $report = surJson();

    expect(surCategory($report, 'handwritings'))->toMatchArray(['bytes' => 1500, 'files' => 2])
        ->and(surCategory($report, 'prescriptions'))->toMatchArray(['bytes' => 300, 'files' => 1])
        ->and(surCategory($report, 'rme-consents'))->toMatchArray(['bytes' => 200, 'files' => 1])
        ->and(surCategory($report, 'other'))->toMatchArray(['bytes' => 100, 'files' => 2]);
});

it('SU-03 never prints a file name, object key, consent number or host path', function () {
    [, $text] = surRun();
    [, $json] = surRun(['--json' => true]);

    foreach ([$text, $json] as $output) {
        foreach ([
            'patient-sur-secret-name', 'nama-pasien-sur', 'CONSENT-SUR-0001', 'LEGACY-SUR-1',
            'handwriting_p1', 'handwritings/12', 'source.pdf', storage_path(), base_path(),
        ] as $needle) {
            expect($output)->not->toContain($needle);
        }
    }
});

it('SU-04 is read-only: no file changes and no database write', function () {
    $before = collect(array_keys(StorageUsageReportService::DISKS))
        ->mapWithKeys(fn (string $disk) => [$disk => Storage::disk($disk)->allFiles()])
        ->all();

    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    [$code] = surRun();
    surRun(['--json' => true]);

    $after = collect(array_keys(StorageUsageReportService::DISKS))
        ->mapWithKeys(fn (string $disk) => [$disk => Storage::disk($disk)->allFiles()])
        ->all();

    expect($code)->toBe(0)
        ->and($after)->toBe($before)
        ->and($writes)->toBe([]);
});

it('SU-05 reports the database size on PostgreSQL and says why it cannot elsewhere', function () {
    $db = surJson()['database'];

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect($db['driver'])->toBe('pgsql')
            ->and($db['bytes'])->toBeInt()->toBeGreaterThan(0);
    } else {
        expect($db['bytes'])->toBeNull()
            ->and($db['note'])->toContain('PostgreSQL');
    }
});

it('SU-06 reports a missing or unreadable backup directory without failing', function () {
    config()->set('backup_dr.backup_expectations.required_backup_directory', 'storage/app/backups/sur-does-not-exist');

    [$code, $text] = surRun();
    $backups = surJson()['backups'];

    expect($code)->toBe(0)
        ->and($backups)->toMatchArray(['configured' => true, 'readable' => false])
        ->and($text)->toContain('BACKUPS UNREADABLE');
});

it('SU-07 counts an unreadable sub-directory instead of failing', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root reads every directory; permission denial cannot be shown.');
    }

    $locked = Storage::disk('legacy_odontogram_private')->path('locked');
    mkdir($locked, 0700, true);
    file_put_contents($locked.'/x.bin', 'x');
    chmod($locked, 0000);

    try {
        $disk = surDisk(surJson(), 'legacy_odontogram_private');

        expect($disk['readable'])->toBeTrue()
            ->and($disk['unreadable_dirs'])->toBe(1)
            ->and($disk['files'])->toBe(0);
    } finally {
        chmod($locked, 0700);
    }
});

/**
 * Output must never carry a host path: the clinical root, storage/ or the
 * project directory (CG-11), whatever went wrong while measuring.
 */
function surExpectNoPath(string ...$outputs): void
{
    foreach ($outputs as $output) {
        foreach ([Storage::disk('clinical_evidence')->path(''), storage_path(), base_path()] as $path) {
            expect($output)->not->toContain(rtrim($path, '/'));
        }
    }
}

it('SU-08 a broken symlink at the clinical disk root is skipped: exit 0 and no host path', function () {
    $root = rtrim(Storage::disk('clinical_evidence')->path(''), '/');
    $link = $root.'/sur-dangling-link';
    symlink(storage_path('framework/testing/sur-missing-target-'.uniqid()), $link);

    try {
        [$code, $text] = surRun();
        [$jsonCode, $json] = surRun(['--json' => true]);
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)
            ->and($jsonCode)->toBe(0)
            ->and(surCategory($report, 'other'))->toMatchArray(['readable' => true, 'bytes' => 100, 'files' => 2])
            ->and($text)->not->toContain('sur-dangling-link')
            ->and($json)->not->toContain('sur-dangling-link');
        surExpectNoPath($text, $json);
    } finally {
        @unlink($link);
    }
});

it('SU-09 an entry that cannot be stat\'ed is counted unreadable, never fatal and never printed', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root stats every entry; permission denial cannot be shown.');
    }

    // Readable but not searchable: the names list, yet no entry can be stat'ed
    // (the same failure as a file deleted between listing and sizing).
    $root = rtrim(Storage::disk('clinical_evidence')->path(''), '/');
    chmod($root, 0600);

    try {
        [$code, $text] = surRun();
        [$jsonCode, $json] = surRun(['--json' => true]);
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)
            ->and($jsonCode)->toBe(0)
            ->and(surDisk($report, 'clinical_evidence'))->toMatchArray(['readable' => true, 'bytes' => 0, 'files' => 0, 'unreadable_entries' => 5])
            ->and(surCategory($report, 'handwritings'))->toMatchArray(['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_entries' => 1])
            ->and(surCategory($report, 'other'))->toMatchArray(['readable' => false, 'bytes' => null, 'files' => null, 'unreadable_entries' => 2])
            ->and(surCategory($report, 'lab-orders'))->toMatchArray(['readable' => true, 'bytes' => 0, 'files' => 0])
            ->and($text)->toContain('CLINICAL handwritings UNREADABLE');
        surExpectNoPath($text, $json);
    } finally {
        chmod($root, 0755);
    }
});

it('SU-10 an unexpected failure prints only the exception class and still exits 0', function () {
    app()->instance(StorageUsageReportService::class, new class extends StorageUsageReportService
    {
        public function report(): array
        {
            throw new RuntimeException('stat failed for '.storage_path('app/private/clinical/nama-pasien-sur.png'));
        }
    });

    [$code, $text] = surRun();
    [$jsonCode, $json] = surRun(['--json' => true]);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($jsonCode)->toBe(0)
        ->and($text)->toContain('RuntimeException')
        ->and($decoded)->toMatchArray(['report_failed' => true, 'exception' => 'RuntimeException'])
        ->and($text.$json)->not->toContain('nama-pasien-sur')
        ->and($text.$json)->not->toContain('stat failed');
    surExpectNoPath($text, $json);
});

it('SU-11 a symlinked category directory pointing outside the clinical root is not followed', function () {
    $outside = storage_path('framework/testing/sur-outside-'.uniqid());
    mkdir($outside, 0755, true);
    file_put_contents($outside.'/outside.bin', str_repeat('x', 9999));
    $link = rtrim(Storage::disk('clinical_evidence')->path(''), '/').'/lab-orders';
    symlink($outside, $link);

    try {
        $report = surJson();

        expect(surCategory($report, 'lab-orders'))->toMatchArray(['readable' => true, 'bytes' => 0, 'files' => 0])
            ->and(surCategory($report, 'other'))->toMatchArray(['bytes' => 100, 'files' => 2])
            ->and(collect($report['clinical_evidence_categories'])->sum('bytes'))->toBe(2100)
            ->and(surDisk($report, 'clinical_evidence'))->toMatchArray(['bytes' => 2100, 'files' => 6]);
    } finally {
        @unlink($link);
        @unlink($outside.'/outside.bin');
        @rmdir($outside);
    }
});

it('SU-12 reports the categories as not measurable when the clinical disk is not local', function () {
    config()->set('filesystems.disks.clinical_evidence.driver', 's3');

    [$code, $text] = surRun();
    $report = surJson();

    expect($code)->toBe(0)
        ->and(surDisk($report, 'clinical_evidence'))->toMatchArray(['measurable' => false, 'bytes' => null, 'files' => null])
        ->and($text)->toContain('DISK clinical_evidence NOT_MEASURABLE')
        ->and($text)->toContain('CLINICAL handwritings NOT_MEASURABLE')
        ->and($text)->not->toContain('CLINICAL handwritings SIZE=');

    foreach ($report['clinical_evidence_categories'] as $row) {
        expect($row)->toMatchArray(['measurable' => false, 'readable' => false, 'bytes' => null, 'files' => null]);
    }
});

it('SU-13 an unreadable clinical root or category directory reads UNREADABLE with its count, never as zero', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root reads every directory; permission denial cannot be shown.');
    }

    $clinical = Storage::disk('clinical_evidence');
    $handwritings = $clinical->path('handwritings');
    $prescriptionSub = $clinical->path('prescriptions/7');
    chmod($handwritings, 0000);
    chmod($prescriptionSub, 0000);

    try {
        [$code, $text] = surRun();
        $report = surJson();

        expect($code)->toBe(0)
            ->and(surCategory($report, 'handwritings'))->toMatchArray(['measurable' => true, 'readable' => false, 'bytes' => null, 'files' => null, 'unreadable_dirs' => 1])
            ->and(surCategory($report, 'prescriptions'))->toMatchArray(['readable' => true, 'bytes' => 0, 'files' => 0, 'unreadable_dirs' => 1])
            ->and(surCategory($report, 'rme-consents'))->toMatchArray(['readable' => true, 'bytes' => 200, 'files' => 1, 'unreadable_dirs' => 0])
            ->and($text)->toMatch('/CLINICAL handwritings UNREADABLE FILES=- UNREADABLE_DIRS=1 /')
            ->and($text)->toMatch('/CLINICAL prescriptions SIZE=0 B FILES=0 UNREADABLE_DIRS=1 /');
        surExpectNoPath($text);
    } finally {
        chmod($handwritings, 0755);
        chmod($prescriptionSub, 0755);
    }

    $root = rtrim($clinical->path(''), '/');
    chmod($root, 0000);

    try {
        [$code, $text] = surRun();
        $report = surJson();

        expect($code)->toBe(0)
            ->and(surDisk($report, 'clinical_evidence'))->toMatchArray(['readable' => false, 'unreadable_dirs' => 1])
            ->and($text)->toContain('CLINICAL handwritings UNREADABLE');

        foreach ($report['clinical_evidence_categories'] as $row) {
            expect($row)->toMatchArray(['measurable' => true, 'readable' => false, 'bytes' => null, 'files' => null]);
        }
    } finally {
        chmod($root, 0755);
    }
});
