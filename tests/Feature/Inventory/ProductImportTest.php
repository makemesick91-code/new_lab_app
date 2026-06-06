<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\ProductImportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
});

function productImportCsv(array $rows): UploadedFile
{
    $handle = fopen('php://temp', 'r+');

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    rewind($handle);
    $contents = stream_get_contents($handle);
    fclose($handle);

    return UploadedFile::fake()->createWithContent('products.csv', $contents);
}

it('denies users without manage_inventory from accessing import page', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.products.import'))
        ->assertForbidden();
});

it('allows manage_inventory users to download import template', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.products.import.template'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('includes the required template header', function () {
    $rows = app(ProductImportService::class)->templateRows();

    expect($rows['header'])->toBe([
        'sku',
        'name',
        'category',
        'unit',
        'supplier',
        'minimum_stock',
        'reorder_point',
        'reorder_quantity',
        'is_active',
    ]);
});

it('rejects invalid uploaded files', function () {
    $file = UploadedFile::fake()->create('products.pdf', 10, 'application/pdf');

    $this->actingAs($this->manager)
        ->from(route('inventory.products.import'))
        ->post(route('inventory.products.import.store'), [
            'csv_file' => $file,
        ])
        ->assertRedirect(route('inventory.products.import'))
        ->assertSessionHasErrors('csv_file');
});

it('creates products from valid csv rows', function () {
    $category = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Bahan',
    ]);
    $unit = ProductUnit::factory()->create([
        'name' => 'Pieces',
        'symbol' => 'pcs',
    ]);
    Supplier::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'PT Supplier A',
    ]);

    $file = productImportCsv([
        ProductImportService::HEADERS,
        ['BRG-CSV-001', 'Zirconia Block', $category->name, $unit->symbol, 'PT Supplier A', '5.0000', '2.5000', '10.0000', '1'],
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.import.store'), [
            'csv_file' => $file,
        ])
        ->assertRedirect(route('inventory.products.index'))
        ->assertSessionHas('status');

    $product = Product::query()
        ->where('branch_id', $this->branch->id)
        ->where('code', 'BRG-CSV-001')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->name)->toBe('Zirconia Block')
        ->and((float) $product->minimum_stock)->toBe(5.0)
        ->and((float) $product->reorder_point)->toBe(2.5)
        ->and((float) $product->reorder_quantity)->toBe(10.0)
        ->and($product->is_active)->toBeTrue();
});

it('rejects duplicate sku in csv import', function () {
    $category = ProductCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bahan']);
    $unit = ProductUnit::factory()->create(['symbol' => 'pcs']);

    Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'BRG-DUP-001',
        'product_category_id' => $category->id,
        'product_unit_id' => $unit->id,
    ]);

    $file = productImportCsv([
        ProductImportService::HEADERS,
        ['BRG-DUP-001', 'Duplicate Product', $category->name, $unit->symbol, '', '', '', '', '1'],
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.import.store'), [
            'csv_file' => $file,
        ])
        ->assertRedirect(route('inventory.products.import'))
        ->assertSessionHas('import_errors');

    expect(Product::query()->where('code', 'BRG-DUP-001')->count())->toBe(1);
});

it('rejects unknown category and unit rows', function () {
    $category = ProductCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bahan']);
    $unit = ProductUnit::factory()->create(['symbol' => 'pcs']);

    $file = productImportCsv([
        ProductImportService::HEADERS,
        ['BRG-UNK-001', 'Unknown Category', 'Kategori Tidak Ada', $unit->symbol, '', '', '', '', '1'],
        ['BRG-UNK-002', 'Unknown Unit', $category->name, 'liter', '', '', '', '', '1'],
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.import.store'), [
            'csv_file' => $file,
        ])
        ->assertRedirect(route('inventory.products.import'))
        ->assertSessionHas('import_errors');

    expect(Product::query()->whereIn('code', ['BRG-UNK-001', 'BRG-UNK-002'])->count())->toBe(0);
});

it('accepts decimal minimum stock and reorder values', function () {
    $category = ProductCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bahan']);
    $unit = ProductUnit::factory()->create(['symbol' => 'pcs']);

    $file = productImportCsv([
        ProductImportService::HEADERS,
        ['BRG-DEC-001', 'Decimal Product', $category->name, $unit->symbol, '', '3.2500', '1.1250', '7.5000', '1'],
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.import.store'), [
            'csv_file' => $file,
        ])
        ->assertRedirect(route('inventory.products.index'));

    $product = Product::query()->where('code', 'BRG-DEC-001')->firstOrFail();

    expect((float) $product->minimum_stock)->toBe(3.25)
        ->and((float) $product->reorder_point)->toBe(1.125)
        ->and((float) $product->reorder_quantity)->toBe(7.5);
});
