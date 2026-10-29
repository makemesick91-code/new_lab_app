<?php

/**
 * SECURITY-F4C-CLINIC-CALENDAR-LAB-ORDERS-1 — owner decision D16: lab operational business dates
 * use the clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
 * so a new lab order was dated (and numbered) on the previous clinic day, a due date of
 * "yesterday" passed the not-in-the-past check, and the operational dashboard's "delivered today"
 * counted the UTC day.
 *
 *   2026-10-27 15:59 UTC = 2026-10-27 23:59 WITA  (same date)
 *   2026-10-27 16:01 UTC = 2026-10-28 00:01 WITA  (UTC is still the 27th)
 *   2026-10-27 23:59 UTC = 2026-10-28 07:59 WITA  (UTC is still the 27th)
 *
 * D16 also says what must NOT change: an explicitly entered order or due date is kept exactly as
 * entered, and event timestamps stay UTC instants.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Delivery\Services\DeliveryNumberGeneratorService;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\ConvertLabCaseCandidateRequest;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\LabOrder\Services\LabWorkflowOperationalDashboardService;
use App\Modules\LabOrder\Services\OrderNumberGeneratorService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\Validator;

beforeEach(fn () => seedAccessControl());

afterEach(function () {
    freeTestClock();
});

dataset('instants', [
    '23:59 WITA' => ['2026-10-27 15:59:00', '2026-10-27'],
    '00:01 WITA' => ['2026-10-27 16:01:00', '2026-10-28'],
    '07:59 WITA' => ['2026-10-27 23:59:00', '2026-10-28'],
]);

it('dates a new lab order on the clinic date when none is given', function (string $instant, string $clinicDate) {
    pinTestClock($instant);
    $payload = labOrderPayload();
    unset($payload['order_date']);

    $order = app(LabOrderService::class)->create($payload, superAdmin());

    expect($order->fresh()->order_date->toDateString())->toBe($clinicDate);
})->with('instants');

it('keeps an explicitly entered order date exactly as entered', function () {
    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01

    $order = app(LabOrderService::class)->create(labOrderPayload(['order_date' => '2026-10-20']), superAdmin());

    expect($order->fresh()->order_date->toDateString())->toBe('2026-10-20')
        ->and($order->fresh()->created_at->toDateTimeString())->toBe('2026-10-27 16:01:00');
});

it('numbers a lab order and a delivery by the clinic year across new year', function () {
    pinTestClock('2026-12-31 16:30:00'); // clinic 2027-01-01 00:30, UTC still 2026

    expect(app(OrderNumberGeneratorService::class)->generate())->toStartWith('ADL-2027-')
        ->and(app(DeliveryNumberGeneratorService::class)->generate())->toStartWith('DLV-2027-');
});

it('accepts a due date on the clinic date and refuses the previous clinic day', function () {
    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01, UTC still the 27th
    $rule = (new ConvertLabCaseCandidateRequest)->rules()['due_date'];

    $valid = fn (string $date) => Validator::make(['due_date' => $date], ['due_date' => $rule])->passes();

    expect($valid('2026-10-28'))->toBeTrue()
        ->and($valid('2026-10-27'))->toBeFalse();
});

it('counts deliveries made on the clinic day, not on the UTC day', function () {
    $staff = userWith(['manage_lab_orders']);
    $branch = Branch::factory()->create(['is_active' => true]);
    $delivered = function (string $deliveredAtUtc) use ($branch): void {
        $order = LabOrder::factory()->create([
            'workflow_version' => LabOrder::WORKFLOW_V2,
            'status' => LabWorkflowState::DELIVERED,
            'branch_id' => $branch->id,
        ]);
        LabDeliveryTask::factory()->create([
            'lab_order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'status' => LabDeliveryTask::STATUS_DELIVERED,
            'delivered_at' => $deliveredAtUtc,
        ]);
    };

    $delivered('2026-10-27 15:30:00'); // 2026-10-27 23:30 WITA — previous clinic day
    $delivered('2026-10-27 16:30:00'); // 2026-10-28 00:30 WITA — this clinic day
    $delivered('2026-10-27 23:00:00'); // 2026-10-28 07:00 WITA — this clinic day

    pinTestClock('2026-10-27 23:59:00'); // clinic 2026-10-28 07:59, UTC still the 27th

    $overview = app(LabWorkflowOperationalDashboardService::class)->overview($staff);

    expect($overview['delivered_today'])->toBe(2);
});
