<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index(['branch_id', 'is_active'], 'inv_product_categories_branch_active_index');
            $table->unique(['branch_id', 'code'], 'inv_product_categories_branch_code_unique');
        });

        Schema::create('inv_product_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('symbol', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('symbol');
            $table->index('is_active');
        });

        Schema::create('inv_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index(['branch_id', 'is_active'], 'inv_suppliers_branch_active_index');
        });

        Schema::create('inv_inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->nullable();
            $table->string('type', 50);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index(['branch_id', 'type'], 'inv_inventory_locations_branch_type_index');
            $table->index(['branch_id', 'is_active'], 'inv_inventory_locations_branch_active_index');
            $table->unique(['branch_id', 'code'], 'inv_inventory_locations_branch_code_unique');
        });

        Schema::create('inv_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_category_id')->constrained('inv_product_categories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained('inv_product_units')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->decimal('minimum_stock', 12, 2)->default(0);
            $table->decimal('average_cost', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index('product_category_id');
            $table->index('product_unit_id');
            $table->index(['branch_id', 'is_active'], 'inv_products_branch_active_index');
            $table->unique(['branch_id', 'code'], 'inv_products_branch_code_unique');
        });

        Schema::create('trx_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('inv_suppliers')->cascadeOnUpdate()->nullOnDelete();
            $table->string('movement_type', 50);
            $table->date('movement_date');
            $table->decimal('quantity_in', 12, 2)->default(0);
            $table->decimal('quantity_out', 12, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('inventory_location_id');
            $table->index('product_id');
            $table->index('supplier_id');
            $table->index('movement_type');
            $table->index('movement_date');
            $table->index(['reference_type', 'reference_id'], 'trx_inventory_movements_reference_index');
            $table->index(['branch_id', 'inventory_location_id', 'product_id'], 'trx_inventory_movements_branch_location_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_inventory_movements');
        Schema::dropIfExists('inv_products');
        Schema::dropIfExists('inv_inventory_locations');
        Schema::dropIfExists('inv_suppliers');
        Schema::dropIfExists('inv_product_units');
        Schema::dropIfExists('inv_product_categories');
    }
};
