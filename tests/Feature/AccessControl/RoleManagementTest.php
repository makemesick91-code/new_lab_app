<?php

use App\Modules\AccessControl\Services\PermissionGroupingService;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
});

it('lists roles for an authorized user', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.index', ['search' => 'Super Admin']))
        ->assertOk()
        ->assertViewIs('settings.roles.index')
        ->assertSee('Super Admin');
});

it('creates a role with permissions (happy path)', function () {
    $this->actingAs(userWith(['manage roles']))
        ->post(route('settings.roles.store'), [
            'name' => 'Receptionist',
            'permissions' => ['view dashboard'],
        ])
        ->assertRedirect(route('settings.roles.index'));

    $role = Role::where('name', 'Receptionist')->first();
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('view dashboard'))->toBeTrue();
});

it('validates required and unique role name on create', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.roles.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs(superAdmin())
        ->post(route('settings.roles.store'), ['name' => 'Finance']) // already seeded
        ->assertSessionHasErrors('name');
});

it('updates a role and syncs permissions (assignment)', function () {
    $role = Role::create(['name' => 'Temp Role', 'guard_name' => 'web']);

    $this->actingAs(superAdmin())
        ->put(route('settings.roles.update', $role), [
            'name' => 'Temp Role Renamed',
            'permissions' => ['view dashboard', 'manage invoices'],
        ])
        ->assertRedirect(route('settings.roles.index'));

    $role->refresh();
    expect($role->name)->toBe('Temp Role Renamed');
    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['manage invoices', 'view dashboard']);
});

it('removes all permissions when none submitted on update', function () {
    $role = Role::create(['name' => 'Has Perms', 'guard_name' => 'web']);
    $role->givePermissionTo('view dashboard');

    $this->actingAs(superAdmin())
        ->put(route('settings.roles.update', $role), ['name' => 'Has Perms'])
        ->assertRedirect();

    expect($role->refresh()->permissions)->toHaveCount(0);
});

it('deletes a non-protected role', function () {
    $role = Role::create(['name' => 'Disposable', 'guard_name' => 'web']);

    $this->actingAs(superAdmin())
        ->delete(route('settings.roles.destroy', $role))
        ->assertRedirect(route('settings.roles.index'));

    expect(Role::where('name', 'Disposable')->exists())->toBeFalse();
});

it('prevents deleting the Super Admin role', function () {
    $role = Role::findByName('Super Admin');

    $this->actingAs(superAdmin())
        ->delete(route('settings.roles.destroy', $role))
        ->assertSessionHasErrors('role');

    expect(Role::where('name', 'Super Admin')->exists())->toBeTrue();
});

it('denies role management without permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('settings.roles.index'))
        ->assertForbidden();
});

it('shows inventory permission group on create role page', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertSee('data-permission-group="inventory"', false)
        ->assertSee('Inventory / Persediaan');
});

it('shows inventory permissions on create role page', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertSee('view_inventory')
        ->assertSee('manage_inventory')
        ->assertSee('Lihat data persediaan dan stok')
        ->assertSee('Kelola persediaan, stok, dan operasi gudang');
});

it('shows procurement permissions inside inventory group on create role page', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertDontSee('data-permission-group="procurement"', false)
        ->assertSee('approve_inventory_purchase_request')
        ->assertSee('approve_inventory_purchase_order')
        ->assertSee('view_purchase_request')
        ->assertSee('manage_purchase_order')
        ->assertSee('view_goods_receipt');
});

it('preserves checked permissions on edit role page', function () {
    $role = Role::create(['name' => 'Inventory Operator', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_inventory', 'manage_inventory', 'view dashboard']);

    $response = $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.edit', $role))
        ->assertOk()
        ->assertSee('data-permission-group="inventory"', false);

    $html = $response->getContent();

    expect($html)->toMatch('/value="view_inventory"[^>]*checked|checked[^>]*value="view_inventory"/');
    expect($html)->toMatch('/value="manage_inventory"[^>]*checked|checked[^>]*value="manage_inventory"/');
});

it('renders module select-all controls without changing permission input names', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertSee('data-module-select-all="inventory"', false)
        ->assertSee('name="permissions[]"', false);
});

it('creates a role with inventory permissions', function () {
    $this->actingAs(userWith(['manage roles']))
        ->post(route('settings.roles.store'), [
            'name' => 'Gudang Staff',
            'permissions' => ['view_inventory', 'manage_inventory'],
        ])
        ->assertRedirect(route('settings.roles.index'));

    $role = Role::where('name', 'Gudang Staff')->first();
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('view_inventory'))->toBeTrue();
    expect($role->hasPermissionTo('manage_inventory'))->toBeTrue();
});

it('seeds inventory permissions idempotently', function () {
    seedAccessControl();

    expect(Permission::where('name', 'view_inventory')->count())->toBe(1);
    expect(Permission::where('name', 'manage_inventory')->count())->toBe(1);

    test()->seed([PermissionSeeder::class]);

    expect(Permission::where('name', 'view_inventory')->count())->toBe(1);
    expect(Permission::where('name', 'manage_inventory')->count())->toBe(1);
});

it('does not assign inventory permissions to the Lab-only Admin Lab role', function () {
    // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — inventory is out of the Admin Lab domain.
    $role = Role::findByName('Admin Lab');

    expect($role->hasPermissionTo('view_inventory'))->toBeFalse();
    expect($role->hasPermissionTo('manage_inventory'))->toBeFalse();
    expect($role->hasPermissionTo('view_inventory_executive_dashboard'))->toBeFalse();
});

it('keeps view_inventory on Technician and Quality Control roles', function () {
    expect(Role::findByName('Technician')->hasPermissionTo('view_inventory'))->toBeTrue();
    expect(Role::findByName('Quality Control')->hasPermissionTo('view_inventory'))->toBeTrue();
});

it('seeds granular inventory permissions for role assignment', function () {
    $granular = [
        'view_stock_opname',
        'manage_stock_opname',
        'view_inventory_batch_lot',
        'manage_inventory_batch_lot',
        'view_stock_alert',
        'manage_stock_alert',
        'view_inventory_analytics',
        'manage_inventory_analytics',
        'view_inventory_executive_dashboard',
        'view_stock_transfer',
        'manage_stock_transfer',
        'view_purchase_request',
        'manage_purchase_request',
        'view_purchase_order',
        'manage_purchase_order',
        'view_goods_receipt',
        'manage_goods_receipt',
    ];

    foreach ($granular as $permission) {
        expect(Permission::where('name', $permission)->count())->toBe(1);
    }
});

it('groups inventory permissions under inventory module bucket', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());
    $inventoryGroup = collect($groups)->firstWhere('key', 'inventory');

    expect($inventoryGroup)->not->toBeNull();
    expect($inventoryGroup['label'])->toBe('Inventory / Persediaan');
    expect(collect($inventoryGroup['permissions'])->pluck('name')->all())->toContain(
        'view_stock_transfer',
        'view_goods_receipt',
        'view_inventory_executive_dashboard',
    );
    expect(collect($groups)->contains('key', 'procurement'))->toBeFalse();

    // Sprint 55: purchase request permissions are reclassified into a dedicated
    // Purchase / Procurement group (out of Other and out of the Inventory bucket).
    $purchaseGroup = collect($groups)->firstWhere('key', 'purchase');
    expect($purchaseGroup)->not->toBeNull();
    expect($purchaseGroup['label'])->toBe('Purchase / Procurement');
    expect(collect($purchaseGroup['permissions'])->pluck('name')->all())->toContain(
        'view_purchase_request',
        'manage_purchase_request',
    );
});

it('groups every seeded permission into a module bucket', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());
    $groupedNames = collect($groups)
        ->flatMap(fn (array $group) => collect($group['permissions'])->pluck('name'))
        ->sort()
        ->values()
        ->all();

    expect($groupedNames)->toBe(collect(PermissionSeeder::PERMISSIONS)->sort()->values()->all());
});
