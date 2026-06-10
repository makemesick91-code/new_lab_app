<?php

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedAccessControl();

    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
});

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.payment-methods.index'))
        ->assertForbidden();
});

it('allows a viewer with view_clinic_master_data to access the index', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index'))
        ->assertOk()
        ->assertViewIs('settings.payment-methods.index');
});

it('lists payment methods visible to a viewer', function () {
    PaymentMethod::factory()->create([
        'code' => 'CASH',
        'name' => 'Tunai',
        'type' => 'cash',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index'))
        ->assertOk()
        ->assertSee('CASH')
        ->assertSee('Tunai');
});

it('denies a viewer from opening the create page', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.create'))
        ->assertForbidden();
});

it('denies a viewer from storing a payment method', function () {
    $this->actingAs($this->viewer)
        ->post(route('settings.payment-methods.store'), [
            'code' => 'NEW',
            'name' => 'Baru',
            'type' => 'cash',
        ])
        ->assertForbidden();
});

it('denies a viewer from updating a payment method', function () {
    $method = PaymentMethod::factory()->create();

    $this->actingAs($this->viewer)
        ->put(route('settings.payment-methods.update', $method), [
            'code' => $method->code,
            'name' => 'Diubah Viewer',
            'type' => $method->type,
        ])
        ->assertForbidden();
});

it('denies a viewer from deleting a payment method', function () {
    $method = PaymentMethod::factory()->create();

    $this->actingAs($this->viewer)
        ->delete(route('settings.payment-methods.destroy', $method))
        ->assertForbidden();
});

it('lets a manager open the create page', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.payment-methods.create'))
        ->assertOk()
        ->assertViewIs('settings.payment-methods.create');
});

it('stores a valid payment method', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.payment-methods.store'), [
            'code' => 'EWALLET',
            'name' => 'Dompet Digital',
            'type' => 'other',
            'sort_order' => 60,
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.payment-methods.index'));

    $this->assertDatabaseHas('mst_payment_methods', [
        'code' => 'EWALLET',
        'name' => 'Dompet Digital',
        'type' => 'other',
        'sort_order' => 60,
        'is_active' => true,
    ]);
});

it('normalizes the code to uppercase on store', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.payment-methods.store'), [
            'code' => 'debit',
            'name' => 'Debit',
            'type' => 'card',
        ])
        ->assertRedirect(route('settings.payment-methods.index'));

    $this->assertDatabaseHas('mst_payment_methods', ['code' => 'DEBIT']);
    $this->assertDatabaseMissing('mst_payment_methods', ['code' => 'debit']);
});

it('requires code', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => '',
            'name' => 'Test',
            'type' => 'cash',
        ])
        ->assertSessionHasErrors('code');
});

it('requires name', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TEST',
            'name' => '',
            'type' => 'cash',
        ])
        ->assertSessionHasErrors('name');
});

it('requires type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'type' => '',
        ])
        ->assertSessionHasErrors('type');
});

it('rejects an invalid type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'type' => 'cryptocurrency',
        ])
        ->assertSessionHasErrors('type');
});

it('rejects a negative sort_order', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'type' => 'cash',
            'sort_order' => -1,
        ])
        ->assertSessionHasErrors('sort_order');
});

it('rejects a non-integer sort_order', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'type' => 'cash',
            'sort_order' => 'abc',
        ])
        ->assertSessionHasErrors('sort_order');
});

it('rejects a duplicate code', function () {
    PaymentMethod::factory()->create(['code' => 'DUPL']);

    $this->actingAs($this->manager)
        ->from(route('settings.payment-methods.create'))
        ->post(route('settings.payment-methods.store'), [
            'code' => 'DUPL',
            'name' => 'Duplikat',
            'type' => 'cash',
        ])
        ->assertSessionHasErrors('code');
});

it('updates a payment method', function () {
    $method = PaymentMethod::factory()->create([
        'code' => 'ORIG',
        'name' => 'Original',
        'type' => 'cash',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.payment-methods.update', $method), [
            'code' => 'ORIG',
            'name' => 'Diperbarui',
            'type' => 'bank_transfer',
            'sort_order' => 20,
            'is_active' => '0',
        ])
        ->assertRedirect(route('settings.payment-methods.index'));

    $method->refresh();
    expect($method->name)->toBe('Diperbarui')
        ->and($method->type)->toBe('bank_transfer')
        ->and($method->sort_order)->toBe(20)
        ->and($method->is_active)->toBeFalse();
});

it('soft deletes a payment method', function () {
    $method = PaymentMethod::factory()->create();

    $this->actingAs($this->manager)
        ->delete(route('settings.payment-methods.destroy', $method))
        ->assertRedirect(route('settings.payment-methods.index'));

    expect(PaymentMethod::find($method->id))->toBeNull();
    expect(PaymentMethod::withTrashed()->find($method->id))->not->toBeNull();
});

it('searches payment methods by code or name', function () {
    PaymentMethod::factory()->create(['code' => 'CASH', 'name' => 'Tunai']);
    PaymentMethod::factory()->create(['code' => 'QRIS', 'name' => 'Kode QR']);

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['search' => 'Tunai']))
        ->assertOk()
        ->assertSee('Tunai')
        ->assertDontSee('Kode QR');

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['search' => 'QRIS']))
        ->assertOk()
        ->assertSee('Kode QR')
        ->assertDontSee('Tunai');
});

it('filters payment methods by active status', function () {
    PaymentMethod::factory()->create(['code' => 'ACTIVE-PM', 'name' => 'Metode Aktif', 'is_active' => true]);
    PaymentMethod::factory()->inactive()->create(['code' => 'INACT-PM', 'name' => 'Metode Nonaktif']);

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['is_active' => '1']))
        ->assertOk()
        ->assertSee('Metode Aktif')
        ->assertDontSee('Metode Nonaktif');

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['is_active' => '0']))
        ->assertOk()
        ->assertSee('Metode Nonaktif')
        ->assertDontSee('Metode Aktif');
});

it('filters payment methods by type', function () {
    PaymentMethod::factory()->create(['code' => 'CSH-1', 'name' => 'Bayar Tunai', 'type' => 'cash']);
    PaymentMethod::factory()->create(['code' => 'TRF-1', 'name' => 'Transfer Bank', 'type' => 'bank_transfer']);

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['type' => 'cash']))
        ->assertOk()
        ->assertSee('Bayar Tunai')
        ->assertDontSee('Transfer Bank');

    $this->actingAs($this->viewer)
        ->get(route('settings.payment-methods.index', ['type' => 'bank_transfer']))
        ->assertOk()
        ->assertSee('Transfer Bank')
        ->assertDontSee('Bayar Tunai');
});

it('seeds the 5 default payment methods', function () {
    test()->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::count())->toBe(5);

    $expected = [
        ['code' => 'CASH',          'name' => 'Tunai',         'type' => 'cash',          'sort_order' => 10],
        ['code' => 'BANK_TRANSFER', 'name' => 'Transfer Bank', 'type' => 'bank_transfer', 'sort_order' => 20],
        ['code' => 'QRIS',          'name' => 'QRIS',          'type' => 'qris',          'sort_order' => 30],
        ['code' => 'CARD',          'name' => 'Kartu',         'type' => 'card',          'sort_order' => 40],
        ['code' => 'OTHER',         'name' => 'Lainnya',       'type' => 'other',         'sort_order' => 50],
    ];

    foreach ($expected as $row) {
        $this->assertDatabaseHas('mst_payment_methods', $row + ['is_active' => true]);
    }
});

it('has an idempotent payment method seeder', function () {
    test()->seed(PaymentMethodSeeder::class);
    test()->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::count())->toBe(5);
});

it('does not create Invoice, Payment, LabOrder, installment, or RME records when a payment method is stored', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.payment-methods.store'), [
            'code' => 'TESTPM',
            'name' => 'Test Metode',
            'type' => 'other',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.payment-methods.index'));

    expect(Invoice::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(LabOrder::count())->toBe(0);

    if (Schema::hasTable('trx_medical_records')) {
        expect(DB::table('trx_medical_records')->count())->toBe(0);
    }
});
