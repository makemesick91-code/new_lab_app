<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-LAB-...-UPLOAD-COMPRESSION — additive, nullable-first compression audit
 * metadata on Lab Workflow evidence (size before/after + method + dimensions).
 * Legacy rows keep NULLs; no backfill, no destructive change. PostgreSQL and
 * SQLite safe; the previous app version keeps running against this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_lab_workflow_evidence', function (Blueprint $table) {
            $table->unsignedBigInteger('original_file_size')->nullable()->after('file_size');
            $table->unsignedInteger('width')->nullable()->after('original_file_size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('compression_method', 50)->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('trx_lab_workflow_evidence', function (Blueprint $table) {
            $table->dropColumn(['original_file_size', 'width', 'height', 'compression_method']);
        });
    }
};
