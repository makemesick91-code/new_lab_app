<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — external lab (vendor) master data.
 *
 * Deliberately a NEW master table: inv_suppliers is a branch-scoped inventory
 * purchasing vendor and must not be overloaded with lab-vendor semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_external_labs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_external_labs');
    }
};
