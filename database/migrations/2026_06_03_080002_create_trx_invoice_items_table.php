<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 - Invoice item lines and Lab Order billing link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('trx_invoices')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('lab_order_id');
            $table->index(['invoice_id', 'lab_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_invoice_items');
    }
};
