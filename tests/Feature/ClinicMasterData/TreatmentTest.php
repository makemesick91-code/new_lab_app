<?php

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Database\Seeders\TreatmentCategorySeeder;
use Database\Seeders\TreatmentSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedAccessControl();

    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
    $this->category = TreatmentCategory::factory()->create([
        'code' => 'CAT-REST',
        'name' => 'Restorasi Test',
    ]);
});

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.treatments.index'))
        ->assertForbidden();
});

it('lists treatments for a viewer', function () {
    Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-UI',
        'name' => 'Perawatan UI',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.index'))
        ->assertOk()
        ->assertViewIs('settings.treatments.index')
        ->assertSee('TRT-UI')
        ->assertSee('Perawatan UI');
});

it('filters treatments by category, active status, requires_lab and search', function () {
    $otherCategory = TreatmentCategory::factory()->create(['code' => 'CAT-OTH', 'name' => 'Kategori Lain']);

    Treatment::factory()->requiresLab()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-LAB',
        'name' => 'Perawatan Lab',
    ]);
    Treatment::factory()->create([
        'treatment_category_id' => $otherCategory->id,
        'code' => 'TRT-NOLAB',
        'name' => 'Perawatan Tanpa Lab',
        'is_active' => false,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.index', ['treatment_category_id' => $this->category->id]))
        ->assertOk()
        ->assertSee('Perawatan Lab')
        ->assertDontSee('Perawatan Tanpa Lab');

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.index', ['requires_lab' => '1']))
        ->assertOk()
        ->assertSee('Perawatan Lab')
        ->assertDontSee('Perawatan Tanpa Lab');

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.index', ['is_active' => '0']))
        ->assertOk()
        ->assertSee('Perawatan Tanpa Lab')
        ->assertDontSee('Perawatan Lab');

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.index', ['search' => 'TRT-LAB']))
        ->assertOk()
        ->assertSee('Perawatan Lab')
        ->assertDontSee('Perawatan Tanpa Lab');
});

it('lets a manager open the create page', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.treatments.create'))
        ->assertOk()
        ->assertViewIs('settings.treatments.create');
});

it('stores a valid treatment', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => $this->category->id,
            'code' => 'TRT-NEW',
            'name' => 'Perawatan Baru',
            'description' => 'Deskripsi',
            'default_duration_minutes' => 30,
            'requires_doctor' => '1',
            'requires_room' => '1',
            'requires_lab' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.treatments.index'));

    $this->assertDatabaseHas('mst_treatments', [
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-NEW',
        'name' => 'Perawatan Baru',
        'default_duration_minutes' => 30,
        'requires_lab' => true,
        'is_active' => true,
    ]);
});

it('saves requires_lab correctly when unchecked', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => $this->category->id,
            'code' => 'TRT-NOLAB',
            'name' => 'Tanpa Lab',
            'requires_doctor' => '1',
            'requires_room' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.treatments.index'));

    $this->assertDatabaseHas('mst_treatments', [
        'code' => 'TRT-NOLAB',
        'requires_lab' => false,
    ]);
});

it('requires category, code and name', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.treatments.create'))
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => '',
            'code' => '',
            'name' => '',
        ])
        ->assertSessionHasErrors(['treatment_category_id', 'code', 'name']);
});

it('requires the category to exist', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.treatments.create'))
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => 999999,
            'code' => 'TRT-X',
            'name' => 'Kategori Tidak Ada',
        ])
        ->assertSessionHasErrors('treatment_category_id');
});

it('rejects a duplicate code', function () {
    Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'code' => 'TRT-DUP',
    ]);

    $this->actingAs($this->manager)
        ->from(route('settings.treatments.create'))
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => $this->category->id,
            'code' => 'TRT-DUP',
            'name' => 'Perawatan Duplikat',
        ])
        ->assertSessionHasErrors('code');
});

it('updates a treatment', function () {
    $treatment = Treatment::factory()->create([
        'treatment_category_id' => $this->category->id,
        'name' => 'Nama Lama',
        'requires_lab' => false,
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.treatments.update', $treatment), [
            'treatment_category_id' => $this->category->id,
            'code' => $treatment->code,
            'name' => 'Nama Baru',
            'default_duration_minutes' => 45,
            'requires_doctor' => '1',
            'requires_room' => '0',
            'requires_lab' => '1',
            'is_active' => '0',
        ])
        ->assertRedirect(route('settings.treatments.index'));

    $treatment->refresh();
    expect($treatment->name)->toBe('Nama Baru')
        ->and($treatment->requires_room)->toBeFalse()
        ->and($treatment->requires_lab)->toBeTrue()
        ->and($treatment->is_active)->toBeFalse();
});

it('soft deletes a treatment', function () {
    $treatment = Treatment::factory()->create(['treatment_category_id' => $this->category->id]);

    $this->actingAs($this->manager)
        ->delete(route('settings.treatments.destroy', $treatment))
        ->assertRedirect(route('settings.treatments.index'));

    expect(Treatment::find($treatment->id))->toBeNull();
    expect(Treatment::withTrashed()->find($treatment->id))->not->toBeNull();
});

it('denies a viewer from creating, updating or deleting', function () {
    $treatment = Treatment::factory()->create(['treatment_category_id' => $this->category->id]);

    $this->actingAs($this->viewer)
        ->get(route('settings.treatments.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => $this->category->id,
            'code' => 'TRT-NO',
            'name' => 'Terlarang',
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->delete(route('settings.treatments.destroy', $treatment))
        ->assertForbidden();
});

it('seeds the default treatments with the lab flag set correctly', function () {
    test()->seed(TreatmentCategorySeeder::class);
    test()->seed(TreatmentSeeder::class);

    expect(Treatment::count())->toBe(11);

    $labTreatments = ['Gigi Palsu Sebagian', 'Gigi Palsu Full', 'Crown', 'Bridge', 'Denture', 'Retainer'];
    foreach ($labTreatments as $name) {
        expect(Treatment::where('name', $name)->value('requires_lab'))->toBeTrue();
    }

    expect(Treatment::where('name', 'Konsultasi Dokter')->value('requires_lab'))->toBeFalse()
        ->and(Treatment::where('name', 'Scaling')->value('requires_lab'))->toBeFalse();
});

it('has an idempotent treatment seeder', function () {
    test()->seed(TreatmentCategorySeeder::class);
    test()->seed(TreatmentSeeder::class);
    test()->seed(TreatmentSeeder::class);

    expect(Treatment::where('code', 'TRT-CONS')->count())->toBe(1)
        ->and(Treatment::count())->toBe(11);
});

it('does not create any lab case, billing or RME records when a treatment is stored', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.treatments.store'), [
            'treatment_category_id' => $this->category->id,
            'code' => 'TRT-SIDE',
            'name' => 'Perawatan Tanpa Efek Samping',
            'requires_doctor' => '1',
            'requires_room' => '1',
            'requires_lab' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.treatments.index'));

    // Treatment master data must not trigger any transactional side effects.
    expect(LabOrder::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(Payment::count())->toBe(0);

    // No RME/dental-lab tables should have been introduced by this phase.
    expect(Schema::hasTable('trx_dental_lab_cases'))->toBeFalse()
        ->and(Schema::hasTable('trx_medical_records'))->toBeFalse();
});
