<?php

use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Database\Seeders\TreatmentCategorySeeder;

beforeEach(function () {
    seedAccessControl();

    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
});

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.treatment-categories.index'))
        ->assertForbidden();
});

it('lists treatment categories for a viewer', function () {
    TreatmentCategory::factory()->create([
        'code' => 'CAT-CONS',
        'name' => 'Konsultasi UI',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.treatment-categories.index'))
        ->assertOk()
        ->assertViewIs('settings.treatment-categories.index')
        ->assertSee('CAT-CONS')
        ->assertSee('Konsultasi UI');
});

it('filters treatment categories by active status and search', function () {
    TreatmentCategory::factory()->create([
        'code' => 'CAT-A',
        'name' => 'Kategori Aktif',
        'is_active' => true,
    ]);
    TreatmentCategory::factory()->inactive()->create([
        'code' => 'CAT-I',
        'name' => 'Kategori Nonaktif',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.treatment-categories.index', ['is_active' => '1']))
        ->assertOk()
        ->assertSee('Kategori Aktif')
        ->assertDontSee('Kategori Nonaktif');

    $this->actingAs($this->viewer)
        ->get(route('settings.treatment-categories.index', ['is_active' => '0']))
        ->assertOk()
        ->assertSee('Kategori Nonaktif')
        ->assertDontSee('Kategori Aktif');

    $this->actingAs($this->viewer)
        ->get(route('settings.treatment-categories.index', ['search' => 'CAT-A']))
        ->assertOk()
        ->assertSee('Kategori Aktif')
        ->assertDontSee('Kategori Nonaktif');
});

it('lets a manager open the create page', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.treatment-categories.create'))
        ->assertOk()
        ->assertViewIs('settings.treatment-categories.create');
});

it('stores a valid treatment category', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.treatment-categories.store'), [
            'code' => 'CAT-NEW',
            'name' => 'Kategori Baru',
            'description' => 'Deskripsi',
            'sort_order' => 5,
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.treatment-categories.index'));

    $this->assertDatabaseHas('mst_treatment_categories', [
        'code' => 'CAT-NEW',
        'name' => 'Kategori Baru',
        'sort_order' => 5,
        'is_active' => true,
    ]);
});

it('requires code and name', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.treatment-categories.create'))
        ->post(route('settings.treatment-categories.store'), [
            'code' => '',
            'name' => '',
        ])
        ->assertSessionHasErrors(['code', 'name']);
});

it('rejects a duplicate code', function () {
    TreatmentCategory::factory()->create(['code' => 'CAT-DUP']);

    $this->actingAs($this->manager)
        ->from(route('settings.treatment-categories.create'))
        ->post(route('settings.treatment-categories.store'), [
            'code' => 'CAT-DUP',
            'name' => 'Kategori Duplikat',
        ])
        ->assertSessionHasErrors('code');
});

it('rejects a duplicate name', function () {
    TreatmentCategory::factory()->create(['name' => 'Kategori Sama']);

    $this->actingAs($this->manager)
        ->from(route('settings.treatment-categories.create'))
        ->post(route('settings.treatment-categories.store'), [
            'code' => 'CAT-X',
            'name' => 'Kategori Sama',
        ])
        ->assertSessionHasErrors('name');
});

it('updates a treatment category', function () {
    $category = TreatmentCategory::factory()->create([
        'name' => 'Nama Lama',
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.treatment-categories.update', $category), [
            'code' => $category->code,
            'name' => 'Nama Baru',
            'sort_order' => 9,
            'is_active' => '0',
        ])
        ->assertRedirect(route('settings.treatment-categories.index'));

    expect($category->refresh()->name)->toBe('Nama Baru')
        ->and($category->sort_order)->toBe(9)
        ->and($category->is_active)->toBeFalse();
});

it('soft deletes a treatment category', function () {
    $category = TreatmentCategory::factory()->create();

    $this->actingAs($this->manager)
        ->delete(route('settings.treatment-categories.destroy', $category))
        ->assertRedirect(route('settings.treatment-categories.index'));

    expect(TreatmentCategory::find($category->id))->toBeNull();
    expect(TreatmentCategory::withTrashed()->find($category->id))->not->toBeNull();
});

it('denies a viewer from creating, updating or deleting', function () {
    $category = TreatmentCategory::factory()->create();

    $this->actingAs($this->viewer)
        ->get(route('settings.treatment-categories.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('settings.treatment-categories.store'), [
            'code' => 'CAT-NO',
            'name' => 'Terlarang',
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->delete(route('settings.treatment-categories.destroy', $category))
        ->assertForbidden();
});

it('seeds the default treatment categories', function () {
    test()->seed(TreatmentCategorySeeder::class);

    foreach (['consultation', 'preventive', 'restoration', 'extraction', 'endodontic', 'orthodontic', 'prosthodontic', 'surgery', 'lab', 'other'] as $code) {
        expect(TreatmentCategory::where('code', $code)->exists())->toBeTrue();
    }

    expect(TreatmentCategory::count())->toBe(10);
});

it('has an idempotent treatment category seeder', function () {
    test()->seed(TreatmentCategorySeeder::class);
    test()->seed(TreatmentCategorySeeder::class);

    expect(TreatmentCategory::where('code', 'consultation')->count())->toBe(1)
        ->and(TreatmentCategory::count())->toBe(10);
});
