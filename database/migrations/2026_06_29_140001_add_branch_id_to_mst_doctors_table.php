<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 66.1 — RME doctor master uses mst_branches (Cabang RME) as source of truth.
 *
 * Additive + non-destructive:
 *   - mst_clinics and clinic_id on doctors are kept for historical reads.
 *   - New doctor writes set clinic_id = null and use branch_id instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_doctors', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('user_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('branch_id');
        });

        $this->backfillBranchIdFromClinicCode();

        Schema::table('mst_doctors', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mst_doctors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });

        // clinic_id NOT NULL restore is only safe when no doctors have null clinic_id.
        Schema::table('mst_doctors', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
        });
    }

    private function backfillBranchIdFromClinicCode(): void
    {
        $doctors = DB::table('mst_doctors')
            ->whereNotNull('clinic_id')
            ->whereNull('branch_id')
            ->get(['id', 'clinic_id']);

        foreach ($doctors as $doctor) {
            $clinic = DB::table('mst_clinics')
                ->where('id', $doctor->clinic_id)
                ->first(['code']);

            if ($clinic === null || $clinic->code === null || $clinic->code === '') {
                continue;
            }

            $branch = DB::table('mst_branches')
                ->where('is_active', true)
                ->where('is_rme_enabled', true)
                ->whereRaw('LOWER(code) = ?', [strtolower((string) $clinic->code)])
                ->first(['id']);

            if ($branch !== null) {
                DB::table('mst_doctors')
                    ->where('id', $doctor->id)
                    ->update(['branch_id' => $branch->id]);
            }
        }
    }
};
