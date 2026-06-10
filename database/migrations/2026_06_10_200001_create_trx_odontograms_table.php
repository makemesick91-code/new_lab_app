<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_odontograms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->unique()->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained('trx_medical_records')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->text('summary_notes')->nullable();
            $table->jsonb('tooth_map_payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status'], 'trx_odontograms_branch_status_index');
            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_odontograms');
    }
};
