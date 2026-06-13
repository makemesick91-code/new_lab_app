<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation
// Additive only: tracks follow-up/reminder actions for active RME receivables.
// No payment/invoice status logic is affected by this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_receivable_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('mst_branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40);
            $table->string('channel', 40)->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->date('next_follow_up_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['rme_invoice_id', 'created_at']);
            $table->index(['branch_id', 'next_follow_up_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_receivable_follow_ups');
    }
};
