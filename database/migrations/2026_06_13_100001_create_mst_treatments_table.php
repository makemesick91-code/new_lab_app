<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 — mst_treatments. Global treatment master data.
 * Not branch-scoped; tariff/pricing is handled separately in a later phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_category_id')
                ->constrained('mst_treatment_categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('default_duration_minutes')->nullable();
            $table->boolean('requires_doctor')->default(true);
            $table->boolean('requires_room')->default(true);
            $table->boolean('requires_lab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('treatment_category_id');
            $table->index('is_active');
            $table->index('requires_lab');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_treatments');
    }
};
