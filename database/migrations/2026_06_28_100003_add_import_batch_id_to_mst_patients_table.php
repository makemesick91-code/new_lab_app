<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Adds a nullable, indexed trace column so patients created by a legacy batch
 * import are batch-attributable (for reporting and rollback). Additive and
 * non-destructive: existing rows keep NULL. No data rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('mst_patients', 'import_batch_id')) {
                $table->foreignId('import_batch_id')
                    ->nullable()
                    ->after('is_active')
                    ->constrained('stg_legacy_patient_import_batches')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            if (Schema::hasColumn('mst_patients', 'import_batch_id')) {
                $table->dropConstrainedForeignId('import_batch_id');
            }
        });
    }
};
