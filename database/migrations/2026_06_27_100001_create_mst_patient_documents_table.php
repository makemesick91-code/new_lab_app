<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 61.1 — Direct KTP Scanner Capture & Compression.
 *
 * Stores private patient identity documents (currently only the KTP scan
 * captured via the Daengtisia Scanner Agent during registration). The scan
 * file itself lives on the private `local` disk; this table only records the
 * pointer + integrity metadata. No public URL is ever generated.
 *
 * Additive only — no existing table touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_patient_documents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Document classification. Only "ktp" is used in this sprint.
            $table->string('document_type', 32);

            // Relative path on the private disk (storage/app/private/...).
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100);

            // Integrity / audit metadata.
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('compressed_file_size')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('patient_id');
            $table->index('document_type');
            $table->index('uploaded_by');
            $table->index(['patient_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_patient_documents');
    }
};
