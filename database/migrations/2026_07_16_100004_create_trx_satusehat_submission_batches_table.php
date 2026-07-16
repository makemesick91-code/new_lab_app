<?php

// SATUSEHAT-1 — Submission batch scaffold (prepared/blocked only; NO network
// send this sprint). A batch groups approved candidates prepared for a future
// SATUSEHAT-2 submission. Additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_satusehat_submission_batches', function (Blueprint $table) {
            $table->id();

            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // draft | preparing | prepared | blocked | cancelled
            // (no "sent"/"completed" — external submission is SATUSEHAT-2.)
            $table->string('status', 20)->default('draft');

            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('resource_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index('environment');
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_submission_batches');
    }
};
