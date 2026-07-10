<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — typed workflow evidence (photos & signatures).
 *
 * Explicitly-typed proof artifacts for the V2 workflow (SPK photo, branch
 * model photo, pickup photo, and in later phases the pre-delivery handover
 * photo, courier signature, recipient signature, delivery location photo).
 * Files live on the PRIVATE local disk (never the public symlink); this table
 * stores metadata + sha256 checksum only. Evidence is audit material: rows are
 * soft-deletable at most, never hard-deleted by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_workflow_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')
                ->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()
                ->constrained('mst_branches')->cascadeOnUpdate()->nullOnDelete();
            $table->string('type', 60);
            $table->string('file_path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lab_order_id', 'type']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_workflow_evidence');
    }
};
