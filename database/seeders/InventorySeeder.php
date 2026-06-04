<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', Branch::MAIN_CODE)->first();

        if (! $branch) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();

        $categories = collect([
            ['code' => 'ZIRC', 'name' => 'Zirconia'],
            ['code' => 'ACRY', 'name' => 'Acrylic'],
            ['code' => 'METAL', 'name' => 'Metal'],
            ['code' => 'CONS', 'name' => 'Consumable'],
        ])->mapWithKeys(fn (array $data) => [
            $data['code'] => ProductCategory::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $data['code']],
                ['name' => $data['name'], 'is_active' => true]
            ),
        ]);

        $units = collect([
            ['symbol' => 'pcs', 'name' => 'Pieces'],
            ['symbol' => 'gram', 'name' => 'Gram'],
            ['symbol' => 'ml', 'name' => 'Milliliter'],
            ['symbol' => 'box', 'name' => 'Box'],
        ])->mapWithKeys(fn (array $data) => [
            $data['symbol'] => ProductUnit::firstOrCreate(
                ['symbol' => $data['symbol']],
                ['name' => $data['name'], 'is_active' => true]
            ),
        ]);

        $locations = collect([
            ['code' => 'GDG-UTM', 'name' => 'Gudang Utama', 'type' => InventoryLocation::TYPE_WAREHOUSE],
            ['code' => 'PROD', 'name' => 'Ruang Produksi', 'type' => InventoryLocation::TYPE_PRODUCTION_ROOM],
            ['code' => 'QC', 'name' => 'Ruang QC', 'type' => InventoryLocation::TYPE_QC_ROOM],
        ])->mapWithKeys(fn (array $data) => [
            $data['code'] => InventoryLocation::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $data['code']],
                ['name' => $data['name'], 'type' => $data['type'], 'is_active' => true]
            ),
        ]);

        $supplier = Supplier::firstOrCreate(
            ['branch_id' => $branch->id, 'name' => 'Asia Dental Material Supply'],
            [
                'phone' => '081234567890',
                'email' => 'supply@example.test',
                'address' => 'Makassar',
                'is_active' => true,
            ]
        );

        $products = collect([
            [
                'code' => 'ZIR-BLOCK-A2',
                'name' => 'Zirconia Block A2',
                'category' => 'ZIRC',
                'unit' => 'pcs',
                'minimum_stock' => 5,
                'average_cost' => 250000,
                'opening_qty' => 20,
            ],
            [
                'code' => 'ACR-RESIN-PINK',
                'name' => 'Acrylic Resin Pink',
                'category' => 'ACRY',
                'unit' => 'gram',
                'minimum_stock' => 500,
                'average_cost' => 150,
                'opening_qty' => 2500,
            ],
            [
                'code' => 'COCR-ALLOY',
                'name' => 'CoCr Alloy',
                'category' => 'METAL',
                'unit' => 'gram',
                'minimum_stock' => 300,
                'average_cost' => 450,
                'opening_qty' => 1200,
            ],
            [
                'code' => 'GLOVE-NITRILE',
                'name' => 'Nitrile Glove',
                'category' => 'CONS',
                'unit' => 'box',
                'minimum_stock' => 10,
                'average_cost' => 85000,
                'opening_qty' => 35,
            ],
        ])->map(function (array $data) use ($branch, $categories, $units) {
            return [
                'model' => Product::firstOrCreate(
                    ['branch_id' => $branch->id, 'code' => $data['code']],
                    [
                        'product_category_id' => $categories[$data['category']]->id,
                        'product_unit_id' => $units[$data['unit']]->id,
                        'name' => $data['name'],
                        'minimum_stock' => $data['minimum_stock'],
                        'average_cost' => $data['average_cost'],
                        'is_active' => true,
                    ]
                ),
                'opening_qty' => $data['opening_qty'],
            ];
        });

        $mainLocation = $locations['GDG-UTM'];

        foreach ($products as $productData) {
            /** @var Product $product */
            $product = $productData['model'];

            InventoryMovement::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'inventory_location_id' => $mainLocation->id,
                    'product_id' => $product->id,
                    'movement_type' => InventoryMovement::TYPE_OPENING,
                ],
                [
                    'supplier_id' => $supplier->id,
                    'movement_date' => now()->toDateString(),
                    'quantity_in' => $productData['opening_qty'],
                    'quantity_out' => 0,
                    'unit_cost' => $product->average_cost,
                    'reference_type' => 'inventory_seed',
                    'reference_id' => $product->id,
                    'notes' => 'Opening stock seeded for Sprint 12 Inventory Core.',
                    'created_by' => $admin?->id,
                ]
            );
        }
    }
}
