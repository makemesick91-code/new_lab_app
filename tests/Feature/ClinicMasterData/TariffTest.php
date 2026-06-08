<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Database\Seeders\BranchSeeder;
use Database\Seeders\TariffSeeder;
use Database\Seeders\TreatmentCategorySeeder;
use Database\Seeders\TreatmentSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
    $this->category = TreatmentCategory::factory()->create(['code' => 'CAT-REST', 'name' => 'Restorasi Test']);
    $this->treatment = Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-FILL',
        'name' => 'Tambal Gigi',
    ]);
});

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.tariffs.index'))
        ->assertForbidden();
});

it('lists tariffs for a viewer with treatment details and rupiah price', function () {
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'price' => 300000,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index'))
        ->assertOk()
        ->assertViewIs('settings.tariffs.index')
        ->assertSee('TRT-FILL')
        ->assertSee('Tambal Gigi')
        ->assertSee('300.000');
});

it('only lists tariffs from the active branch', function () {
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Tarif Cabang Aktif',
    ]);
    $otherBranch = Branch::factory()->create(['code' => 'TRF2']);
    $otherTreatment = Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'name' => 'Perawatan Cabang Lain',
    ]);
    Tariff::factory()->create([
        'branch_id' => $otherBranch->id,
        'treatment_id' => $otherTreatment->id,
        'notes' => 'Tarif Cabang Lain',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index'))
        ->assertOk()
        ->assertSee('Tarif Cabang Aktif')
        ->assertDontSee('Tarif Cabang Lain');
});

it('lets a manager open the create page without exposing a branch selector', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.tariffs.create'))
        ->assertOk()
        ->assertViewIs('settings.tariffs.create')
        ->assertSee('TRT-FILL')
        ->assertDontSee('name="branch_id"', false);
});

it('stores a valid tariff in the active branch', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => 300000,
            'effective_date' => '2026-01-01',
            'is_active' => '1',
            'notes' => 'Tarif standar',
        ])
        ->assertRedirect(route('settings.tariffs.index'));

    $this->assertDatabaseHas('mst_tariffs', [
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'price' => 300000,
        'effective_date' => '2026-01-01',
        'is_active' => true,
    ]);
});

it('ignores any branch_id supplied in the request and uses the active branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'TRF9']);

    $this->actingAs($this->manager)
        ->post(route('settings.tariffs.store'), [
            'branch_id' => $otherBranch->id,
            'treatment_id' => $this->treatment->id,
            'price' => 123000,
            'effective_date' => '2026-02-01',
        ])
        ->assertRedirect(route('settings.tariffs.index'));

    $this->assertDatabaseHas('mst_tariffs', [
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'price' => 123000,
    ]);
    $this->assertDatabaseMissing('mst_tariffs', [
        'branch_id' => $otherBranch->id,
    ]);
});

it('requires treatment_id, price and effective_date', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.tariffs.create'))
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => '',
            'price' => '',
            'effective_date' => '',
        ])
        ->assertSessionHasErrors(['treatment_id', 'price', 'effective_date']);
});

it('rejects a negative price', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.tariffs.create'))
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => -100,
            'effective_date' => '2026-01-01',
        ])
        ->assertSessionHasErrors('price');
});

it('requires the treatment to exist', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.tariffs.create'))
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => 999999,
            'price' => 100000,
            'effective_date' => '2026-01-01',
        ])
        ->assertSessionHasErrors('treatment_id');
});

it('rejects a duplicate tariff for the same branch, treatment and effective date', function () {
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'effective_date' => '2026-01-01',
    ]);

    $this->actingAs($this->manager)
        ->from(route('settings.tariffs.create'))
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => 400000,
            'effective_date' => '2026-01-01',
        ])
        ->assertSessionHasErrors('treatment_id');
});

it('allows the same treatment and date in a different branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'TRF3']);
    Tariff::factory()->create([
        'branch_id' => $otherBranch->id,
        'treatment_id' => $this->treatment->id,
        'effective_date' => '2026-01-01',
    ]);

    $this->actingAs($this->manager)
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => 400000,
            'effective_date' => '2026-01-01',
        ])
        ->assertRedirect(route('settings.tariffs.index'));

    $this->assertDatabaseHas('mst_tariffs', [
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'effective_date' => '2026-01-01',
        'price' => 400000,
    ]);
});

it('updates a tariff', function () {
    $tariff = Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'price' => 100000,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.tariffs.update', $tariff), [
            'treatment_id' => $this->treatment->id,
            'price' => 275000,
            'effective_date' => '2026-03-01',
            'is_active' => '0',
            'notes' => 'Disesuaikan',
        ])
        ->assertRedirect(route('settings.tariffs.index'));

    $tariff->refresh();
    expect((float) $tariff->price)->toBe(275000.0)
        ->and($tariff->effective_date->toDateString())->toBe('2026-03-01')
        ->and($tariff->is_active)->toBeFalse();
});

it('soft deletes a tariff', function () {
    $tariff = Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
    ]);

    $this->actingAs($this->manager)
        ->delete(route('settings.tariffs.destroy', $tariff))
        ->assertRedirect(route('settings.tariffs.index'));

    expect(Tariff::find($tariff->id))->toBeNull();
    expect(Tariff::withTrashed()->find($tariff->id))->not->toBeNull();
});

it('denies a viewer from creating, updating or deleting', function () {
    $tariff = Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => 100000,
            'effective_date' => '2026-01-01',
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->delete(route('settings.tariffs.destroy', $tariff))
        ->assertForbidden();
});

it('denies updating or deleting a tariff from another branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'TRF4']);
    $tariff = Tariff::factory()->create([
        'branch_id' => $otherBranch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Milik Cabang Lain',
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.tariffs.update', $tariff), [
            'treatment_id' => $this->treatment->id,
            'price' => 999000,
            'effective_date' => '2026-01-01',
        ])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->delete(route('settings.tariffs.destroy', $tariff))
        ->assertForbidden();

    expect($tariff->refresh()->notes)->toBe('Milik Cabang Lain');
});

it('filters tariffs by treatment', function () {
    $otherTreatment = Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-SCAL',
        'name' => 'Scaling',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Tarif Tambal',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $otherTreatment->id,
        'notes' => 'Tarif Scaling',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['treatment_id' => $this->treatment->id]))
        ->assertOk()
        ->assertSee('Tarif Tambal')
        ->assertDontSee('Tarif Scaling');
});

it('filters tariffs by active status', function () {
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Tarif Aktif',
    ]);
    $otherTreatment = Treatment::factory()->create(['treatment_category_id' => $this->category->id, 'name' => 'Cabut Gigi']);
    Tariff::factory()->inactive()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $otherTreatment->id,
        'notes' => 'Tarif Nonaktif',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['is_active' => '1']))
        ->assertOk()
        ->assertSee('Tarif Aktif')
        ->assertDontSee('Tarif Nonaktif');

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['is_active' => '0']))
        ->assertOk()
        ->assertSee('Tarif Nonaktif')
        ->assertDontSee('Tarif Aktif');
});

it('filters tariffs by requires_lab treatment', function () {
    $labTreatment = Treatment::factory()->requiresLab()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-CRWN',
        'name' => 'Crown',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $labTreatment->id,
        'notes' => 'Tarif Lab',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Tarif Non Lab',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['requires_lab' => '1']))
        ->assertOk()
        ->assertSee('Tarif Lab')
        ->assertDontSee('Tarif Non Lab');
});

it('searches tariffs by treatment code or name', function () {
    $otherTreatment = Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-SCAL',
        'name' => 'Scaling',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $this->treatment->id,
        'notes' => 'Tarif Tambal',
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branch->id,
        'treatment_id' => $otherTreatment->id,
        'notes' => 'Tarif Scaling',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['search' => 'Scaling']))
        ->assertOk()
        ->assertSee('Tarif Scaling')
        ->assertDontSee('Tarif Tambal');

    $this->actingAs($this->viewer)
        ->get(route('settings.tariffs.index', ['search' => 'TRT-FILL']))
        ->assertOk()
        ->assertSee('Tarif Tambal')
        ->assertDontSee('Tarif Scaling');
});

it('seeds the default tariffs for the main branch with the expected prices', function () {
    test()->seed(TreatmentCategorySeeder::class);
    test()->seed(TreatmentSeeder::class);
    test()->seed(TariffSeeder::class);

    $expected = [
        'TRT-CONS' => 50000,
        'TRT-SCAL' => 250000,
        'TRT-FILL' => 300000,
        'TRT-EXTR' => 250000,
        'TRT-RCT' => 750000,
        'TRT-PDEN' => 1500000,
        'TRT-FDEN' => 3000000,
        'TRT-CRWN' => 2500000,
        'TRT-BRDG' => 3500000,
        'TRT-DENT' => 1500000,
        'TRT-RETN' => 1000000,
    ];

    expect(Tariff::count())->toBe(11);

    foreach ($expected as $code => $price) {
        $treatment = Treatment::where('code', $code)->firstOrFail();
        $tariff = Tariff::where('treatment_id', $treatment->id)->firstOrFail();
        expect($tariff->branch_id)->toBe($this->branch->id)
            ->and((float) $tariff->price)->toBe((float) $price);
    }
});

it('has an idempotent tariff seeder', function () {
    test()->seed(TreatmentCategorySeeder::class);
    test()->seed(TreatmentSeeder::class);
    test()->seed(TariffSeeder::class);
    test()->seed(TariffSeeder::class);

    expect(Tariff::count())->toBe(11);
});

it('does not create any billing, invoice, payment or RME records when a tariff is stored', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.tariffs.store'), [
            'treatment_id' => $this->treatment->id,
            'price' => 300000,
            'effective_date' => '2026-01-01',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.tariffs.index'));

    expect(LabOrder::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(Payment::count())->toBe(0);

    expect(Schema::hasTable('trx_dental_lab_cases'))->toBeFalse()
        ->and(Schema::hasTable('trx_medical_records'))->toBeFalse();
});
