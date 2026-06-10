<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained('mst_treatments')->cascadeOnUpdate()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('rme_invoice_id');
            $table->index('treatment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_invoice_items');
    }
};
