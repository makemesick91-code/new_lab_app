<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.1 — trx_purchase_requests (purchase request document header).
 *
 * This table records purchase request identity and approval workflow only.
 * It does not store stock balances and must not create trx_inventory_movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('purchase_request_number', 50)->unique();
            $table->date('request_date');
            $table->string('status', 30)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('status');
            $table->index('request_date');
            $table->index(['branch_id', 'status'], 'trx_purchase_requests_branch_status_index');
            $table->index(['branch_id', 'request_date'], 'trx_purchase_requests_branch_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_purchase_requests');
    }
};
