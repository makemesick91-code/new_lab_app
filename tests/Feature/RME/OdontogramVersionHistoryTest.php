<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR3 — version history of a finalized odontogram
|--------------------------------------------------------------------------
|
| Rule 240 §5. Finalizing a chart writes its version 1 — an immutable,
| hash-chained snapshot of the chart payload — inside the finalize transaction,
| after the visit and chart locks; re-finalizing writes nothing; a chart
| finalized before this rule gets a `baseline` version 1 on demand. Reading is
| authorized like reading the chart.
|
| Selected by the existing `Odontogram` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `m33vo` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->author = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
});

function m33voChart(array $attributes = []): Odontogram
{
    $visit = rmeConsentedOdontogramVisit(['branch_id' => test()->branch->id]);

    return Odontogram::factory()->create($attributes + [
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'summary_notes' => 'm33vo ringkasan',
        'additional_conditions' => 'm33vo kondisi tambahan',
        'tooth_map_payload' => ['teeth' => [
            '16' => ['status' => 'caries', 'conditions' => ['caries'], 'note' => 'm33vo oklusal'],
            '21' => ['status' => 'filling', 'conditions' => ['filling'], 'note' => ''],
        ]],
    ])->fresh();
}

/** @return Collection<int, RmeRecordVersion> */
function m33voVersions(Odontogram $chart)
{
    return RmeRecordVersion::query()
        ->where('subject_type', RmeRecordVersion::SUBJECT_ODONTOGRAM)
        ->where('subject_id', $chart->id)
        ->orderBy('version')
        ->get();
}

it('writes version 1 of a chart on finalize, carrying the chart payload', function () {
    $chart = m33voChart();
    $final = app(OdontogramService::class)->finalize($chart, $this->author);

    $versions = m33voVersions($chart);
    expect($versions)->toHaveCount(1);

    $v1 = $versions->first();
    expect($v1->version)->toBe(1)
        ->and($v1->kind)->toBe(RmeRecordVersion::KIND_FINAL)
        ->and($v1->previous_hash)->toBeNull()
        ->and($v1->created_by)->toBe($this->author->id)
        ->and($v1->snapshot['odontogram_id'])->toBe($chart->id)
        ->and($v1->snapshot['status'])->toBe(Odontogram::STATUS_FINALIZED)
        ->and($v1->snapshot['finalized_at'])->toBe($final->finalized_at->toIso8601String())
        ->and($v1->snapshot['summary_notes'])->toBe('m33vo ringkasan')
        ->and($v1->snapshot['additional_conditions'])->toBe('m33vo kondisi tambahan')
        ->and($v1->snapshot['tooth_map_payload']['teeth']['16']['note'])->toBe('m33vo oklusal')
        ->and($v1->snapshot['tooth_map_payload']['teeth']['21']['conditions'])->toBe(['filling'])
        ->and(app(RmeRecordVersionService::class)->verifyChain($chart->fresh()))->toBeTrue();
});

it('writes nothing new when a finalized chart is finalized again, even from a stale draft copy', function () {
    $chart = m33voChart();
    $stale = $chart->fresh();
    app(OdontogramService::class)->finalize($chart, $this->author);
    $hash = m33voVersions($chart)->first()->hash;

    app(OdontogramService::class)->finalize($chart->fresh(), $this->author);
    app(OdontogramService::class)->finalize($stale, $this->author);

    expect(m33voVersions($chart))->toHaveCount(1)
        ->and(m33voVersions($chart)->first()->hash)->toBe($hash);
});

it('writes a baseline version 1, once, for a chart finalized before this rule', function () {
    $chart = m33voChart(['status' => Odontogram::STATUS_FINALIZED, 'finalized_at' => now()]);
    $service = app(RmeRecordVersionService::class);

    $baseline = DB::transaction(fn () => $service->ensureBaseline($chart->fresh(), $this->author->id));
    $again = DB::transaction(fn () => $service->ensureBaseline($chart->fresh(), $this->author->id));

    expect($baseline->kind)->toBe(RmeRecordVersion::KIND_BASELINE)
        ->and($baseline->version)->toBe(1)
        ->and($again->id)->toBe($baseline->id)
        ->and($baseline->snapshot['tooth_map_payload']['teeth']['16']['status'])->toBe('caries')
        ->and(m33voVersions($chart))->toHaveCount(1);

    $draft = m33voChart();
    expect(fn () => DB::transaction(fn () => $service->ensureBaseline($draft, $this->author->id)))->toThrow(LogicException::class);
});

// Review MEDIUM-C — the hash must be over exactly what is stored and read back.
// A chart payload number with a zero fraction (5.0) or a negative zero was hashed
// as `5.0` but stored by the array cast as `5`, so verifyChain() failed for a
// legitimate row. The payload is written by raw SQL, as any earlier writer may
// have stored it, then the chart is finalized through the real service.
it('verifies the chain of a chart whose payload holds zero-fraction and negative-zero floats', function (string $payload, string $path, float $value) {
    $chart = m33voChart();
    DB::table('trx_odontograms')->where('id', $chart->id)->update(['tooth_map_payload' => $payload]);

    app(OdontogramService::class)->finalize($chart->fresh(), $this->author);
    $v1 = m33voVersions($chart)->sole();

    expect(app(RmeRecordVersionService::class)->verifyChain($chart->fresh()))->toBeTrue()
        ->and((float) data_get($v1->snapshot['tooth_map_payload'], $path))->toEqual($value)
        // The version reads back with the hash it was written with.
        ->and(app(RmeRecordVersionService::class)->hashFor(
            $v1->subject_type, $v1->subject_id, $v1->version, $v1->kind, $v1->previous_hash, $v1->snapshotJson(),
        ))->toBe($v1->hash);
})->with([
    'a zero-fraction float' => ['{"teeth": {}, "depth_mm": 5.0}', 'depth_mm', 5.0],
    'a negative zero' => ['{"teeth": {}, "offset_mm": -0.0}', 'offset_mm', 0.0],
    'floats in a list' => ['{"teeth": {}, "readings": [1.0, 2.50, -0.0, 3]}', 'readings.1', 2.5],
]);

it('reads versions only for an account that may read the chart', function () {
    $chart = m33voChart();
    app(OdontogramService::class)->finalize($chart, $this->author);
    $service = app(RmeRecordVersionService::class);

    expect($service->versionsFor($this->author, $chart->fresh())->pluck('kind')->all())->toBe([RmeRecordVersion::KIND_FINAL])
        ->and($service->amendmentCount($chart->fresh()))->toBe(0);

    $outsider = doctorWithOnlineContext($this->branch);
    expect(fn () => $service->versionsFor($outsider, $chart->fresh()))->toThrow(AuthorizationException::class);
});

it('writes the chart version after the visit and chart locks (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    $chart = m33voChart();
    $events = [];
    DB::listen(function ($query) use (&$events) {
        $sql = strtolower(ltrim($query->sql));
        if (preg_match('/for (no key )?update/', $sql)) {
            $events[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_odontograms') ? 'chart' : 'other');
        } elseif (str_starts_with($sql, 'insert into "trx_rme_record_versions"')) {
            $events[] = 'version';
        }
    });

    app(OdontogramService::class)->finalize($chart, $this->author);

    $visitAt = array_search('visit', $events, true);
    $chartAt = array_search('chart', $events, true);
    $versionAt = array_search('version', $events, true);

    expect($visitAt)->not->toBeFalse()
        ->and($chartAt)->not->toBeFalse()
        ->and($versionAt)->not->toBeFalse()
        ->and($visitAt)->toBeLessThan($chartAt)
        ->and($chartAt)->toBeLessThan($versionAt);
});
