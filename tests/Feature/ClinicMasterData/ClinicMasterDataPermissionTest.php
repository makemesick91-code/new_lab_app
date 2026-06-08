<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

it('seeds the clinic master data permissions', function () {
    seedAccessControl();

    expect(Permission::where('name', 'view_clinic_master_data')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage_clinic_master_data')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('is idempotent and does not duplicate clinic master data permissions', function () {
    seedAccessControl();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    seedAccessControl();

    expect(Permission::where('name', 'view_clinic_master_data')->count())->toBe(1)
        ->and(Permission::where('name', 'manage_clinic_master_data')->count())->toBe(1);
});

it('keeps existing inventory and procurement permissions intact', function () {
    seedAccessControl();

    foreach (['manage_inventory', 'view_inventory', 'view_purchase_order', 'view_goods_receipt'] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('grants the Super Admin the clinic master data permissions through role sync', function () {
    seedAccessControl();

    expect(superAdmin()->can('manage_clinic_master_data'))->toBeTrue();
});
