<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Staging header table: one row per uploaded legacy patient CSV file. Nothing
 * touches mst_patients until an explicit Commit; this table records the audit
 * trail (who uploaded / committed / rolled back, counts, file hash).
 *
 * Additive only. No existing table altered here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_legacy_patient_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploaded_by')->nullable()->index()
                ->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            // uploaded | validated | committing | committed | rolled_back | failed
            $table->string('status', 20)->default('uploaded')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->json('error_summary')->nullable();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_legacy_patient_import_batches');
    }
};
