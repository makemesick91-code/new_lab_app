<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 66.1.1 — Multi-branch practice assignment for doctors.
 *
 * Additive pivot; mst_doctors.branch_id is kept for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctor_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['doctor_id', 'branch_id']);
            $table->index('doctor_id');
            $table->index('branch_id');
        });

        $this->backfillFromLegacyBranchId();
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctor_branches');
    }

    private function backfillFromLegacyBranchId(): void
    {
        $doctors = DB::table('mst_doctors')
            ->whereNotNull('branch_id')
            ->get(['id', 'branch_id']);

        $now = now();

        foreach ($doctors as $doctor) {
            $branch = DB::table('mst_branches')
                ->where('id', $doctor->branch_id)
                ->where('is_active', true)
                ->where('is_rme_enabled', true)
                ->first(['id']);

            if ($branch === null) {
                continue;
            }

            DB::table('mst_doctor_branches')->insertOrIgnore([
                'doctor_id' => $doctor->id,
                'branch_id' => $branch->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
