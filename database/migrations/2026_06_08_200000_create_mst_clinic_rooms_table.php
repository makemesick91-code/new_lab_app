<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 — mst_clinic_rooms. Branch-scoped clinic room master data.
 * Rooms belong to a branch and are resolved via BranchContext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_clinic_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('type', 50);
            $table->string('status', 20)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index(['branch_id', 'type'], 'mst_clinic_rooms_branch_type_index');
            $table->index(['branch_id', 'status'], 'mst_clinic_rooms_branch_status_index');
            $table->unique(['branch_id', 'code'], 'mst_clinic_rooms_branch_code_unique');
            $table->unique(['branch_id', 'name'], 'mst_clinic_rooms_branch_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_clinic_rooms');
    }
};
