<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — trx_lab_orders (Lab Order header / transaction center).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('clinic_id')->constrained('mst_clinics')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('order_date');
            $table->date('due_date')->nullable();
            $table->string('priority', 20)->default('NORMAL');
            $table->string('status', 50)->default('RECEIVED');
            $table->text('notes')->nullable();

            // Delivery preparation fields (reserved for Sprint 6, inactive in Sprint 3).
            $table->string('delivery_signature_path', 255)->nullable();
            $table->string('delivery_photo_path', 255)->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('clinic_id');
            $table->index('doctor_id');
            $table->index('patient_id');
            $table->index('status');
            $table->index('priority');
            $table->index('order_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_orders');
    }
};
