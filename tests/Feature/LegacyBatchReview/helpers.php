<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — shared fixtures.
|--------------------------------------------------------------------------
|
| Batch review spans BOTH archives, so this file reaches for each module's own
| fixtures rather than inventing a third set:
|
|   - RME uses the global `legacyRme*` helpers in tests/Pest.php.
|   - Odontogram uses tests/Feature/LegacyOdontogram/helpers.php, which exists
|     precisely because borrowing the RME fixtures would admit a migration wave
|     and rewrite that module's rollout config.
|
| Keeping that separation is the point: a batch review test must not be the
| place the two archives quietly get coupled.
|
| The filename is `helpers.php`, not `*Test.php`, so PHPUnit never mistakes it
| for a test class; each test file requires it explicitly.
*/

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyOdontogramBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyRmeBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use Illuminate\Http\UploadedFile;

if (! function_exists('lbrRmeUpload')) {
    /**
     * A DISTINCT source PDF on every call.
     *
     * The archive refuses an identical checksum staged against a different
     * patient, so a queue of several documents must genuinely differ — exactly
     * as it would in production.
     */
    function lbrRmeUpload(int $pages = 2): UploadedFile
    {
        static $variant = 7000;
        $variant++;

        return UploadedFile::fake()->createWithContent(
            'arsip.pdf',
            legacyRmePdfBytes($pages, 595.276 + $variant, 841.89),
        );
    }
}

if (! function_exists('lbrRmeReady')) {
    /**
     * A fully rendered legacy RME import sitting at READY_FOR_REVIEW, attributed
     * to a NAMED uploader.
     *
     * The uploader is explicit because separation of duties is the subject of
     * several of these tests: `superAdmin()` mints a fresh account per call, so
     * a test that wants uploader and reviewer to be the same account must hold
     * one user and pass it deliberately.
     */
    function lbrRmeReady(User $uploader, string $legacyDate = '2020-05-01', int $pages = 2): LegacyRmeImport
    {
        app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
        app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

        $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
        legacyRmeNativeVisit($patient, '2022-03-10');

        $import = app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            $legacyDate,
            $patient->medical_record_number,
            null,
            lbrRmeUpload($pages),
            $uploader,
        );

        app(LegacyRmeImportProcessingService::class)->process($import->getKey());

        return $import->refresh();
    }
}

if (! function_exists('lbrOdontogramReady')) {
    /**
     * A fully rendered legacy odontogram import at READY_FOR_REVIEW.
     *
     * Requires tests/Feature/LegacyOdontogram/helpers.php to be loaded by the
     * calling test file.
     */
    function lbrOdontogramReady(User $uploader, string $legacyDate = '2020-05-01', int $pages = 1): LegacyOdontogramImport
    {
        $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
        lodoNativeOdontogram($patient, '2022-03-10');

        $import = lodoStageImport($patient, $legacyDate, $uploader, $pages);

        app(LegacyOdontogramProcessingService::class)->process($import->getKey());

        return $import->refresh();
    }
}

if (! function_exists('lbrRmeAdapter')) {
    function lbrRmeAdapter(): LegacyRmeBatchReviewAdapter
    {
        return app(LegacyRmeBatchReviewAdapter::class);
    }
}

if (! function_exists('lbrOdontogramAdapter')) {
    function lbrOdontogramAdapter(): LegacyOdontogramBatchReviewAdapter
    {
        return app(LegacyOdontogramBatchReviewAdapter::class);
    }
}

if (! function_exists('lbrOpenSession')) {
    /** Open a batch review session for one adapter as one actor. */
    function lbrOpenSession(object $adapter, User $actor): LegacyBatchReviewSession
    {
        return app(LegacyBatchReviewSessionService::class)->open($adapter, $actor);
    }
}

if (! function_exists('lbrReviewer')) {
    /** An actor holding ONLY the legacy RME review permission. */
    function lbrReviewer(): User
    {
        return userWith(['view_legacy_rme_imports', 'review_legacy_rme_imports']);
    }
}

if (! function_exists('lbrOdontogramReviewer')) {
    /** An actor holding ONLY the legacy odontogram review permission. */
    function lbrOdontogramReviewer(): User
    {
        return userWith(['view_legacy_odontogram_imports', 'review_legacy_odontogram_imports']);
    }
}
