<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 - Payments against invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->foreignId('invoice_id')->constrained('trx_invoices')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('payment_date');
            $table->string('payment_method', 30);
            $table->decimal('amount', 15, 2);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('payment_date');
            $table->index('payment_method');
            $table->index('received_by');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_payments');
    }
};
