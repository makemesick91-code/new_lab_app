<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-1A — published pages of a legacy RME record.
 *
 * ADDITIVE ONLY. A published page is clinical evidence: the parent FK uses
 * RESTRICT (never cascade-delete) and the table carries no soft delete, matching
 * the immutability contract of trx_rme_legacy_records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_legacy_record_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rme_legacy_record_id')
                ->constrained('trx_rme_legacy_records')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedInteger('page_number');

            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('dpi');
            $table->unsignedSmallInteger('rotation')->default(0);

            $table->string('background_disk', 50);
            $table->string('background_path', 255);
            $table->string('background_sha256', 64);
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('normalized_page_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['rme_legacy_record_id', 'page_number'], 'trx_rme_legacy_record_pages_record_page_uq');
            $table->index('rme_legacy_record_id', 'trx_rme_legacy_record_pages_record_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_legacy_record_pages');
    }
};
