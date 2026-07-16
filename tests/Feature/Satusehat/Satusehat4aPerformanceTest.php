<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityScanService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOperationalReadinessService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => config()->set('satusehat.candidate.auto_generate', false));

function ssBulkCandidates(array $ctx, int $count, int $startQueue): void
{
    $now = now();
    $rows = [];
    foreach (range(1, $count) as $i) {
        $q = $startQueue + $i;
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $ctx['branch']->id,
            'clinic_id' => $ctx['visit']->clinic_id,
            'patient_id' => $ctx['patient']->id,
            'doctor_id' => $ctx['doctor']->id,
            'visit_number' => 'PERF-'.$q,
            'queue_number' => $q,
            'status' => ClinicVisit::STATUS_COMPLETED,
        ]);
        $rows[] = [
            'environment' => 'sandbox',
            'branch_id' => $ctx['branch']->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $ctx['patient']->id,
            'doctor_id' => $ctx['doctor']->id,
            'source_version' => 1,
            'source_hash' => hash('sha256', 'perf-'.$visit->id),
            'readiness_status' => SatusehatCandidate::READINESS_INCOMPLETE,
            'readiness_reasons' => json_encode([['code' => 'patient_ihs_missing', 'severity' => 'incomplete', 'message' => 'x']]),
            'review_status' => SatusehatCandidate::REVIEW_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('trx_satusehat_candidates')->insert($rows);
}

/**
 * Deterministic query-count boundedness: the dashboard aggregation and the
 * candidate board must run a CONSTANT number of queries regardless of the
 * candidate volume (SQL GROUP BY + per-page batched lookups — no N+1).
 */
it('metrics and candidate board query counts stay constant as candidate volume grows', function () {
    $ctx = ssMakeVisit(['queue_number' => 500]);
    $branchIds = [$ctx['branch']->id];
    $service = app(SatusehatOperationalReadinessService::class);

    $count = function (callable $fn): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    ssBulkCandidates($ctx, 10, 1000);
    $small = [
        'metrics' => $count(fn () => $service->metrics($branchIds)),
        'board' => $count(fn () => $service->candidateBoard([], $branchIds, 20)),
    ];

    ssBulkCandidates($ctx, 90, 2000); // now 100
    $large = [
        'metrics' => $count(fn () => $service->metrics($branchIds)),
        'board' => $count(fn () => $service->candidateBoard([], $branchIds, 20)),
    ];

    expect($large['metrics'])->toBe($small['metrics'])
        ->and($large['board'])->toBe($small['board'])
        ->and($small['board'])->toBeLessThan(15);
});

it('the data-quality scan respects the configured hard cap', function () {
    config()->set('satusehat_data_quality.scan.max_batch_size', 5);

    $ctx = ssMakeVisit(['queue_number' => 600]);
    ssBulkCandidates($ctx, 10, 3000);

    $summary = app(SatusehatDataQualityScanService::class)
        ->scan(limit: 1000, apply: false);

    expect($summary['scanned'])->toBeLessThanOrEqual(5);
});
