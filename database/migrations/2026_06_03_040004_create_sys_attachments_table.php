<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — sys_attachments (polymorphic file metadata, entity_type/entity_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 150);
            $table->unsignedBigInteger('entity_id');
            $table->string('category', 100);
            $table->string('file_name', 255);
            $table->string('file_path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index('entity_type');
            $table->index('entity_id');
            $table->index('uploaded_by');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_attachments');
    }
};
