<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — trx_lab_order_items (service items within a Lab Order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('lab_service_id')->constrained('mst_lab_services')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('tooth_number', 20)->nullable();
            $table->string('shade_color_text', 100)->nullable();
            $table->string('material_text', 100)->nullable();
            $table->decimal('quantity', 18, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lab_order_id');
            $table->index('lab_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_order_items');
    }
};
