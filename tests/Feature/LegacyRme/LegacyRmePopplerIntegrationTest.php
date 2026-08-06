<?php

/**
 * LEGACY-RME-PDF-1B — the REAL Poppler adapters, end to end.
 *
 * Every other test in this sprint drives the pipeline through deterministic
 * fakes, so the suite never depends on an external binary. This file is the one
 * place the actual `pdfinfo`/`pdftoppm` integration is exercised, and it SKIPS
 * (never silently passes) when Poppler is not installed.
 */

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

function popplerAvailable(): bool
{
    foreach (['pdfinfo', 'pdftoppm'] as $binary) {
        $process = new Process([$binary, '-v']);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (Throwable) {
            return false;
        }
    }

    return true;
}

beforeEach(function () {
    if (! popplerAvailable()) {
        test()->markTestSkipped('Poppler (pdfinfo/pdftoppm) is not installed in this environment.');
    }

    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrmePopplerTempPdf(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'lrme-poppler-').'.pdf';
    file_put_contents($path, $contents);

    return $path;
}

it('reads real structural metadata from a pdf', function () {
    $path = lrmePopplerTempPdf(legacyRmePdfBytes(3));

    try {
        $metadata = app(LegacyRmePdfInspectorInterface::class)->inspect($path);

        expect($metadata->pageCount)->toBe(3)
            ->and($metadata->encrypted)->toBeFalse()
            ->and($metadata->pdfVersion)->not->toBeNull()
            ->and($metadata->pageDimensions)->toHaveCount(3)
            ->and($metadata->largestDimension())->toBeGreaterThan(500);
    } finally {
        @unlink($path);
    }
});

it('rejects a corrupt pdf with a stable failure code', function () {
    $path = lrmePopplerTempPdf('%PDF-1.4'."\n".'benar-benar rusak isinya');

    try {
        app(LegacyRmePdfInspectorInterface::class)->inspect($path);
        $failure = null;
    } catch (LegacyRmePdfException $exception) {
        $failure = $exception->failureCode;
    } finally {
        @unlink($path);
    }

    expect($failure)->toBe(LegacyRmePdfFailure::PDF_INSPECTION_FAILED);
});

it('renders real page images and thumbnails', function () {
    $source = lrmePopplerTempPdf(legacyRmePdfBytes(2));
    $output = sys_get_temp_dir().'/lrme-render-'.uniqid();
    mkdir($output, 0700, true);

    try {
        $result = app(LegacyRmePdfRasterizerInterface::class)->rasterize($source, $output, 72);

        expect($result->pageCount())->toBe(2)
            ->and($result->pageNumbers())->toBe([1, 2]);

        foreach ($result->pages as $page) {
            $size = getimagesize($page->imagePath);

            expect($size)->not->toBeFalse()
                ->and($size['mime'])->toBe('image/png')
                ->and($page->width)->toBeGreaterThan(0)
                ->and($page->thumbnailPath)->not->toBeNull()
                ->and(is_file($page->thumbnailPath))->toBeTrue();

            // The thumbnail must actually be smaller than the full page.
            $thumbSize = getimagesize($page->thumbnailPath);
            expect(max($thumbSize[0], $thumbSize[1]))
                ->toBeLessThanOrEqual((int) config('legacy_rme.processing.thumbnail_max_edge'));
        }
    } finally {
        array_map('unlink', (array) glob($output.'/*'));
        @rmdir($output);
        @unlink($source);
    }
});

it('normalizes page numbering for a document with more than nine pages', function () {
    // pdftoppm zero-pads the numeric suffix to the width of the page count, so
    // a 12-page document yields `-01` .. `-12`. Page numbers must still come
    // back as plain integers 1..12 in order.
    $source = lrmePopplerTempPdf(legacyRmePdfBytes(12));
    $output = sys_get_temp_dir().'/lrme-render-'.uniqid();
    mkdir($output, 0700, true);

    try {
        $result = app(LegacyRmePdfRasterizerInterface::class)->rasterize($source, $output, 40);

        expect($result->pageNumbers())->toBe(range(1, 12));
    } finally {
        array_map('unlink', (array) glob($output.'/*'));
        @rmdir($output);
        @unlink($source);
    }
});

it('processes a real pdf end to end into private page images', function () {
    config()->set('legacy_rme.processing.dpi', 72);

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload('arsip.pdf', 3),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $import->refresh();
    $pages = LegacyRmeImportPage::where('legacy_import_id', $import->getKey())->orderBy('page_number')->get();

    expect($import->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($import->page_count)->toBe(3)
        ->and($pages)->toHaveCount(3)
        ->and($pages->every(fn ($page) => Storage::disk('legacy_rme_private')->exists($page->background_path)))->toBeTrue()
        ->and($pages->every(fn ($page) => Storage::disk('legacy_rme_private')->exists($page->thumbnail_path)))->toBeTrue();

    // Nothing temporary survives the run.
    $leftovers = collect(Storage::disk('legacy_rme_private')->allFiles())
        ->filter(fn ($path) => str_contains($path, '/processing/'));

    expect($leftovers)->toBeEmpty();
});
