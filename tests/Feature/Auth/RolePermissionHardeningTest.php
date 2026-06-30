<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
});

it('seeds pilot dashboard and RME permissions', function () {
    foreach ([
        'view_owner_dashboard',
        'view_branch_dashboard',
        'view_clinic_visits',
        'manage_clinic_visits',
        'manage_rme_billing',
        'view_lab_orders',
        'manage_lab_orders',
    ] as $permission) {
        expect(Permission::where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue();
    }
});

it('seeds dedicated pilot clinic roles', function () {
    foreach (['Owner', 'Kasir', 'Perawat'] as $role) {
        expect(Role::where('name', $role)->where('guard_name', 'web')->exists())->toBeTrue();
    }
});

it('grants Owner read-only executive permissions without operational manage access', function () {
    $role = Role::findByName('Owner');

    expect($role->hasPermissionTo('view_owner_dashboard'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_report'))->toBeTrue()
        ->and($role->hasPermissionTo('view_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_clinic_visits'))->toBeFalse()
        ->and($role->hasPermissionTo('manage_rme_billing'))->toBeFalse()
        ->and($role->hasPermissionTo('manage_lab_orders'))->toBeFalse()
        ->and($role->hasPermissionTo('manage_inventory'))->toBeFalse();
});

it('grants Kasir least-privilege cashier access only', function () {
    $role = Role::findByName('Kasir');

    expect($role->hasPermissionTo('manage_rme_billing'))->toBeTrue()
        ->and($role->hasPermissionTo('view_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_clinic_visits'))->toBeFalse()
        ->and($role->hasPermissionTo('manage_clinic_master_data'))->toBeFalse()
        ->and($role->hasPermissionTo('manage_lab_orders'))->toBeFalse();
});

it('grants Perawat clinic visit support without cashier or lab access', function () {
    $role = Role::findByName('Perawat');

    expect($role->hasPermissionTo('view_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage patients'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_rme_billing'))->toBeFalse()
        ->and($role->hasPermissionTo('view_lab_orders'))->toBeFalse();
});

it('keeps Doctor on clinical workflow without cashier billing', function () {
    $role = Role::findByName('Doctor');

    expect($role->hasPermissionTo('manage_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_rme_billing'))->toBeFalse()
        ->and($role->hasPermissionTo('view_lab_orders'))->toBeFalse();
});

it('keeps Admin Klinik on full clinic and cashier workflow', function () {
    $role = Role::findByName('Admin Klinik');

    expect($role->hasPermissionTo('manage_clinic_visits'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_rme_billing'))->toBeTrue()
        ->and($role->hasPermissionTo('view_clinic_master_data'))->toBeTrue()
        ->and($role->hasPermissionTo('view_rme_patient_reports'))->toBeTrue()
        ->and($role->hasPermissionTo('view_rme_payment_reports'))->toBeFalse();
});

it('keeps Admin Lab on lab and RME integration permissions', function () {
    $role = Role::findByName('Admin Lab');

    expect($role->hasPermissionTo('view_lab_orders'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_rme_billing'))->toBeTrue()
        ->and($role->hasPermissionTo('view_branch_dashboard'))->toBeTrue();
});
