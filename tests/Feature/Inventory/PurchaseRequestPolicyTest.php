<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\PurchaseRequest;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create();

    $this->draft = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $this->submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);
    $this->otherDraft = PurchaseRequest::factory()->create(['branch_id' => $this->otherBranch->id]);
});

it('allows view_inventory to list and view same branch purchase requests', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('viewAny', PurchaseRequest::class))->toBeTrue()
        ->and($viewer->can('view', $this->draft))->toBeTrue();
});

it('denies view_inventory mutation and workflow abilities', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('create', PurchaseRequest::class))->toBeFalse()
        ->and($viewer->can('update', $this->draft))->toBeFalse()
        ->and($viewer->can('submit', $this->draft))->toBeFalse()
        ->and($viewer->can('approve', $this->submitted))->toBeFalse()
        ->and($viewer->can('reject', $this->submitted))->toBeFalse()
        ->and($viewer->can('cancel', $this->draft))->toBeFalse();
});

it('allows manage_inventory to create update submit and cancel draft or submitted', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('create', PurchaseRequest::class))->toBeTrue()
        ->and($manager->can('update', $this->draft))->toBeTrue()
        ->and($manager->can('submit', $this->draft))->toBeTrue()
        ->and($manager->can('cancel', $this->draft))->toBeTrue()
        ->and($manager->can('cancel', $this->submitted))->toBeTrue();
});

it('allows manage_inventory to approve and reject submitted purchase requests', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('approve', $this->submitted))->toBeTrue()
        ->and($manager->can('reject', $this->submitted))->toBeTrue();
});

it('allows approve_inventory_purchase_request without manage_inventory for approval only', function () {
    $approver = userWith(['approve_inventory_purchase_request', 'view_inventory']);
    $this->actingAs($approver);

    expect($approver->can('approve', $this->submitted))->toBeTrue()
        ->and($approver->can('reject', $this->submitted))->toBeTrue()
        ->and($approver->can('create', PurchaseRequest::class))->toBeFalse()
        ->and($approver->can('update', $this->draft))->toBeFalse();
});

it('denies update submit cancel on non-draft or invalid statuses', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('update', $this->submitted))->toBeFalse()
        ->and($manager->can('submit', $this->submitted))->toBeFalse();

    $approved = PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branch->id]);

    expect($manager->can('cancel', $approved))->toBeFalse()
        ->and($manager->can('approve', $approved))->toBeFalse();
});

it('denies cross branch purchase request access', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('view', $this->otherDraft))->toBeFalse();

    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('view', $this->otherDraft))->toBeFalse()
        ->and($manager->can('update', $this->otherDraft))->toBeFalse()
        ->and($manager->can('submit', $this->otherDraft))->toBeFalse();
});
