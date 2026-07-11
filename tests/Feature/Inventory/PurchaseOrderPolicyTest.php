<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\PurchaseOrder;
use Database\Seeders\BranchSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create();

    $this->draft = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
    $this->submitted = PurchaseOrder::factory()->submitted()->create(['branch_id' => $this->branch->id]);
    $this->approved = PurchaseOrder::factory()->approved()->create(['branch_id' => $this->branch->id]);
    $this->sent = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $this->cancelled = PurchaseOrder::factory()->cancelled()->create(['branch_id' => $this->branch->id]);
    $this->otherDraft = PurchaseOrder::factory()->create(['branch_id' => $this->otherBranch->id]);
    $this->otherSubmitted = PurchaseOrder::factory()->submitted()->create(['branch_id' => $this->otherBranch->id]);
    $this->otherApproved = PurchaseOrder::factory()->approved()->create(['branch_id' => $this->otherBranch->id]);
});

it('allows Admin Warehouse full purchase order workflow', function () {
    // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — the full procurement workflow belongs to the
    // inventory role (Admin Warehouse), not the Lab-only Admin Lab role.
    $admin = User::factory()->create();
    $admin->assignRole('Admin Warehouse');
    $this->actingAs($admin);

    expect($admin->can('viewAny', PurchaseOrder::class))->toBeTrue()
        ->and($admin->can('view', $this->draft))->toBeTrue()
        ->and($admin->can('create', PurchaseOrder::class))->toBeTrue()
        ->and($admin->can('update', $this->draft))->toBeTrue()
        ->and($admin->can('submit', $this->draft))->toBeTrue()
        ->and($admin->can('approve', $this->submitted))->toBeTrue()
        ->and($admin->can('send', $this->approved))->toBeTrue()
        ->and($admin->can('cancel', $this->draft))->toBeTrue()
        ->and($admin->can('cancel', $this->submitted))->toBeTrue();
});

it('allows view_inventory to list and view same branch purchase orders', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('viewAny', PurchaseOrder::class))->toBeTrue()
        ->and($viewer->can('view', $this->draft))->toBeTrue();
});

it('denies view_inventory mutation and workflow abilities', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('create', PurchaseOrder::class))->toBeFalse()
        ->and($viewer->can('update', $this->draft))->toBeFalse()
        ->and($viewer->can('submit', $this->draft))->toBeFalse()
        ->and($viewer->can('approve', $this->submitted))->toBeFalse()
        ->and($viewer->can('send', $this->approved))->toBeFalse()
        ->and($viewer->can('cancel', $this->draft))->toBeFalse();
});

it('allows manage_inventory to create update submit send and cancel draft or submitted', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('create', PurchaseOrder::class))->toBeTrue()
        ->and($manager->can('update', $this->draft))->toBeTrue()
        ->and($manager->can('submit', $this->draft))->toBeTrue()
        ->and($manager->can('send', $this->approved))->toBeTrue()
        ->and($manager->can('cancel', $this->draft))->toBeTrue()
        ->and($manager->can('cancel', $this->submitted))->toBeTrue();
});

it('allows manage_inventory to approve submitted purchase orders', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('approve', $this->submitted))->toBeTrue();
});

it('allows legacy manage master data to approve submitted same branch purchase orders', function () {
    $legacyManager = userWith(['manage master data']);
    $this->actingAs($legacyManager);

    expect($legacyManager->can('approve', $this->submitted))->toBeTrue();
});

it('denies approve without approval manage or legacy permissions', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('approve', $this->submitted))->toBeFalse();
});

it('allows approve_inventory_purchase_order without manage_inventory for approval only', function () {
    $approver = userWith(['approve_inventory_purchase_order', 'view_inventory']);
    $this->actingAs($approver);

    expect($approver->can('approve', $this->submitted))->toBeTrue()
        ->and($approver->can('create', PurchaseOrder::class))->toBeFalse()
        ->and($approver->can('update', $this->draft))->toBeFalse()
        ->and($approver->can('submit', $this->draft))->toBeFalse()
        ->and($approver->can('send', $this->approved))->toBeFalse()
        ->and($approver->can('cancel', $this->draft))->toBeFalse()
        ->and($approver->can('cancel', $this->submitted))->toBeFalse();
});

it('denies cross branch purchase order access', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('view', $this->otherDraft))->toBeFalse();

    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('view', $this->otherDraft))->toBeFalse()
        ->and($manager->can('update', $this->otherDraft))->toBeFalse()
        ->and($manager->can('submit', $this->otherDraft))->toBeFalse()
        ->and($manager->can('approve', $this->otherSubmitted))->toBeFalse()
        ->and($manager->can('send', $this->otherApproved))->toBeFalse()
        ->and($manager->can('cancel', $this->otherDraft))->toBeFalse();
});

it('denies update when purchase order is not draft', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('update', $this->submitted))->toBeFalse()
        ->and($manager->can('update', $this->approved))->toBeFalse()
        ->and($manager->can('update', $this->sent))->toBeFalse()
        ->and($manager->can('update', $this->cancelled))->toBeFalse();
});

it('denies submit when purchase order is not draft', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('submit', $this->submitted))->toBeFalse()
        ->and($manager->can('submit', $this->approved))->toBeFalse()
        ->and($manager->can('submit', $this->sent))->toBeFalse()
        ->and($manager->can('submit', $this->cancelled))->toBeFalse();
});

it('denies approve when purchase order is not submitted', function () {
    $approver = userWith(['approve_inventory_purchase_order', 'view_inventory']);
    $this->actingAs($approver);

    expect($approver->can('approve', $this->draft))->toBeFalse()
        ->and($approver->can('approve', $this->approved))->toBeFalse()
        ->and($approver->can('approve', $this->sent))->toBeFalse()
        ->and($approver->can('approve', $this->cancelled))->toBeFalse();
});

it('denies send when purchase order is not approved', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('send', $this->draft))->toBeFalse()
        ->and($manager->can('send', $this->submitted))->toBeFalse()
        ->and($manager->can('send', $this->sent))->toBeFalse()
        ->and($manager->can('send', $this->cancelled))->toBeFalse();
});

it('denies cancel when purchase order is approved sent or cancelled', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('cancel', $this->approved))->toBeFalse()
        ->and($manager->can('cancel', $this->sent))->toBeFalse()
        ->and($manager->can('cancel', $this->cancelled))->toBeFalse();
});

it('does not overgrant approve_inventory_purchase_order in role seeder', function () {
    $rolesWithoutApproval = [
        'Technician',
        'Quality Control',
        'Delivery Coordinator',
        'Courier',
        'Finance',
        'Doctor',
        // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — Admin Lab is Lab-only, no procurement approval.
        'Admin Lab',
    ];

    foreach ($rolesWithoutApproval as $roleName) {
        $role = Role::findByName($roleName);
        expect($role->hasPermissionTo('approve_inventory_purchase_order'))->toBeFalse();
    }

    // Admin Warehouse is the canonical procurement approver.
    expect(Role::findByName('Admin Warehouse')->hasPermissionTo('approve_inventory_purchase_order'))->toBeTrue();
});

it('supports sprint 16.3 receiving statuses on purchase order model constants', function () {
    expect(PurchaseOrder::STATUSES)->toBe([
        'draft',
        'submitted',
        'approved',
        'sent',
        'partially_received',
        'fully_received',
        'cancelled',
    ])
        ->and(PurchaseOrder::TERMINAL_STATUSES)->toBe(['fully_received', 'cancelled']);

    expect(PurchaseOrder::STATUSES)->not->toContain('closed');
});
