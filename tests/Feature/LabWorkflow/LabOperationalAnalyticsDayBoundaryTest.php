<?php

/**
 * FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1
 *
 * `median_lateness_days` used to be decided by what time of day CI happened to
 * start. The runtime is fine: it measures lateness as an ELAPSED duration from
 * the END of the due day. The fixture was not — it pinned `due_date` to a
 * calendar date but `delivered_at` to the live wall clock, so the same three
 * records were "1.00 days late" just after midnight and "2.00 days late" just
 * before it. Against a `> 1.0` assertion that produced a real CI failure at
 * 00:02:59Z and a green rerun of the same commit hours later.
 *
 * The reproduced failure window is [00:00:00, 00:07:11] in the application
 * timezone — 7m12s of every day, ~0.5% of runs. Both edges are pinned below.
 *
 * Time authority: Lab operational analytics has no domain clock. `resolvePeriod()`
 * uses `today()` and `slaCompliance()` uses `Carbon::parse(...)->endOfDay()`, both
 * of which resolve in the application timezone — `config/app.php` hardcodes it to
 * UTC. `ClinicalClock` (Asia/Makassar) is the CLINICAL-date authority and is
 * deliberately not borrowed here: nothing in this module's dates is clinical.
 */

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOperationalAnalyticsService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Carbon;

beforeEach(fn () => seedAccessControl());

afterEach(fn () => freeTestClock());

/** Full-tier, all-branch scope for direct service assertions. */
function slaBoundaryScope(): array
{
    return [
        'tier' => 'full',
        'sees_all' => true,
        'branch_id' => null,
        'technician_id' => null,
        'technician_name' => null,
    ];
}

function slaBoundaryOrder(array $attrs = []): LabOrder
{
    return LabOrder::factory()->create(array_merge([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => labOpsBranch()->id,
        'order_date' => now()->toDateString(),
        'status' => LabWorkflowState::DELIVERED,
    ], $attrs));
}

function slaBoundaryDeliver(LabOrder $order, $changedAt): void
{
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::RECEIVED_AT_LAB,
        'new_status' => LabWorkflowState::DELIVERED,
        'changed_by' => labOpsActor()->id,
        'changed_at' => $changedAt,
    ]);
}

/**
 * A completed order that is EXACTLY `$daysLate` days late, by construction.
 *
 * The runtime counts lateness from the end of the due day, so delivering at the
 * start of day `due + daysLate + 1` is exactly `daysLate` days past that point.
 * Both endpoints are anchored to the due day — never to "now" — which is why the
 * expected value below does not move when the wall clock does.
 *
 * Whole days only, and typed as `int` on purpose: this helper places the delivery
 * on a day boundary, so a fractional argument could not be honoured and would be
 * silently truncated into a different expectation than the caller wrote.
 */
function slaBoundaryLateCase(Carbon $deliveredOn, int $daysLate): void
{
    $dueDay = $deliveredOn->copy()->startOfDay()->subDays($daysLate + 1);

    $order = slaBoundaryOrder(['due_date' => $dueDay->toDateString()]);
    slaBoundaryDeliver($order, $deliveredOn->copy()->startOfDay());
}

function slaBoundaryKpi(): array
{
    return app(LabOperationalAnalyticsService::class)
        ->analytics(slaBoundaryScope(), ['period' => 'month'])['kpi']['sla'];
}

/**
 * The reproduced failure window and its two edges, plus the control instants
 * either side of the day boundary. 16:00Z is 00:00 the next day in
 * Asia/Makassar: it proves the metric follows the application timezone and does
 * not secretly pivot on the clinical one.
 */
dataset('dayBoundaryInstants', [
    'day before, 23:55' => '2026-05-19 23:55:00',
    'last second of the previous day' => '2026-05-19 23:59:59',
    'exact day boundary' => '2026-05-20 00:00:00',
    'historical CI failure instant' => '2026-05-20 00:02:59',
    'inside the failure window' => '2026-05-20 00:03:00',
    'last instant inside the failure window' => '2026-05-20 00:07:11',
    'first instant outside the failure window' => '2026-05-20 00:07:12',
    'shortly after the window' => '2026-05-20 00:15:00',
    'midday' => '2026-05-20 12:00:00',
    'Asia/Makassar midnight (16:00Z)' => '2026-05-20 16:00:00',
]);

it('reports the same SLA block at every instant across the day boundary', function (string $instant) {
    pinTestClock($instant);

    // Delivered the day before the reference day, so every instant in the
    // dataset sees an identical, already-completed record set.
    $deliveredOn = Carbon::parse('2026-05-19', config('app.timezone'));

    // Lateness 1, 2 and 4 days → median is the MIDDLE value (2.0), not the mean
    // (2.33). Inserted out of order so the median is not the insertion order.
    slaBoundaryLateCase($deliveredOn, 4);
    slaBoundaryLateCase($deliveredOn, 1);
    slaBoundaryLateCase($deliveredOn, 2);

    // On-time: delivered now, due today.
    $onTime = slaBoundaryOrder(['due_date' => now()->toDateString()]);
    slaBoundaryDeliver($onTime, now());

    // Boundary: delivered on the last tick of the due day → still on-time.
    $boundary = slaBoundaryOrder(['due_date' => now()->toDateString()]);
    slaBoundaryDeliver($boundary, now()->endOfDay());

    expect(slaBoundaryKpi())->toMatchArray([
        'eligible' => 5,
        'on_time' => 2,
        'late' => 3,
        'compliance_pct' => 40.0,
        'median_lateness_days' => 2.0,
    ]);
})->with('dayBoundaryInstants');

it('takes the mean of the middle pair when the late count is even', function () {
    pinTestClock('2026-05-20 00:02:59');

    $deliveredOn = Carbon::parse('2026-05-19', config('app.timezone'));
    slaBoundaryLateCase($deliveredOn, 1);
    slaBoundaryLateCase($deliveredOn, 4);

    // Even count → (1.0 + 4.0) / 2. A "middle element" implementation would
    // return 1.0 or 4.0 here, and a mean-of-all would agree by coincidence only
    // because n = 2, which the odd-count case above already rules out.
    expect(slaBoundaryKpi()['median_lateness_days'])->toBe(2.5);
});

it('measures lateness from the end of the due day, so the due day itself is never late', function () {
    pinTestClock('2026-05-20 00:02:59');

    // Delivered on the due day itself, at the very instant the day begins.
    $sameDay = slaBoundaryOrder(['due_date' => '2026-05-18']);
    slaBoundaryDeliver($sameDay, Carbon::parse('2026-05-18 00:00:00', config('app.timezone')));

    // Delivered one full day after that due day ends.
    slaBoundaryLateCase(Carbon::parse('2026-05-19', config('app.timezone')), 1);

    expect(slaBoundaryKpi())->toMatchArray([
        'eligible' => 2,
        'on_time' => 1,
        'late' => 1,
        'median_lateness_days' => 1.0,
    ]);
});

it('resolves the reporting period in the application timezone, not the clinical one', function () {
    // 16:00Z is already the 21st in Asia/Makassar. If this module had borrowed
    // ClinicalClock the window would end on the 21st.
    pinTestClock('2026-05-20 16:00:00');

    $period = app(LabOperationalAnalyticsService::class)->resolvePeriod('month', null, null);

    expect(config('app.timezone'))->toBe('UTC')
        ->and($period['to'])->toBe('2026-05-20')
        ->and($period['from'])->toBe('2026-05-01');
});

it('leaves no pinned clock behind for the next test', function () {
    // Runs after every pinning test in this file. If any of them leaked a frozen
    // instant past its own teardown, this is where an unrelated suite would
    // start inheriting it.
    expect(Carbon::hasTestNow())->toBeFalse();
});
