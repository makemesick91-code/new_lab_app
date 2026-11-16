<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — the automatic
 * Nomor RM counter, one row per branch and clinic year.
 *
 * Additive only (CG-09): a new table, no existing row touched. `last_number`
 * is the highest sequence issued (or seeded) for DG-{KODE}-{TAHUN}-…; the
 * allocator locks the row FOR UPDATE inside the registration transaction, so two
 * registrations in one branch-year never read the same value. UNIQUE(branch,
 * year) lets the first-of-year row be created with INSERT … ON CONFLICT DO
 * NOTHING and then locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_patient_rm_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->smallInteger('year');
            $table->bigInteger('last_number')->default(0);
            // `lazy` (seeded by the allocator on first use) or `command`
            // (raised by patient:rm-sequence-seed).
            $table->string('seed_source', 16)->default('lazy');
            $table->timestamp('seeded_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'year'], 'mst_prs_branch_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_patient_rm_sequences');
    }
};
