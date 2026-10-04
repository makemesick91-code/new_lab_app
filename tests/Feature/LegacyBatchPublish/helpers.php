<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — shared fixtures.
|--------------------------------------------------------------------------
|
| Builds on PR1's fixtures rather than forking them: the batch REVIEW helpers
| already know how to stage a rendered import and how to keep the two archives'
| rollout config from leaking into each other. PR2 adds only what publishing
| needs — an import that has actually been REVIEWED by a separate actor.
|
| The filename is `helpers.php`, not `*Test.php`, so PHPUnit never mistakes it
| for a test class; each test file requires it explicitly.
*/

use App\Models\User;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyOdontogramBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyRmeBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;

if (! function_exists('lbpRmeAdapter')) {
    function lbpRmeAdapter(): LegacyRmeBatchPublishAdapter
    {
        return app(LegacyRmeBatchPublishAdapter::class);
    }
}

if (! function_exists('lbpOdontogramAdapter')) {
    function lbpOdontogramAdapter(): LegacyOdontogramBatchPublishAdapter
    {
        return app(LegacyOdontogramBatchPublishAdapter::class);
    }
}

if (! function_exists('lbpOpenRun')) {
    function lbpOpenRun(object $adapter, User $actor): LegacyBatchPublishRun
    {
        return app(LegacyBatchPublishRunService::class)->open($adapter, $actor);
    }
}

if (! function_exists('lbpRmeReviewed')) {
    /**
     * A legacy RME import that is genuinely REVIEWED — the only state publish
     * may proceed from.
     *
     * The reviewer defaults to a SEPARATE account from the uploader, because
     * RME enforces separation of duties on review as well as publish, so a
     * fixture that reviewed as the uploader would be testing a denial by
     * accident.
     */
    function lbpRmeReviewed(?User $uploader = null, ?User $reviewer = null, string $legacyDate = '2020-05-01'): LegacyRmeImport
    {
        $uploader ??= superAdmin();
        $import = lbrRmeReady($uploader, $legacyDate);

        app(LegacyRmePublishService::class)->review($import, $reviewer ?? superAdmin());

        return $import->refresh();
    }
}

if (! function_exists('lbpDisjointMedicalRecordNumber')) {
    /**
     * A medical record number from a block the shared fixtures cannot reach.
     *
     * THE COLLISION THIS AVOIDS, found by a full-suite CI run rather than by
     * reading the code. `legacyRmeArchivablePatient()` (tests/Pest.php) and
     * `lodoPatient()` (tests/Feature/LegacyOdontogram/helpers.php) both mint
     * `sprintf('DG-%s-2024-%04d', $branchCode, $sequence)` from their OWN
     * independent `static $sequence`. Identical format, identical namespace,
     * separate counters — so any test that creates patients through BOTH
     * helpers collides on `mst_patients_medical_record_number_unique` whenever
     * the two counters happen to hold the same value.
     *
     * Running only the batch suites locally kept the counters far enough apart
     * to pass; across 4795 tests they aligned and the insert failed. The
     * probability was always there — a cross-archive test merely made it
     * reachable.
     *
     * Fixing the shared generators would be the root fix, but they are used by
     * many suites and some assert on the exact strings, so this sprint does not
     * reshape them. Starting at 9000 is disjoint by construction: both
     * sequences count up from 1 within a single process and never approach it.
     */
    function lbpDisjointMedicalRecordNumber(string $branchCode = 'TLK1'): string
    {
        static $sequence = 7000;
        $sequence++;

        return sprintf('DG-%s-2024-%04d', $branchCode, $sequence);
    }
}

if (! function_exists('lbpOdontogramReviewed')) {
    /**
     * A legacy odontogram import that is genuinely REVIEWED.
     *
     * The reviewer may legitimately BE the uploader here — this archive has no
     * separation guard — but a distinct reviewer is used by default so the
     * fixture does not quietly depend on that asymmetry.
     */
    function lbpOdontogramReviewed(?User $uploader = null, ?User $reviewer = null, string $legacyDate = '2020-05-01'): LegacyOdontogramImport
    {
        $uploader ??= superAdmin();

        // The patient is created HERE with a disjoint medical record number,
        // rather than through lbrOdontogramReady(), because that helper routes
        // to lodoPatient() which has no way to override the colliding sequence.
        $patient = lodoPatient([
            'date_of_birth' => '1990-01-01',
            'medical_record_number' => lbpDisjointMedicalRecordNumber(),
        ]);
        lodoNativeOdontogram($patient, '2022-03-10');

        $import = lodoStageImport($patient, $legacyDate, $uploader, 1);
        app(LegacyOdontogramProcessingService::class)
            ->process($import->getKey());

        app(LegacyOdontogramPublishService::class)->review($import->refresh(), $reviewer ?? superAdmin());

        return $import->refresh();
    }
}

if (! function_exists('lbpPublisher')) {
    /** An actor holding ONLY the legacy RME publish permission (plus view). */
    function lbpPublisher(): User
    {
        return userWith(['view_legacy_rme_imports', 'publish_legacy_rme_imports']);
    }
}

if (! function_exists('lbpOdontogramPublisher')) {
    /** An actor holding ONLY the legacy odontogram publish permission. */
    function lbpOdontogramPublisher(): User
    {
        return userWith(['view_legacy_odontogram_imports', 'publish_legacy_odontogram_imports']);
    }
}

if (! function_exists('lbpSelectAndPublish')) {
    /**
     * Select then publish, the way the HTTP flow does.
     *
     * @param  list<int>  $importIds
     * @return array{select: array<string,mixed>, publish: array<string,mixed>}
     */
    function lbpSelectAndPublish(
        LegacyBatchPublishRun $run,
        object $adapter,
        User $actor,
        array $importIds,
        int $limit = LegacyBatchPublishRunService::MAX_PUBLISH_PASS,
    ): array {
        $service = app(LegacyBatchPublishRunService::class);

        $select = $service->select($run, $adapter, $actor, $importIds);
        $publish = $service->publish($run->refresh(), $adapter, $actor, [], $limit);

        return ['select' => $select, 'publish' => $publish];
    }
}

if (! function_exists('lbpRmeReviewedButBlocked')) {
    /**
     * A REVIEWED legacy RME import that ALSO carries a blocking PR1 triage
     * annotation — the exact state PR2's publish-time triage re-check exists for.
     *
     * HOW THIS STATE IS REACHABLE, which took a correction to get right. PR1's
     * batch review `recordDecision()` requires READY_FOR_REVIEW, so triage
     * cannot be raised on an already-reviewed document through that flow, and
     * PR1's batch submit refuses a blocked item so it never becomes REVIEWED
     * that way either.
     *
     * The real path is the CANONICAL SINGLE-ITEM review page, which knows
     * nothing about PR1 triage: an operator blocks a document in the batch
     * review workspace, and a colleague then reviews it from the document page.
     * Now it is REVIEWED and withheld at the same time — and if batch publish
     * did not re-check triage, it would publish something a reviewer rejected.
     *
     * @return array{0: LegacyRmeImport, 1: LegacyReviewTriage}
     */
    function lbpRmeReviewedButBlocked(string $decision, string $reasonCode): array
    {
        $import = lbrRmeReady(superAdmin());

        // 1. Blocked in the PR1 batch review workspace, while still reviewable.
        $blocker = superAdmin();
        $session = lbrOpenSession(lbrRmeAdapter(), $blocker);

        app(LegacyBatchReviewSessionService::class)
            ->recordDecision(
                $session,
                lbrRmeAdapter(),
                $blocker,
                (int) $import->getKey(),
                $decision,
                $reasonCode,
            );

        $triage = LegacyReviewTriage::query()
            ->where('rme_legacy_import_id', $import->getKey())
            ->sole();

        // 2. Reviewed anyway, through the canonical single-item path.
        app(LegacyRmePublishService::class)
            ->review($import->refresh(), superAdmin());

        return [$import->refresh(), $triage->refresh()];
    }
}
