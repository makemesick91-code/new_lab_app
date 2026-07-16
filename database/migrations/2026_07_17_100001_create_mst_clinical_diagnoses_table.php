<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4A — Structured diagnosis foundation (master).
 *
 * Master list of clinical diagnoses (default code system ICD-10). This is the
 * CLINICAL master — it is deliberately separate from SATUSEHAT code mappings
 * (mst_satusehat_code_mappings): a master diagnosis is NOT automatically
 * SATUSEHAT-ready; readiness requires an ACTIVE, clinically reviewed mapping.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 * No existing table/column is dropped, renamed, or made NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mst_clinical_diagnoses')) {
            return;
        }

        Schema::create('mst_clinical_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->string('code_system', 40)->default('ICD-10');
            $table->string('code', 40);
            $table->string('display', 255);
            // Normalized lowercase "code display" text for search/autocomplete.
            $table->string('normalized_search', 320)->nullable()->index();
            // draft|active|deprecated|synthetic_rehearsal (synthetic entries are
            // excluded from doctor-facing search).
            $table->string('status', 30)->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            // Provenance of the master entry (e.g. "WHO ICD-10").
            $table->string('source', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code_system', 'code'], 'mst_clinical_dx_system_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_clinical_diagnoses');
    }
};
