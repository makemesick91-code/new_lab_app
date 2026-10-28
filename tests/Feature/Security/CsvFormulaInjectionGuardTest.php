<?php

/**
 * SECURITY-F3-CSV-CELL-GUARD-1 — one rule for every CSV cell the application
 * writes, and a scan that keeps new exporters on it.
 *
 * A spreadsheet runs a cell beginning with `=`, `+`, `-`, `@`, a tab or a
 * carriage return as a formula. App\Support\Export\CsvCell prefixes such a cell
 * with an apostrophe and leaves numbers numeric (decision D20). Each of the
 * seven exporters it now guards has an end-to-end test in its own module suite
 * with a hostile value; this suite pins the rule itself and the coverage.
 */

use App\Support\Export\CsvCell;
use Illuminate\Support\Str;

dataset('guarded text', [
    'equals' => ['=1+1', "'=1+1"],
    'plus' => ['+SUM(1,2)', "'+SUM(1,2)"],
    'minus with operator' => ['-2+3', "'-2+3"],
    'at' => ['@cmd', "'@cmd"],
    'tab' => ["\tx", "'\tx"],
    'carriage return' => ["\rx", "'\rx"],
    'hyperlink' => ['=HYPERLINK("http://x.invalid","y")', '\'=HYPERLINK("http://x.invalid","y")'],
    'multibyte after trigger' => ['=Ñandú', "'=Ñandú"],
    'exponent is not a plain decimal' => ['-1e5', "'-1e5"],
    'lone sign' => ['-', "'-"],
]);

dataset('unchanged', [
    'plain text' => ['Pasien Biasa'],
    'empty string' => [''],
    'multibyte' => ['Ñandú'],
    'trigger later in the text' => ['A=1'],
    'negative decimal string' => ['-15000.00'],
    'positive signed integer string' => ['+62812'],
    'leading-dot decimal' => ['.5'],
    'signed leading-dot decimal' => ['-.5'],
]);

it('neutralises a text cell that a spreadsheet would run as a formula', function (string $in, string $out) {
    expect(CsvCell::guard($in))->toBe($out);
})->with('guarded text');

it('leaves ordinary text and plain numeric strings unchanged', function (string $value) {
    expect(CsvCell::guard($value))->toBe($value);
})->with('unchanged');

it('leaves non-text values unchanged so numeric columns stay numeric', function () {
    expect(CsvCell::guard(null))->toBeNull()
        ->and(CsvCell::guard(-15))->toBe(-15)
        ->and(CsvCell::guard(1.5))->toBe(1.5)
        ->and(CsvCell::guard(-0.25))->toBe(-0.25)
        ->and(CsvCell::guard(true))->toBeTrue();
});

it('guards stringable values by their text', function () {
    $stringable = new class
    {
        public function __toString(): string
        {
            return '=1+1';
        }
    };

    expect(CsvCell::guard($stringable))->toBe("'=1+1")
        ->and(CsvCell::guard(Str::of('@x')))->toBe("'@x");
});

it('guards every cell of a row and keeps the order', function () {
    expect(CsvCell::row(['ok', '=1', 2, null, '-3']))->toBe(['ok', "'=1", 2, null, '-3']);
});

it('routes every CSV row the application writes through the guard, or records why not', function () {
    // A file listed here writes CSV without CsvCell, and says why. The list is
    // exact: a file that starts writing CSV, or stops, fails this test.
    $exceptions = [
        'app/Modules/ClinicOversight/Services/OversightCsvExporter.php' => 'own guard (safe())',
        'app/Modules/LegacyImport/MassUpload/Services/LegacyMassUploadReportService.php' => 'own guard (escape())',
        'app/Modules/LabCapacity/Controllers/LabTechnicianCapacityController.php' => 'own guard (csvSafe())',
        'app/Modules/LabOrder/Controllers/LabOperationalAnalyticsController.php' => 'system-computed metrics and labels only',
        'app/Modules/Patient/Controllers/LegacyPatientImportController.php' => 'static template; error rows guarded in LegacyPatientImportService',
        'app/Modules/Inventory/Controllers/ProductImportController.php' => 'static template rows',
        'app/Console/Commands/RmeTariffTemplateCommand.php' => 'own guard (TariffImportService::csvSafeCode())',
        'app/Console/Commands/InventoryAmbiguousBatchReviewPackCommand.php' => 'NOT GUARDED: operator CLI file, outside F-3; tracked for SAFE-CLEANUP M-8',
    ];

    $writers = [];
    $unguarded = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! str_contains($source, 'fputcsv(')) {
            continue;
        }
        $relative = 'app/'.ltrim(Str::after($file->getPathname(), app_path()), '/');
        $writers[] = $relative;

        if (isset($exceptions[$relative])) {
            continue;
        }

        preg_match_all('/fputcsv\(\$\w+,\s*(.{0,20})/', $source, $calls);
        foreach ($calls[1] as $argument) {
            if (! str_starts_with($argument, 'CsvCell::row(') && ! str_starts_with($argument, '[])')) {
                $unguarded[] = $relative.': fputcsv(…, '.$argument;
            }
        }
    }

    sort($writers);
    $expected = array_merge(array_keys($exceptions), [
        'app/Modules/Inventory/Services/InventoryBatchDisposalReportService.php',
        'app/Modules/Inventory/Services/InventoryBatchMonthlyClosingPackService.php',
        'app/Modules/Inventory/Services/InventoryReportService.php',
        'app/Modules/Patient/Controllers/PatientAuditController.php',
        'app/Modules/Reporting/Services/ExportReportService.php',
        'app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php',
        'app/Modules/RmeInvoice/Controllers/RmeReportController.php',
    ]);
    sort($expected);

    expect($unguarded)->toBe([])
        ->and($writers)->toBe($expected);
});
