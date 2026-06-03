<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 - Delivery & Proof of Delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('delivery_number', 50)->unique();
            $table->foreignId('courier_id')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 50)->default('READY_FOR_DELIVERY');
            $table->text('delivery_notes')->nullable();
            $table->string('receiver_name', 150)->nullable();
            $table->string('receiver_signature_path', 255)->nullable();
            $table->string('receiver_photo_path', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lab_order_id');
            $table->index('courier_id');
            $table->index('status');
            $table->index('received_at');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_deliveries');
    }
};
