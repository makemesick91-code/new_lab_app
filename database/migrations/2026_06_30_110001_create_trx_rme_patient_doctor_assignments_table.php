<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_patient_doctor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('from_doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('mst_branches')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('source_visit_id')->nullable()->constrained('trx_clinic_visits')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->string('assignment_type')->default('auto_visit');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index('from_doctor_id');
            $table->index('branch_id');
            $table->index('source_visit_id');
            $table->index('assigned_by');
            $table->index('assigned_at');
            $table->index('unassigned_at');
            $table->index('assignment_type');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX trx_rme_patient_doctor_assignments_active_unique
                 ON trx_rme_patient_doctor_assignments (patient_id, doctor_id)
                 WHERE unassigned_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_patient_doctor_assignments');
    }
};
