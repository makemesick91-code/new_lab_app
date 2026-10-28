<?php

/**
 * CICD-GOVERNANCE-EVALUATION-MEMO-1 — one governance evaluation computes each
 * enterprise gate once, and no result ever outlives the evaluation it was
 * computed in.
 *
 * The enterprise gates re-verify their predecessors (ENT-6 asks ENT-5, ENT-7
 * asks ENT-5 and ENT-6, ...), and each gate got fresh predecessor instances, so
 * the chain doubled at every level: one governance summary built the ENT-5 gate
 * 2,080 times and took about 110 seconds. These tests pin both halves of the
 * fix: the memo removes the recomputation, and it never serves a stale answer.
 */

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\BackupDrGovernanceService;
use App\Services\Foundation\CicdEnterpriseGateGovernanceService;
use App\Services\Foundation\DeploymentRollbackGovernanceService;
use App\Services\Foundation\DeveloperConsoleGovernanceService;
use App\Services\Foundation\EnterpriseDocumentationGovernanceService;
use App\Services\Foundation\EnterpriseFoundationClosureGovernanceService;
use App\Services\Foundation\HealthCheckGovernanceService;
use App\Services\Foundation\IdempotencyOutboxGovernanceService;
use App\Services\Foundation\LoadTestBaselineGovernanceService;
use App\Services\Foundation\LoadTestScaleProjectionGovernanceService;
use App\Services\Foundation\QueueRetryFailedJobGovernanceService;
use App\Services\Foundation\SecurityComplianceGovernanceService;
use App\Support\Foundation\GovernanceEvaluation;

function gemMemo(): GovernanceEvaluation
{
    return app(GovernanceEvaluation::class);
}

/** Strip values that legitimately differ between two runs (clock readings). */
function gemNormalize(mixed $value): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/(generated|captured|checked|collected|timestamp|_at$|^at$|duration|elapsed|time)/i', $k)) {
                continue;
            }
            $out[$k] = gemNormalize($v);
        }

        return $out;
    }

    return is_string($value) && preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d/', $value) ? '<ts>' : $value;
}

dataset('enterprise gates', [
    'ENT-5' => [QueueRetryFailedJobGovernanceService::class],
    'ENT-6' => [IdempotencyOutboxGovernanceService::class],
    'ENT-7' => [DeveloperConsoleGovernanceService::class],
    'ENT-8' => [HealthCheckGovernanceService::class],
    'ENT-9' => [SecurityComplianceGovernanceService::class],
    'ENT-10' => [CicdEnterpriseGateGovernanceService::class],
    'ENT-11' => [DeploymentRollbackGovernanceService::class],
    'ENT-12' => [BackupDrGovernanceService::class],
    'ENT-13' => [LoadTestBaselineGovernanceService::class],
    'ENT-14' => [LoadTestScaleProjectionGovernanceService::class],
    'ENT-15' => [EnterpriseDocumentationGovernanceService::class],
    'ENT-16' => [EnterpriseFoundationClosureGovernanceService::class],
]);

it('computes a value once inside one evaluation and again in the next one', function () {
    $memo = gemMemo();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return ['decision' => 'GO'];
    };

    $memo->within(function () use ($memo, $compute) {
        $memo->remember('k', $compute);
        $memo->remember('k', $compute);
        $memo->remember('k', $compute);
    });
    expect($calls)->toBe(1);

    $memo->within(fn () => $memo->remember('k', $compute));
    expect($calls)->toBe(2)
        ->and($memo->stats()['cached'])->toBe(0)
        ->and($memo->active())->toBeFalse();
});

it('never serves a result from an earlier evaluation after configuration changed', function () {
    $memo = gemMemo();
    $read = fn () => config('foundation_governance.evaluation_memo_probe');

    config(['foundation_governance.evaluation_memo_probe' => 'first']);
    $first = $memo->remember('probe', $read);
    config(['foundation_governance.evaluation_memo_probe' => 'second']);
    $second = $memo->remember('probe', $read);

    expect($first)->toBe('first')->and($second)->toBe('second');
});

it('leaves nothing behind when an evaluation throws', function () {
    $memo = gemMemo();

    expect(fn () => $memo->within(function () use ($memo) {
        $memo->remember('ok', fn () => 'cached');
        $memo->remember('boom', fn () => throw new RuntimeException('gate failed'));
    }))->toThrow(RuntimeException::class);

    expect($memo->stats()['cached'])->toBe(0)->and($memo->active())->toBeFalse();

    $calls = 0;
    $memo->remember('ok', function () use (&$calls) {
        $calls++;

        return 'fresh';
    });
    expect($calls)->toBe(1);
});

it('recomputes every time when the memo is switched off', function () {
    config(['foundation_governance.evaluation_memo' => false]);
    $memo = gemMemo();
    $calls = 0;

    $memo->within(function () use ($memo, &$calls) {
        $memo->remember('k', function () use (&$calls) {
            $calls++;
        });
        $memo->remember('k', function () use (&$calls) {
            $calls++;
        });
    });

    expect($calls)->toBe(2)->and($memo->stats()['hits'])->toBe(0);
});

it('reads the enterprise gate through the memo', function (string $gate) {
    $memo = gemMemo();
    $service = app($gate);

    $memo->within(function () use ($memo, $service, &$first, &$second, &$hitsBefore, &$hitsAfter) {
        $first = $service->collect();
        $hitsBefore = $memo->stats()['hits'];
        $second = $service->collect();
        $hitsAfter = $memo->stats()['hits'];
    });

    expect($hitsAfter)->toBe($hitsBefore + 1)
        ->and($second)->toBe($first)
        ->and($memo->stats()['cached'])->toBe(0);
})->with('enterprise gates');

it('gives the identical gate result with the memo on and off', function () {
    config(['foundation_governance.evaluation_memo' => false]);
    $off = app(CicdEnterpriseGateGovernanceService::class)->collect();

    config(['foundation_governance.evaluation_memo' => true]);
    $on = app(CicdEnterpriseGateGovernanceService::class)->collect();

    expect(gemNormalize($on))->toBe(gemNormalize($off))
        ->and($on['decision'])->toBe($off['decision']);
});

it('computes each enterprise gate once per governance summary', function () {
    $memo = gemMemo();
    $before = $memo->stats();

    app(FoundationGovernanceSummaryService::class)->collect('local');

    $after = $memo->stats();

    // One computation per enterprise gate (ENT-5..ENT-16); everything else the
    // summary needs from them is a hit. A new memoized gate raises this number
    // on purpose; a gate computed twice is the regression this guards.
    expect($after['misses'] - $before['misses'])->toBe(12)
        ->and($after['hits'] - $before['hits'])->toBeGreaterThan(0)
        ->and($after['cached'])->toBe(0)
        ->and($memo->active())->toBeFalse();
});

it('keys a gate result on its arguments, so a different argument is never served a cached result', function () {
    $gate = app(EnterpriseFoundationClosureGovernanceService::class);

    // Reference: the bare result, computed in an evaluation of its own.
    $reference = $gate->collect();

    // An empty ENT-15 report makes every predecessor decision default to FAIL,
    // so its result is distinguishable from the bare one. Without this
    // precondition the assertion below could pass vacuously.
    $failing = $gate->collect([]);
    expect($failing)->not->toBe($reference);

    // Same evaluation, argument first, then bare: the bare call must not be
    // answered with the result computed for the empty report.
    gemMemo()->within(function () use ($gate, &$withArgument, &$bare) {
        $withArgument = $gate->collect([]);
        $bare = $gate->collect();
    });

    expect($withArgument)->toBe($failing)
        ->and($bare)->toBe($reference);
});
