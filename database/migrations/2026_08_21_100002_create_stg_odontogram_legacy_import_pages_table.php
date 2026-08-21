<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-04b — staging pages of a legacy odontogram chart.
 *
 * ADDITIVE ONLY. One row = one rasterized page of the staged source PDF.
 * Staging pages belong to their batch and are removed with it (cascade),
 * unlike the published pages in trx_odontogram_legacy_record_pages, which are
 * clinical evidence and are never cascade-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_odontogram_legacy_import_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legacy_import_id')
                ->constrained('stg_odontogram_legacy_imports')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedInteger('page_number');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('dpi')->nullable();
            $table->unsignedSmallInteger('rotation')->default(0);

            $table->string('image_disk', 50)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('image_sha256', 64)->nullable();
            $table->string('thumbnail_path', 255)->nullable();

            $table->string('status', 30)->default('PENDING');

            $table->timestamps();

            $table->unique(['legacy_import_id', 'page_number'], 'stg_odo_legacy_import_pages_import_page_uq');
            $table->index(['legacy_import_id', 'status'], 'stg_odo_legacy_import_pages_import_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_odontogram_legacy_import_pages');
    }
};
