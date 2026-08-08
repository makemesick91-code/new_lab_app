<?php

/*
|--------------------------------------------------------------------------
| Hotfix — Inventory Unified Branch Master Guardrail
|--------------------------------------------------------------------------
|
| Project decision: mst_branches is the single source of truth for branch
| identity across every module (RME, Inventory, Lab, Cashier, Receivables,
| Owner Dashboard, Access Control). The Inventory module must NEVER introduce
| its own branch master (inventory_branches, inv_branches, etc.) and must
| always resolve branch data from App\Modules\Branch\Models\Branch / the
| mst_branches table.
|
| These are governance tests. They use DB introspection, model reflection and
| file scanning so that any future regression — a redundant branch table, a
| dropped foreign key, a duplicated branch master in a seeder — fails loudly.
|
| See: docs/hotfix_inventory_unified_branch_master.md
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\InventoryAnalyticsFilterRequest;
use App\Modules\Inventory\Requests\InventoryReportFilterRequest;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\SchemaFacts;

/**
 * The forbidden, module-specific branch master tables that must never exist.
 */
const FORBIDDEN_BRANCH_MASTER_TABLES = [
    'inventory_branches',
    'inv_branches',
    'lab_branches',
    'cashier_branches',
    'rme_branches',
];

/**
 * The unified branch master table shared by every module.
 */
const UNIFIED_BRANCH_MASTER_TABLE = 'mst_branches';

/**
 * Inventory tables that carry a branch_id and therefore must point at the
 * unified branch master.
 */
const INVENTORY_BRANCH_SCOPED_TABLES = [
    'inv_product_categories',
    'inv_suppliers',
    'inv_inventory_locations',
    'inv_products',
    'inv_inventory_batches',
    'inv_inventory_activity_logs',
    'trx_inventory_movements',
    'trx_stock_opnames',
    'trx_stock_transfers',
    'trx_purchase_requests',
    'trx_purchase_orders',
    'trx_goods_receipts',
];

/**
 * Inventory Eloquent models that expose a branch() relation.
 */
const INVENTORY_BRANCH_OWNING_MODELS = [
    ProductCategory::class,
    Supplier::class,
    InventoryLocation::class,
    Product::class,
    InventoryBatch::class,
    InventoryActivityLog::class,
    InventoryMovement::class,
    StockOpname::class,
    StockTransfer::class,
    PurchaseRequest::class,
    PurchaseOrder::class,
    GoodsReceipt::class,
];

function migrationFilesContents(): array
{
    $contents = [];
    foreach (glob(database_path('migrations/*.php')) as $file) {
        $contents[$file] = (string) file_get_contents($file);
    }

    return $contents;
}

function inventoryModuleFilesContents(): array
{
    $contents = [];
    $directory = new RecursiveDirectoryIterator(app_path('Modules/Inventory'));
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $contents[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $contents;
}

it('the unified branch master table exists', function () {
    expect(DB::getSchemaBuilder()->hasTable(UNIFIED_BRANCH_MASTER_TABLE))->toBeTrue();
});

it('no module-specific branch master table exists in the schema', function () {
    foreach (FORBIDDEN_BRANCH_MASTER_TABLES as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))
            ->toBeFalse("Forbidden branch master table [{$table}] must not exist. Use mst_branches.");
    }
});

it('no migration ever creates a module-specific branch master table', function () {
    foreach (migrationFilesContents() as $file => $contents) {
        foreach (FORBIDDEN_BRANCH_MASTER_TABLES as $table) {
            expect(str_contains($contents, "Schema::create('{$table}'") || str_contains($contents, "Schema::create(\"{$table}\""))
                ->toBeFalse('Migration ['.basename((string) $file)."] must not create branch master table [{$table}].");
        }
    }
});

it('every branch-scoped inventory table has a branch_id foreign key to mst_branches', function () {
    // CICD-FIX-4 — this assertion used raw `PRAGMA foreign_key_list()` and so
    // skipped itself on every non-SQLite driver, which meant the branch-master
    // foreign-key contract was NEVER verified on the authoritative PostgreSQL
    // connection. It now reads through the portable SchemaFacts introspection
    // (CICD-FIX-2), so the same contract is enforced on both drivers.
    foreach (INVENTORY_BRANCH_SCOPED_TABLES as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue("Expected inventory table [{$table}] to exist.");
        expect(DB::getSchemaBuilder()->hasColumn($table, 'branch_id'))
            ->toBeTrue("Inventory table [{$table}] must carry a branch_id column.");

        $branchForeignKeyTarget = SchemaFacts::foreignKeyTargetFor($table, 'branch_id');

        expect($branchForeignKeyTarget)->not->toBeNull("Inventory table [{$table}] must declare a foreign key on branch_id.");
        expect($branchForeignKeyTarget)
            ->toBe(UNIFIED_BRANCH_MASTER_TABLE, "Inventory table [{$table}].branch_id must reference mst_branches, got [{$branchForeignKeyTarget}].");
    }
});

it('inventory models resolve their branch relation to the unified Branch model', function () {
    foreach (INVENTORY_BRANCH_OWNING_MODELS as $modelClass) {
        $model = new $modelClass;

        expect(method_exists($model, 'branch'))->toBeTrue("Model [{$modelClass}] is expected to expose a branch() relation.");

        $relation = $model->branch();
        expect($relation)->toBeInstanceOf(BelongsTo::class);
        expect($relation->getRelated())->toBeInstanceOf(Branch::class);
        expect($relation->getRelated()->getTable())
            ->toBe(UNIFIED_BRANCH_MASTER_TABLE, "Model [{$modelClass}]->branch() must resolve to mst_branches.");
    }
});

it('the Branch model is the unified branch master bound to mst_branches', function () {
    expect((new Branch)->getTable())->toBe(UNIFIED_BRANCH_MASTER_TABLE);
});

it('inventory branch filter requests validate against mst_branches and not a module table', function () {
    $branchFilterRequests = [
        InventoryReportFilterRequest::class,
        InventoryAnalyticsFilterRequest::class,
    ];

    foreach ($branchFilterRequests as $requestClass) {
        if (! class_exists($requestClass)) {
            continue;
        }

        $rules = (new $requestClass)->rules();

        if (! array_key_exists('branch_id', $rules)) {
            continue;
        }

        $branchRule = implode('|', (array) $rules['branch_id']);

        // If branch_id is validated by existence, it must be against mst_branches.
        if (str_contains($branchRule, 'exists:')) {
            expect($branchRule)->toContain('exists:'.UNIFIED_BRANCH_MASTER_TABLE.',id');
        }

        foreach (FORBIDDEN_BRANCH_MASTER_TABLES as $table) {
            expect($branchRule)->not->toContain($table);
        }
    }
});

it('no inventory module source references a module-specific branch master table', function () {
    foreach (inventoryModuleFilesContents() as $file => $contents) {
        foreach (FORBIDDEN_BRANCH_MASTER_TABLES as $table) {
            expect(str_contains($contents, $table))
                ->toBeFalse('Inventory file ['.basename((string) $file)."] must not reference branch master table [{$table}].");
        }
    }
});

it('the inventory module resolves branch data through the unified Branch model and BranchContext', function () {
    $sources = inventoryModuleFilesContents();
    $referencesUnifiedBranch = collect($sources)->contains(function (string $contents): bool {
        return str_contains($contents, 'App\\Modules\\Branch\\Models\\Branch')
            || str_contains($contents, 'App\\Modules\\Branch\\Services\\BranchContext');
    });

    expect($referencesUnifiedBranch)->toBeTrue('Inventory must resolve branch data via the unified Branch model / BranchContext.');
});

it('the inventory seeder does not create a branch master record outside mst_branches', function () {
    $seeder = (string) file_get_contents(database_path('seeders/InventorySeeder.php'));

    // The seeder must look up an existing unified branch, never mint a new one.
    expect($seeder)->toContain('Branch::where');

    foreach (['Branch::create(', 'Branch::factory(', "table('".UNIFIED_BRANCH_MASTER_TABLE."')->insert", 'table("'.UNIFIED_BRANCH_MASTER_TABLE.'")->insert'] as $forbidden) {
        expect(str_contains($seeder, $forbidden))
            ->toBeFalse("InventorySeeder must not create branch master records (found [{$forbidden}]). It must reuse the existing mst_branches row.");
    }
});

it('inventory stock movement records persist a branch_id tied to mst_branches', function () {
    $branch = Branch::factory()->create();
    $category = ProductCategory::factory()->create(['branch_id' => $branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'product_category_id' => $category->id,
    ]);

    $movement = InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
    ]);

    expect($movement->branch_id)->toBe($branch->id);
    expect($movement->branch)->toBeInstanceOf(Branch::class);
    expect($movement->branch->getTable())->toBe(UNIFIED_BRANCH_MASTER_TABLE);

    // The movement's branch is genuinely a row in the unified branch master.
    expect(DB::table(UNIFIED_BRANCH_MASTER_TABLE)->where('id', $movement->branch_id)->exists())->toBeTrue();
});
