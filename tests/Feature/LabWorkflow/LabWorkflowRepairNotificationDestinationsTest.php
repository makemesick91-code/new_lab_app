<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Notifications\LabWorkflowEvent;
use App\Modules\LabOrder\Services\LabWorkflowNotificationRepairService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->mainBranch = Branch::factory()->main()->create();
    $this->branch = Branch::factory()->create([
        'code' => 'BR2',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

function repairOrder(string $status = LabWorkflowState::DELIVERED): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => $status,
        'branch_id' => Branch::query()->where('code', 'BR2')->value('id'),
    ]);
}

/** Simulate a legacy broken "Model tiba di cabang" notification for Admin Lab. */
function brokenDeliveredNotification(User $recipient, LabOrder $order): DatabaseNotification
{
    $recipient->notify(new LabWorkflowEvent(
        'Model tiba di cabang',
        "Order {$order->order_number} telah diterima Perawat.",
        route('lab-workflow-requests.show', $order->id), // the WRONG legacy destination
        $order->id,
    ));

    return DatabaseNotification::query()->where('notifiable_id', $recipient->id)->latest('created_at')->firstOrFail();
}

function runRepair(array $options = []): array
{
    return app(LabWorkflowNotificationRepairService::class)->run($options);
}

// ---------------------------------------------------------------------------
// Dry-run vs apply
// ---------------------------------------------------------------------------

it('dry-run detects the broken Admin Lab notification without changing the DB', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    $row = brokenDeliveredNotification($adminLab, $order);

    $summary = runRepair(['apply' => false]);

    expect($summary['mode'])->toBe('dry-run');
    expect($summary['repairable'])->toBe(1);
    expect($summary['applied'])->toBe(0);
    // DB untouched.
    expect($row->fresh()->data['url'])->toContain('/lab/workflow-requests/');
});

it('apply repairs the broken Admin Lab URL to the V2 order detail', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    $row = brokenDeliveredNotification($adminLab, $order);
    $before = $row->created_at;
    $readAtBefore = $row->read_at;

    $summary = runRepair(['apply' => true]);

    expect($summary['applied'])->toBe(1);
    $fresh = $row->fresh();
    expect($fresh->data['url'])->toContain('/lab/v2-orders/'.$order->id);
    expect($fresh->data['url'])->not->toContain('/lab/workflow-requests/');
    // Preserved fields.
    expect($fresh->data['title'])->toBe('Model tiba di cabang');
    expect($fresh->data['lab_order_id'])->toBe($order->id);
    expect($fresh->read_at)->toEqual($readAtBefore);
    expect($fresh->created_at->timestamp)->toBe($before->timestamp);
    expect($fresh->id)->toBe($row->id);
});

it('is idempotent — a second apply repairs nothing', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    brokenDeliveredNotification($adminLab, $order);

    runRepair(['apply' => true]);
    $second = runRepair(['apply' => true]);

    expect($second['repairable'])->toBe(0);
    expect($second['applied'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Scope safety
// ---------------------------------------------------------------------------

it('never touches a non-Lab notification', function () {
    $user = userWith(['view_lab_orders']);
    // A raw non-LabWorkflowEvent notification row.
    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SomethingElse',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['url' => '/lab/workflow-requests/1', 'title' => 'Model tiba di cabang'],
        'read_at' => null,
    ]);

    $summary = runRepair(['apply' => true]);

    expect($summary['scanned'])->toBe(0);
    expect($summary['applied'])->toBe(0);
});

it('leaves a correct branch-operator notification unchanged', function () {
    $order = repairOrder();
    $perawat = User::factory()->create(['branch_id' => (int) $order->branch_id])->assignRole('Perawat');
    // Correct destination already stored.
    $perawat->notify(new LabWorkflowEvent('Model tiba di cabang', 'msg', route('lab-workflow-requests.show', $order->id), $order->id));

    $summary = runRepair(['apply' => true]);

    expect($summary['already_correct'])->toBe(1);
    expect($summary['applied'])->toBe(0);
});

it('skips (fails closed) a notification whose order no longer exists', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    brokenDeliveredNotification($adminLab, $order);
    $order->delete(); // soft-delete -> LabOrder::find returns null

    $summary = runRepair(['apply' => true]);

    expect($summary['applied'])->toBe(0);
    expect($summary['skipped_missing_order'])->toBe(1);
    expect($summary['anomalies'])->toBe(1);
});

it('skips a legacy (non-V2) order notification', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_LEGACY,
        'branch_id' => (int) $this->branch->id,
    ]);
    $adminLab->notify(new LabWorkflowEvent('Model tiba di cabang', 'msg', route('lab-workflow-requests.show', $order->id), $order->id));

    $summary = runRepair(['apply' => true]);

    expect($summary['skipped_non_v2'])->toBe(1);
    expect($summary['applied'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Filters + command
// ---------------------------------------------------------------------------

it('filters by notification id and by user id', function () {
    $adminA = User::factory()->create()->assignRole('Admin Lab');
    $adminB = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    $rowA = brokenDeliveredNotification($adminA, $order);
    brokenDeliveredNotification($adminB, $order);

    expect(runRepair(['notification_id' => $rowA->id])['scanned'])->toBe(1);
    expect(runRepair(['user_id' => $adminB->id])['scanned'])->toBe(1);
});

it('command dry-run reports pending repairs and strict exits non-zero', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    brokenDeliveredNotification($adminLab, $order);

    $this->artisan('lab-workflow:repair-notification-destinations --strict')
        ->assertExitCode(2);
});

it('command apply fixes and a subsequent strict dry-run exits zero', function () {
    $adminLab = User::factory()->create()->assignRole('Admin Lab');
    $order = repairOrder();
    brokenDeliveredNotification($adminLab, $order);

    $this->artisan('lab-workflow:repair-notification-destinations --apply')->assertExitCode(0);
    $this->artisan('lab-workflow:repair-notification-destinations --strict')->assertExitCode(0);
});
