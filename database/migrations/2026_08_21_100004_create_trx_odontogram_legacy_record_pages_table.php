<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-04b — published pages of a legacy odontogram record.
 *
 * ADDITIVE ONLY. A published page is clinical evidence: the parent FK uses
 * RESTRICT (never cascade-delete) and the table carries no soft delete,
 * matching the immutability contract of trx_odontogram_legacy_records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_odontogram_legacy_record_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('odontogram_legacy_record_id')
                ->constrained('trx_odontogram_legacy_records')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedInteger('page_number');

            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('dpi');
            $table->unsignedSmallInteger('rotation')->default(0);

            $table->string('image_disk', 50);
            $table->string('image_path', 255);
            $table->string('image_sha256', 64);
            $table->string('thumbnail_path', 255)->nullable();

            $table->timestamps();

            $table->unique(['odontogram_legacy_record_id', 'page_number'], 'trx_odo_legacy_record_pages_record_page_uq');
            $table->index('odontogram_legacy_record_id', 'trx_odo_legacy_record_pages_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_odontogram_legacy_record_pages');
    }
};
