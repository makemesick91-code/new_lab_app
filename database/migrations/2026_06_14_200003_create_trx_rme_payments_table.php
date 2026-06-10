<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('mst_payment_methods')->cascadeOnUpdate()->nullOnDelete();
            $table->string('payment_number')->unique();
            $table->decimal('amount', 15, 2);
            $table->timestamp('paid_at');
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'rme_invoice_id'], 'trx_rme_payments_branch_invoice_index');
            $table->index('clinic_visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_payments');
    }
};
