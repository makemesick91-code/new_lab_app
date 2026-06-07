<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryActivityLogRepositoryInterface;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Repositories\InventoryActivityLogRepository;
use App\Modules\Inventory\Services\InventoryActivityLogService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->repo = app(InventoryActivityLogRepositoryInterface::class);
    $this->service = app(InventoryActivityLogService::class);
});

describe('migration and model', function () {
    it('can create inventory activity log', function () {
        $log = InventoryActivityLog::factory()->create(['branch_id' => $this->branch->id]);

        expect($log)->toBeInstanceOf(InventoryActivityLog::class)
            ->and($log->exists)->toBeTrue()
            ->and($log->branch_id)->toBe($this->branch->id);
    });

    it('casts metadata to array', function () {
        $log = InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'metadata' => ['status_before' => 'draft', 'status_after' => 'submitted'],
        ]);

        expect($log->metadata)->toBeArray()
            ->and($log->metadata['status_before'])->toBe('draft');
    });

    it('has no updated_at', function () {
        expect(InventoryActivityLog::UPDATED_AT)->toBeNull();

        $log = InventoryActivityLog::factory()->create(['branch_id' => $this->branch->id]);

        expect($log->updated_at)->toBeNull()
            ->and($log->getAttributes())->not->toHaveKey('updated_at');
    });
});

describe('InventoryActivityAction', function () {
    it('accepts valid actions', function () {
        expect(InventoryActivityAction::isValid(InventoryActivityAction::PURCHASE_REQUEST_CREATED))->toBeTrue()
            ->and(InventoryActivityAction::isValid(InventoryActivityAction::GOODS_RECEIPT_COMPLETED))->toBeTrue()
            ->and(in_array(InventoryActivityAction::STOCK_TRANSFER_RECEIVED, InventoryActivityAction::all(), true))->toBeTrue();
    });

    it('rejects invalid actions', function () {
        expect(InventoryActivityAction::isValid('not_a_real_action'))->toBeFalse()
            ->and(InventoryActivityAction::isValid(''))->toBeFalse();
    });
});

describe('InventoryActivityLogService', function () {
    it('log creates record', function () {
        $manager = userWith(['manage_inventory']);
        $this->actingAs($manager);

        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        $log = $this->service->log(
            InventoryActivityAction::PURCHASE_REQUEST_CREATED,
            $purchaseRequest,
            ['status_after' => PurchaseRequest::STATUS_DRAFT],
            'Purchase request created',
            $manager,
        );

        expect($log->action)->toBe(InventoryActivityAction::PURCHASE_REQUEST_CREATED)
            ->and($log->branch_id)->toBe($this->branch->id)
            ->and($log->user_id)->toBe($manager->id)
            ->and($log->description)->toBe('Purchase request created');
    });

    it('logForBranch creates record', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->otherBranch->id]);

        $log = $this->service->logForBranch(
            $this->otherBranch->id,
            InventoryActivityAction::PURCHASE_REQUEST_SUBMITTED,
            $purchaseRequest,
            ['status_after' => PurchaseRequest::STATUS_SUBMITTED],
        );

        expect($log->branch_id)->toBe($this->otherBranch->id)
            ->and($log->action)->toBe(InventoryActivityAction::PURCHASE_REQUEST_SUBMITTED);
    });

    it('saves correlation_id when provided', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
        $correlationId = (string) Str::uuid();

        $log = $this->service->logForBranch(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_ORDER_CREATED,
            $purchaseRequest,
            [],
            null,
            null,
            $correlationId,
        );

        expect($log->correlation_id)->toBe($correlationId);
    });

    it('rejects invalid correlation_id', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        expect(fn () => $this->service->logForBranch(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_CREATED,
            $purchaseRequest,
            [],
            null,
            null,
            'not-a-uuid',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows nullable user for system logging', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        $log = $this->service->logForBranch(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $purchaseRequest,
        );

        expect($log->user_id)->toBeNull();
    });

    it('uses subject table name for subject_type', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        $log = $this->service->logForBranch(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_UPDATED,
            $purchaseRequest,
        );

        expect($log->subject_type)->toBe($purchaseRequest->getTable())
            ->and($log->subject_id)->toBe($purchaseRequest->id);
    });

    it('rejects invalid action', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        expect(fn () => $this->service->logForBranch(
            $this->branch->id,
            'bogus_action',
            $purchaseRequest,
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('InventoryActivityLogRepository', function () {
    it('binds the interface to the concrete repository', function () {
        expect($this->repo)->toBeInstanceOf(InventoryActivityLogRepository::class);
    });

    it('paginates only within the given branch', function () {
        InventoryActivityLog::factory()->count(2)->create(['branch_id' => $this->branch->id]);
        InventoryActivityLog::factory()->create(['branch_id' => $this->otherBranch->id]);

        $result = $this->repo->paginate($this->branch->id);

        expect($result->total())->toBe(2)
            ->and($result->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);
    });

    it('does not expose cross branch logs via findInBranch', function () {
        $otherLog = InventoryActivityLog::factory()->create(['branch_id' => $this->otherBranch->id]);

        expect($this->repo->findInBranch($this->branch->id, $otherLog->id))->toBeNull();
    });

    it('filters by action', function () {
        InventoryActivityLog::factory()->forAction(InventoryActivityAction::PURCHASE_REQUEST_CREATED)->create(['branch_id' => $this->branch->id]);
        InventoryActivityLog::factory()->forAction(InventoryActivityAction::GOODS_RECEIPT_CREATED)->create(['branch_id' => $this->branch->id]);

        expect($this->repo->paginate($this->branch->id, ['action' => InventoryActivityAction::PURCHASE_REQUEST_CREATED])->total())->toBe(1);
    });

    it('filters by user', function () {
        $actor = User::factory()->create();

        InventoryActivityLog::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $actor->id]);
        InventoryActivityLog::factory()->withoutUser()->create(['branch_id' => $this->branch->id]);

        expect($this->repo->paginate($this->branch->id, ['user_id' => $actor->id])->total())->toBe(1);
    });

    it('filters by correlation_id', function () {
        $correlationId = (string) Str::uuid();

        InventoryActivityLog::factory()->withCorrelationId($correlationId)->create(['branch_id' => $this->branch->id]);
        InventoryActivityLog::factory()->create(['branch_id' => $this->branch->id]);

        expect($this->repo->paginate($this->branch->id, ['correlation_id' => $correlationId])->total())->toBe(1);
    });

    it('filters by date range', function () {
        InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'created_at' => now()->subDays(10),
        ]);
        InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'created_at' => now()->subDay(),
        ]);

        $result = $this->repo->paginate($this->branch->id, [
            'date_from' => now()->subDays(3)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        expect($result->total())->toBe(1);
    });

    it('forSubject returns branch scoped subject logs', function () {
        $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
        $otherRequest = PurchaseRequest::factory()->create(['branch_id' => $this->otherBranch->id]);

        InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'subject_type' => $purchaseRequest->getTable(),
            'subject_id' => $purchaseRequest->id,
        ]);
        InventoryActivityLog::factory()->create([
            'branch_id' => $this->otherBranch->id,
            'subject_type' => $otherRequest->getTable(),
            'subject_id' => $otherRequest->id,
        ]);

        $result = $this->repo->forSubject(
            $this->branch->id,
            $purchaseRequest->getTable(),
            $purchaseRequest->id,
        );

        expect($result->total())->toBe(1)
            ->and($result->first()->subject_id)->toBe($purchaseRequest->id);
    });
});

describe('InventoryActivityLogPolicy', function () {
    beforeEach(function () {
        $this->log = InventoryActivityLog::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherLog = InventoryActivityLog::factory()->create(['branch_id' => $this->otherBranch->id]);
    });

    it('allows view_inventory to viewAny', function () {
        $viewer = userWith(['view_inventory']);
        $this->actingAs($viewer);

        expect($viewer->can('viewAny', InventoryActivityLog::class))->toBeTrue()
            ->and($viewer->can('view', $this->log))->toBeTrue();
    });

    it('allows manage_inventory to viewAny', function () {
        $manager = userWith(['manage_inventory']);
        $this->actingAs($manager);

        expect($manager->can('viewAny', InventoryActivityLog::class))->toBeTrue()
            ->and($manager->can('view', $this->log))->toBeTrue();
    });

    it('denies users without inventory permissions', function () {
        $user = userWith(['view_purchase_request']);
        $this->actingAs($user);

        expect($user->can('viewAny', InventoryActivityLog::class))->toBeFalse()
            ->and($user->can('view', $this->log))->toBeFalse();
    });

    it('respects branch isolation on view', function () {
        $viewer = userWith(['view_inventory']);
        $this->actingAs($viewer);

        expect($viewer->can('view', $this->otherLog))->toBeFalse();
    });

    it('allows view_inventory_activity_log to viewAny', function () {
        $viewer = userWith(['view_inventory_activity_log']);
        $this->actingAs($viewer);

        expect($viewer->can('viewAny', InventoryActivityLog::class))->toBeTrue()
            ->and($viewer->can('view', $this->log))->toBeTrue();
    });

    it('allows view_inventory_analytics to viewAny', function () {
        $viewer = userWith(['view_inventory_analytics']);
        $this->actingAs($viewer);

        expect($viewer->can('viewAny', InventoryActivityLog::class))->toBeTrue();
    });
});

describe('display helpers', function () {
    it('formats action labels from snake_case', function () {
        $log = InventoryActivityLog::factory()->forAction(InventoryActivityAction::PURCHASE_REQUEST_CREATED)->make();

        expect($log->displayActionLabel())->toBe('Purchase Request Created');
    });

    it('clarifies stock transfer approved as shipped when in transit', function () {
        $log = InventoryActivityLog::factory()->forAction(InventoryActivityAction::STOCK_TRANSFER_APPROVED)->make([
            'metadata' => ['status_to' => 'in_transit'],
        ]);

        expect($log->displayActionLabel())->toBe('Stock Transfer Shipped (In Transit)');
    });

    it('clarifies goods receipt completed as posted when posted', function () {
        $log = InventoryActivityLog::factory()->forAction(InventoryActivityAction::GOODS_RECEIPT_COMPLETED)->make([
            'metadata' => ['status_to' => 'posted'],
        ]);

        expect($log->displayActionLabel())->toBe('Goods Receipt Posted');
    });

    it('summarizes metadata without dumping full json', function () {
        $log = InventoryActivityLog::factory()->make([
            'metadata' => [
                'document_number' => 'PR-1001',
                'status_from' => 'draft',
                'status_to' => 'submitted',
                'item_count' => 3,
                'movement_ids' => [10, 11],
            ],
        ]);

        expect($log->metadataSummary())->toBe('PR-1001 · draft → submitted · 3 item · 2 movement');
    });
});

describe('activity log routes and authorization', function () {
    beforeEach(function () {
        $this->log = InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'action' => InventoryActivityAction::PURCHASE_REQUEST_CREATED,
            'description' => 'Purchase request alpha created',
        ]);
    });

    it('allows view_inventory_activity_log users to access index', function () {
        $this->actingAs(userWith(['view_inventory_activity_log']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk()
            ->assertSee('Log Aktivitas Persediaan');
    });

    it('allows view_inventory users to access index', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk();
    });

    it('allows manage_inventory users to access index', function () {
        $this->actingAs(userWith(['manage_inventory']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk();
    });

    it('allows view_inventory_analytics users to access index', function () {
        $this->actingAs(userWith(['view_inventory_analytics']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk();
    });

    it('denies users without inventory activity permissions', function () {
        $this->actingAs(userWith(['view_purchase_request']))
            ->get(route('inventory.activity-logs.index'))
            ->assertForbidden();
    });

    it('shows only logs from the active branch on index', function () {
        $otherLog = InventoryActivityLog::factory()->create([
            'branch_id' => $this->otherBranch->id,
            'description' => 'Other branch secret log entry',
        ]);

        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk()
            ->assertSee($this->log->description)
            ->assertDontSee($otherLog->description);
    });

    it('returns 404 when showing a log from another branch', function () {
        $otherLog = InventoryActivityLog::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.show', $otherLog))
            ->assertNotFound();
    });

    it('shows activity log detail for authorized users', function () {
        $this->actingAs(userWith(['view_inventory_activity_log']))
            ->get(route('inventory.activity-logs.show', $this->log))
            ->assertOk()
            ->assertSee('Detail Log Aktivitas')
            ->assertSee('Purchase Request Created')
            ->assertSee('"document_number"');
    });
});

describe('activity log index filters via http', function () {
    beforeEach(function () {
        $this->actor = User::factory()->create();
        $this->purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

        InventoryActivityLog::factory()->forAction(InventoryActivityAction::PURCHASE_REQUEST_CREATED)->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->actor->id,
            'subject_type' => $this->purchaseRequest->getTable(),
            'subject_id' => $this->purchaseRequest->id,
            'description' => 'Alpha purchase request log',
            'created_at' => now()->subDays(5),
        ]);

        InventoryActivityLog::factory()->forAction(InventoryActivityAction::GOODS_RECEIPT_COMPLETED)->create([
            'branch_id' => $this->branch->id,
            'description' => 'Beta goods receipt log',
            'created_at' => now()->subDay(),
        ]);
    });

    it('filters by action', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', ['action' => InventoryActivityAction::GOODS_RECEIPT_COMPLETED]))
            ->assertOk()
            ->assertSee('Beta goods receipt log')
            ->assertDontSee('Alpha purchase request log');
    });

    it('filters by user_id', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', ['user_id' => $this->actor->id]))
            ->assertOk()
            ->assertSee('Alpha purchase request log')
            ->assertDontSee('Beta goods receipt log');
    });

    it('filters by subject_type and subject_id', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', [
                'subject_type' => $this->purchaseRequest->getTable(),
                'subject_id' => $this->purchaseRequest->id,
            ]))
            ->assertOk()
            ->assertSee('Alpha purchase request log')
            ->assertDontSee('Beta goods receipt log');
    });

    it('filters by correlation_id', function () {
        $correlationId = (string) Str::uuid();

        InventoryActivityLog::factory()->withCorrelationId($correlationId)->create([
            'branch_id' => $this->branch->id,
            'description' => 'Correlated workflow log',
        ]);

        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', ['correlation_id' => $correlationId]))
            ->assertOk()
            ->assertSee('Correlated workflow log')
            ->assertDontSee('Alpha purchase request log');
    });

    it('filters by date range', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', [
                'date_from' => now()->subDays(2)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Beta goods receipt log')
            ->assertDontSee('Alpha purchase request log');
    });

    it('filters by search description', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', ['search' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha purchase request log')
            ->assertDontSee('Beta goods receipt log');
    });

    it('respects per_page', function () {
        InventoryActivityLog::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index', ['per_page' => 2]));

        $response->assertOk();
        expect($response->viewData('logs')->perPage())->toBe(2);
    });
});

describe('activity log sidebar visibility', function () {
    it('shows Log Aktivitas for users with view_inventory_activity_log', function () {
        $this->actingAs(userWith(['view_inventory_activity_log']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Log Aktivitas');
    });

    it('shows Log Aktivitas for users with view_inventory', function () {
        $this->actingAs(userWith(['view_inventory']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Log Aktivitas');
    });

    it('shows Log Aktivitas for users with view_inventory_analytics', function () {
        $this->actingAs(userWith(['view_inventory_analytics']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Log Aktivitas');
    });

    it('hides Log Aktivitas for users without activity log permissions', function () {
        $user = userWith(['view_purchase_request']);

        $response = $this->actingAs($user)->get(route('inventory.purchase-requests.index'));

        $response->assertOk()->assertDontSee('Log Aktivitas');
    });
});

describe('activity log views', function () {
    it('renders human-readable action labels on index without full metadata json', function () {
        InventoryActivityLog::factory()->create([
            'branch_id' => $this->branch->id,
            'action' => InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            'description' => 'Movement log for UI test',
            'metadata' => [
                'document_number' => 'GR-9001',
                'status_from' => 'draft',
                'status_to' => 'posted',
                'item_count' => 2,
            ],
        ]);

        $this->actingAs(userWith(['view_inventory']))
            ->get(route('inventory.activity-logs.index'))
            ->assertOk()
            ->assertSee('Inventory Movement Created')
            ->assertSee('GR-9001 · draft → posted · 2 item')
            ->assertDontSee('"status_from"');
    });
});
